import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/dobles_chofer.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

void main() {
  late UbicadorFalso gps;

  setUp(() => gps = UbicadorFalso());

  testWidgets('sin posición el mapa no se mueve y "Mi ubicación" está deshabilitado', (tester) async {
    await montarChofer(tester, entornoChofer(), ubicador: gps);

    expect(enfoqueDelMapa(tester), isNull);
    final boton = tester.widget<FloatingActionButton>(find.widgetWithIcon(FloatingActionButton, Icons.my_location));
    expect(boton.onPressed, isNull);
  });

  testWidgets('el primer punto del GPS centra el mapa en la posición del chofer', (tester) async {
    await montarChofer(tester, entornoChofer(), ubicador: gps);

    gps.emitir(punto(0));
    await tester.pump();

    expect(enfoqueDelMapa(tester)!.puntos, [const Coordenada(-26.83, -65.2)]);
  });

  testWidgets('los puntos siguientes no mueven la cámara', (tester) async {
    await montarChofer(tester, entornoChofer(), ubicador: gps);
    gps.emitir(punto(0));
    await tester.pump();
    final primero = enfoqueDelMapa(tester);

    gps.emitir(punto(5, lat: -26.9));
    await tester.pump();

    expect(find.byKey(const Key('marcador-yo')), findsOneWidget);
    expect(enfoqueDelMapa(tester), primero);
  });

  testWidgets('"Mi ubicación" vuelve a centrar en el último punto, con otra versión', (tester) async {
    await montarChofer(tester, entornoChofer(), ubicador: gps);
    gps.emitir(punto(0));
    await tester.pump();
    gps.emitir(punto(5, lat: -26.9));
    await tester.pump();
    final antes = enfoqueDelMapa(tester)!;

    await tester.tap(find.byTooltip('Mi ubicación'));
    await tester.pump();

    final despues = enfoqueDelMapa(tester)!;
    expect(despues.puntos, [const Coordenada(-26.9, -65.2)]);
    expect(despues.version, antes.version + 1);
  });

  group('cambiar vehículo', () {
    testWidgets('tocar el vehículo abre la hoja, elige otro y el mapa muestra el nuevo', (tester) async {
      final e = entornoChofer()
        ..http.responder('GET', 'vehiculos/disponibles', 200, c.otroVehiculoDisponible)
        ..http.responder('POST', 'turnos/actual/vehiculo', 200, c.turnoConOtroVehiculo);
      await montarChofer(tester, e, ubicador: gps);

      await tester.tap(find.byTooltip('Cambiar vehículo'));
      await esperar(tester);
      expect(find.text('Cambiar vehículo'), findsOneWidget);
      await tester.tap(find.text('Ford Ranger (AC456EF)'));
      await esperar(tester);

      final pedido = e.http.pedidos.singleWhere((r) => r.uri.path == '/api/turnos/actual/vehiculo');
      expect(jsonDecode(pedido.cuerpo), {'vehiculo_id': 2});
      expect(find.text('Ford Ranger (AC456EF) · Gris'), findsOneWidget);
      expect(find.text('Toyota Corolla (AB123CD) · Blanco'), findsNothing);
      expect(gps.siguiendo, isTrue);
    });

    testWidgets('un 422 muestra el mensaje y el turno sigue con su vehículo', (tester) async {
      final e = entornoChofer()
        ..http.responder('GET', 'vehiculos/disponibles', 200, c.otroVehiculoDisponible)
        ..http.responder('POST', 'turnos/actual/vehiculo', 422, c.vehiculoEnUso);
      await montarChofer(tester, e, ubicador: gps);

      await tester.tap(find.byTooltip('Cambiar vehículo'));
      await esperar(tester);
      await tester.tap(find.text('Ford Ranger (AC456EF)'));
      await esperar(tester);

      expect(find.text('El vehículo está en uso por otro chofer.'), findsOneWidget);
      expect(find.text('Toyota Corolla (AB123CD) · Blanco'), findsOneWidget);
    });

    testWidgets('con un viaje en curso no se puede cambiar y se explica por qué', (tester) async {
      final e = entornoChofer(viajeActual: '{"viaje":${p.viajeAceptado},"oferta":null}');
      await montarChofer(tester, e, ubicador: gps);
      await tester.binding.handlePopRoute(); // del viaje vuelve al mapa
      await tester.pumpAndSettle();
      expect(find.text('Tenés un viaje en curso.'), findsOneWidget);

      await tester.tap(find.byTooltip('Cambiar vehículo'));
      await esperar(tester);

      expect(find.text('No podés cambiar el vehículo durante un viaje.'), findsOneWidget);
      expect(pedidosHechos(e), isNot(contains('GET vehiculos/disponibles')));
    });
  });

  testWidgets('un turno abierto por fichaje lo dice, y también si se cierra al terminar el viaje', (tester) async {
    final e = entornoChofer(turno: c.turnoPorFichaje);
    await montarChofer(tester, e, ubicador: gps);

    expect(find.textContaining('Turno por fichaje'), findsOneWidget);
    expect(find.textContaining('se cierra al terminar el viaje'), findsOneWidget);
  });
}
