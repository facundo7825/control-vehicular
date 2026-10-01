import 'dart:async';

import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart' show configuracionProvider;
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/solicitante/borrador_pedido.dart';
import 'package:vehiculos_oficiales/src/solicitante/busqueda_lugares.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

const _aqui = Coordenada(-26.8241, -65.2226);
const _tribunales = LugarEncontrado(
  nombre: 'Tribunales',
  direccion: 'Tribunales, Tucumán',
  coordenada: Coordenada(1, 1),
);

void main() {
  late ApiFalsa api;
  late UbicadorFalso ubicador;

  setUp(() {
    api = ApiFalsa()..lugares = [_tribunales];
    ubicador = UbicadorFalso(_aqui);
  });

  ProviderContainer crear({bool autocompletar = true}) {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      ubicadorProvider.overrideWithValue(ubicador),
      configuracionProvider.overrideWithValue(
        AsyncData(
          Configuracion(gpsTurnoSeg: 10, gpsViajeSeg: 5, ofertaSegundos: 30, lugaresAutocompletar: autocompletar),
        ),
      ),
    ]);
    c.listen(busquedaLugaresProvider, (_, _) {});
    return c;
  }

  BusquedaLugares leer(ProviderContainer c) => c.read(busquedaLugaresProvider);

  void escribir(ProviderContainer c, String texto) => c.read(busquedaLugaresProvider.notifier).escribir(texto);

  test('una sola consulta 400 ms después de dejar de escribir, sesgada a la ubicación actual', () {
    fakeAsync((async) {
      final c = crear();
      c.read(borradorPedidoProvider.notifier).ubicar();
      async.flushMicrotasks();

      for (final t in ['t', 'tr', 'tri', 'trib', 'tribu']) {
        escribir(c, t);
        async.elapse(const Duration(milliseconds: 100));
      }
      expect(leer(c).estado, EstadoBusqueda.buscando);
      async.elapse(const Duration(milliseconds: 299));
      expect(api.busquedas, isEmpty);

      async.elapse(const Duration(milliseconds: 1));
      async.flushMicrotasks();

      expect(api.busquedas, [('tribu', _aqui)]);
      expect(leer(c).estado, EstadoBusqueda.lista);
      expect(leer(c).resultados, [_tribunales]);
    });
  });

  test('recuerda para qué punto se empezó a escribir, aunque después cambie lo que se marca', () {
    fakeAsync((async) {
      final c = crear();
      final borrador = c.read(borradorPedidoProvider.notifier);
      expect(leer(c).punto, isNull);

      escribir(c, 't');
      expect(leer(c).punto, PuntoPedido.destino);
      borrador.marcarAhora(PuntoPedido.origen); // p. ej. la ubicación no llegó
      escribir(c, 'tribu');
      expect(leer(c).punto, PuntoPedido.destino);

      escribir(c, '');
      expect(leer(c).punto, isNull);
      escribir(c, 'tri');
      expect(leer(c).punto, PuntoPedido.origen);
      c.read(busquedaLugaresProvider.notifier).limpiar();
      expect(leer(c).punto, isNull);
    });
  });

  test('con menos de 3 letras no consulta y borra las sugerencias', () {
    fakeAsync((async) {
      final c = crear();
      escribir(c, 'tribu');
      async.elapse(const Duration(milliseconds: 400));
      expect(leer(c).resultados, [_tribunales]);

      escribir(c, 'tr');
      async.elapse(const Duration(seconds: 1));

      expect(api.busquedas, hasLength(1));
      expect(leer(c).estado, EstadoBusqueda.inactiva);
      expect(leer(c).resultados, isEmpty);
    });
  });

  test('sin ubicación busca sin sesgo', () {
    fakeAsync((async) {
      ubicador.posicion = null;
      final c = crear();
      c.read(borradorPedidoProvider.notifier).ubicar();
      async.flushMicrotasks();

      escribir(c, 'tribu');
      async.elapse(const Duration(milliseconds: 400));

      expect(api.busquedas, [('tribu', null)]);
    });
  });

  test('un error de la búsqueda es "sin resultados", sin excepción', () {
    fakeAsync((async) {
      api.errorLugares = const SinConexion();
      final c = crear();

      escribir(c, 'tribu');
      async.elapse(const Duration(milliseconds: 400));

      expect(leer(c).estado, EstadoBusqueda.lista);
      expect(leer(c).resultados, isEmpty);
    });
  });

  test('con autocompletar, buscar ya no espera los 400 ms', () {
    fakeAsync((async) {
      final c = crear();
      escribir(c, 'tribu');
      c.read(busquedaLugaresProvider.notifier).buscarAhora();
      async.flushMicrotasks();

      expect(api.busquedas.map((b) => b.$1), ['tribu']);
      expect(leer(c).estado, EstadoBusqueda.lista);
      async.elapse(const Duration(seconds: 1));
      expect(api.busquedas, hasLength(1));
    });
  });

  group('sin autocompletar (Nominatim)', () {
    test('escribir no consulta; buscar sí, y muestra los resultados', () {
      fakeAsync((async) {
        final c = crear(autocompletar: false);
        escribir(c, 'tribu');
        async.elapse(const Duration(seconds: 2));
        expect(api.busquedas, isEmpty);
        expect(leer(c).estado, EstadoBusqueda.inactiva);
        expect(leer(c).texto, 'tribu');
        expect(leer(c).punto, PuntoPedido.destino);

        api.demorarLugares = Completer();
        c.read(busquedaLugaresProvider.notifier).buscarAhora();
        expect(leer(c).estado, EstadoBusqueda.buscando);
        api.demorarLugares!.complete();
        async.flushMicrotasks();

        expect(api.busquedas.map((b) => b.$1), ['tribu']);
        expect(leer(c).estado, EstadoBusqueda.lista);
        expect(leer(c).resultados, [_tribunales]);

        // Seguir escribiendo deja los resultados viejos de lado hasta volver a buscar.
        escribir(c, 'tribuna');
        async.elapse(const Duration(seconds: 1));
        expect(api.busquedas, hasLength(1));
        expect(leer(c).estado, EstadoBusqueda.inactiva);
      });
    });

    test('buscar con menos de 3 letras no consulta', () {
      fakeAsync((async) {
        final c = crear(autocompletar: false);
        escribir(c, 'tr');
        c.read(busquedaLugaresProvider.notifier).buscarAhora();
        async.flushMicrotasks();

        expect(api.busquedas, isEmpty);
        expect(leer(c).estado, EstadoBusqueda.inactiva);
      });
    });

    test('si la configuración no llegó, no autocompleta', () {
      fakeAsync((async) {
        final c = EntornoPrueba().contenedor([
          apiProvider.overrideWithValue(api),
          ubicadorProvider.overrideWithValue(ubicador),
          configuracionProvider.overrideWithValue(const AsyncLoading()),
        ]);
        c.listen(busquedaLugaresProvider, (_, _) {});
        escribir(c, 'tribu');
        async.elapse(const Duration(seconds: 1));

        expect(api.busquedas, isEmpty);
      });
    });
  });

  test('la respuesta de un texto que ya cambió se descarta; limpiar cancela la espera', () {
    fakeAsync((async) {
      final c = crear();
      api.demorarLugares = Completer();
      escribir(c, 'tribu');
      async.elapse(const Duration(milliseconds: 400)); // consulta en curso
      escribir(c, 'tribun');
      api.demorarLugares!.complete();
      async.flushMicrotasks();
      expect(leer(c).resultados, isEmpty);
      expect(leer(c).estado, EstadoBusqueda.buscando);
      expect(leer(c).texto, 'tribun');

      c.read(busquedaLugaresProvider.notifier).limpiar();
      async.elapse(const Duration(seconds: 1));

      expect(api.busquedas.map((b) => b.$1), ['tribu']);
      expect(leer(c).estado, EstadoBusqueda.inactiva);
    });
  });
}
