import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart' as gm;
import 'package:latlong2/latlong.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_google.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_osm.dart';
import 'package:vehiculos_oficiales/src/modelos/comunes.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'soporte/entorno_prueba.dart';
import 'soporte/montar.dart';

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
      Color? fondo(String id) =>
          ((tester.widget<Container>(
                    find.descendant(of: find.byKey(Key('marcador-$id')), matching: find.byType(Container)).first,
                  )).decoration
                  as BoxDecoration?)
              ?.color;
      expect(fondo('c1'), MapaOsm.colorDe(TipoMarcador.choferLibre));
      expect(fondo('o'), MapaOsm.colorDe(TipoMarcador.origen));
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

    testWidgets('los choferes son un auto y el usuario un punto, centrados; el destino es un pin', (tester) async {
      // Todos en el centro del mapa: el centro de la vista (400, 400).
      const centro = Coordenada(-26.8241, -65.2226);
      await montar(
        tester,
        const DatosMapa(
          centro: centro,
          marcadores: [
            MarcadorMapa(id: 'libre', posicion: centro, tipo: TipoMarcador.choferLibre, titulo: 'Libre'),
            MarcadorMapa(id: 'ocupado', posicion: centro, tipo: TipoMarcador.choferNoDisponible, titulo: 'Ocupado'),
            MarcadorMapa(id: 'asignado', posicion: centro, tipo: TipoMarcador.choferAsignado, titulo: 'Asignado'),
            MarcadorMapa(id: 'o', posicion: centro, tipo: TipoMarcador.origen, titulo: 'Origen'),
            MarcadorMapa(id: 'd', posicion: centro, tipo: TipoMarcador.destino, titulo: 'Destino'),
          ],
        ),
      );

      Finder iconos(String id) => find.descendant(of: find.byKey(Key('marcador-$id')), matching: find.byType(Icon));
      const posicion = Offset(400, 400);

      for (final id in ['libre', 'ocupado', 'asignado']) {
        expect(tester.widget<Icon>(iconos(id)).icon, Icons.directions_car, reason: id);
        expect(tester.getCenter(find.byKey(Key('marcador-$id'))), posicion, reason: '$id centrado');
      }
      expect(
        tester
            .widget<Opacity>(
              find.descendant(of: find.byKey(const Key('marcador-ocupado')), matching: find.byType(Opacity)),
            )
            .opacity,
        lessThan(1),
        reason: 'el no disponible sigue desvaído',
      );

      expect(iconos('o'), findsNothing, reason: 'el usuario es un punto, no un pin');
      final punto = tester.getRect(find.byKey(const Key('marcador-o')));
      expect(punto.center, posicion);
      expect(punto.width, lessThan(30), reason: 'un punto chico');

      expect(tester.widget<Icon>(iconos('d')).icon, Icons.location_on);
      expect(tester.getRect(find.byKey(const Key('marcador-d'))).bottomCenter, posicion, reason: 'punta abajo');
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

    group('enfoque', () {
      const tucuman = Coordenada(-26.8241, -65.2226);
      const yerbaBuena = Coordenada(-26.8167, -65.3167);
      const famailla = Coordenada(-27.0544, -65.4031);

      MapCamera camara(WidgetTester tester) => MapCamera.of(tester.element(find.byType(TileLayer)));

      Future<void> enfocar(WidgetTester tester, Enfoque? enfoque) async {
        await tester.pumpWidget(
          ProviderScope(
            child: MaterialApp(
              home: Scaffold(
                body: MapaOsm(
                  datos: DatosMapa(centro: tucuman, enfoque: enfoque),
                  teselas: teselas,
                ),
              ),
            ),
          ),
        );
        await tester.pump();
      }

      testWidgets('arranca mirando el enfoque si ya hay uno', (tester) async {
        tester.view.physicalSize = const Size(800, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        await enfocar(tester, Enfoque.punto(yerbaBuena));
        expect(camara(tester).center.latitude, closeTo(yerbaBuena.lat, 1e-6));
        expect(camara(tester).center.longitude, closeTo(yerbaBuena.lng, 1e-6));
      });

      testWidgets('un enfoque nuevo mueve la cámara sin recrear el mapa', (tester) async {
        tester.view.physicalSize = const Size(800, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        await enfocar(tester, null);
        expect(camara(tester).center.latitude, closeTo(tucuman.lat, 1e-6));
        final estado = tester.state(find.byType(FlutterMap));

        await enfocar(tester, Enfoque.punto(yerbaBuena, version: 1));
        expect(camara(tester).center.latitude, closeTo(yerbaBuena.lat, 1e-6));
        expect(camara(tester).center.longitude, closeTo(yerbaBuena.lng, 1e-6));
        expect(camara(tester).zoom, Enfoque.zoomPunto);
        expect(tester.state(find.byType(FlutterMap)), same(estado), reason: 'no se recrea el mapa');
      });

      testWidgets('el mismo enfoque no vuelve a mover; otra versión del mismo punto sí', (tester) async {
        tester.view.physicalSize = const Size(800, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        await enfocar(tester, Enfoque.punto(yerbaBuena));

        // La persona mueve el mapa con el dedo.
        await tester.drag(find.byType(FlutterMap), const Offset(-300, 0));
        await tester.pumpAndSettle();
        final movida = camara(tester).center;
        expect(movida.longitude, isNot(closeTo(yerbaBuena.lng, 1e-4)));

        // Reconstruir con el mismo enfoque (p. ej. cambió un marcador) no la devuelve.
        await enfocar(tester, Enfoque.punto(yerbaBuena));
        expect(camara(tester).center, movida);

        // "Mi ubicación": mismo punto, versión nueva → vuelve a centrar.
        await enfocar(tester, Enfoque.punto(yerbaBuena, version: 1));
        expect(camara(tester).center.longitude, closeTo(yerbaBuena.lng, 1e-6));
      });

      testWidgets('con varios puntos los encuadra a todos', (tester) async {
        tester.view.physicalSize = const Size(800, 800);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        await enfocar(tester, null);
        await enfocar(tester, Enfoque.entre(const [tucuman, famailla]));
        final visible = camara(tester).visibleBounds;
        for (final p in [tucuman, famailla]) {
          expect(visible.contains(LatLng(p.lat, p.lng)), isTrue, reason: '$p visible');
        }
        expect(camara(tester).zoom, greaterThan(9), reason: 'encuadra, no se aleja de más');
      });
    });
  });

  group('Enfoque', () {
    const a = Coordenada(-26.8, -65.2);
    const b = Coordenada(-27.0, -65.4);

    test('se compara por puntos y versión', () {
      expect(Enfoque.punto(a), Enfoque.punto(a));
      expect(Enfoque.punto(a).hashCode, Enfoque.punto(a).hashCode);
      expect(Enfoque.punto(a), isNot(Enfoque.punto(a, version: 1)));
      expect(Enfoque.punto(a), isNot(Enfoque.punto(b)));
      expect(Enfoque.entre(const [a, b]), Enfoque.entre(const [a, b]));
    });

    test('varios puntos iguales cuentan como uno solo', () {
      expect(Enfoque.entre(const [a, a]).unico, a);
      expect(Enfoque.punto(a).unico, a);
      expect(Enfoque.entre(const [a, b]).unico, isNull);
    });

    test('límites de varios puntos', () {
      final e = Enfoque.entre(const [a, b]);
      expect(e.sur, -27.0);
      expect(e.norte, -26.8);
      expect(e.oeste, -65.4);
      expect(e.este, -65.2);
    });

    test('SeguidorEnfoque solo pide mover cuando el enfoque cambia', () {
      final s = SeguidorEnfoque();
      expect(s.aMover(null), isNull);
      expect(s.aMover(Enfoque.punto(a)), Enfoque.punto(a));
      expect(s.aMover(Enfoque.punto(a)), isNull, reason: 'el mismo enfoque ya se aplicó');
      expect(s.aMover(null), isNull);
      expect(s.aMover(Enfoque.punto(a)), isNull, reason: 'quitar el enfoque no olvida el último aplicado');
      expect(s.aMover(Enfoque.punto(a, version: 1)), Enfoque.punto(a, version: 1));
      expect(s.aMover(Enfoque.entre(const [a, b])), Enfoque.entre(const [a, b]));
    });

    test('SeguidorEnfoque: un enfoque que no se pudo aplicar se vuelve a pedir', () {
      final s = SeguidorEnfoque();
      final e = Enfoque.punto(a);
      expect(s.aMover(e), e);

      s.fallo(e);
      expect(s.aMover(e), e);
      expect(s.aMover(e), isNull);

      s.fallo(Enfoque.punto(b)); // uno que ya no es el último: no cambia nada
      expect(s.aMover(e), isNull);
    });
  });

  group('MapaGoogle', () {
    const a = Coordenada(-26.8, -65.2);
    const a2 = Coordenada(-26.8001, -65.2001); // a unos metros de a
    const b = Coordenada(-27.0, -65.4);

    test('un punto: centra con el zoom de enfoque', () {
      expect(
        MapaGoogle.actualizacionPara(Enfoque.punto(a)).toJson(),
        gm.CameraUpdate.newLatLngZoom(const gm.LatLng(-26.8, -65.2), Enfoque.zoomPunto).toJson(),
      );
    });

    test('varios puntos: encuadra los límites', () {
      expect(
        MapaGoogle.actualizacionPara(Enfoque.entre(const [a, b])).toJson(),
        gm.CameraUpdate.newLatLngBounds(
          gm.LatLngBounds(southwest: const gm.LatLng(-27.0, -65.4), northeast: const gm.LatLng(-26.8, -65.2)),
          Enfoque.margen,
        ).toJson(),
      );
    });

    group('aplicar', () {
      late List<Map<String, Object?>> movimientos;
      Future<void> mover(gm.CameraUpdate u) async => movimientos.add({'u': u.toJson()});
      setUp(() => movimientos = []);

      test('un encuadre que acerca más que el zoom de un punto se limita a ese zoom', () async {
        await MapaGoogle.aplicar(Enfoque.entre(const [a, a2]), mover: mover, zoom: () async => 19);

        expect(movimientos.map((m) => m['u']), [
          MapaGoogle.actualizacionPara(Enfoque.entre(const [a, a2])).toJson(),
          gm.CameraUpdate.zoomTo(Enfoque.zoomPunto).toJson(),
        ]);
      });

      test('un encuadre amplio no se toca; un punto no consulta el zoom', () async {
        await MapaGoogle.aplicar(Enfoque.entre(const [a, b]), mover: mover, zoom: () async => 9);
        expect(movimientos, hasLength(1));

        movimientos.clear();
        await MapaGoogle.aplicar(Enfoque.punto(a), mover: mover, zoom: () async => fail('no hace falta'));
        expect(movimientos, hasLength(1));
      });

      test('si mover falla, el error llega a quien llamó (para reintentar)', () async {
        await expectLater(
          MapaGoogle.aplicar(Enfoque.punto(a), mover: (_) async => throw Exception('sin mapa'), zoom: () async => 15),
          throwsException,
        );
      });
    });

    group('íconos', () {
      const tonos = {
        TipoMarcador.choferLibre: gm.BitmapDescriptor.hueGreen,
        TipoMarcador.choferNoDisponible: gm.BitmapDescriptor.hueYellow,
        TipoMarcador.choferAsignado: gm.BitmapDescriptor.hueAzure,
        TipoMarcador.origen: gm.BitmapDescriptor.hueOrange,
        TipoMarcador.destino: gm.BitmapDescriptor.hueRed,
      };

      test('choferes como auto, el usuario como punto, el destino como pin', () {
        expect(TipoMarcador.choferLibre.forma, FormaMarcador.auto);
        expect(TipoMarcador.choferNoDisponible.forma, FormaMarcador.auto);
        expect(TipoMarcador.choferAsignado.forma, FormaMarcador.auto);
        expect(TipoMarcador.origen.forma, FormaMarcador.punto);
        expect(TipoMarcador.destino.forma, FormaMarcador.pin);
      });

      test('mientras no hay íconos dibujados, pines de color con la punta en la posición', () {
        for (final t in TipoMarcador.values) {
          final a = MapaGoogle.aparienciaDe(t, const {});
          expect(a.icono.toJson(), gm.BitmapDescriptor.defaultMarkerWithHue(tonos[t]!).toJson(), reason: '$t');
          expect(a.ancla, const Offset(0.5, 1), reason: '$t');
        }
      });

      test('con los íconos dibujados, auto y punto van centrados; el destino sigue siendo un pin', () {
        final iconos = {
          for (final t in TipoMarcador.values)
            if (t.forma != FormaMarcador.pin) t: gm.BitmapDescriptor.bytes(_pngVacio, width: t.forma.lado),
        };
        for (final t in [TipoMarcador.choferLibre, TipoMarcador.choferNoDisponible, TipoMarcador.origen]) {
          final a = MapaGoogle.aparienciaDe(t, iconos);
          expect(a.icono, same(iconos[t]), reason: '$t');
          expect(a.ancla, const Offset(0.5, 0.5), reason: '$t');
        }
        final destino = MapaGoogle.aparienciaDe(TipoMarcador.destino, iconos);
        expect(destino.icono.toJson(), gm.BitmapDescriptor.defaultMarkerWithHue(gm.BitmapDescriptor.hueRed).toJson());
        expect(destino.ancla, const Offset(0.5, 1));
      });

      testWidgets('dibuja un PNG para cada auto y punto', (tester) async {
        final iconos = (await tester.runAsync(MapaGoogle.dibujarIconos))!;
        expect(iconos.keys.toSet(), {
          TipoMarcador.choferLibre,
          TipoMarcador.choferNoDisponible,
          TipoMarcador.choferAsignado,
          TipoMarcador.origen,
        });
        for (final MapEntry(key: t, value: icono) in iconos.entries) {
          expect(icono, isA<gm.BytesMapBitmap>(), reason: '$t');
          icono as gm.BytesMapBitmap;
          expect(icono.byteData.sublist(1, 4), 'PNG'.codeUnits, reason: '$t es un PNG');
          expect(icono.width, t.forma.lado, reason: '$t');
        }
      });
    });

    test('la posición inicial mira el enfoque de un punto, si no el centro', () {
      expect(
        MapaGoogle.posicionInicial(DatosMapa(centro: a, enfoque: Enfoque.punto(b))).target,
        const gm.LatLng(-27.0, -65.4),
      );
      expect(MapaGoogle.posicionInicial(const DatosMapa(centro: a)).target, const gm.LatLng(-26.8, -65.2));
      expect(
        MapaGoogle.posicionInicial(DatosMapa(centro: a, enfoque: Enfoque.entre(const [a, b]))).target,
        const gm.LatLng(-26.8, -65.2),
        reason: 'el encuadre de varios puntos se aplica al crearse el mapa',
      );
    });
  });

  testWidgets('el mapa de prueba deja leer su enfoque', (tester) async {
    Future<void> mostrar(Enfoque? enfoque) => tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (c) => mapaDePrueba(c, DatosMapa(centro: const Coordenada(-26.8, -65.2), enfoque: enfoque)),
        ),
      ),
    );
    await mostrar(null);
    expect(enfoqueDelMapa(tester), isNull);
    await mostrar(Enfoque.punto(const Coordenada(-27, -65.4), version: 2));
    expect(enfoqueDelMapa(tester), Enfoque.punto(const Coordenada(-27, -65.4), version: 2));
  });

  group('BotonMiUbicacion', () {
    testWidgets('avisa al tocarlo', (tester) async {
      var tocado = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(body: BotonMiUbicacion(alTocar: () => tocado++)),
        ),
      );
      await tester.tap(find.byTooltip('Mi ubicación'));
      expect(tocado, 1);
    });
  });
}
