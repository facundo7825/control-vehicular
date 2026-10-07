import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../push/push_modulo.dart';
import '../sesion/sesion.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';
import '../viaje/viaje_actual.dart';
import 'turno.dart';

/// Avisos push que cambian la agenda (AvisosViaje, AvisosReserva y los jobs de reservas).
const _avisosDeAgenda = {'oferta_reserva', 'recordatorio_reserva', 'alerta_reserva', 'viaje'};

final agendaProvider = AsyncNotifierProvider.autoDispose<AgendaNotifier, Agenda>(AgendaNotifier.new);

/// Spec 7, chofer 5: `GET /agenda`, que se vuelve a pedir con los avisos push de reservas y
/// con cualquier evento de una reserva en `chofer.{id}` (solicitud nueva, reserva cancelada o reasignada).
class AgendaNotifier extends AsyncNotifier<Agenda> {
  @override
  Future<Agenda> build() async {
    final usuario = ref.watch(usuarioProvider);
    final avisos = ref.watch(pushModuloProvider).avisos.listen((a) {
      if (_avisosDeAgenda.contains(a.tipo)) ref.invalidateSelf();
    });
    final canal = ref.watch(tiempoRealProvider).canal(Canales.chofer(usuario.id)).listen((e) {
      if (_esDeAgenda(e.datos)) ref.invalidateSelf();
    });
    ref.onDispose(() {
      unawaited(avisos.cancel());
      unawaited(canal.cancel());
    });
    return ref.read(apiProvider).agenda();
  }

  /// `viaje.actualizado` trae el viaje; `oferta.creada`, `{oferta_id, vence_en, viaje}`. Se mira solo el
  /// tipo, sin leer el resto: un evento raro no puede romper nada.
  static bool _esDeAgenda(Json datos) {
    final viaje = datos['viaje'];
    final tipo = viaje is Map ? viaje['tipo'] : datos['tipo'];
    return tipo == TipoViaje.reserva.name || tipo == TipoViaje.largo.name;
  }

  /// Acepta una solicitud de reserva. 422 si ya no está disponible o se superpone con otra: la agenda se
  /// recarga igual y el error llega a la pantalla.
  Future<void> aceptar(Oferta solicitud) => _responder(() => ref.read(apiProvider).aceptarOferta(solicitud.id));

  Future<void> rechazar(Oferta solicitud) => _responder(() => ref.read(apiProvider).rechazarOferta(solicitud.id));

  /// "Voy en camino" hacia una reserva confirmada o un viaje largo (spec 5.4, paso 7): pasa a ser el viaje
  /// actual. En un largo el backend cambia el vehículo del turno al del viaje: se vuelve a leer el turno (sigue
  /// siendo el mismo, el GPS no se toca) para que el mapa muestre ese vehículo. El refresco no puede fallar
  /// la salida, que ya se hizo.
  Future<void> salir(Viaje reserva) async {
    await ref.read(viajeActualProvider.notifier).salirHaciaReserva(reserva);
    if (!reserva.esLargo || !ref.mounted) return;
    try {
      await ref.read(turnoProvider.notifier).refrescar();
    } catch (_) {
      // Mejor esfuerzo: el próximo aviso o sondeo del turno lo corrige.
    }
  }

  Future<void> _responder(Future<Object?> Function() pedido) async {
    try {
      await pedido();
    } on ErrorNegocio {
      if (ref.mounted) ref.invalidateSelf();
      rethrow;
    }
    if (ref.mounted) ref.invalidateSelf();
  }
}
