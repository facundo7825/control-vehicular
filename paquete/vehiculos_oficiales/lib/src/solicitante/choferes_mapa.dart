import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../tiempo_real/respaldo.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';

final choferesMapaProvider = AsyncNotifierProvider<ChoferesMapaNotifier, List<ChoferEnMapa>>(ChoferesMapaNotifier.new);

/// Choferes en turno para el mapa del solicitante (spec 7, solicitante 1): `GET /choferes` y después
/// `chofer.ubicacion` / `chofer.estado` por `mapa.choferes`; con el socket caído, `GET /choferes` cada 10 s.
class ChoferesMapaNotifier extends AsyncNotifier<List<ChoferEnMapa>> {
  bool _consultando = false;

  @override
  Future<List<ChoferEnMapa>> build() async {
    final tr = ref.watch(tiempoRealProvider);
    final canal = tr.canal(Canales.mapaChoferes).listen(_alEvento);
    final respaldo = Respaldo(tiempoReal: tr, intervalo: ref.read(intervaloRespaldoProvider), refrescar: refrescar);
    ref.onDispose(() {
      respaldo.cerrar();
      unawaited(canal.cancel());
    });
    return ref.read(apiProvider).choferes();
  }

  Future<void> refrescar() async {
    if (_consultando) return;
    _consultando = true;
    try {
      final lista = await ref.read(apiProvider).choferes();
      if (ref.mounted) state = AsyncData(lista);
    } on SesionInvalida {
      rethrow;
    } on ErrorApi {
      // Se conserva la última lista; el respaldo reintenta.
    } finally {
      _consultando = false;
    }
  }

  void _alEvento(EventoTiempoReal e) {
    final lista = state.value;
    if (lista == null) return;

    switch (e.nombre) {
      case Eventos.choferUbicacion:
        final u = UbicacionChofer.fromJson(e.datos);
        if (!lista.any((c) => c.id == u.choferId)) {
          unawaited(refrescar()); // chofer que recién inició turno: faltan nombre y vehículo
          return;
        }
        state = AsyncData([for (final c in lista) c.id == u.choferId ? c.conUbicacion(u) : c]);
      case Eventos.choferEstado:
        final id = e.datos['chofer_id'] as int;
        final estado = EstadoChofer.desde(e.datos['estado'] as String);
        if (estado == EstadoChofer.fueraDeTurno) {
          state = AsyncData(lista.where((c) => c.id != id).toList());
        } else if (lista.any((c) => c.id == id)) {
          state = AsyncData([for (final c in lista) c.id == id ? c.conEstado(estado) : c]);
        } else {
          unawaited(refrescar());
        }
    }
  }
}
