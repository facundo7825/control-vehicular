import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../chofer/turno.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import 'iniciar_turno.dart';
import 'mapa_chofer.dart';

/// Entrada del chofer: sin turno, "Iniciar turno"; con turno, su mapa. Como `InicioSolicitante`, pasa a la
/// pantalla que corresponde cuando aparece algo nuevo: una oferta, un viaje asignado sin oferta o un viaje
/// (el que había al abrir, uno aceptado, una reserva que arrancó).
class InicioChofer extends ConsumerWidget {
  const InicioChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Solo cuando aparece algo nuevo: las novedades de lo mismo no deshacen el "atrás".
    ref.listen(viajeActualProvider, (anterior, siguiente) {
      final antes = anterior?.value;
      final ahora = siguiente.value;
      if (ahora == null) return;
      if (ahora.asignadoSinOferta && antes?.asignadoSinOferta != true) return context.go(Rutas.viajeAsignado);
      final oferta = ahora.oferta;
      if (oferta != null && oferta.id != antes?.oferta?.id) return context.go(Rutas.ofertaChofer);
      final id = ahora.viaje?.id;
      if (id != null && id != antes?.viaje?.id) context.go(Rutas.viajeChofer);
    });
    final turno = ref.watch(turnoProvider);

    if (turno.hasValue) {
      final t = turno.value;
      if (t == null) return const IniciarTurno();
      // "Atrás" en el mapa cerraría el módulo sin preguntar: pasa por la misma confirmación que la X.
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (cerro, _) {
          if (!cerro) cerrarModuloChofer(context, ref);
        },
        child: MapaChofer(turno: t),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Vehículos oficiales'), leading: const BotonCerrarModulo()),
      body: Center(
        child: turno.hasError
            ? Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(mensajeDeError(turno.error!)),
                  TextButton(onPressed: () => ref.invalidate(turnoProvider), child: const Text('Reintentar')),
                ],
              )
            : const CircularProgressIndicator(),
      ),
    );
  }
}

/// Cierra el módulo. Con el turno abierto pregunta antes: el GPS vive con el módulo y se corta al cerrarlo.
Future<void> cerrarModuloChofer(BuildContext context, WidgetRef ref) async {
  final cerrar = ref.read(cerrarModuloProvider);
  if (ref.read(turnoProvider).value == null) return cerrar();
  final confirma = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Tu turno sigue abierto'),
      content: const Text(
        'Si cerrás Vehículos oficiales dejás de compartir tu ubicación hasta que lo vuelvas a abrir. '
        'Para terminar el día usá "Finalizar turno".',
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Seguir acá')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Cerrar igual')),
      ],
    ),
  );
  if (confirma == true) cerrar();
}

class BotonCerrarModulo extends ConsumerWidget {
  const BotonCerrarModulo({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) =>
      IconButton(icon: const Icon(Icons.close), tooltip: 'Cerrar', onPressed: () => cerrarModuloChofer(context, ref));
}
