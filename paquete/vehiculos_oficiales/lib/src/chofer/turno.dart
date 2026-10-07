import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../avisos/notificaciones_locales.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../ubicacion/ubicador.dart';
import '../viaje/viaje_actual.dart';
import 'almacen_cola.dart';
import 'cola_acciones.dart';
import 'cola_ubicaciones.dart';
import 'emisor_ubicacion.dart';
import 'rastreador_turno.dart';

/// Los valores por defecto del backend (spec 5.7), si `GET /configuracion` no responde. Sin mapa de fondo
/// (`teselas` nulo: no se sabe cuál es) y sin autocompletar; `mapaFondoProvider` reintenta el pedido.
const configuracionPorDefecto = Configuracion(gpsTurnoSeg: 10, gpsViajeSeg: 5, ofertaSegundos: 30, teselas: null);

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

final ubicacionesAtrasadasProvider = NotifierProvider<UbicacionesAtrasadasNotifier, bool>(
  UbicacionesAtrasadasNotifier.new,
);

/// Hay puntos del GPS del turno que no se pudieron mandar (ver `RastreadorTurno.alCambiarAtraso`).
class UbicacionesAtrasadasNotifier extends Notifier<bool> {
  @override
  bool build() => false;

  void fijar(bool atrasadas) => state = atrasadas;
}

/// Por qué "Finalizar turno" espera (decisión 3 del plan sin señal).
const esperandoSenalParaFinalizar = 'Esperando señal para enviar el viaje';

/// "Finalizar turno" espera mientras haya acciones del viaje o puntos del GPS sin enviar: sin turno, el
/// servidor ya no puede ubicar el recorrido que falta.
final finalizarEsperaSenalProvider = Provider<bool>(
  (ref) => hayAcciones(ref.watch(colaAccionesProvider)) || ref.watch(ubicacionesAtrasadasProvider),
);

/// Hay acciones del viaje sin enviar (o todavía no se sabe: la cola se está leyendo del disco).
bool hayAcciones(AsyncValue<List<AccionViaje>> cola) => !(cola.value?.isEmpty ?? false);

/// Un viaje activo (aceptado a en curso) del chofer [choferId] cambia el ritmo de envío (spec 5.7). Uno
/// que se reasignó a otro chofer, o que terminó y sigue en pantalla, no cuenta.
bool viajeActivo(Viaje? v, int choferId) => v != null && v.estado.conChofer && v.chofer?.id == choferId;

final turnoProvider = AsyncNotifierProvider<TurnoNotifier, Turno?>(TurnoNotifier.new);

/// Turno del chofer (spec 7, chofer 1 y 6). Mientras está abierto, y solo entonces, corre un [RastreadorTurno]
/// (spec 10). Es el único dueño del rastreo: lo arranca, lo detiene y lo libera en `onDispose`.
///
/// El turno también lo abre y lo cierra el fichaje de asistencia: el push `turno` llama a [refrescar] y, sin
/// turno, se pregunta cada [intervaloSondeo] por si el push no llega.
///
/// El sondeo corre solo con la app en primer plano, y al volver a primer plano se pregunta enseguida. Un turno
/// que aparece con la app en segundo plano (push, fichaje) se muestra, pero el GPS arranca recién al volver:
/// Android 12+ no deja iniciar el servicio de ubicación en primer plano desde segundo plano. Un rastreo que ya
/// corre sigue en segundo plano.
class TurnoNotifier extends AsyncNotifier<Turno?> {
  static const intervaloSondeo = Duration(seconds: 30);

  RastreadorTurno? _rastreador;

  /// Solo corre sin turno (la pantalla "Iniciar turno") y en primer plano.
  Timer? _sondeo;

  /// Sin turno: hay que preguntar cada [intervaloSondeo] (mientras la app esté en primer plano).
  bool _sinTurno = false;

  /// La app se ve: `resumed` o `inactive` (tapada un momento por un diálogo del sistema o el panel de
  /// notificaciones). Sin estado todavía (al arrancar) cuenta como primer plano.
  bool _enPrimerPlano = true;

  /// Hay un turno que no se retomó por estar en segundo plano: se retoma al volver.
  bool _retomarAlVolver = false;

  /// La consulta de [refrescar] en curso, y si hay que repetirla al terminar (llegó otro aviso mientras tanto).
  Future<void>? _refresco;
  bool _otraVez = false;

  /// Cambia al detener el rastreo o descartar el notifier: un arranque que quedó esperando la
  /// configuración no arranca un rastreo viejo.
  int _generacion = 0;

