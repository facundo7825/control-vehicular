import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/errores_api.dart';
import '../../chofer/turno.dart';
import '../../ubicacion/ubicador.dart';
import '../comunes/comunes.dart';
import 'inicio_chofer.dart';

/// Spec 7, chofer 1: elegir el vehículo e iniciar el turno. Es la pieza que después reemplazará la
/// asistencia: toda la lógica está en `TurnoNotifier`.
class IniciarTurno extends ConsumerStatefulWidget {
  const IniciarTurno({super.key});

  @override
  ConsumerState<IniciarTurno> createState() => _IniciarTurnoState();
}

class _IniciarTurnoState extends ConsumerState<IniciarTurno> {
  int? _elegido;
  bool _iniciando = false;

  /// Por qué no se pudo iniciar (permiso de ubicación), si pasó.
  PermisoUbicacion? _permiso;

  Future<void> _iniciar() async {
    setState(() {
      _iniciando = true;
      _permiso = null;
    });
    try {
      final permiso = await ref.read(turnoProvider.notifier).iniciar(_elegido!);
      // Con el turno abierto esta pantalla se reemplaza por el mapa.
      if (mounted && permiso != PermisoUbicacion.concedido) setState(() => _permiso = permiso);
    } on ErrorApi catch (e) {
      // P. ej. "El vehículo está en uso por otro chofer.": se avisa y se recarga la lista.
      if (!mounted) return;
      mostrarError(context, e);
      setState(() => _elegido = null);
      ref.invalidate(vehiculosDisponiblesProvider);
    } finally {
      if (mounted) setState(() => _iniciando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final vehiculos = ref.watch(vehiculosDisponiblesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Iniciar turno'), leading: const BotonCerrarModulo()),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_permiso case final permiso?) _AvisoPermiso(permiso: permiso),
          const Padding(padding: EdgeInsets.fromLTRB(16, 16, 16, 8), child: Text('Elegí el vehículo de hoy:')),
          Expanded(
            child: switch (vehiculos) {
              AsyncData(:final value) => RefreshIndicator(
                onRefresh: () => ref.refresh(vehiculosDisponiblesProvider.future),
                child: value.isEmpty
                    ? ListView(
                        children: const [
                          ListTile(title: Text('No hay vehículos disponibles. Consultá con el administrador.')),
                        ],
                      )
                    : ListView(
                        children: [
                          for (final v in value)
                            ListTile(
                              leading: const Icon(Icons.directions_car),
                              title: Text(v.descripcion),
                              subtitle: v.color == null ? null : Text(v.color!),
                              selected: v.id == _elegido,
                              trailing: v.id == _elegido ? const Icon(Icons.check_circle) : null,
                              onTap: _iniciando ? null : () => setState(() => _elegido = v.id),
                            ),
                        ],
                      ),
              ),
              AsyncError(:final error) => Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(mensajeDeError(error)),
                    TextButton(
                      onPressed: () => ref.invalidate(vehiculosDisponiblesProvider),
                      child: const Text('Reintentar'),
                    ),
                  ],
                ),
              ),
              _ => const Center(child: CircularProgressIndicator()),
            },
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: FilledButton(
              onPressed: _elegido != null && !_iniciando ? _iniciar : null,
              child: const Text('Iniciar turno'),
            ),
          ),
        ],
      ),
    );
  }
}

/// Spec 9: sin permiso de ubicación no hay turno; se explica y se ofrece ir a los ajustes.
class _AvisoPermiso extends ConsumerWidget {
  const _AvisoPermiso({required this.permiso});

  final PermisoUbicacion permiso;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final gpsApagado = permiso == PermisoUbicacion.gpsApagado;
    return MaterialBanner(
      leading: const Icon(Icons.location_off),
      content: Text(
        gpsApagado
            ? 'La ubicación del teléfono está apagada. Activala para iniciar el turno.'
            : 'Para iniciar el turno necesitamos tu ubicación: mientras el turno esté abierto se comparte '
                  'con el sistema de vehículos oficiales. Permití el acceso a la ubicación en los ajustes.',
      ),
      actions: [
        TextButton(
          onPressed: () => ref.read(ubicadorProvider).abrirAjustes(permiso),
          child: const Text('Abrir ajustes'),
        ),
      ],
    );
  }
}
