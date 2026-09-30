import 'dart:async';
import 'dart:convert';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_pusher.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/adaptador_falso.dart';
import 'soporte/entorno_prueba.dart';

/// Servidor Pusher mínimo en memoria: responde connection_established y confirma suscripciones.
class ConexionFalsa implements PusherChannelsConnection {
  ConexionFalsa(this.servidor);

  final ServidorFalso servidor;
  PusherChannelsConnectionOnEventCallback? _alEvento;
  PusherChannelsConnectionOnDoneCallback? _alCerrar;

  @override
  void connect({
    required PusherChannelsConnectionOnDoneCallback onDoneCallback,
    required PusherChannelsConnectionOnErrorCallback onErrorCallback,
    required PusherChannelsConnectionOnEventCallback onEventCallback,
  }) {
    _alEvento = onEventCallback;
    _alCerrar = onDoneCallback;
    servidor.conexiones.add(this);
    scheduleMicrotask(
      () => onEventCallback(
        jsonEncode({
          'event': 'pusher:connection_established',
          'data': jsonEncode({'socket_id': '1234.5678', 'activity_timeout': 120}),
        }),
      ),
    );
  }

  @override
  void sendEvent(String eventEncoded) {
    final e = jsonDecode(eventEncoded) as Map<String, dynamic>;
    servidor.recibidos.add(e);
    if (e['event'] == 'pusher:subscribe') {
      final canal = (e['data'] as Map)['channel'] as String;
      scheduleMicrotask(
        () => _alEvento?.call(
          jsonEncode({'event': 'pusher_internal:subscription_succeeded', 'channel': canal, 'data': '{}'}),
        ),
      );
    }
  }

  void emitir(String canal, String evento, String datos) =>
      _alEvento?.call(jsonEncode({'event': evento, 'channel': canal, 'data': datos}));

  void caer() => _alCerrar?.call();

  @override
  void ping() {}

  @override
  FutureOr<void> close() {}
}

class ServidorFalso {
  final conexiones = <ConexionFalsa>[];
  final recibidos = <Map<String, dynamic>>[];

  ConexionFalsa get actual => conexiones.last;
}

