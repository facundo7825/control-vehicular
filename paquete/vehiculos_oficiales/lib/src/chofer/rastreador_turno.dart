import 'dart:async';

import 'package:flutter/foundation.dart';

import '../modelos/modelos.dart';
import '../ubicacion/ubicador.dart';
import 'cola_ubicaciones.dart';
import 'emisor_ubicacion.dart';

/// El GPS está abierto pero no entrega posiciones (ver `RastreadorTurno._armarSilencio`).
class SinPosicionGps implements Exception {
  const SinPosicionGps();

  @override
  String toString() => 'SinPosicionGps: el GPS no entrega posiciones';
}

/// Une el GPS del turno con la cola y el emisor (decisiones 4 y 5). Es dueño del stream del GPS y del
/// timer de envío: [detener] los libera.
///
/// El GPS se abre **una sola vez por turno**, al ritmo más rápido de los dos ([intervaloGps]), y no se
/// vuelve a abrir cuando empieza o termina un viaje: con la app en segundo plano, cortar y reabrir el
/// stream apaga el servicio en primer plano de Android y reabrirlo falla en Android 12+ con el permiso
/// "mientras se usa la app" (en iOS tampoco se pueden reiniciar las actualizaciones desde segundo plano).
/// Solo se reabre al iniciar el turno o con "Reintentar" después de un error del GPS ([reabrirGps]).
///
/// Lo que cambia con el viaje es el ritmo de envío: cada [intervaloTurno] sin viaje, cada
/// [intervaloViaje] con un viaje activo. Para que la cola no crezca más rápido de lo que se manda, se
/// encola como mucho un punto por intervalo de envío (ver [_alPunto]); la posición propia se actualiza
/// con todos.
class RastreadorTurno {
  RastreadorTurno({
    required this.ubicador,
    required this.cola,
    required this.emisor,
    required this.intervaloTurno,
    required this.intervaloViaje,
    required this.alPunto,
    required this.alErrorGps,
    required this.alQuedarSinTurno,
    this.sinPosicionTras,
    this.guardar,
    this.intervaloGuardado = const Duration(seconds: 5),
    this.alCambiarAtraso,
  });

  final Ubicador ubicador;
  final ColaUbicaciones cola;
  final EmisorUbicacion emisor;
  final Duration intervaloTurno;
  final Duration intervaloViaje;

  /// Cada punto del GPS (para mostrar la posición propia).
  final void Function(PuntoGps punto) alPunto;

  /// El GPS falló (permiso revocado, GPS apagado…). El envío de lo pendiente sigue.
  final void Function(Object error) alErrorGps;

  /// El backend respondió que no hay turno (o que ya no es chofer). El rastreo ya está detenido.
  final void Function() alQuedarSinTurno;

  /// Tiempo sin puntos del GPS después del cual se avisa con [SinPosicionGps]. Por defecto
  /// `max(30 s, 3 × intervaloGps)`.
  final Duration? sinPosicionTras;

  /// Guarda la cola completa en disco (ver `AlmacenCola`), para retomarla si el sistema cierra la app. Se
  /// llama como mucho una vez cada [intervaloGuardado] mientras entran puntos (lo que protege es este
  /// guardado periódico: no hay un aviso confiable de que la app se va a cerrar), después de cada envío
  /// confirmado y con [guardarPendiente]. Sus errores se loguean y no cortan el rastreo.
  final Future<void> Function(List<PuntoGps> puntos)? guardar;
  final Duration intervaloGuardado;

  /// Hay puntos que no se pudieron mandar (sin señal, o retenidos mientras hay acciones del viaje sin enviar),
  /// o ya salieron. Se llama solo cuando cambia, después de cada envío.
  final void Function(bool atrasados)? alCambiarAtraso;
  bool _atrasados = false;

  StreamSubscription<PuntoGps>? _gps;
  Timer? _envio;

  /// Pendiente mientras hay puntos encolados que todavía no se guardaron.
  Timer? _guardado;

  /// Guardados pedidos (para saber si el que terminó es el último).
  int _guardados = 0;

  /// El último guardado fue de una cola vacía y terminó bien.
  bool _vacioGuardado = false;

