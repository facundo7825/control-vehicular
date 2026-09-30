import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../ubicacion/ubicador.dart';
import '../viaje/viaje_actual.dart';
import 'cola_ubicaciones.dart';
import 'emisor_ubicacion.dart';
import 'rastreador_turno.dart';

/// Los valores por defecto del backend (spec 5.7), si `GET /configuracion` no responde.
const configuracionPorDefecto = Configuracion(gpsTurnoSeg: 10, gpsViajeSeg: 5, ofertaSegundos: 30);

/// `GET /configuracion`. Si falla se usan los valores por defecto: el GPS del turno no puede depender de
/// que ese pedido salga bien.
final configuracionProvider = FutureProvider<Configuracion>((ref) async {
  try {
    return await ref.watch(apiProvider).configuracion();
  } on ErrorApi {
    return configuracionPorDefecto;
  }
});

/// `GET /vehiculos/disponibles` para la pantalla "Iniciar turno".
final vehiculosDisponiblesProvider = FutureProvider.autoDispose<List<Vehiculo>>(
  (ref) => ref.watch(apiProvider).vehiculosDisponibles(),
);

/// Última posición propia del GPS del turno, y si el GPS está fallando (para avisar en el mapa).
class PosicionPropia {
  const PosicionPropia({this.punto, this.sinGps = false});

  final PuntoGps? punto;
  final bool sinGps;
}

final posicionPropiaProvider = NotifierProvider<PosicionPropiaNotifier, PosicionPropia>(PosicionPropiaNotifier.new);

class PosicionPropiaNotifier extends Notifier<PosicionPropia> {
  @override
  PosicionPropia build() => const PosicionPropia();

  void punto(PuntoGps p) => state = PosicionPropia(punto: p);

  void sinGps() => state = PosicionPropia(punto: state.punto, sinGps: true);

  void limpiar() => state = const PosicionPropia();
}

/// Un viaje activo (aceptado a en curso) del chofer [choferId] cambia el ritmo de envío (spec 5.7). Uno
/// que se reasignó a otro chofer, o que terminó y sigue en pantalla, no cuenta.
bool viajeActivo(Viaje? v, int choferId) => v != null && v.estado.conChofer && v.chofer?.id == choferId;

final turnoProvider = AsyncNotifierProvider<TurnoNotifier, Turno?>(TurnoNotifier.new);

/// Turno del chofer (spec 7, chofer 1 y 6). Mientras está abierto, y solo entonces, corre un [RastreadorTurno]
/// (spec 10). Es el único dueño del rastreo: lo arranca, lo detiene y lo libera en `onDispose`.
class TurnoNotifier extends AsyncNotifier<Turno?> {
  RastreadorTurno? _rastreador;

  /// Cambia al detener el rastreo o descartar el notifier: un arranque que quedó esperando la
  /// configuración no arranca un rastreo viejo.
  int _generacion = 0;

  @override
  Future<Turno?> build() async {
    ref.onDispose(() {
      // Al cerrar el módulo (o recargar el turno) se corta el GPS. En onDispose no se puede usar `ref`.
      _generacion++;
      final r = _rastreador;
      _rastreador = null;
      r?.detener();
    });
    final yo = ref.read(usuarioProvider).id;
    ref.listen(
      viajeActualProvider.select((s) => viajeActivo(s.value?.viaje, yo)),
      (_, activo) => _rastreador?.enViaje(activo),
    );

    final turno = await ref.read(apiProvider).turnoActual();
    // Se cerró el módulo (o se recargó el turno) mientras tanto: no se pide permiso ni se abre el GPS.
    if (!ref.mounted) return turno;
    if (turno != null) {
      // Turno abierto de antes (la app se cerró o se reabrió el módulo): se retoma el rastreo. Si el
      // permiso ya no está, el GPS falla y el mapa lo avisa.
      await ref.read(ubicadorProvider).pedirPermiso();
      if (!ref.mounted) return turno;
      await _iniciarRastreo();
    }
    return turno;
  }

  /// Spec 7, chofer 1. Sin permiso de ubicación no se llama a la API y se devuelve el motivo. Los errores
  /// del backend (422 "El vehículo está en uso por otro chofer.") llegan a la pantalla.
  Future<PermisoUbicacion> iniciar(int vehiculoId) async {
    final permiso = await ref.read(ubicadorProvider).pedirPermiso();
    if (permiso != PermisoUbicacion.concedido || !ref.mounted) return permiso;

    final turno = await ref.read(apiProvider).iniciarTurno(vehiculoId);
    if (!ref.mounted) return permiso;
    state = AsyncData(turno);
    await _iniciarRastreo();
    return permiso;
  }

  /// Primero intenta mandar lo pendiente, después cierra el turno. Un 422 ("Finalizá el viaje en curso
  /// antes de cerrar el turno.") llega a la pantalla y el turno y el rastreo siguen como estaban. Si al
  /// vaciar la cola el backend ya dice que no hay turno (lo cerró un administrador), no se pide cerrarlo:
  /// se deja de rastrear y se vuelve a preguntar el turno.
  Future<void> finalizar() async {
    final vaciado = await _rastreador?.vaciar();
    if (!ref.mounted) return;
    if (vaciado == ResultadoEnvio.sinTurno) return _alQuedarSinTurno();
    await ref.read(apiProvider).finalizarTurno();
    if (!ref.mounted) return;
    _detenerRastreo();
    state = const AsyncData(null);
  }

  /// "Reintentar" del aviso de GPS: vuelve a pedir permiso y reabre el GPS (un stream que falló no se
  /// recupera solo). Lo pendiente en la cola se conserva.
  Future<void> reintentarGps() async {
    await ref.read(ubicadorProvider).pedirPermiso();
    if (ref.mounted) _rastreador?.reabrirGps();
  }

  Future<void> _iniciarRastreo() async {
    if (_rastreador != null) return;
    final generacion = _generacion;
    final conf = await ref.read(configuracionProvider.future);
    if (!ref.mounted || generacion != _generacion || _rastreador != null) return;

    final cola = ColaUbicaciones();
    final posicion = ref.read(posicionPropiaProvider.notifier);
    _rastreador = RastreadorTurno(
      ubicador: ref.read(ubicadorProvider),
      cola: cola,
      emisor: EmisorUbicacion(api: ref.read(apiProvider), cola: cola),
      intervaloTurno: Duration(seconds: conf.gpsTurnoSeg),
      intervaloViaje: Duration(seconds: conf.gpsViajeSeg),
      alPunto: posicion.punto,
      alErrorGps: (_) => posicion.sinGps(),
      alQuedarSinTurno: () => unawaited(_alQuedarSinTurno()),
    )..iniciar(enViaje: viajeActivo(ref.read(viajeActualProvider).value?.viaje, ref.read(usuarioProvider).id));
  }

  void _detenerRastreo() {
    _generacion++;
    _rastreador?.detener();
    _rastreador = null;
    ref.read(posicionPropiaProvider.notifier).limpiar();
  }

  /// El backend dice que no hay turno (lo cerró un admin, o se cerró en otro dispositivo): se deja de
  /// rastrear y se vuelve a preguntar. Corre sin await desde el timer: no puede propagar errores.
  Future<void> _alQuedarSinTurno() async {
    if (!ref.mounted) return;
    _detenerRastreo();
    try {
      final turno = await ref.read(apiProvider).turnoActual();
      if (!ref.mounted) return;
      state = AsyncData(turno);
      if (turno != null) await _iniciarRastreo();
    } on ErrorApi {
      if (ref.mounted) state = const AsyncData(null);
    }
  }
}
