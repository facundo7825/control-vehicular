import 'package:clock/clock.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../entorno.dart';
import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Elige fecha y hora (en la zona del dispositivo). Costura para los tests.
typedef ElegirFechaHora = Future<DateTime?> Function(BuildContext context, DateTime inicial);

final elegirFechaHoraProvider = Provider<ElegirFechaHora>((ref) => _elegirConPickers);

Future<DateTime?> _elegirConPickers(BuildContext context, DateTime inicial) async {
  final ahora = clock.now();
  final dia = await showDatePicker(
    context: context,
    initialDate: inicial,
    firstDate: DateTime(ahora.year, ahora.month, ahora.day),
    lastDate: ahora.add(const Duration(days: 90)),
  );
  if (dia == null || !context.mounted) return null;
  final hora = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(inicial));
  if (hora == null) return null;
  return DateTime(dia.year, dia.month, dia.day, hora.hour, hora.minute);
}

/// Reserva a futuro (spec 5.4 y 7, solicitante 5): fecha y hora, choferes disponibles o "Cualquiera
/// disponible", confirmación. Origen, destino y motivo vienen del pedido armado en el mapa.
class PantallaReserva extends ConsumerStatefulWidget {
  const PantallaReserva({super.key, this.fechaInicial});

  final DateTime? fechaInicial;

  @override
  ConsumerState<PantallaReserva> createState() => _PantallaReservaState();
}

class _PantallaReservaState extends ConsumerState<PantallaReserva> {
  late DateTime _cuando;
  DisponiblesReserva? _disponibles;

  /// Nulo = "Cualquiera disponible".
  int? _choferId;
  bool _cargando = false;

  @override
  void initState() {
    super.initState();
    final sugerida = clock.now().add(const Duration(hours: 2));
    final inicial = widget.fechaInicial?.toLocal();
    _cuando = inicial != null && inicial.isAfter(clock.now())
        ? inicial
        : DateTime(sugerida.year, sugerida.month, sugerida.day, sugerida.hour);
  }

  Future<void> _elegirFecha() async {
    final elegida = await ref.read(elegirFechaHoraProvider)(context, _cuando);
    if (elegida == null) return;
    setState(() {
      _cuando = elegida;
      _disponibles = null; // la franja cambió: hay que volver a consultar
      _choferId = null;
    });
  }

  Future<void> _consultar(BorradorPedido b) async {
    setState(() => _cargando = true);
    try {
      final d = await ref
          .read(apiProvider)
          .disponiblesReserva(FranjaReserva(programadoPara: _cuando, origen: b.origen!, destino: b.destino!));
      if (mounted) setState(() => _disponibles = d);
    } on Exception catch (e) {
      if (mounted) mostrarError(context, e);
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  Future<void> _confirmar(BorradorPedido b) async {
    setState(() => _cargando = true);
    final borrador = ref.read(borradorPedidoProvider.notifier);
    try {
      final reserva = await ref
          .read(apiProvider)
          .crearReserva(
            PedidoReserva(
              programadoPara: _cuando,
              modo: _choferId == null ? ModoViaje.cualquieraDisponible : ModoViaje.especifico,
              choferId: _choferId,
              origen: b.lugarOrigen!,
              destino: b.lugarDestino!,
              motivo: b.motivo.trim().isEmpty ? null : b.motivo.trim(),
            ),
          );
      borrador.limpiar();
      if (!mounted) return;
      final texto = reserva.estado == EstadoViaje.aceptado
          ? 'Reserva confirmada para ${formatearFechaHora(reserva.programadoPara!)}.'
          : 'Solicitud enviada. Te avisamos cuando el chofer responda.';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(texto)));
      context.go(Rutas.misViajes);
    } on Exception catch (e) {
      if (mounted) mostrarError(context, e);
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = ref.watch(borradorPedidoProvider);
    final disponibles = _disponibles;

    return Scaffold(
      appBar: AppBar(title: const Text('Reservar un viaje')),
      body: !b.completo
          ? const Center(child: Text('Elegí el origen y el destino antes de reservar.'))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                ListTile(
                  leading: const Icon(Icons.trip_origin),
                  title: const Text('Origen'),
                  subtitle: Text(b.descripcion(PuntoPedido.origen)!),
                ),
                ListTile(
                  leading: const Icon(Icons.place),
                  title: const Text('Destino'),
                  subtitle: Text(b.descripcion(PuntoPedido.destino)!),
                ),
                ListTile(
                  leading: const Icon(Icons.event),
                  title: const Text('Fecha y hora'),
                  subtitle: Text(formatearFechaHora(_cuando)),
                  trailing: const Icon(Icons.edit),
                  onTap: _cargando ? null : _elegirFecha,
                ),
                const SizedBox(height: 8),
                if (disponibles == null)
                  FilledButton(
                    onPressed: _cargando ? null : () => _consultar(b),
                    child: const Text('Ver choferes disponibles'),
                  )
                else ...[
                  Text('Duración estimada: ${disponibles.duracionEstimadaMin} min'),
                  const SizedBox(height: 8),
                  if (disponibles.choferes.isEmpty)
                    const Text('No hay choferes disponibles en ese horario. Probá con otra hora.')
                  else
                    RadioGroup<int?>(
                      groupValue: _choferId,
                      onChanged: (v) => setState(() => _choferId = v),
                      child: Column(
                        children: [
                          const RadioListTile<int?>(
                            value: null,
                            title: Text('Cualquiera disponible'),
                            subtitle: Text('El sistema elige al que tenga menos reservas ese día.'),
                          ),
                          for (final c in disponibles.choferes)
                            RadioListTile<int?>(
                              value: c.id,
                              title: Text(c.nombre),
                              subtitle: Text('${c.reservasDelDia} reservas ese día'),
                            ),
                        ],
                      ),
                    ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _cargando || disponibles.choferes.isEmpty ? null : () => _confirmar(b),
                    child: const Text('Confirmar reserva'),
                  ),
                ],
              ],
            ),
    );
  }
}
