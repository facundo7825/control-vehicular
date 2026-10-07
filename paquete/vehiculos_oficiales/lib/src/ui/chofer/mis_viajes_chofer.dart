import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../chofer/mis_viajes_chofer.dart';
import '../../modelos/modelos.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import '../solicitante/detalle_viaje.dart';

/// "Mis viajes" del chofer: el resumen de hoy y sus viajes finalizados y cancelados. Tocar uno abre el mismo
/// detalle que ve el solicitante, con los textos del chofer.
class MisViajesChofer extends ConsumerWidget {
  const MisViajesChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final viajes = ref.watch(viajesChoferProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis viajes')),
      body: switch (viajes) {
        AsyncData(:final value) => RefreshIndicator(
          onRefresh: () => ref.refresh(viajesChoferProvider.future),
          child: ListView(
            children: [
              _Resumen(hoy: value.hoy),
              if (value.viajes.isEmpty) const ListTile(title: Text('Todavía no hiciste viajes.')),
              for (final v in value.viajes) _Fila(viaje: v),
            ],
          ),
        ),
        AsyncError(:final error) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(mensajeDeError(error)),
              TextButton(onPressed: () => ref.invalidate(viajesChoferProvider), child: const Text('Reintentar')),
            ],
          ),
        ),
        _ => const Center(child: CircularProgressIndicator()),
      },
    );
  }
}

/// Acceso a "Mis viajes" en la barra del chofer: junto a la Agenda en el mapa y en "Iniciar turno".
class BotonMisViajesChofer extends StatelessWidget {
  const BotonMisViajesChofer({super.key});

  @override
  Widget build(BuildContext context) => IconButton(
    icon: const Icon(Icons.history),
    tooltip: 'Mis viajes',
    onPressed: () => context.push(Rutas.misViajesChofer),
  );
}

class _Resumen extends StatelessWidget {
  const _Resumen({required this.hoy});

  final ResumenDia hoy;

  @override
  Widget build(BuildContext context) {
    final texto = Theme.of(context).textTheme;
    final viajes = hoy.viajes == 1 ? '1 viaje' : '${hoy.viajes} viajes';
    // "0 m" se lee raro en un resumen: sin km recorridos dice "0 km".
    final distancia = hoy.metros == 0 ? '0 km' : formatearDistancia(hoy.metros.toDouble());
    return Card(
      margin: const EdgeInsets.all(16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Hoy: $viajes · $distancia', style: texto.titleMedium),
            if (hoy.enTurnoDesde case final desde?)
              Text('En turno desde ${DateFormat('HH:mm').format(desde.toLocal())}'),
          ],
        ),
      ),
    );
  }
}

class _Fila extends StatelessWidget {
  const _Fila({required this.viaje});

  final Viaje viaje;

  @override
  Widget build(BuildContext context) {
    final cuando = fechaDelViaje(viaje, QuienMira.chofer);
    return ListTile(
      leading: Icon(switch (viaje.tipo) {
        TipoViaje.largo => Icons.luggage,
        TipoViaje.reserva => Icons.event_available,
        TipoViaje.inmediato => Icons.local_taxi,
      }),
      title: Text([if (cuando != null) formatearFechaHora(cuando), viaje.destino.descripcion].join(' · ')),
      subtitle: Text([viaje.solicitante.nombre, viaje.estado.texto].where((t) => t.isNotEmpty).join(' · ')),
      onTap: () => context.push(Rutas.detalleViajeChofer(viaje.id)),
    );
  }
}
