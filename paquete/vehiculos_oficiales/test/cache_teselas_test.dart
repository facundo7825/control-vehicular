import 'dart:io';
import 'dart:typed_data';

import 'package:dio_cache_interceptor/dio_cache_interceptor.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/mapa/cache_teselas.dart';

void main() {
  late Directory temporal;

  setUp(() async => temporal = await Directory.systemTemp.createTemp('teselas_'));
  tearDown(() async {
    if (await temporal.exists()) await temporal.delete(recursive: true);
  });

  final ahora = DateTime(2026, 10, 7, 12);

  Future<File> archivo(String nombre, int bytes, DateTime modificado) async {
    final f = File('${temporal.path}/$nombre');
    await f.parent.create(recursive: true);
    await f.writeAsBytes(Uint8List(bytes));
    await f.setLastModified(modificado);
    return f;
  }

  group('recortarDirectorio', () {
    test('borra las más viejas hasta quedar en el máximo, también en subdirectorios', () async {
      final viejo = await archivo('normal/a', 400, ahora.subtract(const Duration(days: 3)));
      final medio = await archivo('high/b', 400, ahora.subtract(const Duration(days: 2)));
      final nuevo = await archivo('normal/c', 400, ahora.subtract(const Duration(days: 1)));

      final borrados = await recortarDirectorio(temporal, maxBytes: 900, ahora: ahora);

      expect(borrados, 1);
      expect(await viejo.exists(), isFalse);
      expect(await medio.exists(), isTrue);
      expect(await nuevo.exists(), isTrue);

      await recortarDirectorio(temporal, maxBytes: 400, ahora: ahora);
      expect(await medio.exists(), isFalse);
      expect(await nuevo.exists(), isTrue);
    });

    test('borra las que pasaron la edad máxima aunque sobre lugar', () async {
      final vieja = await archivo('normal/a', 10, ahora.subtract(const Duration(days: 61)));
      final reciente = await archivo('normal/b', 10, ahora.subtract(const Duration(days: 59)));

      await recortarDirectorio(temporal, maxBytes: 1000, maxEdad: const Duration(days: 60), ahora: ahora);

      expect(await vieja.exists(), isFalse);
      expect(await reciente.exists(), isTrue);
    });

    test('sin directorio no hace nada', () async {
      expect(await recortarDirectorio(Directory('${temporal.path}/no-existe'), maxBytes: 0, ahora: ahora), 0);
    });
  });

  group('AlmacenTeselasArchivo', () {
    test('guarda las teselas en vehiculos_oficiales/teselas del directorio de caché y las recorta', () async {
      final almacen = AlmacenTeselasArchivo(() async => temporal, maxBytes: 0);
      final respuesta = CacheResponse(
        cacheControl: CacheControl(),
        content: List.filled(100, 7),
        date: null,
        eTag: null,
        expires: null,
        headers: null,
        key: 'clave',
        lastModified: null,
        maxStale: null,
        priority: CachePriority.normal,
        requestDate: ahora,
        responseDate: ahora,
        url: 'https://tile.openstreetmap.org/14/1/2.png',
        statusCode: 200,
      );

      await almacen.set(respuesta);
      expect((await almacen.get('clave'))?.content, respuesta.content);
      expect(Directory('${temporal.path}/vehiculos_oficiales/teselas').listSync(recursive: true), isNotEmpty);

      await almacen.recortar();
      expect(await almacen.exists('clave'), isFalse);
    });

    test('al abrir no lee ni borra las teselas guardadas (el recorte por edad y tamaño es aparte)', () async {
      // Una que no se puede leer: el FileCacheStore del paquete la leería (y la borraría) al crearse.
      final rara = await archivo('vehiculos_oficiales/teselas/normal/rara', 3, ahora);
      final almacen = AlmacenTeselasArchivo(() async => temporal);

      expect(await almacen.exists('otra'), isFalse);
      await Future<void>.delayed(const Duration(milliseconds: 50));

      expect(await rara.exists(), isTrue);
    });

    test('getFromPath y clean listan sin bloquear y no fallan sin carpeta', () async {
      final almacen = AlmacenTeselasArchivo(() async => Directory('${temporal.path}/no-existe'));

      expect(await almacen.getFromPath(RegExp('.*')), isEmpty);
      await almacen.clean();
      await almacen.deleteFromPath(RegExp('.*'));
    });

    test('el máximo es de unos 200 MB', () {
      expect(maxBytesTeselas, 200 * 1024 * 1024);
    });
  });
}
