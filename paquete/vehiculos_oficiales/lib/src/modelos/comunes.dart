import 'json.dart';

class Coordenada {
  const Coordenada(this.lat, this.lng);

  final double lat;
  final double lng;

  @override
  bool operator ==(Object other) => other is Coordenada && other.lat == lat && other.lng == lng;

  @override
  int get hashCode => Object.hash(lat, lng);

  @override
  String toString() => 'Coordenada($lat, $lng)';
}

/// Origen o destino de un viaje: `{lat, lng, direccion}`.
class Lugar {
  const Lugar(this.coordenada, {this.direccion});

  factory Lugar.fromJson(Json j) =>
      Lugar(Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])), direccion: j['direccion'] as String?);

  final Coordenada coordenada;
  final String? direccion;

  /// Texto para mostrar: la dirección o, si no hay, un texto fijo (nunca las coordenadas).
  String get descripcion => direccion ?? sinDireccion;

  static const sinDireccion = 'Ubicación marcada en el mapa';
}

/// `{patente, marca, modelo, color}`; `id` solo viene en `GET /vehiculos/disponibles`.
class Vehiculo {
  const Vehiculo({this.id, required this.patente, required this.marca, required this.modelo, this.color});

  factory Vehiculo.fromJson(Json j) => Vehiculo(
    id: j['id'] as int?,
    patente: j['patente'] as String,
    marca: j['marca'] as String,
    modelo: j['modelo'] as String,
    color: j['color'] as String?,
  );

  final int? id;
  final String patente;
  final String marca;
  final String modelo;
  final String? color;

  String get descripcion => '$marca $modelo ($patente)';
}

/// Chofer o solicitante dentro de un viaje: `{id, nombre, telefono}`.
class Persona {
  const Persona({required this.id, required this.nombre, this.telefono});

  factory Persona.fromJson(Json j) =>
      Persona(id: j['id'] as int, nombre: j['nombre'] as String, telefono: j['telefono'] as String?);

  final int id;
  final String nombre;
  final String? telefono;
}
