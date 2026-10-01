import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

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
}
