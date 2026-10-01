import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_google.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_osm.dart';
import 'package:vehiculos_oficiales/src/modelos/comunes.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'soporte/entorno_prueba.dart';

/// PNG transparente de 1x1: los tests nunca piden teselas a la red.
final Uint8List _pngVacio = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
);

class _TeselasVacias extends TileProvider {
  int pedidas = 0;

  @override
  ImageProvider getImage(TileCoordinates coordinates, TileLayer options) {
    pedidas++;
    return MemoryImage(_pngVacio);
  }
}

EntornoModulo _entornoConClave(String clave) => EntornoModulo(
  config: VehiculosOficialesConfig(
    apiBaseUrl: configPrueba.apiBaseUrl,
    reverbHost: configPrueba.reverbHost,
    reverbKey: configPrueba.reverbKey,
    googleMapsApiKey: clave,
  ),
  sesion: const SesionPJ('t'),
  push: PuenteFalso(),
  onSesionInvalida: () {},
);

void main() {
  const datosVacios = DatosMapa(centro: Coordenada(-26.8241, -65.2226));

  Future<Widget> construir(WidgetTester tester, String clave) async {
    final contenedor = ProviderContainer.test(overrides: [entornoProvider.overrideWithValue(_entornoConClave(clave))]);
    late BuildContext contexto;
    await tester.pumpWidget(
      Builder(
        builder: (c) {
          contexto = c;
          return const SizedBox();
        },
      ),
    );
    return contenedor.read(constructorMapaProvider)(contexto, datosVacios);
  }

  testWidgets('sin clave de Google Maps usa OpenStreetMap', (tester) async {
    expect(await construir(tester, ''), isA<MapaOsm>());
  });

  testWidgets('con clave de Google Maps usa Google', (tester) async {
    expect(await construir(tester, 'AIza-clave'), isA<MapaGoogle>());
  });

  group('MapaOsm', () {
    late _TeselasVacias teselas;
    late List<Uri> abiertas;

    setUp(() {
      teselas = _TeselasVacias();
      abiertas = [];
    });

    Future<void> montar(WidgetTester tester, DatosMapa datos, {Object? errorAlAbrir}) async {
      tester.view.physicalSize = const Size(800, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            lanzadorUrlProvider.overrideWithValue((uri) async {
              abiertas.add(uri);
              if (errorAlAbrir != null) throw errorAlAbrir;
              return true;
            }),
          ],
          child: MaterialApp(
            home: Scaffold(
              body: MapaOsm(datos: datos, teselas: teselas),
            ),
          ),
        ),
      );
      await tester.pump();
    }

    testWidgets('dibuja los marcadores y avisa al tocarlos o al tocar el mapa', (tester) async {
      final tocados = <String>[];
      final puntos = <Coordenada>[];
      await montar(
        tester,
        DatosMapa(
          centro: const Coordenada(-26.8241, -65.2226),
          marcadores: [
            MarcadorMapa(
              id: 'c1',
              posicion: const Coordenada(-26.8241, -65.2226),
              tipo: TipoMarcador.choferLibre,
              titulo: 'Juan',
              alTocar: () => tocados.add('c1'),
            ),
            MarcadorMapa(
              id: 'c2',
              posicion: const Coordenada(-26.8200, -65.2150),
              tipo: TipoMarcador.choferNoDisponible,
              titulo: 'Pedro',
              alTocar: () => tocados.add('c2'),
            ),
            const MarcadorMapa(
              id: 'o',
              posicion: Coordenada(-26.8300, -65.2300),
              tipo: TipoMarcador.origen,
              titulo: 'Origen',
            ),
          ],
          alTocarMapa: puntos.add,
        ),
      );

      expect(find.byKey(const Key('marcador-c1')), findsOneWidget);
      expect(find.byKey(const Key('marcador-c2')), findsOneWidget);
      expect(find.byKey(const Key('marcador-o')), findsOneWidget);
      expect(find.byTooltip('Juan'), findsOneWidget);
      expect(teselas.pedidas, greaterThan(0));

      // Colores según el tipo; los no disponibles, desvaídos.
      Icon icono(String id) =>
          tester.widget<Icon>(find.descendant(of: find.byKey(Key('marcador-$id')), matching: find.byType(Icon)));
      expect(icono('c1').color, MapaOsm.colorDe(TipoMarcador.choferLibre));
      expect(icono('o').color, MapaOsm.colorDe(TipoMarcador.origen));
      expect(
        tester
            .widget<Opacity>(find.descendant(of: find.byKey(const Key('marcador-c2')), matching: find.byType(Opacity)))
            .opacity,
        lessThan(1),
      );

      await tester.tap(find.byKey(const Key('marcador-c1')));
      await tester.pump(const Duration(milliseconds: 500));
      expect(tocados, ['c1']);
      expect(puntos, isEmpty, reason: 'tocar un marcador no elige un punto');

      // Tocar el centro de la vista (lejos de los marcadores) elige ese punto: el centro del mapa.
      await tester.tapAt(const Offset(400, 200));
      await tester.pump(const Duration(milliseconds: 500));
      expect(puntos, hasLength(1));
      expect(puntos.single.lng, closeTo(-65.2226, 0.001));
      expect(puntos.single.lat, greaterThan(-26.8241));

      // Atribución obligatoria de OpenStreetMap, que abre la página de derechos.
      expect(find.textContaining('OpenStreetMap'), findsOneWidget);
      await tester.tap(find.textContaining('OpenStreetMap'));
      await tester.pump(const Duration(milliseconds: 500));
      expect(abiertas, [Uri.parse('https://www.openstreetmap.org/copyright')]);
    });

    testWidgets('sin alTocarMapa, tocar el mapa no hace nada', (tester) async {
      await montar(tester, const DatosMapa(centro: Coordenada(-26.8241, -65.2226)));
      await tester.tapAt(const Offset(400, 200));
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
    });

    testWidgets('si no se puede abrir la página de créditos, no queda un error sin capturar', (tester) async {
      await montar(
        tester,
        const DatosMapa(centro: Coordenada(-26.8241, -65.2226)),
        errorAlAbrir: PlatformException(code: 'ACTIVITY_NOT_FOUND'),
      );
      await tester.tap(find.textContaining('OpenStreetMap'));
      await tester.pump(const Duration(milliseconds: 500));
      expect(abiertas, hasLength(1));
      expect(tester.takeException(), isNull);
    });
  });
}
