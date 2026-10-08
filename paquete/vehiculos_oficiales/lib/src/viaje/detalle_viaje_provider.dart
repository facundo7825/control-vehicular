import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';

/// `GET /viajes/{id}`: el detalle de un viaje del historial (del solicitante o del chofer), siempre pedido
/// de nuevo al abrirlo.
final detalleViajeProvider = FutureProvider.autoDispose.family<Viaje, int>(
  (ref, id) => ref.watch(apiProvider).viaje(id),
);

/// `GET /viajes/{id}/recorrido`: el recorrido real de un viaje. Nulo si la API falla: el detalle se
/// muestra igual, sin la línea.
final recorridoRealProvider = FutureProvider.autoDispose.family<RecorridoReal?, int>((ref, id) async {
  try {
    return await ref.watch(apiProvider).recorridoViaje(id);
  } on ErrorApi catch (e) {
    debugPrint('vehiculos_oficiales: no se pudo obtener el recorrido del viaje $id (${e.runtimeType}).');
    return null;
  }
});
