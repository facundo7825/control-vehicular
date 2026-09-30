import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../chofer/almacen_cola.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import 'almacen_token.dart';

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
      unawaited(_almacenar('borrar', (a) => a.borrar()));
      unawaited(_borrarCola());
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

    try {
      final r = await api.intercambiar(tokenPJ);
      api.cliente.token = r.token;
      // Si no se puede guardar, igual se sigue: solo se pierde el respaldo ante una caída del PJ.
      await _almacenar('guardar', (a) => a.guardar(tokenPJ, r.token));
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
    } catch (e) {
      // Cualquier otra cosa (un bug, una respuesta rara): no se queda "iniciando" para siempre.
      debugPrint('vehiculos_oficiales: error inesperado al iniciar la sesión (${e.runtimeType}).');
      if (ref.mounted) state = const SesionConError('Ocurrió un error inesperado.');
    }
  }

  /// El almacén seguro es un respaldo: si falla (p. ej. `PlatformException` del keystore) se sigue sin él.
  Future<T?> _almacenar<T>(String accion, Future<T> Function(AlmacenToken almacen) f) async {
    try {
      return await f(ref.read(almacenTokenProvider));
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo $accion el token guardado (${e.runtimeType}).');
      return null;
    }
  }

  /// Spec 10: al cerrarse la sesión (401) no quedan en el dispositivo ubicaciones del turno sin enviar.
  Future<void> _borrarCola() async {
    try {
      await ref.read(almacenColaProvider).borrar();
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo borrar la cola de ubicaciones guardada (${e.runtimeType}).');
    }
  }

  /// Spec 9: si el PJ no responde pero el token Sanctum de esta misma sesión sigue vigente, se sigue.
  Future<EstadoSesion?> _conTokenGuardado(String tokenPJ) async {
    final guardado = await _almacenar('leer', (a) => a.leer(tokenPJ));
    if (guardado == null) return null;

    final api = ref.read(apiProvider);
    final aviso = ref.read(avisoSesionProvider);
    api.cliente.token = guardado;
    aviso.silenciado = true; // un 401 acá significa "token guardado vencido", no "sesión del PJ inválida"
    try {
      return SesionLista(await api.yo());
    } catch (_) {
      // ErrorApi (401: el token guardado venció) o cualquier otro: no sirve, identidad no disponible.
      api.cliente.token = null;
      return null;
    } finally {
      aviso.silenciado = false;
    }
  }
}
