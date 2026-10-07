import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/solicitante/borrador_pedido.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import 'soporte/dobles.dart';

const _aqui = Coordenada(-26.8241, -65.2226);
const _tribunales = LugarEncontrado(
  nombre: 'Tribunales',
  direccion: 'Tribunales, 24 de Septiembre 677, Tucumán',
  coordenada: Coordenada(-26.83, -65.2),
);

void main() {
  late ProviderContainer c;
  late UbicadorFalso ubicador;
  late ApiFalsa api;
  BorradorPedidoNotifier notifier() => c.read(borradorPedidoProvider.notifier);
  BorradorPedido borrador() => c.read(borradorPedidoProvider);

  setUp(() {
    ubicador = UbicadorFalso(_aqui);
    api = ApiFalsa();
    c = ProviderContainer.test(
      overrides: [ubicadorProvider.overrideWithValue(ubicador), apiProvider.overrideWithValue(api)],
    );
  });

  test('sin ubicación, el primer toque marca el origen y el segundo el destino', () async {
    ubicador.posicion = null;
    await notifier().ubicar();
    expect(borrador().marcando, PuntoPedido.origen);

    notifier().marcar(const Coordenada(1, 1));
    expect(borrador().marcando, PuntoPedido.destino);
    notifier().marcar(const Coordenada(2, 2));

    expect(borrador().origen, const Coordenada(1, 1));
    expect(borrador().destino, const Coordenada(2, 2));
    expect(borrador().completo, isTrue);
  });

  test('sin chofer pide el más cercano; con chofer, a ese chofer; los textos vacíos no se mandan', () {
    notifier()
      ..fijar(PuntoPedido.origen, const Coordenada(1, 1))
      ..fijar(PuntoPedido.destino, const Coordenada(2, 2))
      ..direccion(PuntoPedido.destino, '  Tribunales ')
      ..motivo('   ');

    expect(borrador().pedido().toJson(), {
      'modo': 'mas_cercano',
      'origen_lat': 1.0,
      'origen_lng': 1.0,
      'destino_lat': 2.0,
      'destino_lng': 2.0,
      'destino_direccion': 'Tribunales',
    });

    final carlos = ChoferEnMapa(
      id: 2,
      nombre: 'Carlos',
      estado: EstadoChofer.libre,
      vehiculo: const Vehiculo(patente: 'A', marca: 'B', modelo: 'C'),
    );
    notifier().elegirChofer(carlos);
    expect(borrador().pedido().toJson()['modo'], 'especifico');
    expect(borrador().pedido().toJson()['chofer_id'], 2);

    notifier().elegirChofer(null);
    expect(borrador().chofer, isNull);
  });

  test('desde un viaje sin chofer copia origen, destino y motivo', () {
    notifier().desdeViaje(viaje(estado: 'sin_chofer'));

    expect(borrador().lugarOrigen!.descripcion, 'Plaza Independencia');
    expect(borrador().lugarDestino!.descripcion, 'Tribunales');
    expect(borrador().motivo, 'Audiencia');
    expect(borrador().chofer, isNull);
    expect(borrador().origenEsMiUbicacion, isFalse);
  });

  group('ubicación actual', () {
    test('con permiso, el origen es la ubicación actual, se centra ahí y sigue el destino', () async {
      expect(borrador().ubicacion, EstadoUbicacion.buscando);

      expect(await notifier().ubicar(), isTrue);

      expect(borrador().ubicacion, EstadoUbicacion.obtenida);
      expect(borrador().miUbicacion, _aqui);
      expect(borrador().origen, _aqui);
      expect(borrador().origenEsMiUbicacion, isTrue);
      expect(borrador().descripcion(PuntoPedido.origen), 'Tu ubicación actual');
      expect(borrador().marcando, PuntoPedido.destino);
      expect(borrador().enfoque!.puntos, [_aqui]);
      // El pedido manda las coordenadas, sin dirección inventada.
      notifier().fijar(PuntoPedido.destino, const Coordenada(2, 2));
      expect(borrador().pedido().toJson(), {
        'modo': 'mas_cercano',
        'origen_lat': _aqui.lat,
        'origen_lng': _aqui.lng,
        'destino_lat': 2.0,
        'destino_lng': 2.0,
      });
    });

    test('sin permiso no hay origen ni enfoque: se marca a mano', () async {
      ubicador.posicion = null;

      expect(await notifier().ubicar(), isFalse);

      expect(borrador().ubicacion, EstadoUbicacion.noDisponible);
      expect(borrador().origen, isNull);
      expect(borrador().enfoque, isNull);
      expect(borrador().marcando, PuntoPedido.origen);
    });

    test('volver a ubicar re-centra (otra versión del enfoque) y no pisa un origen marcado a mano', () async {
      await notifier().ubicar();
      final primero = borrador().enfoque!;
      notifier().fijar(PuntoPedido.origen, const Coordenada(1, 1));
      expect(borrador().origenEsMiUbicacion, isFalse);

      await notifier().ubicar();

      expect(borrador().origen, const Coordenada(1, 1));
      expect(borrador().enfoque!.puntos, [_aqui]);
      expect(borrador().enfoque, isNot(primero));
    });

    test('mientras se busca la ubicación, lo que se marca es el destino; al llegar, el origen es ella', () async {
      ubicador.retener = Completer();
      expect(borrador().marcando, PuntoPedido.destino);
      final ubicando = notifier().ubicar();

      notifier().marcar(const Coordenada(2, 2));
      expect(borrador().destino, const Coordenada(2, 2));
      expect(borrador().origen, isNull);
      ubicador.retener!.complete();
      await ubicando;

      expect(borrador().origen, _aqui);
      expect(borrador().origenEsMiUbicacion, isTrue);
      expect(borrador().destino, const Coordenada(2, 2));
      expect(borrador().marcando, PuntoPedido.destino);
      // Ya había elegido el destino: el mapa no salta solo a la ubicación, encuadra el pedido.
      expect(borrador().enfoque!.puntos, [_aqui, const Coordenada(2, 2)]);
    });

    test('lo marcado mientras se busca que no sea el destino no hace saltar el mapa', () async {
      ubicador.retener = Completer();
      final ubicando = notifier().ubicar();
      notifier().fijar(PuntoPedido.origen, const Coordenada(1, 1));
      ubicador.retener!.complete();
      await ubicando;

      expect(borrador().origen, const Coordenada(1, 1));
      expect(borrador().enfoque, isNull);
    });

    test('con el destino ya elegido, al llegar la ubicación se encuadran ella y el destino', () async {
      ubicador.retener = Completer();
      final ubicando = notifier().ubicar();
      notifier().elegirLugar(_tribunales); // el mapa mira solo el destino
      ubicador.retener!.complete();
      await ubicando;

      expect(borrador().origen, _aqui);
      expect(borrador().enfoque!.puntos, [_aqui, _tribunales.coordenada]);
    });

    test('"Elegir otro" con el origen en la ubicación actual: el mapa encuadra ubicación y destino', () async {
      await notifier().ubicar();
      final v = viaje(estado: 'sin_chofer');
      final j = jsonViaje(v, conChofer: false)
        ..['origen'] = {'lat': _aqui.lat + 0.0003, 'lng': _aqui.lng, 'direccion': null};
      notifier().desdeViaje(Viaje.fromJson(j));
      final destino = borrador().destino!;

      await notifier().ubicar(); // como al volver a abrir el mapa

      expect(borrador().origen, _aqui);
      expect(borrador().enfoque!.puntos, [_aqui, destino]);
    });

    test('"Usar mi ubicación" con destino encuadra los dos', () async {
      await notifier().ubicar();
      notifier()
        ..elegirLugar(_tribunales)
        ..fijar(PuntoPedido.origen, const Coordenada(1, 1));

      await notifier().ubicar(comoOrigen: true);

      expect(borrador().enfoque!.puntos, [_aqui, _tribunales.coordenada]);
    });

    test('recuerda si el permiso se negó para siempre (para ofrecer los ajustes)', () async {
      ubicador
        ..posicion = null
        ..permiso = PermisoUbicacion.denegadoParaSiempre;

      await notifier().ubicar();
      expect(borrador().permisoUbicacion, PermisoUbicacion.denegadoParaSiempre);
      notifier().limpiar();
      expect(borrador().permisoUbicacion, PermisoUbicacion.denegadoParaSiempre);

      ubicador
        ..posicion = _aqui
        ..permiso = PermisoUbicacion.concedido;
      await notifier().ubicar();
      expect(borrador().permisoUbicacion, PermisoUbicacion.concedido);
    });

    test('si la ubicación no llega después de elegir el destino, lo que sigue es el origen', () async {
      ubicador
        ..posicion = null
        ..retener = Completer();
      final ubicando = notifier().ubicar();
      notifier().elegirLugar(_tribunales);
      final enfoque = borrador().enfoque;
      ubicador.retener!.complete();
      await ubicando;

      expect(borrador().destino, _tribunales.coordenada);
      expect(borrador().ubicacion, EstadoUbicacion.noDisponible);
      expect(borrador().marcando, PuntoPedido.origen);
      expect(borrador().enfoque, enfoque);
    });

    test('elegir un lugar para un punto dado (la búsqueda empezó antes de que cambiara lo que se marca)', () async {
      ubicador.posicion = null;
      await notifier().ubicar(); // ahora se marca el origen

      notifier().elegirLugar(_tribunales, punto: PuntoPedido.destino);

      expect(borrador().destino, _tribunales.coordenada);
      expect(borrador().origen, isNull);
      expect(borrador().marcando, PuntoPedido.origen);
    });

    test('"Mi ubicación" no borra la dirección de origen escrita cuando el origen es la ubicación', () async {
      await notifier().ubicar();
      notifier().direccion(PuntoPedido.origen, 'Puerta 2');

      await notifier().ubicar();

      expect(borrador().direccionOrigen, 'Puerta 2');
      expect(borrador().descripcion(PuntoPedido.origen), 'Puerta 2');
      notifier().fijar(PuntoPedido.destino, const Coordenada(2, 2));
      expect(borrador().pedido().toJson()['origen_direccion'], 'Puerta 2');
    });

    test(
      '"Elegir otro": un origen sin dirección a pasos de la ubicación actual sigue siendo "Tu ubicación actual"',
      () async {
        await notifier().ubicar();
        final v = viaje(estado: 'sin_chofer');
        final j = jsonViaje(v, conChofer: false)
          ..['origen'] = {'lat': _aqui.lat + 0.0003, 'lng': _aqui.lng, 'direccion': null}; // ~33 m
        notifier().desdeViaje(Viaje.fromJson(j));
        expect(borrador().origenEsMiUbicacion, isTrue);
        expect(borrador().descripcion(PuntoPedido.origen), 'Tu ubicación actual');

        j['origen'] = {'lat': _aqui.lat + 0.01, 'lng': _aqui.lng, 'direccion': null}; // ~1 km
        notifier().desdeViaje(Viaje.fromJson(j));
        expect(borrador().origenEsMiUbicacion, isFalse);
        await pumpEventQueue(); // sin dirección encontrada: nunca las coordenadas
        expect(borrador().descripcion(PuntoPedido.origen), 'Ubicación marcada en el mapa');
      },
    );

    test('sin centrar ("Elegir otro" con un origen elegido a mano) el mapa sigue mirando el pedido', () async {
      notifier().desdeViaje(viaje(estado: 'sin_chofer'));
      final pedido = borrador().enfoque;

      await notifier().ubicar(centrar: false);

      expect(borrador().enfoque, pedido);
      expect(borrador().miUbicacion, _aqui);
      expect(borrador().lugarOrigen!.descripcion, 'Plaza Independencia');
    });

    test('"Usar mi ubicación" la vuelve a poner como origen', () async {
      await notifier().ubicar();
      notifier().fijar(PuntoPedido.origen, const Coordenada(1, 1));

      expect(await notifier().ubicar(comoOrigen: true), isTrue);

      expect(borrador().origen, _aqui);
      expect(borrador().origenEsMiUbicacion, isTrue);
      expect(borrador().marcando, PuntoPedido.destino);
    });

    test('después de pedir, el borrador vuelve a salir de la ubicación actual', () async {
      await notifier().ubicar();
      notifier()
        ..fijar(PuntoPedido.destino, const Coordenada(2, 2))
        ..motivo('Audiencia')
        ..limpiar();

      expect(borrador().origen, _aqui);
      expect(borrador().origenEsMiUbicacion, isTrue);
      expect(borrador().destino, isNull);
      expect(borrador().motivo, '');
      expect(borrador().marcando, PuntoPedido.destino);
    });
  });

  group('elegir un lugar buscado', () {
    test('fija el destino con su dirección y encuadra origen y destino', () async {
      await notifier().ubicar();

      notifier().elegirLugar(_tribunales);

      expect(borrador().destino, _tribunales.coordenada);
      expect(borrador().direccionDestino, 'Tribunales, 24 de Septiembre 677, Tucumán');
      expect(borrador().enfoque!.puntos, [_aqui, _tribunales.coordenada]);
      expect(borrador().pedido().toJson()['destino_direccion'], 'Tribunales, 24 de Septiembre 677, Tucumán');
    });

    test('mientras se cambia el origen, fija el origen y pasa al destino', () async {
      await notifier().ubicar();
      notifier().marcarAhora(PuntoPedido.origen);

      notifier().elegirLugar(_tribunales);

      expect(borrador().origen, _tribunales.coordenada);
      expect(borrador().direccionOrigen, _tribunales.direccion);
      expect(borrador().origenEsMiUbicacion, isFalse);
      expect(borrador().marcando, PuntoPedido.destino);
      expect(borrador().enfoque, Enfoque.punto(_tribunales.coordenada, version: borrador().enfoque!.version));
    });

    test('una dirección larga se corta en el límite del backend (255)', () {
      notifier()
        ..marcarAhora(PuntoPedido.destino)
        ..elegirLugar(LugarEncontrado(nombre: 'X', direccion: 'a' * 300, coordenada: const Coordenada(1, 1)));

      expect(borrador().direccionDestino.length, 255);
    });
  });

  group('dirección de cada punto (geocodificación inversa)', () {
    const tocado = Coordenada(-26.8083, -65.2176);

    test('el origen en mi ubicación dice "Tu ubicación actual" con su dirección debajo, y la manda', () async {
      api
        ..direcciones[_aqui] = 'Sarmiento 520'
        ..demorarDireccion[_aqui] = Completer();

      await notifier().ubicar();
      expect(borrador().descripcion(PuntoPedido.origen), 'Tu ubicación actual');
      expect(borrador().detalleOrigen, 'Buscando la dirección…');

      api.demorarDireccion[_aqui]!.complete();
      await pumpEventQueue();

      expect(api.consultasDireccion, [_aqui]);
      expect(borrador().descripcion(PuntoPedido.origen), 'Tu ubicación actual');
      expect(borrador().detalleOrigen, 'Sarmiento 520');
      expect(borrador().direccionOrigen, ''); // el campo para escribir queda libre
      notifier().fijar(PuntoPedido.destino, const Coordenada(2, 2));
      expect(borrador().pedido().toJson()['origen_direccion'], 'Sarmiento 520');
    });

    test('lo escrito como dirección de origen gana a la encontrada', () async {
      api.direcciones[_aqui] = 'Sarmiento 520';
      await notifier().ubicar();
      await pumpEventQueue();

      notifier()
        ..direccion(PuntoPedido.origen, 'Puerta 2')
        ..fijar(PuntoPedido.destino, const Coordenada(2, 2));

      expect(borrador().descripcion(PuntoPedido.origen), 'Puerta 2');
      expect(borrador().pedido().toJson()['origen_direccion'], 'Puerta 2');
    });

    test('un destino tocado en el mapa toma su dirección y la manda', () async {
      api
        ..direcciones[tocado] = 'San Martín 100'
        ..demorarDireccion[tocado] = Completer();
      await notifier().ubicar();

      notifier().marcar(tocado);
      expect(borrador().descripcion(PuntoPedido.destino), 'Buscando la dirección…');
      api.demorarDireccion[tocado]!.complete();
      await pumpEventQueue();

      expect(borrador().direccionDestino, 'San Martín 100');
      expect(borrador().descripcion(PuntoPedido.destino), 'San Martín 100');
      expect(borrador().pedido().toJson()['destino_direccion'], 'San Martín 100');
    });

    test('un origen tocado en el mapa también toma su dirección', () async {
      api.direcciones[tocado] = 'San Martín 100';
      ubicador.posicion = null;
      await notifier().ubicar();

      notifier().marcar(tocado);
      await pumpEventQueue();

      expect(borrador().descripcion(PuntoPedido.origen), 'San Martín 100');
    });

    test('una respuesta vieja no pisa la de un punto más nuevo', () async {
      const otro = Coordenada(-26.81, -65.21);
      api
        ..direcciones[tocado] = 'San Martín 100'
        ..direcciones[otro] = 'Laprida 50'
        ..demorarDireccion[tocado] = Completer()
        ..demorarDireccion[otro] = Completer();
      await notifier().ubicar();

      notifier()
        ..marcar(tocado)
        ..marcar(otro);
      api.demorarDireccion[otro]!.complete();
      await pumpEventQueue();
      api.demorarDireccion[tocado]!.complete();
      await pumpEventQueue();

      expect(borrador().destino, otro);
      expect(borrador().direccionDestino, 'Laprida 50');
    });

    test('la dirección de la ubicación que llega tarde no se aplica a un origen elegido a mano', () async {
      api
        ..direcciones[_aqui] = 'Sarmiento 520'
        ..demorarDireccion[_aqui] = Completer();
      await notifier().ubicar();

      notifier()
        ..marcarAhora(PuntoPedido.origen)
        ..marcar(tocado);
      api.demorarDireccion[_aqui]!.complete();
      await pumpEventQueue();

      expect(borrador().origen, tocado);
      expect(borrador().detalleOrigen, isNull);
      expect(borrador().lugarOrigen!.direccion, isNull);
    });

    test('si no hay dirección (falla o sin conexión) se sigue sin ella: ni coordenadas ni dirección', () async {
      await notifier().ubicar();
      notifier().marcar(tocado);
      await pumpEventQueue();

      expect(borrador().detalleOrigen, isNull);
      expect(borrador().descripcion(PuntoPedido.origen), 'Tu ubicación actual');
      expect(borrador().descripcion(PuntoPedido.destino), 'Ubicación marcada en el mapa');
      final cuerpo = borrador().pedido().toJson();
      expect(cuerpo.containsKey('origen_direccion'), isFalse);
      expect(cuerpo.containsKey('destino_direccion'), isFalse);
    });

    test('lo que se escribe mientras se busca la dirección del destino no se pisa', () async {
      api
        ..direcciones[tocado] = 'San Martín 100'
        ..demorarDireccion[tocado] = Completer();
      await notifier().ubicar();

      notifier()
        ..marcar(tocado)
        ..direccion(PuntoPedido.destino, 'Puerta 2');
      api.demorarDireccion[tocado]!.complete();
      await pumpEventQueue();

      expect(borrador().direccionDestino, 'Puerta 2');
    });

    test('un toque nuevo reemplaza la dirección de la sugerencia elegida antes', () async {
      await notifier().ubicar();
      notifier().elegirLugar(_tribunales);

      notifier().marcar(tocado);
      await pumpEventQueue();

      expect(borrador().direccionDestino, '');
      expect(borrador().pedido().toJson().containsKey('destino_direccion'), isFalse);
    });

    test('una dirección larga se corta en el límite del backend (255)', () async {
      api.direcciones[tocado] = 'a' * 300;
      await notifier().ubicar();
      notifier().marcar(tocado);
      await pumpEventQueue();

      expect(borrador().direccionDestino.length, 255);
    });

    test('después de pedir, la misma ubicación no se vuelve a consultar', () async {
      api.direcciones[_aqui] = 'Sarmiento 520';
      await notifier().ubicar();
      await pumpEventQueue();

      notifier().limpiar();
      await pumpEventQueue();

      expect(api.consultasDireccion, [_aqui]);
      expect(borrador().detalleOrigen, 'Sarmiento 520');
    });
  });

  group('encuadrar el recorrido', () {
    const ruta = Ruta(
      distanciaM: 100,
      duracionS: 60,
      puntos: [Coordenada(1, 1), Coordenada(1.5, 3), Coordenada(2, 2)],
      pasos: [],
    );

    void pedirDeAaB() => notifier()
      ..fijar(PuntoPedido.origen, const Coordenada(1, 1))
      ..fijar(PuntoPedido.destino, const Coordenada(2, 2));

    /// Encuadra el recorrido del tramo actual; true si el mapa se movió.
    bool encuadrar() {
      final antes = borrador().enfoque;
      notifier().encuadrarRuta(borrador().tramo!, ruta);
      return !identical(borrador().enfoque, antes);
    }

    test('una sola vez por tramo', () {
      pedirDeAaB();
      expect(encuadrar(), isTrue);
      expect(borrador().enfoque!.puntos, contains(const Coordenada(1.5, 3)));
      expect(encuadrar(), isFalse);
    });

    test('después de limpiar (pedido hecho), el mismo tramo vuelve a encuadrarse', () {
      pedirDeAaB();
      expect(encuadrar(), isTrue);

      notifier().limpiar();
      pedirDeAaB();
      expect(encuadrar(), isTrue);
    });

    test('"Elegir otro" con el mismo tramo vuelve a encuadrarlo', () {
      final v = viaje(estado: 'sin_chofer');
      notifier().desdeViaje(v);
      expect(encuadrar(), isTrue);

      notifier().desdeViaje(v);
      expect(encuadrar(), isTrue);
    });
  });
}
