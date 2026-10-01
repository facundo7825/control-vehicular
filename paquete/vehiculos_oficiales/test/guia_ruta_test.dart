import 'dart:math' as math;

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/chofer/guia_ruta.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

/// Un punto a [norte] y [este] metros de un origen fijo (en Tucumán).
Coordenada m(double norte, [double este = 0]) {
  const lat0 = -26.83;
  const lng0 = -65.2;
  const metrosPorGrado = 6371000 * math.pi / 180;
  return Coordenada(lat0 + norte / metrosPorGrado, lng0 + este / (metrosPorGrado * math.cos(lat0 * math.pi / 180)));
}

PasoRuta paso(int indice, String tipo, String instruccion, Coordenada c) =>
    PasoRuta(instruccion: instruccion, distanciaM: 0, indice: indice, coordenada: c, tipo: tipo);

/// Recta de 1 km al norte, un punto cada 100 m.
Ruta recta() {
  final puntos = [for (var i = 0; i <= 10; i++) m(i * 100.0)];
  return Ruta(
    distanciaM: 1000,
    duracionS: 200,
    puntos: puntos,
    pasos: [
      paso(0, 'salida', 'Seguí por Belgrano', puntos.first),
      paso(10, 'llegada', 'Llegaste a destino', puntos.last),
    ],
  );
}

/// 500 m al norte y 500 m al este, un punto cada 100 m.
Ruta enL() {
  final puntos = [for (var i = 0; i <= 5; i++) m(i * 100.0), for (var i = 1; i <= 5; i++) m(500, i * 100.0)];
  return Ruta(
    distanciaM: 1000,
    duracionS: 300,
    puntos: puntos,
    pasos: [
      paso(0, 'salida', 'Seguí por Belgrano', puntos.first),
      paso(5, 'derecha', 'Doblá a la derecha por San Martín', puntos[5]),
      paso(10, 'llegada', 'Llegaste a destino', puntos.last),
    ],
  );
}

/// Ida y vuelta por la misma calle: [largo] al norte y de vuelta, un punto cada [cada].
Ruta idaYVuelta({double largo = 500, double cada = 100}) {
  final n = (largo / cada).round();
  final puntos = [for (var i = 0; i <= n; i++) m(i * cada), for (var i = n - 1; i >= 0; i--) m(i * cada)];
  return Ruta(
    distanciaM: 2 * largo,
    duracionS: 200,
    puntos: puntos,
    pasos: [
      paso(0, 'salida', 'Seguí por Belgrano', puntos.first),
      paso(n, 'retorno', 'Pegá la vuelta en U', puntos[n]),
      paso(2 * n, 'llegada', 'Llegaste a destino', puntos.last),
    ],
  );
}

