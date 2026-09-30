import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../chofer/pasos_viaje.dart';
import '../../chofer/turno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../sesion/sesion.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Spec 7, chofer 4: viaje en curso paso a paso, navegación externa, llamar y cancelar (spec 5.5 y 5.6).
class ViajeChofer extends ConsumerWidget {
  const ViajeChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final seguimiento = ref.watch(viajeActualProvider);
    final viaje = seguimiento.value?.viaje;
    final usuario = ref.watch(usuarioProvider);

    if (seguimiento.hasValue && viaje == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (context.mounted) context.go(Rutas.chofer);
      });
    }

    final Widget contenido;
    if (viaje == null) {
      contenido = const Center(child: CircularProgressIndicator());
    } else if (viaje.estado == EstadoViaje.cancelado) {
      contenido = const _Fin(icono: Icons.cancel, texto: 'El viaje fue cancelado');
    } else if (viaje.chofer?.id != usuario.id) {
      // Lo reasignó un administrador (o volvió a buscar chofer): ya no es suyo.
      contenido = const _Fin(icono: Icons.swap_horiz, texto: 'El viaje se reasignó a otro chofer');
    } else if (viaje.estado == EstadoViaje.finalizado) {
      contenido = const _Fin(icono: Icons.check_circle, texto: 'Viaje finalizado');
    } else {
      contenido = _EnCurso(viaje: viaje);
    }

    return Scaffold(
      appBar: AppBar(title: Text(viaje == null ? 'Viaje' : estadoParaChofer(viaje.estado))),
      body: Column(
        children: [
          const BannerConexion(),
          Expanded(child: contenido),
        ],
      ),
    );
  }
}

class _EnCurso extends ConsumerStatefulWidget {
  const _EnCurso({required this.viaje});

  final Viaje viaje;

  @override
  ConsumerState<_EnCurso> createState() => _EnCursoState();
}

class _EnCursoState extends ConsumerState<_EnCurso> {
  bool _enviando = false;

