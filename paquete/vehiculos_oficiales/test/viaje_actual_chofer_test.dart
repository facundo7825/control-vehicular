import 'dart:async';

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
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  late ApiChofer api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiChofer();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(chofer),
    ]);
    c.listen(viajeActualProvider, (_, _) {});
    return c;
  }

  SeguimientoViaje leer(ProviderContainer c) => c.read(viajeActualProvider).requireValue;

  group('avanzar', () {
    test('manda el paso y aplica la respuesta', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.enCamino);
        async.flushMicrotasks();

        expect(api.avances.single, (1, EstadoViaje.enCamino));
        expect(leer(c).viaje!.estado, EstadoViaje.enCamino);
      });
    });

    test('una respuesta que llega después de un "cancelado" no lo pisa', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'cancelado', conChofer: true)));
        c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.llego);
        async.flushMicrotasks();

        expect(leer(c).viaje!.estado, EstadoViaje.cancelado);
      });
    });

    test('cancelar como chofer: el viaje vuelve sin chofer (no cuenta como retroceso)', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        c.read(viajeActualProvider.notifier).cancelar(motivo: 'Se rompió el auto');
        async.flushMicrotasks();

        expect(api.cancelaciones.single, (1, 'Se rompió el auto'));
        expect(leer(c).viaje!.chofer, isNull);
        expect(leer(c).viaje!.estado, EstadoViaje.buscando);
      });
    });
  });

  test('una consulta del respaldo que empezó antes de una novedad no la pisa', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      final c = crear();
      async.flushMicrotasks();

      api.demoraActual = Completer<void>();
      async.elapse(const Duration(seconds: 10)); // empieza la consulta, que ve "aceptado"
      c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.enCamino);
      async.flushMicrotasks();
      // El servidor ya está en "enCamino" (la consulta en vuelo vio "aceptado" al empezar): la repetición
      // que sigue a la descartada trae el estado nuevo.
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      api.demoraActual!.complete();
      async.flushMicrotasks();

      expect(leer(c).viaje!.estado, EstadoViaje.enCamino);

      api
        ..demoraActual = null
        ..actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
      async.elapse(const Duration(seconds: 10)); // la siguiente sí se aplica
      expect(leer(c).viaje!.estado, EstadoViaje.llego);
    });
  });

  group('oferta', () {
    ProviderContainer conOferta(FakeAsync async) {
      api.actual = ViajeActual.fromJson(p.json(p.viajeActualChofer));
      final c = crear();
      async.flushMicrotasks();
      expect(leer(c).oferta!.id, 1);
      return c;
    }

    test('aceptar: queda el viaje, se va la oferta y no es "asignado sin oferta"', () {
      fakeAsync((async) {
        final c = conOferta(async);

        c.read(viajeActualProvider.notifier).aceptarOferta();
        async.flushMicrotasks();

        expect(api.llamadas, contains('aceptar:1'));
        expect(leer(c).oferta, isNull);
        expect(leer(c).viaje!.estado, EstadoViaje.aceptado);
        expect(leer(c).asignadoSinOferta, isFalse);
      });
    });

    test('si el evento "aceptado" llega antes que la respuesta, tampoco es "asignado sin oferta"', () {
      fakeAsync((async) {
        final c = conOferta(async);

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'aceptado', conChofer: true)));
        c.read(viajeActualProvider.notifier).aceptarOferta(); // la oferta ya no está: no hace nada
        async.flushMicrotasks();

        expect(leer(c).viaje!.estado, EstadoViaje.aceptado);
        expect(leer(c).asignadoSinOferta, isFalse);
      });
    });

    test('rechazar la quita; un 422 también la quita y llega a quien llamó', () {
      fakeAsync((async) {
        var c = conOferta(async);
        c.read(viajeActualProvider.notifier).rechazarOferta();
        async.flushMicrotasks();
        expect(api.llamadas, contains('rechazar:1'));
        expect(leer(c).oferta, isNull);

        c = conOferta(async);
        api.errorOferta = const ErrorNegocio('La oferta ya no está vigente.');
        Object? error;
        c.read(viajeActualProvider.notifier).aceptarOferta().catchError((Object e) => error = e);
        async.flushMicrotasks();
        expect(error, isA<ErrorNegocio>());
        expect(leer(c).oferta, isNull);
      });
    });

    test('sin red al rechazar la oferta se conserva', () {
      fakeAsync((async) {
        final c = conOferta(async);
        api.errorOferta = const SinConexion();

        c.read(viajeActualProvider.notifier).rechazarOferta().catchError((Object _) {});
        async.flushMicrotasks();

        expect(leer(c).oferta!.id, 1);
      });
    });

    test('al vencer se quita', () {
      fakeAsync((async) {
        final c = conOferta(async);

        c.read(viajeActualProvider.notifier).ofertaVencida(1);

        expect(leer(c).oferta, isNull);
      });
    });
  });

  group('asignado sin oferta', () {
    test('un obligatorio aceptado que llega por el canal queda marcado hasta verlo', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();

        tr.emitir(
          'chofer.2',
          Eventos.viajeActualizado,
          jsonViaje(viaje(id: 3, estado: 'aceptado', obligatorio: true, conChofer: true)),
        );
        expect(leer(c).viaje!.id, 3);
        expect(leer(c).asignadoSinOferta, isTrue);

        tr.emitir('chofer.2', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
        expect(leer(c).asignadoSinOferta, isTrue);

        c.read(viajeActualProvider.notifier).verViajeAsignado();
        expect(leer(c).asignadoSinOferta, isFalse);
        expect(leer(c).viaje!.id, 3);
      });
    });

    test('sin socket, el viaje asignado que aparece en la consulta también queda marcado', () {
      fakeAsync((async) {
        tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
        final c = crear();
        async.flushMicrotasks();

        api.actual = ViajeActual(viaje: viaje(id: 4, estado: 'aceptado', conChofer: true));
        async.elapse(const Duration(seconds: 10));

        expect(leer(c).viaje!.id, 4);
        expect(leer(c).asignadoSinOferta, isTrue);
      });
    });

    test('un refresco por push justo después del evento no borra la marca', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();

        final asignado = viaje(id: 3, estado: 'aceptado', obligatorio: true, conChofer: true);
        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(asignado));
        api.actual = ViajeActual(viaje: asignado);
        c.read(viajeActualProvider.notifier).refrescar();
        async.flushMicrotasks();

        expect(leer(c).viaje!.id, 3);
        expect(leer(c).asignadoSinOferta, isTrue);

        c.read(viajeActualProvider.notifier).verViajeAsignado();
        expect(leer(c).asignadoSinOferta, isFalse);
        c.read(viajeActualProvider.notifier).refrescar();
        async.flushMicrotasks();
        expect(leer(c).asignadoSinOferta, isFalse);
      });
    });

    test('sin socket, dos consultas seguidas conservan la marca', () {
      fakeAsync((async) {
        tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
        final c = crear();
        async.flushMicrotasks();

        api.actual = ViajeActual(viaje: viaje(id: 4, estado: 'aceptado', conChofer: true));
        async.elapse(const Duration(seconds: 10));
        expect(leer(c).asignadoSinOferta, isTrue);
        async.elapse(const Duration(seconds: 20));
        expect(leer(c).asignadoSinOferta, isTrue);
      });
    });

    test('una oferta aceptada y después cancelada no impide marcar el mismo viaje si se lo asignan', () {
      fakeAsync((async) {
        api.actual = ViajeActual.fromJson(p.json(p.viajeActualChofer));
        final c = crear();
        async.flushMicrotasks();

        c.read(viajeActualProvider.notifier).aceptarOferta();
        async.flushMicrotasks();
        c.read(viajeActualProvider.notifier).cancelar(motivo: 'Se rompió el auto');
        async.flushMicrotasks();
        c.read(viajeActualProvider.notifier).descartar();

        tr.emitir(
          'chofer.2',
          Eventos.viajeActualizado,
          jsonViaje(viaje(estado: 'aceptado', obligatorio: true, conChofer: true)),
        );
        expect(leer(c).viaje!.id, 1);
        expect(leer(c).asignadoSinOferta, isTrue);
      });
    });

    test('el viaje que había al abrir no se marca', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        expect(leer(c).asignadoSinOferta, isFalse);
      });
    });
  });

  group('refresco completo al reconectar', () {
    /// Socket caído, viaje "aceptado"; el servidor ya está en "llego" y la consulta de la reconexión queda
    /// en vuelo (ve "llego" al empezar).
    ProviderContainer conReconexionEnVuelo(FakeAsync async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      final c = crear();
      async.flushMicrotasks();

      api.actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
      api.demoraActual = Completer<void>();
      final antes = api.consultasActual;
      tr.cambiar(EstadoConexion.conectado);
      async.flushMicrotasks();
      expect(api.consultasActual, antes + 1);
      return c;
    }

    test('un evento que cambia algo durante la consulta la descarta, y se consulta otra vez una sola vez', () {
      fakeAsync((async) {
        final c = conReconexionEnVuelo(async);
        final antes = api.consultasActual;

        final inmediata = p.json(p.viajeActualChofer)['oferta'] as Map<String, dynamic>;
        tr.emitir('chofer.2', Eventos.ofertaCreada, {...inmediata, 'oferta_id': inmediata['id']});
        api.demoraActual!.complete();
        async.flushMicrotasks();

        expect(api.consultasActual, antes + 1);
        expect(leer(c).viaje!.estado, EstadoViaje.llego);
      });
    });

    test('un evento que no cambia nada no descarta la consulta en vuelo', () {
      fakeAsync((async) {
        final c = conReconexionEnVuelo(async);
        final antes = api.consultasActual;

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'aceptado', conChofer: true)));
        api.demoraActual!.complete();
        async.flushMicrotasks();

        expect(api.consultasActual, antes); // ninguna consulta más
        expect(leer(c).viaje!.estado, EstadoViaje.llego);
      });
    });

    test('refrescar() durante una consulta en vuelo encadena exactamente una más', () {
      fakeAsync((async) {
        final c = conReconexionEnVuelo(async);
        final antes = api.consultasActual;
        final notifier = c.read(viajeActualProvider.notifier);

        notifier.refrescar();
        notifier.refrescar();
        notifier.refrescar();
        api.actual = ViajeActual(viaje: viaje(estado: 'en_curso', conChofer: true));
        api.demoraActual!.complete();
        async.flushMicrotasks();

        expect(api.consultasActual, antes + 1);
        expect(leer(c).viaje!.estado, EstadoViaje.enCurso);
      });
    });
  });

  group('un evento atrasado no revive un viaje cancelado o finalizado', () {
    for (final final_ in ['cancelado', 'finalizado']) {
      test('$final_: tras descartar, un "en_camino" tardío del mismo viaje se ignora', () {
        fakeAsync((async) {
          api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
          final c = crear();
          async.flushMicrotasks();

          tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: final_, conChofer: true)));
          c.read(viajeActualProvider.notifier).descartar();
          tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'en_camino', conChofer: true)));
          async.flushMicrotasks();

          expect(leer(c).viaje, isNull);
        });
      });
    }

    test('antes de descartar, el estado final se conserva', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'cancelado', conChofer: true)));
        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'en_camino', conChofer: true)));

        expect(leer(c).viaje!.estado, EstadoViaje.cancelado);
      });
    });

    test('un refresco que trae el viaje viejo como en_camino después de verlo cancelado se ignora', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();
        final notifier = c.read(viajeActualProvider.notifier);

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'cancelado', conChofer: true)));
        notifier.descartar();
        notifier.refrescar(); // el API todavía devuelve en_camino
        async.flushMicrotasks();

        expect(leer(c).viaje, isNull);
      });
    });

    test('la respuesta de avanzar a finalizado también lo marca como final', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_curso', conChofer: true));
        final c = crear();
        async.flushMicrotasks();
        final notifier = c.read(viajeActualProvider.notifier);

        api.respuestaAvance = viaje(estado: 'finalizado', conChofer: true);
        notifier.avanzar(EstadoViaje.finalizado);
        async.flushMicrotasks();
        notifier.descartar();
        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'en_curso', conChofer: true)));

        expect(leer(c).viaje, isNull);
      });
    });

    test('sin_chofer no es final: un "aceptado" posterior (reasignación) se aplica', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'sin_chofer')));
        c.read(viajeActualProvider.notifier).descartar();
        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'aceptado', conChofer: true)));

        expect(leer(c).viaje!.estado, EstadoViaje.aceptado);
      });
    });
  });
}
