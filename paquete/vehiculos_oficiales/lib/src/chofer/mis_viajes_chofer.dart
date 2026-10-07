import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import '../modelos/modelos.dart';

/// `GET /chofer/viajes`: el resumen de hoy y los viajes pasados del chofer, pedidos de nuevo cada vez que
/// se abre "Mis viajes" (o se tira hacia abajo).
final viajesChoferProvider = FutureProvider.autoDispose<ViajesChofer>((ref) => ref.watch(apiProvider).viajesChofer());
