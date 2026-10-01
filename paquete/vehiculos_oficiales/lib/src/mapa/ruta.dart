import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';

/// Origen y destino de un recorrido, redondeados a 4 decimales (unos 11 m), como los cachea el backend:
/// dos tramos que redondean igual son el mismo y comparten la ruta.
@immutable
class TramoRuta {
  TramoRuta(Coordenada origen, Coordenada destino) : origen = _redondear(origen), destino = _redondear(destino);

  final Coordenada origen;
  final Coordenada destino;

  /// Origen y destino en el mismo lugar: no hay recorrido que pedir.
  bool get vacio => origen == destino;

  static Coordenada _redondear(Coordenada c) =>
      Coordenada((c.lat * 10000).roundToDouble() / 10000, (c.lng * 10000).roundToDouble() / 10000);

  @override
  bool operator ==(Object other) => other is TramoRuta && other.origen == origen && other.destino == destino;

  @override
  int get hashCode => Object.hash(origen, destino);

  @override
  String toString() => 'TramoRuta($origen → $destino)';
}

/// El recorrido de un [TramoRuta] (`GET /ruta`), pedido una sola vez mientras alguien lo mire. Nulo si no
/// hay recorrido: origen y destino en el mismo lugar, el proveedor no pudo armarlo o la API falló. Nunca
/// queda en error por la API: sin ruta, la app solo deja de mostrar el recorrido.
final rutaProvider = FutureProvider.autoDispose.family<Ruta?, TramoRuta>((ref, tramo) async {
  if (tramo.vacio) return null;
  try {
    return await ref.watch(apiProvider).obtenerRuta(tramo.origen, tramo.destino);
  } on ErrorApi catch (e) {
    debugPrint('vehiculos_oficiales: no se pudo obtener la ruta (${e.runtimeType}).');
    return null;
  }
});
