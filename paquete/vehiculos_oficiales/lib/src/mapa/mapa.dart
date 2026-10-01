import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import '../modelos/comunes.dart';
import 'mapa_google.dart';
import 'mapa_osm.dart';

enum TipoMarcador { choferLibre, choferNoDisponible, choferAsignado, origen, destino }

class MarcadorMapa {
  const MarcadorMapa({
    required this.id,
    required this.posicion,
    required this.tipo,
    required this.titulo,
    this.alTocar,
  });

  final String id;
  final Coordenada posicion;
  final TipoMarcador tipo;
  final String titulo;
  final VoidCallback? alTocar;
}

/// A dónde tiene que mirar el mapa: un punto (p. ej. la ubicación actual) o varios que deben quedar
/// todos a la vista (p. ej. origen y destino).
///
/// El mapa mueve la cámara cada vez que recibe un enfoque distinto del último que aplicó. Para volver
/// a centrar en el mismo lugar (botón "Mi ubicación", después de que la persona movió el mapa) se
/// manda el mismo punto con otra [version].
@immutable
class Enfoque {
  Enfoque.punto(Coordenada punto, {this.version = 0}) : puntos = List.unmodifiable([punto]);

  Enfoque.entre(List<Coordenada> puntos, {this.version = 0})
    : assert(puntos.isNotEmpty, 'un enfoque necesita al menos un punto'),
      puntos = List.unmodifiable(puntos);

  final List<Coordenada> puntos;

  /// Distingue dos pedidos de centrar en los mismos puntos.
  final int version;

  /// Zoom al centrar en un solo punto.
  static const zoomPunto = 15.0;

  /// Margen (en píxeles lógicos) alrededor de los puntos al encuadrar varios.
  static const margen = 64.0;

  /// El punto, si todos son el mismo; nulo si hay que encuadrar varios.
  Coordenada? get unico => puntos.every((p) => p == puntos.first) ? puntos.first : null;

  double get sur => puntos.map((p) => p.lat).reduce(math.min);
  double get norte => puntos.map((p) => p.lat).reduce(math.max);
  double get oeste => puntos.map((p) => p.lng).reduce(math.min);
  double get este => puntos.map((p) => p.lng).reduce(math.max);

  @override
  bool operator ==(Object other) => other is Enfoque && other.version == version && listEquals(other.puntos, puntos);

  @override
  int get hashCode => Object.hash(version, Object.hashAll(puntos));

  @override
  String toString() => 'Enfoque($puntos, version: $version)';
}

/// Decide cuándo mover la cámara: solo cuando llega un enfoque distinto del último aplicado. Así
/// reconstruir el mapa (cambió un marcador, etc.) no le quita a la persona el lugar al que lo movió.
class SeguidorEnfoque {
  Enfoque? _aplicado;

  /// El enfoque a aplicar ahora, o nulo si no hay que mover la cámara. Lo da por aplicado.
  Enfoque? aMover(Enfoque? actual) {
    if (actual == null || actual == _aplicado) return null;
    return _aplicado = actual;
  }
}

class DatosMapa {
  const DatosMapa({required this.centro, this.enfoque, this.marcadores = const [], this.alTocarMapa});

  /// Dónde arranca el mapa si no hay [enfoque].
  final Coordenada centro;

  /// A dónde mirar; ver [Enfoque]. Nulo = dejar la cámara donde está.
  final Enfoque? enfoque;

  final List<MarcadorMapa> marcadores;

  /// Si no es nulo, tocar el mapa elige un punto (origen o destino del pedido).
  final ValueChanged<Coordenada>? alTocarMapa;
}

/// Botón para volver a centrar el mapa en la ubicación actual; va encima del mapa.
class BotonMiUbicacion extends StatelessWidget {
  const BotonMiUbicacion({super.key, required this.alTocar});

  final VoidCallback? alTocar;

  @override
  Widget build(BuildContext context) {
    return FloatingActionButton.small(
      heroTag: null,
      tooltip: 'Mi ubicación',
      onPressed: alTocar,
      child: const Icon(Icons.my_location),
    );
  }
}

/// Costura para no instanciar un mapa real en los tests: las pantallas dibujan el mapa con lo que
/// devuelva este provider.
typedef ConstructorMapa = Widget Function(BuildContext context, DatosMapa datos);

/// Con clave de Google Maps en la configuración, [MapaGoogle]; sin clave, [MapaOsm] (OpenStreetMap,
/// solo para desarrollo y demos).
final constructorMapaProvider = Provider<ConstructorMapa>((ref) {
  final claveGoogle = ref.watch(entornoProvider).config.googleMapsApiKey;
  if (claveGoogle.isEmpty) return (context, datos) => MapaOsm(datos: datos);
  return (context, datos) => MapaGoogle(datos: datos);
});
