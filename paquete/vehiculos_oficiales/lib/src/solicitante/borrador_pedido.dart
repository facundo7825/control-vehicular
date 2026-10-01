import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../mapa/mapa.dart';
import '../modelos/modelos.dart';
import '../ubicacion/ubicador.dart';

enum PuntoPedido { origen, destino }

/// Si ya se sabe dónde está el solicitante. Sin permiso o sin GPS, el origen se marca a mano (spec 9).
enum EstadoUbicacion { buscando, obtenida, noDisponible }

/// Largo máximo de las direcciones (ViajeController y ReservaController).
const largoMaximoDireccion = 255;

/// Pedido que el solicitante está armando en el mapa (spec 7, solicitante 2).
class BorradorPedido {
  const BorradorPedido({
    this.origen,
    this.destino,
    this.direccionOrigen = '',
    this.direccionDestino = '',
    this.motivo = '',
    this.chofer,
    this.marcando = PuntoPedido.origen,
    this.ubicacion = EstadoUbicacion.buscando,
    this.miUbicacion,
    this.origenEsMiUbicacion = false,
    this.enfoque,
  });

  final Coordenada? origen;
  final Coordenada? destino;
  final String direccionOrigen;
  final String direccionDestino;
  final String motivo;

  /// Chofer elegido en el mapa ("Pedir a este chofer"); nulo = el más cercano.
  final ChoferEnMapa? chofer;

  /// Qué punto fija el próximo toque en el mapa (o la próxima sugerencia elegida).
  final PuntoPedido marcando;

  final EstadoUbicacion ubicacion;

  /// La última ubicación conocida del dispositivo; sesga la búsqueda de lugares.
  final Coordenada? miUbicacion;

  /// El origen es [miUbicacion] (no se marcó a mano ni se buscó).
  final bool origenEsMiUbicacion;

  /// A dónde tiene que mirar el mapa.
  final Enfoque? enfoque;

  bool get completo => origen != null && destino != null;

  Lugar? get lugarOrigen => origen == null ? null : Lugar(origen!, direccion: _texto(direccionOrigen));

  Lugar? get lugarDestino => destino == null ? null : Lugar(destino!, direccion: _texto(direccionDestino));

  /// Texto para mostrar el punto ya fijado: la dirección, "Tu ubicación actual" o las coordenadas.
  /// Nulo si todavía no se fijó.
  String? descripcion(PuntoPedido punto) {
    final lugar = punto == PuntoPedido.origen ? lugarOrigen : lugarDestino;
    if (lugar == null) return null;
    if (lugar.direccion == null && punto == PuntoPedido.origen && origenEsMiUbicacion) return 'Tu ubicación actual';
    return lugar.descripcion;
  }

  static String? _texto(String s) => s.trim().isEmpty ? null : s.trim();

  PedidoViaje pedido() => PedidoViaje(
    modo: chofer == null ? ModoViaje.masCercano : ModoViaje.especifico,
    choferId: chofer?.id,
    origen: lugarOrigen!,
    destino: lugarDestino!,
    motivo: _texto(motivo),
  );

  BorradorPedido copiar({
    Coordenada? origen,
    Coordenada? destino,
    String? direccionOrigen,
    String? direccionDestino,
    String? motivo,
    ChoferEnMapa? Function()? chofer,
    PuntoPedido? marcando,
    EstadoUbicacion? ubicacion,
    Coordenada? miUbicacion,
    bool? origenEsMiUbicacion,
    Enfoque? enfoque,
  }) => BorradorPedido(
    origen: origen ?? this.origen,
    destino: destino ?? this.destino,
    direccionOrigen: direccionOrigen ?? this.direccionOrigen,
    direccionDestino: direccionDestino ?? this.direccionDestino,
    motivo: motivo ?? this.motivo,
    chofer: chofer == null ? this.chofer : chofer(),
    marcando: marcando ?? this.marcando,
    ubicacion: ubicacion ?? this.ubicacion,
    miUbicacion: miUbicacion ?? this.miUbicacion,
    origenEsMiUbicacion: origenEsMiUbicacion ?? this.origenEsMiUbicacion,
    enfoque: enfoque ?? this.enfoque,
  );
}

