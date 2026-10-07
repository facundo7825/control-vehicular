import 'comunes.dart';
import 'json.dart';
import 'viaje.dart';

/// Turno del chofer. `GET /turnos/actual`, `POST /turnos` y `POST /turnos/actual/finalizar` devuelven el
/// modelo Eloquent (no un Resource): fechas con microsegundos y campos extra que se ignoran. Al finalizar
/// no viene `vehiculo`. `origen` (`manual` o `asistencia`) y `cierre_pendiente_en` pueden faltar.
class Turno {
  const Turno({
    required this.id,
    required this.vehiculo,
    required this.inicio,
    this.fin,
    this.origen,
    this.cierrePendienteEn,
  });

  factory Turno.fromJson(Json j) => Turno(
    id: j['id'] as int,
    vehiculo: j['vehiculo'] == null ? null : Vehiculo.fromJson(leerMapa(j['vehiculo'])),
    inicio: leerFecha(j['inicio']),
    fin: leerFechaOpcional(j['fin']),
    origen: j['origen']?.toString(),
    cierrePendienteEn: leerFechaOpcional(j['cierre_pendiente_en']),
  );

  final int id;
  final Vehiculo? vehiculo;
  final DateTime inicio;
  final DateTime? fin;
  final String? origen;

  /// Se fichó la salida durante un viaje: el turno se cierra solo cuando el viaje termina.
  final DateTime? cierrePendienteEn;

  bool get abierto => fin == null;

  /// Lo abrió un fichaje de entrada (no el chofer desde la app).
  bool get porFichaje => origen == 'asistencia';

  /// Lo que lee [Turno.fromJson] (para guardarlo en el teléfono y abrir sin señal).
  Json toJson() => {
    'id': id,
    'vehiculo': vehiculo?.toJson(),
    'inicio': escribirFecha(inicio),
    'fin': fin == null ? null : escribirFecha(fin!),
    'origen': origen,
    'cierre_pendiente_en': cierrePendienteEn == null ? null : escribirFecha(cierrePendienteEn!),
  };
}

/// `GET /agenda`: reservas confirmadas del chofer y solicitudes de reserva por responder.
class Agenda {
  const Agenda({required this.reservas, required this.solicitudes});

  factory Agenda.fromJson(Json j) => Agenda(
    reservas: leerLista(j['reservas']).map(Viaje.fromJson).toList(),
    solicitudes: leerLista(j['solicitudes']).map(Oferta.fromJson).toList(),
  );

  static const vacia = Agenda(reservas: [], solicitudes: []);

  final List<Viaje> reservas;
  final List<Oferta> solicitudes;
}

/// `hoy` de `GET /chofer/viajes`: viajes finalizados y metros recorridos en el día local, y desde cuándo
/// está abierto el turno (nulo sin turno).
class ResumenDia {
  const ResumenDia({required this.viajes, required this.metros, this.enTurnoDesde});

  factory ResumenDia.fromJson(Json j) => ResumenDia(
    viajes: j['viajes'] as int,
    metros: j['metros'] as int,
    enTurnoDesde: leerFechaOpcional(j['en_turno_desde']),
  );

  final int viajes;
  final int metros;
  final DateTime? enTurnoDesde;
}

/// `GET /chofer/viajes`: el resumen de hoy y los viajes pasados del chofer (finalizados y cancelados), los
/// más recientes primero.
class ViajesChofer {
  const ViajesChofer({required this.hoy, required this.viajes});

  factory ViajesChofer.fromJson(Json j) => ViajesChofer(
    hoy: ResumenDia.fromJson(leerMapa(j['hoy'])),
    viajes: leerLista(j['viajes']).map(Viaje.fromJson).toList(),
  );

  final ResumenDia hoy;
  final List<Viaje> viajes;
}

/// Un punto del GPS del turno, tal como se manda en `POST /ubicacion`.
class PuntoGps {
  const PuntoGps({required this.posicion, this.rumbo, this.velocidad, required this.registradoEn});

  final Coordenada posicion;
  final double? rumbo;
  final double? velocidad;
  final DateTime registradoEn;

  /// El backend valida `rumbo` entre 0 y 360 y `velocidad` no negativa. El GPS informa -1 (iOS) o valores
  /// sin dato cuando no los tiene: esos van como nulos, así un punto así no rechaza el lote entero.
  Json toJson() => {
    'lat': posicion.lat,
    'lng': posicion.lng,
    'rumbo': rumbo != null && rumbo! >= 0 && rumbo! <= 360 ? rumbo : null,
    'velocidad': velocidad != null && velocidad! >= 0 ? velocidad : null,
    'registrado_en': escribirFecha(registradoEn),
  };
}
