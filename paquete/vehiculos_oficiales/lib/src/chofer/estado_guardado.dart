import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import '../modelos/modelos.dart';
import 'almacen_cola.dart';

/// Lo último que se supo del turno y del viaje del chofer, guardado en el teléfono para abrir el módulo sin
/// señal (decisión 3 del plan sin señal): si `GET /turnos/actual` o `GET /viajes/actual` no responden por
/// falta de red, se usa esta copia hasta que vuelva la señal. Es de **un** usuario: la de otro se ignora.
abstract interface class AlmacenJson {
  /// Nulo si no hay nada o no se puede leer.
  Future<Json?> leer();

  Future<void> guardar(Json datos);

  Future<void> borrar();
}

/// Un archivo JSON en el directorio de caché, junto a las colas (ver [AlmacenColaArchivo]): fuera de las
/// copias de seguridad. Se escribe en un temporal que después se renombra; las operaciones de todas las
/// instancias se hacen de a una, en el orden en que se piden.
class AlmacenJsonArchivo implements AlmacenJson {
  AlmacenJsonArchivo(this._directorio, this.nombre);

  final Future<Directory> Function() _directorio;
  final String nombre;

  static Future<void> _anterior = Future.value();

  @override
  Future<Json?> leer() => _enOrden(() async {
    final archivo = await _archivo();
    if (!await archivo.exists()) return null;
    try {
      return leerMapa(jsonDecode(await archivo.readAsString()));
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo leer $nombre (${e.runtimeType}).');
      return null;
    }
  });

  @override
  Future<void> guardar(Json datos) {
    final contenido = jsonEncode(datos);
    return _enOrden(() async {
      final archivo = await _archivo();
      await archivo.parent.create(recursive: true);
      final temporal = File('${archivo.path}.tmp');
      await temporal.writeAsString(contenido, flush: true);
      await temporal.rename(archivo.path);
    });
  }

  @override
  Future<void> borrar() => _enOrden(() async {
    final archivo = await _archivo();
    for (final f in [archivo, File('${archivo.path}.tmp')]) {
      if (await f.exists()) await f.delete();
    }
  });

  Future<File> _archivo() async => File('${(await _directorio()).path}/${AlmacenColaArchivo.subdirectorio}/$nombre');

  static Future<T> _enOrden<T>(Future<T> Function() operacion) {
    final resultado = _anterior.then((_) => operacion());
    _anterior = resultado.then<void>((_) {}, onError: (Object _) {});
    return resultado;
  }
}

/// En web no se guarda nada.
class AlmacenJsonNulo implements AlmacenJson {
  const AlmacenJsonNulo();

  @override
  Future<Json?> leer() async => null;

  @override
  Future<void> guardar(Json datos) async {}

  @override
  Future<void> borrar() async {}
}

AlmacenJson _enCache(String nombre) =>
    kIsWeb ? const AlmacenJsonNulo() : AlmacenJsonArchivo(getApplicationCacheDirectory, nombre);

final almacenTurnoGuardadoProvider = Provider<AlmacenJson>((ref) => _enCache('turno_guardado.json'));
final almacenViajeGuardadoProvider = Provider<AlmacenJson>((ref) => _enCache('viaje_guardado.json'));

/// Lo guardado para [usuarioId] en [almacen] (leído con [leer]), o nulo. Nunca lanza.
Future<T?> leerGuardado<T>(AlmacenJson almacen, int usuarioId, T Function(Json j) leer) async {
  try {
    final j = await almacen.leer();
    if (j == null || j['usuario_id'] != usuarioId || j['dato'] == null) return null;
    return leer(leerMapa(j['dato']));
  } catch (e) {
    debugPrint('vehiculos_oficiales: no se pudo leer lo guardado (${e.runtimeType}).');
    return null;
  }
}

/// Guarda [dato] de [usuarioId] en [almacen]; nulo lo borra. Nunca lanza.
Future<void> guardarPara(AlmacenJson almacen, int usuarioId, Json? dato) async {
  try {
    if (dato == null) {
      await almacen.borrar();
    } else {
      await almacen.guardar({'usuario_id': usuarioId, 'dato': dato});
    }
  } catch (e) {
    debugPrint('vehiculos_oficiales: no se pudo guardar el estado del chofer (${e.runtimeType}).');
  }
}
