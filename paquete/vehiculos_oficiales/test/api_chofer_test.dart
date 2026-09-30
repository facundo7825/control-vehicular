import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;
import 'fixtures/payloads_chofer.dart' as c;
import 'soporte/adaptador_falso.dart';

void main() {
  late AdaptadorFalso http;
  late ApiVehiculos api;

  setUp(() {
    http = AdaptadorFalso();
    api = ApiVehiculos(
      ClienteApi(baseApi: Uri.parse('http://10.0.2.2:8000/api/'), alRecibir401: () {}, adaptador: http),
    );
    api.cliente.token = '2|chofer';
  });

  Object? cuerpo([int i = 0]) => jsonDecode(http.pedidos[i].cuerpo);

  test('vehículos disponibles y turno actual (con y sin turno)', () async {
    http.responder('GET', 'vehiculos/disponibles', 200, c.vehiculosDisponibles);
    http.responder('GET', 'turnos/actual', 200, c.sinTurno);
    http.responder('GET', 'turnos/actual', 200, c.turnoActual);

    expect((await api.vehiculosDisponibles()).single.patente, 'AB123CD');
    expect(await api.turnoActual(), isNull);
    expect((await api.turnoActual())!.vehiculo!.id, 1);
    expect(http.pedidos.first.headers['Authorization'], 'Bearer 2|chofer');
  });

  test('iniciar turno manda el vehículo; finalizar lee la respuesta sin vehículo', () async {
    http.responder('POST', 'turnos', 201, c.turnoIniciado);
    http.responder('POST', 'turnos/actual/finalizar', 200, c.turnoFinalizado);

    final t = await api.iniciarTurno(1);
    final f = await api.finalizarTurno();

    expect(t.abierto, isTrue);
    expect(cuerpo(0), {'vehiculo_id': 1});
    expect(f.abierto, isFalse);
    expect(f.vehiculo, isNull);
  });

  test('los 422 del turno llegan con el message del backend', () async {
    http.responder('POST', 'turnos', 422, c.vehiculoEnUso);
    http.responder('POST', 'turnos/actual/finalizar', 422, c.finalizarConViaje);

    await expectLater(
      api.iniciarTurno(1),
      throwsA(isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'El vehículo está en uso por otro chofer.')),
    );
    await expectLater(
      api.finalizarTurno(),
      throwsA(
        isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'Finalizá el viaje en curso antes de cerrar el turno.'),
      ),
    );
  });

  test('ubicación: lote de puntos con fechas UTC y 204', () async {
    http.responder('POST', 'ubicacion', 204);

    await api.enviarUbicacion([
      PuntoGps(
        posicion: const Coordenada(-26.83, -65.2),
        rumbo: 90,
        velocidad: 0,
        registradoEn: DateTime.utc(2026, 10, 1, 11, 59, 50),
      ),
      PuntoGps(posicion: const Coordenada(-26.84, -65.21), registradoEn: DateTime.utc(2026, 10, 1, 12)),
    ]);

    expect(cuerpo(), {
      'puntos': [
        {'lat': -26.83, 'lng': -65.2, 'rumbo': 90.0, 'velocidad': 0.0, 'registrado_en': '2026-10-01T11:59:50.000Z'},
        {'lat': -26.84, 'lng': -65.21, 'rumbo': null, 'velocidad': null, 'registrado_en': '2026-10-01T12:00:00.000Z'},
      ],
    });
  });

  test('ubicación sin turno: 422 de regla de negocio (sin errores por campo)', () async {
    http.responder('POST', 'ubicacion', 422, c.ubicacionSinTurno);

    await expectLater(
      api.enviarUbicacion([PuntoGps(posicion: const Coordenada(0, 0), registradoEn: DateTime.utc(2026))]),
      throwsA(
        isA<ErrorNegocio>()
            .having((e) => e.mensaje, 'mensaje', 'Iniciá un turno para compartir tu ubicación.')
            .having((e) => e.errores, 'errores', isEmpty),
      ),
    );
  });

  test('aceptar una oferta devuelve el viaje; rechazar es 204; una vencida es 422', () async {
    http.responder('POST', 'ofertas/1/aceptar', 200, p.viajeAceptado);
    http.responder('POST', 'ofertas/1/rechazar', 204);
    http.responder('POST', 'ofertas/2/aceptar', 422, c.ofertaNoVigente);

    final v = await api.aceptarOferta(1);
    await api.rechazarOferta(1);

    expect(v.estado, EstadoViaje.aceptado);
    expect(v.chofer!.id, 2);
    expect(http.pedidos.map((r) => r.uri.path), ['/api/ofertas/1/aceptar', '/api/ofertas/1/rechazar']);
    await expectLater(
      api.aceptarOferta(2),
      throwsA(isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'La oferta ya no está vigente.')),
    );
  });

  test('avanzar el viaje manda el estado; 403 si no es suyo', () async {
    http.responder('POST', 'viajes/1/estado', 200, p.viajeAceptado.replaceFirst('"aceptado"', '"en_camino"'));
    http.responder('POST', 'viajes/9/estado', 403, c.viajeAjeno);

    final v = await api.avanzarViaje(1, EstadoViaje.enCamino);

    expect(v.estado, EstadoViaje.enCamino);
    expect(cuerpo(), {'estado': 'en_camino'});
    await expectLater(
      api.avanzarViaje(9, EstadoViaje.llego),
      throwsA(isA<AccesoDenegado>().having((e) => e.mensaje, 'mensaje', 'Este viaje no es tuyo.')),
    );
  });

  test('cancelar como chofer manda el motivo y recibe el viaje ya sin chofer', () async {
    http.responder('POST', 'viajes/1/cancelar', 200, c.viajeCanceladoPorChofer);

    final v = await api.cancelarViaje(1, motivo: 'Se rompió el auto');

    expect(cuerpo(), {'motivo': 'Se rompió el auto'});
    expect(v.chofer, isNull);
    expect(v.estado, EstadoViaje.buscando);
  });

  test('agenda del chofer; como solicitante es 403', () async {
    http.responder('GET', 'agenda', 200, c.agenda);
    http.responder('GET', 'agenda', 403, p.sinPermiso);

    expect((await api.agenda()).solicitudes.single.id, 3);
    await expectLater(api.agenda(), throwsA(isA<AccesoDenegado>()));
  });

  test('una respuesta del chofer que no se puede leer es ErrorServidor', () async {
    http.responder('GET', 'turnos/actual', 200, '{"turno":{"id":"uno"}}');
    http.responder('GET', 'agenda', 200, '{"reservas":null}');

    await expectLater(api.turnoActual(), throwsA(isA<ErrorServidor>()));
    await expectLater(api.agenda(), throwsA(isA<ErrorServidor>()));
  });
}
