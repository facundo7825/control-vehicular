import 'dart:async';
import 'dart:io';

import 'package:dio_cache_interceptor/dio_cache_interceptor.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:http_cache_file_store/http_cache_file_store.dart';
import 'package:path_provider/path_provider.dart';

/// Hasta cuánto ocupan en disco las teselas guardadas (decisión 4 del plan del modo sin señal).
const maxBytesTeselas = 200 * 1024 * 1024;

/// Una tesela guardada hace más de esto se borra, así el mapa se actualiza (calles nuevas) la próxima vez que
/// se vea con señal.
const maxEdadTeselas = Duration(days: 60);

/// Cada cuánto se recorta el caché mientras el módulo está abierto (además de al abrirlo).
const intervaloRecorteTeselas = Duration(minutes: 30);

/// Dónde guarda `MapaOsm` las teselas que ya bajó, para verlas sin señal. Nulo en web: ahí no hay disco y las
/// teselas van directo a la red (con el caché del navegador). Recorta el caché al abrirse el módulo y cada
/// [intervaloRecorteTeselas]; el timer vive acá y se cancela al cerrar el módulo.
final almacenTeselasProvider = Provider<CacheStore?>((ref) {
  if (kIsWeb) return null;
  final almacen = AlmacenTeselasArchivo(getApplicationCacheDirectory);
  Future<void> recortar() async {
    try {
      await almacen.recortar();
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo recortar el caché del mapa (${e.runtimeType}).');
    }
  }

  unawaited(recortar());
  final timer = Timer.periodic(intervaloRecorteTeselas, (_) => unawaited(recortar()));
  ref.onDispose(timer.cancel);
  return almacen;
});

/// Las teselas en archivos (un [FileCacheStore]) dentro de `vehiculos_oficiales/teselas` del directorio que da
/// [_directorio]: en producción, el de caché de la app, que no va a las copias de seguridad y que el sistema
/// puede vaciar con poco espacio (se vuelven a bajar). El directorio se pide recién al usarlo.
///
/// [FileCacheStore] no tiene tamaño máximo: [recortar] borra las más viejas (por fecha de escritura) hasta
/// quedar en [maxBytes], y las de más de [maxEdad].
class AlmacenTeselasArchivo extends CacheStore {
  AlmacenTeselasArchivo(this._directorio, {this.maxBytes = maxBytesTeselas, this.maxEdad = maxEdadTeselas});

  static const subdirectorio = 'vehiculos_oficiales/teselas';

  final Future<Directory> Function() _directorio;
  final int maxBytes;
  final Duration maxEdad;

  late final Future<Directory> _carpeta = _directorio().then((d) => Directory('${d.path}/$subdirectorio'));
  late final Future<FileCacheStore> _almacen = _carpeta.then((c) => FileCacheStore(c.path));

  Future<int> recortar() async => recortarDirectorio(await _carpeta, maxBytes: maxBytes, maxEdad: maxEdad);

  @override
  Future<bool> exists(String key) async => (await _almacen).exists(key);

  @override
  Future<CacheResponse?> get(String key) async => (await _almacen).get(key);

  @override
  Future<List<CacheResponse>> getFromPath(RegExp pathPattern, {Map<String, String?>? queryParams}) async =>
      (await _almacen).getFromPath(pathPattern, queryParams: queryParams);

  @override
  Future<void> set(CacheResponse response) async => (await _almacen).set(response);

  @override
  Future<void> delete(String key, {bool staleOnly = false}) async => (await _almacen).delete(key, staleOnly: staleOnly);

  @override
  Future<void> deleteFromPath(RegExp pathPattern, {Map<String, String?>? queryParams}) async =>
      (await _almacen).deleteFromPath(pathPattern, queryParams: queryParams);

  @override
  Future<void> clean({CachePriority priorityOrBelow = CachePriority.high, bool staleOnly = false}) async =>
      (await _almacen).clean(priorityOrBelow: priorityOrBelow, staleOnly: staleOnly);

  @override
  Future<void> close() async => (await _almacen).close();
}

/// Borra los archivos de [directorio] (y sus subdirectorios) modificados hace más de [maxEdad] y, después, los
/// más viejos hasta que el resto ocupe [maxBytes] o menos. Devuelve cuántos borró. Un archivo que no se puede
/// leer o borrar (p. ej. se está escribiendo) se saltea.
Future<int> recortarDirectorio(
  Directory directorio, {
  required int maxBytes,
  Duration? maxEdad,
  DateTime? ahora,
}) async {
  if (!await directorio.exists()) return 0;
  final limite = maxEdad == null ? null : (ahora ?? DateTime.now()).subtract(maxEdad);
  final archivos = <({File archivo, int bytes, DateTime modificado})>[];
  await for (final entidad in directorio.list(recursive: true, followLinks: false)) {
    if (entidad is! File) continue;
    try {
      final datos = await entidad.stat();
      archivos.add((archivo: entidad, bytes: datos.size, modificado: datos.modified));
    } catch (_) {
      // Se borró mientras tanto.
    }
  }
  archivos.sort((a, b) => a.modificado.compareTo(b.modificado));
  var total = archivos.fold<int>(0, (suma, a) => suma + a.bytes);
  var borrados = 0;
  for (final a in archivos) {
    final vencido = limite != null && a.modificado.isBefore(limite);
    if (!vencido && total <= maxBytes) break;
    try {
      await a.archivo.delete();
      total -= a.bytes;
      borrados++;
    } catch (_) {
      // Se está escribiendo o ya no está: queda para el próximo recorte.
    }
  }
  return borrados;
}