  /// Perro guardián del GPS: se arma al abrir el stream y con cada punto; dispara una sola vez por período
  /// de silencio.
  Timer? _silencio;
  bool _activo = false;
  bool _enViaje = false;

  /// Hora del punto encolado más nuevo (para no encolar más de uno por intervalo de envío).
  DateTime? _ultimoEncolado;

  bool get activo => _activo;

  /// Ritmo de envío actual.
  Duration get intervalo => _enViaje ? intervaloViaje : intervaloTurno;

  /// Ritmo del GPS: el más rápido de los dos, fijo durante todo el turno.
  Duration get intervaloGps => intervaloViaje < intervaloTurno ? intervaloViaje : intervaloTurno;

  Duration get _limiteSilencio {
    final tres = intervaloGps * 3;
    return sinPosicionTras ?? (tres > const Duration(seconds: 30) ? tres : const Duration(seconds: 30));
  }

  void iniciar({bool enViaje = false}) {
    if (_activo) return;
    _activo = true;
    _enViaje = enViaje;
    _abrirGps();
    _programarEnvio();
    // Lo que quedó guardado de antes no salió: está atrasado hasta el primer envío.
    if (cola.largo > 0) _informarAtraso(ResultadoEnvio.reintentar);
  }

  /// Con un viaje activo el envío pasa a [intervaloViaje]; sin viaje, a [intervaloTurno]. El GPS no se toca.
  void enViaje(bool activo) {
    if (_enViaje == activo) return;
    _enViaje = activo;
    if (_activo) _programarEnvio();
  }

  /// Vuelve a abrir el GPS (después de que falló) sin tocar la cola ni el envío.
  void reabrirGps() {
    if (_activo) _abrirGps();
  }

  void _abrirGps() {
    // geolocator_android reutiliza el stream mientras alguien lo escuche: se cancela el anterior antes de
    // abrir otro. La cancelación suelta el stream en el momento (no hace falta esperar el Future de `cancel`).
    _cancelar(_gps);
    _gps = null;
    _silencio?.cancel();
    _silencio = null;
    try {
      _gps = ubicador.seguir(intervaloGps).listen(_alPunto, onError: alErrorGps);
      _armarSilencio();
    } catch (e) {
      alErrorGps(e); // un plugin que lanza al abrir el stream (p. ej. sin implementación en la plataforma)
    }
  }

  /// Si la plataforma rechaza el stream al activarlo (p. ej. falta un permiso de Android) Flutter lo manda
  /// a FlutterError y el stream nunca emite ni llama a `onError`; lo mismo si el GPS deja de entregar a
  /// mitad del turno. Sin puntos en [_limiteSilencio] se avisa como un error del GPS. No reabre el GPS.
  void _armarSilencio() {
    _silencio?.cancel();
    _silencio = Timer(_limiteSilencio, () {
      _silencio = null;
      if (_activo) alErrorGps(const SinPosicionGps());
    });
  }

  void _programarEnvio() {
    _envio?.cancel();
    _envio = Timer.periodic(intervalo, (_) => unawaited(_enviar()));
  }

  /// Se encola un punto si pasó al menos el intervalo de envío desde el último encolado, con medio
  /// intervalo del GPS de tolerancia (el GPS no entrega exacto: con 10 s de envío y 5 s de GPS, un punto
  /// a los 9,9 s entra). En viaje, con el GPS al mismo ritmo que el envío, entran todos. Un punto más
  /// viejo que el último encolado (el GPS entregó uno atrasado o el reloj del teléfono volvió atrás) entra
  /// igual: la cola lo ordena.
  void _alPunto(PuntoGps p) {
    _armarSilencio();
    final ultimo = _ultimoEncolado;
    final desde = ultimo == null ? null : p.registradoEn.difference(ultimo);
    if (desde == null || desde.isNegative || desde >= intervalo - intervaloGps ~/ 2) {
      cola.agregar(p);
      _guardado ??= Timer(intervaloGuardado, _guardar);
      if (desde == null || !desde.isNegative) _ultimoEncolado = p.registradoEn;
    }
    alPunto(p);
  }

