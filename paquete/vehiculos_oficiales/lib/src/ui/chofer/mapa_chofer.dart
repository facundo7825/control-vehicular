import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../chofer/agenda.dart';
import '../../chofer/turno.dart';
import '../../entorno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../sesion/sesion.dart';
import '../../solicitante/choferes_mapa.dart';
import '../../ubicacion/ubicador.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import 'agenda.dart';
import 'inicio_chofer.dart';

/// Spec 7, chofer 2 y 6: su posición, su estado, el vehículo del turno, la próxima reserva
/// confirmada, el acceso a la agenda y "Finalizar turno".
class MapaChofer extends ConsumerStatefulWidget {
  const MapaChofer({super.key, required this.turno});

  final Turno turno;

  @override
  ConsumerState<MapaChofer> createState() => _MapaChoferState();
}

class _MapaChoferState extends ConsumerState<MapaChofer> {
  /// A dónde se pidió mirar. Se fija con el primer punto del GPS (o al tocar "Mi ubicación"); los
  /// puntos siguientes no lo tocan, para que el chofer pueda mover el mapa.
  Enfoque? _enfoque;

  Turno get turno => widget.turno;

  @override
  void initState() {
    super.initState();
    _centrarEn(ref.read(posicionPropiaProvider).punto?.posicion);
  }

  /// Un enfoque nuevo (otra versión), aunque sea el mismo punto que el anterior.
  void _centrarEn(Coordenada? punto) {
    if (punto == null) return;
    _enfoque = Enfoque.punto(punto, version: (_enfoque?.version ?? 0) + 1);
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(posicionPropiaProvider, (anterior, actual) {
      // El primero del turno: al cortar el GPS la posición se limpia y el siguiente vuelve a ser el primero.
      if (anterior?.punto == null && actual.punto != null) setState(() => _centrarEn(actual.punto!.posicion));
    });
    final posicion = ref.watch(posicionPropiaProvider);
    final usuario = ref.watch(usuarioProvider);
    // El estado lo calcula el backend (spec 4.1) y llega en el mismo listado que ve el solicitante.
    final yo = ref.watch(choferesMapaProvider).value?.where((c) => c.id == usuario.id).firstOrNull;
    final mapa = ref.watch(constructorMapaProvider);
    final config = ref.watch(entornoProvider).config;
    final aqui = posicion.punto?.posicion;
    final texto = Theme.of(context).textTheme;
    // Solo uno activo y suyo: uno terminado o reasignado que todavía no descartó no es "en curso".
    final hayViaje = viajeActivo(ref.watch(viajeActualProvider).value?.viaje, usuario.id);
    // La agenda llega ordenada por fecha: la primera reserva confirmada es la próxima.
    final proxima = ref.watch(agendaProvider).value?.reservas.firstOrNull;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: const BotonCerrarModulo(),
        actions: [
          IconButton(icon: const Icon(Icons.event), tooltip: 'Agenda', onPressed: () => context.push(Rutas.agenda)),
        ],
      ),
      body: Column(
        children: [
          const BannerConexion(),
          if (hayViaje)
            MaterialBanner(
              content: const Text('Tenés un viaje en curso.'),
              actions: [TextButton(onPressed: () => context.go(Rutas.viajeChofer), child: const Text('Ver'))],
            ),
          if (posicion.sinGps)
            MaterialBanner(
              leading: const Icon(Icons.gps_off),
              content: const Text('Sin señal de GPS por ahora'),
              actions: [
                TextButton(
                  onPressed: () => ref.read(ubicadorProvider).abrirAjustes(PermisoUbicacion.denegado),
                  child: const Text('Abrir ajustes'),
                ),
                TextButton(
                  onPressed: () => ref.read(turnoProvider.notifier).reintentarGps(),
                  child: const Text('Reintentar'),
                ),
              ],
            ),
          Expanded(
            child: Stack(
              children: [
                Positioned.fill(
                  child: mapa(
                    context,
                    DatosMapa(
                      centro: aqui ?? Coordenada(config.centroMapaLat, config.centroMapaLng),
                      enfoque: _enfoque,
                      marcadores: [
                        if (aqui != null)
                          MarcadorMapa(id: 'yo', posicion: aqui, tipo: TipoMarcador.choferAsignado, titulo: 'Vos'),
                      ],
                    ),
                  ),
                ),
                Positioned(
                  right: 12,
                  bottom: 12,
                  child: BotonMiUbicacion(alTocar: aqui == null ? null : () => setState(() => _centrarEn(aqui))),
                ),
              ],
            ),
          ),
          if (proxima != null && !hayViaje) TarjetaReserva(reserva: proxima, titulo: 'Próxima reserva'),
          Material(
            elevation: 8,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(yo?.estado.texto ?? 'En turno', style: texto.titleLarge),
                  if (turno.vehiculo case final v?) Text([v.descripcion, ?v.color].join(' · ')),
                  if (aqui == null && !posicion.sinGps) const Text('Buscando tu ubicación…'),
                  const SizedBox(height: 16),
                  OutlinedButton(onPressed: () => _finalizar(context), child: const Text('Finalizar turno')),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _finalizar(BuildContext context) async {
    final confirma = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('¿Finalizar el turno?'),
        content: const Text('Se deja de compartir tu ubicación y no vas a recibir viajes.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, finalizar')),
        ],
      ),
    );
    if (confirma != true || !context.mounted) return;
    try {
      await ref.read(turnoProvider.notifier).finalizar();
    } on ErrorApi catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }
}
