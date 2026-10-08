import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';
import '../viaje/viaje_actual.dart';
import 'almacen_cola.dart';

/// Un paso del chofer en un viaje ("Voy en camino", "Llegué", "Iniciar viaje", "Finalizar") que todavía no
/// llegó al servidor (decisión 3 del plan sin señal). [momento] es cuándo lo tocó, según el reloj del
/// servidor; [id] lo identifica para que el servidor no lo aplique dos veces si se reenvía.
@immutable
class AccionViaje {
  const AccionViaje({required this.id, required this.viajeId, required this.estado, required this.momento});

  final String id;
  final int viajeId;
  final EstadoViaje estado;
  final DateTime momento;

  Json toJson() => {
    'id_accion': id,
    'viaje_id': viajeId,
    'estado': estado.valor,
    'momento_us': momento.toUtc().microsecondsSinceEpoch,
  };

  factory AccionViaje.fromJson(Json j) => AccionViaje(
    id: j['id_accion'] as String,
    viajeId: j['viaje_id'] as int,
    estado: EstadoViaje.desde(j['estado'] as String),
    momento: DateTime.fromMicrosecondsSinceEpoch(j['momento_us'] as int, isUtc: true),
  );

  @override
  String toString() => 'AccionViaje($id, viaje $viajeId → ${estado.valor} a las $momento)';
}

/// Un UUID v4 al azar (el `id_accion` que valida el backend).
String nuevoIdAccion([math.Random? azar]) {
  final r = azar ?? math.Random.secure();
  final bytes = List<int>.generate(16, (_) => r.nextInt(256));
  bytes[6] = (bytes[6] & 0x0f) | 0x40; // versión 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80; // variante RFC 4122
  final hex = [for (final b in bytes) b.toRadixString(16).padLeft(2, '0')].join();
  return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}-'
      '${hex.substring(16, 20)}-${hex.substring(20)}';
}

/// Dónde sobreviven las acciones sin enviar si el sistema cierra la app. Guarda las de **un** chofer.
abstract interface class AlmacenAcciones {
  /// Las acciones guardadas de [usuarioId], en orden. Vacía si no hay nada, si son de otro usuario o si no
  /// se pueden leer.
  Future<List<AccionViaje>> leer(int usuarioId);

  /// Reemplaza lo guardado por [acciones] de [usuarioId].
  Future<void> guardar(int usuarioId, List<AccionViaje> acciones);

  /// Al cerrarse la sesión (401), junto con la cola de ubicaciones.
  Future<void> borrar(int usuarioId);
}

/// Un archivo JSON junto a la cola de ubicaciones (ver [AlmacenColaArchivo]: mismo directorio de caché, fuera
/// de las copias de seguridad), escrito en un temporal que después se renombra. Las operaciones se hacen de a
/// una y en el orden en que se piden. Un archivo ilegible se lee como vacío.
class AlmacenAccionesArchivo implements AlmacenAcciones {
  AlmacenAccionesArchivo(this._directorio);

  /// Un archivo por chofer: `cola_acciones_<usuario>.json`.
  static const nombreArchivo = 'cola_acciones';

  final Future<Directory> Function() _directorio;

  static Future<void> _anterior = Future.value();

  @override
  Future<List<AccionViaje>> leer(int usuarioId) => _enOrden(() async {
    final archivo = await _archivo(usuarioId);
    if (!await archivo.exists()) return <AccionViaje>[];
    try {
      final j = leerMapa(jsonDecode(await archivo.readAsString()));
      if (j['usuario_id'] != usuarioId) return <AccionViaje>[];
      return [for (final a in j['acciones'] as List) AccionViaje.fromJson(leerMapa(a))];
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo leer la cola de acciones guardada (${e.runtimeType}).');
      return <AccionViaje>[];
    }
  });

  @override
  Future<void> guardar(int usuarioId, List<AccionViaje> acciones) {
    final contenido = jsonEncode({
      'usuario_id': usuarioId,
      'acciones': [for (final a in acciones) a.toJson()],
    });
    return _enOrden(() async {
      final archivo = await _archivo(usuarioId);
      await archivo.parent.create(recursive: true);
      final temporal = File('${archivo.path}.tmp');
      await temporal.writeAsString(contenido, flush: true);
      await temporal.rename(archivo.path);
    });
  }