  /// [todo]: lotes hasta vaciar la cola (después de que salieron las acciones del viaje), no solo uno.
  Future<void> _enviar({bool todo = false}) async {
    try {
      final antes = cola.largo;
      final resultado = await (todo ? emisor.vaciarTodo() : emisor.enviar());
      if (!_activo) return;
      // Con un solo lote no salió todo lo que había: sigue atrasado hasta el próximo envío.
      _informarAtraso(resultado, quedaronLotes: !todo && antes > emisor.lote);
      if (resultado == ResultadoEnvio.enviado) _guardar();
      if (resultado == ResultadoEnvio.sinTurno) {
        // El turno se lleva los puntos que quedan antes de que se descarten (pueden ser de un viaje).
        alQuedarSinTurno();
        detener();
      }
    } catch (e) {
      // Corre sin await desde el timer: nada puede escaparse (el emisor ya no lanza ErrorApi).
      debugPrint('vehiculos_oficiales: error inesperado al enviar la ubicación (${e.runtimeType}).');
    }
  }

  /// Un intento de mandar todo lo pendiente (antes de finalizar el turno).
  Future<ResultadoEnvio> vaciar() async {
    final resultado = await emisor.vaciarTodo();
    if (_activo) _informarAtraso(resultado);
    if (_activo) _guardar(); // si después no se puede cerrar el turno, lo guardado refleja lo que salió
    return resultado;
  }

  /// Todo lo pendiente ya, sin esperar al timer: lo pide el turno cuando salieron las acciones del viaje.
  void enviarAhora() {
    if (_activo) unawaited(_enviar(todo: true));
  }

  /// Atrasado mientras queden puntos sin mandar después de un envío que no salió (sin señal, retenido) o
  /// que mandó solo uno de varios lotes.
  void _informarAtraso(ResultadoEnvio resultado, {bool quedaronLotes = false}) {
    final atrasados = switch (resultado) {
      ResultadoEnvio.reintentar || ResultadoEnvio.retenido => cola.largo > 0,
      ResultadoEnvio.enviado => quedaronLotes && cola.largo > 0,
      ResultadoEnvio.sinCambios || ResultadoEnvio.sinTurno => false,
    };
    if (atrasados == _atrasados) return;
    _atrasados = atrasados;
    alCambiarAtraso?.call(atrasados);
  }

  /// Guarda ya los puntos encolados que todavía no se guardaron (al cerrar el módulo con el turno abierto).
  void guardarPendiente() {
    if (_guardado != null) _guardar();
  }

  void _guardar() {
    _guardado?.cancel();
    _guardado = null;
    final guardar = this.guardar;
    if (guardar == null) return;
    final puntos = cola.puntos;
    // Con conexión casi todos los guardados son de una cola vacía: no se reescribe un vacío ya guardado.
    if (puntos.isEmpty && _vacioGuardado) return;
    _vacioGuardado = false;
    final numero = ++_guardados;
    unawaited(
      Future.sync(() => guardar(puntos))
          .then<void>((_) {
            // Solo si terminó bien y no se pidió otro guardado después (que pudo tener puntos).
            if (puntos.isEmpty && numero == _guardados) _vacioGuardado = true;
          })
          .catchError(
            (Object e) =>
                debugPrint('vehiculos_oficiales: no se pudo guardar la cola de ubicaciones (${e.runtimeType}).'),
          ),
    );
  }

  /// Corta el GPS (y con él la notificación fija de Android) y el envío. Lo pendiente se descarta (en
  /// memoria: lo guardado en disco lo borra quien corresponda).
  void detener() {
    _activo = false;
    _guardado?.cancel();
    _guardado = null;
    _envio?.cancel();
    _envio = null;
    _silencio?.cancel();
    _silencio = null;
    _cancelar(_gps);
    _gps = null;
    _ultimoEncolado = null;
    _atrasados = false;
    cola.vaciar();
  }

  static void _cancelar(StreamSubscription<PuntoGps>? gps) {
    if (gps == null) return;
    unawaited(gps.cancel().catchError((Object e) => debugPrint('vehiculos_oficiales: error al cortar el GPS ($e).')));
  }
}
