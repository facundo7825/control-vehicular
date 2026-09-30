import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/agenda.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_chofer.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

/// Agenda con la reserva confirmada 2 y la solicitud 3 (del fixture).
String agendaCompleta() {
  final a = p.json(c.agenda)..['reservas'] = [p.json(c.reservaConfirmada)];
  return jsonEncode(a);
}

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;

  setUp(() {
    e = entornoChofer(agenda: agendaCompleta());
    tr = TiempoRealFalso();
  });

  int consultasAgenda() => pedidosHechos(e).where((r) => r == 'GET agenda').length;

  Future<void> abrirAgenda(WidgetTester tester) async {
    await montarChofer(tester, e, tiempoReal: tr);
    await tester.tap(find.byTooltip('Agenda'));
    await esperar(tester);
    expect(find.byType(AgendaPantalla), findsOneWidget);
  }

  testWidgets('muestra solicitudes con su vencimiento y reservas confirmadas', (tester) async {
    await abrirAgenda(tester);

    expect(find.textContaining('Casa de Gobierno'), findsNWidgets(2));
    expect(find.textContaining('Responder antes de'), findsOneWidget);
    expect(find.textContaining('~19 min'), findsOneWidget);
    expect(find.text('Aceptar'), findsOneWidget);
    expect(find.text('Rechazar'), findsOneWidget);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('aceptar una solicitud llama a la API y recarga', (tester) async {
    e.http.responder('POST', 'ofertas/3/aceptar', 200, c.reservaConfirmada);

    await abrirAgenda(tester);
    final antes = consultasAgenda();
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/3/aceptar'));
    expect(consultasAgenda(), antes + 1);
  });

  testWidgets('rechazar una solicitud llama a la API y recarga', (tester) async {
    e.http.responder('POST', 'ofertas/3/rechazar', 204);

    await abrirAgenda(tester);
    final antes = consultasAgenda();
    await tester.tap(find.text('Rechazar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/3/rechazar'));
    expect(consultasAgenda(), antes + 1);
  });

  testWidgets('una solicitud que se superpone muestra el 422 y recarga', (tester) async {
    e.http.responder('POST', 'ofertas/3/aceptar', 422, c.reservaNoDisponible);

    await abrirAgenda(tester);
    final antes = consultasAgenda();
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(find.text('La reserva ya no está disponible o se superpone con otra de tu agenda.'), findsOneWidget);
    expect(consultasAgenda(), antes + 1);
  });

  testWidgets('un push oferta_reserva y un evento de una reserva recargan; uno de un inmediato no', (tester) async {
    await abrirAgenda(tester);
    final antes = consultasAgenda();

    e.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'oferta_reserva', 'oferta_id': '4'});
    await esperar(tester);
    expect(consultasAgenda(), antes + 1);

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(c.reservaConfirmada)..['estado'] = 'cancelado');
    await esperar(tester);
    expect(consultasAgenda(), antes + 2);

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(p.viajeOfrecido)..['estado'] = 'cancelado');
    await esperar(tester);
    expect(consultasAgenda(), antes + 2);
  });

  testWidgets('el mapa destaca la próxima reserva; "Voy en camino" antes de tiempo muestra el 422', (tester) async {
    e.http.responder('POST', 'viajes/2/estado', 422, c.reservaAntesDeTiempo);

    await montarChofer(tester, e, tiempoReal: tr);
    expect(find.textContaining('Próxima reserva'), findsOneWidget);

    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(find.text('Podés salir hacia esta reserva a partir de las 11:15.'), findsOneWidget);
  });

  testWidgets('"Voy en camino" a tiempo: la reserva pasa a ser el viaje actual', (tester) async {
    e.http.responder('POST', 'viajes/2/estado', 200, jsonEncode(p.json(c.reservaConfirmada)..['estado'] = 'en_camino'));

    await abrirAgenda(tester);
    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(jsonDecode(e.http.pedidos.last.cuerpo), {'estado': 'en_camino'});
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Llegué'), findsOneWidget);
  });
}
