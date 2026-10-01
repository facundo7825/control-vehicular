import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/ruta.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

const _plaza = Coordenada(-26.8241, -65.2226);
const _tribunales = Coordenada(-26.8083, -65.2176);

void main() {
  late ApiFalsa api;

  setUp(() => api = ApiFalsa()..ruta = Ruta.fromJson(p.json(p.ruta)));

  ProviderContainer crear() => EntornoPrueba().contenedor([apiProvider.overrideWithValue(api)]);

  Future<Ruta?> leer(ProviderContainer c, TramoRuta tramo) {
    c.listen(rutaProvider(tramo), (_, _) {});
    return c.read(rutaProvider(tramo).future);
  }

  test('el tramo redondea origen y destino a 4 decimales (unos 11 m)', () {
    final t = TramoRuta(const Coordenada(-26.82412, -65.22258), const Coordenada(-26.80834, -65.21761));
    expect(t.origen, _plaza);
    expect(t.destino, _tribunales);
    expect(t, TramoRuta(_plaza, _tribunales));
    expect(t.hashCode, TramoRuta(_plaza, _tribunales).hashCode);
    expect(t, isNot(TramoRuta(_tribunales, _plaza)));
  });

  test('pide la ruta con las coordenadas redondeadas y la misma no se vuelve a pedir', () async {
    final c = crear();

    final r = await leer(c, TramoRuta(const Coordenada(-26.82412, -65.22258), _tribunales));
    final otra = await leer(c, TramoRuta(const Coordenada(-26.82408, -65.22262), _tribunales));

    expect(r, isNotNull);
    expect(otra, same(r));
    expect(api.consultasRuta, [(_plaza, _tribunales)]);

    await leer(c, TramoRuta(_tribunales, _plaza));
    expect(api.consultasRuta, hasLength(2), reason: 'otro tramo es otra consulta');
  });

  test('sin recorrido disponible el valor es nulo', () async {
    api.ruta = null;
    final c = crear();

    expect(await leer(c, TramoRuta(_plaza, _tribunales)), isNull);
  });

  test('si la API falla el valor es nulo, sin error', () async {
    api.errorRuta = const SinConexion();
    final c = crear();

    final tramo = TramoRuta(_plaza, _tribunales);
    expect(await leer(c, tramo), isNull);
    expect(c.read(rutaProvider(tramo)), isA<AsyncData<Ruta?>>());
  });

  test('con origen y destino en el mismo lugar no pide nada', () async {
    final c = crear();

    expect(await leer(c, TramoRuta(_plaza, const Coordenada(-26.82411, -65.22261))), isNull);
    expect(api.consultasRuta, isEmpty);
  });

  group('resumen del recorrido', () {
    Ruta ruta(double metros, double segundos) =>
        Ruta(distanciaM: metros, duracionS: segundos, puntos: const [], pasos: const []);

    test('minutos redondeados hacia arriba y kilómetros con coma', () {
      expect(resumenRuta(ruta(5300, 691)), '≈ 12 min · 5,3 km');
      expect(resumenRuta(ruta(1830, 240.5)), '≈ 5 min · 1,8 km');
    });

    test('menos de un kilómetro, en metros', () {
      expect(resumenRuta(ruta(734, 120)), '≈ 2 min · 730 m');
    });

    test('una hora o más, en horas y minutos', () {
      expect(formatearDuracion(3900), '1 h 5 min');
      expect(formatearDuracion(3600), '1 h');
      expect(formatearDuracion(7261), '2 h 2 min');
    });

    test('menos de un minuto es 1 min', () {
      expect(formatearDuracion(20), '1 min');
      expect(formatearDuracion(0), '1 min');
    });
  });
}
