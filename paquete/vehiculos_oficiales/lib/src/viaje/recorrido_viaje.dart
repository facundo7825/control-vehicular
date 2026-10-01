import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../mapa/ruta.dart';
import '../modelos/modelos.dart';
import 'viaje_actual.dart';

final tramoViajeProvider = NotifierProvider.autoDispose<TramoViajeNotifier, TramoRuta?>(TramoViajeNotifier.new);

/// El tramo del recorrido que ve el solicitante en la pantalla del viaje. Mientras el chofer viene (aceptado,
/// en camino, llegó), el recorrido pedido: origen → destino. En curso, desde la posición del chofer al
/// destino, cambiando como mucho cada [intervalo] (al vencer, con la última posición recibida). Sin viaje
/// activo, nulo. El timer vive acá, no en la pantalla.
class TramoViajeNotifier extends Notifier<TramoRuta?> {
  static const intervalo = Duration(seconds: 30);

  Timer? _espera;

  /// Posición llegada durante la [_espera]: se usa al terminar.
  Coordenada? _pendiente;

  @override
  TramoRuta? build() {
    ref.onDispose(() {
      _espera?.cancel();
      _espera = null;
      _pendiente = null;
    });
    // Al cambiar el viaje o su estado se arma el tramo de nuevo.
    final (id, estado) = ref.watch(
      viajeActualProvider.select((s) {
        final v = s.value?.viaje;
        return (v?.id, v?.estado);
      }),
    );
    final viaje = ref.read(viajeActualProvider).value?.viaje;
    if (id == null || viaje == null || !_conRecorrido(estado)) return null;
    final origen = viaje.origen.coordenada;
    final destino = viaje.destino.coordenada;
    if (estado != EstadoViaje.enCurso) return TramoRuta(origen, destino);

    ref.listen(viajeActualProvider.select((s) => s.value?.ubicacionChofer?.posicion), (_, posicion) {
      if (posicion != null) _desde(posicion, destino);
    });
    final posicion = ref.read(viajeActualProvider).value?.ubicacionChofer?.posicion;
    // Sin posición del chofer todavía, el recorrido pedido; la primera posición lo cambia enseguida.
    if (posicion == null) return TramoRuta(origen, destino);
    _esperar(destino);
    return TramoRuta(posicion, destino);
  }

  static bool _conRecorrido(EstadoViaje? e) =>
      e == EstadoViaje.aceptado || e == EstadoViaje.enCamino || e == EstadoViaje.llego || e == EstadoViaje.enCurso;

  void _desde(Coordenada posicion, Coordenada destino) {
    if (_espera != null) {
      _pendiente = posicion;
      return;
    }
    state = TramoRuta(posicion, destino);
    _esperar(destino);
  }

  void _esperar(Coordenada destino) {
    _espera = Timer(intervalo, () {
      _espera = null;
      final posicion = _pendiente;
      _pendiente = null;
      if (posicion != null && ref.mounted) _desde(posicion, destino);
    });
  }
}

final rutaViajeProvider = NotifierProvider.autoDispose<RutaViajeNotifier, Ruta?>(RutaViajeNotifier.new);

/// La ruta de [tramoViajeProvider]. Mientras se pide la de un tramo nuevo sigue la anterior (así la línea
/// no parpadea cada vez que se recalcula); si no hay ruta, nula.
class RutaViajeNotifier extends Notifier<Ruta?> {
  @override
  Ruta? build() {
    final tramo = ref.watch(tramoViajeProvider);
    if (tramo == null) return null;
    final ruta = ref.watch(rutaProvider(tramo));
    return ruta.isLoading ? stateOrNull : ruta.value;
  }
}
