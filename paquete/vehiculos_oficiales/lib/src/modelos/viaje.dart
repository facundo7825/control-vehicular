import 'comunes.dart';
import 'json.dart';

enum EstadoViaje {
  buscando('buscando', 'Buscando chofer'),
  ofrecido('ofrecido', 'Buscando chofer'),
  aceptado('aceptado', 'Chofer asignado'),
  enCamino('en_camino', 'El chofer va en camino'),
  llego('llego', 'El chofer llegó'),
  enCurso('en_curso', 'En viaje'),
  finalizado('finalizado', 'Viaje finalizado'),
  cancelado('cancelado', 'Viaje cancelado'),
  sinChofer('sin_chofer', 'No hay choferes disponibles');

  const EstadoViaje(this.valor, this.texto);

  final String valor;
  final String texto;

  static EstadoViaje desde(String valor) => values.firstWhere(
    (e) => e.valor == valor,
    orElse: () => throw FormatException('Estado de viaje desconocido: $valor'),
  );

  bool get buscandoChofer => this == buscando || this == ofrecido;

  bool get conChofer => this == aceptado || this == enCamino || this == llego || this == enCurso;

  bool get terminado => this == finalizado || this == cancelado || this == sinChofer;

  /// El solicitante puede cancelar en cualquier estado anterior a `en_curso` (spec 5.6).
  bool get cancelablePorSolicitante => !terminado && this != enCurso;
}

enum TipoViaje {
  inmediato,
  reserva;

  static TipoViaje desde(String valor) => values.byName(valor);
}

enum ModoViaje {
  masCercano('mas_cercano'),
  especifico('especifico'),
  cualquieraDisponible('cualquiera_disponible');

  const ModoViaje(this.valor);

  final String valor;

  static ModoViaje desde(String valor) => values.firstWhere(
    (e) => e.valor == valor,
    orElse: () => throw FormatException('Modo de viaje desconocido: $valor'),
  );
}

/// `ViajeResource` del backend (también es el payload del evento `viaje.actualizado`).
class Viaje {
  const Viaje({
    required this.id,
    required this.tipo,
    required this.modo,
    required this.estado,
    required this.obligatorio,
    required this.origen,
    required this.destino,
    this.motivo,
    this.programadoPara,
    this.duracionEstimadaMin,
    this.chofer,
    this.vehiculo,
    required this.solicitante,
    this.aceptadoEn,
    this.llegoEn,
    this.iniciadoEn,
    this.finalizadoEn,
    this.canceladoEn,
  });

  factory Viaje.fromJson(Json j) => Viaje(
    id: j['id'] as int,
    tipo: TipoViaje.desde(j['tipo'] as String),
    modo: ModoViaje.desde(j['modo'] as String),
    estado: EstadoViaje.desde(j['estado'] as String),
    obligatorio: j['obligatorio'] as bool,
    origen: Lugar.fromJson(leerMapa(j['origen'])),
    destino: Lugar.fromJson(leerMapa(j['destino'])),
    motivo: j['motivo'] as String?,
    programadoPara: leerFechaOpcional(j['programado_para']),
    duracionEstimadaMin: j['duracion_estimada_min'] as int?,
    chofer: j['chofer'] == null ? null : Persona.fromJson(leerMapa(j['chofer'])),
    vehiculo: j['vehiculo'] == null ? null : Vehiculo.fromJson(leerMapa(j['vehiculo'])),
    solicitante: Persona.fromJson(leerMapa(j['solicitante'])),
    aceptadoEn: leerFechaOpcional(j['aceptado_en']),
    llegoEn: leerFechaOpcional(j['llego_en']),
    iniciadoEn: leerFechaOpcional(j['iniciado_en']),
    finalizadoEn: leerFechaOpcional(j['finalizado_en']),
    canceladoEn: leerFechaOpcional(j['cancelado_en']),
  );

  final int id;
  final TipoViaje tipo;
  final ModoViaje modo;
  final EstadoViaje estado;
  final bool obligatorio;
  final Lugar origen;
  final Lugar destino;
  final String? motivo;
  final DateTime? programadoPara;
  final int? duracionEstimadaMin;
  final Persona? chofer;
  final Vehiculo? vehiculo;
  final Persona solicitante;
  final DateTime? aceptadoEn;
  final DateTime? llegoEn;
  final DateTime? iniciadoEn;
  final DateTime? finalizadoEn;
  final DateTime? canceladoEn;
}

/// Oferta de viaje para un chofer. Llega como `{id, vence_en, viaje}` (`GET /viajes/actual`,
/// `GET /agenda`) o como `{oferta_id, vence_en, viaje}` (evento `oferta.creada`).
class Oferta {
  const Oferta({required this.id, required this.venceEn, required this.viaje});

  factory Oferta.fromJson(Json j) => Oferta(
    id: (j['id'] ?? j['oferta_id']) as int,
    venceEn: leerFecha(j['vence_en']),
    viaje: Viaje.fromJson(leerMapa(j['viaje'])),
  );

  final int id;
  final DateTime venceEn;
  final Viaje viaje;
}

/// `GET /viajes/actual`: `{viaje, oferta}`. `oferta` solo aparece para choferes.
class ViajeActual {
  const ViajeActual({this.viaje, this.oferta});

  factory ViajeActual.fromJson(Json j) => ViajeActual(
    viaje: j['viaje'] == null ? null : Viaje.fromJson(leerMapa(j['viaje'])),
    oferta: j['oferta'] == null ? null : Oferta.fromJson(leerMapa(j['oferta'])),
  );

  static const vacio = ViajeActual();

  final Viaje? viaje;
  final Oferta? oferta;
}

/// `GET /viajes`: próximas reservas e historial del solicitante.
class MisViajes {
  const MisViajes({required this.proximas, required this.historial});

  factory MisViajes.fromJson(Json j) => MisViajes(
    proximas: leerLista(j['proximas']).map(Viaje.fromJson).toList(),
    historial: leerLista(j['historial']).map(Viaje.fromJson).toList(),
  );

  final List<Viaje> proximas;
  final List<Viaje> historial;
}