  @override
  Future<Turno?> build() async {
    _enPrimerPlano = _esPrimerPlano(WidgetsBinding.instance.lifecycleState);
    final ciclo = AppLifecycleListener(onStateChange: _alCambiarCiclo);
    ref.onDispose(() {
      // Al cerrar el módulo (o recargar el turno) se corta el GPS. En onDispose no se puede usar `ref`.
      // Lo que no se llegó a guardar se guarda: el turno sigue abierto y se retoma al volver.
      ciclo.dispose();
      _generacion++;
      _retomarAlVolver = false;
      _sondeo?.cancel();
      _sondeo = null;
      final r = _rastreador;
      _rastreador = null;
      r
        ?..guardarPendiente()
        ..detener();
    });
    final yo = ref.read(usuarioProvider).id;
    ref.listen(
      viajeActualProvider.select((s) => viajeActivo(s.value?.viaje, yo)),
      (_, activo) => _rastreador?.enViaje(activo),
    );
    // Los puntos esperan a las acciones del viaje (ver `EmisorUbicacion.retener`): cuando salió la última, se
    // mandan enseguida.
    ref.listen(colaAccionesProvider.select((s) => !hayAcciones(s)), (antes, vacia) {
      if (vacia && antes == false) _rastreador?.enviarAhora();
    });

    final turno = await ref.read(apiProvider).turnoActual();
    // Se cerró el módulo (o se recargó el turno) mientras tanto: no se pide permiso ni se abre el GPS.
    if (!ref.mounted) return turno;
    _ajustarSondeo(sinTurno: turno == null);
    if (turno == null) {
      await _borrarCola(); // lo guardado es de un turno que ya se cerró (spec 10)
    } else if (_enPrimerPlano) {
      // Turno abierto de antes (la app se cerró o se reabrió el módulo).
      await _retomar(turno, desdeBuild: true);
    } else {
      _retomarAlVolver = true;
    }
    return turno;
  }

  static bool _esPrimerPlano(AppLifecycleState? ciclo) =>
      ciclo == null || ciclo == AppLifecycleState.resumed || ciclo == AppLifecycleState.inactive;

  /// En segundo plano se pausa el sondeo. Al volver: se retoma el turno que quedó esperando, se pregunta
  /// enseguida (pudo abrirse o cerrarse mientras tanto) y se rearma el sondeo.
  void _alCambiarCiclo(AppLifecycleState ciclo) {
    final antes = _enPrimerPlano;
    _enPrimerPlano = _esPrimerPlano(ciclo);
    if (!ref.mounted || antes == _enPrimerPlano) return;
    _armarSondeo();
    if (!_enPrimerPlano) return;
    final turno = state.value;
    if (_retomarAlVolver && turno != null && _rastreador == null) unawaited(_retomar(turno));
    _retomarAlVolver = false;
    unawaited(refrescar());
    unawaited(ref.read(colaAccionesProvider.notifier).sincronizar());
  }

  /// Retoma [turno] ya si la app está en primer plano; si no, al volver.
  void _retomarEnPrimerPlano(Turno turno) {
    if (_enPrimerPlano) {
      unawaited(_retomar(turno));
    } else {
      _retomarAlVolver = true;
    }
  }

  /// Un turno abierto que la app no inició (de antes, o abierto por un fichaje): se retoma el rastreo con lo
  /// que quedó sin enviar. Si el permiso ya no está, el GPS falla y el mapa lo avisa.
  ///
  /// Mientras espera (el diálogo de permiso, el archivo de la cola, la configuración) el turno puede
  /// cerrarse (finalizar, push `cerrado`, módulo cerrado): después de cada espera se verifica que siga
  /// siendo el mismo, y si no, no abre el GPS. Nunca lanza: si algo falla queda el aviso "Sin señal de GPS"
  /// del mapa, y su "Reintentar" vuelve a llamar acá ([reintentarGps]). [desdeBuild]: el turno todavía no
  /// está en `state` (lo devuelve build).
  Future<void> _retomar(Turno turno, {bool desdeBuild = false}) async {
    final generacion = _generacion;
    bool vigente() => ref.mounted && generacion == _generacion && (desdeBuild || state.value?.id == turno.id);
    try {
      await ref.read(ubicadorProvider).pedirPermiso();
      if (!vigente()) return;
      unawaited(ref.read(notificacionesLocalesProvider).pedirPermiso());
      final pendientes = await _leerCola(turno.id);
      if (!vigente()) return;
      await _iniciarRastreo(turno, pendientes: pendientes, generacion: generacion);
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo retomar el rastreo del turno (${e.runtimeType}).');
      if (vigente()) ref.read(posicionPropiaProvider.notifier).sinGps();
    }
  }

