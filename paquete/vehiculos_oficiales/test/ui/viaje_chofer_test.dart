import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/mapa_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

/// El viaje del fixture (chofer 2 = el usuario) con otros datos.
String viajeJson({
  String estado = 'aceptado',
  bool obligatorio = false,
  String tipo = 'inmediato',
  String? telefonoSolicitante = '3815551111',
}) {
  final j = p.json(p.viajeAceptado)
    ..['estado'] = estado
    ..['obligatorio'] = obligatorio
    ..['tipo'] = tipo;
  (j['solicitante'] as Map<String, dynamic>)['telefono'] = telefonoSolicitante;
  return jsonEncode(j);
}

String actualCon(String viaje) => '{"viaje":$viaje,"oferta":null}';

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;
  late List<Uri> lanzadas;
  late bool abreGoogleMaps;

  setUp(() {
    tr = TiempoRealFalso();
    lanzadas = [];
    abreGoogleMaps = true;
  });

  /// [preparar] agrega respuestas antes de abrir.
  Future<void> abrir(WidgetTester tester, String viaje, [void Function(EntornoPrueba e)? preparar]) async {
    e = entornoChofer(viajeActual: actualCon(viaje));
    preparar?.call(e);
    await montarChofer(
      tester,
      e,
      tiempoReal: tr,
      extra: [
        lanzadorUrlProvider.overrideWithValue((uri) async {
          lanzadas.add(uri);
          return uri.scheme != 'google.navigation' || abreGoogleMaps;
        }),
      ],
    );
  }

  Map<String, dynamic> cuerpo(String ruta) =>
      jsonDecode(e.http.pedidos.lastWhere((r) => r.uri.path == '/api/$ruta').cuerpo) as Map<String, dynamic>;

  testWidgets('al abrir con un viaje va a su pantalla y recorre los pasos hasta "Viaje finalizado"', (tester) async {
    await abrir(tester, viajeJson(), (e) {
      for (final estado in ['en_camino', 'llego', 'en_curso', 'finalizado']) {
        e.http.responder('POST', 'viajes/1/estado', 200, viajeJson(estado: estado));
      }
    });
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Ana Pérez'), findsOneWidget);

    final pasos = {
      'Voy en camino': 'en_camino',
      'Llegué': 'llego',
      'Iniciar viaje': 'en_curso',
      'Finalizar': 'finalizado',
    };
    for (final MapEntry(key: boton, value: estado) in pasos.entries) {
      await tester.tap(find.widgetWithText(FilledButton, boton));
      await esperar(tester);
      expect(cuerpo('viajes/1/estado'), {'estado': estado});
    }

    expect(find.text('Viaje finalizado'), findsWidgets);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('navegar: al origen antes de en_curso y al destino después; Waze; y la web si falta la app', (
    tester,
  ) async {
    await abrir(tester, viajeJson(estado: 'en_camino'));

    await tester.tap(find.text('Navegar'));
    await esperar(tester);
    await tester.tap(find.text('Google Maps'));
    await esperar(tester);
    expect(lanzadas.last.toString(), 'google.navigation:q=-26.8241,-65.2226');

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_curso')));
    await esperar(tester);
    await tester.tap(find.text('Navegar'));
    await esperar(tester);
    await tester.tap(find.text('Waze'));
    await esperar(tester);
    expect(lanzadas.last.toString(), 'https://waze.com/ul?ll=-26.8083,-65.2176&navigate=yes');

    abreGoogleMaps = false;
    await tester.tap(find.text('Navegar'));
    await esperar(tester);
    await tester.tap(find.text('Google Maps'));
    await esperar(tester);
    expect(lanzadas.sublist(lanzadas.length - 2).map((u) => u.toString()), [
      'google.navigation:q=-26.8083,-65.2176',
      'https://www.google.com/maps/dir/?api=1&destination=-26.8083,-65.2176',
    ]);
  });

  testWidgets('llamar al solicitante; sin teléfono no hay botón', (tester) async {
    await abrir(tester, viajeJson());

    await tester.tap(find.text('Llamar'));
    await esperar(tester);
    expect(lanzadas.single.toString(), 'tel:3815551111');

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(viajeJson(telefonoSolicitante: null)));
    await esperar(tester);
    expect(find.text('Llamar'), findsNothing);
  });

  testWidgets('cancelar pide un motivo, no deja confirmar vacío, lo manda y vuelve al mapa', (tester) async {
    await abrir(
      tester,
      viajeJson(),
      (e) => e.http.responder('POST', 'viajes/1/cancelar', 200, c.viajeCanceladoPorChofer),
    );
    await tester.tap(find.text('Cancelar viaje'));
    await esperar(tester);

    final confirmar = find.widgetWithText(FilledButton, 'Cancelar viaje');
    expect(tester.widget<FilledButton>(confirmar).onPressed, isNull);
    await tester.enterText(find.byType(TextField), '  Se rompió el auto  ');
    await tester.pump();
    await tester.tap(confirmar);
    await esperar(tester);

    expect(cuerpo('viajes/1/cancelar'), {'motivo': 'Se rompió el auto'});
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(find.text('Cancelaste el viaje.'), findsOneWidget);
  });

  testWidgets('un obligatorio no se puede cancelar', (tester) async {
    await abrir(tester, viajeJson(obligatorio: true));

    expect(find.text('Viaje obligatorio'), findsOneWidget);
    expect(find.text('Cancelar viaje'), findsNothing);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('en curso tampoco; una reserva que ya salió tampoco', (tester) async {
    await abrir(tester, viajeJson(estado: 'en_curso'));
    expect(find.text('Cancelar viaje'), findsNothing);

    // Otro viaje (id 5): una reserva que arrancó.
    tr.emitir(
      'chofer.2',
      Eventos.viajeActualizado,
      p.json(viajeJson(estado: 'en_camino', tipo: 'reserva'))..['id'] = 5,
    );
    await esperar(tester);
    expect(find.text('Llegué'), findsOneWidget);
    expect(find.text('Cancelar viaje'), findsNothing);
  });

  testWidgets('el 422 de una reserva que todavía no puede empezar se muestra', (tester) async {
    await abrir(
      tester,
      viajeJson(tipo: 'reserva'),
      (e) => e.http.responder('POST', 'viajes/1/estado', 422, c.reservaAntesDeTiempo),
    );
    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(find.text('Podés salir hacia esta reserva a partir de las 11:15.'), findsOneWidget);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('cancelado por el solicitante o reasignado: lo avisa y vuelve al mapa', (tester) async {
    await abrir(tester, viajeJson());

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(viajeJson(estado: 'cancelado')));
    await esperar(tester);
    expect(find.text('El viaje fue cancelado'), findsOneWidget);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('reasignado por un administrador', (tester) async {
    await abrir(tester, viajeJson());

    final reasignado = p.json(viajeJson())..['chofer'] = {'id': 9, 'nombre': 'Otro', 'telefono': null};
    tr.emitir('chofer.2', Eventos.viajeActualizado, reasignado);
    await esperar(tester);

    expect(find.text('El viaje se reasignó a otro chofer'), findsOneWidget);
  });

  testWidgets('"atrás" vuelve al mapa, que ofrece volver al viaje', (tester) async {
    await abrir(tester, viajeJson());

    await tester.binding.handlePopRoute();
    await esperar(tester);
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(find.text('Tenés un viaje en curso.'), findsOneWidget);

    await tester.tap(find.text('Ver'));
    await esperar(tester);
    expect(find.byType(ViajeChofer), findsOneWidget);
  });
}
