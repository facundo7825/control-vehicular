import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';

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
    expect(find.textContaining('km del origen'), findsOneWidget);
    expect(find.byKey(const Key('marcador-chofer')), findsOneWidget);

    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_curso')));
    await tester.pumpAndSettle();
    expect(find.text('En viaje'), findsWidgets);
    expect(find.text('Cancelar viaje'), findsNothing); // spec 5.6: no se cancela en curso
  });

  testWidgets('con el socket caído avisa y se mantiene al día consultando cada 10 s', (tester) async {
    tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
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
    e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));

    await abrir(tester);
    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'finalizado')));
    await tester.pumpAndSettle();

    expect(find.text('Viaje finalizado'), findsWidgets);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });
}
