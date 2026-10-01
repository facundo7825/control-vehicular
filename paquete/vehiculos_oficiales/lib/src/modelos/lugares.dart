import 'comunes.dart';
import 'json.dart';

/// Sugerencia de `GET /lugares` (búsqueda de una dirección): `{nombre, direccion, lat, lng}`.
class LugarEncontrado {
  const LugarEncontrado({required this.nombre, required this.direccion, required this.coordenada});

  factory LugarEncontrado.fromJson(Json j) => LugarEncontrado(
    nombre: j['nombre'] as String,
    direccion: j['direccion'] as String,
    coordenada: Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])),
  );

  final String nombre;
  final String direccion;
  final Coordenada coordenada;

  @override
  bool operator ==(Object other) =>
      other is LugarEncontrado &&
      other.nombre == nombre &&
      other.direccion == direccion &&
      other.coordenada == coordenada;

  @override
  int get hashCode => Object.hash(nombre, direccion, coordenada);
}
