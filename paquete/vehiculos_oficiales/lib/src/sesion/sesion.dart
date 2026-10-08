import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../chofer/almacen_cola.dart';
import '../chofer/cola_acciones.dart';
import '../chofer/estado_guardado.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';
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
      final antes = state;
      _dejarDeConfirmar();
      state = const SesionVencida();
      unawaited(_almacenar('borrar', (a) => a.borrar()));
      if (antes is SesionLista) unawaited(_borrarCola(antes.usuario.id));
    });
    ref.onDispose(_dejarDeConfirmar);
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
      // Si no se puede guardar, igual se sigue: solo se pierde el respaldo ante una caída del PJ o sin señal.
      await _almacenar('guardar', (a) => a.guardar(tokenPJ, r.token, usuario: r.usuario.toJson()));
      if (ref.mounted) _lista(SesionLista(r.usuario));
    } on SinConexion catch (e) {
      // Sin señal al abrir (el chofer en la ruta): con la sesión guardada de este mismo token del PJ se sigue
      // con ese usuario, sin preguntarle al servidor, y se confirma al volver la señal.
      final guardada = await _sesionGuardada(tokenPJ);
      if (!ref.mounted) return;
      if (guardada == null) {
        state = SesionConError(e.mensaje);
      } else {
        _lista(guardada);
        _confirmarAlVolver(tokenPJ);
      }
    } on SesionInvalida {
      // El aviso ya pasó el estado a SesionVencida y llamó a la app principal.
    } on AccesoDenegado catch (e) {
      if (ref.mounted) state = SesionDeshabilitada(e.mensaje);
    } on ServicioNoDisponible {
      final respaldo = await _conTokenGuardado(tokenPJ);
      if (!ref.mounted) return;
      if (respaldo != null) {
        _lista(respaldo);
      } else {
        state = const SesionIdentidadNoDisponible();
      }
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

  /// Lo pendiente de cada chofer va en sus propios archivos: otra sesión en el mismo teléfono (otro chofer o
  /// alguien que no es chofer) no lo toca ni lo manda. Lo que nadie retomó en 24 h se borra
  /// ([purgarPendientesViejosProvider], spec 10).
  void _lista(SesionLista lista) {
    state = lista;
    unawaited(_purgarViejos());
  }

  /// Nunca lanza.
  Future<void> _purgarViejos() async {
    try {
      await ref.read(purgarPendientesViejosProvider)();
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo purgar lo pendiente viejo (${e.runtimeType}).');
    }
  }

  /// Spec 10: al cerrarse la sesión (401) no quedan en el dispositivo ubicaciones del turno sin enviar de
  /// [usuarioId]; tampoco sus acciones del viaje, sus puntos de turnos cerrados ni la copia del turno y del viaje
  /// guardada para abrir sin señal. Nunca lanza.
  Future<void> _borrarCola(int usuarioId) async {
    final borrados = <(String, Future<void> Function())>[
      ('la cola de ubicaciones', () => ref.read(almacenColaProvider).borrar(usuarioId)),
      ('las ubicaciones sin turno', () => ref.read(almacenSinTurnoProvider).borrar(usuarioId)),
      ('la cola de acciones', () => ref.read(almacenAccionesProvider).borrar(usuarioId)),
      ('el turno guardado', ref.read(almacenTurnoGuardadoProvider).borrar),
      ('el viaje guardado', ref.read(almacenViajeGuardadoProvider).borrar),
    ];
    for (final (que, borrar) in borrados) {
      try {
        await borrar();
      } catch (e) {
        debugPrint('vehiculos_oficiales: no se pudo borrar $que (${e.runtimeType}).');
      }
    }
  }

  /// La sesión guardada para [tokenPJ] (token Sanctum y usuario), con el token ya puesto en el cliente; nula si
  /// falta alguno de los dos. Nunca lanza.
  Future<SesionLista?> _sesionGuardada(String tokenPJ) async {
    final token = await _almacenar('leer', (a) => a.leer(tokenPJ));
    final usuario = await _almacenar('leer', (a) => a.leerUsuario(tokenPJ));
    if (token == null || usuario == null) return null;
    try {
      final lista = SesionLista(Usuario.fromJson(usuario));
      ref.read(apiProvider).cliente.token = token;
      return lista;
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo leer el usuario guardado (${e.runtimeType}).');
      return null;
    }
  }

  /// Cada cuánto se reintenta confirmar una sesión que se abrió sin señal (además de al reconectar el socket).
  static const intervaloConfirmacion = Duration(seconds: 30);

  Timer? _confirmacion;
  ProviderSubscription<EstadoConexion>? _escuchaConexion;
  bool _confirmando = false;

  /// Una sesión abierta con lo guardado se confirma con el servidor al reconectar el socket y cada
  /// [intervaloConfirmacion], hasta que responda.
  void _confirmarAlVolver(String tokenPJ) {
    _confirmacion ??= Timer.periodic(intervaloConfirmacion, (_) => unawaited(_confirmar(tokenPJ)));
    _escuchaConexion ??= ref.listen(estadoConexionProvider, (antes, ahora) {
      if (ahora == EstadoConexion.conectado && antes != EstadoConexion.conectado) unawaited(_confirmar(tokenPJ));
    });
  }

  void _dejarDeConfirmar() {
    _confirmacion?.cancel();
    _confirmacion = null;
    _escuchaConexion?.close();
    _escuchaConexion = null;
  }

  /// Vuelve a intercambiar el token del PJ. El mismo usuario sigue con la misma sesión (no se reinicia el
  /// módulo: el turno y su GPS siguen); otro, o con otro rol, la reemplaza. Un 401 vence la sesión como
  /// siempre (el aviso borra lo guardado). Sin red (o el servidor caído) se reintenta más tarde. Nunca lanza.
  Future<void> _confirmar(String tokenPJ) async {
    final aviso = ref.read(avisoSesionProvider);
    // Una sesión que venció (401 de cualquier pedido) no se vuelve a confirmar ni a guardar.
    bool vigente() => ref.mounted && !aviso.avisado && state is SesionLista;
    if (_confirmando || !vigente()) return;
    _confirmando = true;
    final api = ref.read(apiProvider);
    try {
      final r = await api.intercambiar(tokenPJ);
      if (!vigente()) return;
      api.cliente.token = r.token;
      await _almacenar('guardar', (a) => a.guardar(tokenPJ, r.token, usuario: r.usuario.toJson()));
      if (!vigente()) {
        // Venció mientras se guardaba: no queda nada guardado.
        await _almacenar('borrar', (a) => a.borrar());
        return;
      }
      _dejarDeConfirmar();
      final actual = state;
      if (actual is SesionLista && actual.usuario.id == r.usuario.id && actual.usuario.rol == r.usuario.rol) return;
      _lista(SesionLista(r.usuario));
    } on SesionInvalida {
      _dejarDeConfirmar(); // el aviso ya pasó a SesionVencida
    } on AccesoDenegado catch (e) {
      _dejarDeConfirmar();
      if (vigente()) state = SesionDeshabilitada(e.mensaje);
    } on SinConexion {
      // Sin red: la sesión guardada sigue y se reintenta.
    } on ErrorServidor {
      // 5xx: se reintenta.
    } on ServicioNoDisponible {
      // El PJ caído (503): se reintenta.
    } on ErrorApi catch (e) {
      // Cualquier otro rechazo (422, 404…) no se arregla reintentando: la sesión guardada sigue hasta que un
      // pedido diga lo contrario.
      debugPrint('vehiculos_oficiales: no se pudo confirmar la sesión guardada (${e.runtimeType}).');
      _dejarDeConfirmar();
    } catch (e) {
      debugPrint('vehiculos_oficiales: error inesperado al confirmar la sesión (${e.runtimeType}).');
    } finally {
      _confirmando = false;
    }
  }

  /// Spec 9: si el PJ no responde pero el token Sanctum de esta misma sesión sigue vigente, se sigue.
  Future<SesionLista?> _conTokenGuardado(String tokenPJ) async {
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