  Future<void> _accion(Future<void> Function() accion) async {
    setState(() => _enviando = true);
    try {
      await accion();
    } on ErrorApi catch (e) {
      // P. ej. "Podés salir hacia esta reserva a partir de las 11:15." o "Este viaje no es tuyo.".
      if (!mounted) return;
      mostrarError(context, e);
      if (e is AccesoDenegado) await ref.read(viajeActualProvider.notifier).refrescar();
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  Future<void> _navegar() async {
    final c = widget.viaje.haciaDonde.coordenada;
    final urls = await showModalBottomSheet<List<Uri>>(
      context: context,
      builder: (hoja) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.map),
              title: const Text('Google Maps'),
              onTap: () => Navigator.pop(hoja, urlsGoogleMaps(c)),
            ),
            ListTile(
              leading: const Icon(Icons.navigation),
              title: const Text('Waze'),
              onTap: () => Navigator.pop(hoja, [urlWaze(c)]),
            ),
          ],
        ),
      ),
    );
    if (urls != null && mounted) await _abrir(urls, 'No se pudo abrir la navegación.');
  }

  /// Prueba las URLs en orden hasta que una abra. Un error de la plataforma (sin la app, sin manejador
  /// del esquema) cuenta como "no abrió" y nunca se escapa.
  Future<void> _abrir(List<Uri> urls, String siFalla) async {
    final lanzar = ref.read(lanzadorUrlProvider);
    for (final u in urls) {
      try {
        if (await lanzar(u)) return;
      } catch (_) {
        // Se prueba la siguiente.
      }
    }
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(siFalla)));
  }

  Future<void> _cancelar() async {
    final motivo = await showDialog<String>(context: context, builder: (_) => const _DialogoCancelar());
    if (motivo == null || !mounted) return;
    final notifier = ref.read(viajeActualProvider.notifier);
    final mensajero = ScaffoldMessenger.of(context);
    await _accion(() async {
      await notifier.cancelar(motivo: motivo);
      // Ya no es suyo (vuelve a buscar chofer o queda sin chofer): se vuelve al mapa.
      notifier.descartar();
      mensajero.showSnackBar(const SnackBar(content: Text('Cancelaste el viaje.')));
    });
  }

  @override
  Widget build(BuildContext context) {
    final viaje = widget.viaje;
    final paso = viaje.siguientePaso;
    final telefono = telefonoMarcable(viaje.solicitante.telefono);
    final aqui = ref.watch(posicionPropiaProvider).punto?.posicion;
    final mapa = ref.watch(constructorMapaProvider);
    final texto = Theme.of(context).textTheme;
    final notifier = ref.read(viajeActualProvider.notifier);

    return Column(
      children: [
        Expanded(
          child: mapa(
            context,
            DatosMapa(
              centro: aqui ?? viaje.haciaDonde.coordenada,
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
                if (aqui != null)
                  MarcadorMapa(id: 'yo', posicion: aqui, tipo: TipoMarcador.choferAsignado, titulo: 'Vos'),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(viaje.solicitante.nombre, style: texto.titleLarge),
              if (viaje.obligatorio) const Text('Viaje obligatorio'),
              if (viaje.programadoPara case final cuando?) Text('Reserva para ${formatearFechaHora(cuando)}'),
              Text('Origen: ${viaje.origen.descripcion}'),
              Text('Destino: ${viaje.destino.descripcion}'),
              if (viaje.motivo case final motivo?) Text('Motivo: $motivo'),
              const SizedBox(height: 16),
              if (paso != null)
                FilledButton(
                  onPressed: _enviando ? null : () => _accion(() => notifier.avanzar(paso)),
                  child: Text(textoPaso(paso)),
                ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.navigation),
                      label: const Text('Navegar'),
                      onPressed: _navegar,
                    ),
                  ),
                  if (telefono != null) ...[
                    const SizedBox(width: 12),
                    Expanded(
                      child: OutlinedButton.icon(
                        icon: const Icon(Icons.phone),
                        label: const Text('Llamar'),
                        onPressed: () => _abrir([Uri(scheme: 'tel', path: telefono)], 'No se pudo abrir el teléfono.'),
                      ),
                    ),
                  ],
                ],
              ),
              if (viaje.cancelablePorChofer)
                TextButton(onPressed: _enviando ? null : _cancelar, child: const Text('Cancelar viaje')),
            ],
          ),
        ),
      ],
    );
  }
}

/// Pide el motivo (obligatorio para el chofer, `ViajeController::cancelar`).
class _DialogoCancelar extends StatefulWidget {
  const _DialogoCancelar();

  @override
  State<_DialogoCancelar> createState() => _DialogoCancelarState();
}

class _DialogoCancelarState extends State<_DialogoCancelar> {
  final _motivo = TextEditingController();

  @override
  void dispose() {
    _motivo.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final motivo = _motivo.text.trim();
    return AlertDialog(
      title: const Text('¿Cancelar el viaje?'),
      content: TextField(
        controller: _motivo,
        autofocus: true,
        maxLength: 255,
        decoration: const InputDecoration(labelText: 'Motivo'),
        onChanged: (_) => setState(() {}),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Volver')),
        FilledButton(
          onPressed: motivo.isEmpty ? null : () => Navigator.pop(context, motivo),
          child: const Text('Cancelar viaje'),
        ),
      ],
    );
  }
}

class _Fin extends ConsumerWidget {
  const _Fin({required this.icono, required this.texto});

  final IconData icono;
  final String texto;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(icono, size: 48),
          const SizedBox(height: 16),
          Text(texto, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 32),
          FilledButton(
            onPressed: () {
              ref.read(viajeActualProvider.notifier).descartar();
              context.go(Rutas.chofer);
            },
            child: const Text('Volver al mapa'),
          ),
        ],
      ),
    );
  }
}
