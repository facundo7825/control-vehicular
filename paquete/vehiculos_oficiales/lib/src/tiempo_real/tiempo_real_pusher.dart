import 'dart:async';
import 'dart:math';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';
import 'package:flutter/foundation.dart';

import '../api/api_vehiculos.dart';
import '../config.dart';
import 'tiempo_real.dart';

/// Autoriza los canales privados contra `POST /api/broadcasting/auth` con el token **Sanctum**
/// (vía [ApiVehiculos], así un 401 también dispara `onSesionInvalida`).
class AutorizacionCanalApi
    implements EndpointAuthorizableChannelAuthorizationDelegate<PrivateChannelAuthorizationData> {
  AutorizacionCanalApi(this._api);

  final ApiVehiculos _api;

  /// El fallo también llega al canal como `pusher:subscription_error`; ahí lo maneja [TiempoRealPusher].
  @override
  EndpointAuthFailedCallback? get onAuthFailed => null;

  @override
  Future<PrivateChannelAuthorizationData> authorizationData(String socketId, String channelName) async =>
      PrivateChannelAuthorizationData(
        authKey: await _api.autorizarCanal(socketId: socketId, canal: channelName),
      );
}

/// [TiempoReal] sobre `dart_pusher_channels` (Dart puro: Android, iOS y web) contra Laravel Reverb.
///
/// Si la suscripción a un canal falla (p. ej. `/broadcasting/auth` responde 500) con el socket conectado,
/// se reintenta con esperas crecientes ([esperasReintentoCanal]; la última se repite) mientras alguien
/// escuche el canal. Hasta que se suscriba, [estado] se informa como `desconectado`: sin suscripción no
/// llegan eventos, así que quien usa el canal consulta la API (`Respaldo`); al suscribirse vuelve a
/// `conectado` y `Respaldo` pide el estado completo.
class TiempoRealPusher implements TiempoReal {
  TiempoRealPusher({
    required VehiculosOficialesConfig config,
    required ApiVehiculos api,
    PusherChannelsConnection Function()? conexion,
    Duration esperaReconexion = const Duration(seconds: 3),
    this.esperasReintentoCanal = const [
      Duration(seconds: 2),
      Duration(seconds: 5),
      Duration(seconds: 10),
      Duration(seconds: 30),
    ],
  }) : _autorizacion = AutorizacionCanalApi(api) {
    final opciones = PusherChannelsOptions.fromHost(
      scheme: config.reverbWsScheme,
      host: config.reverbHost,
      port: config.reverbPort,
      key: config.reverbKey,
      shouldSupplyMetadataQueries: true,
      metadata: PusherChannelsOptionsMetadata.byDefault(),
    );
    void alFallar(Object? error, StackTrace traza, void Function() reintentar) {
      _cambiarSocket(EstadoConexion.desconectado);
      reintentar(); // el cliente espera minimumReconnectDelayDuration entre intentos
    }

    _cliente = conexion == null
        ? PusherChannelsClient.websocket(
            options: opciones,
            connectionErrorHandler: alFallar,
            minimumReconnectDelayDuration: esperaReconexion,
          )
        : PusherChannelsClient.custom(
            connectionDelegate: conexion,
            connectionErrorHandler: alFallar,
            minimumReconnectDelayDuration: esperaReconexion,
          );

    _suscripciones.add(
      _cliente.lifecycleStream.listen((ciclo) {
        switch (ciclo) {
          case PusherChannelsClientLifeCycleState.establishedConnection:
            _cambiarSocket(EstadoConexion.conectado);
          case PusherChannelsClientLifeCycleState.pendingConnection:
            _cambiarSocket(EstadoConexion.conectando);
          // Tras una caída la librería pasa directo a reconnecting: el socket ya no está.
          case PusherChannelsClientLifeCycleState.reconnecting ||
              PusherChannelsClientLifeCycleState.connectionError ||
              PusherChannelsClientLifeCycleState.disconnected ||
              PusherChannelsClientLifeCycleState.gotPusherError ||
              PusherChannelsClientLifeCycleState.disposed:
            _cambiarSocket(EstadoConexion.desconectado);
          case PusherChannelsClientLifeCycleState.inactive:
            break;
        }
      }),
    );
    // Recomendación de la librería: (re)suscribir en cada conexión establecida.
    _suscripciones.add(
      _cliente.onConnectionEstablished.listen((_) {
        for (final c in _canales.values) {
          c.canal.subscribeIfNotUnsubscribed();
        }
      }),
    );
  }

