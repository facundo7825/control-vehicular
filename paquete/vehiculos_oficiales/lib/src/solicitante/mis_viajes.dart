import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../avisos/avisos_viaje.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../push/push_modulo.dart';

/// `GET /viajes` (próximas reservas e historial). Se vuelve a pedir con cada aviso push del módulo
/// (reserva aceptada o rechazada, recordatorios…).
final misViajesProvider = FutureProvider.autoDispose<MisViajes>((ref) async {
  final escucha = ref.watch(pushModuloProvider).avisos.listen((_) => ref.invalidateSelf());
  ref.onDispose(() => unawaited(escucha.cancel()));
  final mis = await ref.watch(apiProvider).misViajes();
  // Sin push (o sin socket de reservas), una reserva aceptada se nota al recargar la lista.
  if (ref.mounted) ref.read(avisosViajeProvider.notifier).alCargarMisViajes(mis);
  return mis;
});

/// `GET /viajes/{id}`: el detalle de un viaje del historial, siempre pedido de nuevo al abrirlo.
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
