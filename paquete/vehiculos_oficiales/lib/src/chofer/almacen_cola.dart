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

  /// Lo guardado, de cualquier turno (nulo si no hay nada o no se puede leer): al abrir sin ese turno, los
  /// puntos todavía pueden ser del recorrido de un viaje y se mandan igual.
  Future<({int turnoId, List<PuntoGps> puntos})?> leerCualquiera();

  /// Reemplaza lo guardado por [puntos] del turno [turnoId].
  Future<void> guardar(int turnoId, List<PuntoGps> puntos);

  Future<void> borrar();
}

/// Un archivo JSON ([subdirectorio]/[nombreArchivo]) dentro del directorio que devuelve [_directorio]. En
/// producción es el de caché de la app: iOS (iCloud/iTunes) y Android (Auto Backup) no lo incluyen en las
/// copias de seguridad, así que el recorrido del chofer no termina en una nube personal (spec 10). El sistema
/// puede vaciarlo con poco espacio: se pierde lo pendiente, que es un respaldo de mejor esfuerzo. En los
/// tests es un directorio temporal. El subdirectorio (se crea si falta) evita chocar con archivos de la app.
///
/// Los puntos se guardan tal cual los dio el GPS (también el -1 de iOS en rumbo y velocidad, y la hora en
/// microsegundos UTC): el filtro para la API está en `PuntoGps.toJson` y se aplica al enviar. Un número no
/// finito (NaN, infinito) se guarda como nulo; un punto sin latitud o longitud se descarta al leer.
///
/// Las operaciones se hacen de a una y en el orden en que se piden, también entre instancias (un módulo
/// cerrado con una escritura pendiente y vuelto a abrir lee después de esa escritura): un `borrar` pedido
/// después de un `guardar` nunca queda antes. Se escribe en un archivo temporal que después se renombra,
/// para que una app cerrada a mitad de la escritura no deje un archivo cortado. Un archivo ilegible se lee
/// como vacío.
class AlmacenColaArchivo implements AlmacenCola {
  AlmacenColaArchivo(this._directorio, {this.nombre = nombreArchivo});

  static const subdirectorio = 'vehiculos_oficiales';
  static const nombreArchivo = 'cola_ubicaciones.json';

  final Future<Directory> Function() _directorio;

  /// El archivo: [nombreArchivo] para la cola del turno, otro para los puntos de turnos ya cerrados.
  final String nombre;

  /// Compartida por todas las instancias: en producción todas usan el mismo archivo.
  static Future<void> _anterior = Future.value();

  @override
  Future<List<PuntoGps>> leer(int turnoId) async {
    final guardado = await leerCualquiera();
    return guardado != null && guardado.turnoId == turnoId ? guardado.puntos : <PuntoGps>[];
  }

  @override
  Future<({int turnoId, List<PuntoGps> puntos})?> leerCualquiera() => _enOrden(() async {
    final archivo = await _archivo();
    if (!await archivo.exists()) return null;
    try {
      final j = leerMapa(jsonDecode(await archivo.readAsString()));
      return (turnoId: j['turno_id'] as int, puntos: [for (final p in j['puntos'] as List) ?_deDisco(leerMapa(p))]);
    } catch (e) {
      // JSON cortado o con otra forma: se sigue sin lo guardado.
      debugPrint('vehiculos_oficiales: no se pudo leer la cola de ubicaciones guardada (${e.runtimeType}).');
      return null;
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
      await archivo.parent.create(recursive: true);
      final temporal = _temporal(archivo);
      await temporal.writeAsString(contenido, flush: true);
      await temporal.rename(archivo.path);
    });
  }

  /// También el temporal que pudo quedar de una escritura cortada o fallida.
  @override
  Future<void> borrar() => _enOrden(() async {
    final archivo = await _archivo();
    for (final f in [archivo, _temporal(archivo)]) {
      if (await f.exists()) await f.delete();
    }
  });

  Future<File> _archivo() async => File('${(await _directorio()).path}/$subdirectorio/$nombre');

  static File _temporal(File archivo) => File('${archivo.path}.tmp');

  static Future<T> _enOrden<T>(Future<T> Function() operacion) {
    final resultado = _anterior.then((_) => operacion());
    _anterior = resultado.then<void>((_) {}, onError: (Object _) {});
    return resultado;
  }

  static Json _aDisco(PuntoGps p) => {
    'lat': _finito(p.posicion.lat),
    'lng': _finito(p.posicion.lng),
    'rumbo': _finito(p.rumbo),
    'velocidad': _finito(p.velocidad),
    'registrado_en_us': p.registradoEn.microsecondsSinceEpoch,
  };

  /// `jsonEncode` lanza con NaN o infinito.
  static double? _finito(double? x) => x != null && x.isFinite ? x : null;

  static PuntoGps? _deDisco(Json j) {
    final lat = j['lat'] as num?;
    final lng = j['lng'] as num?;
    if (lat == null || lng == null) return null;
    return PuntoGps(
      posicion: Coordenada(lat.toDouble(), lng.toDouble()),
      rumbo: (j['rumbo'] as num?)?.toDouble(),
      velocidad: (j['velocidad'] as num?)?.toDouble(),
      registradoEn: DateTime.fromMicrosecondsSinceEpoch(j['registrado_en_us'] as int, isUtc: true),
    );
  }
}

/// En web no se persiste nada.
class AlmacenColaNula implements AlmacenCola {
  const AlmacenColaNula();

  @override
  Future<List<PuntoGps>> leer(int turnoId) async => [];

  @override
  Future<({int turnoId, List<PuntoGps> puntos})?> leerCualquiera() async => null;

  @override
  Future<void> guardar(int turnoId, List<PuntoGps> puntos) async {}

  @override
  Future<void> borrar() async {}
}

final almacenColaProvider = Provider<AlmacenCola>(
  (ref) => kIsWeb ? const AlmacenColaNula() : AlmacenColaArchivo(getApplicationCacheDirectory),
);

/// Los puntos de turnos que ya se cerraron y todavía no llegaron al servidor (ver `TurnoNotifier`): pueden ser
/// del recorrido de un viaje, que el servidor acepta aunque no haya turno. Se guardan con el turno 0.
final almacenSinTurnoProvider = Provider<AlmacenCola>(
  (ref) => kIsWeb
      ? const AlmacenColaNula()
      : AlmacenColaArchivo(getApplicationCacheDirectory, nombre: 'ubicaciones_sin_turno.json'),
);
