import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/pantalla_reserva.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

final _cuando = DateTime(2026, 10, 2, 10); // hora local del dispositivo

String _misViajes({List<Map<String, dynamic>> proximas = const [], List<Map<String, dynamic>> historial = const []}) =>
    jsonEncode({'proximas': proximas, 'historial': historial});

Map<String, dynamic> _reserva({String estado = 'ofrecido', int id = 2}) => p.json(p.reservaCreada)
  ..['estado'] = estado
  ..['id'] = id;

void main() {
  late EntornoPrueba e;

  setUp(() {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);
    e.http.responder('GET', 'choferes', 200, p.choferes);
  });

  Future<void> abrir(WidgetTester tester) => montarModulo(
    tester,
    e,
    extra: [
      ubicadorProvider.overrideWithValue(UbicadorFalso(const Coordenada(-26.8241, -65.2226))),
      elegirFechaHoraProvider.overrideWithValue((_, _) async => _cuando),
    ],
  );

  /// El origen es la ubicación actual; el destino se marca en el mapa.
  Future<void> marcarOrigenYDestino(WidgetTester tester) async {
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.pumpAndSettle();
  }

  Map<String, dynamic> cuerpo(String metodo, String ruta) =>
      jsonDecode(e.http.pedidos.lastWhere((x) => x.metodo == metodo && x.uri.path == '/api/$ruta').cuerpo)
          as Map<String, dynamic>;

  testWidgets('reservar con un chofer elegido de los disponibles', (tester) async {
    e.http.responder('GET', 'reservas/disponibles', 200, p.reservasDisponibles);
    e.http.responder('POST', 'reservas', 201, jsonEncode(_reserva()..['modo'] = 'especifico'));
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [_reserva()]));

    await abrir(tester);
    await marcarOrigenYDestino(tester);
    await tester.tap(find.text('Reservar para más tarde'));
    await tester.pumpAndSettle();
    expect(find.byType(PantallaReserva), findsOneWidget);
    expect(find.text('Tu ubicación actual'), findsOneWidget);

    await tester.tap(find.text('Fecha y hora'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ver choferes disponibles'));
    await tester.pumpAndSettle();

    final consulta = e.http.pedidos.lastWhere((x) => x.uri.path == '/api/reservas/disponibles').uri.queryParameters;
    expect(consulta['programado_para'], escribirFecha(_cuando));
    expect(consulta['origen_lat'], '-26.8241');
    expect(find.text('Duración estimada: 19 min'), findsOneWidget);
    expect(find.text('Cualquiera disponible'), findsOneWidget);
    expect(find.text('0 reservas ese día'), findsOneWidget);

    await tester.tap(find.text('Carlos Gómez'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirmar reserva'));
    await tester.pumpAndSettle();

    expect(cuerpo('POST', 'reservas'), {
      'programado_para': escribirFecha(_cuando),
      'modo': 'especifico',
      'chofer_id': 2,
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'destino_lat': puntoTocado.lat,
      'destino_lng': puntoTocado.lng,
    });
    expect(find.text('Solicitud enviada. Te avisamos cuando el chofer responda.'), findsOneWidget);
    expect(find.text('Mis viajes'), findsOneWidget);
    expect(find.text('Esperando confirmación del chofer'), findsOneWidget);
  });

  testWidgets('"Cualquiera disponible" es la opción por defecto', (tester) async {
    e.http.responder('GET', 'reservas/disponibles', 200, p.reservasDisponibles);
    e.http.responder('POST', 'reservas', 201, p.reservaCreada);
    e.http.responder('GET', 'viajes', 200, _misViajes());

    await abrir(tester);
    await marcarOrigenYDestino(tester);
    await tester.tap(find.text('Reservar para más tarde'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ver choferes disponibles'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirmar reserva'));
    await tester.pumpAndSettle();

    expect(cuerpo('POST', 'reservas')['modo'], 'cualquiera_disponible');
    expect(cuerpo('POST', 'reservas').containsKey('chofer_id'), isFalse);
  });

  testWidgets('sin la anticipación mínima se muestra el mensaje del backend', (tester) async {
    e.http.responder(
      'GET',
      'reservas/disponibles',
      422,
      '{"message":"La reserva debe hacerse con al menos 60 minutos de anticipaci\\u00f3n."}',
    );

    await abrir(tester);
    await marcarOrigenYDestino(tester);
    await tester.tap(find.text('Reservar para más tarde'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ver choferes disponibles'));
    await tester.pumpAndSettle();

    expect(find.text('La reserva debe hacerse con al menos 60 minutos de anticipación.'), findsOneWidget);
  });

  testWidgets('mis viajes: cancelar una reserva y "atrás" vuelve al mapa', (tester) async {
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [_reserva(estado: 'aceptado')]));
    e.http.responder('GET', 'viajes', 200, _misViajes(historial: [_reserva(estado: 'cancelado')]));
    e.http.responder('POST', 'viajes/2/cancelar', 200, jsonEncode(_reserva(estado: 'cancelado')));

    await abrir(tester);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();
    expect(find.text('Confirmada'), findsOneWidget); // la reserva del fixture todavía no tiene chofer: sin "·" suelto

    await tester.tap(find.byTooltip('Cancelar reserva'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sí, cancelar'));
    await tester.pumpAndSettle();

    expect(find.text('No tenés reservas próximas.'), findsOneWidget);
    expect(find.text('Viaje cancelado'), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  testWidgets('mis viajes se actualiza con un aviso push', (tester) async {
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [_reserva()]));
    final confirmada = _reserva(estado: 'aceptado')..['chofer'] = {'id': 2, 'nombre': 'Carlos Gómez', 'telefono': null};
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [confirmada]));

    await abrir(tester);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();
    expect(find.text('Esperando confirmación del chofer'), findsOneWidget);

    e.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'viaje', 'viaje_id': '2', 'estado': 'aceptado'});
    await tester.pumpAndSettle();

    expect(find.text('Confirmada · Carlos Gómez'), findsOneWidget);
  });

  testWidgets('una reserva rechazada ofrece elegir otro chofer con los mismos datos', (tester) async {
    e.http.responder('GET', 'viajes', 200, _misViajes(historial: [_reserva(estado: 'sin_chofer')]));

    await abrir(tester);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Elegir otro'));
    await tester.pumpAndSettle();

    expect(find.byType(PantallaReserva), findsOneWidget);
    expect(find.text('Casa de Gobierno'), findsOneWidget);
  });
}
