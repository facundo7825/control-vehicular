import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/iniciar_turno.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/mapa_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/mis_viajes_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/detalle_viaje.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

/// Un viaje cancelado ayer por quien diga [por] (sin recorrido).
Map<String, dynamic> _cancelado(String por) => p.json(p.viajeFinalizado)
  ..['estado'] = 'cancelado'
  ..['llego_en'] = null
  ..['iniciado_en'] = null
  ..['finalizado_en'] = null
  ..['metros_recorridos'] = null
  ..['cancelado_en'] = escribirFecha(DateTime.now().subtract(const Duration(days: 1)))
  ..['cancelado_por'] = por
  ..['motivo_cancelacion'] = 'Ya no lo necesito';

String _respuesta(List<Map<String, dynamic>> viajes, {int hoy = 0, int metros = 0, String? enTurnoDesde}) =>
    jsonEncode({
      'hoy': {'viajes': hoy, 'metros': metros, 'en_turno_desde': enTurnoDesde},
      'viajes': viajes,
    });

void main() {
  late EntornoPrueba e;

  setUp(() {
    e = entornoChofer();
    e.http.responder('GET', 'viajes/1/recorrido', 200, p.recorrido);
  });

  int consultas() => pedidosHechos(e).where((r) => r == 'GET chofer/viajes').length;

  /// Abre "Mis viajes" desde el mapa (con turno) y espera la lista.
  Future<void> abrir(WidgetTester tester) async {
    await montarChofer(tester, e);
    expect(find.byType(MapaChofer), findsOneWidget);
    await tester.tap(find.byTooltip('Mis viajes'));
    await esperar(tester);
    expect(find.byType(MisViajesChofer), findsOneWidget);
  }

  /// La fila [etiqueta] del detalle muestra [valor].
  Finder fila(String etiqueta, String valor) => find.descendant(
    of: find.ancestor(of: find.text(etiqueta), matching: find.byType(Row)).first,
    matching: find.text(valor),
  );

  testWidgets('con turno: el resumen de hoy y la lista con fecha, destino, solicitante y estado', (tester) async {
    e.http.responder('GET', 'chofer/viajes', 200, c.viajesChofer);
    await abrir(tester);

    expect(find.text('Hoy: 2 viajes · 5,3 km'), findsOneWidget);
    final desde = DateFormat('HH:mm').format(DateTime.utc(2026, 10, 7, 11, 30).toLocal());
    expect(find.text('En turno desde $desde'), findsOneWidget);
    expect(find.text('${formatearFechaHora(DateTime.utc(2026, 10, 1, 12, 20))} · Tribunales'), findsOneWidget);
    expect(find.text('Ana Pérez · Viaje finalizado'), findsOneWidget);
  });

  testWidgets('sin turno se llega desde "Iniciar turno"; un viaje en singular y 0 km', (tester) async {
    e = entornoChofer(conTurno: false);
    e.http
      ..responder('GET', 'vehiculos/disponibles', 200, c.vehiculosDisponibles)
      ..responder('GET', 'chofer/viajes', 200, _respuesta([_cancelado('solicitante')], hoy: 1));
    await montarChofer(tester, e);
    expect(find.byType(IniciarTurno), findsOneWidget);

    await tester.tap(find.byTooltip('Mis viajes'));
    await esperar(tester);

    expect(find.text('Hoy: 1 viaje · 0 km'), findsOneWidget);
    expect(find.textContaining('En turno desde'), findsNothing);
    expect(find.text('Ana Pérez · Viaje cancelado'), findsOneWidget);
  });

  testWidgets('sin viajes: el resumen en cero y "Todavía no hiciste viajes."', (tester) async {
    e.http.responder('GET', 'chofer/viajes', 200, c.viajesChoferVacio);
    await abrir(tester);

    expect(find.text('Hoy: 0 viajes · 0 km'), findsOneWidget);
    expect(find.text('Todavía no hiciste viajes.'), findsOneWidget);
  });

  testWidgets('un error se muestra con "Reintentar", que vuelve a pedir', (tester) async {
    e.http
      ..responder('GET', 'chofer/viajes', 500, '{"message":"Server Error"}')
      ..responder('GET', 'chofer/viajes', 200, c.viajesChofer);
    await abrir(tester);

    expect(find.text('Reintentar'), findsOneWidget);
    await tester.tap(find.text('Reintentar'));
    await esperar(tester);

    expect(find.text('Hoy: 2 viajes · 5,3 km'), findsOneWidget);
  });

  testWidgets('tirar hacia abajo vuelve a pedir la lista', (tester) async {
    e.http
      ..responder('GET', 'chofer/viajes', 200, c.viajesChoferVacio)
      ..responder('GET', 'chofer/viajes', 200, c.viajesChofer);
    await abrir(tester);
    final antes = consultas();

    await tester.fling(find.text('Todavía no hiciste viajes.'), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();

    expect(consultas(), antes + 1);
    expect(find.text('Hoy: 2 viajes · 5,3 km'), findsOneWidget);
  });

  testWidgets('tocar un viaje abre el detalle con el solicitante, y "atrás" vuelve a la lista', (tester) async {
    e.http
      ..responder('GET', 'chofer/viajes', 200, c.viajesChofer)
      ..responder('GET', 'viajes/1', 200, p.viajeFinalizado);
    await abrir(tester);

    await tester.tap(find.textContaining('Tribunales'));
    await esperar(tester);

    expect(find.byType(DetalleViaje), findsOneWidget);
    expect(pedidosHechos(e), containsAll(['GET viajes/1', 'GET viajes/1/recorrido']));
    expect(fila('Solicitante', 'Ana Pérez'), findsOneWidget);
    expect(find.text('Carlos Gómez'), findsNothing);
    expect(find.text('Chofer asignado'), findsNothing);
    expect(fila('Aceptado', formatearFechaHora(DateTime.utc(2026, 10, 1, 12))), findsOneWidget);
    expect(fila('Distancia', '5,3 km'), findsOneWidget);
    expect(find.text(formatearFechaHora(DateTime.utc(2026, 10, 1, 12, 20))), findsWidgets);

    await tester.binding.handlePopRoute();
    await esperar(tester);
    expect(find.byType(MisViajesChofer), findsOneWidget);
  });

  for (final (por, texto) in [
    ('solicitante', 'Lo canceló el solicitante'),
    ('admin', 'Lo canceló la administración'),
  ]) {
    testWidgets('un viaje cancelado por $por dice "$texto"', (tester) async {
      final viaje = _cancelado(por);
      e.http
        ..responder('GET', 'chofer/viajes', 200, _respuesta([viaje]))
        ..responder('GET', 'viajes/1', 200, jsonEncode(viaje))
        ..responder('GET', 'viajes/1/recorrido', 200, p.recorridoNoDisponible);
      await abrir(tester);

      await tester.tap(find.textContaining('Tribunales'));
      await esperar(tester);

      expect(find.text(texto), findsOneWidget);
      expect(find.text('Lo cancelaste vos'), findsNothing);
      expect(find.text('Motivo: Ya no lo necesito'), findsOneWidget);
    });
  }

  testWidgets('un id inválido en la ruta del chofer muestra "Viaje no encontrado" sin romper', (tester) async {
    await montarChofer(tester, e);

    GoRouter.of(tester.element(find.byType(MapaChofer))).go('/chofer/mis-viajes/viaje/abc');
    await esperar(tester);

    expect(find.text('Viaje no encontrado'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox()); // se cierra el módulo
  });
}
