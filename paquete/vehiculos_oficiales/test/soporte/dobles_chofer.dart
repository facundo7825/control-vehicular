import 'dart:async';
import 'dart:convert';

import 'package:fake_async/fake_async.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/almacen_cola.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_acciones.dart';
import 'package:vehiculos_oficiales/src/chofer/estado_guardado.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import 'dobles.dart';

Turno turnoDePrueba() => Turno.fromJson(leerMapa(p.json(c.turnoActual)['turno']));

PuntoGps punto(int segundo, {double lat = -26.83}) => PuntoGps(
  posicion: Coordenada(lat, -65.2),
  registradoEn: DateTime.utc(2026, 10, 1, 12).add(Duration(seconds: segundo)),
);

/// Segundos de cada punto desde las 12:00 (la hora de [punto]).
List<int> segundos(List<PuntoGps> puntos) => [
  for (final p in puntos) p.registradoEn.difference(DateTime.utc(2026, 10, 1, 12)).inSeconds,
];

/// [ApiFalsa] con los endpoints del chofer. Registra lo que se llama y en qué orden ([llamadas]).
class ApiChofer extends ApiFalsa {
  final llamadas = <String>[];

  Configuracion configuracionRespuesta = const Configuracion(gpsTurnoSeg: 10, gpsViajeSeg: 5, ofertaSegundos: 30);

  List<Vehiculo> vehiculos = [];
  Turno? turno;
  ErrorApi? errorTurnoActual;
  ErrorApi? errorIniciar;
  ErrorApi? errorFinalizar;
  ErrorApi? errorCambiar;

  /// Lotes recibidos por `POST /ubicacion` (también los que fallaron).
  final lotes = <List<PuntoGps>>[];

  /// Errores de los próximos envíos de ubicación, en orden; sin errores pendientes responde 204.
  final erroresUbicacion = <ErrorApi>[];

  /// Error de todos los envíos de ubicación (después de [erroresUbicacion]), p. ej. sin señal.
  ErrorApi? errorUbicacion;

  /// Si no es nulo, `enviarUbicacion` espera a que el test lo complete.
  Completer<void>? demoraUbicacion;

  /// Si no es nulo, `turnoActual` espera a que el test lo complete.
  Completer<void>? demoraTurno;

  /// Si no es nulo, `viajeActual` espera a que el test lo complete (una consulta que tarda).
  Completer<void>? demoraActual;

  Viaje? respuestaAceptar;
  ErrorApi? errorOferta;
  Viaje? respuestaAvance;
  ErrorApi? errorAvance;
  final avances = <(int, EstadoViaje)>[];

  /// El `id_accion` y el `momento` de cada avance, en el mismo orden que [avances].
  final acciones = <({String? idAccion, DateTime? momento})>[];

  /// Errores de los próximos avances, en orden (antes que [errorAvance]).
  final erroresAvance = <ErrorApi>[];

  /// Si no es nulo, `avanzarViaje` espera a que el test lo complete.
  Completer<void>? demoraAvance;
  Viaje? respuestaCancelar;
  Agenda agendaRespuesta = Agenda.vacia;
  ErrorApi? errorAgenda;

  @override
  Future<ViajeActual> viajeActual() async {
    final consulta = super.viajeActual(); // lee `actual` al empezar, como el servidor
    if (demoraActual != null) await demoraActual!.future;
    return consulta;
  }

  @override
  Future<Configuracion> configuracion() async {
    llamadas.add('configuracion');
    return configuracionRespuesta;
  }

  @override
  Future<List<Vehiculo>> vehiculosDisponibles() async {
    llamadas.add('vehiculos');
    return vehiculos;
  }

  @override
  Future<Turno?> turnoActual() async {
    llamadas.add('turnoActual');
    if (demoraTurno != null) await demoraTurno!.future;
    if (errorTurnoActual != null) throw errorTurnoActual!;
    return turno;
  }

  @override
  Future<Turno> iniciarTurno(int vehiculoId) async {
    llamadas.add('iniciar:$vehiculoId');
    if (errorIniciar != null) throw errorIniciar!;
    return turno = turnoDePrueba();
  }

  @override
  Future<Turno> finalizarTurno() async {
    llamadas.add('finalizar');
    if (errorFinalizar != null) throw errorFinalizar!;
    turno = null;
    return Turno.fromJson(p.json(c.turnoFinalizado));
  }

  /// Responde el turno abierto con el vehículo 2 (Ford Ranger).
  @override
  Future<Turno> cambiarVehiculo(int vehiculoId) async {
    llamadas.add('cambiar:$vehiculoId');
    if (errorCambiar != null) throw errorCambiar!;
    return turno = Turno.fromJson(leerMapa(p.json(c.turnoConOtroVehiculo)['turno']));
  }

  @override
  Future<void> enviarUbicacion(List<PuntoGps> puntos) async {
    llamadas.add('ubicacion:${puntos.length}');
    lotes.add(List.of(puntos));
    if (demoraUbicacion != null) await demoraUbicacion!.future;
    if (erroresUbicacion.isNotEmpty) throw erroresUbicacion.removeAt(0);
    if (errorUbicacion != null) throw errorUbicacion!;
  }

