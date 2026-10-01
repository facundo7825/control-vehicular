import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show ProviderListenable;

import '../mapa/ruta.dart';
import '../modelos/modelos.dart';
import 'viaje_actual.dart';

/// Deja pasar un valor a [alPasar] como mucho una vez cada [intervalo]. Lo que llega mientras tanto queda
/// pendiente (solo el último) y pasa al vencer. Lo usa un notifier, que lo cancela en `onDispose`.
class Limitador<T extends Object> {
  Limitador(this.intervalo, this.alPasar);

  final Duration intervalo;
  final void Function(T valor) alPasar;

  Timer? _espera;
  T? _pendiente;

  void poner(T valor) {
    if (_espera != null) {
      _pendiente = valor;
      return;
    }
    alPasar(valor);
    esperar();
  }

  /// Empieza un intervalo sin pasar nada (p. ej. después del valor inicial).
  void esperar() {
    _espera?.cancel();
    _espera = Timer(intervalo, () {
      _espera = null;
      final valor = _pendiente;
      _pendiente = null;
      if (valor != null) poner(valor);
    });
  }

  /// Lo pendiente ya no hace falta.
  void descartarPendiente() => _pendiente = null;

  void cancelar() {
    _espera?.cancel();
    _espera = null;
    _pendiente = null;
  }
}

final tramoViajeProvider = NotifierProvider.autoDispose<TramoViajeNotifier, TramoRuta?>(TramoViajeNotifier.new);

/// El tramo del recorrido que ve el solicitante en la pantalla del viaje. Mientras el chofer viene (aceptado,
/// en camino, llegó), el recorrido pedido: origen → destino. En curso, desde la posición del chofer al
/// destino, cambiando como mucho cada [intervalo] (al vencer, con la última posición recibida). Sin viaje
/// activo, nulo. El timer vive acá, no en la pantalla.
class TramoViajeNotifier extends Notifier<TramoRuta?> {
  static const intervalo = Duration(seconds: 30);

  @override
  TramoRuta? build() {
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

    final limitador = Limitador<Coordenada>(intervalo, (posicion) {
      if (ref.mounted) state = TramoRuta(posicion, destino);
    });
    ref.onDispose(limitador.cancelar);
    ref.listen(viajeActualProvider.select((s) => s.value?.ubicacionChofer?.posicion), (_, posicion) {
      if (posicion != null) limitador.poner(posicion);
    });
    final posicion = ref.read(viajeActualProvider).value?.ubicacionChofer?.posicion;
    // Sin posición del chofer todavía, el recorrido pedido; la primera posición lo cambia enseguida.
    if (posicion == null) return TramoRuta(origen, destino);
    limitador.esperar();
    return TramoRuta(posicion, destino);
  }

  static bool _conRecorrido(EstadoViaje? e) =>
      e == EstadoViaje.aceptado || e == EstadoViaje.enCamino || e == EstadoViaje.llego || e == EstadoViaje.enCurso;
}

final rutaViajeProvider = NotifierProvider.autoDispose<RutaDeTramoNotifier, Ruta?>(
  () => RutaDeTramoNotifier(tramoViajeProvider),
);

/// La ruta del tramo que da [tramo]. Mientras se pide la de un tramo nuevo hacia el mismo destino, o si esa no
/// llega (falló), sigue la anterior: así la línea no parpadea ni se pierde al recalcular. Hacia otro destino,
/// la nueva o nula.
class RutaDeTramoNotifier extends Notifier<Ruta?> {
  RutaDeTramoNotifier(this.tramo);

  final ProviderListenable<TramoRuta?> tramo;

  TramoRuta? _anterior;

  @override
  Ruta? build() {
    final actual = ref.watch(tramo);
    final mismoDestino = actual != null && _anterior?.destino == actual.destino;
    _anterior = actual;
    if (actual == null) return null;
    final ruta = ref.watch(rutaProvider(actual));
    final nueva = ruta.isLoading ? null : ruta.value;
    return nueva ?? (mismoDestino ? stateOrNull : null);
  }
}
