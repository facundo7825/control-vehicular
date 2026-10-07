import 'dart:io' show SocketException;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/corredor_teselas.dart';
import 'package:vehiculos_oficiales/src/mapa/descarga_corredor.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa_osm.dart';
import 'package:vehiculos_oficiales/src/mapa/ruta.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
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
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiFalsa()..ruta = _ruta;
    descargador = DescargadorFalso();
    tr = TiempoRealFalso();
    _ViajeFijo.inicial = null;
  });

  Future<ProviderContainer> crear({Viaje? viajeActual, DescargadorTeselas? conDescargador}) async {
    _ViajeFijo.inicial = viajeActual;
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      usuarioProvider.overrideWithValue(chofer),
      viajeActualProvider.overrideWith(_ViajeFijo.new),
      descargadorTeselasProvider.overrideWithValue(conDescargador ?? descargador),
      tiempoRealProvider.overrideWithValue(tr),
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
      tiempoRealProvider.overrideWithValue(tr),
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

  test('sin recorrido al salir (sin señal), al reconectar se vuelve a pedir y se baja', () async {
    api.ruta = null;
    final c = await crear(viajeActual: largo('en_camino'));
    expect(descargador.pedidas, isEmpty);

    api.ruta = _ruta;
    tr
      ..cambiar(EstadoConexion.desconectado)
      ..cambiar(EstadoConexion.conectado);
    await pumpEventQueue();

    expect(api.consultasRuta, hasLength(2));
    expect(descargador.pedidas.toSet(), teselasDelCorredor(_ruta.puntos).toSet());
    expect(c.read(descargaCorredorProvider)!.terminada, isTrue);
  });

  test('una descarga que se dejó por falta de señal se retoma al reconectar', () async {
    final fallando = DescargadorQueFalla();
    final c = await crear(viajeActual: largo('en_camino'), conDescargador: fallando);
    expect(c.read(descargaCorredorProvider)!.bajadas, 0);
    expect(c.read(descargaCorredorProvider)!.terminada, isTrue);

    fallando.falla = false;
    tr
      ..cambiar(EstadoConexion.desconectado)
      ..cambiar(EstadoConexion.conectado);
    await pumpEventQueue();

    final estado = c.read(descargaCorredorProvider)!;
    expect(estado.bajadas, estado.total);
  });

  test('una descarga completa no se repite al reconectar', () async {
    final c = await crear(viajeActual: largo('en_camino'));
    final pedidas = descargador.pedidas.length;

    tr
      ..cambiar(EstadoConexion.desconectado)
      ..cambiar(EstadoConexion.conectado);
    await pumpEventQueue();

    expect(descargador.pedidas, hasLength(pedidas));
    expect(c.read(descargaCorredorProvider)!.terminada, isTrue);
  });
}

/// Sin señal: cada tesela falla mientras [falla] sea verdadero.
class DescargadorQueFalla implements DescargadorTeselas {
  bool falla = true;

  @override
  int get zoomMaximo => 19;

  @override
  Future<void> descargar(Tesela tesela) async {
    if (falla) throw const SocketException('sin red');
  }
}
