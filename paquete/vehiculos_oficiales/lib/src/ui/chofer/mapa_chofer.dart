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
import 'mis_viajes_chofer.dart';
import 'viaje_chofer.dart';

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
    // Sin turno, el servidor ya no podría ubicar los pasos y el recorrido que faltan enviar.
    final esperaSenal = ref.watch(finalizarEsperaSenalProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: const BotonCerrarModulo(),
        actions: [
          IconButton(icon: const Icon(Icons.event), tooltip: 'Agenda', onPressed: () => context.push(Rutas.agenda)),
          const BotonMisViajesChofer(),
        ],
      ),
      body: Column(
        children: [
          const BannerConexion(),
          const BannerAccionesPendientes(),
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
                  _FilaVehiculo(
                    turno: turno,
                    alCambiar: () => _cambiarVehiculo(context, hayViaje: hayViaje),
                  ),
                  if (turno.porFichaje) const Text('Turno por fichaje'),
                  if (turno.cierrePendienteEn != null)
                    const Text('Fichaste la salida: se cierra al terminar el viaje.'),
                  if (aqui == null && !posicion.sinGps) const Text('Buscando tu ubicación…'),
                  const SizedBox(height: 16),
                  OutlinedButton(
                    onPressed: esperaSenal ? null : () => _finalizar(context),
                    child: const Text('Finalizar turno'),
                  ),
                  if (esperaSenal) const Text(esperandoSenalParaFinalizar, textAlign: TextAlign.center),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Si ese día usa otro vehículo que el del turno (el habitual, si lo abrió el fichaje). Durante un viaje no
  /// se puede: se explica y no se pregunta nada.
  Future<void> _cambiarVehiculo(BuildContext context, {required bool hayViaje}) async {
    if (hayViaje) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('No podés cambiar el vehículo durante un viaje.')));
      return;
    }
    final elegido = await showModalBottomSheet<Vehiculo>(context: context, builder: (_) => const _HojaVehiculos());
    final id = elegido?.id;
    if (id == null || !context.mounted) return;
    try {
      await ref.read(turnoProvider.notifier).cambiarVehiculo(id);
    } on ErrorApi catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
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

/// El vehículo del turno; tocarlo (o el ícono) abre "Cambiar vehículo".
class _FilaVehiculo extends StatelessWidget {
  const _FilaVehiculo({required this.turno, required this.alCambiar});

  final Turno turno;
  final VoidCallback alCambiar;

  @override
  Widget build(BuildContext context) {
    final v = turno.vehiculo;
    return InkWell(
      onTap: alCambiar,
      child: Row(
        children: [
          Expanded(child: Text(v == null ? 'Sin vehículo' : [v.descripcion, ?v.color].join(' · '))),
          IconButton(icon: const Icon(Icons.swap_horiz), tooltip: 'Cambiar vehículo', onPressed: alCambiar),
        ],
      ),
    );
  }
}

/// Los vehículos libres (`GET /vehiculos/disponibles`); devuelve el elegido.
class _HojaVehiculos extends ConsumerWidget {
  const _HojaVehiculos();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehiculos = ref.watch(vehiculosDisponiblesProvider);
    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: Text('Cambiar vehículo', style: Theme.of(context).textTheme.titleMedium),
          ),
          ...switch (vehiculos) {
            AsyncData(:final value) when value.isEmpty => [
              const ListTile(title: Text('No hay otros vehículos disponibles.')),
            ],
            AsyncData(:final value) => [
              for (final v in value)
                ListTile(
                  leading: const Icon(Icons.directions_car),
                  title: Text(v.descripcion),
                  subtitle: v.color == null ? null : Text(v.color!),
                  onTap: () => Navigator.pop(context, v),
                ),
            ],
            AsyncError(:final error) => [
              ListTile(
                title: Text(mensajeDeError(error)),
                trailing: TextButton(
                  onPressed: () => ref.invalidate(vehiculosDisponiblesProvider),
                  child: const Text('Reintentar'),
                ),
              ),
            ],
            _ => [
              const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              ),
            ],
          },
        ],
      ),
    );
  }
}
