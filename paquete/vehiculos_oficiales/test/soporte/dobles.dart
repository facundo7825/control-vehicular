import 'dart:async';

import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import '../fixtures/payloads.dart' as p;
import 'adaptador_falso.dart';
import 'entorno_prueba.dart';

const solicitante = Usuario(id: 1, nombre: 'Ana Pérez', cargo: 'Secretaria', rol: Rol.solicitante);
const chofer = Usuario(id: 2, nombre: 'Carlos Gómez', cargo: 'Chofer', rol: Rol.chofer);

/// Un viaje real (fixture) con el estado pedido. `conChofer` agrega chofer 2 y vehículo.
Viaje viaje({
  int id = 1,
  String estado = 'ofrecido',
  bool conChofer = false,
  bool obligatorio = false,
  String tipo = 'inmediato',
}) {
  final j = p.json(conChofer ? p.viajeAceptado : p.viajeOfrecido)
    ..['id'] = id
    ..['estado'] = estado
    ..['obligatorio'] = obligatorio
    ..['tipo'] = tipo;
  return Viaje.fromJson(j);
}

Json jsonViaje(Viaje v, {bool conChofer = true}) {
  final j = p.json(conChofer ? p.viajeAceptado : p.viajeOfrecido)
    ..['id'] = v.id
    ..['estado'] = v.estado.valor
    ..['obligatorio'] = v.obligatorio
    ..['tipo'] = v.tipo.name;
  return j;
}

/// API con respuestas programables. Lo que no se programa falla como "sin respuesta preparada".
class ApiFalsa extends ApiVehiculos {
  ApiFalsa() : super(ClienteApi(baseApi: configPrueba.apiUri, alRecibir401: () {}, adaptador: AdaptadorFalso()));

  ViajeActual actual = ViajeActual.vacio;
  MisViajes mis = const MisViajes(proximas: [], historial: []);
  List<ChoferEnMapa> listaChoferes = [];
  ErrorApi? fallarConsultas;

  /// Solo para `choferes()` (además de [fallarConsultas]).
  ErrorApi? fallarChoferes;
  int consultasActual = 0;
  int consultasChoferes = 0;
  final pedidos = <PedidoViaje>[];
  final cancelaciones = <(int, String?)>[];
  Viaje? respuestaPedido;
  ErrorApi? errorPedido;
  Eta? etaRespuesta;
  ErrorApi? fallarEta;
  int consultasEta = 0;

  /// Respuestas de `GET /viajes/{id}` por id; sin respuesta preparada falla con StateError.
  final detalles = <int, Viaje>{};
  ErrorApi? errorDetalle;
  final consultasDetalle = <int>[];

  /// Respuesta de `buscarLugares` y las búsquedas recibidas (texto, cerca de).
  List<LugarEncontrado> lugares = [];
  ErrorApi? errorLugares;
  final busquedas = <(String, Coordenada?)>[];

  /// Si no es nulo, `buscarLugares` espera a que el test lo complete antes de responder.
  Completer<void>? demorarLugares;

  @override
  Future<List<LugarEncontrado>> buscarLugares(String texto, {Coordenada? cerca}) async {
    busquedas.add((texto, cerca));
    await demorarLugares?.future;
    if (errorLugares != null) throw errorLugares!;
    return lugares;
  }

  /// Respuesta de `obtenerRuta` (nula = sin recorrido) y los pedidos recibidos (origen, destino).
  Ruta? ruta;
  ErrorApi? errorRuta;
  final consultasRuta = <(Coordenada, Coordenada)>[];

  /// Si no es nulo, `obtenerRuta` espera a que el test lo complete antes de responder.
  Completer<void>? demorarRuta;

  @override
  Future<Ruta?> obtenerRuta(Coordenada origen, Coordenada destino) async {
    consultasRuta.add((origen, destino));
    await demorarRuta?.future;
    if (errorRuta != null) throw errorRuta!;
    return ruta;
  }

  @override
  Future<Viaje> viaje(int id) async {
    consultasDetalle.add(id);
    if (errorDetalle != null) throw errorDetalle!;
    return detalles[id] ?? (throw StateError('Sin detalle preparado para el viaje $id'));
  }