final borradorPedidoProvider = NotifierProvider<BorradorPedidoNotifier, BorradorPedido>(BorradorPedidoNotifier.new);

class BorradorPedidoNotifier extends Notifier<BorradorPedido> {
  @override
  BorradorPedido build() => const BorradorPedido();

  /// Pide la ubicación actual y (si [centrar]) centra el mapa ahí. Si el origen no se eligió a mano (o
  /// [comoOrigen]), pasa a ser el origen y lo que sigue es el destino. Devuelve si se obtuvo.
  Future<bool> ubicar({bool comoOrigen = false, bool centrar = true}) async {
    final aqui = await ref.read(ubicadorProvider).actual();
    if (!ref.mounted) return false;
    if (aqui == null) {
      if (state.miUbicacion == null) state = state.copiar(ubicacion: EstadoUbicacion.noDisponible);
      return false;
    }
    final esOrigen = comoOrigen || state.origen == null || state.origenEsMiUbicacion;
    state = state.copiar(
      ubicacion: EstadoUbicacion.obtenida,
      miUbicacion: aqui,
      enfoque: centrar ? _enfoque([aqui]) : null,
    );
    if (esOrigen) _origenEnMiUbicacion();
    return true;
  }

  /// Toque en el mapa: fija el punto que se está marcando. Después del origen se pasa al destino.
  void marcar(Coordenada c) => fijar(state.marcando, c);

  void fijar(PuntoPedido punto, Coordenada c) => state = punto == PuntoPedido.origen
      ? state.copiar(origen: c, origenEsMiUbicacion: false, marcando: PuntoPedido.destino)
      : state.copiar(destino: c);

  /// Sugerencia de la búsqueda: fija el punto que se está marcando con su dirección y encuadra el pedido.
  void elegirLugar(LugarEncontrado lugar) {
    final punto = state.marcando;
    var direccion = lugar.direccion.trim();
    if (direccion.length > largoMaximoDireccion) direccion = direccion.substring(0, largoMaximoDireccion);
    fijar(punto, lugar.coordenada);
    this.direccion(punto, direccion);
    state = state.copiar(enfoque: _enfoque([?state.origen, ?state.destino]));
  }

  void marcarAhora(PuntoPedido punto) => state = state.copiar(marcando: punto);

  void direccion(PuntoPedido punto, String texto) => state = punto == PuntoPedido.origen
      ? state.copiar(direccionOrigen: texto)
      : state.copiar(direccionDestino: texto);

  void motivo(String texto) => state = state.copiar(motivo: texto);

  void elegirChofer(ChoferEnMapa? chofer) => state = state.copiar(chofer: () => chofer);

  /// "Elegir otro" después de un `sin_chofer`: mismo origen, destino y motivo, sin chofer.
  void desdeViaje(Viaje v) => state = BorradorPedido(
    origen: v.origen.coordenada,
    destino: v.destino.coordenada,
    direccionOrigen: v.origen.direccion ?? '',
    direccionDestino: v.destino.direccion ?? '',
    motivo: v.motivo ?? '',
    marcando: PuntoPedido.destino,
    ubicacion: state.ubicacion,
    miUbicacion: state.miUbicacion,
    enfoque: _enfoque([v.origen.coordenada, v.destino.coordenada]),
  );

  /// Pedido nuevo; si se conoce la ubicación actual, vuelve a ser el origen.
  void limpiar() {
    state = BorradorPedido(ubicacion: state.ubicacion, miUbicacion: state.miUbicacion, enfoque: state.enfoque);
    if (state.miUbicacion != null) _origenEnMiUbicacion();
  }

  void _origenEnMiUbicacion() => state = BorradorPedido(
    origen: state.miUbicacion,
    destino: state.destino,
    direccionDestino: state.direccionDestino,
    motivo: state.motivo,
    chofer: state.chofer,
    marcando: PuntoPedido.destino,
    ubicacion: state.ubicacion,
    miUbicacion: state.miUbicacion,
    origenEsMiUbicacion: true,
    enfoque: state.enfoque,
  );

  /// Un enfoque nuevo (otra versión), aunque los puntos sean los mismos que el anterior.
  Enfoque _enfoque(List<Coordenada> puntos) => Enfoque.entre(puntos, version: (state.enfoque?.version ?? 0) + 1);
}
