import 'dart:async';
import 'dart:io';

import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_ubicaciones.dart';
import 'package:vehiculos_oficiales/src/chofer/emisor_ubicacion.dart';
import 'package:vehiculos_oficiales/src/chofer/rastreador_turno.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/push/push_modulo.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import 'soporte/dobles.dart';
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  late ApiChofer api;
  late UbicadorFalso gps;
  late TiempoRealFalso tr;
  late AlmacenColaMemoria almacen;
  late EntornoPrueba entorno;

  setUp(() {
    api = ApiChofer();
    gps = UbicadorFalso();
    tr = TiempoRealFalso();
    almacen = AlmacenColaMemoria();
  });

  ProviderContainer crear() {
    entorno = EntornoPrueba()..almacenCola = almacen;
    final c = entorno.contenedor([
      apiProvider.overrideWithValue(api),
      ubicadorProvider.overrideWithValue(gps),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(chofer),
    ]);
    c.listen(turnoProvider, (_, _) {});
    return c;
  }

  int envios() => api.llamadas.where((l) => l.startsWith('ubicacion')).length;

  test('sin turno no se sigue el GPS', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      expect(c.read(turnoProvider).value, isNull);
      expect(gps.intervalos, isEmpty);
      expect(gps.siguiendo, isFalse);
    });
  });

  test('con un turno abierto al abrir, retoma el rastreo y manda lotes cada 10 s', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();

      expect(c.read(turnoProvider).value!.id, 1);
      // El GPS se abre una sola vez al ritmo más rápido (el del viaje); el envío va al del turno.
      expect(gps.intervalos, [const Duration(seconds: 5)]);
      gps
        ..emitir(punto(0))
        ..emitir(punto(5));
      expect(c.read(posicionPropiaProvider).punto!.registradoEn, punto(5).registradoEn);
      gps.emitir(punto(10));

      async.elapse(const Duration(seconds: 9));
      expect(envios(), 0);
      async.elapse(const Duration(seconds: 1));
      // Sin viaje se encola un punto por intervalo de envío: el de los 5 s no entra.
      expect(segundos(api.lotes.single), [0, 10]);

      async.elapse(const Duration(seconds: 10)); // cola vacía: no sale nada
      expect(envios(), 1);
    });
  });

  test('con un viaje activo los envíos pasan a 5 s y vuelven a 10 s al terminar, sin reabrir el GPS', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      crear();
      async.flushMicrotasks();

      expect(gps.intervalos, [const Duration(seconds: 5)]);
      gps
        ..emitir(punto(0))
        ..emitir(punto(5));
      async.elapse(const Duration(seconds: 5));
      expect(segundos(api.lotes.single), [0, 5]); // en viaje entran todos

      tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'finalizado', conChofer: true)));
      async.flushMicrotasks();
      expect(gps.intervalos, [const Duration(seconds: 5)]);
      expect(gps.siguiendo, isTrue);

      gps
        ..emitir(punto(15))
        ..emitir(punto(20))
        ..emitir(punto(25));
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 1);
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 2);
      expect(segundos(api.lotes.last), [15, 25]); // sin viaje, uno cada 10 s
    });
  });

  test('al asignarle un viaje con la app en segundo plano no se reabre el GPS: solo cambia el envío', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      crear();
      async.flushMicrotasks();

      tr.emitir(
        'chofer.2',
        Eventos.viajeActualizado,
        jsonViaje(viaje(id: 3, estado: 'aceptado', obligatorio: true, conChofer: true)),
      );
      async.flushMicrotasks();
      expect(gps.intervalos, [const Duration(seconds: 5)]);
      expect(gps.siguiendo, isTrue);

      gps.emitir(punto(0));
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 1);

      tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(id: 3, estado: 'cancelado', conChofer: true)));
      async.flushMicrotasks();
      expect(gps.intervalos, hasLength(1));
      expect(gps.siguiendo, isTrue);
    });
  });

  test('un viaje reasignado a otro chofer no cuenta como viaje activo', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      crear();
      async.flushMicrotasks();

      final reasignado = jsonViaje(viaje(estado: 'aceptado', conChofer: true))
        ..['chofer'] = {'id': 9, 'nombre': 'Otro', 'telefono': null};
      tr.emitir('chofer.2', Eventos.viajeActualizado, reasignado);
      async.flushMicrotasks();

      gps.emitir(punto(0));
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 0);
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 1);
    });
  });

  test('los intervalos salen de GET /configuracion', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      api.configuracionRespuesta = const Configuracion(gpsTurnoSeg: 15, gpsViajeSeg: 3, ofertaSegundos: 30);
      crear();
      async.flushMicrotasks();

      expect(gps.intervalos, [const Duration(seconds: 3)]);
      gps.emitir(punto(0));
      async.elapse(const Duration(seconds: 14));
      expect(envios(), 0);
      async.elapse(const Duration(seconds: 1));
      expect(envios(), 1);
    });
  });

  test('si GET /configuracion falla: los intervalos por defecto, sin mapa de fondo ni autocompletar', () {
    expect([configuracionPorDefecto.gpsTurnoSeg, configuracionPorDefecto.gpsViajeSeg], [10, 5]);
    expect(configuracionPorDefecto.teselas, isNull, reason: 'nunca el OSM público en lugar del configurado');
    expect(configuracionPorDefecto.lugaresAutocompletar, isFalse);
  });

  test('iniciar: pide permiso, POST /turnos y arranca el GPS', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      PermisoUbicacion? permiso;
      c.read(turnoProvider.notifier).iniciar(1).then((p) => permiso = p);
      async.flushMicrotasks();

      expect(permiso, PermisoUbicacion.concedido);
      expect(api.llamadas, contains('iniciar:1'));
      expect(c.read(turnoProvider).value!.abierto, isTrue);
      expect(gps.siguiendo, isTrue);
    });
  });

  test('permiso denegado: no llama a POST /turnos ni sigue el GPS', () {
    fakeAsync((async) {
      gps.permiso = PermisoUbicacion.denegadoParaSiempre;
      final c = crear();
      async.flushMicrotasks();

      PermisoUbicacion? permiso;
      c.read(turnoProvider.notifier).iniciar(1).then((p) => permiso = p);
      async.flushMicrotasks();

      expect(permiso, PermisoUbicacion.denegadoParaSiempre);
      expect(api.llamadas.where((l) => l.startsWith('iniciar')), isEmpty);
      expect(gps.siguiendo, isFalse);
    });
  });

  test('finalizar vacía la cola antes de cerrar el turno y después no sale ningún punto', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();
      gps
        ..emitir(punto(0))
        ..emitir(punto(10));

      c.read(turnoProvider.notifier).finalizar();
      async.flushMicrotasks();

      expect(api.llamadas.skipWhile((l) => l != 'ubicacion:2'), ['ubicacion:2', 'finalizar']);
      expect(c.read(turnoProvider).value, isNull);
      expect(gps.siguiendo, isFalse);
      expect(c.read(posicionPropiaProvider).punto, isNull);

      async.elapse(const Duration(minutes: 1));
      expect(envios(), 1);
    });
  });

  test('un 422 al finalizar deja el turno y el rastreo como estaban', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      api.errorFinalizar = const ErrorNegocio('Finalizá el viaje en curso antes de cerrar el turno.');
      final c = crear();
      async.flushMicrotasks();

      Object? error;
      c.read(turnoProvider.notifier).finalizar().catchError((Object e) => error = e);
      async.flushMicrotasks();

      expect(error, isA<ErrorNegocio>());
      expect(c.read(turnoProvider).value, isNotNull);
      expect(gps.siguiendo, isTrue);
      gps.emitir(punto(20));
      async.elapse(const Duration(seconds: 10));
      expect(envios(), 1);
    });
  });

  test('si al vaciar la cola el backend dice que no hay turno, no se pide finalizar y se deja de rastrear', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();

      api
        ..turno = null
        ..erroresUbicacion.add(const ErrorNegocio('Iniciá un turno para compartir tu ubicación.'));
      gps.emitir(punto(0));
      Object? error;
      c.read(turnoProvider.notifier).finalizar().catchError((Object e) => error = e);
      async.flushMicrotasks();

      expect(error, isNull);
      expect(api.llamadas, isNot(contains('finalizar')));
      expect(api.llamadas.where((l) => l == 'turnoActual'), hasLength(2));
      expect(c.read(turnoProvider).value, isNull);
      expect(gps.siguiendo, isFalse);
    });
  });

  test('cerrar el módulo mientras se lee el turno no pide permiso ni abre el GPS', () {
    fakeAsync((async) {
      api
        ..turno = turnoDePrueba()
        ..demoraTurno = Completer<void>();
      final c = crear();
      async.flushMicrotasks();

      c.dispose();
      api.demoraTurno!.complete();
      async.flushMicrotasks();
      async.elapse(const Duration(minutes: 1));

      expect(gps.pedidosDePermiso, 0);
      expect(gps.intervalos, isEmpty);
    });
  });

  test('"Reintentar" mientras se lee el turno: la lectura vieja no pide permiso', () {
    fakeAsync((async) {
      api
        ..turno = turnoDePrueba()
        ..demoraTurno = Completer<void>();
      final c = crear();
      async.flushMicrotasks();

      c.invalidate(turnoProvider);
      async.flushMicrotasks();
      api.demoraTurno!.complete();
      async.flushMicrotasks();

      expect(gps.pedidosDePermiso, 1);
      expect(gps.intervalos, hasLength(1));
    });
  });

  test('un error inesperado del envío no se escapa y el rastreo sigue', () {
    fakeAsync((async) {
      api = _ApiRota()..turno = turnoDePrueba();
      crear();
      async.flushMicrotasks();

      gps.emitir(punto(0));
      async.elapse(const Duration(seconds: 10));

      expect(envios(), 1);
      expect(gps.siguiendo, isTrue);
    });
  });

  test('un 422 "Iniciá un turno…" del envío detiene el rastreo y vuelve a preguntar el turno', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();

      api
        ..turno = null
        ..erroresUbicacion.add(const ErrorNegocio('Iniciá un turno para compartir tu ubicación.'));
      gps.emitir(punto(0));
      async.elapse(const Duration(seconds: 10));

      expect(gps.siguiendo, isFalse);
      expect(api.llamadas.where((l) => l == 'turnoActual'), hasLength(2));
      expect(c.read(turnoProvider).value, isNull);
      async.elapse(const Duration(minutes: 1));
      expect(envios(), 1);
    });
  });

  test('sin red los puntos se acumulan y al volver salen juntos y en orden', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      crear();
      async.flushMicrotasks();

      api.erroresUbicacion.addAll([const SinConexion(), const SinConexion(), const SinConexion()]);
      for (var ciclo = 0; ciclo < 4; ciclo++) {
        gps.emitir(punto(ciclo * 10 + 5));
        gps.emitir(punto(ciclo * 10 + 5)); // repetido del GPS
        async.elapse(const Duration(seconds: 10));
      }

      expect(segundos(api.lotes.last), [5, 15, 25, 35]);
      expect(api.lotes, hasLength(4));
    });
  });

  test('un error del GPS se avisa, Reintentar reabre el GPS sin perder la cola y un 401 no escapa', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();

      gps.fallar(Exception('GPS apagado'));
      expect(c.read(posicionPropiaProvider).sinGps, isTrue);
      gps.emitir(punto(1));
      expect(c.read(posicionPropiaProvider).sinGps, isFalse);

      gps.fallar(Exception('permiso revocado'));
      c.read(turnoProvider.notifier).reintentarGps();
      async.flushMicrotasks();
      expect(gps.pedidosDePermiso, 2);
      expect(gps.intervalos, hasLength(2));
      expect(gps.siguiendo, isTrue);

      api.erroresUbicacion.add(const SesionInvalida());
      async.elapse(const Duration(seconds: 10));
      expect(gps.siguiendo, isTrue);
      async.elapse(const Duration(seconds: 10));
      expect(segundos(api.lotes.last), [1]); // el punto de antes de reabrir no se perdió
    });
  });

  group('GPS que no entrega posiciones', () {
    const limite = Duration(seconds: 30);

    test('un stream que nunca emite se avisa pasado el límite, una sola vez, y un punto lo limpia', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();

        async.elapse(limite - const Duration(seconds: 1));
        expect(c.read(posicionPropiaProvider).sinGps, isFalse);
        async.elapse(const Duration(seconds: 1));
        expect(c.read(posicionPropiaProvider).sinGps, isTrue);

        // Sigue en silencio: no se vuelve a avisar ni se reabre el GPS solo.
        final avisos = <PosicionPropia>[];
        c.listen(posicionPropiaProvider, (_, n) => avisos.add(n));
        async.elapse(const Duration(minutes: 5));
        expect(avisos, isEmpty);
        expect(gps.intervalos, hasLength(1));

        gps.emitir(punto(0));
        expect(c.read(posicionPropiaProvider).sinGps, isFalse);
        expect(c.read(posicionPropiaProvider).punto, isNotNull);
      });
    });

    test('tras recuperarse, un nuevo silencio vuelve a avisar', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();

        async.elapse(limite);
        gps.emitir(punto(0));
        expect(c.read(posicionPropiaProvider).sinGps, isFalse);
        async.elapse(limite);
        expect(c.read(posicionPropiaProvider).sinGps, isTrue);
      });
    });

    test('con puntos regulares nunca se avisa', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();

        for (var s = 0; s < 300; s += 5) {
          gps.emitir(punto(s));
          async.elapse(const Duration(seconds: 5));
          expect(c.read(posicionPropiaProvider).sinGps, isFalse);
        }
      });
    });

    test('Reintentar rearma el aviso sobre el stream nuevo', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();
        async.elapse(limite);
        expect(c.read(posicionPropiaProvider).sinGps, isTrue);

        c.read(turnoProvider.notifier).reintentarGps();
        async.flushMicrotasks();
        expect(gps.intervalos, hasLength(2));
        gps.emitir(punto(0));
        expect(c.read(posicionPropiaProvider).sinGps, isFalse);
        async.elapse(limite);
        expect(c.read(posicionPropiaProvider).sinGps, isTrue);
      });
    });

    test('el límite es max(30 s, 3 × intervalo del GPS) y se puede inyectar', () {
      fakeAsync((async) {
        final avisos = <Object>[];
        RastreadorTurno crearR(Duration intervaloViaje, {Duration? sinPosicionTras}) {
          final cola = ColaUbicaciones();
          return RastreadorTurno(
            ubicador: gps,
            cola: cola,
            emisor: EmisorUbicacion(api: api, cola: cola),
            intervaloTurno: const Duration(seconds: 60),
            intervaloViaje: intervaloViaje,
            alPunto: (_) {},
            alErrorGps: avisos.add,
            alQuedarSinTurno: () {},
            sinPosicionTras: sinPosicionTras,
          )..iniciar();
        }

        final lento = crearR(const Duration(seconds: 20)); // 3 × 20 s = 60 s
        async.elapse(const Duration(seconds: 59));
        expect(avisos, isEmpty);
        async.elapse(const Duration(seconds: 1));
        expect(avisos.single, isA<SinPosicionGps>());
        lento.detener();

        avisos.clear();
        final rapido = crearR(const Duration(seconds: 5), sinPosicionTras: const Duration(seconds: 2));
        async.elapse(const Duration(seconds: 2));
        expect(avisos.single, isA<SinPosicionGps>());
        rapido.detener();
      });
    });

    test('detener y cerrar el módulo no dejan timers pendientes', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();
        gps.emitir(punto(0));

        c.dispose();
        async.flushMicrotasks();
        expect(async.pendingTimers, isEmpty);
        async.elapse(const Duration(minutes: 2));
      });
    });

    test('finalizar el turno cancela el aviso', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();

        c.read(turnoProvider.notifier).finalizar();
        async.flushMicrotasks();
        // Solo queda el sondeo del turno (sin turno se pregunta cada 30 s).
        expect(async.pendingTimers.map((t) => t.duration), [TurnoNotifier.intervaloSondeo]);
        async.elapse(const Duration(minutes: 2));
        expect(c.read(posicionPropiaProvider).sinGps, isFalse);
      });
    });
  });

  test('un error al leer el turno queda como error de la pantalla, sin GPS', () {
    fakeAsync((async) {
      api.errorTurnoActual = const SinConexion();
      final c = crear();
      async.flushMicrotasks();

      expect(c.read(turnoProvider).hasError, isTrue);
      expect(gps.intervalos, isEmpty);
    });
  });

  test('al descartar el contenedor (cerrar el módulo) se corta el GPS', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();
      expect(gps.siguiendo, isTrue);

      c.dispose();
      async.flushMicrotasks();

      expect(gps.siguiendo, isFalse);
      async.elapse(const Duration(minutes: 1));
      expect(envios(), 0);
    });
  });

  group('cola persistente', () {
    test('los puntos sin enviar se guardan, como mucho una escritura cada 5 s', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        crear();
        async.flushMicrotasks();
        api.erroresUbicacion.addAll(List.filled(5, const SinConexion()));

        gps.emitir(punto(0));
        async.elapse(const Duration(seconds: 4));
        expect(almacen.escrituras, isEmpty);
        async.elapse(const Duration(seconds: 1));
        expect(segundos(almacen.escrituras.single), [0]);
        expect(almacen.turnoId, 1);

        // Un punto encolado por segundo durante 20 s: 4 escrituras, no 20.
        almacen.escrituras.clear();
        for (var s = 1; s <= 20; s++) {
          gps.emitir(punto(s * 10));
          async.elapse(const Duration(seconds: 1));
        }
        expect(almacen.escrituras, hasLength(4));
        expect(segundos(almacen.puntos), [for (var s = 0; s <= 20; s++) s * 10]);
      });
    });

    test('tras un envío confirmado lo guardado refleja la cola reducida', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        crear();
        async.flushMicrotasks();

        gps.emitir(punto(0));
        async.elapse(const Duration(seconds: 5));
        expect(segundos(almacen.puntos), [0]);
        gps.emitir(punto(10));
        async.elapse(const Duration(seconds: 5)); // sale el lote [0, 10]
        expect(segundos(api.lotes.single), [0, 10]);
        expect(almacen.puntos, isEmpty);
        expect(almacen.turnoId, 1);
      });
    });

    test('con la cola vacía no se reescribe lo guardado si ya estaba vacío', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        crear();
        async.flushMicrotasks();

        // Cada punto sale antes de que venza su guardado periódico: solo quedan los guardados de después
        // de cada envío, todos con la cola vacía.
        for (var s = 0; s < 5; s++) {
          async.elapse(const Duration(seconds: 9));
          gps.emitir(punto(s * 10 + 9));
          async.elapse(const Duration(seconds: 1));
        }
        expect(api.lotes, hasLength(5));
        expect(almacen.escrituras, [isEmpty]);

        // Un guardado con puntos vuelve a habilitar el vacío siguiente (lo guardado refleja la cola reducida).
        gps.emitir(punto(59));
        async.elapse(const Duration(seconds: 5));
        expect(segundos(almacen.puntos), [59]);
        async.elapse(const Duration(seconds: 5));
        expect(api.lotes, hasLength(6));
        expect(almacen.puntos, isEmpty);
        expect(almacen.escrituras, hasLength(3));
      });
    });

    test('al reiniciar con el mismo turno abierto, lo guardado vuelve a la cola y sale en orden', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        almacen
          ..turnoId = 1
          ..puntos = [punto(10), punto(0)];
        crear();
        async.flushMicrotasks();

        gps.emitir(punto(30));
        async.elapse(const Duration(seconds: 10));
        expect(segundos(api.lotes.single), [0, 10, 30]);
      });
    });

    test('cerrar el módulo guarda lo pendiente y al volver a abrirlo se retoma', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c1 = crear();
        async.flushMicrotasks();
        gps
          ..emitir(punto(0))
          ..emitir(punto(10));
        c1.dispose();
        async.flushMicrotasks();
        expect(segundos(almacen.puntos), [0, 10]);

        gps = UbicadorFalso();
        crear();
        async.flushMicrotasks();
        async.elapse(const Duration(seconds: 10));
        expect(segundos(api.lotes.single), [0, 10]);
      });
    });

    test('lo guardado de otro turno se descarta', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        almacen
          ..turnoId = 99
          ..puntos = [punto(0)];
        crear();
        async.flushMicrotasks();

        expect(almacen.guardado, isFalse);
        gps.emitir(punto(30));
        async.elapse(const Duration(seconds: 10));
        expect(segundos(api.lotes.single), [30]);
      });
    });

    test('sin turno al abrir se borra lo guardado', () {
      fakeAsync((async) {
        almacen
          ..turnoId = 1
          ..puntos = [punto(0)];
        crear();
        async.flushMicrotasks();

        expect(almacen.guardado, isFalse);
      });
    });

    test('finalizar el turno borra lo guardado y no se vuelve a escribir', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();
        api.erroresUbicacion.add(const SinConexion());
        gps.emitir(punto(0));
        async.elapse(const Duration(seconds: 5));
        expect(almacen.guardado, isTrue);

        c.read(turnoProvider.notifier).finalizar();
        async.flushMicrotasks();
        expect(c.read(turnoProvider).value, isNull);
        expect(almacen.guardado, isFalse);

        final escrituras = almacen.escrituras.length;
        async.elapse(const Duration(minutes: 1));
        c.dispose();
        async.flushMicrotasks();
        expect(almacen.escrituras, hasLength(escrituras));
        expect(almacen.guardado, isFalse);
      });
    });

    test('un 422 "Iniciá un turno…" del envío borra lo guardado', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        crear();
        async.flushMicrotasks();
        gps.emitir(punto(0));
        async.elapse(const Duration(seconds: 5));
        expect(almacen.guardado, isTrue);

        api
          ..turno = null
          ..erroresUbicacion.add(const ErrorNegocio('Iniciá un turno para compartir tu ubicación.'));
        async.elapse(const Duration(seconds: 5));

        expect(gps.siguiendo, isFalse);
        expect(almacen.guardado, isFalse);
      });
    });

    test('después de un 401 no se vuelve a escribir lo pendiente', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();

        c.read(avisoSesionProvider).avisar();
        gps.emitir(punto(0));
        async.elapse(const Duration(seconds: 5));
        c.dispose();
        async.flushMicrotasks();

        expect(almacen.escrituras, isEmpty);
      });
    });

    test('un almacén que falla no rompe el rastreo', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        almacen.error = const FileSystemException('disco lleno');
        final c = crear();
        async.flushMicrotasks();
        expect(gps.siguiendo, isTrue);

        gps.emitir(punto(0));
        async.elapse(const Duration(seconds: 10));
        expect(segundos(api.lotes.single), [0]);
        expect(gps.siguiendo, isTrue);

        c.read(turnoProvider.notifier).finalizar();
        async.flushMicrotasks();
        expect(c.read(turnoProvider).value, isNull);
      });
    });
  });

  group('turno abierto o cerrado por un fichaje', () {
    int consultasTurno() => api.llamadas.where((l) => l == 'turnoActual').length;

    void pushTurno(String estado) =>
        entorno.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'turno', 'estado': estado});

    test('sin turno pregunta cada 30 s y, cuando aparece uno, lo adopta, arranca el GPS y deja de preguntar', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();
        expect(consultasTurno(), 1);

        async.elapse(TurnoNotifier.intervaloSondeo);
        expect(consultasTurno(), 2);
        expect(c.read(turnoProvider).value, isNull);
        expect(gps.siguiendo, isFalse);

        api.turno = turnoDePrueba();
        async.elapse(TurnoNotifier.intervaloSondeo);
        expect(consultasTurno(), 3);
        expect(c.read(turnoProvider).value!.id, 1);
        expect(gps.siguiendo, isTrue);
        expect(gps.pedidosDePermiso, 1);

        async.elapse(TurnoNotifier.intervaloSondeo * 4);
        expect(consultasTurno(), 3);
      });
    });

    test('con el turno abierto no pregunta; al finalizarlo vuelve a preguntar', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();

        async.elapse(TurnoNotifier.intervaloSondeo * 3);
        expect(consultasTurno(), 1);

        c.read(turnoProvider.notifier).finalizar();
        async.flushMicrotasks();
        async.elapse(TurnoNotifier.intervaloSondeo);
        expect(consultasTurno(), 2);
      });
    });

    test('un error al preguntar no corta el sondeo ni deja la pantalla en error', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();

        api.errorTurnoActual = const SinConexion();
        async.elapse(TurnoNotifier.intervaloSondeo);
        expect(c.read(turnoProvider).hasError, isFalse);
        expect(c.read(turnoProvider).value, isNull);

        api
          ..errorTurnoActual = null
          ..turno = turnoDePrueba();
        async.elapse(TurnoNotifier.intervaloSondeo);
        expect(c.read(turnoProvider).value!.id, 1);
      });
    });

    test('cerrar el módulo corta el sondeo', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();

        c.dispose();
        async.flushMicrotasks();
        expect(async.pendingTimers, isEmpty);
      });
    });

    test('el push "turno abierto" adopta el turno y arranca el GPS sin esperar el sondeo', () {
      fakeAsync((async) {
        final c = crear();
        c.read(pushModuloProvider);
        async.flushMicrotasks();

        api.turno = turnoDePrueba();
        pushTurno('abierto');
        async.flushMicrotasks();

        expect(c.read(turnoProvider).value!.id, 1);
        expect(gps.siguiendo, isTrue);
      });
    });

    test('el push "turno cerrado" corta el GPS y vuelve a "sin turno"', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        c.read(pushModuloProvider);
        async.flushMicrotasks();
        expect(gps.siguiendo, isTrue);

        api.turno = null;
        pushTurno('cerrado');
        async.flushMicrotasks();

        expect(c.read(turnoProvider).value, isNull);
        expect(gps.siguiendo, isFalse);
        expect(c.read(posicionPropiaProvider).punto, isNull);
      });
    });

    test('un push del mismo turno abierto no reabre el GPS', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        c.read(pushModuloProvider);
        async.flushMicrotasks();

        pushTurno('abierto');
        async.flushMicrotasks();

        expect(consultasTurno(), 2);
        expect(gps.intervalos, hasLength(1));
        expect(gps.siguiendo, isTrue);
      });
    });

    test('finalizar mientras se retoma el turno (diálogo de permiso abierto) no abre el GPS', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();
        api.turno = turnoDePrueba();
        gps.demoraPermiso = Completer<void>();
        async.elapse(TurnoNotifier.intervaloSondeo);
        expect(c.read(turnoProvider).value!.id, 1);

        c.read(turnoProvider.notifier).finalizar();
        async.flushMicrotasks();
        expect(c.read(turnoProvider).value, isNull);

        gps.demoraPermiso!.complete();
        async.flushMicrotasks();
        expect(gps.intervalos, isEmpty);
        expect(gps.siguiendo, isFalse);
      });
    });

    test('un push "cerrado" con el permiso pendiente se procesa enseguida y no se abre el GPS', () {
      fakeAsync((async) {
        final c = crear();
        c.read(pushModuloProvider);
        async.flushMicrotasks();
        api.turno = turnoDePrueba();
        gps.demoraPermiso = Completer<void>();
        pushTurno('abierto');
        async.flushMicrotasks();
        expect(c.read(turnoProvider).value!.id, 1);

        api.turno = null;
        pushTurno('cerrado');
        async.flushMicrotasks();
        expect(c.read(turnoProvider).value, isNull);

        gps.demoraPermiso!.complete();
        async.flushMicrotasks();
        expect(gps.intervalos, isEmpty);
      });
    });

    test('un push que llega con una consulta en curso no se pierde: se vuelve a consultar al terminar', () {
      fakeAsync((async) {
        final c = crear();
        c.read(pushModuloProvider);
        async.flushMicrotasks();
        api.demoraTurno = Completer<void>();
        pushTurno('abierto');
        async.flushMicrotasks();
        pushTurno('cerrado');
        async.flushMicrotasks();
        expect(consultasTurno(), 2);

        api.demoraTurno!.complete();
        async.flushMicrotasks();
        expect(consultasTurno(), 3);
        expect(c.read(turnoProvider).value, isNull);
      });
    });

    test('si retomar el turno falla, no escapa el error: avisa en el mapa y "Reintentar" lo arranca', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();
        api.turno = turnoDePrueba();
        gps.errorPermiso = StateError('plugin');
        async.elapse(TurnoNotifier.intervaloSondeo);

        expect(c.read(turnoProvider).value!.id, 1);
        expect(c.read(posicionPropiaProvider).sinGps, isTrue);
        expect(gps.intervalos, isEmpty);

        gps.errorPermiso = null;
        c.read(turnoProvider.notifier).reintentarGps();
        async.flushMicrotasks();
        expect(gps.siguiendo, isTrue);
      });
    });

    test('una consulta que vuelve después de cambiar el vehículo no pisa el cambio', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        c.read(pushModuloProvider);
        async.flushMicrotasks();

        api.demoraTurno = Completer<void>();
        pushTurno('abierto');
        async.flushMicrotasks();
        c.read(turnoProvider.notifier).cambiarVehiculo(2);
        async.flushMicrotasks();
        api.turno = turnoDePrueba(); // la consulta trae lo de antes del cambio
        api.demoraTurno!.complete();
        async.flushMicrotasks();

        expect(c.read(turnoProvider).value!.vehiculo!.patente, 'AC456EF');
      });
    });

    test('el push de turno sin la pantalla del chofer abierta no crea el turno ni falla', () {
      fakeAsync((async) {
        entorno = EntornoPrueba()..almacenCola = almacen;
        final contenedor = entorno.contenedor([
          apiProvider.overrideWithValue(api),
          ubicadorProvider.overrideWithValue(gps),
          tiempoRealProvider.overrideWithValue(tr),
          usuarioProvider.overrideWithValue(chofer),
        ]);
        contenedor.read(pushModuloProvider);
        async.flushMicrotasks();

        pushTurno('abierto');
        async.flushMicrotasks();

        expect(contenedor.exists(turnoProvider), isFalse);
        expect(api.llamadas, isNot(contains('turnoActual')));
      });
    });

    test('el push "sin_vehiculo" recarga los vehículos disponibles', () {
      fakeAsync((async) {
        final c = crear();
        c.read(pushModuloProvider);
        c.listen(vehiculosDisponiblesProvider, (_, _) {});
        async.flushMicrotasks();
        expect(api.llamadas.where((l) => l == 'vehiculos'), hasLength(1));

        pushTurno('sin_vehiculo');
        async.elapse(Duration.zero); // Riverpod reconstruye lo invalidado en su próximo ciclo
        expect(api.llamadas.where((l) => l == 'vehiculos'), hasLength(2));
      });
    });
  });

  group('cambiar vehículo', () {
    test('cambia el vehículo del turno sin cortar el rastreo', () {
      fakeAsync((async) {
        api.turno = turnoDePrueba();
        final c = crear();
        async.flushMicrotasks();

        c.read(turnoProvider.notifier).cambiarVehiculo(2);
        async.flushMicrotasks();

        expect(api.llamadas, contains('cambiar:2'));
        expect(c.read(turnoProvider).value!.vehiculo!.patente, 'AC456EF');
        expect(gps.intervalos, hasLength(1));
        expect(gps.siguiendo, isTrue);
      });
    });

    test('un 422 llega a la pantalla y el turno sigue con su vehículo', () {
      fakeAsync((async) {
        api
          ..turno = turnoDePrueba()
          ..errorCambiar = const ErrorNegocio('No podés cambiar el vehículo durante un viaje.');
        final c = crear();
        async.flushMicrotasks();

        Object? error;
        c.read(turnoProvider.notifier).cambiarVehiculo(2).catchError((Object e) => error = e);
        async.flushMicrotasks();

        expect(error, isA<ErrorNegocio>());
        expect(c.read(turnoProvider).value!.vehiculo!.patente, 'AB123CD');
      });
    });
  });
}

/// Un envío que falla con algo que no es un [ErrorApi] (un error de programación, un plugin).
class _ApiRota extends ApiChofer {
  @override
  Future<void> enviarUbicacion(List<PuntoGps> puntos) async {
    await super.enviarUbicacion(puntos);
    throw StateError('inesperado');
  }
}
