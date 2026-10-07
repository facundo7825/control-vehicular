import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../viaje/detalle_viaje_provider.dart';
import '../comunes/comunes.dart';

/// Quién abre el detalle: cambia a quién se muestra (el chofer o el solicitante) y los textos.
enum QuienMira { solicitante, chofer }

/// Detalle de un viaje del historial: el recorrido real en el mapa, el chofer (o, para el chofer, el
/// solicitante), vehículo, horarios, duración, km y, si se canceló, quién y por qué.
class DetalleViaje extends ConsumerWidget {
  const DetalleViaje({super.key, required this.viajeId, this.quienMira = QuienMira.solicitante});

  final int viajeId;
  final QuienMira quienMira;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detalle = ref.watch(detalleViajeProvider(viajeId));

    return Scaffold(
      appBar: AppBar(title: const Text('Detalle del viaje')),
      body: switch (detalle) {
        AsyncData(:final value) => _Contenido(viaje: value, quienMira: quienMira),
        AsyncError(:final error) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(mensajeDeError(error)),
              TextButton(
                onPressed: () => ref.invalidate(detalleViajeProvider(viajeId)),
                child: const Text('Reintentar'),
              ),
            ],
          ),
        ),
        _ => const Center(child: CircularProgressIndicator()),
      },
    );
  }
}

/// La fecha que identifica al viaje, la misma en la lista de "Mis viajes" y en el detalle: para el
/// solicitante manda la hora programada de una reserva; para el chofer, cuándo terminó o se canceló.
DateTime? fechaDelViaje(Viaje v, QuienMira quienMira) => switch (quienMira) {
  QuienMira.solicitante => v.programadoPara ?? v.finalizadoEn ?? v.canceladoEn ?? v.aceptadoEn ?? v.pedidoEn,
  QuienMira.chofer => v.finalizadoEn ?? v.canceladoEn ?? v.programadoPara ?? v.aceptadoEn ?? v.pedidoEn,
};

class _Contenido extends ConsumerWidget {
  const _Contenido({required this.viaje, required this.quienMira});

  final Viaje viaje;
  final QuienMira quienMira;

  /// La nota debajo del mapa cuando no hay recorrido para dibujar; nula si lo hay o si falló al pedirlo.
  String? _notaRecorrido(RecorridoReal? recorrido) {
    if (recorrido == null || recorrido.disponible) return null;
    if (recorrido.vencido) {
      final dias = recorrido.retencionDias;
      return 'El recorrido ya no está disponible${dias == null ? '' : ' (se conserva $dias días)'}.';
    }
    return 'Este viaje no tiene recorrido registrado.';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final recorrido = ref.watch(recorridoRealProvider(viaje.id)).value;
    final puntos = recorrido != null && recorrido.disponible ? recorrido.puntos : const <Coordenada>[];
    final nota = _notaRecorrido(recorrido);
    final mapa = ref.watch(constructorMapaProvider);
    final texto = Theme.of(context).textTheme;
    final esChofer = quienMira == QuienMira.chofer;
    final chofer = viaje.chofer;
    final vehiculo = viaje.vehiculo;
    final cuando = fechaDelViaje(viaje, quienMira);
    final inicio = viaje.iniciadoEn;
    final fin = viaje.finalizadoEn;
    final metros = viaje.metrosRecorridos;
    final quienCancelo = switch (viaje.canceladoPor) {
      CanceladoPor.solicitante => esChofer ? 'Lo canceló el solicitante' : 'Lo cancelaste vos',
      CanceladoPor.admin => 'Lo canceló la administración',
      null => null,
    };

    return Column(
      children: [
        SizedBox(
          height: 280,
          child: mapa(
            context,
            DatosMapa(
              centro: viaje.origen.coordenada,
              enfoque: Enfoque.entre([viaje.origen.coordenada, viaje.destino.coordenada, ...puntos]),
              lineas: [if (puntos.length >= 2) LineaMapa.recorrido(puntos)],
              marcadores: [
                MarcadorMapa(
                  id: 'origen',
                  posicion: viaje.origen.coordenada,
                  tipo: TipoMarcador.origen,
                  titulo: 'Origen',
                ),
                MarcadorMapa(
                  id: 'destino',
                  posicion: viaje.destino.coordenada,
                  tipo: TipoMarcador.destino,
                  titulo: 'Destino',
                ),
              ],
            ),
          ),
        ),
        Expanded(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (nota != null) ...[Text(nota, style: texto.bodySmall), const SizedBox(height: 12)],
                Text(viaje.estado.texto, style: texto.titleLarge),
                if (cuando != null) Text(formatearFechaHora(cuando)),
                if (viaje.estado == EstadoViaje.cancelado) ...[
                  const SizedBox(height: 8),
                  if (quienCancelo != null) Text(quienCancelo),
                  if (viaje.motivoCancelacion case final motivo? when motivo.isNotEmpty) Text('Motivo: $motivo'),
                ],
                if (viaje.estado == EstadoViaje.sinChofer) ...[
                  const SizedBox(height: 8),
                  const Text('No hubo choferes disponibles'),
                ],
                const SizedBox(height: 16),
                if (viaje.esLargo) _Fila('Tipo', viaje.tipo.etiqueta),
                _Fila('Origen', viaje.origen.descripcion),
                _Fila('Destino', viaje.destino.descripcion),
                if (viaje.regresoEstimado case final t?) _Fila('Regreso estimado', formatearFechaHora(t)),
                if (viaje.pasajeros case final t? when t.isNotEmpty) _Fila('Pasajeros', t),
                if (esChofer) ...[
                  const SizedBox(height: 16),
                  _Fila('Solicitante', viaje.solicitante.nombre),
                  if (vehiculo != null) _Fila('Vehículo', [vehiculo.descripcion, ?vehiculo.color].join(' · ')),
                ] else if (chofer != null) ...[
                  const SizedBox(height: 16),
                  Text(chofer.nombre, style: texto.titleMedium),
                  if (vehiculo != null) Text([vehiculo.descripcion, ?vehiculo.color].join(' · ')),
                ],
                const SizedBox(height: 16),
                if (viaje.pedidoEn case final t?) _Fila('Pedido', formatearFechaHora(t)),
                if (viaje.aceptadoEn case final t?)
                  _Fila(esChofer ? 'Aceptado' : 'Chofer asignado', formatearFechaHora(t)),
                if (viaje.llegoEn case final t?) _Fila('Llegó', formatearFechaHora(t)),
                if (inicio != null) _Fila('Inicio', formatearFechaHora(inicio)),
                if (fin != null) _Fila('Fin', formatearFechaHora(fin)),
                if (inicio != null && fin != null && !fin.isBefore(inicio))
                  _Fila('Duración', formatearDuracion(fin.difference(inicio).inSeconds.toDouble())),
                if (metros != null) _Fila('Distancia', formatearDistancia(metros.toDouble())),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _Fila extends StatelessWidget {
  const _Fila(this.etiqueta, this.valor);

  final String etiqueta;
  final String valor;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 2),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 130,
          child: Text(etiqueta, style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant)),
        ),
        Expanded(child: Text(valor)),
      ],
    ),
  );
}