  /// Vuelve a preguntar el turno: lo llaman el push `turno` (un fichaje lo abrió o lo cerró) y el sondeo.
  /// Si apareció un turno arranca el GPS como al abrir la app; si se cerró, lo corta; si es el mismo, no
  /// toca el rastreo. Nunca lanza: un error de red se ignora y se reintenta en el próximo sondeo.
  ///
  /// Una llamada mientras otra consulta está en curso no se pierde: al terminar se consulta otra vez (el
  /// push más nuevo siempre se procesa). La consulta no espera a que arranque el GPS ([_retomar] corre
  /// aparte), así un push `cerrado` con el diálogo de permiso abierto se procesa enseguida.
  Future<void> refrescar() {
    final enCurso = _refresco;
    if (enCurso != null) {
      _otraVez = true;
      return enCurso;
    }
    return _refresco = _refrescarMientrasHaga();
  }

  /// `_refresco` se limpia acá, en el mismo paso en que el bucle decide terminar: un [refrescar] que llegue
  /// después ya arranca una consulta nueva (no se cuelga de esta, que ya no vuelve a consultar).
  Future<void> _refrescarMientrasHaga() async {
    try {
      do {
        _otraVez = false;
        try {
          await _refrescar();
        } catch (e) {
          debugPrint('vehiculos_oficiales: no se pudo refrescar el turno (${e.runtimeType}).');
        }
      } while (_otraVez && ref.mounted);
    } finally {
      _refresco = null;
    }
  }

  Future<void> _refrescar() async {
    if (state.isLoading) return; // lo está leyendo build: ya trae lo último
    if (!state.hasValue) return ref.invalidateSelf(); // la pantalla muestra un error: se reintenta
    final antes = state.value;
    final Turno? turno;
    try {
      turno = await ref.read(apiProvider).turnoActual();
    } on ErrorApi {
      return;
    }
    // Mientras tanto el chofer inició, finalizó o cambió de vehículo: lo suyo es más nuevo y manda.
    if (!ref.mounted || !identical(state.value, antes)) return;
    if (turno?.id == antes?.id) {
      if (turno != null) state = AsyncData(turno);
      return;
    }
    if (antes != null) _detenerRastreo();
    state = AsyncData(turno);
    _ajustarSondeo(sinTurno: turno == null);
    if (turno != null) _retomarEnPrimerPlano(turno);
  }

  /// Cambia el vehículo del turno abierto, sin tocar el rastreo. Los 422 (vehículo en uso, viaje activo)
  /// llegan a la pantalla y el turno sigue como estaba.
  Future<void> cambiarVehiculo(int vehiculoId) async {
    final turno = await ref.read(apiProvider).cambiarVehiculo(vehiculoId);
    if (ref.mounted) state = AsyncData(turno);
  }

  void _ajustarSondeo({required bool sinTurno}) {
    _sinTurno = sinTurno;
    _armarSondeo();
  }

  void _armarSondeo() {
    if (_sinTurno && _enPrimerPlano) {
      _sondeo ??= Timer.periodic(intervaloSondeo, (_) => unawaited(refrescar()));
    } else {
      _sondeo?.cancel();
      _sondeo = null;
    }
  }

  /// Spec 7, chofer 1. Sin permiso de ubicación no se llama a la API y se devuelve el motivo. Los errores
  /// del backend (422 "El vehículo está en uso por otro chofer.") llegan a la pantalla.
  Future<PermisoUbicacion> iniciar(int vehiculoId) async {
    final permiso = await ref.read(ubicadorProvider).pedirPermiso();
    if (permiso != PermisoUbicacion.concedido || !ref.mounted) return permiso;

    final turno = await ref.read(apiProvider).iniciarTurno(vehiculoId);
    if (!ref.mounted) return permiso;
    state = AsyncData(turno);
    _ajustarSondeo(sinTurno: false);
    // Android 13+: sin este permiso las ofertas con la app en segundo plano no se ven.
    unawaited(ref.read(notificacionesLocalesProvider).pedirPermiso());
    await _iniciarRastreo(turno);
    return permiso;
  }

  /// Primero intenta mandar lo pendiente, después cierra el turno. Un 422 ("Finalizá el viaje en curso
  /// antes de cerrar el turno.") llega a la pantalla y el turno y el rastreo siguen como estaban. Si al
  /// vaciar la cola el backend ya dice que no hay turno (lo cerró un administrador), no se pide cerrarlo:
  /// se deja de rastrear y se vuelve a preguntar el turno.
  ///
  /// Con acciones del viaje sin enviar no se cierra: se intenta mandarlas y, si no salen, lanza [SinConexion]
  /// ("Esperando señal para enviar el viaje.").
  Future<void> finalizar() async {
    if (hayAcciones(ref.read(colaAccionesProvider))) {
      await ref.read(colaAccionesProvider.notifier).sincronizar();
      if (!ref.mounted) return;
      if (hayAcciones(ref.read(colaAccionesProvider))) throw const SinConexion('$esperandoSenalParaFinalizar.');
    }
    final vaciado = await _rastreador?.vaciar();
    if (!ref.mounted) return;
    if (vaciado == ResultadoEnvio.sinTurno) return _alQuedarSinTurno();
    await ref.read(apiProvider).finalizarTurno();
    if (!ref.mounted) return;
    _detenerRastreo();
    state = const AsyncData(null);
    _ajustarSondeo(sinTurno: true);
  }

