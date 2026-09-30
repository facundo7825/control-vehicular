import 'dart:async';

import 'package:vehiculos_oficiales/src/api/errores_api.dart';
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

  /// Lotes recibidos por `POST /ubicacion` (también los que fallaron).
  final lotes = <List<PuntoGps>>[];

  /// Errores de los próximos envíos de ubicación, en orden; sin errores pendientes responde 204.
  final erroresUbicacion = <ErrorApi>[];

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

  @override
  Future<void> enviarUbicacion(List<PuntoGps> puntos) async {
    llamadas.add('ubicacion:${puntos.length}');
    lotes.add(List.of(puntos));
    if (demoraUbicacion != null) await demoraUbicacion!.future;
    if (erroresUbicacion.isNotEmpty) throw erroresUbicacion.removeAt(0);
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
  Future<Viaje> avanzarViaje(int viajeId, EstadoViaje estado) async {
    llamadas.add('avanzar:$viajeId:${estado.valor}');
    avances.add((viajeId, estado));
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
