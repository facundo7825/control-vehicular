import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../entorno.dart';
import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../../solicitante/mis_viajes.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Próximas reservas e historial (spec 7, solicitante 6).
class MisViajesPantalla extends ConsumerWidget {
  const MisViajesPantalla({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mis = ref.watch(misViajesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis viajes')),
      body: switch (mis) {
        AsyncData(:final value) => RefreshIndicator(
          onRefresh: () => ref.refresh(misViajesProvider.future),
          child: ListView(
            children: [
              const _Titulo('Próximas reservas'),
              if (value.proximas.isEmpty) const ListTile(title: Text('No tenés reservas próximas.')),
              for (final v in value.proximas) _Proxima(viaje: v),
              const _Titulo('Historial'),
              if (value.historial.isEmpty) const ListTile(title: Text('Todavía no hiciste viajes.')),
              for (final v in value.historial) _Pasado(viaje: v),
            ],
          ),
        ),
        AsyncError(:final error) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(mensajeDeError(error)),
              TextButton(onPressed: () => ref.invalidate(misViajesProvider), child: const Text('Reintentar')),
            ],
          ),
        ),
        _ => const Center(child: CircularProgressIndicator()),
      },
    );
  }
}

class _Titulo extends StatelessWidget {
  const _Titulo(this.texto);

  final String texto;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
    child: Text(texto, style: Theme.of(context).textTheme.titleMedium),
  );
}

String _estadoReserva(Viaje v) => switch (v.estado) {
  EstadoViaje.buscando || EstadoViaje.ofrecido => 'Esperando confirmación del chofer',
  EstadoViaje.aceptado => 'Confirmada · ${v.chofer?.nombre ?? ''}',
  _ => v.estado.texto,
};

class _Proxima extends ConsumerWidget {
  const _Proxima({required this.viaje});

  final Viaje viaje;

  Future<void> _cancelar(BuildContext context, WidgetRef ref) async {
    final confirma = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('¿Cancelar la reserva?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, cancelar')),
        ],
      ),
    );
    if (confirma != true) return;
    try {
      await ref.read(apiProvider).cancelarViaje(viaje.id);
      ref.invalidate(misViajesProvider);
    } on Exception catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ListTile(
      leading: const Icon(Icons.event),
      title: Text('${formatearFechaHora(viaje.programadoPara!)} · ${viaje.destino.descripcion}'),
      subtitle: Text(_estadoReserva(viaje)),
      trailing: viaje.estado.cancelablePorSolicitante
          ? IconButton(
              icon: const Icon(Icons.cancel),
              tooltip: 'Cancelar reserva',
              onPressed: () => _cancelar(context, ref),
            )
          : null,
    );
  }
}

class _Pasado extends ConsumerWidget {
  const _Pasado({required this.viaje});

  final Viaje viaje;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cuando = viaje.programadoPara ?? viaje.finalizadoEn ?? viaje.canceladoEn ?? viaje.aceptadoEn;
    final reservaSinChofer = viaje.tipo == TipoViaje.reserva && viaje.estado == EstadoViaje.sinChofer;
    return ListTile(
      leading: Icon(viaje.tipo == TipoViaje.reserva ? Icons.event_available : Icons.local_taxi),
      title: Text([if (cuando != null) formatearFechaHora(cuando), viaje.destino.descripcion].join(' · ')),
      subtitle: Text(viaje.estado.texto),
      // Spec 5.4: si el chofer rechazó la reserva o no respondió, el solicitante elige otro.
      trailing: reservaSinChofer
          ? TextButton(
              onPressed: () {
                ref.read(borradorPedidoProvider.notifier).desdeViaje(viaje);
                context.push(Rutas.reservar, extra: viaje.programadoPara);
              },
              child: const Text('Elegir otro'),
            )
          : null,
    );
  }
}