  @override
  Future<Eta> eta(int viajeId) async {
    consultasEta++;
    if (fallarEta != null) throw fallarEta!;
    return etaRespuesta ?? (throw StateError('Sin ETA preparada'));
  }

  @override
  Future<ViajeActual> viajeActual() async {
    consultasActual++;
    if (fallarConsultas != null) throw fallarConsultas!;
    return actual;
  }

  @override
  Future<MisViajes> misViajes() async => mis;

  @override
  Future<List<ChoferEnMapa>> choferes() async {
    consultasChoferes++;
    if (fallarConsultas != null) throw fallarConsultas!;
    if (fallarChoferes != null) throw fallarChoferes!;
    return listaChoferes;
  }

  @override
  Future<Viaje> pedirViaje(PedidoViaje pedido) async {
    pedidos.add(pedido);
    if (errorPedido != null) throw errorPedido!;
    return respuestaPedido ?? Viaje.fromJson(p.json(p.viajeOfrecido));
  }

  @override
  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) async {
    cancelaciones.add((viajeId, motivo));
    return Viaje.fromJson(
      p.json(p.viajeOfrecido)
        ..['id'] = viajeId
        ..['estado'] = 'cancelado',
    );
  }
}

/// Reverb en memoria: se controla el estado de la conexión y se emiten eventos a mano.
class TiempoRealFalso implements TiempoReal {
  TiempoRealFalso({EstadoConexion estado = EstadoConexion.conectado}) : _estado = estado;

  EstadoConexion _estado;
  final _estados = StreamController<EstadoConexion>.broadcast(sync: true);
  final _canales = <String, StreamController<EventoTiempoReal>>{};

  Set<String> get canalesActivos => _canales.keys.toSet();

  @override
  EstadoConexion get estado => _estado;

  @override
  Stream<EstadoConexion> get estados => _estados.stream;

  void cambiar(EstadoConexion e) {
    _estado = e;
    _estados.add(e);
  }

  void emitir(String canal, String evento, Json datos) => _canales[canal]?.add(EventoTiempoReal(canal, evento, datos));

  @override
  Stream<EventoTiempoReal> canal(String nombre) {
    final c = _canales[nombre] ??= StreamController<EventoTiempoReal>.broadcast(
      sync: true,
      onCancel: () => _canales.remove(nombre),
    );
    return c.stream;
  }

  @override
  void conectar() {}

  @override
  void cerrar() {}
}

/// Ubicador sin geolocator: la posición actual es fija y el GPS del turno emite los puntos que el test
/// mande con [emitir] (o un error con [fallar]).
class UbicadorFalso implements Ubicador {
  UbicadorFalso([this.posicion]);

  /// Nula = permiso denegado o GPS apagado.
  Coordenada? posicion;

  /// Lo que responde [pedirPermiso].
  PermisoUbicacion permiso = PermisoUbicacion.concedido;
  int pedidosDePermiso = 0;
  final ajustesAbiertos = <PermisoUbicacion>[];

  /// Intervalo de cada `seguir()`, en orden.
  final intervalos = <Duration>[];
  StreamController<PuntoGps>? _gps;

  /// Hay alguien escuchando el GPS del turno.
  bool get siguiendo => _gps?.hasListener ?? false;

  void emitir(PuntoGps p) => _gps?.add(p);

  void fallar(Object error) => _gps?.addError(error);

  /// Si no es nulo, `actual()` espera a que el test lo complete (el GPS tardando en responder).
  Completer<void>? retener;

  @override
  Future<Coordenada?> actual() async {
    await retener?.future;
    return posicion;
  }

  @override
  Future<PermisoUbicacion> pedirPermiso() async {
    pedidosDePermiso++;
    return permiso;
  }

  @override
  Future<PermisoUbicacion> consultarPermiso() async => permiso;

  @override
  Stream<PuntoGps> seguir(Duration intervalo) {
    intervalos.add(intervalo);
    final gps = _gps = StreamController<PuntoGps>(sync: true);
    gps.onCancel = () {
      if (identical(_gps, gps)) _gps = null;
    };
    return gps.stream;
  }

  @override
  Future<void> abrirAjustes(PermisoUbicacion motivo) async => ajustesAbiertos.add(motivo);
}