  /// "Reintentar" del aviso de GPS: vuelve a pedir permiso y reabre el GPS (un stream que falló no se
  /// recupera solo). Lo pendiente en la cola se conserva. Si el rastreo no llegó a arrancar (falló al
  /// retomar un turno), lo vuelve a intentar.
  Future<void> reintentarGps() async {
    final turno = state.value;
    if (_rastreador == null && turno != null) return _retomar(turno);
    await ref.read(ubicadorProvider).pedirPermiso();
    if (ref.mounted) _rastreador?.reabrirGps();
  }

  /// [pendientes]: los puntos guardados del mismo turno, que vuelven a la cola antes de abrir el GPS.
  /// [generacion]: la de cuando se decidió arrancar (por defecto, la actual); si cambió, no arranca.
  Future<void> _iniciarRastreo(Turno turno, {List<PuntoGps> pendientes = const [], int? generacion}) async {
    if (_rastreador != null) return;
    final gen = generacion ?? _generacion;
    if (gen != _generacion) return;
    final conf = await ref.read(configuracionProvider.future);
    if (!ref.mounted || gen != _generacion || _rastreador != null) return;
    // Pasó a segundo plano mientras esperaba (el permiso, la cola, la configuración): arranca al volver.
    if (!_enPrimerPlano) {
      _retomarAlVolver = true;
      return;
    }

    final cola = ColaUbicaciones()..cargar(pendientes);
    final posicion = ref.read(posicionPropiaProvider.notifier);
    final almacen = ref.read(almacenColaProvider);
    final aviso = ref.read(avisoSesionProvider);
    final atraso = ref.read(ubicacionesAtrasadasProvider.notifier);
    _rastreador = RastreadorTurno(
      ubicador: ref.read(ubicadorProvider),
      cola: cola,
      // Primero las acciones del viaje: así el servidor ya conoce el intervalo del viaje al recibir los puntos.
      emisor: EmisorUbicacion(
        api: ref.read(apiProvider),
        cola: cola,
        retener: () => ref.mounted && hayAcciones(ref.read(colaAccionesProvider)),
      ),
      alCambiarAtraso: atraso.fijar,
      intervaloTurno: Duration(seconds: conf.gpsTurnoSeg),
      intervaloViaje: Duration(seconds: conf.gpsViajeSeg),
      alPunto: posicion.punto,
      alErrorGps: (_) => posicion.sinGps(),
      alQuedarSinTurno: () => unawaited(_alQuedarSinTurno()),
      // Después de un 401 la cola ya se borró (SesionNotifier): no se vuelve a escribir.
      guardar: (puntos) async {
        if (!aviso.avisado) await almacen.guardar(turno.id, puntos);
      },
    )..iniciar(enViaje: viajeActivo(ref.read(viajeActualProvider).value?.viaje, ref.read(usuarioProvider).id));
  }

  /// El turno terminó (se finalizó, o el backend dice que no hay): también se borra la cola guardada.
  void _detenerRastreo() {
    _generacion++;
    _retomarAlVolver = false;
    _rastreador?.detener();
    _rastreador = null;
    ref.read(posicionPropiaProvider.notifier).limpiar();
    ref.read(ubicacionesAtrasadasProvider.notifier).fijar(false);
    unawaited(_borrarCola());
  }

  /// Lo guardado para [turnoId]. Si no hay nada (o es de otro turno, o no se puede leer) se borra el
  /// archivo. Nunca lanza.
  Future<List<PuntoGps>> _leerCola(int turnoId) async {
    final almacen = ref.read(almacenColaProvider);
    try {
      final puntos = await almacen.leer(turnoId);
      if (puntos.isEmpty) await almacen.borrar();
      return puntos;
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo leer la cola de ubicaciones guardada (${e.runtimeType}).');
      return const [];
    }
  }

  /// Nunca lanza: un error de disco no puede cortar el cierre del turno.
  Future<void> _borrarCola() async {
    try {
      await ref.read(almacenColaProvider).borrar();
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo borrar la cola de ubicaciones guardada (${e.runtimeType}).');
    }
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
      _ajustarSondeo(sinTurno: turno == null);
      if (turno != null) await _iniciarRastreo(turno); // en segundo plano, arranca al volver
    } on ErrorApi {
      if (!ref.mounted) return;
      state = const AsyncData(null);
      _ajustarSondeo(sinTurno: true);
    }
  }
}