  @override
  Future<void> borrar(int usuarioId) => _enOrden(() async {
    final archivo = await _archivo(usuarioId);
    for (final f in [archivo, File('${archivo.path}.tmp')]) {
      if (await f.exists()) await f.delete();
    }
  });

  Future<File> _archivo(int usuarioId) async =>
      File('${(await _directorio()).path}/${AlmacenColaArchivo.subdirectorio}/${nombreArchivo}_$usuarioId.json');

  static Future<T> _enOrden<T>(Future<T> Function() operacion) {
    final resultado = _anterior.then((_) => operacion());
    _anterior = resultado.then<void>((_) {}, onError: (Object _) {});
    return resultado;
  }
}

/// En web no se persiste nada.
class AlmacenAccionesNulo implements AlmacenAcciones {
  const AlmacenAccionesNulo();

  @override
  Future<List<AccionViaje>> leer(int usuarioId) async => [];

  @override
  Future<void> guardar(int usuarioId, List<AccionViaje> acciones) async {}

  @override
  Future<void> borrar(int usuarioId) async {}
}

final almacenAccionesProvider = Provider<AlmacenAcciones>(
  (ref) => kIsWeb ? const AlmacenAccionesNulo() : AlmacenAccionesArchivo(getApplicationCacheDirectory),
);

/// "1 acción se enviará al reconectar" / "2 acciones se enviarán al reconectar".
String textoAccionesPendientes(int n) =>
    n == 1 ? 'Sin señal: 1 acción se enviará al reconectar' : 'Sin señal: $n acciones se enviarán al reconectar';

/// El servidor rechazó una acción que se mandó sola (al reconectar): el mensaje para el chofer. [numero]
/// cambia con cada aviso, así dos iguales seguidos se muestran los dos.
@immutable
class AvisoAccion {
  const AvisoAccion(this.numero, this.mensaje);

  final int numero;
  final String mensaje;
}

final avisoAccionProvider = NotifierProvider<AvisoAccionNotifier, AvisoAccion?>(AvisoAccionNotifier.new);

class AvisoAccionNotifier extends Notifier<AvisoAccion?> {
  @override
  AvisoAccion? build() => null;

  void avisar(String mensaje) => state = AvisoAccion((state?.numero ?? 0) + 1, mensaje);
}

/// Cómo vienen saliendo las acciones del viaje.
enum EnvioAcciones {
  /// Salen normalmente (o no se intentó todavía): con señal no se avisa nada.
  normal,

  /// El último intento falló por la red: "Sin señal: N acciones se enviarán al reconectar".
  sinSenal,

  /// La misma acción falló [ColaAccionesNotifier.maxErroresServidor] veces seguidas con un error del servidor
  /// (5xx): no es la señal, hay que avisar ("No se pudo enviar el viaje: avisá al encargado"). Se sigue
  /// reintentando.
  errorServidor,
}

/// Lo que se le dice al chofer cuando el servidor rechaza una y otra vez el envío.
const noSePudoEnviarViaje = 'No se pudo enviar el viaje: avisá al encargado';

final envioAccionesProvider = NotifierProvider<EnvioAccionesNotifier, EnvioAcciones>(EnvioAccionesNotifier.new);

/// Ver [EnvioAcciones]: el aviso de acciones pendientes se muestra solo después de un intento fallido (o con el
/// socket caído), no mientras salen normalmente con señal.
class EnvioAccionesNotifier extends Notifier<EnvioAcciones> {
  @override
  EnvioAcciones build() => EnvioAcciones.normal;

  void fijar(EnvioAcciones estado) => state = estado;
}

final colaAccionesProvider = AsyncNotifierProvider<ColaAccionesNotifier, List<AccionViaje>>(ColaAccionesNotifier.new);

