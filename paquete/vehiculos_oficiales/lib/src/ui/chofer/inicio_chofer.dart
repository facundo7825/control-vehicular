import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../sesion/sesion.dart';
import '../modulo_app.dart';

/// Lugar reservado para las pantallas del chofer (plan `2026-09-30-flutter-chofer.md`).
class InicioChofer extends ConsumerWidget {
  const InicioChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final usuario = ref.watch(usuarioProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
      ),
      body: Center(child: Text('Hola, ${usuario.nombre}. Las pantallas del chofer llegan en el próximo plan.')),
    );
  }
}
