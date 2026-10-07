import 'dart:async';
import 'dart:math' as math;

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/mapa/corredor_teselas.dart';
import 'package:vehiculos_oficiales/src/modelos/comunes.dart';

const _metrosPorGrado = 6371000 * math.pi / 180;

/// Un punto a [norte] y [este] metros de [desde].
Coordenada mover(Coordenada desde, double norte, [double este = 0]) => Coordenada(
  desde.lat + norte / _metrosPorGrado,
  desde.lng + este / (_metrosPorGrado * math.cos(desde.lat * math.pi / 180)),
);

const tucuman = Coordenada(-26.8241, -65.2226);

void main() {
  group('teselaDe', () {
    test('las teselas XYZ de OpenStreetMap (y hacia el sur)', () {
      expect(teselaDe(const Coordenada(0.1, 0.1), 1), const Tesela(1, 1, 0));
      expect(teselaDe(const Coordenada(-0.1, -0.1), 1), const Tesela(1, 0, 1));
      // Londres, zoom 10: la tesela 511/340 de tile.openstreetmap.org.
      expect(teselaDe(const Coordenada(51.5074, -0.1278), 10), const Tesela(10, 511, 340));
      // Sídney, zoom 10 (hemisferio sur): 942/614.
      expect(teselaDe(const Coordenada(-33.8688, 151.2093), 10), const Tesela(10, 942, 614));
    });

    test('los polos y el antimeridiano quedan dentro de la grilla', () {
      expect(teselaDe(const Coordenada(89.9, 180), 3), const Tesela(3, 7, 0));
      expect(teselaDe(const Coordenada(-89.9, -180), 3), const Tesela(3, 0, 7));
    });
  });

  group('teselasDelCorredor', () {
    // 30 km al norte, un punto cada 10 km (los tramos se recorren entre puntos).
    final recorrido = [for (var i = 0; i <= 3; i++) mover(tucuman, i * 10000.0)];

    test('cubre el recorrido en los zooms 10 a 14, también entre los puntos', () {
      final teselas = teselasDelCorredor(recorrido).toSet();
      for (var z = 10; z <= 14; z++) {
        for (var metros = 0.0; metros <= 30000; metros += 250) {
          expect(teselas, contains(teselaDe(mover(tucuman, metros), z)), reason: 'z$z a $metros m');
        }
      }
      expect(teselas.map((t) => t.z).toSet(), {10, 11, 12, 13, 14});
    });

    test('un corredor de unos 2 km: 1 km a cada lado, no más allá de unos 3 km', () {
      final teselas = teselasDelCorredor(recorrido).toSet();
      for (final metros in <double>[0, 5000, 15000, 30000]) {
        expect(teselas, contains(teselaDe(mover(tucuman, metros, 950), 14)));
        expect(teselas, contains(teselaDe(mover(tucuman, metros, -950), 14)));
        expect(teselas, isNot(contains(teselaDe(mover(tucuman, metros, 4500), 14))));
        expect(teselas, isNot(contains(teselaDe(mover(tucuman, metros, -4500), 14))));
      }
      // Ni más allá de las puntas.
      expect(teselas, isNot(contains(teselaDe(mover(tucuman, -4500), 14))));
      expect(teselas, isNot(contains(teselaDe(mover(tucuman, 34500), 14))));
    });

    test('sin repetidas, de los zooms más lejanos a los más cercanos', () {
      final teselas = teselasDelCorredor(recorrido);
      expect(teselas.toSet().length, teselas.length);
      final zooms = teselas.map((t) => t.z).toList();
      expect(zooms, [...zooms]..sort());
      // Un tramo de ida y vuelta no agrega teselas.
      expect(teselasDelCorredor([...recorrido, ...recorrido.reversed]).toSet(), teselas.toSet());
    });

    test('un solo punto: su alrededor; sin puntos, nada', () {
      final teselas = teselasDelCorredor([tucuman]);
      expect(teselas, contains(teselaDe(tucuman, 14)));
      expect(teselas.length, lessThan(30));
      expect(teselasDelCorredor(const []), isEmpty);
    });

    test('un recorrido larguísimo se corta en 3000 teselas, con los zooms lejanos completos', () {
      // 3000 km al sur (más de 6000 teselas sin el límite).
      final largo = [for (var i = 0; i <= 300; i++) mover(tucuman, -i * 10000.0)];
      final teselas = teselasDelCorredor(largo);
      expect(teselas, hasLength(maxTeselasCorredor));
      expect(maxTeselasCorredor, 3000);
      final z10 = teselas.where((t) => t.z == 10).toSet();
      for (var metros = 0.0; metros <= 3000000; metros += 5000) {
        expect(z10, contains(teselaDe(mover(tucuman, -metros), 10)));
      }
      expect(teselasDelCorredor(largo, maximo: 100), hasLength(100));
    });

    test('se pueden pedir otros zooms (p. ej. un servidor que llega hasta el 12)', () {
      final teselas = teselasDelCorredor(recorrido, zoomMaximo: 12);
      expect(teselas.map((t) => t.z).toSet(), {10, 11, 12});
    });
  });

  group('descargarTeselas', () {
    final teselas = [for (var x = 0; x < 20; x++) Tesela(14, x, 0)];

    test('baja todas, de a 4 a la vez como mucho', () async {
      var enVuelo = 0;
      var maximo = 0;
      final bajadas = <Tesela>[];
      final hechas = await descargarTeselas(teselas, (t) async {
        enVuelo++;
        maximo = math.max(maximo, enVuelo);
        await Future<void>.delayed(const Duration(milliseconds: 1));
        enVuelo--;
        bajadas.add(t);
      });
      expect(hechas, 20);
      expect(bajadas.toSet(), teselas.toSet());
      expect(maximo, 4);
    });

    test('las que fallan se saltean; después de 10 fallas seguidas (sin señal) deja de intentar', () async {
      var pedidas = 0;
      final hechas = await descargarTeselas(teselas, (t) async {
        pedidas++;
        if (t.x.isOdd) throw Exception('sin red');
      }, concurrencia: 1);
      expect(hechas, 10);
      expect(pedidas, 20);

      pedidas = 0;
      final ninguna = await descargarTeselas([for (var x = 0; x < 100; x++) Tesela(14, x, 0)], (t) async {
        pedidas++;
        throw Exception('sin red');
      }, concurrencia: 1);
      expect(ninguna, 0);
      expect(pedidas, 10);
    });

    test('cancelada, no pide más (las que estaban en vuelo terminan)', () async {
      final esperas = <Completer<void>>[];
      var cancelada = false;
      final descarga = descargarTeselas(teselas, (t) {
        final c = Completer<void>();
        esperas.add(c);
        return c.future;
      }, cancelada: () => cancelada);
      await Future<void>.delayed(Duration.zero);
      expect(esperas, hasLength(4));
      cancelada = true;
      for (final c in esperas.toList()) {
        c.complete();
      }
      await descarga;
      expect(esperas, hasLength(4));
    });

    test('avisa el avance', () async {
      final avances = <int>[];
      await descargarTeselas(teselas.take(3).toList(), (t) async {}, alAvanzar: avances.add);
      expect(avances, [1, 2, 3]);
    });
  });
}
