import 'dart:async';

import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

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
}
