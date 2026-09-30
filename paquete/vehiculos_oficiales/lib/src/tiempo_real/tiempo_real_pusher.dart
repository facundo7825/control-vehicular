import 'dart:async';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';

import '../api/api_vehiculos.dart';
import '../config.dart';
import 'tiempo_real.dart';

/// Autoriza los canales privados contra `POST /api/broadcasting/auth` con el token **Sanctum**
/// (vía [ApiVehiculos], así un 401 también dispara `onSesionInvalida`).
class AutorizacionCanalApi
    implements EndpointAuthorizableChannelAuthorizationDelegate<PrivateChannelAuthorizationData> {
  AutorizacionCanalApi(this._api);

  final ApiVehiculos _api;

  @override
  EndpointAuthFailedCallback? get onAuthFailed => null;

  @override
  Future<PrivateChannelAuthorizationData> authorizationData(String socketId, String channelName) async =>
      PrivateChannelAuthorizationData(
        authKey: await _api.autorizarCanal(socketId: socketId, canal: channelName),
      );
}

/// [TiempoReal] sobre `dart_pusher_channels` (Dart puro: Android, iOS y web) contra Laravel Reverb.
class TiempoRealPusher implements TiempoReal {
  TiempoRealPusher({
    required VehiculosOficialesConfig config,
    required ApiVehiculos api,
    PusherChannelsConnection Function()? conexion,
    Duration esperaReconexion = const Duration(seconds: 3),
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
      _cambiar(EstadoConexion.desconectado);
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
            _cambiar(EstadoConexion.conectado);
          case PusherChannelsClientLifeCycleState.pendingConnection:
            _cambiar(EstadoConexion.conectando);
          // Tras una caída la librería pasa directo a reconnecting: el socket ya no está.
          case PusherChannelsClientLifeCycleState.reconnecting ||
              PusherChannelsClientLifeCycleState.connectionError ||
              PusherChannelsClientLifeCycleState.disconnected ||
              PusherChannelsClientLifeCycleState.gotPusherError ||
              PusherChannelsClientLifeCycleState.disposed:
            _cambiar(EstadoConexion.desconectado);
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

  late final PusherChannelsClient _cliente;
  final AutorizacionCanalApi _autorizacion;
  final _estados = StreamController<EstadoConexion>.broadcast();
  final _canales = <String, _CanalActivo>{};
  final _suscripciones = <StreamSubscription<Object?>>[];
  EstadoConexion _estado = EstadoConexion.desconectado;

  @override
  EstadoConexion get estado => _estado;

  @override
  Stream<EstadoConexion> get estados => _estados.stream;

  void _cambiar(EstadoConexion nuevo) {
    if (nuevo == _estado) return;
    _estado = nuevo;
    _estados.add(nuevo);
  }

  @override
  void conectar() {
    _cambiar(EstadoConexion.conectando);
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
          if (e.name.startsWith('pusher:') || e.name.startsWith('pusher_internal:')) return;
          final datos = e.tryGetDataAsMap();
          if (datos != null) activo.eventos.add(EventoTiempoReal(nombre, e.name, datos));
        });
        privado.subscribe();
      },
      onCancel: () {
        unawaited(escucha?.cancel());
        privado.unsubscribe();
        _canales.remove(nombre);
      },
    );
    activo = _CanalActivo(privado, controlador);
    _canales[nombre] = activo;
    return controlador.stream;
  }

  @override
  void cerrar() {
    for (final s in _suscripciones) {
      unawaited(s.cancel());
    }
    for (final c in _canales.values) {
      unawaited(c.eventos.close());
    }
    _canales.clear();
    _cliente.dispose();
    _cambiar(EstadoConexion.desconectado);
    unawaited(_estados.close());
  }
}

class _CanalActivo {
  _CanalActivo(this.canal, this.eventos);

  final PrivateChannel canal;
  final StreamController<EventoTiempoReal> eventos;
}
