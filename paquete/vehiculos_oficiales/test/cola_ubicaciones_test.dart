import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_ubicaciones.dart';

import 'soporte/dobles_chofer.dart';

void main() {
  test('queda ordenada por registrado_en aunque llegue uno más viejo', () {
    final cola = ColaUbicaciones()
      ..agregar(punto(20))
      ..agregar(punto(0))
      ..agregar(punto(10));

    expect(segundos(cola.primeros(10)), [0, 10, 20]);
  });

  test('un punto repetido del GPS (misma hora) no se encola dos veces', () {
    final cola = ColaUbicaciones()
      ..agregar(punto(0))
      ..agregar(punto(0, lat: -26.9))
      ..agregar(punto(10));

    expect(cola.largo, 2);
    expect(cola.primeros(1).single.posicion.lat, -26.83);
  });

  test('al pasar el tope se descartan los más viejos', () {
    final cola = ColaUbicaciones(tope: 3);
    for (var s = 0; s < 5; s++) {
      cola.agregar(punto(s));
    }

    expect(segundos(cola.primeros(10)), [2, 3, 4]);
  });

  test('quitar saca exactamente lo enviado aunque mientras tanto entre uno más viejo', () {
    final cola = ColaUbicaciones()
      ..agregar(punto(10))
      ..agregar(punto(20));
    final enviados = cola.primeros(2);
    cola.agregar(punto(5));

    cola.quitar(enviados);

    expect(segundos(cola.primeros(10)), [5]);
    cola.vaciar();
    expect(cola.largo, 0);
  });
}
