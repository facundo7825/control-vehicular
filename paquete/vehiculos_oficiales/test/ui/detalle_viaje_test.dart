import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/detalle_viaje.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/mis_viajes.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

const _origen = Coordenada(-26.8241, -65.2226);
const _destino = Coordenada(-26.8083, -65.2176);

/// El mapa del detalle (debajo queda el de la pantalla de inicio).
final _detalle = find.byType(DetalleViaje);

Map<String, dynamic> _finalizado() => p.json(p.viajeFinalizado);

/// Un viaje cancelado hace [hace] (sin recorrido), por quien diga [por].
Map<String, dynamic> _cancelado({String? por, String? motivo, Duration hace = const Duration(days: 1)}) => _finalizado()
  ..['estado'] = 'cancelado'
  ..['llego_en'] = null
  ..['iniciado_en'] = null
  ..['finalizado_en'] = null
  ..['metros_recorridos'] = null
  ..['cancelado_en'] = escribirFecha(DateTime.now().subtract(hace))
  ..['cancelado_por'] = por
  ..['motivo_cancelacion'] = motivo;

String _misViajes(List<Map<String, dynamic>> historial) => jsonEncode({'proximas': [], 'historial': historial});

void main() {
  late EntornoPrueba e;

  setUp(() {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);
    e.http.responder('GET', 'choferes', 200, p.choferes);
  });

  /// Abre "Mis viajes" con [viaje] en el historial y toca ese viaje. El detalle responde [detalle]
  /// (por defecto, el mismo viaje) y el recorrido [recorrido].
  Future<void> abrirDetalle(
    WidgetTester tester,
    Map<String, dynamic> viaje, {
    String? detalle,
    String recorrido = p.recorrido,
    int estadoRecorrido = 200,
  }) async {
    e.http.responder('GET', 'viajes', 200, _misViajes([viaje]));
    e.http.responder('GET', 'viajes/${viaje['id']}', 200, detalle ?? jsonEncode(viaje));
    e.http.responder('GET', 'viajes/${viaje['id']}/recorrido', estadoRecorrido, recorrido);
    await montarModulo(tester, e);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Tribunales'));
    await tester.pumpAndSettle();
  }

  /// La fila [etiqueta] del detalle muestra [valor].
  Finder fila(String etiqueta, String valor) => find.descendant(
    of: find.ancestor(of: find.text(etiqueta), matching: find.byType(Row)).first,
    matching: find.text(valor),
  );

  String fecha(int hora, int minuto) => formatearFechaHora(DateTime.utc(2026, 10, 1, hora, minuto));

  testWidgets('tocar un viaje del historial abre su detalle con datos frescos y "atrás" vuelve a la lista', (
    tester,
  ) async {
    await abrirDetalle(tester, _finalizado());

    expect(find.byType(DetalleViaje), findsOneWidget);
    expect(e.http.pedidos.where((x) => x.uri.path == '/api/viajes/1'), hasLength(1));
    expect(e.http.pedidos.where((x) => x.uri.path == '/api/viajes/1/recorrido'), hasLength(1));
    expect(find.text('Viaje finalizado'), findsOneWidget);
    expect(find.text('Carlos Gómez'), findsOneWidget);
    expect(find.text('Toyota Corolla (AB123CD) · Blanco'), findsOneWidget);
    expect(find.text('Plaza Independencia'), findsOneWidget);
    expect(find.text('Tribunales'), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byType(MisViajesPantalla), findsOneWidget);
  });

  testWidgets('horarios, duración y km con su formato', (tester) async {
    await abrirDetalle(tester, _finalizado());

    expect(fila('Pedido', fecha(11, 58)), findsOneWidget);
    expect(fila('Chofer asignado', fecha(12, 0)), findsOneWidget);
    expect(fila('Llegó', fecha(12, 5)), findsOneWidget);
    expect(fila('Inicio', fecha(12, 6)), findsOneWidget);
    expect(fila('Fin', fecha(12, 20)), findsOneWidget);
    expect(fila('Duración', '14 min'), findsOneWidget);
    expect(fila('Distancia', '5,3 km'), findsOneWidget);
  });

  testWidgets('con recorrido, una línea en el mapa, punto en el origen, pin en el destino y encuadre de todo', (
    tester,
  ) async {
    await abrirDetalle(tester, _finalizado());

    final linea = lineasDelMapa(tester, en: _detalle).single;
    expect(linea.puntos, const [_origen, Coordenada(-26.8162, -65.2201), _destino]);
    expect(find.text('origen: Origen'), findsOneWidget);
    expect(find.text('destino: Destino'), findsOneWidget);
    expect(enfoqueDelMapa(tester, en: _detalle)!.puntos, containsAll(<Coordenada>[_origen, _destino, linea.puntos[1]]));
    expect(find.textContaining('recorrido'), findsNothing);
  });

  testWidgets('cancelado por el solicitante, con su motivo', (tester) async {
    await abrirDetalle(
      tester,
      _cancelado(por: 'solicitante', motivo: 'Ya no lo necesito'),
      recorrido: p.recorridoNoDisponible,
    );

    expect(find.text('Viaje cancelado'), findsOneWidget);
    expect(find.text('Lo cancelaste vos'), findsOneWidget);
    expect(find.text('Motivo: Ya no lo necesito'), findsOneWidget);
    expect(find.text('Duración'), findsNothing);
    expect(find.text('Distancia'), findsNothing);
  });

  testWidgets('cancelado por la administración, sin motivo', (tester) async {
    await abrirDetalle(tester, _cancelado(por: 'admin'), recorrido: p.recorridoNoDisponible);

    expect(find.text('Lo canceló la administración'), findsOneWidget);
    expect(find.textContaining('Motivo'), findsNothing);
  });

  testWidgets('sin chofer: lo dice y no muestra chofer ni vehículo', (tester) async {
    final sinChofer = _cancelado()
      ..['estado'] = 'sin_chofer'
      ..['chofer'] = null
      ..['vehiculo'] = null
      ..['aceptado_en'] = null;
    await abrirDetalle(tester, sinChofer, recorrido: p.recorridoNoDisponible);

    expect(find.text('No hubo choferes disponibles'), findsOneWidget);
    expect(find.text('Carlos Gómez'), findsNothing);
    expect(find.text('Chofer asignado'), findsNothing);
  });

  testWidgets('sin recorrido registrado: solo origen y destino, y la nota', (tester) async {
    await abrirDetalle(tester, _cancelado(por: 'solicitante'), recorrido: p.recorridoNoDisponible);

    expect(lineasDelMapa(tester, en: _detalle), isEmpty);
    expect(find.text('origen: Origen'), findsOneWidget);
    expect(find.text('destino: Destino'), findsOneWidget);
    expect(enfoqueDelMapa(tester, en: _detalle)!.puntos, containsAll(<Coordenada>[_origen, _destino]));
    expect(find.text('Este viaje no tiene recorrido registrado.'), findsOneWidget);
  });

  testWidgets('recorrido vencido: la nota con los días de retención que dice el backend', (tester) async {
    await abrirDetalle(tester, _finalizado(), recorrido: p.recorridoVencido);

    expect(lineasDelMapa(tester, en: _detalle), isEmpty);
    expect(find.text('El recorrido ya no está disponible (se conserva 30 días).'), findsOneWidget);
  });

  testWidgets('recorrido vencido sin retención_dias: la nota sin paréntesis', (tester) async {
    await abrirDetalle(tester, _finalizado(), recorrido: '{"puntos":[],"disponible":false,"vencido":true}');

    expect(find.text('El recorrido ya no está disponible.'), findsOneWidget);
  });

  testWidgets('un viaje viejo sin puntos pero no vencido dice que no hay recorrido registrado', (tester) async {
    final viejo = _finalizado()..['finalizado_en'] = escribirFecha(DateTime.now().subtract(const Duration(days: 120)));
    await abrirDetalle(tester, viejo, recorrido: '{"puntos":[],"disponible":false}');

    expect(find.text('Este viaje no tiene recorrido registrado.'), findsOneWidget);
  });

  testWidgets('un id inválido en la ruta muestra "Viaje no encontrado" sin romper', (tester) async {
    await montarModulo(tester, e);

    GoRouter.of(tester.element(find.byType(InicioSolicitante))).go('/solicitante/mis-viajes/viaje/abc');
    await esperar(tester);

    expect(find.text('Viaje no encontrado'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox()); // se cierra el módulo
  });

  testWidgets('un error del recorrido no rompe la pantalla: sin línea ni nota', (tester) async {
    await abrirDetalle(tester, _finalizado(), recorrido: '{"message":"Server Error"}', estadoRecorrido: 500);

    expect(find.text('Viaje finalizado'), findsOneWidget);
    expect(fila('Distancia', '5,3 km'), findsOneWidget);
    expect(lineasDelMapa(tester, en: _detalle), isEmpty);
    expect(find.textContaining('recorrido'), findsNothing);
  });

  testWidgets('un error del detalle se muestra con "Reintentar"', (tester) async {
    e.http.responder('GET', 'viajes/1', 403, '{"message":"Este viaje no es tuyo."}');
    await abrirDetalle(tester, _finalizado());

    expect(find.text('Este viaje no es tuyo.'), findsOneWidget);
    await tester.tap(find.text('Reintentar'));
    await tester.pumpAndSettle();

    expect(find.text('Viaje finalizado'), findsOneWidget);
  });

  testWidgets('un JSON viejo sin los campos nuevos se muestra igual, sin km ni "Pedido"', (tester) async {
    final viejo = p.json(p.viajeAceptado)
      ..['estado'] = 'finalizado'
      ..['iniciado_en'] = '2026-10-01T12:06:00+00:00'
      ..['finalizado_en'] = '2026-10-01T12:20:00+00:00';
    await abrirDetalle(tester, viejo);

    expect(find.text('Viaje finalizado'), findsOneWidget);
    expect(fila('Duración', '14 min'), findsOneWidget);
    expect(find.text('Pedido'), findsNothing);
    expect(find.text('Distancia'), findsNothing);
  });

  testWidgets('una reserva sin chofer sigue ofreciendo "Elegir otro" en la lista', (tester) async {
    final reserva = p.json(p.reservaCreada)
      ..['estado'] = 'sin_chofer'
      ..['destino'] = {'lat': -26.8083, 'lng': -65.2176, 'direccion': 'Tribunales'};
    e.http.responder('GET', 'viajes', 200, _misViajes([reserva]));
    await montarModulo(tester, e);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();

    expect(find.text('Elegir otro'), findsOneWidget);
  });
}
