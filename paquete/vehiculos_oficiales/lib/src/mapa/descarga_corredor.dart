import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../chofer/turno.dart' show viajeActivo;
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../viaje/viaje_actual.dart';
import 'corredor_teselas.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';
import 'mapa_osm.dart' show descargadorTeselasProvider;
import 'ruta.dart';

/// Cómo va la descarga anticipada del recorrido de un viaje largo.
@immutable
class DescargaCorredor {
  const DescargaCorredor({required this.viajeId, required this.total, this.bajadas = 0, this.terminada = false});

  final int viajeId;
  final int total;
  final int bajadas;

  /// Ya no se pide ninguna más (bajadas todas, o se dejó por falta de señal).
  final bool terminada;

  DescargaCorredor conBajadas(int n) => DescargaCorredor(viajeId: viajeId, total: total, bajadas: n);

  DescargaCorredor terminar(int n) => DescargaCorredor(viajeId: viajeId, total: total, bajadas: n, terminada: true);
}

final descargaCorredorProvider = NotifierProvider.autoDispose<DescargaCorredorNotifier, DescargaCorredor?>(
  DescargaCorredorNotifier.new,
);

/// Decisión 4 del plan del modo sin señal: cuando el chofer sale en un viaje largo ("Voy en camino" en la
/// agenda) se bajan al caché del mapa, en segundo plano, las teselas del recorrido de origen a destino (zoom 10
/// a 14, un corredor de unos 2 km, como mucho [maxTeselasCorredor]), así el mapa se ve en las zonas sin señal
/// de la ruta. También si la app se abre con el viaje ya en camino, en el origen o en curso (lo ya bajado sale
/// del caché sin pedirlo otra vez).
///
/// Si el viaje cambia o termina, o se cierra el módulo, la descarga se cancela: no se pide ninguna tesela más
/// (las que están en vuelo terminan). Nulo sin viaje largo en camino, sin recorrido o sin a dónde bajarlas (ver
/// [descargadorTeselasProvider]). Lo escucha `InicioChofer`, que vive mientras el chofer está en el módulo.
class DescargaCorredorNotifier extends Notifier<DescargaCorredor?> {
  static const zoomMinimo = 10;
  static const zoomMaximo = 14;

  @override
  DescargaCorredor? build() {
    final yo = ref.read(usuarioProvider).id;
    // Cambia con el viaje, no con cada paso del viaje (en camino → llegó → en curso sigue la misma descarga).
    final largo = ref.watch(
      viajeActualProvider.select((s) {
        final v = s.value?.viaje;
        if (v == null || !v.esLargo || !viajeActivo(v, yo) || v.estado == EstadoViaje.aceptado) return null;
        return (id: v.id, tramo: TramoRuta(v.origen.coordenada, v.destino.coordenada));
      }),
    );
    if (largo == null) return null;
    final descargador = ref.watch(descargadorTeselasProvider);
    if (descargador == null) return null;
    final ruta = ref.watch(rutaProvider(largo.tramo)).value;
    // Al reconectar: el recorrido que no llegó se vuelve a pedir, y una descarga que se dejó por falta de señal
    // se retoma (lo ya bajado sale del caché sin pedirlo otra vez).
    ref.listen(estadoConexionProvider, (antes, ahora) {
      if (ahora != EstadoConexion.conectado || antes == EstadoConexion.conectado) return;
      final actual = stateOrNull;
      if (ruta == null || ruta.puntos.isEmpty) {
        ref.invalidate(rutaProvider(largo.tramo));
      } else if (actual != null && actual.terminada && actual.bajadas < actual.total) {
        ref.invalidateSelf();
      }
    });
    if (ruta == null || ruta.puntos.isEmpty) return null;

    final teselas = teselasDelCorredor(
      ruta.puntos,
      zoomMinimo: zoomMinimo,
      zoomMaximo: math.min(zoomMaximo, descargador.zoomMaximo),
    );
    var cancelada = false;
    ref.onDispose(() => cancelada = true);
    unawaited(_descargar(teselas, descargador, () => cancelada));
    return DescargaCorredor(viajeId: largo.id, total: teselas.length);
  }

  Future<void> _descargar(List<Tesela> teselas, DescargadorTeselas descargador, bool Function() cancelada) async {
    try {
      final bajadas = await descargarTeselas(
        teselas,
        descargador.descargar,
        cancelada: cancelada,
        alAvanzar: (n) {
          if (!cancelada() && ref.mounted) state = state?.conBajadas(n);
        },
      );
      if (!cancelada() && ref.mounted) state = state?.terminar(bajadas);
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo bajar el mapa del recorrido (${e.runtimeType}).');
    }
  }
}
