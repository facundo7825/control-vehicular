import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/corredor_teselas.dart';
import 'package:vehiculos_oficiales/src/mapa/descarga_corredor.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_osm.dart';
import 'package:vehiculos_oficiales/src/mapa/ruta.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

/// El viaje actual que pone el test, sin API ni socket.
class _ViajeFijo extends ViajeActualNotifier {
  static Viaje? inicial;

  @override
  Future<SeguimientoViaje> build() async => SeguimientoViaje(viaje: inicial);

  void poner(Viaje? v) => state = AsyncData(SeguimientoViaje(viaje: v));
}

/// 40 km al sur del origen del viaje del fixture, un punto cada 10 km.
final _ruta = Ruta(
  distanciaM: 40000,
  duracionS: 2400,
  puntos: [for (var i = 0; i <= 4; i++) Coordenada(-26.8241 - i * 0.09, -65.2226)],
  pasos: const [],
);

void main() {
  late ApiFalsa api;
  late DescargadorFalso descargador;

  setUp(() {
    api = ApiFalsa()..ruta = _ruta;
    descargador = DescargadorFalso();
    _ViajeFijo.inicial = null;
  });

  Future<ProviderContainer> crear({Viaje? viajeActual, DescargadorTeselas? conDescargador}) async {
    _ViajeFijo.inicial = viajeActual;
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      usuarioProvider.overrideWithValue(chofer),
      viajeActualProvider.overrideWith(_ViajeFijo.new),
      descargadorTeselasProvider.overrideWithValue(conDescargador ?? descargador),
    ]);
    c.listen(descargaCorredorProvider, (_, _) {});
    await c.read(viajeActualProvider.future);
    await pumpEventQueue();
    return c;
  }

  Viaje largo(String estado, {int id = 7}) => viaje(id: id, estado: estado, conChofer: true, tipo: 'largo');

  test('un viaje largo en camino baja las teselas del recorrido de origen a destino, zoom 10 a 14', () async {
    final v = largo('en_camino');
    final c = await crear(viajeActual: v);

    expect(api.consultasRuta.single, (
      TramoRuta(v.origen.coordenada, v.destino.coordenada).origen,
      TramoRuta(v.origen.coordenada, v.destino.coordenada).destino,
    ));
    final esperadas = teselasDelCorredor(_ruta.puntos);
    expect(descargador.pedidas.toSet(), esperadas.toSet());
    expect(descargador.pedidas.map((t) => t.z).toSet(), {10, 11, 12, 13, 14});
    final estado = c.read(descargaCorredorProvider)!;
    expect(estado.viajeId, 7);
    expect(estado.total, esperadas.length);
    expect(estado.bajadas, esperadas.length);
    expect(estado.terminada, isTrue);
  });

  test('también si la app se abre con el viaje largo ya en curso', () async {
    await crear(viajeActual: largo('en_curso'));
    expect(descargador.pedidas, isNotEmpty);
  });

  test('no baja nada antes de salir, en un viaje que no es largo ni sin viaje', () async {
    await crear(viajeActual: largo('aceptado'));
    expect(descargador.pedidas, isEmpty);

    await crear(
      viajeActual: viaje(estado: 'en_camino', conChofer: true, tipo: 'reserva'),
    );
    expect(descargador.pedidas, isEmpty);

    await crear();
    expect(descargador.pedidas, isEmpty);
    expect(api.consultasRuta, isEmpty);
  });

  test('sin recorrido (la ruta no llegó) no baja nada', () async {
    api.ruta = null;
    final c = await crear(viajeActual: largo('en_camino'));
    expect(descargador.pedidas, isEmpty);
    expect(c.read(descargaCorredorProvider), isNull);
  });

  test('con un servidor que llega hasta el zoom 12, solo hasta ese', () async {
    await crear(viajeActual: largo('en_camino'), conDescargador: descargador = DescargadorFalso(zoomMaximo: 12));
    expect(descargador.pedidas.map((t) => t.z).toSet(), {10, 11, 12});
  });

  test('sin descargador (mapa de Google, web) no pide ni la ruta', () async {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      usuarioProvider.overrideWithValue(chofer),
      viajeActualProvider.overrideWith(_ViajeFijo.new),
      descargadorTeselasProvider.overrideWithValue(null),
    ]);
    _ViajeFijo.inicial = largo('en_camino');
    c.listen(descargaCorredorProvider, (_, _) {});
    await c.read(viajeActualProvider.future);
    await pumpEventQueue();
    expect(api.consultasRuta, isEmpty);
    expect(c.read(descargaCorredorProvider), isNull);
  });

  test('si cambia el viaje se cancela: no pide más teselas del anterior', () async {
    descargador.demorar = true;
    final c = await crear(viajeActual: largo('en_camino'));
    expect(descargador.pedidas, hasLength(4));

    (c.read(viajeActualProvider.notifier) as _ViajeFijo).poner(largo('finalizado'));
    await pumpEventQueue();
    descargador.liberar();
    await pumpEventQueue();

    expect(descargador.pedidas, hasLength(4));
    expect(c.read(descargaCorredorProvider), isNull);
  });

  test('pasar de en camino a en curso no la reinicia', () async {
    descargador.demorar = true;
    final c = await crear(viajeActual: largo('en_camino'));
    (c.read(viajeActualProvider.notifier) as _ViajeFijo).poner(largo('en_curso'));
    await pumpEventQueue();
    expect(descargador.pedidas, hasLength(4));
    expect(api.consultasRuta, hasLength(1));
    descargador.demorar = false;
    descargador.liberar();
    await pumpEventQueue();
    expect(c.read(descargaCorredorProvider)!.terminada, isTrue);
  });

  test('al cerrar el módulo se cancela', () async {
    descargador.demorar = true;
    final c = await crear(viajeActual: largo('en_camino'));
    c.dispose();
    descargador.liberar();
    await pumpEventQueue();
    expect(descargador.pedidas, hasLength(4));
  });
}