void main() {
  group('distanciaAManiobra', () {
    test('desde 1 km en km con un decimal, debajo de a 50 m, y a 30 m o menos nada', () {
      expect(distanciaAManiobra(1230), 'En 1,2 km');
      expect(distanciaAManiobra(1000), 'En 1 km');
      expect(distanciaAManiobra(980), 'En 1 km');
      expect(distanciaAManiobra(960), 'En 950 m');
      expect(distanciaAManiobra(237), 'En 250 m');
      expect(distanciaAManiobra(212), 'En 200 m');
      expect(distanciaAManiobra(40), 'En 50 m');
      expect(distanciaAManiobra(31), 'En 50 m');
      expect(distanciaAManiobra(30), isNull);
      expect(distanciaAManiobra(0), isNull);
    });
  });

  group('recta', () {
    test('la llegada con la distancia por la línea, y lo que falta con el tiempo proporcional', () {
      final s = SeguidorRuta(recta(), alDestino: true);

      var g = s.avanzar(m(0))!;
      expect(g.tipo, 'llegada');
      expect(g.texto, 'En 1 km, llegás a destino');
      expect(g.restanteM, closeTo(1000, 1));
      expect(g.restanteS, closeTo(200, 0.5));

      g = s.avanzar(m(300, 5))!;
      expect(g.texto, 'En 700 m, llegás a destino');
      expect(g.restanteM, closeTo(700, 1));
      expect(g.restanteS, closeTo(140, 0.5));

      s.avanzar(m(700));
      g = s.avanzar(m(985))!;
      expect(g.texto, 'Llegaste a destino');
      expect(g.restanteM, closeTo(15, 1));
    });

    test('yendo al origen la llegada dice "al origen"; pasada la última, igual', () {
      final s = SeguidorRuta(recta(), alDestino: false);
      expect(s.avanzar(m(400))!.texto, 'En 600 m, llegás al origen');
      s.avanzar(m(800));
      expect(s.avanzar(m(1010))!.texto, 'Llegaste al origen');
      expect(s.guia!.restanteM, closeTo(0, 0.5));
    });
  });

  group('en L', () {
    test('la próxima maniobra con la instrucción en minúscula solo al empezar', () {
      final s = SeguidorRuta(enL(), alDestino: true);

      var g = s.avanzar(m(200, 5))!;
      expect(g.tipo, 'derecha');
      expect(g.texto, 'En 300 m, doblá a la derecha por San Martín');
      expect(g.restanteM, closeTo(800, 1));

      g = s.avanzar(m(480))!;
      expect(g.texto, 'Doblá a la derecha por San Martín');

      g = s.avanzar(m(503, 100))!;
      expect(g.tipo, 'llegada');
      expect(g.texto, 'En 400 m, llegás a destino');
      expect(g.restanteM, closeTo(400, 1));
    });
  });

  test('con tramos largos (más que la ventana) pasa igual al tramo siguiente', () {
    final puntos = [m(0), m(800), m(800, 500)];
    final s = SeguidorRuta(
      Ruta(
        distanciaM: 1300,
        duracionS: 130,
        puntos: puntos,
        pasos: [
          paso(1, 'izquierda', 'Doblá a la izquierda', puntos[1]),
          paso(2, 'llegada', 'Llegaste a destino', puntos[2]),
        ],
      ),
      alDestino: true,
    );
    expect(s.avanzar(m(790))!.texto, 'Doblá a la izquierda');
    final g = s.avanzar(m(802, 100))!;
    expect(g.texto, 'En 400 m, llegás a destino');
    expect(s.fueraDeRuta, isFalse);
  });

  group('ida y vuelta', () {
    test('busca hacia adelante: a la ida no salta a la vuelta, y después de pegar la vuelta no vuelve atrás', () {
      final s = SeguidorRuta(idaYVuelta(), alDestino: true);

      var g = s.avanzar(m(100, 3))!;
      expect(g.tipo, 'retorno');
      expect(g.texto, 'En 400 m, pegá la vuelta en U');
      expect(g.restanteM, closeTo(900, 1));

      s.avanzar(m(300));
      g = s.avanzar(m(500))!;
      expect(g.restanteM, closeTo(500, 1));

      g = s.avanzar(m(450, -3))!;
      expect(g.tipo, 'llegada');
      expect(g.restanteM, closeTo(450, 1));

      g = s.avanzar(m(200))!;
      expect(g.texto, 'En 200 m, llegás a destino');
      expect(g.restanteM, closeTo(200, 1));
    });

    test('corta (toda dentro de la ventana): con ruido a la ida sigue en la ida', () {
      final s = SeguidorRuta(idaYVuelta(largo: 200, cada: 50), alDestino: true);

      final g = s.avanzar(m(50, 4))!;
      expect(g.tipo, 'retorno');
      expect(g.restanteM, closeTo(350, 1));
      expect(s.avanzar(m(120, -4))!.restanteM, closeTo(280, 1));
    });
  });

  group('fuera del recorrido', () {
    test('a más de 60 m en 2 posiciones seguidas; mientras tanto la indicación no cambia', () {
      final s = SeguidorRuta(recta(), alDestino: true);
      final antes = s.avanzar(m(300))!;

      expect(s.avanzar(m(400, 100)), same(antes));
      expect(s.fueraDeRuta, isFalse);
      expect(s.avanzar(m(500, 120)), same(antes));
      expect(s.fueraDeRuta, isTrue);

      s.avanzar(m(400, 10));
      expect(s.fueraDeRuta, isFalse);
      expect(s.guia!.restanteM, closeTo(600, 1));

      s.avanzar(m(500, 100));
      expect(s.fueraDeRuta, isFalse, reason: 'vuelve a contar desde cero');
    });

    test('a 50 m sigue en el recorrido', () {
      final s = SeguidorRuta(recta(), alDestino: true);
      s.avanzar(m(100, 50));
      s.avanzar(m(200, 50));
      expect(s.fueraDeRuta, isFalse);
      expect(s.guia!.restanteM, closeTo(800, 1));
    });

    test('sobre la línea pero mucho más adelante (un salto del GPS) también cuenta como fuera', () {
      final s = SeguidorRuta(idaYVuelta(largo: 2000), alDestino: true);
      final antes = s.avanzar(m(0));
      expect(s.avanzar(m(1500)), same(antes));
      expect(s.avanzar(m(1600)), same(antes));
      expect(s.fueraDeRuta, isTrue);
    });
  });
}
