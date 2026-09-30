import 'dart:async';

import 'package:flutter/foundation.dart';

import '../modelos/modelos.dart';
import '../ubicacion/ubicador.dart';
import 'cola_ubicaciones.dart';
import 'emisor_ubicacion.dart';

/// Une el GPS del turno con la cola y el emisor (decisiones 4 y 5): cada punto va a la cola y cada
/// [intervaloTurno] (o [intervaloViaje] con un viaje activo) sale un lote. Es dueño del stream del GPS
/// y del timer de envío: [detener] los libera.
class RastreadorTurno {
  RastreadorTurno({
    required this.ubicador,
    required this.cola,
    required this.emisor,
    required this.intervaloTurno,
    required this.intervaloViaje,
    required this.alPunto,
    required this.alErrorGps,
    required this.alQuedarSinTurno,
  });

  final Ubicador ubicador;
  final ColaUbicaciones cola;
  final EmisorUbicacion emisor;
  final Duration intervaloTurno;
  final Duration intervaloViaje;

  /// Cada punto del GPS (para mostrar la posición propia).
  final void Function(PuntoGps punto) alPunto;

  /// El GPS falló (permiso revocado, GPS apagado…). El envío de lo pendiente sigue.
  final void Function(Object error) alErrorGps;

  /// El backend respondió que no hay turno (o que ya no es chofer). El rastreo ya está detenido.
  final void Function() alQuedarSinTurno;

  StreamSubscription<PuntoGps>? _gps;
  Timer? _envio;
  bool _activo = false;
  bool _enViaje = false;

  bool get activo => _activo;

  Duration get intervalo => _enViaje ? intervaloViaje : intervaloTurno;

  void iniciar({bool enViaje = false}) {
    if (_activo) return;
    _activo = true;
    _enViaje = enViaje;
    _abrir();
  }

  /// Con un viaje activo el GPS y el envío pasan a [intervaloViaje]; sin viaje, a [intervaloTurno].
  void enViaje(bool activo) {
    if (_enViaje == activo) return;
    _enViaje = activo;
    if (_activo) _abrir();
  }

  /// Vuelve a abrir el GPS (después de que falló) sin tocar la cola.
  void reabrirGps() {
    if (_activo) _abrir();
  }

  void _abrir() {
    _envio?.cancel();
    // geolocator_android reutiliza el stream mientras alguien lo escuche: se cancela el anterior antes de
    // abrir otro con el intervalo nuevo. La cancelación suelta el stream en el momento (no hace falta
    // esperar el Future de `cancel`).
    _cancelar(_gps);
    _gps = null;
    try {
      _gps = ubicador.seguir(intervalo).listen(_alPunto, onError: alErrorGps);
    } catch (e) {
      alErrorGps(e); // un plugin que lanza al abrir el stream (p. ej. sin implementación en la plataforma)
    }
    _envio = Timer.periodic(intervalo, (_) => unawaited(_enviar()));
  }

  void _alPunto(PuntoGps p) {
    cola.agregar(p);
    alPunto(p);
  }

  Future<void> _enviar() async {
    final resultado = await emisor.enviar();
    if (resultado == ResultadoEnvio.sinTurno && _activo) {
      detener();
      alQuedarSinTurno();
    }
  }

  /// Un intento de mandar todo lo pendiente (antes de finalizar el turno).
  Future<ResultadoEnvio> vaciar() => emisor.vaciarTodo();

  /// Corta el GPS (y con él la notificación fija de Android) y el envío. Lo pendiente se descarta.
  void detener() {
    _activo = false;
    _envio?.cancel();
    _envio = null;
    _cancelar(_gps);
    _gps = null;
    cola.vaciar();
  }

  static void _cancelar(StreamSubscription<PuntoGps>? gps) {
    if (gps == null) return;
    unawaited(gps.cancel().catchError((Object e) => debugPrint('vehiculos_oficiales: error al cortar el GPS ($e).')));
  }
}
