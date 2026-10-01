import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

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
