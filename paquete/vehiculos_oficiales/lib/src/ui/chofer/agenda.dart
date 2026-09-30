import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/errores_api.dart';
import '../../chofer/agenda.dart';
import '../../modelos/modelos.dart';
import '../comunes/comunes.dart';

/// Spec 7, chofer 5: reservas confirmadas y solicitudes de reserva por responder.
class AgendaPantalla extends ConsumerWidget {
  const AgendaPantalla({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final agenda = ref.watch(agendaProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Agenda')),
      // Con una agenda ya cargada, una recarga (o una recarga que falló) la sigue mostrando.
      body: switch (agenda) {
        AsyncValue(:final value?) => RefreshIndicator(
          onRefresh: () async {
            try {
              ref.invalidate(agendaProvider);
              await ref.read(agendaProvider.future);
            } on ErrorApi {
              // El error queda en el estado y se avisa arriba de la lista.
            }
          },
          child: ListView(
            children: [
              if (agenda.error case final error?)
                ListTile(
                  leading: const Icon(Icons.cloud_off),
                  title: const Text('No se pudo actualizar la agenda.'),
                  subtitle: Text(mensajeDeError(error)),
                  trailing: TextButton(
                    onPressed: () => ref.invalidate(agendaProvider),
                    child: const Text('Reintentar'),
                  ),
                ),
              const _Titulo('Solicitudes'),
              if (value.solicitudes.isEmpty) const ListTile(title: Text('No tenés solicitudes pendientes.')),
              for (final s in value.solicitudes) _Solicitud(solicitud: s),
              const _Titulo('Reservas confirmadas'),
              if (value.reservas.isEmpty) const ListTile(title: Text('No tenés reservas confirmadas.')),
              for (final r in value.reservas) TarjetaReserva(reserva: r),
            ],
          ),
        ),
        AsyncError(:final error) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(mensajeDeError(error)),
              TextButton(onPressed: () => ref.invalidate(agendaProvider), child: const Text('Reintentar')),
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

/// Ejecuta una acción de la agenda mostrando el error del backend, si lo hay.
Future<void> _accionAgenda(BuildContext context, Future<void> Function() accion) async {
  try {
    await accion();
  } on ErrorApi catch (e) {
    if (context.mounted) mostrarError(context, e);
  }
}

class _Solicitud extends ConsumerWidget {
  const _Solicitud({required this.solicitud});

  final Oferta solicitud;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final viaje = solicitud.viaje;
    final agenda = ref.read(agendaProvider.notifier);
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            leading: const Icon(Icons.event_note),
            title: Text('${formatearFechaHora(viaje.programadoPara!)} · ${viaje.destino.descripcion}'),
            subtitle: Text(
              [
                viaje.solicitante.nombre,
                if (viaje.duracionEstimadaMin case final min?) '~$min min',
                'Responder antes de ${formatearFechaHora(solicitud.venceEn)}',
              ].join(' · '),
            ),
          ),
          OverflowBar(
            alignment: MainAxisAlignment.end,
            children: [
              TextButton(
                onPressed: () => _accionAgenda(context, () => agenda.rechazar(solicitud)),
                child: const Text('Rechazar'),
              ),
              FilledButton(
                onPressed: () => _accionAgenda(context, () => agenda.aceptar(solicitud)),
                child: const Text('Aceptar'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Una reserva confirmada con "Voy en camino" (el backend decide si ya se puede salir). También la usa el
/// mapa del chofer para destacar la próxima.
class TarjetaReserva extends ConsumerWidget {
  const TarjetaReserva({super.key, required this.reserva, this.titulo});

  final Viaje reserva;

  /// Encabezado opcional (p. ej. "Próxima reserva").
  final String? titulo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final agenda = ref.read(agendaProvider.notifier);
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            leading: const Icon(Icons.event_available),
            title: Text(
              [?titulo, formatearFechaHora(reserva.programadoPara!), reserva.destino.descripcion].join(' · '),
            ),
            subtitle: Text('${reserva.solicitante.nombre} · Desde ${reserva.origen.descripcion}'),
          ),
          OverflowBar(
            alignment: MainAxisAlignment.end,
            children: [
              FilledButton.tonal(
                onPressed: () => _accionAgenda(context, () => agenda.salir(reserva)),
                child: const Text('Voy en camino'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