  @override
  Future<Viaje> aceptarOferta(int ofertaId) async {
    llamadas.add('aceptar:$ofertaId');
    if (errorOferta != null) throw errorOferta!;
    return respuestaAceptar ?? Viaje.fromJson(p.json(p.viajeAceptado));
  }

  @override
  Future<void> rechazarOferta(int ofertaId) async {
    llamadas.add('rechazar:$ofertaId');
    if (errorOferta != null) throw errorOferta!;
  }

  @override
  Future<Viaje> avanzarViaje(int viajeId, EstadoViaje estado, {DateTime? momento, String? idAccion}) async {
    llamadas.add('avanzar:$viajeId:${estado.valor}');
    avances.add((viajeId, estado));
    acciones.add((idAccion: idAccion, momento: momento));
    if (demoraAvance != null) await demoraAvance!.future;
    if (erroresAvance.isNotEmpty) throw erroresAvance.removeAt(0);
    if (errorAvance != null) throw errorAvance!;
    return respuestaAvance ??
        Viaje.fromJson(
          p.json(p.viajeAceptado)
            ..['id'] = viajeId
            ..['estado'] = estado.valor,
        );
  }

  @override
  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) async {
    cancelaciones.add((viajeId, motivo));
    return respuestaCancelar ?? Viaje.fromJson(p.json(c.viajeCanceladoPorChofer)..['id'] = viajeId);
  }

  @override
  Future<Agenda> agenda() async {
    llamadas.add('agenda');
    if (errorAgenda != null) throw errorAgenda!;
    return agendaRespuesta;
  }
}

/// [AlmacenCola] en memoria: lo que quedaría en el archivo, y cada escritura que llegó a hacerse.
class AlmacenColaMemoria implements AlmacenCola {
  /// Turno de lo guardado; nulo si no hay nada guardado (el archivo no existe).
  int? turnoId;

  /// Chofer de lo guardado: por defecto el de prueba ([chofer], id 2).
  int usuarioId = 2;
  List<PuntoGps> puntos = [];

  /// Cada `guardar`, con los puntos que recibió.
  final escrituras = <List<PuntoGps>>[];
  int borrados = 0;

  /// Si no es nulo, todas las operaciones lo lanzan (un disco lleno, un permiso).
  Object? error;

  bool get guardado => turnoId != null;

  @override
  Future<List<PuntoGps>> leer(int usuarioId, int turnoId) async {
    if (error != null) throw error!;
    return usuarioId == this.usuarioId && turnoId == this.turnoId ? List.of(puntos) : [];
  }

  @override
  Future<({int turnoId, List<PuntoGps> puntos})?> leerCualquiera(int usuarioId) async {
    if (error != null) throw error!;
    final id = turnoId;
    return id == null || usuarioId != this.usuarioId ? null : (turnoId: id, puntos: List.of(puntos));
  }

  @override
  Future<void> guardar(int usuarioId, int turnoId, List<PuntoGps> puntos) async {
    if (error != null) throw error!;
    this.usuarioId = usuarioId;
    this.turnoId = turnoId;
    this.puntos = List.of(puntos);
    escrituras.add(List.of(puntos));
  }

  @override
  Future<void> borrar(int usuarioId) async {
    if (error != null) throw error!;
    if (usuarioId != this.usuarioId) return; // el archivo de otro chofer no se toca
    borrados++;
    turnoId = null;
    puntos = [];
  }
}

/// [AlmacenAcciones] en memoria: lo que quedaría en el archivo (sobrevive a un contenedor descartado, como el
/// archivo a la app cerrada).
class AlmacenAccionesMemoria implements AlmacenAcciones {
  /// Usuario de lo guardado; nulo si no hay nada guardado.
  int? usuarioId;
  List<AccionViaje> acciones = [];

  /// Si no es nulo, todas las operaciones lo lanzan.
  Object? error;

  @override
  Future<List<AccionViaje>> leer(int usuarioId) async {
    if (error != null) throw error!;
    return usuarioId == this.usuarioId ? List.of(acciones) : [];
  }

  @override
  Future<void> guardar(int usuarioId, List<AccionViaje> acciones) async {
    if (error != null) throw error!;
    this.usuarioId = usuarioId;
    this.acciones = List.of(acciones);
  }

  @override
  Future<void> borrar(int usuarioId) async {
    if (error != null) throw error!;
    if (usuarioId != this.usuarioId) return;
    this.usuarioId = null;
    acciones = [];
  }
}

/// [AlmacenJson] en memoria (sobrevive a un contenedor descartado).
class AlmacenJsonMemoria implements AlmacenJson {
  Json? datos;

  @override
  Future<Json?> leer() async => datos == null ? null : jsonDecode(jsonEncode(datos)) as Json;

  @override
  Future<void> guardar(Json datos) async => this.datos = jsonDecode(jsonEncode(datos)) as Json;

  @override
  Future<void> borrar() async => datos = null;
}

/// [fakeAsync] con el reloj en la hora de [punto] (las 12:00 del 1/10/2026): los puntos de los tests no tienen
/// más de 24 h, que el servidor ya no acepta y la app descarta.
void enHoraDeLosPuntos(void Function(FakeAsync async) prueba) =>
    fakeAsync(prueba, initialTime: DateTime.utc(2026, 10, 1, 12, 30));
