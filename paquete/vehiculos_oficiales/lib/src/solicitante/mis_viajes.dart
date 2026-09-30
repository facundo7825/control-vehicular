import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import '../modelos/modelos.dart';
import '../push/push_modulo.dart';

/// `GET /viajes` (próximas reservas e historial). Se vuelve a pedir con cada aviso push del módulo
/// (reserva aceptada o rechazada, recordatorios…).
final misViajesProvider = FutureProvider.autoDispose<MisViajes>((ref) {
  final escucha = ref.watch(pushModuloProvider).avisos.listen((_) => ref.invalidateSelf());
  ref.onDispose(() => unawaited(escucha.cancel()));
  return ref.watch(apiProvider).misViajes();
});
