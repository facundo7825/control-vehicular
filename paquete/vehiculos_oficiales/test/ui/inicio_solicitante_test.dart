import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/adaptador_falso.dart';
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

const _reservado =
    '[{"id":3,"nombre":"Luis Díaz","estado":"reservado_pronto","lat":-26.83,"lng":-65.21,"rumbo":null,"actualizado_en":"2026-10-01T12:00:00+00:00","vehiculo":{"patente":"AC456EF","marca":"Fiat","modelo":"Cronos","color":null}}]';

const _sugerencias =
    '[{"nombre":"Tribunales","direccion":"Tribunales, 24 de Septiembre 677, Tucumán","lat":-26.83,"lng":-65.2}]';

void main() {
  late EntornoPrueba e;
  late UbicadorFalso ubicador;

  /// Lo que responde `GET /configuracion` al abrir (por defecto, autocompleta mientras se escribe).
  late String configuracion;

  setUp(() {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);
    e.http.responder('GET', 'choferes', 200, p.choferes);
    ubicador = UbicadorFalso(const Coordenada(-26.8241, -65.2226));
    configuracion = p.configuracion;
  });

  Future<void> abrir(WidgetTester tester) {
    e.http.responder('GET', 'configuracion', 200, configuracion);
    return montarModulo(tester, e, ubicador: ubicador);
  }

  Map<String, dynamic> ultimoCuerpo() => jsonDecode(e.http.pedidos.last.cuerpo) as Map<String, dynamic>;

  testWidgets('muestra los choferes en turno; el libre en verde', (tester) async {
    await abrir(tester);

    expect(find.text('choferLibre: Carlos Gómez · Libre'), findsOneWidget);
  });

  testWidgets('un chofer reservado pronto se ve pero no se puede elegir', (tester) async {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);
    e.http.responder('GET', 'choferes', 200, _reservado);

    await abrir(tester);
    await tester.tap(find.text('choferNoDisponible: Luis Díaz · Reservado pronto'));
    await tester.pumpAndSettle();

    expect(find.textContaining('no se le pueden pedir viajes ahora'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Pedir a este chofer')).onPressed, isNull);
  });

  testWidgets('al abrir, el origen es mi ubicación y el mapa se centra ahí', (tester) async {
    await abrir(tester);

    expect(find.text('Tu ubicación actual'), findsOneWidget);
    expect(find.byTooltip('Cambiar origen'), findsOneWidget);
    expect(find.text('origen: Origen'), findsOneWidget);
    expect(enfoqueDelMapa(tester)!.puntos, [const Coordenada(-26.8241, -65.2226)]);
  });

  testWidgets('"Mi ubicación" vuelve a centrar el mapa', (tester) async {
    await abrir(tester);
    final antes = enfoqueDelMapa(tester)!;

    await tester.tap(find.byTooltip('Mi ubicación'));
    await tester.pumpAndSettle();

    expect(enfoqueDelMapa(tester)!.puntos, antes.puntos);
    expect(enfoqueDelMapa(tester), isNot(antes));
  });

  testWidgets('pedir el más cercano con mi ubicación y un destino marcado en el mapa', (tester) async {
    e.http.responder('POST', 'viajes', 201, p.viajeOfrecido);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);

    await abrir(tester);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Pedir el más cercano')).onPressed, isNull);

    await tester.tap(find.byKey(const Key('tocar-mapa'))); // el origen ya está: se marca el destino
    await tester.pumpAndSettle();
    await tester.tap(find.text('Direcciones y motivo (opcional)'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Dirección de destino'), 'Tribunales');
    await tester.enterText(find.widgetWithText(TextField, 'Motivo'), 'Audiencia');
    await tester.tap(find.text('Pedir el más cercano'));
    await esperar(tester);

    expect(ultimoCuerpo(), {
      'modo': 'mas_cercano',
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'destino_lat': puntoTocado.lat,
      'destino_lng': puntoTocado.lng,
      'destino_direccion': 'Tribunales',
      'motivo': 'Audiencia',
    });
    expect(find.text('Buscando el chofer más cercano…'), findsOneWidget);
  });

  testWidgets('pedir a un chofer elegido en el mapa', (tester) async {
    e.http.responder('POST', 'viajes', 201, jsonEncode(p.json(p.viajeOfrecido)..['modo'] = 'especifico'));

    await abrir(tester);
    await tester.tap(find.text('choferLibre: Carlos Gómez · Libre'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pedir a este chofer'));
    await tester.pumpAndSettle();
    expect(find.text('Chofer: Carlos Gómez'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.tap(find.text('Destino'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pedir a Carlos Gómez'));
    await esperar(tester);

    expect(ultimoCuerpo()['modo'], 'especifico');
    expect(ultimoCuerpo()['chofer_id'], 2);
    expect(find.text('Esperando que el chofer acepte…'), findsOneWidget);
  });

  testWidgets('sin permiso de ubicación se explica cómo marcar el origen', (tester) async {
    ubicador.posicion = null;

    await abrir(tester);

    expect(enfoqueDelMapa(tester), isNull);
    expect(find.text('No pudimos obtener tu ubicación: tocá el mapa o buscá una dirección.'), findsOneWidget);
    expect(find.widgetWithText(TextField, '¿Desde dónde salís?'), findsOneWidget);

    await tester.tap(find.byTooltip('Usar mi ubicación'));
    await tester.pump();
    expect(find.text('No pudimos obtener tu ubicación. Marcá el origen tocando el mapa.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tocar-mapa'))); // el primer toque es el origen
    await tester.pumpAndSettle();
    expect(find.text('origen: Origen'), findsOneWidget);
    expect(find.widgetWithText(TextField, '¿A dónde vas?'), findsOneWidget);
  });

  testWidgets('"Cambiar origen" con un toque en el mapa; "Usar mi ubicación" lo deshace', (tester) async {
    e.http.responder('POST', 'viajes', 201, p.viajeOfrecido);

    await abrir(tester);
    await tester.tap(find.byTooltip('Cambiar origen'));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(TextField, '¿Desde dónde salís?'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.pumpAndSettle();
    expect(find.text('Tu ubicación actual'), findsNothing);

    await tester.tap(find.byTooltip('Usar mi ubicación'));
    await tester.pumpAndSettle();
    expect(find.text('Tu ubicación actual'), findsOneWidget);

    await tester.tap(find.byTooltip('Cambiar origen'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tocar-mapa'))); // origen
    await tester.tap(find.byKey(const Key('tocar-mapa'))); // destino
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pedir el más cercano'));
    await esperar(tester);

    expect(ultimoCuerpo()['origen_lat'], puntoTocado.lat);
    expect(ultimoCuerpo()['destino_lat'], puntoTocado.lat);
  });

  testWidgets('escribir el destino, elegir una sugerencia y pedir', (tester) async {
    e.http.responder('GET', 'lugares', 200, _sugerencias);
    e.http.responder('POST', 'viajes', 201, p.viajeOfrecido);

    await abrir(tester);
    await tester.enterText(find.byKey(const Key('buscar-lugar')), 'tri');
    await tester.pump(const Duration(milliseconds: 200));
    await tester.enterText(find.byKey(const Key('buscar-lugar')), 'tribu');
    await tester.pump(const Duration(milliseconds: 399));
    expect(e.http.pedidos.where((x) => x.uri.path == '/api/lugares'), isEmpty);
    await tester.pump(const Duration(milliseconds: 1));
    await esperar(tester);

    final busquedas = e.http.pedidos.where((x) => x.uri.path == '/api/lugares').toList();
    expect(busquedas.single.uri.queryParameters, {'q': 'tribu', 'lat': '-26.8241', 'lng': '-65.2226'});
    expect(find.text('Tribunales'), findsOneWidget);
    expect(find.text('Tribunales, 24 de Septiembre 677, Tucumán'), findsOneWidget);

    await tester.tap(find.text('Tribunales'));
    await tester.pumpAndSettle();

    expect(find.text('Tribunales'), findsNothing); // las sugerencias se cierran
    expect(find.text('Tribunales, 24 de Septiembre 677, Tucumán'), findsOneWidget); // el destino elegido
    expect(find.text('destino: Destino'), findsOneWidget);
    expect(enfoqueDelMapa(tester)!.puntos, [const Coordenada(-26.8241, -65.2226), const Coordenada(-26.83, -65.2)]);

    await tester.tap(find.text('Pedir el más cercano'));
    await esperar(tester);

    expect(ultimoCuerpo(), {
      'modo': 'mas_cercano',
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'destino_lat': -26.83,
      'destino_lng': -65.2,
      'destino_direccion': 'Tribunales, 24 de Septiembre 677, Tucumán',
    });
  });

  group('mientras llega la ubicación', () {
    final campo = find.byKey(const Key('buscar-lugar'));
    String escrito(WidgetTester tester) => tester.widget<TextField>(campo).controller!.text;

    Future<void> buscar(WidgetTester tester) async {
      await tester.enterText(campo, 'tribu');
      await tester.pump(const Duration(milliseconds: 400));
      await esperar(tester);
    }

    // El Completer se crea dentro del test (zona de fake_async) para que completarlo avance la pantalla.
    Future<void> abrirSinUbicacionTodavia(WidgetTester tester) {
      ubicador.retener = Completer();
      return abrir(tester);
    }

    setUp(() => e.http.responder('GET', 'lugares', 200, _sugerencias));

    testWidgets('lo que se escribe es el destino y no se borra cuando la ubicación llega', (tester) async {
      await abrirSinUbicacionTodavia(tester);
      expect(find.text('Buscando tu ubicación…'), findsOneWidget);
      expect(find.widgetWithText(TextField, '¿A dónde vas?'), findsOneWidget);

      await buscar(tester);
      ubicador.retener!.complete();
      await esperar(tester);

      expect(find.text('Tu ubicación actual'), findsOneWidget);
      expect(escrito(tester), 'tribu');
      await tester.tap(find.text('Tribunales'));
      await tester.pumpAndSettle();
      expect(find.text('Tribunales, 24 de Septiembre 677, Tucumán'), findsOneWidget);
      expect(find.text('Tu ubicación actual'), findsOneWidget);
    });

    testWidgets('elegir el destino y después recibir la ubicación: origen = ubicación, destino = lo elegido', (
      tester,
    ) async {
      e.http.responder('POST', 'viajes', 201, p.viajeOfrecido);

      await abrirSinUbicacionTodavia(tester);
      await buscar(tester);
      await tester.tap(find.text('Tribunales'));
      await tester.pumpAndSettle();
      final enfoque = enfoqueDelMapa(tester);
      ubicador.retener!.complete();
      await esperar(tester);

      expect(find.text('Tu ubicación actual'), findsOneWidget);
      expect(enfoqueDelMapa(tester), enfoque); // no salta a la ubicación: ya había elegido algo
      await tester.tap(find.text('Pedir el más cercano'));
      await esperar(tester);
      expect(ultimoCuerpo(), {
        'modo': 'mas_cercano',
        'origen_lat': -26.8241,
        'origen_lng': -65.2226,
        'destino_lat': -26.83,
        'destino_lng': -65.2,
        'destino_direccion': 'Tribunales, 24 de Septiembre 677, Tucumán',
      });
    });

    testWidgets('un toque en el mapa es el destino', (tester) async {
      await abrirSinUbicacionTodavia(tester);
      await tester.tap(find.byKey(const Key('tocar-mapa')));
      await tester.pumpAndSettle();
      expect(find.text('destino: Destino'), findsOneWidget);
      expect(find.text('origen: Origen'), findsNothing);

      ubicador.retener!.complete();
      await esperar(tester);
      expect(find.text('origen: Origen'), findsOneWidget);
      expect(find.text('Tu ubicación actual'), findsOneWidget);
    });

    testWidgets('si no llega, lo que se estaba escribiendo sigue siendo el destino', (tester) async {
      ubicador.posicion = null;

      await abrirSinUbicacionTodavia(tester);
      await buscar(tester);
      ubicador.retener!.complete();
      await esperar(tester);

      expect(escrito(tester), 'tribu');
      expect(find.widgetWithText(TextField, '¿A dónde vas?'), findsOneWidget);
      await tester.tap(find.text('Tribunales'));
      await tester.pumpAndSettle();
      expect(find.text('destino: Destino'), findsOneWidget);
      expect(find.text('origen: Origen'), findsNothing);
      expect(find.widgetWithText(TextField, '¿Desde dónde salís?'), findsOneWidget); // ahora, el origen
    });
  });

  group('sin autocompletar (Nominatim)', () {
    final campo = find.byKey(const Key('buscar-lugar'));
    List<PedidoRegistrado> busquedas() => e.http.pedidos.where((x) => x.uri.path == '/api/lugares').toList();

    setUp(() => configuracion = p.configuracionSinAutocompletar);

    testWidgets('escribir no busca; el botón de buscar sí, con "Buscando…" y los resultados', (tester) async {
      final respuesta = e.http.demorar('GET', 'lugares');
      await abrir(tester);
      await tester.enterText(campo, 'tribu');
      await tester.pump(const Duration(seconds: 2));
      expect(busquedas(), isEmpty);
      expect(find.text('Buscando…'), findsNothing);

      await tester.tap(find.byTooltip('Buscar'));
      await tester.pump();
      expect(find.text('Buscando…'), findsOneWidget);
      respuesta.complete((200, _sugerencias));
      await esperar(tester);

      expect(busquedas().single.uri.queryParameters['q'], 'tribu');
      expect(find.text('Tribunales'), findsOneWidget);
    });

    testWidgets('la tecla "buscar" del teclado busca; sin resultados lo dice', (tester) async {
      e.http.responder('GET', 'lugares', 200, '[]');
      await abrir(tester);
      await tester.showKeyboard(campo);
      await tester.enterText(campo, 'xyzw');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await esperar(tester);

      expect(busquedas(), hasLength(1));
      expect(find.text('Sin resultados'), findsOneWidget);
    });
  });

  testWidgets('con autocompletar también se puede buscar ya con el botón', (tester) async {
    e.http.responder('GET', 'lugares', 200, _sugerencias);
    await abrir(tester);
    await tester.enterText(find.byKey(const Key('buscar-lugar')), 'tribu');
    await tester.tap(find.byTooltip('Buscar'));
    await esperar(tester);

    expect(e.http.pedidos.where((x) => x.uri.path == '/api/lugares'), hasLength(1));
    expect(find.text('Tribunales'), findsOneWidget);
  });

  testWidgets('si la búsqueda falla se ve "Sin resultados"', (tester) async {
    e.http.responder('GET', 'lugares', 500, '{"message":"Server Error"}');

    await abrir(tester);
    await tester.enterText(find.byKey(const Key('buscar-lugar')), 'tribu');
    await tester.pump(const Duration(milliseconds: 400));
    await esperar(tester);

    expect(find.text('Sin resultados'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('en un teléfono chico con el teclado abierto el campo y las sugerencias se ven', (tester) async {
    e.http.responder('GET', 'lugares', 200, _sugerencias);

    await abrir(tester);
    tester.view.physicalSize = const Size(1080, 1920); // 360 x 640
    tester.view.viewInsets = const FakeViewPadding(bottom: 900); // teclado de 300
    addTearDown(tester.view.resetViewInsets);
    await tester.pumpAndSettle();

    await tester.showKeyboard(find.byKey(const Key('buscar-lugar')));
    await tester.enterText(find.byKey(const Key('buscar-lugar')), 'tribu');
    await tester.pump(const Duration(milliseconds: 400));
    await esperar(tester);

    const visible = Rect.fromLTRB(0, 0, 360, 340);
    expect(visible.contains(tester.getRect(find.byKey(const Key('buscar-lugar'))).center), isTrue);
    expect(visible.contains(tester.getCenter(find.text('Tribunales'))), isTrue);
    await tester.tap(find.text('Tribunales'));
    await tester.pumpAndSettle();
    expect(find.text('Tribunales, 24 de Septiembre 677, Tucumán'), findsOneWidget); // el destino elegido
  });

  testWidgets('si el backend rechaza el pedido se muestra su mensaje', (tester) async {
    e.http.responder('POST', 'viajes', 422, '{"message":"Ya ten\\u00e9s un viaje en curso."}');

    await abrir(tester);
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pedir el más cercano'));
    await tester.pumpAndSettle();

    expect(find.text('Ya tenés un viaje en curso.'), findsOneWidget);
  });

  testWidgets('con un viaje en curso al abrir va a su pantalla; al volver ofrece verlo', (tester) async {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, '{"viaje":${p.viajeAceptado},"oferta":null}');
    e.http.responder('GET', 'choferes', 200, p.choferes);

    await abrir(tester);
    expect(find.text('Carlos Gómez'), findsOneWidget);

    await tester.binding.handlePopRoute(); // "atrás" del sistema: vuelve al mapa, no cierra el módulo
    await tester.pumpAndSettle();
    expect(find.text('Tenés un viaje en curso.'), findsOneWidget);

    await tester.tap(find.text('Ver'));
    await tester.pumpAndSettle();
    expect(find.text('Chofer asignado'), findsWidgets);
  });

  testWidgets('las direcciones y el motivo tienen el límite del backend (255 caracteres)', (tester) async {
    await abrir(tester);
    await tester.tap(find.text('Direcciones y motivo (opcional)'));
    await tester.pumpAndSettle();

    final campos = ['Dirección de origen', 'Dirección de destino', 'Motivo'];
    expect(
      [for (final c in campos) tester.widget<TextField>(find.widgetWithText(TextField, c)).maxLength],
      [255, 255, 255],
    );
  });

  testWidgets('después de volver al mapa, las novedades del mismo viaje no lo vuelven a abrir', (tester) async {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, '{"viaje":${p.viajeAceptado},"oferta":null}');
    e.http.responder('GET', 'choferes', 200, p.choferes);
    final tr = TiempoRealFalso();

    await montarModulo(tester, e, tiempoReal: tr, ubicador: ubicador);
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('Tenés un viaje en curso.'), findsOneWidget);

    tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(p.viajeAceptado)..['estado'] = 'en_camino');
    await tester.pumpAndSettle();

    expect(find.text('Tenés un viaje en curso.'), findsOneWidget);
    expect(find.text('El chofer va en camino'), findsNothing);
  });

  testWidgets('"Elegir otro" vuelve al mapa con el mismo origen y destino', (tester) async {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    final sinChofer = p.json(p.viajeOfrecido)
      ..['estado'] = 'sin_chofer'
      ..['modo'] = 'especifico';
    e.http.responder('GET', 'viajes/actual', 200, jsonEncode({'viaje': sinChofer, 'oferta': null}));
    e.http.responder('GET', 'choferes', 200, p.choferes);

    await abrir(tester);
    await tester.tap(find.text('Elegir otro'));
    await tester.pumpAndSettle();

    expect(find.text('Plaza Independencia'), findsOneWidget);
    expect(find.text('Tribunales'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Pedir el más cercano')).onPressed, isNotNull);
  });
}