  /// Esperas entre reintentos de suscripción a un canal que falló; la última se repite.
  final List<Duration> esperasReintentoCanal;

  late final PusherChannelsClient _cliente;
  final AutorizacionCanalApi _autorizacion;
  final _estados = StreamController<EstadoConexion>.broadcast();
  final _canales = <String, _CanalActivo>{};
  final _suscripciones = <StreamSubscription<Object?>>[];

  /// Estado del WebSocket. [_estado] es lo que se informa: además tiene en cuenta los canales fallidos.
  EstadoConexion _socket = EstadoConexion.desconectado;
  EstadoConexion _estado = EstadoConexion.desconectado;

  @override
  EstadoConexion get estado => _estado;

  @override
  Stream<EstadoConexion> get estados => _estados.stream;

  void _cambiarSocket(EstadoConexion nuevo) {
    _socket = nuevo;
    _publicar();
  }

  /// Con el socket arriba pero algún canal sin poder suscribirse, para quien escucha es como no tener socket.
  void _publicar() {
    final hayFallidos = _canales.values.any((c) => c.fallos > 0);
    final nuevo = _socket == EstadoConexion.conectado && hayFallidos ? EstadoConexion.desconectado : _socket;
    if (nuevo == _estado || _estados.isClosed) return;
    _estado = nuevo;
    _estados.add(nuevo);
  }

  @override
  void conectar() {
    _cambiarSocket(EstadoConexion.conectando);
    unawaited(_cliente.connect());
  }

  @override
  Stream<EventoTiempoReal> canal(String nombre) {
    final existente = _canales[nombre];
    if (existente != null) return existente.eventos.stream;

    final privado = _cliente.privateChannel('private-$nombre', authorizationDelegate: _autorizacion);
    late final _CanalActivo activo;
    StreamSubscription<ChannelReadEvent>? escucha;
    final controlador = StreamController<EventoTiempoReal>.broadcast(
      onListen: () {
        escucha = privado.bindToAll().listen((e) {
          if (e.name == Channel.subscriptionSucceededEventName) return _alSuscribirse(activo);
          if (e.name == Channel.subscriptionErrorEventName) return _alFallarSuscripcion(nombre, activo);
          if (e.name.startsWith('pusher:') || e.name.startsWith('pusher_internal:')) return;
          final datos = e.tryGetDataAsMap();
          if (datos != null) activo.eventos.add(EventoTiempoReal(nombre, e.name, datos));
        });
        privado.subscribe();
      },
      onCancel: () {
        unawaited(escucha?.cancel());
        privado.unsubscribe();
        activo.reintento?.cancel();
        _canales.remove(nombre);
        _publicar();
      },
    );
    activo = _CanalActivo(privado, controlador);
    _canales[nombre] = activo;
    return controlador.stream;
  }

  void _alSuscribirse(_CanalActivo activo) {
    activo.reintento?.cancel();
    activo.fallos = 0;
    _publicar();
  }

  void _alFallarSuscripcion(String nombre, _CanalActivo activo) {
    final espera = esperasReintentoCanal[min(activo.fallos, esperasReintentoCanal.length - 1)];
    activo.fallos++;
    debugPrint('vehiculos_oficiales: no se pudo suscribir a $nombre; se reintenta en ${espera.inSeconds} s.');
    _publicar();
    activo.reintento?.cancel();
    activo.reintento = Timer(espera, () {
      if (_canales[nombre] != activo) return; // ya nadie lo escucha
      // Sin socket no se reintenta acá: al reconectar se vuelve a suscribir solo (onConnectionEstablished).
      if (_socket == EstadoConexion.conectado) activo.canal.subscribeIfNotUnsubscribed();
    });
  }

  @override
  void cerrar() {
    for (final s in _suscripciones) {
      unawaited(s.cancel());
    }
    for (final c in _canales.values) {
      c.reintento?.cancel();
      unawaited(c.eventos.close());
    }
    _canales.clear();
    _cliente.dispose();
    _cambiarSocket(EstadoConexion.desconectado);
    unawaited(_estados.close());
  }
}

class _CanalActivo {
  _CanalActivo(this.canal, this.eventos);

  final PrivateChannel canal;
  final StreamController<EventoTiempoReal> eventos;

  /// Suscripciones fallidas seguidas (0 = suscripto o esperando la primera respuesta).
  int fallos = 0;
  Timer? reintento;
}
