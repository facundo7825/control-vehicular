import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  late ApiFalsa api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiFalsa();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear({Usuario usuario = solicitante}) {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(usuario),
    ]);
    c.listen(viajeActualProvider, (_, _) {});
    return c;
  }

  SeguimientoViaje leer(ProviderContainer c) => c.read(viajeActualProvider).requireValue;

  test('carga el viaje actual y escucha su canal', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      expect(leer(c).viaje!.estado, EstadoViaje.ofrecido);
      expect(tr.canalesActivos, {'viaje.1'});
    });
  });

  test('con el socket conectado se actualiza por eventos y no consulta la API', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(p.viajeAceptado));
      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
      async.elapse(const Duration(seconds: 30));

      expect(leer(c).viaje!.estado, EstadoViaje.aceptado);
      expect(leer(c).viaje!.chofer!.nombre, 'Carlos Gómez');
      expect(leer(c).ubicacionChofer!.posicion, const Coordenada(-26.8301, -65.2001));
      expect(api.consultasActual, 1);
    });
  });

  test('con el socket caído consulta cada 10 s y mantiene el viaje al día', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      api.listaChoferes = [ChoferEnMapa.fromJson(leerMapa(p.jsonLista(p.choferes).single))];
      final c = crear();
      async.flushMicrotasks();

      tr.cambiar(EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      async.elapse(const Duration(seconds: 9));
      expect(leer(c).viaje!.estado, EstadoViaje.aceptado);

      async.elapse(const Duration(seconds: 1));
      expect(leer(c).viaje!.estado, EstadoViaje.enCamino);
      expect(leer(c).ubicacionChofer!.posicion, const Coordenada(-26.8301, -65.2001));

      api.actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
      async.elapse(const Duration(seconds: 10));
      expect(leer(c).viaje!.estado, EstadoViaje.llego);
      expect(api.consultasActual, 3);
    });
  });

  test('al reconectar pide el estado completo y deja de consultar', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();
      async.elapse(const Duration(seconds: 10));
      expect(api.consultasActual, 2);

      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      tr.cambiar(EstadoConexion.conectado);
      async.flushMicrotasks();
      expect(api.consultasActual, 3);
      expect(leer(c).viaje!.estado, EstadoViaje.aceptado);

      async.elapse(const Duration(seconds: 60));
      expect(api.consultasActual, 3);
    });
  });

  test('si el viaje desaparece mientras no había socket, muestra cómo terminó', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      api.actual = ViajeActual.vacio;
      api.mis = MisViajes(
        proximas: const [],
        historial: [viaje(estado: 'sin_chofer')],
      );
      async.elapse(const Duration(seconds: 10));

      expect(leer(c).viaje!.estado, EstadoViaje.sinChofer);
      expect(tr.canalesActivos, isEmpty);
    });
  });

  test('un error de red durante el respaldo conserva el último estado', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      api.fallarConsultas = const SinConexion();
      async.elapse(const Duration(seconds: 20));

      expect(leer(c).viaje!.estado, EstadoViaje.ofrecido);
      expect(c.read(viajeActualProvider).hasError, isFalse);
    });
  });

  test('pedir guarda el viaje y lo sigue; cancelar lo deja cancelado; descartar vuelve a cero', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();
      api.respuestaPedido = viaje(id: 7, estado: 'buscando');

      c
          .read(viajeActualProvider.notifier)
          .pedir(
            const PedidoViaje(
              modo: ModoViaje.masCercano,
              origen: Lugar(Coordenada(1, 1)),
              destino: Lugar(Coordenada(2, 2)),
            ),
          );
      async.flushMicrotasks();
      expect(leer(c).viaje!.id, 7);
      expect(tr.canalesActivos, {'viaje.7'});

      c.read(viajeActualProvider.notifier).cancelar();
      async.flushMicrotasks();
      expect(api.cancelaciones.single, (7, null));
      expect(leer(c).viaje!.estado, EstadoViaje.cancelado);
      expect(tr.canalesActivos, isEmpty);

      c.read(viajeActualProvider.notifier).descartar();
      expect(leer(c).viaje, isNull);
    });
  });

  test('un viaje terminado que se recuperó del historial se conserva al refrescar hasta descartarlo', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      api.actual = ViajeActual.vacio;
      api.mis = MisViajes(
        proximas: const [],
        historial: [viaje(estado: 'sin_chofer')],
      );
      async.elapse(const Duration(seconds: 10));
      expect(leer(c).viaje!.estado, EstadoViaje.sinChofer);

      async.elapse(const Duration(seconds: 30));
      expect(leer(c).viaje!.estado, EstadoViaje.sinChofer);

      tr.cambiar(EstadoConexion.conectado);
      async.flushMicrotasks();
      expect(leer(c).viaje!.id, 1);
      expect(leer(c).viaje!.estado, EstadoViaje.sinChofer);

      c.read(viajeActualProvider.notifier).descartar();
      expect(leer(c).viaje, isNull);
    });
  });

  test('un 401 durante el sondeo no produce un error sin capturar y se conserva el estado', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      api.fallarConsultas = const SesionInvalida();
      async.elapse(const Duration(seconds: 20));
      async.flushMicrotasks();

      expect(api.consultasActual, 3);
      expect(leer(c).viaje!.estado, EstadoViaje.ofrecido);
    });
  });

  test('ignora eventos de otros viajes y ubicaciones de otros choferes', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('viaje.1', Eventos.viajeActualizado, jsonViaje(viaje(id: 99, estado: 'finalizado')));
      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion)..['chofer_id'] = 55);

      expect(leer(c).viaje!.id, 1);
      expect(leer(c).ubicacionChofer, isNull);
    });
  });

  group('chofer', () {
    test('escucha su canal: una oferta inmediata aparece y una de reserva no', () {
      fakeAsync((async) {
        final c = crear(usuario: chofer);
        async.flushMicrotasks();
        expect(tr.canalesActivos, {'chofer.2'});

        tr.emitir('chofer.2', Eventos.ofertaCreada, p.json(p.eventoOfertaCreada)); // es de una reserva
        expect(leer(c).oferta, isNull);

        final inmediata = p.json(p.viajeActualChofer)['oferta'] as Map<String, dynamic>;
        tr.emitir('chofer.2', Eventos.ofertaCreada, {...inmediata, 'oferta_id': inmediata['id']});
        expect(leer(c).oferta!.id, 1);
        expect(leer(c).oferta!.venceEn, DateTime.utc(2026, 10, 1, 12, 0, 30));
      });
    });

    test(
      'la oferta se va cuando el viaje deja de estar ofrecido y un obligatorio asignado se vuelve el viaje actual',
      () {
        fakeAsync((async) {
          api.actual = ViajeActual.fromJson(p.json(p.viajeActualChofer));
          final c = crear(usuario: chofer);
          async.flushMicrotasks();
          expect(leer(c).oferta, isNotNull);

          tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'buscando'), conChofer: false));
          expect(leer(c).oferta, isNull);
          expect(leer(c).viaje, isNull);

          tr.emitir(
            'chofer.2',
            Eventos.viajeActualizado,
            jsonViaje(viaje(id: 3, estado: 'aceptado', obligatorio: true)),
          );
          expect(leer(c).viaje!.id, 3);
          expect(leer(c).viaje!.obligatorio, isTrue);
        });
      },
    );
  });
}
