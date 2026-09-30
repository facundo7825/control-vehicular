import 'comunes.dart';
import 'json.dart';

enum EstadoChofer {
  fueraDeTurno('fuera_de_turno', 'Fuera de turno'),
  sinSenal('sin_senal', 'Sin señal'),
  enViaje('en_viaje', 'En viaje'),
  reservadoPronto('reservado_pronto', 'Reservado pronto'),
  libre('libre', 'Libre');

  const EstadoChofer(this.valor, this.texto);

  final String valor;
  final String texto;

  static EstadoChofer desde(String valor) => values.firstWhere(
    (e) => e.valor == valor,
    orElse: () => throw FormatException('Estado de chofer desconocido: $valor'),
  );
}

/// Un chofer en turno, de `GET /choferes`.
class ChoferEnMapa {
  const ChoferEnMapa({
    required this.id,
    required this.nombre,
    required this.estado,
    this.posicion,
    this.rumbo,
    this.actualizadoEn,
    required this.vehiculo,
  });

  factory ChoferEnMapa.fromJson(Json j) => ChoferEnMapa(
    id: j['id'] as int,
    nombre: j['nombre'] as String,
    estado: EstadoChofer.desde(j['estado'] as String),
    posicion: j['lat'] == null ? null : Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])),
    rumbo: leerDoubleOpcional(j['rumbo']),
    actualizadoEn: leerFechaOpcional(j['actualizado_en']),
    vehiculo: Vehiculo.fromJson(leerMapa(j['vehiculo'])),
  );

  final int id;
  final String nombre;
  final EstadoChofer estado;
  final Coordenada? posicion;
  final double? rumbo;
  final DateTime? actualizadoEn;
  final Vehiculo vehiculo;

  /// Spec 5.3 y 7: solo se le puede pedir un viaje a un chofer libre (los "reservados pronto" se ven
  /// pero no se eligen).
  bool get seleccionable => estado == EstadoChofer.libre;

  ChoferEnMapa conEstado(EstadoChofer nuevo) => ChoferEnMapa(
    id: id,
    nombre: nombre,
    estado: nuevo,
    posicion: posicion,
    rumbo: rumbo,
    actualizadoEn: actualizadoEn,
    vehiculo: vehiculo,
  );

  ChoferEnMapa conUbicacion(UbicacionChofer u) => ChoferEnMapa(
    id: id,
    nombre: nombre,
    estado: estado,
    posicion: u.posicion,
    rumbo: u.rumbo,
    actualizadoEn: u.actualizadoEn,
    vehiculo: vehiculo,
  );
}

/// Payload de `chofer.ubicacion` (canales `mapa.choferes` y `viaje.{id}`).
class UbicacionChofer {
  const UbicacionChofer({required this.choferId, required this.posicion, this.rumbo, required this.actualizadoEn});

  factory UbicacionChofer.fromJson(Json j) => UbicacionChofer(
    choferId: j['chofer_id'] as int,
    posicion: Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])),
    rumbo: leerDoubleOpcional(j['rumbo']),
    actualizadoEn: leerFecha(j['actualizado_en']),
  );

  final int choferId;
  final Coordenada posicion;
  final double? rumbo;
  final DateTime actualizadoEn;
}
