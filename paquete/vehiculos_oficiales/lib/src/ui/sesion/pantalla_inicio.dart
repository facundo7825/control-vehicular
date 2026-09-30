import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../sesion/sesion.dart';
import '../modulo_app.dart';

/// Primera pantalla: intercambio de sesión y sus errores (spec 3.2 y 9).
class PantallaInicio extends ConsumerWidget {
  const PantallaInicio({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final reintentar = ref.read(sesionProvider.notifier).iniciar;
    final cerrar = ref.read(cerrarModuloProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(icon: const Icon(Icons.close), tooltip: 'Cerrar', onPressed: cerrar),
      ),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: switch (sesion) {
            SesionIniciando() || SesionLista() => const Column(
              mainAxisSize: MainAxisSize.min,
              children: [CircularProgressIndicator(), SizedBox(height: 16), Text('Conectando…')],
            ),
            SesionIdentidadNoDisponible() => _Aviso(
              icono: Icons.cloud_off,
              titulo: 'Servicio de identidad no disponible',
              detalle: 'No pudimos validar tu sesión con el Poder Judicial. Probá de nuevo en unos minutos.',
              accion: 'Reintentar',
              alAccionar: reintentar,
            ),
            SesionVencida() => _Aviso(
              icono: Icons.lock_clock,
              titulo: 'Tu sesión venció',
              detalle: 'Volvé a iniciar sesión en la app.',
              accion: 'Cerrar',
              alAccionar: cerrar,
            ),
            SesionDeshabilitada(:final mensaje) => _Aviso(
              icono: Icons.block,
              titulo: mensaje,
              detalle: 'Consultá con el administrador del sistema.',
              accion: 'Cerrar',
              alAccionar: cerrar,
            ),
            SesionConError(:final mensaje) => _Aviso(
              icono: Icons.wifi_off,
              titulo: mensaje,
              detalle: 'Revisá tu conexión.',
              accion: 'Reintentar',
              alAccionar: reintentar,
            ),
          },
        ),
      ),
    );
  }
}

class _Aviso extends StatelessWidget {
  const _Aviso({
    required this.icono,
    required this.titulo,
    required this.detalle,
    required this.accion,
    required this.alAccionar,
  });

  final IconData icono;
  final String titulo;
  final String detalle;
  final String accion;
  final VoidCallback alAccionar;

  @override
  Widget build(BuildContext context) {
    final texto = Theme.of(context).textTheme;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icono, size: 48),
        const SizedBox(height: 16),
        Text(titulo, style: texto.titleLarge, textAlign: TextAlign.center),
        const SizedBox(height: 8),
        Text(detalle, textAlign: TextAlign.center),
        const SizedBox(height: 24),
        FilledButton(onPressed: alAccionar, child: Text(accion)),
      ],
    );
  }
}
