import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';

sealed class EstadoSesion {
  const EstadoSesion();
}

class SesionIniciando extends EstadoSesion {
  const SesionIniciando();
}

class SesionLista extends EstadoSesion {
  const SesionLista(this.usuario);

  final Usuario usuario;
}

/// 503 del intercambio y sin token guardado válido: "Servicio de identidad no disponible" + reintentar.
class SesionIdentidadNoDisponible extends EstadoSesion {
  const SesionIdentidadNoDisponible();
}

/// 401 en cualquier pedido. Ya se llamó a `onSesionInvalida`.
class SesionVencida extends EstadoSesion {
  const SesionVencida();
}

/// 403 del intercambio: el usuario está deshabilitado en el panel.
class SesionDeshabilitada extends EstadoSesion {
  const SesionDeshabilitada(this.mensaje);

  final String mensaje;
}

/// Sin red u otro error: se puede reintentar.
class SesionConError extends EstadoSesion {
  const SesionConError(this.mensaje);

  final String mensaje;
}

final sesionProvider = NotifierProvider<SesionNotifier, EstadoSesion>(SesionNotifier.new);

/// Usuario de la sesión lista. Solo se lee debajo de las pantallas que requieren sesión.
final usuarioProvider = Provider<Usuario>((ref) {
  final s = ref.watch(sesionProvider);
  if (s is SesionLista) return s.usuario;
  throw StateError('No hay sesión');
});

class SesionNotifier extends Notifier<EstadoSesion> {
  @override
  EstadoSesion build() {
    final aviso = ref.watch(avisoSesionProvider);
    aviso.escuchar(() {
      state = const SesionVencida();
      ref.read(almacenTokenProvider).borrar();
    });
    Future.microtask(iniciar);
    return const SesionIniciando();
  }

  /// Intercambia el token del PJ por uno Sanctum (spec 3.2). También es el "Reintentar".
  Future<void> iniciar() async {
    final aviso = ref.read(avisoSesionProvider);
    if (aviso.avisado) return;

    state = const SesionIniciando();
    final tokenPJ = ref.read(entornoProvider).sesion.token;
    final api = ref.read(apiProvider);
    final almacen = ref.read(almacenTokenProvider);

    try {
      final r = await api.intercambiar(tokenPJ);
      api.cliente.token = r.token;
      await almacen.guardar(tokenPJ, r.token);
      if (ref.mounted) state = SesionLista(r.usuario);
    } on SesionInvalida {
      // El aviso ya pasó el estado a SesionVencida y llamó a la app principal.
    } on AccesoDenegado catch (e) {
      if (ref.mounted) state = SesionDeshabilitada(e.mensaje);
    } on ServicioNoDisponible {
      final respaldo = await _conTokenGuardado(tokenPJ);
      if (ref.mounted) state = respaldo ?? const SesionIdentidadNoDisponible();
    } on ErrorApi catch (e) {
      if (ref.mounted) state = SesionConError(e.mensaje);
    }
  }

  /// Spec 9: si el PJ no responde pero el token Sanctum de esta misma sesión sigue vigente, se sigue.
  Future<EstadoSesion?> _conTokenGuardado(String tokenPJ) async {
    final guardado = await ref.read(almacenTokenProvider).leer(tokenPJ);
    if (guardado == null) return null;

    final api = ref.read(apiProvider);
    final aviso = ref.read(avisoSesionProvider);
    api.cliente.token = guardado;
    aviso.silenciado = true; // un 401 acá significa "token guardado vencido", no "sesión del PJ inválida"
    try {
      return SesionLista(await api.yo());
    } on ErrorApi {
      api.cliente.token = null;
      return null;
    } finally {
      aviso.silenciado = false;
    }
  }
}
