import 'dart:io';
import 'dart:math' as math;

import 'package:fake_async/fake_async.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/almacen_cola.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_acciones.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'soporte/dobles.dart';
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  final binding = TestWidgetsFlutterBinding.ensureInitialized();
  late ApiChofer api;
  late TiempoRealFalso tr;
  late UbicadorFalso gps;
  late AlmacenAccionesMemoria almacen;
  late List<String> avisos;

  setUp(() {
    api = ApiChofer();
    tr = TiempoRealFalso();
    gps = UbicadorFalso();
    almacen = AlmacenAccionesMemoria();
    avisos = [];
    binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
  });

  /// Con [conTurno] también corre el turno (y su GPS).
  ProviderContainer crear({bool conTurno = false}) {
    final c = (EntornoPrueba()..almacenAcciones = almacen).contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(chofer),
      ubicadorProvider.overrideWithValue(gps),
    ]);
    c.listen(viajeActualProvider, (_, _) {});
    c.listen(avisoAccionProvider, (_, a) => avisos.add(a!.mensaje));
    if (conTurno) c.listen(turnoProvider, (_, _) {});
    return c;
  }

  EstadoViaje? estado(ProviderContainer c) => c.read(viajeActualProvider).requireValue.viaje?.estado;
  List<AccionViaje> pendientes(ProviderContainer c) => c.read(colaAccionesProvider).requireValue;
  ViajeActualNotifier viajeActual(ProviderContainer c) => c.read(viajeActualProvider.notifier);

  /// El socket se cae y vuelve.
  void reconectar() {
    tr
      ..cambiar(EstadoConexion.desconectado)
      ..cambiar(EstadoConexion.conectado);
  }

  group('sin señal', () {
    test('"Llegué" e "Iniciar viaje" avanzan al instante, con su hora, y quedan en la cola guardada', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();
        api.errorAvance = const SinConexion();

        viajeActual(c).avanzar(EstadoViaje.llego);
        async.flushMicrotasks();
        async.elapse(const Duration(seconds: 5));
        viajeActual(c).avanzar(EstadoViaje.enCurso);
        async.flushMicrotasks();

        expect(estado(c), EstadoViaje.enCurso);
        final viajeLocal = c.read(viajeActualProvider).requireValue.viaje!;
        final [llego, enCurso] = pendientes(c);
        expect((llego.viajeId, llego.estado), (1, EstadoViaje.llego));
        expect((enCurso.viajeId, enCurso.estado), (1, EstadoViaje.enCurso));
        expect(viajeLocal.llegoEn, llego.momento);
        expect(viajeLocal.iniciadoEn, enCurso.momento);
        expect(enCurso.momento.difference(llego.momento), const Duration(seconds: 5));
        expect(llego.id, isNot(enCurso.id));
        expect(almacen.acciones.map((a) => a.id), [llego.id, enCurso.id]);
        expect(almacen.usuarioId, chofer.id);
      });
    });

    test('al reconectar manda las acciones en orden, con su hora y su id, y recién después el GPS', () {
      fakeAsync((async) {
        api
          ..turno = turnoDePrueba()
          ..actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear(conTurno: true);
        async.flushMicrotasks();
        api.errorAvance = const SinConexion();

        viajeActual(c).avanzar(EstadoViaje.llego);
        async.flushMicrotasks();
        viajeActual(c).avanzar(EstadoViaje.enCurso);
        async.flushMicrotasks();
        gps
          ..emitir(punto(0))
          ..emitir(punto(5));
        async.elapse(const Duration(seconds: 10));
        // Los puntos esperan: el servidor todavía no sabe que el viaje empezó.
        expect(api.lotes, isEmpty);
        final encoladas = pendientes(c);

        api
          ..errorAvance = null
          ..llamadas.clear()
          ..acciones.clear();
        reconectar();
        async.flushMicrotasks();

        expect(api.llamadas, ['avanzar:1:llego', 'avanzar:1:en_curso', 'ubicacion:2']);
        expect(api.acciones.map((a) => (a.idAccion, a.momento)), [for (final a in encoladas) (a.id, a.momento)]);
        expect(pendientes(c), isEmpty);
        expect(almacen.acciones, isEmpty);
        expect(estado(c), EstadoViaje.enCurso);
      });
    });

    test('reintento idempotente: la misma acción sale otra vez, con el mismo id y la misma hora, a los 30 s', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_curso', conChofer: true));
        final c = crear();
        async.flushMicrotasks();
        // Llegó al servidor, pero la respuesta se perdió.
        api.erroresAvance.add(const SinConexion());

        viajeActual(c).avanzar(EstadoViaje.finalizado);
        async.flushMicrotasks();
        expect(api.acciones, hasLength(1));
        expect(pendientes(c), hasLength(1));
        expect(estado(c), EstadoViaje.finalizado);

        async.elapse(ColaAccionesNotifier.intervaloReintento);
        expect(api.acciones, hasLength(2));
        expect(api.acciones[1].idAccion, api.acciones[0].idAccion);
        expect(api.acciones[1].momento, api.acciones[0].momento);
        expect(pendientes(c), isEmpty);

        async.elapse(const Duration(minutes: 2)); // sin pendientes no hay más reintentos
        expect(api.acciones, hasLength(2));
      });
    });

    test('conflicto: se descartan las acciones de ese viaje, se muestra cómo quedó y se avisa', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();
        api.errorAvance = const SinConexion();
        viajeActual(c).avanzar(EstadoViaje.llego);
        async.flushMicrotasks();
        viajeActual(c).avanzar(EstadoViaje.enCurso);
        async.flushMicrotasks();

        // Mientras tanto un administrador lo canceló.
        api
          ..errorAvance = null
          ..erroresAvance.add(const Conflicto('El viaje fue cancelado mientras estabas sin señal.'))
          ..actual = ViajeActual.vacio
          ..detalles[1] = viaje(estado: 'cancelado', conChofer: true)
          ..avances.clear();
        reconectar();
        async.flushMicrotasks();

        expect(api.avances, [(1, EstadoViaje.llego)]); // el "Iniciar viaje" ya no sale
        expect(pendientes(c), isEmpty);
        expect(almacen.acciones, isEmpty);
        expect(avisos, ['El viaje fue cancelado mientras estabas sin señal.']);
        expect(estado(c), EstadoViaje.cancelado);
      });
    });

    test('un 422 (p. ej. la hora no es válida) también descarta y vuelve al estado real', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_curso', conChofer: true));
        final c = crear();
        async.flushMicrotasks();
        api.erroresAvance.add(const ErrorNegocio('La hora del paso no es válida.'));

        viajeActual(c).avanzar(EstadoViaje.finalizado);
        async.flushMicrotasks();

        expect(pendientes(c), isEmpty);
        expect(avisos, ['La hora del paso no es válida.']);
        expect(estado(c), EstadoViaje.enCurso);
        // Ya no cuenta como finalizado: un evento del mismo viaje se aplica.
        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'en_curso', conChofer: true)));
        expect(estado(c), EstadoViaje.enCurso);
      });
    });

    test('al reiniciar la app lo guardado se vuelve a aplicar sobre el estado del servidor hasta que sale', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
        final antes = crear();
        async.flushMicrotasks();
        api.errorAvance = const SinConexion();
        viajeActual(antes).avanzar(EstadoViaje.llego);
        async.flushMicrotasks();
        viajeActual(antes).avanzar(EstadoViaje.enCurso);
        async.flushMicrotasks();
        antes.dispose();
        async.flushMicrotasks();

        // El servidor sigue en en_camino; la consulta responde, pero las acciones todavía no salen.
        final c = crear();
        async.flushMicrotasks();
        expect(estado(c), EstadoViaje.enCurso);
        expect(pendientes(c).map((a) => a.estado), [EstadoViaje.llego, EstadoViaje.enCurso]);
        async.elapse(const Duration(seconds: 10)); // el respaldo vuelve a consultar: no lo pisa
        expect(estado(c), EstadoViaje.enCurso);

        final hechos = api.avances.length;
        api.errorAvance = null;
        async.elapse(ColaAccionesNotifier.intervaloReintento);
        expect(pendientes(c), isEmpty);
        expect(almacen.acciones, isEmpty);
        expect(api.avances.skip(hechos), [(1, EstadoViaje.llego), (1, EstadoViaje.enCurso)]);
      });
    });

    test('al volver a primer plano se intenta enseguida, sin esperar los 30 s', () {
      fakeAsync((async) {
        api
          ..turno = turnoDePrueba()
          ..actual = ViajeActual(viaje: viaje(estado: 'en_curso', conChofer: true));
        final c = crear(conTurno: true);
        async.flushMicrotasks();
        api.errorAvance = const SinConexion();
        viajeActual(c).avanzar(EstadoViaje.finalizado);
        async.flushMicrotasks();
        expect(pendientes(c), hasLength(1));

        api.errorAvance = null;
        for (final e in [AppLifecycleState.inactive, AppLifecycleState.hidden, AppLifecycleState.paused]) {
          binding.handleAppLifecycleStateChanged(e);
        }
        for (final e in [AppLifecycleState.hidden, AppLifecycleState.inactive, AppLifecycleState.resumed]) {
          binding.handleAppLifecycleStateChanged(e);
        }
        async.flushMicrotasks();

        expect(pendientes(c), isEmpty);
      });
    });

    test('lo guardado por otro usuario no se manda', () {
      fakeAsync((async) {
        almacen
          ..usuarioId = 99
          ..acciones = [
            AccionViaje(id: nuevoIdAccion(), viajeId: 5, estado: EstadoViaje.llego, momento: DateTime.utc(2026)),
          ];
        final c = crear();
        async.flushMicrotasks();

        expect(pendientes(c), isEmpty);
        expect(api.avances, isEmpty);
      });
    });
  });

  group('"Voy en camino"', () {
    test('espera la respuesta: un 422 llega a quien lo tocó, sin aviso aparte, y no queda en la cola', () {
      fakeAsync((async) {
        api.actual = ViajeActual(
          viaje: viaje(estado: 'aceptado', conChofer: true, tipo: 'reserva'),
        );
        final c = crear();
        async.flushMicrotasks();
        api.erroresAvance.add(const ErrorNegocio('Podés salir hacia esta reserva a partir de las 11:15.'));

        Object? error;
        viajeActual(c).avanzar(EstadoViaje.enCamino).catchError((Object e) => error = e);
        async.flushMicrotasks();

        expect(error, isA<ErrorNegocio>());
        expect(avisos, isEmpty);
        expect(pendientes(c), isEmpty);
        expect(estado(c), EstadoViaje.aceptado);
      });
    });

    test('sin señal hacia una reserva: pasa a ser el viaje actual y queda en la cola', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();
        api.errorAvance = const SinConexion();

        Object? error;
        viajeActual(c)
            .salirHaciaReserva(viaje(id: 7, estado: 'aceptado', conChofer: true, tipo: 'reserva'))
            .catchError((Object e) => error = e);
        async.flushMicrotasks();

        expect(error, isNull);
        expect(c.read(viajeActualProvider).requireValue.viaje!.id, 7);
        expect(estado(c), EstadoViaje.enCamino);
        expect(pendientes(c).single.viajeId, 7);
      });
    });
  });

  test('"Finalizar turno" con acciones sin enviar: se intentan mandar y, si no salen, el turno sigue', () {
    fakeAsync((async) {
      api
        ..turno = turnoDePrueba()
        ..actual = ViajeActual(viaje: viaje(estado: 'en_curso', conChofer: true));
      final c = crear(conTurno: true);
      async.flushMicrotasks();
      api.errorAvance = const SinConexion();
      viajeActual(c).avanzar(EstadoViaje.finalizado);
      async.flushMicrotasks();

      Object? error;
      c.read(turnoProvider.notifier).finalizar().catchError((Object e) => error = e);
      async.flushMicrotasks();

      expect(error, isA<SinConexion>().having((e) => e.mensaje, 'mensaje', 'Esperando señal para enviar el viaje.'));
      expect(api.llamadas, isNot(contains('finalizar')));
      expect(c.read(turnoProvider).value, isNotNull);
    });
  });

  group('AlmacenAccionesArchivo', () {
    late Directory dir;
    late AlmacenAccionesArchivo almacenArchivo;

    setUp(() {
      dir = Directory.systemTemp.createTempSync('acciones');
      almacenArchivo = AlmacenAccionesArchivo(() async => dir);
    });

    tearDown(() => dir.deleteSync(recursive: true));

    test('guarda y lee las acciones del mismo usuario, en orden; las de otro no', () async {
      final acciones = [
        AccionViaje(id: nuevoIdAccion(), viajeId: 1, estado: EstadoViaje.llego, momento: DateTime.utc(2026, 10, 7, 12)),
        AccionViaje(
          id: nuevoIdAccion(),
          viajeId: 1,
          estado: EstadoViaje.enCurso,
          momento: DateTime.utc(2026, 10, 7, 12, 5, 0, 123, 456),
        ),
      ];
      await almacenArchivo.guardar(2, acciones);

      final leidas = await almacenArchivo.leer(2);
      expect(leidas.map((a) => (a.id, a.viajeId, a.estado, a.momento)), [
        for (final a in acciones) (a.id, a.viajeId, a.estado, a.momento),
      ]);
      expect(await almacenArchivo.leer(3), isEmpty);
      expect(File('${dir.path}/${AlmacenColaArchivo.subdirectorio}/cola_acciones.json').existsSync(), isTrue);
    });

    test('un archivo ilegible se lee como vacío', () async {
      final archivo = File('${dir.path}/${AlmacenColaArchivo.subdirectorio}/cola_acciones.json')
        ..createSync(recursive: true)
        ..writeAsStringSync('{"usuario_id":2,"acciones":[{"id_ac');
      expect(archivo.existsSync(), isTrue);

      expect(await almacenArchivo.leer(2), isEmpty);
    });
  });

  test('nuevoIdAccion es un UUID v4', () {
    final uuid = RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$');
    final ids = {for (var i = 0; i < 100; i++) nuevoIdAccion()};
    expect(ids, hasLength(100));
    expect(ids.every(uuid.hasMatch), isTrue);
    expect(nuevoIdAccion(math.Random(1)), nuevoIdAccion(math.Random(1)));
  });
}
