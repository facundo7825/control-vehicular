import 'dart:async';

import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
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

  setUp(() {
    api = ApiChofer();
    gps = UbicadorFalso();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
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
}

/// Un envío que falla con algo que no es un [ErrorApi] (un error de programación, un plugin).
class _ApiRota extends ApiChofer {
  @override
  Future<void> enviarUbicacion(List<PuntoGps> puntos) async {
    await super.enviarUbicacion(puntos);
    throw StateError('inesperado');
  }
}
