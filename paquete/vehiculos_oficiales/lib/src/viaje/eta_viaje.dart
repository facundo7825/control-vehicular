import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../tiempo_real/tiempo_real_provider.dart';
import 'viaje_actual.dart';

final etaViajeProvider = NotifierProvider<EtaViajeNotifier, Eta?>(EtaViajeNotifier.new);

/// Llegada estimada del viaje actual (`GET /viajes/{id}/eta`). Consulta al arrancar, cada 30 s y enseguida
/// cuando cambia el estado del viaje, y solo mientras el chofer viene (aceptado, en camino) o lleva al
/// solicitante (en curso). Con otro estado, o sin viaje, no hay timer ni valor. El timer vive acá, no en la pantalla.
class EtaViajeNotifier extends Notifier<Eta?> {
  @override
  Eta? build() {
    // Al cambiar el viaje o su estado se reconstruye: se descarta el valor (`hacia` puede haber cambiado)
    // y se consulta de nuevo.
    final (id, estado) = ref.watch(
      viajeActualProvider.select((s) {
        final v = s.value?.viaje;
        return (v?.id, v?.estado);
      }),
    );
    final intervalo = ref.read(intervaloEtaProvider);
    if (id == null || !_seConsulta(estado)) return null;

    var vigente = true;
    final timer = Timer.periodic(intervalo, (_) => unawaited(_consultar(id, () => vigente)));
    ref.onDispose(() {
      vigente = false;
      timer.cancel();
    });
    unawaited(_consultar(id, () => vigente));
    return null;
  }

  static bool _seConsulta(EstadoViaje? e) =>
      e == EstadoViaje.aceptado || e == EstadoViaje.enCamino || e == EstadoViaje.enCurso;

  Future<void> _consultar(int viajeId, bool Function() vigente) async {
    try {
      final eta = await ref.read(apiProvider).eta(viajeId);
      if (vigente() && ref.mounted) state = eta;
    } on ErrorApi {
      // Incluye `SesionInvalida` (`ClienteApi` ya avisó una sola vez): se conserva el último valor y se
      // reintenta en el próximo ciclo. Corre sin await desde el timer, así que no puede propagar el error.
    }
  }
}
