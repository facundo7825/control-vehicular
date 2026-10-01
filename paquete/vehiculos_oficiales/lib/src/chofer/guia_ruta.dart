import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../mapa/ruta.dart';
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../ui/comunes/comunes.dart';
import '../viaje/recorrido_viaje.dart';
import '../viaje/viaje_actual.dart';
import 'pasos_viaje.dart';
import 'turno.dart';

final tramoChoferProvider = NotifierProvider.autoDispose<TramoChoferNotifier, TramoRuta?>(TramoChoferNotifier.new);

/// El tramo del recorrido del chofer en su viaje: desde su posición (GPS del turno) al origen mientras va a
/// buscar al pasajero (aceptado, en camino, llegó) y al destino en curso. Nulo sin viaje activo o sin
/// posición todavía (la primera lo arma). Se vuelve a pedir desde otra posición solo con [recalcular], como
/// mucho cada [intervalo]; el timer vive acá.
class TramoChoferNotifier extends Notifier<TramoRuta?> {
  static const intervalo = Duration(seconds: 30);

  Limitador<Coordenada>? _recalculo;

  @override
  TramoRuta? build() {
    final yo = ref.read(usuarioProvider).id;
    // El tramo cambia con el viaje y con a dónde va (origen o destino), no con cada estado.
    final (_, hacia) = ref.watch(
      viajeActualProvider.select((s) {
        final v = s.value?.viaje;
        return viajeActivo(v, yo) ? (v!.id, v.haciaDonde.coordenada) : (null, null);
      }),
    );
    _recalculo = null;
    if (hacia == null) return null;
    final limitador = _recalculo = Limitador<Coordenada>(intervalo, (desde) {
      if (ref.mounted) state = TramoRuta(desde, hacia);
    });
    ref.onDispose(limitador.cancelar);

    final aqui = ref.read(posicionPropiaProvider).punto?.posicion;
    if (aqui != null) {
      limitador.esperar();
      return TramoRuta(aqui, hacia);
    }
    ref.listen(posicionPropiaProvider.select((s) => s.punto?.posicion), (_, p) {
      if (p != null && stateOrNull == null) limitador.poner(p);
    });
    return null;
  }

  /// Se salió del recorrido: otro desde [desde], ya o al terminar el intervalo (con la última posición).
  void recalcular(Coordenada desde) => _recalculo?.poner(desde);

  /// Volvió al recorrido: un recálculo que esperaba el intervalo ya no hace falta.
  void enRuta() => _recalculo?.descartarPendiente();
}

/// La ruta de [tramoChoferProvider]; mientras llega una recalculada sigue la anterior.
final rutaChoferProvider = NotifierProvider.autoDispose<RutaDeTramoNotifier, Ruta?>(
  () => RutaDeTramoNotifier(tramoChoferProvider),
);

final guiaRutaProvider = NotifierProvider.autoDispose<GuiaRutaNotifier, GuiaRuta?>(GuiaRutaNotifier.new);

/// La próxima indicación del chofer, que avanza con su GPS sobre [rutaChoferProvider] (ver [SeguidorRuta]).
/// Si se sale del recorrido pide otro a [TramoChoferNotifier.recalcular]. Nula sin ruta o sin posición.
class GuiaRutaNotifier extends Notifier<GuiaRuta?> {
  @override
  GuiaRuta? build() {
    final ruta = ref.watch(rutaChoferProvider);
    final alDestino = ref.watch(viajeActualProvider.select((s) => s.value?.viaje?.estado == EstadoViaje.enCurso));
    if (ruta == null) return null;
    final seguidor = SeguidorRuta(ruta, alDestino: alDestino);
    ref.listen(posicionPropiaProvider.select((s) => s.punto?.posicion), (_, p) {
      if (p == null) return;
      state = seguidor.avanzar(p);
      final tramo = ref.read(tramoChoferProvider.notifier);
      seguidor.fueraDeRuta ? tramo.recalcular(p) : tramo.enRuta();
    });
    final aqui = ref.read(posicionPropiaProvider).punto?.posicion;
    return aqui == null ? null : seguidor.avanzar(aqui);
  }
}

/// La próxima indicación para el chofer y lo que falta del recorrido.
@immutable
class GuiaRuta {
  const GuiaRuta({required this.tipo, required this.texto, required this.restanteM, required this.restanteS});

  /// El `tipo` de la maniobra (`derecha`, `rotonda`, `llegada`, …), para la flecha.
  final String tipo;

  /// P. ej. "En 200 m, doblá a la derecha por San Martín"; a 30 m o menos, solo la instrucción.
  final String texto;
  final double restanteM;
  final double restanteS;

  /// P. ej. "4,1 km · 9 min".
  String get resumen => '${formatearDistancia(restanteM)} · ${formatearDuracion(restanteS)}';
}

/// "En 1,2 km" desde 1 km, "En 250 m" (de a 50 m) debajo, y nulo a 30 m o menos (se muestra la indicación sola).
String? distanciaAManiobra(double metros) {
  if (metros <= 30) return null;
  final redondeado = math.max(50, (metros / 50).round() * 50);
  if (redondeado < 1000) return 'En $redondeado m';
  return 'En ${NumberFormat('0.#', 'es').format(metros / 1000)} km';
}

/// Sigue el avance del chofer sobre una [Ruta] con cada posición del GPS. Sin Riverpod ni timers: lo usa
/// `GuiaRutaNotifier` y se prueba con recorridos inventados.
///
/// El avance es la proyección de la posición sobre el tramo más cercano de la línea, buscando desde el
/// último tramo hacia adelante y solo hasta [ventanaM] metros: así un recorrido que pasa dos veces por la
/// misma calle (ida y vuelta) no salta a la otra pasada. Una posición a más de [maxDesvioM] de esa parte de
/// la línea no avanza; dos seguidas así son [fueraDeRuta].
class SeguidorRuta {
  SeguidorRuta(this.ruta, {required this.alDestino}) : _acumulado = _acumular(ruta.puntos);

