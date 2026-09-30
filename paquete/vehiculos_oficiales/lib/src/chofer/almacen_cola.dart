import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import '../modelos/modelos.dart';

/// Dónde sobreviven los puntos del GPS pendientes de envío si el sistema cierra la app a mitad de un
/// turno (decisión 3 del plan de robustez; spec 6, 9 y 10). Guarda la cola de **un** turno por vez.
abstract interface class AlmacenCola {
  /// Los puntos guardados para [turnoId], en el orden en que se guardaron. Vacía si no hay nada, si lo
  /// guardado es de otro turno o si no se puede leer.
  Future<List<PuntoGps>> leer(int turnoId);

  /// Reemplaza lo guardado por [puntos] del turno [turnoId].
  Future<void> guardar(int turnoId, List<PuntoGps> puntos);

  Future<void> borrar();
}

/// Un archivo JSON ([nombreArchivo]) en el directorio que devuelve [_directorio] (el de soporte de la app
/// en producción; uno temporal en los tests).
///
/// Los puntos se guardan tal cual los dio el GPS (también el -1 de iOS en rumbo y velocidad, y la hora en
/// microsegundos UTC): el filtro para la API está en `PuntoGps.toJson` y se aplica al enviar.
///
/// Las operaciones se hacen de a una y en el orden en que se piden: un `borrar` pedido después de un
/// `guardar` nunca queda antes. Se escribe en un archivo temporal que después se renombra, para que una
/// app cerrada a mitad de la escritura no deje un archivo cortado. Un archivo ilegible se lee como vacío.
class AlmacenColaArchivo implements AlmacenCola {
  AlmacenColaArchivo(this._directorio);

  static const nombreArchivo = 'cola_ubicaciones.json';

  final Future<Directory> Function() _directorio;
  Future<void> _anterior = Future.value();

  @override
  Future<List<PuntoGps>> leer(int turnoId) => _enOrden(() async {
    final archivo = await _archivo();
    if (!await archivo.exists()) return <PuntoGps>[];
    try {
      final j = leerMapa(jsonDecode(await archivo.readAsString()));
      if (j['turno_id'] != turnoId) return <PuntoGps>[];
      return [for (final p in j['puntos'] as List) _deDisco(leerMapa(p))];
    } catch (e) {
      // JSON cortado o con otra forma: se sigue sin lo guardado.
      debugPrint('vehiculos_oficiales: no se pudo leer la cola de ubicaciones guardada (${e.runtimeType}).');
      return <PuntoGps>[];
    }
  });

  @override
  Future<void> guardar(int turnoId, List<PuntoGps> puntos) {
    final contenido = jsonEncode({
      'turno_id': turnoId,
      'puntos': [for (final p in puntos) _aDisco(p)],
    });
    return _enOrden(() async {
      final archivo = await _archivo();
      final temporal = File('${archivo.path}.tmp');
      await temporal.writeAsString(contenido, flush: true);
      await temporal.rename(archivo.path);
    });
  }

  @override
  Future<void> borrar() => _enOrden(() async {
    final archivo = await _archivo();
    if (await archivo.exists()) await archivo.delete();
  });

  Future<File> _archivo() async => File('${(await _directorio()).path}/$nombreArchivo');

  Future<T> _enOrden<T>(Future<T> Function() operacion) {
    final resultado = _anterior.then((_) => operacion());
    _anterior = resultado.then<void>((_) {}, onError: (Object _) {});
    return resultado;
  }

  static Json _aDisco(PuntoGps p) => {
    'lat': p.posicion.lat,
    'lng': p.posicion.lng,
    'rumbo': p.rumbo,
    'velocidad': p.velocidad,
    'registrado_en_us': p.registradoEn.microsecondsSinceEpoch,
  };

  static PuntoGps _deDisco(Json j) => PuntoGps(
    posicion: Coordenada((j['lat'] as num).toDouble(), (j['lng'] as num).toDouble()),
    rumbo: (j['rumbo'] as num?)?.toDouble(),
    velocidad: (j['velocidad'] as num?)?.toDouble(),
    registradoEn: DateTime.fromMicrosecondsSinceEpoch(j['registrado_en_us'] as int, isUtc: true),
  );
}

/// En web no se persiste nada.
class AlmacenColaNula implements AlmacenCola {
  const AlmacenColaNula();

  @override
  Future<List<PuntoGps>> leer(int turnoId) async => [];

  @override
  Future<void> guardar(int turnoId, List<PuntoGps> puntos) async {}

  @override
  Future<void> borrar() async {}
}

final almacenColaProvider = Provider<AlmacenCola>(
  (ref) => kIsWeb ? const AlmacenColaNula() : AlmacenColaArchivo(getApplicationSupportDirectory),
);
