import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/solicitante/choferes_mapa.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

ChoferEnMapa carlos({String estado = 'libre'}) =>
    ChoferEnMapa.fromJson(leerMapa(p.jsonLista(p.choferes).single)..['estado'] = estado);

ChoferEnMapa otro(int id) => ChoferEnMapa.fromJson(
  leerMapa(p.jsonLista(p.choferes).single)
    ..['id'] = id
    ..['nombre'] = 'Chofer $id',
);

void main() {
  late ApiFalsa api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiFalsa()..listaChoferes = [carlos()];
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
    ]);
    c.listen(choferesMapaProvider, (_, _) {});
    return c;
  }

  List<ChoferEnMapa> leer(ProviderContainer c) => c.read(choferesMapaProvider).requireValue;

  test('carga los choferes y escucha mapa.choferes', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      expect(leer(c).single.nombre, 'Carlos Gómez');
      expect(tr.canalesActivos, {'mapa.choferes'});
    });
  });

  test('mueve al chofer y cambia su estado con los eventos', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('mapa.choferes', Eventos.choferUbicacion, {...p.json(p.eventoUbicacion), 'lat': -26.9, 'lng': -65.3});
      tr.emitir('mapa.choferes', Eventos.choferEstado, p.json(p.eventoEstadoChofer));

      expect(leer(c).single.posicion, const Coordenada(-26.9, -65.3));
      expect(leer(c).single.estado, EstadoChofer.enViaje);
      expect(api.consultasChoferes, 1);
    });
  });

  test('saca al chofer que termina el turno y recarga si aparece uno desconocido', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('mapa.choferes', Eventos.choferEstado, {'chofer_id': 2, 'estado': 'fuera_de_turno'});
      expect(leer(c), isEmpty);

      api.listaChoferes = [carlos(), otro(9)];
      tr.emitir('mapa.choferes', Eventos.choferEstado, {'chofer_id': 9, 'estado': 'libre'});
      async.flushMicrotasks();
      expect(leer(c).map((c) => c.id), [2, 9]);
      expect(api.consultasChoferes, 2);
    });
  });

  test('con el socket caído consulta cada 10 s y al reconectar deja de hacerlo', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.cambiar(EstadoConexion.desconectado);
      api.listaChoferes = [carlos(estado: 'reservado_pronto')];
      async.elapse(const Duration(seconds: 10));
      expect(leer(c).single.estado, EstadoChofer.reservadoPronto);
      expect(leer(c).single.seleccionable, isFalse);

      tr.cambiar(EstadoConexion.conectado);
      async.flushMicrotasks();
      final consultas = api.consultasChoferes;
      async.elapse(const Duration(seconds: 60));
      expect(api.consultasChoferes, consultas);
    });
  });

  test('un 401 durante el sondeo no produce un error sin capturar y se conserva la lista', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.cambiar(EstadoConexion.desconectado);
      api.fallarConsultas = const SesionInvalida();
      async.elapse(const Duration(seconds: 20));
      async.flushMicrotasks();

      expect(api.consultasChoferes, 3);
      expect(leer(c).single.nombre, 'Carlos Gómez');
    });
  });

  test('un evento mal formado (estado desconocido, datos faltantes) se ignora sin escapar', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('mapa.choferes', Eventos.choferEstado, {'chofer_id': 2, 'estado': 'de_vacaciones'});
      tr.emitir('mapa.choferes', Eventos.choferEstado, {'estado': 'libre'});
      tr.emitir('mapa.choferes', Eventos.choferUbicacion, {'chofer_id': 2});
      async.flushMicrotasks();

      expect(leer(c).single.estado, EstadoChofer.libre);
      expect(leer(c).single.posicion, const Coordenada(-26.8301, -65.2001));
    });
  });
}
