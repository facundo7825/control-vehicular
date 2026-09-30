import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Buscando chofer, viaje activo y cómo terminó (spec 7, solicitante 3 y 4; spec 5.3 y 5.5).
class PantallaViaje extends ConsumerWidget {
  const PantallaViaje({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final seguimiento = ref.watch(viajeActualProvider);
    final viaje = seguimiento.value?.viaje;

    if (seguimiento.hasValue && viaje == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (context.mounted) context.go(Rutas.solicitante);
      });
    }

    return Scaffold(
      appBar: AppBar(title: Text(viaje?.estado.texto ?? 'Tu viaje')),
      body: Column(
        children: [
          const BannerConexion(),
          Expanded(
            child: viaje == null
                ? const Center(child: CircularProgressIndicator())
                : switch (viaje.estado) {
                    EstadoViaje.buscando || EstadoViaje.ofrecido => _Buscando(viaje: viaje),
                    EstadoViaje.aceptado ||
                    EstadoViaje.enCamino ||
                    EstadoViaje.llego ||
                    EstadoViaje.enCurso => _Activo(viaje: viaje, ubicacion: seguimiento.value?.ubicacionChofer),
                    EstadoViaje.sinChofer => _SinChofer(viaje: viaje),
                    EstadoViaje.finalizado || EstadoViaje.cancelado => _Terminado(viaje: viaje),
                  },
          ),
        ],
      ),
    );
  }
}

Future<void> _cancelar(BuildContext context, WidgetRef ref) async {
  final confirma = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('¿Cancelar el viaje?'),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, cancelar')),
      ],
    ),
  );
  if (confirma != true) return;
  try {
    await ref.read(viajeActualProvider.notifier).cancelar();
  } on Exception catch (e) {
    if (context.mounted) mostrarError(context, e);
  }
}

class _Buscando extends ConsumerWidget {
  const _Buscando({required this.viaje});

  final Viaje viaje;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const LinearProgressIndicator(),
          const SizedBox(height: 24),
          Text(
            viaje.modo == ModoViaje.especifico ? 'Esperando que el chofer acepte…' : 'Buscando el chofer más cercano…',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          Text('Hacia ${viaje.destino.descripcion}', textAlign: TextAlign.center),
          const SizedBox(height: 32),
          OutlinedButton(onPressed: () => _cancelar(context, ref), child: const Text('Cancelar pedido')),
        ],
      ),
    );
  }
}

class _Activo extends ConsumerWidget {
  const _Activo({required this.viaje, this.ubicacion});

  final Viaje viaje;
  final UbicacionChofer? ubicacion;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final chofer = viaje.chofer;
    final vehiculo = viaje.vehiculo;
    final mapa = ref.watch(constructorMapaProvider);
    final texto = Theme.of(context).textTheme;
    final aproximandose = viaje.estado == EstadoViaje.aceptado || viaje.estado == EstadoViaje.enCamino;

    return Column(
      children: [
        Expanded(
          child: mapa(
            context,
            DatosMapa(
              centro: ubicacion?.posicion ?? viaje.origen.coordenada,
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
                if (ubicacion != null)
                  MarcadorMapa(
                    id: 'chofer',
                    posicion: ubicacion!.posicion,
                    tipo: TipoMarcador.choferAsignado,
                    titulo: chofer?.nombre ?? 'Chofer',
                  ),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(viaje.estado.texto, style: texto.titleLarge),
              if (aproximandose && ubicacion != null)
                // Sin ETA real en v1: distancia en línea recta hasta el origen (ver Decisiones).
                Text(
                  'A ${formatearDistancia(distanciaMetros(ubicacion!.posicion, viaje.origen.coordenada))} del origen',
                ),
              const SizedBox(height: 8),
              if (chofer != null) Text(chofer.nombre, style: texto.titleMedium),
              if (vehiculo != null) Text([vehiculo.descripcion, ?vehiculo.color].join(' · ')),
              const SizedBox(height: 16),
              Row(
                children: [
                  if (chofer?.telefono != null)
                    Expanded(
                      child: FilledButton.icon(
                        icon: const Icon(Icons.phone),
                        label: const Text('Llamar'),
                        onPressed: () => ref.read(lanzadorUrlProvider)(Uri(scheme: 'tel', path: chofer!.telefono)),
                      ),
                    ),
                  if (chofer?.telefono != null && viaje.estado.cancelablePorSolicitante) const SizedBox(width: 12),
                  if (viaje.estado.cancelablePorSolicitante)
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () => _cancelar(context, ref),
                        child: const Text('Cancelar viaje'),
                      ),
                    ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _SinChofer extends ConsumerWidget {
  const _SinChofer({required this.viaje});

  final Viaje viaje;

  Future<void> _pedirMasCercano(BuildContext context, WidgetRef ref) async {
    try {
      await ref
          .read(viajeActualProvider.notifier)
          .pedir(
            PedidoViaje(modo: ModoViaje.masCercano, origen: viaje.origen, destino: viaje.destino, motivo: viaje.motivo),
          );
    } on Exception catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }

  void _volver(BuildContext context, WidgetRef ref) {
    ref.read(viajeActualProvider.notifier).descartar();
    context.go(Rutas.solicitante);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final especifico = viaje.modo == ModoViaje.especifico;
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Icon(Icons.no_crash, size: 48),
          const SizedBox(height: 16),
          Text(
            especifico ? 'El chofer no aceptó el viaje' : 'No hay choferes disponibles',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 32),
          FilledButton(onPressed: () => _pedirMasCercano(context, ref), child: const Text('Pedir el más cercano')),
          const SizedBox(height: 12),
          OutlinedButton(
            onPressed: () => _volver(context, ref),
            child: Text(especifico ? 'Elegir otro' : 'Volver al mapa'),
          ),
        ],
      ),
    );
  }
}

class _Terminado extends ConsumerWidget {
  const _Terminado({required this.viaje});

  final Viaje viaje;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(viaje.estado == EstadoViaje.finalizado ? Icons.check_circle : Icons.cancel, size: 48),
          const SizedBox(height: 16),
          Text(viaje.estado.texto, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 32),
          FilledButton(
            onPressed: () {
              ref.read(viajeActualProvider.notifier).descartar();
              context.go(Rutas.solicitante);
            },
            child: const Text('Volver al mapa'),
          ),
        ],
      ),
    );
  }
}
