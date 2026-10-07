import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';

import '../modelos/comunes.dart';

/// Una tesela del mapa de fondo en el esquema XYZ de OpenStreetMap (`{z}/{x}/{y}`, y crece hacia el sur). Sin
/// los paquetes de mapa: la cuenta es propia y `MapaOsm` arma la dirección (también la de un servidor TMS).
@immutable
class Tesela {
  const Tesela(this.z, this.x, this.y);

  final int z;
  final int x;
  final int y;

  @override
  bool operator ==(Object other) => other is Tesela && other.z == z && other.x == x && other.y == y;

  @override
  int get hashCode => Object.hash(z, x, y);

  @override
  String toString() => 'Tesela($z/$x/$y)';
}

/// Baja teselas del mapa de fondo al caché de `MapaOsm` (lo implementa `mapa_osm.dart`, el único que conoce
/// los paquetes de mapa).
abstract interface class DescargadorTeselas {
  /// El zoom máximo del servidor de teselas: más cerca no hay teselas propias.
  int get zoomMaximo;

  /// Baja [tesela] al caché, si no estaba. Lanza si no llega.
  Future<void> descargar(Tesela tesela);
}

/// La latitud máxima de la proyección de los mapas web (Mercator): más allá no hay teselas.
const _latitudMaxima = 85.05112878;

/// La tesela que contiene [c] en el zoom [z].
Tesela teselaDe(Coordenada c, int z) {
  final n = 1 << z;
  final lat = c.lat.clamp(-_latitudMaxima, _latitudMaxima) * math.pi / 180;
  final x = ((c.lng + 180) / 360 * n).floor();
  final y = ((1 - math.log(math.tan(lat) + 1 / math.cos(lat)) / math.pi) / 2 * n).floor();
  return Tesela(z, x.clamp(0, n - 1), y.clamp(0, n - 1));
}

/// Cuántas teselas se bajan como mucho para un viaje (unos 30 a 50 MB).
const maxTeselasCorredor = 3000;

const _metrosPorGrado = 6371000 * math.pi / 180;

/// Las teselas que cubren el [recorrido] (una línea) y [radioM] a cada lado, de [zoomMinimo] a [zoomMaximo]:
/// primero todas las de los zooms más lejanos, que con pocas teselas cubren todo el viaje, y después las de
/// más cerca, cada zoom en el orden del recorrido. Sin repetidas, y como mucho [maximo]: en un recorrido muy
/// largo se cortan los zooms más cercanos, nunca el principio de los lejanos.
///
/// Cada tramo se recorre con un punto cada `radioM / 2` y de cada punto se toman las teselas de un cuadrado de
/// [radioM] de lado a lado del punto: así quedan cubiertos los dos costados del tramo, sea cual sea su rumbo.
List<Tesela> teselasDelCorredor(
  List<Coordenada> recorrido, {
  int zoomMinimo = 10,
  int zoomMaximo = 14,
  double radioM = 1000,
  int maximo = maxTeselasCorredor,
}) {
  if (recorrido.isEmpty) return const [];
  final muestras = _muestrear(recorrido, radioM / 2);
  final teselas = <Tesela>{};
  for (var z = zoomMinimo; z <= zoomMaximo; z++) {
    for (final p in muestras) {
      final dLat = radioM / _metrosPorGrado;
      final dLng = radioM / (_metrosPorGrado * math.max(0.01, math.cos(p.lat * math.pi / 180)));
      final noroeste = teselaDe(Coordenada(p.lat + dLat, p.lng - dLng), z);
      final sudeste = teselaDe(Coordenada(p.lat - dLat, p.lng + dLng), z);
      for (var x = noroeste.x; x <= sudeste.x; x++) {
        for (var y = noroeste.y; y <= sudeste.y; y++) {
          teselas.add(Tesela(z, x, y));
          if (teselas.length >= maximo) return teselas.toList();
        }
      }
    }
  }
  return teselas.toList();
}

/// Los puntos del [recorrido] y, entre cada par, los que hagan falta para que ninguno quede a más de [pasoM].
List<Coordenada> _muestrear(List<Coordenada> recorrido, double pasoM) {
  final muestras = [recorrido.first];
  for (var i = 1; i < recorrido.length; i++) {
    final a = recorrido[i - 1];
    final b = recorrido[i];
    final partes = math.max(1, (_distanciaAproximadaM(a, b) / pasoM).ceil());
    for (var k = 1; k <= partes; k++) {
      final t = k / partes;
      muestras.add(Coordenada(a.lat + (b.lat - a.lat) * t, a.lng + (b.lng - a.lng) * t));
    }
  }
  return muestras;
}

/// En un plano local: alcanza para repartir los puntos de un tramo.
double _distanciaAproximadaM(Coordenada a, Coordenada b) {
  final dy = (b.lat - a.lat) * _metrosPorGrado;
  final dx = (b.lng - a.lng) * _metrosPorGrado * math.cos((a.lat + b.lat) / 2 * math.pi / 180);
  return math.sqrt(dx * dx + dy * dy);
}

/// Baja las [teselas] con [bajar], de a [concurrencia] a la vez, y devuelve cuántas bajó. Una que falla se
/// saltea; [maxFallasSeguidas] seguidas son falta de señal y se deja de intentar (lo ya bajado queda). Si
/// [cancelada] da verdadero no se pide ninguna más (las que están en vuelo terminan). [alAvanzar] recibe la
/// cuenta de bajadas con cada una.
Future<int> descargarTeselas(
  List<Tesela> teselas,
  Future<void> Function(Tesela tesela) bajar, {
  int concurrencia = 4,
  int maxFallasSeguidas = 10,
  bool Function()? cancelada,
  void Function(int bajadas)? alAvanzar,
}) async {
  var siguiente = 0;
  var bajadas = 0;
  var fallasSeguidas = 0;
  bool seguir() => siguiente < teselas.length && fallasSeguidas < maxFallasSeguidas && !(cancelada?.call() ?? false);

  Future<void> trabajador() async {
    while (seguir()) {
      final tesela = teselas[siguiente++];
      try {
        await bajar(tesela);
        fallasSeguidas = 0;
        bajadas++;
        alAvanzar?.call(bajadas);
      } catch (_) {
        fallasSeguidas++;
      }
    }
  }

  await Future.wait([for (var i = 0; i < concurrencia; i++) trabajador()]);
  return bajadas;
}
