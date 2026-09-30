import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import 'tiempo_real.dart';
import 'tiempo_real_pusher.dart';

/// Conexión única a Reverb. Se crea (y conecta) la primera vez que la lee una pantalla con sesión lista.
final tiempoRealProvider = Provider<TiempoReal>((ref) {
  final tr = TiempoRealPusher(config: ref.watch(entornoProvider).config, api: ref.watch(apiProvider));
  ref.onDispose(tr.cerrar);
  tr.conectar();
  return tr;
});

/// Spec 6: con el WebSocket caído se consulta la API cada 10 s.
final intervaloRespaldoProvider = Provider<Duration>((ref) => const Duration(seconds: 10));

/// Cada cuánto se vuelve a pedir la llegada estimada del viaje activo (el backend la cachea 30 s).
final intervaloEtaProvider = Provider<Duration>((ref) => const Duration(seconds: 30));

/// Estado del socket, para avisar en pantalla cuando se está actualizando por sondeo.
final estadoConexionProvider = NotifierProvider<EstadoConexionNotifier, EstadoConexion>(EstadoConexionNotifier.new);

class EstadoConexionNotifier extends Notifier<EstadoConexion> {
  @override
  EstadoConexion build() {
    final tr = ref.watch(tiempoRealProvider);
    final escucha = tr.estados.listen((e) => state = e);
    ref.onDispose(() => unawaited(escucha.cancel()));
    return tr.estado;
  }
}