Future<void> vaciar() async {
  for (var i = 0; i < 20; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

void main() {
  late AdaptadorFalso http;
  late ServidorFalso servidor;
  late TiempoRealPusher tr;
  late int avisos401;

  setUp(() {
    http = AdaptadorFalso();
    servidor = ServidorFalso();
    avisos401 = 0;
    final cliente = ClienteApi(baseApi: configPrueba.apiUri, alRecibir401: () => avisos401++, adaptador: http)
      ..token = '1|sanctum';
    tr = TiempoRealPusher(
      config: configPrueba,
      api: ApiVehiculos(cliente),
      conexion: () => ConexionFalsa(servidor),
      esperaReconexion: const Duration(milliseconds: 10),
      esperasReintentoCanal: const [Duration(milliseconds: 10)],
    );
  });

  tearDown(() => tr.cerrar());

  test('se conecta y autoriza el canal privado con el token Sanctum', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    final eventos = <EventoTiempoReal>[];

    tr.conectar();
    tr.canal(Canales.viaje(1)).listen(eventos.add);
    await vaciar();

    expect(tr.estado, EstadoConexion.conectado);
    final auth = http.pedidos.single;
    expect(auth.uri.toString(), 'http://10.0.2.2:8000/api/broadcasting/auth');
    expect(auth.headers['Authorization'], 'Bearer 1|sanctum');
    expect(Uri.splitQueryString(auth.cuerpo), {'socket_id': '1234.5678', 'channel_name': 'private-viaje.1'});
    expect(servidor.recibidos.single, {
      'event': 'pusher:subscribe',
      'data': {'channel': 'private-viaje.1', 'auth': p.json(p.autorizacionCanal)['auth']},
    });

    servidor.actual.emitir('private-viaje.1', Eventos.viajeActualizado, p.viajeAceptado);
    await vaciar();

    expect(eventos.single.canal, 'viaje.1');
    expect(eventos.single.nombre, 'viaje.actualizado');
    expect(eventos.single.datos['estado'], 'aceptado');
  });

  test('un 401 al autorizar el canal avisa la sesión inválida', () async {
    http.responder('POST', 'broadcasting/auth', 401, p.noAutenticado);

    tr.conectar();
    tr.canal(Canales.mapaChoferes).listen((_) {});
    await vaciar();

    expect(avisos401, 1);
    expect(servidor.recibidos.where((e) => e['event'] == 'pusher:subscribe'), isEmpty);
  });

  test('informa la caída de la conexión', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    final estados = <EstadoConexion>[];
    tr.estados.listen(estados.add);

    tr.conectar();
    tr.canal(Canales.mapaChoferes).listen((_) {});
    await vaciar();
    servidor.actual.caer();
    await vaciar();

    expect(
      estados,
      containsAllInOrder([EstadoConexion.conectando, EstadoConexion.conectado, EstadoConexion.desconectado]),
    );
  });

  test('tras una caída reconecta y vuelve a suscribirse con autorización nueva', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    final eventos = <EventoTiempoReal>[];

    tr.conectar();
    tr.canal(Canales.viaje(1)).listen(eventos.add);
    await vaciar();
    servidor.actual.caer();
    await Future<void>.delayed(const Duration(milliseconds: 100));
    await vaciar();

    expect(servidor.conexiones, hasLength(2));
    expect(tr.estado, EstadoConexion.conectado);
    expect(http.pedidos, hasLength(2));
    expect(servidor.recibidos.where((e) => e['event'] == 'pusher:subscribe'), hasLength(2));

    servidor.actual.emitir('private-viaje.1', Eventos.viajeActualizado, p.viajeAceptado);
    await vaciar();
    expect(eventos, hasLength(1));
  });

  test('al dejar de escuchar el canal se desuscribe', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);

    tr.conectar();
    final s = tr.canal(Canales.chofer(2)).listen((_) {});
    await vaciar();
    await s.cancel();
    await vaciar();

    expect(servidor.recibidos.last, {
      'event': 'pusher:unsubscribe',
      'data': {'channel': 'private-chofer.2'},
    });
  });

  test('si falla la autorización con el socket arriba, informa desconectado y reintenta hasta suscribirse', () async {
    http.responder('POST', 'broadcasting/auth', 500, '{"message":"Server Error"}');
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    final estados = <EstadoConexion>[];
    tr.estados.listen(estados.add);
    final eventos = <EventoTiempoReal>[];
    Iterable<Map<String, dynamic>> suscripciones() => servidor.recibidos.where((e) => e['event'] == 'pusher:subscribe');

    tr.conectar();
    tr.canal(Canales.viaje(1)).listen(eventos.add);
    await vaciar();

    // Sin suscripción no llegan eventos: quien usa el canal tiene que consultar la API (Respaldo).
    expect(http.pedidos, hasLength(1));
    expect(suscripciones(), isEmpty);
    expect(tr.estado, EstadoConexion.desconectado);

    await Future<void>.delayed(const Duration(milliseconds: 100));
    await vaciar();

    expect(http.pedidos, hasLength(2));
    expect(suscripciones(), hasLength(1));
    expect(tr.estado, EstadoConexion.conectado);
    expect(
      estados,
      containsAllInOrder([
        EstadoConexion.conectando,
        EstadoConexion.conectado,
        EstadoConexion.desconectado,
        EstadoConexion.conectado,
      ]),
    );

    servidor.actual.emitir('private-viaje.1', Eventos.viajeActualizado, p.viajeAceptado);
    await vaciar();
    expect(eventos, hasLength(1));
  });

  test('deja de reintentar la autorización cuando nadie escucha el canal', () async {
    http.responder('POST', 'broadcasting/auth', 500, '{"message":"Server Error"}');

    tr.conectar();
    final s = tr.canal(Canales.viaje(1)).listen((_) {});
    await vaciar();
    await s.cancel();
    await Future<void>.delayed(const Duration(milliseconds: 100));
    await vaciar();

    expect(http.pedidos, hasLength(1));
    expect(tr.estado, EstadoConexion.conectado);
  });
}
