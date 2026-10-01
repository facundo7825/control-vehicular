import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/pantalla_viaje.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

String actualCon(String viajeJson) => '{"viaje":$viajeJson,"oferta":null}';

String viajeJson({String estado = 'aceptado', String modo = 'mas_cercano', bool conChofer = true}) => jsonEncode(
  p.json(conChofer ? p.viajeAceptado : p.viajeOfrecido)
    ..['estado'] = estado
    ..['modo'] = modo,
);

String etaJson({String hacia = 'origen', int? segundos = 240, int? metros = 1850}) => jsonEncode({
  'hacia': hacia,
  'segundos': segundos,
  'metros': metros,
  'calculado_en': '2026-10-01T12:00:00+00:00',
  'ubicacion_actualizada_en': null,
});

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;
  late List<Uri> lanzadas;

  setUp(() {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    tr = TiempoRealFalso();
    lanzadas = [];
  });

  Future<void> abrir(WidgetTester tester) => montarModulo(
    tester,
    e,
    tiempoReal: tr,
    extra: [
      lanzadorUrlProvider.overrideWithValue((uri) async {
        lanzadas.add(uri);
        return true;
      }),
    ],
  );

  testWidgets('buscando chofer: se puede cancelar el pedido', (tester) async {
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualSolicitante);
    e.http.responder('POST', 'viajes/1/cancelar', 200, viajeJson(estado: 'cancelado', conChofer: false));

    await abrir(tester);
    expect(find.text('Buscando el chofer más cercano…'), findsOneWidget);

    await tester.tap(find.text('Cancelar pedido'));
    await esperar(tester);
    await tester.tap(find.text('Sí, cancelar'));
    await esperar(tester);

    expect(find.text('Viaje cancelado'), findsWidgets);
    expect(e.http.pedidos.last.uri.path, '/api/viajes/1/cancelar');

    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  testWidgets('viaje activo: chofer, vehículo, llamar y cambios de estado en vivo', (tester) async {
    e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));
    e.http.responder('GET', 'viajes/1/eta', 200, etaJson());

    await abrir(tester);
    expect(find.text('Chofer asignado'), findsWidgets);
    expect(find.text('Carlos Gómez'), findsOneWidget);
    expect(find.text('Toyota Corolla (AB123CD) · Blanco'), findsOneWidget);

    await tester.tap(find.text('Llamar'));
    expect(lanzadas.single.toString(), 'tel:3815550000');

    tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_camino')));
    await tester.pumpAndSettle();
    expect(find.text('El chofer va en camino'), findsWidgets);
    expect(find.text('Llega en ~4 min'), findsOneWidget);
    expect(find.byKey(const Key('marcador-chofer')), findsOneWidget);

    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_curso')));
    await tester.pumpAndSettle();
    expect(find.text('En viaje'), findsWidgets);
    expect(find.text('Cancelar viaje'), findsNothing); // spec 5.6: no se cancela en curso
  });

  testWidgets('llamar marca solo dígitos y un + inicial', (tester) async {
    final conFormato = p.json(p.viajeAceptado)
      ..['chofer'] = {'id': 2, 'nombre': 'Carlos Gómez', 'telefono': '+54 (381) 555-0000'};
    e.http.responder('GET', 'viajes/actual', 200, actualCon(jsonEncode(conFormato)));
    e.http.responder('GET', 'viajes/1/eta', 200, etaJson());

    await abrir(tester);
    await tester.tap(find.text('Llamar'));

    expect(lanzadas.single.toString(), 'tel:+543815550000');
  });

  testWidgets('con el socket caído avisa y se mantiene al día consultando cada 10 s', (tester) async {
    tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
    e.http.responder('GET', 'viajes/1/eta', 200, etaJson());
    e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));
    e.http.responder('GET', 'viajes/actual', 200, actualCon(viajeJson(estado: 'llego')));
    e.http.responder('GET', 'choferes', 200, p.choferes);

    await abrir(tester);
    expect(find.text('Sin conexión en tiempo real. Actualizando cada 10 s.'), findsOneWidget);
    expect(find.text('Chofer asignado'), findsWidgets);

    await tester.pump(const Duration(seconds: 10));
    await tester.pumpAndSettle();
    expect(find.text('El chofer llegó'), findsWidgets);
    expect(find.byKey(const Key('marcador-chofer')), findsOneWidget); // posición tomada de GET /choferes

    tr.cambiar(EstadoConexion.conectado);
    await tester.pumpAndSettle();
    expect(find.text('Sin conexión en tiempo real. Actualizando cada 10 s.'), findsNothing);
  });

  testWidgets('el chofer elegido no aceptó: pedir el más cercano crea otro viaje', (tester) async {
    e.http.responder(
      'GET',
      'viajes/actual',
      200,
      actualCon(viajeJson(estado: 'sin_chofer', modo: 'especifico', conChofer: false)),
    );
    e.http.responder('POST', 'viajes', 201, p.viajeOfrecido);

    await abrir(tester);
    expect(find.text('El chofer no aceptó el viaje'), findsOneWidget);
    expect(find.text('Elegir otro'), findsOneWidget);

    await tester.tap(find.text('Pedir el más cercano'));
    await esperar(tester);

    final cuerpo = jsonDecode(e.http.pedidos.last.cuerpo) as Map<String, dynamic>;
    expect(cuerpo['modo'], 'mas_cercano');
    expect(cuerpo['destino_direccion'], 'Tribunales');
    expect(find.text('Buscando el chofer más cercano…'), findsOneWidget);
  });

  testWidgets('viaje finalizado: volver al mapa', (tester) async {
    e.http.responder('GET', 'viajes/1/eta', 200, etaJson());
    e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));

    await abrir(tester);
    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'finalizado')));
    await tester.pumpAndSettle();

    expect(find.text('Viaje finalizado'), findsWidgets);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  group('línea de llegada estimada', () {
    Future<void> conEta(WidgetTester tester, {required String estado, required String eta}) async {
      e.http.responder('GET', 'viajes/actual', 200, actualCon(viajeJson(estado: estado)));
      e.http.responder('GET', 'viajes/1/eta', 200, eta);
      await abrir(tester);
    }

    testWidgets('hacia el origen con tiempo: minutos redondeados hacia arriba', (tester) async {
      await conEta(tester, estado: 'en_camino', eta: etaJson(segundos: 241));
      expect(find.text('Llega en ~5 min'), findsOneWidget);
    });

    testWidgets('menos de un minuto se muestra como 1 min', (tester) async {
      await conEta(tester, estado: 'en_camino', eta: etaJson(segundos: 20));
      expect(find.text('Llega en ~1 min'), findsOneWidget);
    });

    testWidgets('con segundos en 0 no agrega nada', (tester) async {
      await conEta(tester, estado: 'en_camino', eta: etaJson(segundos: 0, metros: 0));
      expect(find.textContaining('Llega en'), findsNothing);
      expect(find.textContaining('no disponible'), findsNothing);
      expect(find.textContaining('del origen'), findsNothing);
    });

    testWidgets('hacia el destino con tiempo', (tester) async {
      await conEta(
        tester,
        estado: 'en_curso',
        eta: etaJson(hacia: 'destino', segundos: 600),
      );
      expect(find.text('Llegada a destino en ~10 min'), findsOneWidget);
    });

    testWidgets('sin tiempo pero con distancia: línea recta', (tester) async {
      await conEta(tester, estado: 'en_camino', eta: etaJson(segundos: null, metros: 1850));
      expect(find.text('A 1,9 km del origen'), findsOneWidget);
    });

    testWidgets('sin tiempo ni distancia hacia el destino: del destino', (tester) async {
      await conEta(
        tester,
        estado: 'en_curso',
        eta: etaJson(hacia: 'destino', segundos: null, metros: 500),
      );
      expect(find.text('A 500 m del destino'), findsOneWidget);
    });

    testWidgets('sin ubicación del chofer', (tester) async {
      await conEta(tester, estado: 'en_camino', eta: etaJson(segundos: null, metros: null));
      expect(find.text('Ubicación del chofer no disponible'), findsOneWidget);
    });

    testWidgets('si la ETA falla (500) no muestra la línea', (tester) async {
      e.http.responder('GET', 'viajes/actual', 200, actualCon(viajeJson(estado: 'en_camino')));
      e.http.responder('GET', 'viajes/1/eta', 500, '{"message":"Server Error"}');
      await abrir(tester);
      expect(find.textContaining('Llega en'), findsNothing);
      expect(find.textContaining('no disponible'), findsNothing);
      expect(find.text('El chofer va en camino'), findsWidgets);
    });

    testWidgets('mientras la ETA no llega no muestra la línea, y al llegar sí', (tester) async {
      e.http.responder('GET', 'viajes/actual', 200, actualCon(viajeJson(estado: 'en_camino')));
      final eta = e.http.demorar('GET', 'viajes/1/eta');
      await abrir(tester);
      expect(find.text('El chofer va en camino'), findsWidgets);
      expect(find.textContaining('Llega en'), findsNothing);
      expect(find.textContaining('no disponible'), findsNothing);

      eta.complete((200, etaJson(segundos: 241)));
      await esperar(tester);
      expect(find.text('Llega en ~5 min'), findsOneWidget);
    });
  });

  group('recorrido', () {
    List<String> origenesDeRuta() => [
      for (final r in e.http.pedidos)
        if (r.uri.path == '/api/ruta') '${r.uri.queryParameters['origen_lat']},${r.uri.queryParameters['origen_lng']}',
    ];

    String ubicacion(double lat, double lng) => jsonEncode(
      p.json(p.eventoUbicacion)
        ..['lat'] = lat
        ..['lng'] = lng,
    );

    testWidgets('antes de "En curso" se ve el recorrido pedido', (tester) async {
      e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));
      e.http.responder('GET', 'viajes/1/eta', 200, etaJson());
      e.http.responder('GET', 'ruta', 200, p.ruta);

      await abrir(tester);
      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
      await esperar(tester);

      expect(lineasDelMapa(tester, en: find.byType(PantallaViaje)).single.puntos, hasLength(3));
      final pedido = e.http.pedidos.lastWhere((r) => r.uri.path == '/api/ruta').uri.queryParameters;
      expect(pedido, {
        'origen_lat': '-26.8241',
        'origen_lng': '-65.2226',
        'destino_lat': '-26.8083',
        'destino_lng': '-65.2176',
      });
      expect(origenesDeRuta(), hasLength(1), reason: 'la posición del chofer no cambia el recorrido pedido');
    });

    testWidgets('en curso: desde el chofer al destino, recalculado como mucho cada 30 s', (tester) async {
      e.http.responder('GET', 'viajes/actual', 200, actualCon(viajeJson(estado: 'en_curso')));
      e.http.responder('GET', 'viajes/1/eta', 200, etaJson(hacia: 'destino'));
      e.http.responder('GET', 'ruta', 200, p.ruta);

      await abrir(tester);
      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(ubicacion(-26.8301, -65.2001)));
      await esperar(tester);
      expect(origenesDeRuta().last, '-26.8301,-65.2001');
      final pedidos = origenesDeRuta().length;
      final destino = e.http.pedidos.lastWhere((r) => r.uri.path == '/api/ruta').uri.queryParameters;
      expect([destino['destino_lat'], destino['destino_lng']], ['-26.8083', '-65.2176']);
      expect(lineasDelMapa(tester, en: find.byType(PantallaViaje)), hasLength(1));

      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(ubicacion(-26.8250, -65.2050)));
      await esperar(tester);
      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(ubicacion(-26.8200, -65.2100)));
      await esperar(tester);
      expect(origenesDeRuta(), hasLength(pedidos), reason: 'no antes de 30 s');
      expect(lineasDelMapa(tester, en: find.byType(PantallaViaje)), hasLength(1));

      final nueva = e.http.demorar('GET', 'ruta');
      await tester.pump(const Duration(seconds: 30));
      await esperar(tester);
      expect(origenesDeRuta().sublist(pedidos), ['-26.82,-65.21'], reason: 'con la última posición');
      expect(
        lineasDelMapa(tester, en: find.byType(PantallaViaje)),
        hasLength(1),
        reason: 'mientras llega la nueva sigue la anterior',
      );

      nueva.complete((200, p.ruta));
      await esperar(tester);
      expect(lineasDelMapa(tester, en: find.byType(PantallaViaje)), hasLength(1));
    });

    testWidgets('sin recorrido el viaje se ve igual, sin línea', (tester) async {
      e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));
      e.http.responder('GET', 'viajes/1/eta', 200, etaJson());
      e.http.responder('GET', 'ruta', 500, '{"message":"Server Error"}');

      await abrir(tester);

      expect(origenesDeRuta(), hasLength(1));
      expect(lineasDelMapa(tester, en: find.byType(PantallaViaje)), isEmpty);
      expect(find.text('Carlos Gómez'), findsOneWidget);
    });
  });
}
