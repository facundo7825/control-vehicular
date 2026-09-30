import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/reloj_servidor.dart';

/// Lo que falta para un vencimiento del backend (`vence_en`), según el reloj del servidor.
/// Se recalcula cada segundo (no se descuenta un contador) y el timer vive acá, no en la pantalla.
final restanteProvider = NotifierProvider.autoDispose.family<RestanteNotifier, Duration, DateTime>(
  RestanteNotifier.new,
);

class RestanteNotifier extends Notifier<Duration> {
  RestanteNotifier(this.vence);

  final DateTime vence;

  @override
  Duration build() {
    final reloj = ref.watch(relojServidorProvider);
    final timer = Timer.periodic(const Duration(seconds: 1), (t) {
      state = reloj.restante(vence);
      if (state == Duration.zero) t.cancel();
    });
    ref.onDispose(timer.cancel);
    return reloj.restante(vence);
  }
}

/// Segundos para mostrar: 11,2 s se muestran como 12 (llega a 0 recién cuando venció).
int segundosRestantes(Duration d) => (d.inMilliseconds / 1000).ceil();