/// Las acciones del chofer sin enviar, en orden (decisión 3 del plan sin señal). Se guardan en disco y se
/// mandan de a una, en el orden en que se tocaron: al agregar una, al reconectar el socket, cada
/// [intervaloReintento] mientras haya pendientes, al volver a primer plano (lo pide el turno) y al abrir.
///
/// Sin red, un 5xx o un 401 cortan el envío: la acción queda y se reintenta. Un rechazo del servidor (409 de
/// un viaje cancelado o reasignado mientras tanto, 422, 403, 404) no va a pasar nunca: se descartan las
/// pendientes de ese viaje, el viaje actual vuelve al estado real y se avisa el mensaje del servidor. Las de
/// otros viajes siguen en orden.
///
/// Límite de 24 h: el servidor no acepta un `momento` de más de 24 h atrás (422), así que una acción que
/// estuvo más de un día sin poder salir se descarta así, con el aviso. Tampoco uno de más de 2 min en el
/// futuro: por eso `momento` sale del reloj del servidor estimado (`RelojServidor`), no del teléfono.
///
/// El viaje actual aplica cada acción al instante (ver `ViajeActualNotifier.avanzar`) y recibe acá la
/// respuesta de cada una. La cola de GPS no se manda mientras haya acciones (ver `EmisorUbicacion.retener`).
class ColaAccionesNotifier extends AsyncNotifier<List<AccionViaje>> {
  static const intervaloReintento = Duration(seconds: 30);

  /// Errores del servidor seguidos en la misma acción a partir de los cuales se avisa
  /// ([EnvioAcciones.errorServidor]).
  static const maxErroresServidor = 3;

  /// La acción que viene fallando con errores del servidor, y cuántas veces seguidas.
  String? _conErrorServidor;
  int _erroresServidor = 0;

  void _fijarEnvio(EnvioAcciones estado) {
    ref.read(envioAccionesProvider.notifier).fijar(estado);
  }

  late int _usuarioId;
  Timer? _reintento;

  Future<void>? _enCurso;
  bool _otraVez = false;

  /// Acciones cuyo resultado espera quien las agregó ([agregarYEsperar]).
  final _esperando = <String>{};
  final _respuestas = <String, Viaje>{};
  final _rechazos = <String, ErrorApi>{};

  @override
  Future<List<AccionViaje>> build() async {
    final usuario = ref.watch(usuarioProvider);
    _usuarioId = usuario.id;
    ref.onDispose(() {
      _reintento?.cancel();
      _reintento = null;
    });
    if (!usuario.esChofer) return const [];
    ref.listen(estadoConexionProvider, (antes, ahora) {
      if (ahora == EstadoConexion.conectado && antes != EstadoConexion.conectado) unawaited(sincronizar());
    });

    final guardadas = await _leer();
    if (!ref.mounted) return guardadas;
    _ajustarReintento(guardadas);
    // Lo que quedó de antes de cerrar la app sale apenas termina de cargar.
    if (guardadas.isNotEmpty) unawaited(Future.microtask(sincronizar));
    return guardadas;
  }

  List<AccionViaje> get _pendientes => state.value ?? const [];

  /// Guarda la acción y empieza a mandarla, sin esperar el envío.
  Future<void> agregar(AccionViaje accion) async {
    await _encolar(accion);
    unawaited(sincronizar());
  }

  /// Guarda la acción y espera el intento de envío. Devuelve el viaje que respondió el servidor, o nulo si
  /// quedó en la cola (sin señal). Si el servidor la rechaza lanza ese error (y no se avisa aparte).
  Future<Viaje?> agregarYEsperar(AccionViaje accion) async {
    _esperando.add(accion.id);
    try {
      await _encolar(accion);
      await sincronizar();
      final rechazo = _rechazos[accion.id];
      if (rechazo != null) throw rechazo;
      return _respuestas[accion.id];
    } finally {
      _esperando.remove(accion.id);
      _rechazos.remove(accion.id);
      _respuestas.remove(accion.id);
    }
  }

  Future<void> _encolar(AccionViaje accion) async {
    await future;
    if (!ref.mounted) return;
    await _fijar([..._pendientes, accion]);
  }

  /// Manda las pendientes en orden hasta vaciar la cola o hasta que una no salga. Una llamada mientras otro
  /// envío está en curso no se pierde: al terminar se recorre la cola otra vez. Nunca lanza.
  Future<void> sincronizar() {
    final enCurso = _enCurso;
    if (enCurso != null) {
      _otraVez = true;
      return enCurso;
    }
    return _enCurso = _sincronizarMientrasHaga();
  }

