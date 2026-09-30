import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../sesion/sesion.dart';
import '../../viaje/viaje_actual.dart';
import '../modulo_app.dart';

/// Provisoria: la Task 11 la reemplaza por el mapa del solicitante.
class InicioSolicitante extends ConsumerWidget {
  const InicioSolicitante({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final usuario = ref.watch(usuarioProvider);
    // Al aparecer un viaje (el que había al abrir o uno recién pedido) se va a su pantalla.
    ref.listen(viajeActualProvider, (_, siguiente) {
      if (siguiente.value?.viaje != null) context.go(Rutas.viaje);
    });
    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
      ),
      body: Center(child: Text('Hola, ${usuario.nombre}')),
    );
  }
}
