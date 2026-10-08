import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
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
  late UbicadorFalso gps;

  setUp(() {
    tr = TiempoRealFalso();
    lanzadas = [];
    abreGoogleMaps = true;
    gps = UbicadorFalso();
  });

  /// [preparar] agrega respuestas antes de abrir.
  Future<void> abrir(WidgetTester tester, String viaje, [void Function(EntornoPrueba e)? preparar]) async {
    e = entornoChofer(viajeActual: actualCon(viaje));
    preparar?.call(e);
    await montarChofer(
      tester,
      e,
      tiempoReal: tr,
      ubicador: gps,
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
      expect(
        cuerpo('viajes/1/estado'),
        allOf(containsPair('estado', estado), contains('momento'), contains('id_accion')),
      );
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

  group('sin señal', () {
    testWidgets('los pasos avanzan al instante, se avisa cuántos faltan enviar y salen al reconectar', (tester) async {
      await abrir(tester, viajeJson(estado: 'en_camino'), (e) => e.http.sinRed('POST', 'viajes/1/estado'));

      await tester.tap(find.widgetWithText(FilledButton, 'Llegué'));
      await esperar(tester);
      expect(find.widgetWithText(FilledButton, 'Iniciar viaje'), findsOneWidget);
      expect(find.text('Sin señal: 1 acción se enviará al reconectar'), findsOneWidget);

      await tester.tap(find.widgetWithText(FilledButton, 'Iniciar viaje'));
      await esperar(tester);
      expect(find.widgetWithText(FilledButton, 'Finalizar'), findsOneWidget);
      expect(find.text('Sin señal: 2 acciones se enviarán al reconectar'), findsOneWidget);

      e.http
        ..limpiar('POST', 'viajes/1/estado')
        ..responder('POST', 'viajes/1/estado', 200, viajeJson(estado: 'llego'))
        ..responder('POST', 'viajes/1/estado', 200, viajeJson(estado: 'en_curso'));
      tr
        ..cambiar(EstadoConexion.desconectado)
        ..cambiar(EstadoConexion.conectado);
      await esperar(tester);

      expect(find.textContaining('se enviar'), findsNothing);
      expect(find.widgetWithText(FilledButton, 'Finalizar'), findsOneWidget);
      final enviados = [
        for (final r in e.http.pedidos.where((r) => r.uri.path == '/api/viajes/1/estado').toList().reversed.take(2))
          (jsonDecode(r.cuerpo) as Map<String, dynamic>)['estado'],
      ];
      expect(enviados.reversed, ['llego', 'en_curso']);
    });

    testWidgets('si el servidor falla 3 veces seguidas con el mismo paso, se pide avisar al encargado', (tester) async {
      await abrir(
        tester,
        viajeJson(estado: 'en_camino'),
        (e) => e.http.responder('POST', 'viajes/1/estado', 500, '{"message":"Server Error"}'),
      );
      await tester.tap(find.widgetWithText(FilledButton, 'Llegué'));
      await esperar(tester);
      expect(find.text('Sin señal: 1 acción se enviará al reconectar'), findsOneWidget);

      for (var i = 0; i < 2; i++) {
        await tester.pump(const Duration(seconds: 30));
        await esperar(tester);
      }

      expect(find.text('No se pudo enviar el viaje: avisá al encargado'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Iniciar viaje'), findsOneWidget);
    });

    testWidgets('con señal no aparece el aviso mientras el paso sale', (tester) async {
      await abrir(tester, viajeJson(estado: 'en_camino'));
      final respuesta = e.http.demorar('POST', 'viajes/1/estado');

      await tester.tap(find.widgetWithText(FilledButton, 'Llegué'));
      await esperar(tester);
      expect(find.widgetWithText(FilledButton, 'Iniciar viaje'), findsOneWidget);
      expect(find.textContaining('se enviará'), findsNothing);

      respuesta.complete((200, viajeJson(estado: 'llego')));
      await esperar(tester);
      expect(find.textContaining('se enviará'), findsNothing);
    });

    testWidgets('si al reconectar el servidor la rechaza, vuelve al estado real y se avisa', (tester) async {
      await abrir(tester, viajeJson(estado: 'en_camino'), (e) => e.http.sinRed('POST', 'viajes/1/estado'));
      await tester.tap(find.widgetWithText(FilledButton, 'Llegué'));
      await esperar(tester);
      expect(find.widgetWithText(FilledButton, 'Iniciar viaje'), findsOneWidget);

      e.http
        ..limpiar('POST', 'viajes/1/estado')
        ..responder('POST', 'viajes/1/estado', 409, '{"message":"El viaje se reasignó mientras estabas sin señal."}');
      tr
        ..cambiar(EstadoConexion.desconectado)
        ..cambiar(EstadoConexion.conectado);
      await esperar(tester);

      expect(find.text('El viaje se reasignó mientras estabas sin señal.'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Llegué'), findsOneWidget);
      expect(find.textContaining('se enviar'), findsNothing);
    });
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

  testWidgets('sin socket, cancelado en el servidor: tras el sondeo avisa que se canceló, no vuelve al mapa', (
    tester,
  ) async {
    tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
    await abrir(tester, viajeJson(), (e) {
      e.http
        ..responder('GET', 'viajes/actual', 200, p.viajeActualVacio)
        ..responder('GET', 'viajes/1', 200, viajeJson(estado: 'cancelado'));
    });
    expect(find.text('Voy en camino'), findsOneWidget);

    await tester.pump(const Duration(seconds: 10));
    await esperar(tester);

    expect(find.text('El viaje fue cancelado'), findsOneWidget);
    expect(find.byType(MapaChofer), findsNothing);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(find.text('Tenés un viaje en curso.'), findsNothing);
  });

  testWidgets('reasignado por un administrador', (tester) async {
    await abrir(tester, viajeJson());

    final reasignado = p.json(viajeJson())..['chofer'] = {'id': 9, 'nombre': 'Otro', 'telefono': null};
    tr.emitir('chofer.2', Eventos.viajeActualizado, reasignado);
    await esperar(tester);

    expect(find.text('El viaje se reasignó a otro chofer'), findsOneWidget);

    // "Atrás" sin descartarlo: el mapa no lo ofrece como viaje en curso (ya no es suyo).
    await tester.binding.handlePopRoute();
    await esperar(tester);
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(find.text('Tenés un viaje en curso.'), findsNothing);
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

  group('recorrido e indicaciones', () {
    // Desde (-26.83, -65.23): 656 m al norte y 734 m al este, hasta el origen del viaje.
    const rutaAlOrigen =
        '{"distancia_m":1390,"duracion_s":300,"puntos":[[-26.83,-65.23],[-26.8241,-65.23],[-26.8241,-65.2226]],'
        '"pasos":[{"instruccion":"Seguí por Belgrano","distancia_m":656,"indice":0,"lat":-26.83,"lng":-65.23,"tipo":"salida"},'
        '{"instruccion":"Doblá a la derecha por San Martín","distancia_m":734,"indice":1,"lat":-26.8241,"lng":-65.23,"tipo":"derecha"},'
        '{"instruccion":"Llegaste a destino","distancia_m":0,"indice":2,"lat":-26.8241,"lng":-65.2226,"tipo":"llegada"}]}';
    const rutaAlDestino =
        '{"distancia_m":1900,"duracion_s":240,"puntos":[[-26.8241,-65.224],[-26.8083,-65.2176]],'
        '"pasos":[{"instruccion":"Seguí por 24 de Septiembre","distancia_m":1900,"indice":0,"lat":-26.8241,"lng":-65.224,"tipo":"salida"},'
        '{"instruccion":"Llegaste a destino","distancia_m":0,"indice":1,"lat":-26.8083,"lng":-65.2176,"tipo":"llegada"}]}';

    var segundo = 0;
    Future<void> ir(WidgetTester tester, double lat, double lng) async {
      gps.emitir(
        PuntoGps(
          posicion: Coordenada(lat, lng),
          registradoEn: DateTime.utc(2026, 10, 1, 12).add(Duration(seconds: ++segundo)),
        ),
      );
      await esperar(tester);
    }

    /// Origen y destino de cada `GET /ruta`, como "lat,lng → lat,lng".
    List<String> tramosPedidos() => [
      for (final r in e.http.pedidos)
        if (r.uri.path == '/api/ruta')
          '${r.uri.queryParameters['origen_lat']},${r.uri.queryParameters['origen_lng']} → '
              '${r.uri.queryParameters['destino_lat']},${r.uri.queryParameters['destino_lng']}',
    ];

    List<LineaMapa> lineas(WidgetTester tester) => lineasDelMapa(tester, en: find.byType(ViajeChofer));

    testWidgets('al origen con la próxima indicación que avanza con el GPS; en curso, al destino', (tester) async {
      await abrir(tester, viajeJson(estado: 'en_camino'), (e) {
        e.http
          ..responder('GET', 'ruta', 200, rutaAlOrigen)
          ..responder('GET', 'ruta', 200, rutaAlDestino);
      });
      expect(tramosPedidos(), isEmpty, reason: 'sin posición todavía no hay recorrido');
      expect(lineas(tester), isEmpty);

      await ir(tester, -26.83, -65.23);
      expect(tramosPedidos(), ['-26.83,-65.23 → -26.8241,-65.2226']);
      expect(lineas(tester).single.puntos, hasLength(3));
      expect(find.text('En 650 m, doblá a la derecha por San Martín'), findsOneWidget);
      expect(find.text('1,4 km · 5 min'), findsOneWidget);
      expect(find.byIcon(Icons.turn_right), findsOneWidget);

      await ir(tester, -26.8243, -65.23);
      expect(find.text('Doblá a la derecha por San Martín'), findsOneWidget);

      await ir(tester, -26.8241, -65.2228);
      expect(find.text('Llegaste al origen'), findsOneWidget);
      expect(find.byIcon(Icons.flag), findsOneWidget);
      expect(tramosPedidos(), hasLength(1), reason: 'sobre el recorrido no se recalcula');

      await ir(tester, -26.8241, -65.224);
      tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_curso')));
      await esperar(tester);
      expect(tramosPedidos().last, '-26.8241,-65.224 → -26.8083,-65.2176');
      expect(lineas(tester).single.puntos, hasLength(2));
      expect(find.text('En 1,9 km, llegás a destino'), findsOneWidget);
      expect(find.text('Navegar'), findsOneWidget, reason: 'la navegación externa sigue');
    });

    testWidgets('fuera del recorrido en 2 posiciones seguidas se recalcula, como mucho cada 30 s', (tester) async {
      await abrir(tester, viajeJson(), (e) => e.http.responder('GET', 'ruta', 200, rutaAlOrigen));
      await ir(tester, -26.83, -65.23);
      expect(tramosPedidos(), hasLength(1));

      await ir(tester, -26.829, -65.227);
      await ir(tester, -26.8285, -65.227);
      expect(tramosPedidos(), hasLength(1), reason: 'no antes de 30 s del anterior');
      await tester.pump(const Duration(seconds: 30));
      await esperar(tester);
      expect(tramosPedidos(), hasLength(2));
      expect(tramosPedidos().last, startsWith('-26.8285,-65.227 →'), reason: 'desde la última posición');

      // Se sale y vuelve antes de los 30 s: no hace falta recalcular.
      await ir(tester, -26.83, -65.23);
      await ir(tester, -26.828, -65.227);
      await ir(tester, -26.8275, -65.227);
      await ir(tester, -26.829, -65.23);
      await tester.pump(const Duration(seconds: 30));
      await esperar(tester);
      expect(tramosPedidos(), hasLength(2));

      // Pasados los 30 s, al salirse se recalcula enseguida.
      await ir(tester, -26.827, -65.227);
      await ir(tester, -26.8265, -65.227);
      expect(tramosPedidos(), hasLength(3));
      expect(tramosPedidos().last, startsWith('-26.8265,-65.227 →'));
    });

    testWidgets('sin recorrido no hay cartel ni línea, y el viaje se ve igual', (tester) async {
      await abrir(tester, viajeJson(), (e) => e.http.responder('GET', 'ruta', 500, '{"message":"Server Error"}'));
      await ir(tester, -26.83, -65.23);

      expect(tramosPedidos(), hasLength(1));
      expect(lineas(tester), isEmpty);
      expect(find.textContaining(' · '), findsNothing);
      expect(find.textContaining('llegás'), findsNothing);
      expect(find.text('Voy en camino'), findsOneWidget);
    });

    testWidgets('si el recorrido falla se reintenta a los 30 s (aunque siga en el mismo lugar)', (tester) async {
      await abrir(tester, viajeJson(), (e) {
        e.http
          ..responder('GET', 'ruta', 500, '{"message":"Server Error"}')
          ..responder('GET', 'ruta', 200, rutaAlOrigen);
      });
      await ir(tester, -26.83, -65.23);
      expect(lineas(tester), isEmpty);

      await ir(tester, -26.83, -65.23);
      expect(tramosPedidos(), hasLength(1), reason: 'no antes de 30 s');
      await tester.pump(const Duration(seconds: 30));
      await esperar(tester);

      expect(tramosPedidos(), ['-26.83,-65.23 → -26.8241,-65.2226', '-26.83,-65.23 → -26.8241,-65.2226']);
      expect(lineas(tester).single.puntos, hasLength(3));
      expect(find.text('En 650 m, doblá a la derecha por San Martín'), findsOneWidget);
    });

    testWidgets('fuera del recorrido dice "Recalculando…"; si el recálculo falla sigue el recorrido anterior', (
      tester,
    ) async {
      await abrir(tester, viajeJson(), (e) {
        e.http
          ..responder('GET', 'ruta', 200, rutaAlOrigen)
          ..responder('GET', 'ruta', 500, '{"message":"Server Error"}');
      });
      await ir(tester, -26.83, -65.23);
      await ir(tester, -26.829, -65.227);
      expect(find.text('En 650 m, doblá a la derecha por San Martín'), findsOneWidget);
      await ir(tester, -26.8285, -65.227);
      expect(find.text('Recalculando…'), findsOneWidget);
      expect(find.text('En 650 m, doblá a la derecha por San Martín'), findsNothing);

      await tester.pump(const Duration(seconds: 30));
      await esperar(tester);
      expect(tramosPedidos(), hasLength(2));
      expect(lineas(tester).single.puntos, hasLength(3), reason: 'el recálculo falló: sigue el anterior');
      expect(find.text('Recalculando…'), findsOneWidget);

      await ir(tester, -26.8299, -65.23);
      expect(find.textContaining('doblá a la derecha por San Martín'), findsOneWidget);
    });

    testWidgets('sin señal y fuera del recorrido dice "Sin señal: recorrido sin actualizar"', (tester) async {
      await abrir(tester, viajeJson(), (e) {
        e.http
          ..responder('GET', 'ruta', 200, rutaAlOrigen)
          ..sinRed('GET', 'ruta');
      });
      await ir(tester, -26.83, -65.23);
      tr.cambiar(EstadoConexion.desconectado);
      await ir(tester, -26.829, -65.227);
      expect(find.text('En 650 m, doblá a la derecha por San Martín'), findsOneWidget, reason: 'en el recorrido');
      await ir(tester, -26.8285, -65.227);

      expect(find.text('Sin señal: recorrido sin actualizar'), findsOneWidget);
      expect(find.text('Recalculando…'), findsNothing);
      expect(lineas(tester).single.puntos, hasLength(3), reason: 'sigue el recorrido que ya tenía');
    });

    for (final estado in ['aceptado', 'llego']) {
      testWidgets('$estado: el recorrido va al origen', (tester) async {
        await abrir(tester, viajeJson(estado: estado), (e) => e.http.responder('GET', 'ruta', 200, rutaAlOrigen));
        await ir(tester, -26.83, -65.23);

        expect(tramosPedidos(), ['-26.83,-65.23 → -26.8241,-65.2226']);
        expect(lineas(tester).single.puntos, hasLength(3));
        expect(find.text('En 650 m, doblá a la derecha por San Martín'), findsOneWidget);
        final texto = tester.widget<Text>(find.text('En 650 m, doblá a la derecha por San Martín'));
        expect([texto.maxLines, texto.overflow], [2, TextOverflow.ellipsis]);
      });
    }
  });

  testWidgets('un viaje largo en camino muestra regreso y pasajeros y no se puede cancelar', (tester) async {
    final largo = p.json(viajeJson(estado: 'en_camino', tipo: 'largo'))
      ..['programado_para'] = '2026-10-02T13:00:00+00:00'
      ..['regreso_estimado'] = '2026-10-03T21:30:00+00:00'
      ..['pasajeros'] = 'Dr. Ruiz y dos asesores';
    await abrir(tester, jsonEncode(largo));

    expect(find.textContaining('Viaje largo para'), findsOneWidget);
    expect(find.textContaining('Regreso estimado'), findsOneWidget);
    expect(find.text('Pasajeros: Dr. Ruiz y dos asesores'), findsOneWidget);
    expect(find.text('Llegué'), findsOneWidget);
    expect(find.text('Cancelar viaje'), findsNothing);
  });
}
