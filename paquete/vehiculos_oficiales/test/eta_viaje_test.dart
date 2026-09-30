import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/viaje/eta_viaje.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

Eta eta({String hacia = 'origen', int? segundos = 240, int? metros = 1850}) =>
    Eta(hacia: hacia, segundos: segundos, metros: metros, calculadoEn: DateTime.utc(2026, 10, 1, 12));

void main() {
  late ApiFalsa api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiFalsa();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(solicitante),
    ]);
    c.listen(viajeActualProvider, (_, _) {});
    c.listen(etaViajeProvider, (_, _) {});
    return c;
  }

  void cambiarEstado(String estado) =>
      tr.emitir('viaje.1', Eventos.viajeActualizado, jsonViaje(viaje(estado: estado, conChofer: true)));

  test('consulta al crearse y cada 30 s, no antes', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      api.etaRespuesta = eta();
      final c = crear();
      async.elapse(Duration.zero);

      expect(api.consultasEta, 1);
      expect(c.read(etaViajeProvider)!.segundos, 240);

      async.elapse(const Duration(seconds: 29));
      expect(api.consultasEta, 1);
      async.elapse(const Duration(seconds: 1));
      expect(api.consultasEta, 2);
    });
  });

  test('vuelve a consultar enseguida al cambiar el estado y usa el nuevo hacia', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      api.etaRespuesta = eta();
      final c = crear();
      async.elapse(Duration.zero);

      api.etaRespuesta = eta(hacia: 'destino', segundos: 600);
      cambiarEstado('en_curso');
      async.elapse(Duration.zero);

      expect(api.consultasEta, 2);
      expect(c.read(etaViajeProvider)!.hacia, 'destino');
    });
  });

  test('sin chofer en camino o al llegar no consulta', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.elapse(Duration.zero);
      async.elapse(const Duration(seconds: 60));
      expect(api.consultasEta, 0);
      expect(c.read(etaViajeProvider), isNull);

      api.etaRespuesta = eta();
      cambiarEstado('aceptado');
      async.elapse(Duration.zero);
      expect(api.consultasEta, 1);

      cambiarEstado('llego');
      async.elapse(Duration.zero);
      async.elapse(const Duration(seconds: 60));
      expect(api.consultasEta, 1);
      expect(c.read(etaViajeProvider), isNull);
    });
  });

  test('deja de consultar al terminar o descartar el viaje', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      api.etaRespuesta = eta();
      final c = crear();
      async.elapse(Duration.zero);
      expect(api.consultasEta, 1);

      cambiarEstado('cancelado');
      async.elapse(Duration.zero);
      async.elapse(const Duration(seconds: 90));
      expect(api.consultasEta, 1);
      expect(c.read(etaViajeProvider), isNull);

      c.read(viajeActualProvider.notifier).descartar();
      async.elapse(Duration.zero);
      async.elapse(const Duration(seconds: 90));
      expect(api.consultasEta, 1);
    });
  });

  test('al liberar el contenedor cancela el timer', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      api.etaRespuesta = eta();
      final c = crear();
      async.elapse(Duration.zero);

      c.dispose();
      async.elapse(const Duration(seconds: 90));
      expect(api.consultasEta, 1);
      expect(async.pendingTimers, isEmpty);
    });
  });

  test('un error de la API no escapa y conserva el valor anterior hasta el próximo ciclo', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      api.etaRespuesta = eta();
      final c = crear();
      async.elapse(Duration.zero);

      api.fallarEta = const SinConexion();
      async.elapse(const Duration(seconds: 30));
      expect(api.consultasEta, 2);
      expect(c.read(etaViajeProvider)!.segundos, 240);

      api.fallarEta = const SesionInvalida();
      async.elapse(const Duration(seconds: 30));
      expect(api.consultasEta, 3);
      expect(c.read(etaViajeProvider)!.segundos, 240);

      api.fallarEta = null;
      api.etaRespuesta = eta(segundos: 120);
      async.elapse(const Duration(seconds: 30));
      expect(c.read(etaViajeProvider)!.segundos, 120);
    });
  });

  test('si falla la primera consulta queda sin valor', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      api.fallarEta = const SinConexion();
      final c = crear();
      async.elapse(Duration.zero);

      expect(c.read(etaViajeProvider), isNull);
    });
  });
}
