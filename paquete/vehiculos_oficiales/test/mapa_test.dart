import 'dart:convert';

import 'package:dio/dio.dart' hide Headers;
import 'package:dio/dio.dart' as dio show Headers;
import 'package:dio_cache_interceptor/dio_cache_interceptor.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_cache/flutter_map_cache.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart' as gm;
import 'package:latlong2/latlong.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart' show configuracionProvider;
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/cache_teselas.dart';
import 'package:vehiculos_oficiales/src/mapa/corredor_teselas.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_google.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_osm.dart';
import 'package:vehiculos_oficiales/src/modelos/comunes.dart';
import 'package:vehiculos_oficiales/src/modelos/configuracion.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
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

/// Un servidor de teselas falso para el cliente HTTP del caché: responde un PNG y anota lo pedido.
class _ServidorTeselas implements HttpClientAdapter {
  final pedidas = <Uri>[];
  final agentes = <String?>[];
  int estado = 200;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    pedidas.add(options.uri);
    agentes.add(options.headers['User-Agent'] as String?);
    return ResponseBody.fromBytes(
      estado == 200 ? _pngVacio : Uint8List(0),
      estado,
      headers: {
        dio.Headers.contentTypeHeader: ['image/png'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

/// Lo que manda el backend por defecto: el mapa de fondo de OpenStreetMap.
const _configuracionOsm = Configuracion(gpsTurnoSeg: 10, gpsViajeSeg: 5, ofertaSegundos: 30);

const _configuracionPropia = Configuracion(
  gpsTurnoSeg: 10,
  gpsViajeSeg: 5,
  ofertaSegundos: 30,
  teselas: MapaFondo(url: 'https://mapas.ejemplo.gob.ar/{z}/{x}/{y}.png', atribucion: 'Mapa propio'),
);

/// `GET /configuracion` que responde, en orden, lo programado: un [ErrorApi] falla y una [Configuracion] responde.
class _ApiConfiguracion extends ApiFalsa {
  _ApiConfiguracion(this.respuestas);

  final List<Object> respuestas;
  int consultas = 0;

  @override
  Future<Configuracion> configuracion() async {
    final r = respuestas[consultas.clamp(0, respuestas.length - 1)];
    consultas++;
    if (r is ErrorApi) throw r;
    return r as Configuracion;
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

    Future<void> montar(
      WidgetTester tester,
      DatosMapa datos, {
      Object? errorAlAbrir,
      AsyncValue<Configuracion> configuracion = const AsyncData(_configuracionOsm),
      ApiVehiculos? api,
    }) async {
      tester.view.physicalSize = const Size(800, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            // Con [api], `GET /configuracion` de verdad (con sus fallas y reintentos); si no, el valor fijo.
            if (api != null)
              apiProvider.overrideWithValue(api)
            else
              configuracionProvider.overrideWithValue(configuracion),
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

    testWidgets('dibuja las líneas debajo de los marcadores', (tester) async {
      const a = Coordenada(-26.8241, -65.2226);
      const b = Coordenada(-26.8083, -65.2176);
      await montar(
        tester,
        DatosMapa(
          centro: a,
          lineas: [
            LineaMapa.recorrido(const [a, b]),
            const LineaMapa(id: 'sola', puntos: [a], color: Colors.red, ancho: 3),
          ],
          marcadores: const [MarcadorMapa(id: 'o', posicion: a, tipo: TipoMarcador.origen, titulo: 'Origen')],
        ),
      );

      final polilineas = tester.widget<PolylineLayer>(find.byType(PolylineLayer)).polylines;
      expect(polilineas, hasLength(1), reason: 'una línea de un solo punto no se dibuja');
      expect(polilineas.single.points, [LatLng(a.lat, a.lng), LatLng(b.lat, b.lng)]);
      expect(polilineas.single.color, LineaMapa.colorRecorrido);
      expect(polilineas.single.strokeWidth, 5);

      final capas = tester.widget<FlutterMap>(find.byType(FlutterMap)).children;
      expect(
        capas.indexWhere((c) => c is PolylineLayer),
        allOf(
          greaterThan(capas.indexWhere((c) => c is TileLayer)),
          lessThan(capas.indexWhere((c) => c is MarkerLayer)),
        ),
        reason: 'encima de las teselas y debajo de los marcadores',
      );
    });

    testWidgets('sin alTocarMapa, tocar el mapa no hace nada', (tester) async {
      await montar(tester, const DatosMapa(centro: Coordenada(-26.8241, -65.2226)));
      await tester.tapAt(const Offset(400, 200));
      await tester.pump(const Duration(milliseconds: 500));
      expect(tester.takeException(), isNull);
    });

    group('mapa de fondo', () {
      const centro = DatosMapa(centro: Coordenada(-26.8241, -65.2226));

      TileLayer capa(WidgetTester tester) => tester.widget<TileLayer>(find.byType(TileLayer));

      testWidgets('por defecto usa OpenStreetMap, identificándose con el agente de usuario', (tester) async {
        await montar(tester, centro);

        expect(capa(tester).urlTemplate, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
        expect(capa(tester).tms, isFalse);
        expect(capa(tester).maxNativeZoom, 19);
        expect(capa(tester).tileProvider.headers['User-Agent'], 'flutter_map (${MapaOsm.agenteUsuario})');
        expect(find.text('© OpenStreetMap contributors'), findsOneWidget);
      });

      testWidgets('usa el servidor, tms, zoom y créditos configurados en el backend', (tester) async {
        const propio = Configuracion(
          gpsTurnoSeg: 10,
          gpsViajeSeg: 5,
          ofertaSegundos: 30,
          teselas: MapaFondo(
            url: 'https://mapas.ejemplo.gob.ar/tms/{z}/{x}/{y}.png',
            atribucion: 'IGN · OpenStreetMap',
            atribucionUrl: 'https://mapas.ejemplo.gob.ar/creditos',
            tms: true,
            maxZoom: 15,
          ),
        );
        await montar(tester, centro, configuracion: const AsyncData(propio));

        expect(capa(tester).urlTemplate, 'https://mapas.ejemplo.gob.ar/tms/{z}/{x}/{y}.png');
        expect(capa(tester).tms, isTrue);
        expect(capa(tester).maxNativeZoom, 15);
        expect(teselas.pedidas, greaterThan(0), reason: 'las pide al proveedor de teselas de siempre');
        expect(find.text('IGN · OpenStreetMap'), findsOneWidget);
        expect(find.text('© OpenStreetMap contributors'), findsNothing);

        await tester.tap(find.text('IGN · OpenStreetMap'));
        await tester.pump(const Duration(milliseconds: 500));
        expect(abiertas, [Uri.parse('https://mapas.ejemplo.gob.ar/creditos')]);
      });

      testWidgets('sin enlace de créditos, tocarlos no abre nada', (tester) async {
        const sinEnlace = Configuracion(
          gpsTurnoSeg: 10,
          gpsViajeSeg: 5,
          ofertaSegundos: 30,
          teselas: MapaFondo(url: 'https://mapas.ejemplo.gob.ar/{z}/{x}/{y}.png', atribucion: 'Mapa propio'),
        );
        await montar(tester, centro, configuracion: const AsyncData(sinEnlace));

        await tester.tap(find.text('Mapa propio'));
        await tester.pump(const Duration(milliseconds: 500));
        expect(abiertas, isEmpty);
        expect(tester.takeException(), isNull);
      });

      testWidgets('mientras carga la configuración no pide teselas a ningún servidor', (tester) async {
        await montar(tester, centro, configuracion: const AsyncLoading());

        expect(find.byType(TileLayer), findsNothing);
        expect(teselas.pedidas, 0);
        expect(find.byType(FlutterMap), findsOneWidget, reason: 'los marcadores y las líneas se ven igual');
      });

      testWidgets('si la configuración falla no dibuja el fondo (nunca el OSM público)', (tester) async {
        await montar(tester, centro, configuracion: AsyncError(Exception('sin red'), StackTrace.empty));

        expect(find.byType(TileLayer), findsNothing);
        expect(teselas.pedidas, 0);
        expect(find.textContaining('OpenStreetMap'), findsNothing);
        expect(find.byType(FlutterMap), findsOneWidget, reason: 'los marcadores y las líneas se ven igual');
      });

      testWidgets('si GET /configuracion falla reintenta cada 30 s y, al responder, usa el servidor configurado', (
        tester,
      ) async {
        final api = _ApiConfiguracion([const SinConexion(), const ErrorServidor(), _configuracionPropia]);
        await montar(tester, centro, api: api);
        await tester.pump();

        // Falló: sin fondo (ni el OSM público), y los valores por defecto para lo demás.
        expect(api.consultas, 1);
        expect(find.byType(TileLayer), findsNothing);

        await tester.pump(const Duration(seconds: 29));
        expect(api.consultas, 1, reason: 'todavía no pasaron 30 s');

        await tester.pump(const Duration(seconds: 1));
        await tester.pump();
        expect(api.consultas, 2);
        expect(find.byType(TileLayer), findsNothing, reason: 'volvió a fallar: sigue sin fondo');

        await tester.pump(const Duration(seconds: 30));
        await tester.pump();
        expect(api.consultas, 3);
        expect(capa(tester).urlTemplate, 'https://mapas.ejemplo.gob.ar/{z}/{x}/{y}.png');
        expect(find.text('Mapa propio'), findsOneWidget);
        expect(teselas.pedidas, greaterThan(0));
        expect(find.textContaining('OpenStreetMap'), findsNothing);

        // Con la configuración cargada no reintenta más.
        await tester.pump(const Duration(seconds: 90));
        expect(api.consultas, 3);
      });

      testWidgets('si GET /configuracion falla, también reintenta al volver a la app', (tester) async {
        final api = _ApiConfiguracion([const SinConexion(), _configuracionPropia]);
        await montar(tester, centro, api: api);
        await tester.pump();
        expect(find.byType(TileLayer), findsNothing);

        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
        tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
        await tester.pump();
        await tester.pump();

        expect(api.consultas, 2);
        expect(capa(tester).urlTemplate, 'https://mapas.ejemplo.gob.ar/{z}/{x}/{y}.png');
      });

      testWidgets('un backend anterior, que no manda el mapa de fondo, sigue con el OSM público', (tester) async {
        final anterior = Configuracion.fromJson(p.json(p.configuracion)..remove('teselas'));
        await montar(tester, centro, configuracion: AsyncData(anterior));

        expect(capa(tester).urlTemplate, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
      });
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
            overrides: [configuracionProvider.overrideWithValue(const AsyncData(_configuracionOsm))],
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

    test('las líneas son polilíneas con su color y ancho; las de menos de dos puntos no se dibujan', () {
      final polilineas = MapaGoogle.polilineasDe([
        LineaMapa.recorrido(const [a, a2, b]),
        const LineaMapa(id: 'otra', puntos: [a, b], color: Colors.red, ancho: 2.6),
        const LineaMapa(id: 'sola', puntos: [a], color: Colors.red, ancho: 3),
      ]);

      expect(polilineas.map((p) => p.polylineId.value), ['recorrido', 'otra']);
      final recorrido = polilineas.first;
      expect(recorrido.points, const [gm.LatLng(-26.8, -65.2), gm.LatLng(-26.8001, -65.2001), gm.LatLng(-27.0, -65.4)]);
      expect(recorrido.color, LineaMapa.colorRecorrido);
      expect(recorrido.width, 5);
      expect(recorrido.zIndex, lessThan(1), reason: 'debajo de los marcadores');
      expect(polilineas.last.width, 3);
      expect(polilineas.last.color, Colors.red);
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

  test('el recorrido es una línea azul de 5 px', () {
    final l = LineaMapa.recorrido(const [Coordenada(-26.8, -65.2), Coordenada(-27, -65.4)]);
    expect(l.id, 'recorrido');
    expect(l.color, Colors.blue);
    expect(l.ancho, 5);
    expect(l.puntos, const [Coordenada(-26.8, -65.2), Coordenada(-27, -65.4)]);
    expect(const DatosMapa(centro: Coordenada(0, 0)).lineas, isEmpty);
  });

  testWidgets('el mapa de prueba deja leer sus líneas', (tester) async {
    final linea = LineaMapa.recorrido(const [Coordenada(-26.8, -65.2), Coordenada(-27, -65.4)]);
    Future<void> mostrar(List<LineaMapa> lineas) => tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (c) => mapaDePrueba(c, DatosMapa(centro: const Coordenada(-26.8, -65.2), lineas: lineas)),
        ),
      ),
    );
    await mostrar(const []);
    expect(lineasDelMapa(tester), isEmpty);
    await mostrar([linea]);
    expect(lineasDelMapa(tester), [linea]);
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
  group('teselas sin señal', () {
    late _ServidorTeselas servidor;
    late MemCacheStore almacen;

    setUp(() {
      servidor = _ServidorTeselas();
      almacen = MemCacheStore();
    });

    List<Override> overrides({
      AsyncValue<Configuracion> configuracion = const AsyncData(_configuracionOsm),
      String claveGoogle = '',
      bool conDisco = true,
    }) => [
      entornoProvider.overrideWithValue(_entornoConClave(claveGoogle)),
      configuracionProvider.overrideWithValue(configuracion),
      almacenTeselasProvider.overrideWithValue(conDisco ? almacen : null),
      adaptadorTeselasProvider.overrideWithValue(servidor),
    ];

    testWidgets('sin un proveedor de prueba, las teselas pasan por el caché en disco', (tester) async {
      tester.view.physicalSize = const Size(800, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        ProviderScope(
          overrides: overrides(),
          child: const MaterialApp(
            home: Scaffold(
              body: MapaOsm(datos: DatosMapa(centro: Coordenada(-26.8241, -65.2226))),
            ),
          ),
        ),
      );
      await tester.pump();
      final proveedor = tester.widget<TileLayer>(find.byType(TileLayer)).tileProvider;
      expect(proveedor, isA<CachedTileProvider>());
      expect(proveedor.headers['User-Agent'], 'flutter_map (${MapaOsm.agenteUsuario})');

      // Las bajadas a la red quedan guardadas.
      for (var i = 0; i < 5 && servidor.pedidas.isEmpty; i++) {
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 50)));
        await tester.pump(const Duration(milliseconds: 100));
      }
      expect(servidor.pedidas, isNotEmpty);
      expect(servidor.pedidas.first.host, 'tile.openstreetmap.org');
      expect(
        await tester.runAsync(() => almacen.exists(CacheOptions.defaultCacheKeyBuilder(url: servidor.pedidas.first))),
        isTrue,
      );
    });

    testWidgets('sin disco (web), las teselas van directo a la red', (tester) async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: overrides(conDisco: false),
          child: const MaterialApp(
            home: Scaffold(
              body: MapaOsm(datos: DatosMapa(centro: Coordenada(-26.8241, -65.2226))),
            ),
          ),
        ),
      );
      await tester.pump();
      expect(tester.widget<TileLayer>(find.byType(TileLayer)).tileProvider, isNot(isA<CachedTileProvider>()));
    });

    group('descargador', () {
      test('baja una tesela del servidor configurado al caché, una sola vez', () async {
        final contenedor = ProviderContainer.test(
          overrides: overrides(configuracion: const AsyncData(_configuracionPropia)),
        );
        final descargador = contenedor.read(descargadorTeselasProvider)!;
        expect(descargador.zoomMaximo, 19);

        await descargador.descargar(const Tesela(14, 5223, 9460));
        await descargador.descargar(const Tesela(14, 5223, 9460));

        expect(servidor.pedidas, [Uri.parse('https://mapas.ejemplo.gob.ar/14/5223/9460.png')]);
        expect(servidor.agentes.single, 'flutter_map (${MapaOsm.agenteUsuario})');
        expect(await almacen.exists(CacheOptions.defaultCacheKeyBuilder(url: servidor.pedidas.single)), isTrue);
      });

      test('en un servidor TMS la fila va invertida, como la pide el mapa', () async {
        const tms = Configuracion(
          gpsTurnoSeg: 10,
          gpsViajeSeg: 5,
          ofertaSegundos: 30,
          teselas: MapaFondo(
            url: 'https://mapas.ejemplo.gob.ar/tms/{z}/{x}/{y}.png',
            atribucion: 'IGN',
            tms: true,
            maxZoom: 13,
          ),
        );
        final contenedor = ProviderContainer.test(overrides: overrides(configuracion: const AsyncData(tms)));
        final descargador = contenedor.read(descargadorTeselasProvider)!;
        expect(descargador.zoomMaximo, 13);
        await descargador.descargar(const Tesela(3, 2, 1));
        expect(servidor.pedidas, [Uri.parse('https://mapas.ejemplo.gob.ar/tms/3/2/6.png')]);
      });

      test('una tesela que no llega es un error (la descarga la saltea)', () async {
        servidor.estado = 500;
        final contenedor = ProviderContainer.test(
          overrides: overrides(configuracion: const AsyncData(_configuracionPropia)),
        );
        await expectLater(
          contenedor.read(descargadorTeselasProvider)!.descargar(const Tesela(1, 0, 0)),
          throwsA(anything),
        );
      });

      test('nulo con el mapa de Google, sin disco, sin mapa de fondo o con el OSM público', () {
        DescargadorTeselas? descargador({String claveGoogle = '', bool conDisco = true}) => ProviderContainer.test(
          overrides: overrides(
            configuracion: const AsyncData(_configuracionPropia),
            claveGoogle: claveGoogle,
            conDisco: conDisco,
          ),
        ).read(descargadorTeselasProvider);
        expect(descargador(), isNotNull);
        expect(descargador(claveGoogle: 'AIza'), isNull);
        expect(descargador(conDisco: false), isNull);
        // Su política de uso no admite bajar teselas por adelantado (solo se guardan las que se ven).
        expect(ProviderContainer.test(overrides: overrides()).read(descargadorTeselasProvider), isNull);
        final sinFondo = ProviderContainer.test(overrides: overrides(configuracion: const AsyncLoading()));
        expect(sinFondo.read(descargadorTeselasProvider), isNull);
      });
    });
  });
}
