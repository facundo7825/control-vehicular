import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/adaptador_falso.dart';

void main() {
  late AdaptadorFalso http;
  late int avisos401;
  late ApiVehiculos api;

  setUp(() {
    http = AdaptadorFalso();
    avisos401 = 0;
    api = ApiVehiculos(
      ClienteApi(baseApi: Uri.parse('http://10.0.2.2:8000/api/'), alRecibir401: () => avisos401++, adaptador: http),
    );
  });

  test('el intercambio manda el token del PJ en JSON y sin Authorization', () async {
    http.responder('POST', 'auth/intercambio', 200, p.intercambio);

    final r = await api.intercambiar('sim|100|Ana Pérez|Secretaria');

    expect(r.token, startsWith('1|'));
    expect(r.usuario.rol, Rol.solicitante);
    final pedido = http.pedidos.single;
    expect(pedido.uri.toString(), 'http://10.0.2.2:8000/api/auth/intercambio');
    expect(pedido.headers['Accept'], 'application/json');
    expect(pedido.headers.containsKey('Authorization'), isFalse);
    expect(jsonDecode(pedido.cuerpo), {'token_externo': 'sim|100|Ana Pérez|Secretaria'});
  });

  test('con token, cada pedido lleva Authorization: Bearer', () async {
    http.responder('GET', 'viajes/actual', 200, p.viajeActualSolicitante);
    api.cliente.token = '1|abc';

    final actual = await api.viajeActual();

    expect(actual.viaje!.estado, EstadoViaje.ofrecido);
    expect(http.pedidos.single.headers['Authorization'], 'Bearer 1|abc');
  });

  test('la ETA sale por GET viajes/{id}/eta con Bearer', () async {
    http.responder(
      'GET',
      'viajes/7/eta',
      200,
      '{"hacia":"origen","segundos":240,"metros":1850,"calculado_en":"2026-10-01T12:00:00+00:00","ubicacion_actualizada_en":null}',
    );
    api.cliente.token = '1|abc';

    final eta = await api.eta(7);

    expect(eta.segundos, 240);
    expect(http.pedidos.single.uri.path, '/api/viajes/7/eta');
    expect(http.pedidos.single.headers['Authorization'], 'Bearer 1|abc');
  });

  test('401 lanza SesionInvalida y avisa', () async {
    http.responder('GET', 'yo', 401, p.noAutenticado);

    await expectLater(api.yo(), throwsA(isA<SesionInvalida>().having((e) => e.mensaje, 'mensaje', 'Unauthenticated.')));
    expect(avisos401, 1);
  });

  test('403, 404, 422 y 503 se traducen con el message del backend', () async {
    http.responder('GET', 'viajes', 403, p.sinPermiso);
    http.responder('POST', 'viajes/1/cancelar', 422, p.reglaNegocio);
    http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    http.responder('GET', 'configuracion', 404, '{"message":"Not Found"}');

    await expectLater(
      api.misViajes(),
      throwsA(isA<AccesoDenegado>().having((e) => e.mensaje, 'mensaje', 'No tenés permiso para esta acción.')),
    );
    await expectLater(
      api.cancelarViaje(1),
      throwsA(
        isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'El viaje no puede pasar de sin_chofer a cancelado.'),
      ),
    );
    await expectLater(api.intercambiar('x'), throwsA(isA<ServicioNoDisponible>()));
    await expectLater(api.configuracion(), throwsA(isA<NoEncontrado>()));
    expect(avisos401, 0);
  });

  test('una validación de Laravel conserva los errores por campo', () async {
    http.responder('POST', 'viajes', 422, p.validacion);

    final pedido = PedidoViaje(
      modo: ModoViaje.masCercano,
      origen: const Lugar(Coordenada(0, 0)),
      destino: const Lugar(Coordenada(0, 0)),
    );

    await expectLater(
      api.pedirViaje(pedido),
      throwsA(
        isA<ErrorNegocio>().having((e) => e.errores['origen_lat'], 'errores', ['The origen lat field is required.']),
      ),
    );
  });

  test(
    'el detalle de un viaje sale por GET viajes/{id}; un 403 es AccesoDenegado y uno ilegible ErrorServidor',
    () async {
      http.responder('GET', 'viajes/7', 200, p.viajeAceptado.replaceFirst('"id":1,', '"id":7,'));
      http.responder('GET', 'viajes/8', 403, '{"message":"Este viaje no es tuyo."}');
      http.responder('GET', 'viajes/9', 200, p.viajeAceptado.replaceFirst('"aceptado"', '"volando"'));

      final v = await api.viaje(7);

      expect(v.id, 7);
      expect(v.chofer!.id, 2);
      await expectLater(
        api.viaje(8),
        throwsA(isA<AccesoDenegado>().having((e) => e.mensaje, 'mensaje', 'Este viaje no es tuyo.')),
      );
      await expectLater(api.viaje(9), throwsA(isA<ErrorServidor>()));
    },
  );

  test('sin red es SinConexion; un 500 o una respuesta que no es JSON es ErrorServidor', () async {
    http.sinRed('GET', 'choferes');
    http.responder('GET', 'viajes/actual', 500, '{"message":"Server Error"}');
    http.responder('GET', 'viajes', 200, '<html>proxy</html>');

    await expectLater(api.choferes(), throwsA(isA<SinConexion>()));
    await expectLater(api.viajeActual(), throwsA(isA<ErrorServidor>()));
    await expectLater(api.misViajes(), throwsA(isA<ErrorServidor>()));
  });

  test('una respuesta que no se puede leer (estado desconocido, tipos cambiados) es ErrorServidor', () async {
    http.responder('GET', 'viajes/actual', 200, p.viajeActualSolicitante.replaceFirst('"ofrecido"', '"volando"'));
    http.responder('GET', 'choferes', 200, p.choferes.replaceFirst('"libre"', '"de_vacaciones"'));
    http.responder('GET', 'viajes', 200, '{"proximas":null,"historial":[]}');
    http.responder('GET', 'viajes/1/eta', 200, '{"minutos":"muchos"}');
    http.responder('POST', 'auth/intercambio', 200, '[]');

    await expectLater(api.viajeActual(), throwsA(isA<ErrorServidor>()));
    await expectLater(api.choferes(), throwsA(isA<ErrorServidor>()));
    await expectLater(api.misViajes(), throwsA(isA<ErrorServidor>()));
    await expectLater(api.eta(1), throwsA(isA<ErrorServidor>()));
    await expectLater(api.intercambiar('sim|1|A|B'), throwsA(isA<ErrorServidor>()));
  });

  test('pedir un viaje manda los campos de ViajeController::store', () async {
    http.responder('POST', 'viajes', 201, p.viajeOfrecido);

    final v = await api.pedirViaje(
      const PedidoViaje(
        modo: ModoViaje.especifico,
        choferId: 2,
        origen: Lugar(Coordenada(-26.8241, -65.2226), direccion: 'Plaza Independencia'),
        destino: Lugar(Coordenada(-26.8083, -65.2176)),
        motivo: 'Audiencia',
      ),
    );

    expect(v.id, 1);
    expect(jsonDecode(http.pedidos.single.cuerpo), {
      'modo': 'especifico',
      'chofer_id': 2,
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'origen_direccion': 'Plaza Independencia',
      'destino_lat': -26.8083,
      'destino_lng': -65.2176,
      'motivo': 'Audiencia',
    });
  });

  test(
    'buscar lugares manda el texto y, si hay, la ubicación para sesgar; lee {nombre, direccion, lat, lng}',
    () async {
      http.responder(
        'GET',
        'lugares',
        200,
        '[{"nombre":"Tribunales","direccion":"Tribunales, 24 de Septiembre 677, Tucumán","lat":-26.83,"lng":-65.2}]',
      );

      final lugares = await api.buscarLugares('tribu', cerca: const Coordenada(-26.8241, -65.2226));
      await api.buscarLugares('tribu');

      expect(lugares.single.nombre, 'Tribunales');
      expect(lugares.single.direccion, 'Tribunales, 24 de Septiembre 677, Tucumán');
      expect(lugares.single.coordenada, const Coordenada(-26.83, -65.2));
      expect(http.pedidos.first.uri.queryParameters, {'q': 'tribu', 'lat': '-26.8241', 'lng': '-65.2226'});
      expect(http.pedidos.last.uri.queryParameters, {'q': 'tribu'});
    },
  );

  test('disponibles de reserva manda la franja como query con fecha UTC', () async {
    http.responder('GET', 'reservas/disponibles', 200, p.reservasDisponibles);

    final d = await api.disponiblesReserva(
      FranjaReserva(
        programadoPara: DateTime.utc(2026, 10, 2, 13),
        origen: const Coordenada(-26.8241, -65.2226),
        destino: const Coordenada(-26.8083, -65.2176),
      ),
    );

    expect(d.choferes.single.nombre, 'Carlos Gómez');
    expect(http.pedidos.single.uri.queryParameters, {
      'programado_para': '2026-10-02T13:00:00.000Z',
      'origen_lat': '-26.8241',
      'origen_lng': '-65.2226',
      'destino_lat': '-26.8083',
      'destino_lng': '-65.2176',
    });
  });

  test('crear una reserva y registrar el token push (204)', () async {
    http.responder('POST', 'reservas', 201, p.reservaCreada);
    http.responder('POST', 'push/token', 204);

    final r = await api.crearReserva(
      PedidoReserva(
        programadoPara: DateTime.utc(2026, 10, 2, 13),
        modo: ModoViaje.cualquieraDisponible,
        origen: const Lugar(Coordenada(-26.8241, -65.2226)),
        destino: const Lugar(Coordenada(-26.8083, -65.2176), direccion: 'Casa de Gobierno'),
      ),
    );
    await api.registrarTokenPush('fcm-abc');

    expect(r.tipo, TipoViaje.reserva);
    expect(jsonDecode(http.pedidos.first.cuerpo), {
      'programado_para': '2026-10-02T13:00:00.000Z',
      'modo': 'cualquiera_disponible',
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'destino_lat': -26.8083,
      'destino_lng': -65.2176,
      'destino_direccion': 'Casa de Gobierno',
    });
    expect(jsonDecode(http.pedidos.last.cuerpo), {'token': 'fcm-abc'});
  });

  test('autorizar un canal privado es un POST de formulario con el token Sanctum', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    api.cliente.token = '1|abc';

    final auth = await api.autorizarCanal(socketId: '1234.5678', canal: 'private-mapa.choferes');

    expect(auth, startsWith('clave:'));
    final pedido = http.pedidos.single;
    expect(pedido.headers['Authorization'], 'Bearer 1|abc');
    expect(pedido.headers['content-type'], 'application/x-www-form-urlencoded');
    expect(Uri.splitQueryString(pedido.cuerpo), {'socket_id': '1234.5678', 'channel_name': 'private-mapa.choferes'});
  });
}
