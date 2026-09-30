import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/chofer/almacen_cola.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

void main() {
  late Directory dir;
  late AlmacenColaArchivo almacen;

  setUp(() {
    dir = Directory.systemTemp.createTempSync('cola_ubicaciones_');
    almacen = AlmacenColaArchivo(() async => dir);
  });

  tearDown(() => dir.deleteSync(recursive: true));

  File archivo() => File('${dir.path}/${AlmacenColaArchivo.nombreArchivo}');

  final puntos = [
    PuntoGps(
      posicion: const Coordenada(-26.83, -65.2),
      rumbo: 90.5,
      velocidad: 12.25,
      registradoEn: DateTime.utc(2026, 10, 1, 12, 0, 0, 123),
    ),
    // Lo que informa iOS sin dato (-1) se guarda tal cual: el filtro para la API está en `toJson`.
    PuntoGps(
      posicion: const Coordenada(-26.84, -65.21),
      rumbo: -1,
      velocidad: -1,
      registradoEn: DateTime.utc(2026, 10, 1, 12, 0, 10),
    ),
    PuntoGps(posicion: const Coordenada(-26.85, -65.22), registradoEn: DateTime.utc(2026, 10, 1, 12, 0, 20)),
  ];

  void mismosPuntos(List<PuntoGps> leidos, List<PuntoGps> esperados) {
    expect(leidos, hasLength(esperados.length));
    for (var i = 0; i < esperados.length; i++) {
      final l = leidos[i];
      final e = esperados[i];
      expect(l.posicion.lat, e.posicion.lat);
      expect(l.posicion.lng, e.posicion.lng);
      expect(l.rumbo, e.rumbo);
      expect(l.velocidad, e.velocidad);
      expect(l.registradoEn, e.registradoEn);
      expect(l.registradoEn.isUtc, isTrue);
    }
  }

  test('sin archivo devuelve una lista vacía', () async {
    expect(await almacen.leer(1), isEmpty);
  });

  test('guarda y lee los puntos del turno sin perder datos (ida y vuelta por JSON)', () async {
    await almacen.guardar(7, puntos);

    expect(archivo().existsSync(), isTrue);
    mismosPuntos(await almacen.leer(7), puntos);
    // Otra instancia (la app reiniciada) lee lo mismo.
    mismosPuntos(await AlmacenColaArchivo(() async => dir).leer(7), puntos);
  });

  test('guardar reemplaza lo anterior', () async {
    await almacen.guardar(7, puntos);
    await almacen.guardar(7, puntos.sublist(2));

    mismosPuntos(await almacen.leer(7), puntos.sublist(2));
  });

  test('lo guardado de otro turno no se devuelve', () async {
    await almacen.guardar(7, puntos);

    expect(await almacen.leer(8), isEmpty);
  });

  test('borrar elimina el archivo y no falla si no existe', () async {
    await almacen.guardar(7, puntos);
    await almacen.borrar();

    expect(archivo().existsSync(), isFalse);
    expect(await almacen.leer(7), isEmpty);
    await almacen.borrar();
  });

  test('un archivo corrupto se lee como una lista vacía, sin lanzar', () async {
    for (final contenido in ['{no es json', '[]', '{"turno_id": 7, "puntos": [{"lat": "x"}]}', '']) {
      archivo().writeAsStringSync(contenido);
      expect(await almacen.leer(7), isEmpty, reason: contenido);
    }
  });

  test('las operaciones se aplican en el orden en que se piden', () async {
    // Sin esperar entre llamadas: un borrado pedido después de un guardado no puede quedar antes.
    final guardado = almacen.guardar(7, puntos);
    final borrado = almacen.borrar();
    await Future.wait([guardado, borrado]);

    expect(archivo().existsSync(), isFalse);
  });
}
