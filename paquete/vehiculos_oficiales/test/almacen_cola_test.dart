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

  /// Crea el subdirectorio si falta, para poder dejar un archivo "de antes".
  File archivo() {
    final sub = Directory('${dir.path}/${AlmacenColaArchivo.subdirectorio}')..createSync(recursive: true);
    return File('${sub.path}/${AlmacenColaArchivo.nombreArchivo}_2.json');
  }

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
    expect(await almacen.leer(2, 1), isEmpty);
  });

  test('guarda y lee los puntos del turno sin perder datos (ida y vuelta por JSON)', () async {
    await almacen.guardar(2, 7, puntos);

    expect(archivo().existsSync(), isTrue);
    mismosPuntos(await almacen.leer(2, 7), puntos);
    // Otra instancia (la app reiniciada) lee lo mismo.
    mismosPuntos(await AlmacenColaArchivo(() async => dir).leer(2, 7), puntos);
  });

  test('guardar reemplaza lo anterior', () async {
    await almacen.guardar(2, 7, puntos);
    await almacen.guardar(2, 7, puntos.sublist(2));

    mismosPuntos(await almacen.leer(2, 7), puntos.sublist(2));
  });

  test('lo guardado de otro turno no se devuelve', () async {
    await almacen.guardar(2, 7, puntos);

    expect(await almacen.leer(2, 8), isEmpty);
  });

  test('lo guardado por otro chofer no se devuelve (tampoco con leerCualquiera)', () async {
    await almacen.guardar(2, 7, puntos);

    expect(await almacen.leer(3, 7), isEmpty);
    expect(await almacen.leerCualquiera(3), isNull);
    expect((await almacen.leerCualquiera(2))!.turnoId, 7);
  });

  test('un archivo de antes, sin chofer, se ignora', () async {
    File('${dir.path}/${AlmacenColaArchivo.subdirectorio}/${AlmacenColaArchivo.nombreArchivo}_2.json')
      ..createSync(recursive: true)
      ..writeAsStringSync('{"turno_id":7,"puntos":[{"lat":-26.8,"lng":-65.2,"registrado_en_us":0}]}');

    expect(await almacen.leer(2, 7), isEmpty);
  });

  test('borrar elimina el archivo y no falla si no existe', () async {
    await almacen.guardar(2, 7, puntos);
    await almacen.borrar(2);

    expect(archivo().existsSync(), isFalse);
    expect(await almacen.leer(2, 7), isEmpty);
    await almacen.borrar(2);
  });

  test('un archivo corrupto se lee como una lista vacía, sin lanzar', () async {
    for (final contenido in ['{no es json', '[]', '{"turno_id": 7, "puntos": [{"lat": "x"}]}', '']) {
      archivo().writeAsStringSync(contenido);
      expect(await almacen.leer(2, 7), isEmpty, reason: contenido);
    }
  });

  test('las operaciones se aplican en el orden en que se piden', () async {
    // Sin esperar entre llamadas: un borrado pedido después de un guardado no puede quedar antes.
    final guardado = almacen.guardar(2, 7, puntos);
    final borrado = almacen.borrar(2);
    await Future.wait([guardado, borrado]);

    expect(archivo().existsSync(), isFalse);
  });

  test('guarda en un subdirectorio propio del paquete, creándolo si falta', () async {
    expect(Directory('${dir.path}/${AlmacenColaArchivo.subdirectorio}').existsSync(), isFalse);

    await almacen.guardar(2, 7, puntos);

    expect(File('${dir.path}/vehiculos_oficiales/cola_ubicaciones_2.json').existsSync(), isTrue);
    expect(File('${dir.path}/cola_ubicaciones.json').existsSync(), isFalse);
  });

  test('borrar también elimina el temporal que dejó una escritura cortada', () async {
    await almacen.guardar(2, 7, puntos);
    final temporal = File('${archivo().path}.tmp')..writeAsStringSync('{"turno_id": 7, "pun');

    await almacen.borrar(2);

    expect(temporal.existsSync(), isFalse);
    expect(archivo().existsSync(), isFalse);
  });

  test('un número no finito se guarda como nulo, sin lanzar', () async {
    final raros = [
      PuntoGps(
        posicion: const Coordenada(-26.83, -65.2),
        rumbo: double.nan,
        velocidad: double.infinity,
        registradoEn: DateTime.utc(2026, 10, 1, 12),
      ),
      // Sin posición el punto no sirve: se descarta al leer, sin perder los demás.
      PuntoGps(
        posicion: const Coordenada(double.nan, double.negativeInfinity),
        registradoEn: DateTime.utc(2026, 10, 1, 12, 0, 5),
      ),
      puntos[2],
    ];

    await almacen.guardar(2, 7, raros);

    final leidos = await almacen.leer(2, 7);
    expect(leidos, hasLength(2));
    expect(leidos[0].rumbo, isNull);
    expect(leidos[0].velocidad, isNull);
    mismosPuntos([leidos[1]], [puntos[2]]);
  });

  test('las operaciones de dos instancias sobre el mismo archivo también van en orden', () async {
    // El módulo se cerró con una escritura pendiente y se volvió a abrir (otro contenedor, otra instancia).
    final anterior = AlmacenColaArchivo(() async {
      await Future<void>.delayed(const Duration(milliseconds: 50));
      return dir;
    });
    final guardado = anterior.guardar(2, 7, puntos);

    mismosPuntos(await AlmacenColaArchivo(() async => dir).leer(2, 7), puntos);
    await guardado;
  });

  test('cada chofer tiene su archivo: lo de uno no pisa ni borra lo del otro', () async {
    await almacen.guardar(2, 7, puntos);
    await almacen.guardar(3, 9, puntos.sublist(1));

    mismosPuntos(await almacen.leer(2, 7), puntos);
    mismosPuntos(await almacen.leer(3, 9), puntos.sublist(1));
    await almacen.borrar(3);
    expect(await almacen.leer(3, 9), isEmpty);
    mismosPuntos(await almacen.leer(2, 7), puntos);
  });

  group('purgarPendientesViejos', () {
    File tocar(String nombre, DateTime cuando) => File('${dir.path}/${AlmacenColaArchivo.subdirectorio}/$nombre')
      ..createSync(recursive: true)
      ..writeAsStringSync('{}')
      ..setLastModifiedSync(cuando);

    test('borra lo pendiente de cualquier chofer sin tocar en 24 h; lo reciente y lo demás quedan', () async {
      final ahora = DateTime(2026, 10, 7, 12);
      final viejos = [
        tocar('cola_ubicaciones_3.json', ahora.subtract(const Duration(hours: 25))),
        tocar('ubicaciones_sin_turno_3.json', ahora.subtract(const Duration(days: 3))),
        tocar('cola_acciones_3.json.tmp', ahora.subtract(const Duration(days: 3))),
        tocar('cola_ubicaciones.json', ahora.subtract(const Duration(days: 3))), // de la versión anterior
      ];
      final quedan = [
        tocar('cola_acciones_2.json', ahora.subtract(const Duration(hours: 2))),
        tocar('turno_guardado.json', ahora.subtract(const Duration(days: 3))),
      ];

      expect(await purgarPendientesViejos(dir, ahora: ahora), 4);

      expect(viejos.where((f) => f.existsSync()), isEmpty);
      expect(quedan.every((f) => f.existsSync()), isTrue);
    });

    test('sin carpeta no hace nada', () async {
      expect(await purgarPendientesViejos(Directory('${dir.path}/no_existe')), 0);
    });
  });
}
