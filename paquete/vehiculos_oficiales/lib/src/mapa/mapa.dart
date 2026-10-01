import 'package:flutter/widgets.dart';
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

class DatosMapa {
  const DatosMapa({required this.centro, this.marcadores = const [], this.alTocarMapa});

  final Coordenada centro;
  final List<MarcadorMapa> marcadores;

  /// Si no es nulo, tocar el mapa elige un punto (origen o destino del pedido).
  final ValueChanged<Coordenada>? alTocarMapa;
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