  static const maxDesvioM = 60.0;
  static const ventanaM = 500.0;

  /// Dos pasadas a esta distancia de la mejor cuentan como empatadas: gana la que no vuelve atrás.
  static const empateM = 15.0;

  /// Cuánto puede retroceder el avance por el ruido del GPS al desempatar.
  static const retrocesoM = 20.0;

  final Ruta ruta;

  /// Yendo al destino (en curso) o al origen: cambia el texto de la llegada.
  final bool alDestino;

  /// Metros por la línea desde el primer punto hasta cada punto.
  final List<double> _acumulado;

  int _tramo = 0;
  double _recorridoM = 0;
  int _fuera = 0;
  GuiaRuta? _guia;

  /// La última indicación; nula antes de la primera posición sobre el recorrido.
  GuiaRuta? get guia => _guia;

  /// Las dos últimas posiciones quedaron a más de [maxDesvioM] del recorrido: hay que pedir otro.
  bool get fueraDeRuta => _fuera >= 2;

  double get _largoM => _acumulado.isEmpty ? 0 : _acumulado.last;

  static List<double> _acumular(List<Coordenada> puntos) {
    final acumulado = <double>[];
    for (var i = 0; i < puntos.length; i++) {
      acumulado.add(i == 0 ? 0 : acumulado[i - 1] + distanciaMetros(puntos[i - 1], puntos[i]));
    }
    return acumulado;
  }

  /// Avanza con una posición nueva y devuelve la indicación (la anterior si quedó fuera del recorrido).
  GuiaRuta? avanzar(Coordenada posicion) {
    final puntos = ruta.puntos;
    if (puntos.isEmpty) return _guia;
    final candidatos = <({int tramo, double recorridoM, double desvioM})>[];
    if (puntos.length == 1) {
      candidatos.add((tramo: 0, recorridoM: 0, desvioM: distanciaMetros(posicion, puntos.first)));
    }
    for (var i = _tramo; i < puntos.length - 1; i++) {
      if (i > _tramo && _acumulado[i] - _recorridoM > ventanaM) break;
      final (t, desvio) = _proyectar(posicion, puntos[i], puntos[i + 1]);
      candidatos.add((tramo: i, recorridoM: _acumulado[i] + t * (_acumulado[i + 1] - _acumulado[i]), desvioM: desvio));
    }

    final mejor = candidatos.map((c) => c.desvioM).reduce(math.min);
    if (mejor > maxDesvioM) {
      _fuera++;
      return _guia;
    }
    _fuera = 0;
    // Entre las pasadas empatadas, la primera que no vuelve atrás (o, si todas vuelven, la primera).
    final empatados = candidatos.where((c) => c.desvioM <= mejor + empateM).toList();
    final elegido = empatados.firstWhere(
      (c) => c.recorridoM >= _recorridoM - retrocesoM,
      orElse: () => empatados.first,
    );
    _tramo = elegido.tramo;
    _recorridoM = elegido.recorridoM;
    return _guia = _armar();
  }

  GuiaRuta _armar() {
    final largo = _largoM;
    final restante = math.max(0.0, largo - _recorridoM);
    final fraccion = largo > 0 ? restante / largo : 0.0;
    final llegada = alDestino ? 'Llegaste a destino' : 'Llegaste al origen';

    // La próxima maniobra es la primera que queda por delante (la salida, en el punto 0, nunca).
    final proximo = ruta.pasos.where((p) => _acumulado[p.indice] > _recorridoM + 1).firstOrNull;
    final tipo = proximo?.tipo ?? 'llegada';
    final distancia = proximo == null ? 0.0 : _acumulado[proximo.indice] - _recorridoM;
    final en = distanciaAManiobra(distancia);
    final String texto;
    if (tipo == 'llegada') {
      texto = en == null ? llegada : '$en, ${alDestino ? 'llegás a destino' : 'llegás al origen'}';
    } else {
      final instruccion = proximo!.instruccion;
      texto = en == null ? instruccion : '$en, ${_minuscula(instruccion)}';
    }
    return GuiaRuta(
      tipo: tipo,
      texto: texto,
      restanteM: ruta.distanciaM * fraccion,
      restanteS: ruta.duracionS * fraccion,
    );
  }

  /// Solo la primera letra: los nombres de las calles quedan como vienen.
  static String _minuscula(String s) => s.isEmpty ? s : s[0].toLowerCase() + s.substring(1);

  /// Proyección de [p] sobre el segmento [a]→[b] en un plano local (metros alrededor de [p]): la fracción
  /// del segmento (0 a 1) y la distancia de [p] a la proyección.
  static (double, double) _proyectar(Coordenada p, Coordenada a, Coordenada b) {
    const metrosPorGrado = 6371000 * math.pi / 180;
    final escalaLng = metrosPorGrado * math.cos(p.lat * math.pi / 180);
    final ax = (a.lng - p.lng) * escalaLng, ay = (a.lat - p.lat) * metrosPorGrado;
    final bx = (b.lng - p.lng) * escalaLng, by = (b.lat - p.lat) * metrosPorGrado;
    final dx = bx - ax, dy = by - ay;
    final largo2 = dx * dx + dy * dy;
    final t = largo2 == 0 ? 0.0 : (-(ax * dx + ay * dy) / largo2).clamp(0.0, 1.0);
    final x = ax + t * dx, y = ay + t * dy;
    return (t, math.sqrt(x * x + y * y));
  }
}