  Future<void> _sincronizarMientrasHaga() async {
    try {
      do {
        _otraVez = false;
        try {
          await _enviarPendientes();
        } catch (e) {
          debugPrint('vehiculos_oficiales: error inesperado al enviar las acciones del viaje (${e.runtimeType}).');
        }
      } while (_otraVez && ref.mounted);
    } finally {
      _enCurso = null;
    }
  }

  Future<void> _enviarPendientes() async {
    await future;
    while (ref.mounted) {
      final accion = _pendientes.firstOrNull;
      if (accion == null) return;
      final Viaje viaje;
      try {
        viaje = await ref
            .read(apiProvider)
            .avanzarViaje(accion.viajeId, accion.estado, momento: accion.momento, idAccion: accion.id);
      } on ErrorApi catch (e) {
        if (!ref.mounted) return;
        switch (e) {
          // El servidor responde con error: se reintenta, pero si la misma acción falla así varias veces
          // seguidas no es la señal y se avisa.
          case ErrorServidor() || ServicioNoDisponible():
            final seguidos = _erroresServidor = accion.id == _conErrorServidor ? _erroresServidor + 1 : 1;
            _conErrorServidor = accion.id;
            _fijarEnvio(seguidos >= maxErroresServidor ? EnvioAcciones.errorServidor : EnvioAcciones.sinSenal);
            return;
          // Sin red o la sesión vencida: se reintenta más tarde.
          case SinConexion() || SesionInvalida():
            _conErrorServidor = null;
            _fijarEnvio(EnvioAcciones.sinSenal);
            return;
          case Conflicto() || ErrorNegocio() || AccesoDenegado() || NoEncontrado():
            await _descartar(accion.viajeId, e);
            continue;
        }
      }
      if (!ref.mounted) return;
      _conErrorServidor = null;
      _fijarEnvio(EnvioAcciones.normal);
      if (_esperando.contains(accion.id)) _respuestas[accion.id] = viaje;
      await _fijar([
        for (final a in _pendientes)
          if (a.id != accion.id) a,
      ]);
      if (!ref.mounted) return;
      ref.read(viajeActualProvider.notifier).accionEnviada(viaje);
    }
  }

  /// El servidor rechazó una acción de [viajeId]: las siguientes de ese viaje tampoco van a pasar.
  Future<void> _descartar(int viajeId, ErrorApi error) async {
    final descartadas = [
      for (final a in _pendientes)
        if (a.viajeId == viajeId) a.id,
    ];
    await _fijar([
      for (final a in _pendientes)
        if (a.viajeId != viajeId) a,
    ]);
    if (!ref.mounted) return;
    var esperada = false;
    for (final id in descartadas) {
      if (_esperando.contains(id)) {
        _rechazos[id] = error;
        esperada = true;
      }
    }
    debugPrint('vehiculos_oficiales: el servidor rechazó una acción del viaje $viajeId (${error.runtimeType}).');
    unawaited(ref.read(viajeActualProvider.notifier).accionRechazada(viajeId));
    if (!esperada) ref.read(avisoAccionProvider.notifier).avisar(error.mensaje);
  }

  Future<void> _fijar(List<AccionViaje> acciones) async {
    state = AsyncData(List.unmodifiable(acciones));
    _ajustarReintento(acciones);
    if (acciones.isEmpty) _fijarEnvio(EnvioAcciones.normal);
    try {
      await ref.read(almacenAccionesProvider).guardar(_usuarioId, acciones);
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo guardar la cola de acciones (${e.runtimeType}).');
    }
  }

  void _ajustarReintento(List<AccionViaje> acciones) {
    if (acciones.isEmpty) {
      _reintento?.cancel();
      _reintento = null;
    } else {
      _reintento ??= Timer.periodic(intervaloReintento, (_) => unawaited(sincronizar()));
    }
  }

  Future<List<AccionViaje>> _leer() async {
    try {
      return List.unmodifiable(await ref.read(almacenAccionesProvider).leer(_usuarioId));
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo leer la cola de acciones guardada (${e.runtimeType}).');
      return const [];
    }
  }
}
