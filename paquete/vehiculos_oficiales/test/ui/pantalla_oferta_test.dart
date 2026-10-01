import 'dart:convert';

import 'package:clock/clock.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/reloj_servidor.dart';
import 'package:vehiculos_oficiales/src/avisos/reproductor_sonidos.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/mapa_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/pantalla_oferta.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_asignado.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_chofer.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

/// Payload de `oferta.creada` (inmediato) que vence en [vence] según el reloj del teléfono.
Map<String, dynamic> ofertaCreada(Duration vence) => {
  'oferta_id': 1,
  'vence_en': escribirFecha(clock.now().add(vence)),
  'viaje': p.json(p.viajeOfrecido),
};

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;

  setUp(() {
    e = entornoChofer();
    tr = TiempoRealFalso();
  });

  Future<void> abrir(WidgetTester tester, {RelojServidor? reloj}) => montarChofer(
    tester,
    e,
    tiempoReal: tr,
    extra: [if (reloj != null) relojServidorProvider.overrideWithValue(reloj)],
  );

  /// Llega la oferta por el socket y se abre su pantalla, sin que pase el tiempo.
  Future<void> llegaOferta(WidgetTester tester, Duration vence) async {
    tr.emitir('chofer.2', Eventos.ofertaCreada, ofertaCreada(vence));
    await tester.pump();
    await tester.pump();
  }

  testWidgets('la cuenta regresiva sale de vence_en (12 s, no 30) y baja con el reloj', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 12));

    expect(find.byType(PantallaOferta), findsOneWidget);
    expect(find.text('12 s'), findsOneWidget);
    expect(find.text('Ana Pérez'), findsOneWidget);
    expect(find.text('Destino: Tribunales'), findsOneWidget);

    await tester.pump(const Duration(seconds: 1));
    expect(find.text('11 s'), findsOneWidget);
  });

  testWidgets('con el reloj del servidor 60 s adelantado, vence_en a 70 s del teléfono son 10 s', (tester) async {
    final cliente = ClienteApi(baseApi: configPrueba.apiUri, alRecibir401: () {})
      ..desfaseReloj = const Duration(seconds: 60);

    await abrir(tester, reloj: RelojServidor(cliente));
    await llegaOferta(tester, const Duration(seconds: 70));

    expect(find.text('10 s'), findsOneWidget);
  });

  testWidgets('el timbre suena en bucle y vibra cada 2 s mientras está abierta', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    expect(e.sonidos.enBucle, Sonido.oferta);
    expect(e.sonidos.vibraciones, 1);

    await tester.pump(const Duration(seconds: 4));
    expect(e.sonidos.vibraciones, 3);
    expect(e.sonidos.bucles, 1);
  });

  testWidgets('el timbre se corta al tocar "Aceptar", antes de la respuesta', (tester) async {
    final demora = e.http.demorar('POST', 'ofertas/1/aceptar');

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Aceptar'));
    await tester.pump();
    expect(e.sonidos.enBucle, isNull);

    demora.complete((200, p.viajeAceptado));
    await esperar(tester);
    expect(e.sonidos.sonados, isEmpty);
  });

  testWidgets('el timbre se corta al rechazar', (tester) async {
    e.http.responder('POST', 'ofertas/1/rechazar', 204);

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Rechazar'));
    await esperar(tester);

    expect(e.sonidos.enBucle, isNull);
  });

  testWidgets('el timbre se corta al vencer y al cerrar el módulo', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 3));
    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
    expect(find.text('La oferta venció'), findsOneWidget);
    expect(e.sonidos.enBucle, isNull);

    tr.emitir('chofer.2', Eventos.ofertaCreada, {...ofertaCreada(const Duration(seconds: 30)), 'oferta_id': 2});
    await tester.pump();
    expect(e.sonidos.enBucle, Sonido.oferta);

    await tester.pumpWidget(const SizedBox()); // se cierra el módulo
    expect(e.sonidos.enBucle, isNull);
  });

  testWidgets('aceptar: POST /ofertas/1/aceptar y pasa al viaje', (tester) async {
    e.http.responder('POST', 'ofertas/1/aceptar', 200, p.viajeAceptado);

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/1/aceptar'));
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('rechazar: POST /ofertas/1/rechazar y vuelve al mapa', (tester) async {
    e.http.responder('POST', 'ofertas/1/rechazar', 204);

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Rechazar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/1/rechazar'));
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('a 0 muestra "La oferta venció" y vuelve al mapa sin responder', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 3));

    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
    expect(find.text('La oferta venció'), findsOneWidget);
    expect(find.text('Aceptar'), findsNothing);

    await tester.tap(find.text('Volver al mapa'));
    await esperar(tester);
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(pedidosHechos(e).where((r) => r.contains('ofertas')), isEmpty);
  });

  testWidgets('422 "La oferta ya no está vigente." se muestra y vuelve al mapa', (tester) async {
    e.http.responder('POST', 'ofertas/1/aceptar', 422, c.ofertaNoVigente);

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(find.text('La oferta ya no está vigente.'), findsOneWidget);
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('si el solicitante cancela mientras está la oferta, vuelve al mapa', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(p.viajeOfrecido)..['estado'] = 'cancelado');
    await esperar(tester);

    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('"atrás" no saca de la oferta', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));

    await tester.binding.handlePopRoute();
    await tester.pump();
    expect(find.byType(PantallaOferta), findsOneWidget);
  });

  testWidgets('un obligatorio asignado sin oferta: "Viaje asignado" sin "Rechazar" y después sin "Cancelar"', (
    tester,
  ) async {
    await abrir(tester);

    final obligatorio = p.json(p.viajeAceptado)
      ..['id'] = 7
      ..['obligatorio'] = true;
    tr.emitir('chofer.2', Eventos.viajeActualizado, obligatorio);
    await esperar(tester);

    expect(find.byType(ViajeAsignado), findsOneWidget);
    expect(find.text('Es un viaje obligatorio: no se puede rechazar.'), findsOneWidget);
    expect(find.text('Rechazar'), findsNothing);
    expect(e.sonidos.sonados, [Sonido.oferta]);

    await tester.tap(find.text('Ver viaje'));
    await esperar(tester);
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Viaje obligatorio'), findsOneWidget);
    expect(find.text('Cancelar viaje'), findsNothing);
  });

  testWidgets('aceptar con la respuesta cruzando el cero: termina en el viaje, no en "Viaje asignado"', (tester) async {
    final demora = e.http.demorar('POST', 'ofertas/1/aceptar');

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 3));
    await tester.tap(find.text('Aceptar'));
    await tester.pump(const Duration(seconds: 4)); // la cuenta llegó a 0 con el pedido en vuelo
    expect(find.text('La oferta venció'), findsNothing);

    demora.complete((200, p.viajeAceptado));
    await esperar(tester);

    expect(find.byType(ViajeAsignado), findsNothing);
    expect(find.byType(ViajeChofer), findsOneWidget);
  });

  testWidgets('aceptar cruzando el cero y el evento "aceptado" antes que la respuesta: tampoco es asignado', (
    tester,
  ) async {
    final demora = e.http.demorar('POST', 'ofertas/1/aceptar');

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 3));
    await tester.tap(find.text('Aceptar'));
    await tester.pump(const Duration(seconds: 4));

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(p.viajeAceptado));
    await tester.pump();
    demora.complete((200, p.viajeAceptado));
    await esperar(tester);

    expect(find.byType(ViajeAsignado), findsNothing);
    expect(find.byType(ViajeChofer), findsOneWidget);
  });

  testWidgets('una segunda oferta mientras se ve "La oferta venció" se muestra y vuelve a avisar', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 3));
    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
    expect(find.text('La oferta venció'), findsOneWidget);
    expect(e.sonidos.bucles, 1);

    tr.emitir('chofer.2', Eventos.ofertaCreada, {...ofertaCreada(const Duration(seconds: 30)), 'oferta_id': 2});
    await tester.pump();
    await tester.pump();

    expect(find.text('La oferta venció'), findsNothing);
    expect(find.text('Aceptar'), findsOneWidget);
    expect(e.sonidos.bucles, 2);
  });

  testWidgets('una oferta pendiente al abrir el módulo se muestra enseguida', (tester) async {
    final conOferta = p.json(p.viajeActualChofer);
    (conOferta['oferta'] as Map<String, dynamic>)['vence_en'] = escribirFecha(
      clock.now().add(const Duration(seconds: 20)),
    );
    e = entornoChofer(viajeActual: jsonEncode(conOferta));

    await abrir(tester);

    expect(find.byType(PantallaOferta), findsOneWidget);
  });
}
