import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart';
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

  testWidgets('si una recarga falla se sigue viendo la agenda anterior, con un aviso', (tester) async {
    e.http.sinRed('GET', 'agenda'); // después de la primera respuesta

    await abrirAgenda(tester);
    e.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'oferta_reserva', 'oferta_id': '4'});
    await esperar(tester);

    expect(find.text('Voy en camino'), findsOneWidget);
    expect(find.text('Aceptar'), findsOneWidget);
    expect(find.text('No se pudo actualizar la agenda.'), findsOneWidget);

    final antes = consultasAgenda();
    await tester.fling(find.text('Solicitudes'), const Offset(0, 300), 1000); // tirar para refrescar, sin red
    await esperar(tester);
    expect(consultasAgenda(), antes + 1);
    expect(find.text('Voy en camino'), findsOneWidget);
    expect(tester.takeException(), isNull);
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

  testWidgets('un viaje largo se ve con su etiqueta, salida, regreso y pasajeros, y sin rechazar', (tester) async {
    final largo = p.json(c.reservaConfirmada)
      ..['id'] = 7
      ..['tipo'] = 'largo'
      ..['destino'] = {'lat': -28.46, 'lng': -65.78, 'direccion': 'Tinogasta'}
      ..['regreso_estimado'] = '2026-10-04T21:30:00+00:00'
      ..['pasajeros'] = 'Dr. Ruiz y dos asesores';
    final agenda = p.json(c.agenda)..['reservas'] = [largo];
    e = entornoChofer(agenda: jsonEncode(agenda));

    await abrirAgenda(tester);

    expect(find.textContaining('Viaje largo'), findsOneWidget);
    expect(find.textContaining('Tinogasta'), findsOneWidget);
    expect(find.textContaining('Regreso'), findsOneWidget);
    expect(find.textContaining('Dr. Ruiz y dos asesores'), findsOneWidget);
    expect(find.text('Voy en camino'), findsOneWidget);
    expect(find.text('Rechazar'), findsOneWidget); // solo el de la solicitud; el largo no tiene
  });

  Map<String, dynamic> largoConfirmado() => p.json(c.reservaConfirmada)
    ..['id'] = 7
    ..['tipo'] = 'largo';

  testWidgets('"Voy en camino" en un viaje largo refresca el turno: el vehículo es el del viaje', (tester) async {
    final agenda = p.json(c.agenda)..['reservas'] = [largoConfirmado()];
    e = entornoChofer(agenda: jsonEncode(agenda));
    e.http
      ..responder('GET', 'turnos/actual', 200, c.turnoConOtroVehiculo) // el 2.º pedido: el turno ya cambió
      ..responder('POST', 'viajes/7/estado', 200, jsonEncode(largoConfirmado()..['estado'] = 'en_camino'));

    await abrirAgenda(tester);
    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(pedidosHechos(e).where((r) => r == 'GET turnos/actual'), hasLength(2));
    final turno = ProviderScope.containerOf(tester.element(find.byType(ViajeChofer))).read(turnoProvider).value;
    expect(turno!.vehiculo!.patente, 'AC456EF');
  });

  testWidgets('"Voy en camino" en un viaje largo sigue bien si el refresco del turno falla', (tester) async {
    final agenda = p.json(c.agenda)..['reservas'] = [largoConfirmado()];
    e = entornoChofer(agenda: jsonEncode(agenda));
    e.http
      ..sinRed('GET', 'turnos/actual')
      ..responder('POST', 'viajes/7/estado', 200, jsonEncode(largoConfirmado()..['estado'] = 'en_camino'));

    await abrirAgenda(tester);
    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(find.byType(ViajeChofer), findsOneWidget);
  });

  testWidgets('"Voy en camino" en una reserva no refresca el turno', (tester) async {
    e.http.responder('POST', 'viajes/2/estado', 200, jsonEncode(p.json(c.reservaConfirmada)..['estado'] = 'en_camino'));

    await abrirAgenda(tester);
    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(pedidosHechos(e).where((r) => r == 'GET turnos/actual'), hasLength(1));
  });

  testWidgets('un evento de un viaje largo recarga la agenda', (tester) async {
    await abrirAgenda(tester);
    final antes = consultasAgenda();

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(c.reservaConfirmada)..['tipo'] = 'largo');
    await esperar(tester);

    expect(consultasAgenda(), antes + 1);
  });
}
