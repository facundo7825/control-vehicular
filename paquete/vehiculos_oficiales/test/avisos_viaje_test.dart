import 'package:clock/clock.dart';
import 'package:fake_async/fake_async.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/avisos/avisos_viaje.dart';
import 'package:vehiculos_oficiales/src/avisos/reproductor_sonidos.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/solicitante/mis_viajes.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();

  late ApiChofer api;
  late TiempoRealFalso tr;
  late EntornoPrueba e;

  setUp(() {
    api = ApiChofer();
    tr = TiempoRealFalso();
    e = EntornoPrueba();
    binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  });

  tearDown(() => binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed));

  ProviderContainer crear(Usuario usuario) {
    final c = e.contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(usuario),
      ubicadorProvider.overrideWithValue(UbicadorFalso()),
    ]);
    c.listen(avisosViajeProvider, (_, _) {});
    return c;
  }

  Json estado(String estado, {bool conChofer = true, int id = 1}) =>
      p.json(conChofer ? p.viajeAceptado : p.viajeOfrecido)
        ..['id'] = id
        ..['estado'] = estado;

  List<Sonido> sonados() => e.sonidos.sonados;

  group('solicitante', () {
    void emitir(String est, {bool conChofer = true}) =>
        tr.emitir('viaje.1', Eventos.viajeActualizado, estado(est, conChofer: conChofer));

    ProviderContainer buscando(FakeAsync async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear(solicitante);
      async.flushMicrotasks();
      return c;
    }

    test('aceptado, llegó y cancelado: cada uno suena una sola vez', () {
      fakeAsync((async) {
        buscando(async);

        emitir('aceptado');
        emitir('aceptado'); // evento repetido
        expect(sonados(), [Sonido.aceptado]);

        emitir('en_camino');
        expect(sonados(), [Sonido.aceptado]);

        emitir('llego');
        emitir('llego');
        expect(sonados(), [Sonido.aceptado, Sonido.llego]);

        emitir('en_camino'); // atrasado: no hace retroceder el viaje
        emitir('llego');
        expect(sonados(), [Sonido.aceptado, Sonido.llego]);

        emitir('cancelado');
        emitir('en_curso'); // atrasado, después de cancelado
        emitir('cancelado');
        expect(sonados(), [Sonido.aceptado, Sonido.llego, Sonido.cancelado]);
      });
    });

    test('sin chofer suena como cancelado', () {
      fakeAsync((async) {
        buscando(async);
        emitir('sin_chofer', conChofer: false);
        expect(sonados(), [Sonido.cancelado]);
      });
    });

    test('el chofer canceló y se busca otro: suena cancelado; el nuevo chofer, aceptado', () {
      fakeAsync((async) {
        buscando(async);
        emitir('aceptado');
        emitir('buscando', conChofer: false);
        expect(sonados(), [Sonido.aceptado, Sonido.cancelado]);
        emitir('aceptado');
        expect(sonados(), [Sonido.aceptado, Sonido.cancelado, Sonido.aceptado]);
      });
    });

    test('cancelar uno mismo no suena, aunque el evento llegue antes que la respuesta', () {
      fakeAsync((async) {
        final c = buscando(async);

        c.read(viajeActualProvider.notifier).cancelar();
        emitir('cancelado', conChofer: false); // el socket gana a la respuesta
        async.flushMicrotasks();

        expect(c.read(viajeActualProvider).requireValue.viaje!.estado, EstadoViaje.cancelado);
        expect(sonados(), isEmpty);
      });
    });

    test('al cargar un viaje que ya estaba en un estado no suena', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
        final c = crear(solicitante);
        async.flushMicrotasks();

        expect(c.read(viajeActualProvider).requireValue.viaje!.estado, EstadoViaje.llego);
        expect(sonados(), isEmpty);
      });
    });

    test('la consulta del respaldo también avisa la transición, una sola vez', () {
      fakeAsync((async) {
        tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
        buscando(async);

        api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
        async.elapse(const Duration(seconds: 25));

        expect(sonados(), [Sonido.aceptado]);
      });
    });

    test('pedir un viaje no suena y pide el permiso de notificaciones', () {
      fakeAsync((async) {
        final c = crear(solicitante);
        async.flushMicrotasks();

        c
            .read(viajeActualProvider.notifier)
            .pedir(
              const PedidoViaje(
                modo: ModoViaje.masCercano,
                origen: Lugar(Coordenada(-26.8241, -65.2226)),
                destino: Lugar(Coordenada(-26.8083, -65.2176)),
              ),
            );
        async.flushMicrotasks();

        expect(c.read(viajeActualProvider).requireValue.viaje, isNotNull);
        expect(sonados(), isEmpty);
        expect(e.notificaciones.permisos, 1);
      });
    });

    test('una reserva aceptada (push) suena una vez; la del viaje actual no', () {
      fakeAsync((async) {
        buscando(async);
        Map<String, dynamic> push(int id) => {
          'modulo': 'vehiculos_oficiales',
          'tipo': 'viaje',
          'viaje_id': '$id',
          'estado': 'aceptado',
        };

        e.puente.controlador.add(push(9));
        e.puente.controlador.add(push(9));
        e.puente.controlador.add(push(1)); // el viaje actual: lo avisa su transición
        async.flushMicrotasks();

        expect(sonados(), [Sonido.aceptado]);
      });
    });

    group('reservas en "Mis viajes"', () {
      MisViajes conReserva(String estado) => MisViajes(
        proximas: [viaje(id: 5, estado: estado, tipo: 'reserva', conChofer: estado == 'aceptado')],
        historial: [],
      );

      test('una reserva que pasa de pendiente a aceptada entre dos cargas suena una vez', () {
        fakeAsync((async) {
          api.mis = conReserva('ofrecido');
          final c = crear(solicitante);
          c.listen(misViajesProvider, (_, _) {});
          async.flushMicrotasks();
          expect(sonados(), isEmpty);

          api.mis = conReserva('aceptado');
          c.refresh(misViajesProvider);
          async.flushMicrotasks();
          expect(sonados(), [Sonido.aceptado]);

          c.refresh(misViajesProvider);
          async.flushMicrotasks();
          e.puente.controlador.add({
            'modulo': 'vehiculos_oficiales',
            'tipo': 'viaje',
            'viaje_id': '5',
            'estado': 'aceptado',
          }); // el push de la misma reserva: ya se avisó
          async.flushMicrotasks();
          expect(sonados(), [Sonido.aceptado]);
        });
      });

      test('en la primera carga una reserva ya aceptada no suena', () {
        fakeAsync((async) {
          api.mis = conReserva('aceptado');
          final c = crear(solicitante);
          c.listen(misViajesProvider, (_, _) {});
          async.flushMicrotasks();

          expect(c.read(misViajesProvider).requireValue.proximas.single.estado, EstadoViaje.aceptado);
          expect(sonados(), isEmpty);
        });
      });
    });

    group('en segundo plano', () {
      test('además del sonido sale la notificación en español', () {
        fakeAsync((async) {
          buscando(async);
          binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);

          emitir('aceptado');
          emitir('llego');
          emitir('cancelado');

          expect(sonados(), [Sonido.aceptado, Sonido.llego, Sonido.cancelado]);
          expect(e.notificaciones.mostradas.map((n) => n.$1), [
            'Tu viaje fue aceptado',
            'El chofer llegó',
            'Viaje cancelado',
          ]);
          expect(e.notificaciones.mostradas.first.$2, 'Te busca Carlos Gómez.');
          expect(e.notificaciones.mostradas[1].$2, 'Te está esperando en Plaza Independencia.');
        });
      });

      test('inactiva (p. ej. con el panel de notificaciones abierto) cuenta como primer plano', () {
        fakeAsync((async) {
          buscando(async);
          binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
          emitir('aceptado');

          expect(sonados(), [Sonido.aceptado]);
          expect(e.notificaciones.mostradas, isEmpty);
        });
      });

      test('en primer plano solo suena', () {
        fakeAsync((async) {
          buscando(async);
          emitir('aceptado');

          expect(sonados(), [Sonido.aceptado]);
          expect(e.notificaciones.mostradas, isEmpty);
        });
      });
    });
  });

  group('chofer', () {
    Json oferta({int id = 1, Duration vence = const Duration(seconds: 30)}) => {
      'oferta_id': id,
      'vence_en': escribirFecha(clock.now().add(vence)),
      'viaje': p.json(p.viajeOfrecido),
    };

    ProviderContainer libre(FakeAsync async) {
      final c = crear(chofer);
      async.flushMicrotasks();
      return c;
    }

    test('una oferta nueva suena en bucle y vibra cada 2 s', () {
      fakeAsync((async) {
        libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());

        expect(e.sonidos.enBucle, Sonido.oferta);
        expect(e.sonidos.vibraciones, 1);
        async.elapse(const Duration(seconds: 6));
        expect(e.sonidos.vibraciones, 4);
        expect(e.sonidos.bucles, 1);
        expect(e.notificaciones.mostradas, isEmpty); // en primer plano
      });
    });

    test('aceptar corta el bucle al tocar, sin esperar la respuesta', () {
      fakeAsync((async) {
        final c = libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());

        c.read(avisosViajeProvider.notifier).silenciarOferta(1);
        expect(e.sonidos.enBucle, isNull);
        async.elapse(const Duration(seconds: 4));
        expect(e.sonidos.vibraciones, 1);

        c.read(viajeActualProvider.notifier).aceptarOferta();
        async.flushMicrotasks();
        expect(e.sonidos.sonados, isEmpty); // el viaje que aceptó no suena como "asignado"
      });
    });

    test('una oferta silenciada no vuelve a sonar si una consulta la trae otra vez', () {
      fakeAsync((async) {
        final c = libre(async);
        final datos = oferta();
        tr.emitir('chofer.2', Eventos.ofertaCreada, datos);
        c.read(avisosViajeProvider.notifier).silenciarOferta(1);

        api.actual = ViajeActual(oferta: Oferta.fromJson(datos)); // push, reconexión o respaldo
        c.read(viajeActualProvider.notifier).refrescar();
        async.flushMicrotasks();
        tr.emitir('chofer.2', Eventos.ofertaCreada, datos);
        async.elapse(const Duration(seconds: 4));

        expect(c.read(viajeActualProvider).requireValue.oferta!.id, 1);
        expect(e.sonidos.bucles, 1);
        expect(e.sonidos.enBucle, isNull);
        expect(e.sonidos.vibraciones, 1);
      });
    });

    test('si aceptar falla sin red, la oferta sigue en pantalla pero callada', () {
      fakeAsync((async) {
        final c = libre(async);
        final datos = oferta();
        tr.emitir('chofer.2', Eventos.ofertaCreada, datos);
        c.read(avisosViajeProvider.notifier).silenciarOferta(1);
        api.errorOferta = const SinConexion();

        c.read(viajeActualProvider.notifier).aceptarOferta().ignore();
        async.flushMicrotasks();
        api.actual = ViajeActual(oferta: Oferta.fromJson(datos));
        c.read(viajeActualProvider.notifier).refrescar();
        async.flushMicrotasks();

        expect(c.read(viajeActualProvider).requireValue.oferta!.id, 1);
        expect(e.sonidos.bucles, 1);
        expect(e.sonidos.enBucle, isNull);
      });
    });

    test('una oferta silenciada que se va y otra nueva: la nueva suena', () {
      fakeAsync((async) {
        final c = libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());
        c.read(avisosViajeProvider.notifier).silenciarOferta(1);
        c.read(viajeActualProvider.notifier).rechazarOferta();
        async.flushMicrotasks();

        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta(id: 2));
        expect(e.sonidos.enBucle, Sonido.oferta);
        expect(e.sonidos.bucles, 2);
      });
    });

    test('silenciar otra oferta no corta la que suena', () {
      fakeAsync((async) {
        final c = libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta(id: 2));

        c.read(avisosViajeProvider.notifier).silenciarOferta(1);
        expect(e.sonidos.enBucle, Sonido.oferta);
      });
    });

    test('rechazar corta el bucle', () {
      fakeAsync((async) {
        final c = libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());

        c.read(viajeActualProvider.notifier).rechazarOferta();
        async.flushMicrotasks();
        expect(e.sonidos.enBucle, isNull);
      });
    });

    test('al vencer se corta, aunque nadie la dé por vencida', () {
      fakeAsync((async) {
        libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta(vence: const Duration(seconds: 10)));

        async.elapse(const Duration(seconds: 9));
        expect(e.sonidos.enBucle, Sonido.oferta);
        async.elapse(const Duration(seconds: 1));
        expect(e.sonidos.enBucle, isNull);
        final vibraciones = e.sonidos.vibraciones;
        async.elapse(const Duration(seconds: 10));
        expect(e.sonidos.vibraciones, vibraciones);
      });
    });

    test('al cerrar el módulo se corta', () {
      fakeAsync((async) {
        final c = libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());

        c.dispose();
        expect(e.sonidos.enBucle, isNull);
        async.elapse(const Duration(seconds: 10));
        expect(e.sonidos.vibraciones, 1);
      });
    });

    test('una oferta repetida no reinicia el bucle; otra oferta sí', () {
      fakeAsync((async) {
        libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());
        expect(e.sonidos.bucles, 1);

        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta(id: 2));
        expect(e.sonidos.bucles, 2);
      });
    });

    test('en segundo plano la oferta además sale como notificación', () {
      fakeAsync((async) {
        libre(async);
        binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());

        expect(e.notificaciones.mostradas.single, (
          'Nuevo viaje ofrecido',
          'Hacia Tribunales. Respondé antes de que venza.',
        ));
      });
    });

    test('la notificación de la oferta tiene id fijo y se quita al cortar el timbre', () {
      fakeAsync((async) {
        final c = libre(async);
        binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());
        expect(e.notificaciones.ids.single, AvisosViaje.idNotificacionOferta);
        expect(e.notificaciones.canceladas, isEmpty);

        c.read(viajeActualProvider.notifier).rechazarOferta();
        async.flushMicrotasks();
        expect(e.notificaciones.canceladas, [AvisosViaje.idNotificacionOferta]);
      });
    });

    test('la notificación de la oferta se quita al vencer', () {
      fakeAsync((async) {
        libre(async);
        binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta(vence: const Duration(seconds: 10)));

        async.elapse(const Duration(seconds: 10));
        expect(e.notificaciones.canceladas, [AvisosViaje.idNotificacionOferta]);
      });
    });

    test('sin notificación de la oferta (primer plano) no se cancela nada', () {
      fakeAsync((async) {
        final c = libre(async);
        tr.emitir('chofer.2', Eventos.ofertaCreada, oferta());

        c.read(viajeActualProvider.notifier).rechazarOferta();
        async.flushMicrotasks();
        expect(e.notificaciones.canceladas, isEmpty);
      });
    });

    test('un obligatorio asignado sin oferta suena una vez', () {
      fakeAsync((async) {
        libre(async);
        final obligatorio = estado('aceptado', id: 7)..['obligatorio'] = true;
        tr.emitir('chofer.2', Eventos.viajeActualizado, obligatorio);
        tr.emitir('chofer.2', Eventos.viajeActualizado, obligatorio);

        expect(e.sonidos.sonados, [Sonido.oferta]);
        expect(e.sonidos.enBucle, isNull);
      });
    });

    test('le cancelan el viaje: suena cancelado; si cancela él, no', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear(chofer);
        async.flushMicrotasks();

        tr.emitir('chofer.2', Eventos.viajeActualizado, estado('cancelado'));
        tr.emitir('chofer.2', Eventos.viajeActualizado, estado('cancelado'));
        expect(e.sonidos.sonados, [Sonido.cancelado]);

        // Otro viaje, que cancela el chofer mismo.
        c.read(viajeActualProvider.notifier).descartar();
        tr.emitir('chofer.2', Eventos.viajeActualizado, estado('aceptado', id: 3));
        api.respuestaCancelar = Viaje.fromJson(estado('cancelado', id: 3));
        c.read(viajeActualProvider.notifier).cancelar(motivo: 'Se rompió el auto');
        async.flushMicrotasks();
        expect(c.read(viajeActualProvider).requireValue.viaje!.estado, EstadoViaje.cancelado);
        expect(e.sonidos.sonados, [Sonido.cancelado, Sonido.oferta]); // oferta: el 3 llegó asignado
      });
    });

    test('al iniciar el turno pide el permiso de notificaciones', () {
      fakeAsync((async) {
        api.turno = null;
        final c = libre(async);
        c.listen(turnoProvider, (_, _) {});
        async.flushMicrotasks();
        expect(e.notificaciones.permisos, 0);

        c.read(turnoProvider.notifier).iniciar(5);
        async.flushMicrotasks();
        expect(e.notificaciones.permisos, 1);
      });
    });
  });
}
