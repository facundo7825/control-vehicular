import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../viaje/viaje_actual.dart';
import '../modulo_app.dart';

/// Spec 5.2 y 5.6: un viaje que llega ya asignado (obligatorio, o asignado por un administrador) no se ofrece:
/// se avisa a pantalla completa, con un único botón. El sonido lo pone `AvisosViaje`.
class ViajeAsignado extends ConsumerStatefulWidget {
  const ViajeAsignado({super.key});

  @override
  ConsumerState<ViajeAsignado> createState() => _ViajeAsignadoState();
}

class _ViajeAsignadoState extends ConsumerState<ViajeAsignado> {
  void _verViaje() {
    ref.read(viajeActualProvider.notifier).verViajeAsignado();
    context.go(Rutas.viajeChofer);
  }

  @override
  Widget build(BuildContext context) {
    final seguimiento = ref.watch(viajeActualProvider).value;
    final viaje = seguimiento?.viaje;
    if (viaje == null || !seguimiento!.asignadoSinOferta) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) context.go(viaje != null ? Rutas.viajeChofer : Rutas.chofer);
      });
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    final texto = Theme.of(context).textTheme;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (yaCerro, _) {
        if (!yaCerro) _verViaje();
      },
      child: Scaffold(
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Icon(Icons.assignment_ind, size: 48),
                const SizedBox(height: 16),
                Text('Viaje asignado', style: texto.headlineMedium, textAlign: TextAlign.center),
                const SizedBox(height: 8),
                Text(
                  viaje.obligatorio
                      ? 'Es un viaje obligatorio: no se puede rechazar.'
                      : 'Te lo asignó un administrador.',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 24),
                Text(viaje.solicitante.nombre, style: texto.titleLarge),
                Text('Origen: ${viaje.origen.descripcion}'),
                Text('Destino: ${viaje.destino.descripcion}'),
                if (viaje.motivo case final motivo?) Text('Motivo: $motivo'),
                const Spacer(),
                FilledButton(
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                  onPressed: _verViaje,
                  child: const Text('Ver viaje'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
