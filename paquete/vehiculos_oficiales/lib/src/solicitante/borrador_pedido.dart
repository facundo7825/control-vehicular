import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../mapa/mapa.dart';
import '../mapa/ruta.dart';
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
    this.marcando = PuntoPedido.destino,
    this.ubicacion = EstadoUbicacion.buscando,
    this.miUbicacion,
    this.origenEsMiUbicacion = false,
    this.enfoque,
    this.permisoUbicacion,
  });

  final Coordenada? origen;
  final Coordenada? destino;
  final String direccionOrigen;
  final String direccionDestino;
  final String motivo;

  /// Chofer elegido en el mapa ("Pedir a este chofer"); nulo = el más cercano.
  final ChoferEnMapa? chofer;

  /// Qué punto fija el próximo toque en el mapa (o la próxima sugerencia elegida). Es el destino mientras
  /// se obtiene la ubicación y cuando el origen es la ubicación; el origen solo si la ubicación no está
  /// disponible y falta el origen, o si la persona eligió cambiarlo.
  final PuntoPedido marcando;

  final EstadoUbicacion ubicacion;

  /// La última ubicación conocida del dispositivo; sesga la búsqueda de lugares.
  final Coordenada? miUbicacion;

  /// El origen es [miUbicacion] (no se marcó a mano ni se buscó).
  final bool origenEsMiUbicacion;

  /// A dónde tiene que mirar el mapa.
  final Enfoque? enfoque;

  /// Cómo estaba el permiso la última vez que se pidió la ubicación (nulo: todavía no se pidió). Si se
  /// negó para siempre, la pantalla ofrece abrir los ajustes.
  final PermisoUbicacion? permisoUbicacion;

  bool get completo => origen != null && destino != null;

  /// El tramo del recorrido a mostrar; nulo mientras falte el origen o el destino.
  TramoRuta? get tramo => completo ? TramoRuta(origen!, destino!) : null;

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
    PermisoUbicacion? permisoUbicacion,
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
    permisoUbicacion: permisoUbicacion ?? this.permisoUbicacion,
  );
}

final borradorPedidoProvider = NotifierProvider<BorradorPedidoNotifier, BorradorPedido>(BorradorPedidoNotifier.new);

class BorradorPedidoNotifier extends Notifier<BorradorPedido> {
  @override
  BorradorPedido build() => const BorradorPedido();

  /// Pide la ubicación actual y (si [centrar]) centra el mapa ahí. Si el origen no se eligió a mano (o
  /// [comoOrigen]), pasa a ser el origen y lo que sigue es el destino. Devuelve si se obtuvo.
  ///
  /// Lo que la persona marque o elija mientras tanto se respeta: el mapa no salta solo a la ubicación y,
  /// si la ubicación no llega, lo que sigue es marcar el origen. Si la ubicación pasa a ser el origen y ya
  /// hay destino, el mapa encuadra los dos.
  Future<bool> ubicar({bool comoOrigen = false, bool centrar = true}) async {
    final antes = state;
    final ubicador = ref.read(ubicadorProvider);
    final aqui = await ubicador.actual();
    if (!ref.mounted) return false;
    if (aqui == null) {
      final permiso = await ubicador.consultarPermiso();
      if (!ref.mounted) return false;
      state = state.copiar(permisoUbicacion: permiso);
      if (state.miUbicacion == null) state = state.copiar(ubicacion: EstadoUbicacion.noDisponible);
      if (state.origen == null) state = state.copiar(marcando: PuntoPedido.origen);
      return false;
    }
    final eligioAlgo = state.origen != antes.origen || state.destino != antes.destino;
    final esOrigen = comoOrigen || state.origen == null || state.origenEsMiUbicacion;
    final destino = state.destino;
    state = state.copiar(
      ubicacion: EstadoUbicacion.obtenida,
      miUbicacion: aqui,
      permisoUbicacion: PermisoUbicacion.concedido,
      enfoque: esOrigen && destino != null
          ? _enfoque([aqui, destino])
          : centrar && !eligioAlgo
          ? _enfoque([aqui])
          : null,
    );
    if (esOrigen) _origenEnMiUbicacion();
    return true;
  }

  /// Toque en el mapa: fija el punto que se está marcando.
  void marcar(Coordenada c) => fijar(state.marcando, c);

  /// Después del origen se pasa al destino; después del destino, al origen si falta y no hay ubicación.
  void fijar(PuntoPedido punto, Coordenada c) => state = punto == PuntoPedido.origen
      ? state.copiar(origen: c, origenEsMiUbicacion: false, marcando: PuntoPedido.destino)
      : state.copiar(
          destino: c,
          marcando: state.origen == null && state.ubicacion == EstadoUbicacion.noDisponible ? PuntoPedido.origen : null,
        );

  /// Sugerencia de la búsqueda: fija [punto] (por defecto, el que se está marcando) con su dirección y
  /// encuadra el pedido.
  void elegirLugar(LugarEncontrado lugar, {PuntoPedido? punto}) {
    final p = punto ?? state.marcando;
    var direccion = lugar.direccion.trim();
    if (direccion.length > largoMaximoDireccion) direccion = direccion.substring(0, largoMaximoDireccion);
    fijar(p, lugar.coordenada);
    this.direccion(p, direccion);
    state = state.copiar(enfoque: _enfoque([?state.origen, ?state.destino]));
  }

  /// Llegó el recorrido de [tramo]: si sigue siendo el del pedido, el mapa encuadra origen, destino y
  /// recorrido. Una sola vez por tramo: después la persona puede mover el mapa sin que vuelva solo.
  void encuadrarRuta(TramoRuta tramo, Ruta ruta) {
    if (tramo != state.tramo || tramo == _tramoEncuadrado) return;
    _tramoEncuadrado = tramo;
    state = state.copiar(enfoque: _enfoque([state.origen!, state.destino!, ...ruta.puntos]));
  }

  /// Se olvida con cada borrador nuevo ([limpiar], [desdeViaje]): pedir otra vez el mismo tramo lo encuadra.
  TramoRuta? _tramoEncuadrado;

  void marcarAhora(PuntoPedido punto) => state = state.copiar(marcando: punto);

  void direccion(PuntoPedido punto, String texto) => state = punto == PuntoPedido.origen
      ? state.copiar(direccionOrigen: texto)
      : state.copiar(direccionDestino: texto);

  void motivo(String texto) => state = state.copiar(motivo: texto);

  void elegirChofer(ChoferEnMapa? chofer) => state = state.copiar(chofer: () => chofer);

  /// "Elegir otro" después de un `sin_chofer`: mismo origen, destino y motivo, sin chofer. Un origen sin
  /// dirección a menos de [cercaDeMiUbicacionMetros] de la ubicación actual se toma como "Tu ubicación
  /// actual" (así se pidió); si no, se muestran sus coordenadas.
  void desdeViaje(Viaje v) {
    final aqui = state.miUbicacion;
    final origen = v.origen.coordenada;
    _tramoEncuadrado = null;
    state = BorradorPedido(
      origen: origen,
      destino: v.destino.coordenada,
      direccionOrigen: v.origen.direccion ?? '',
      direccionDestino: v.destino.direccion ?? '',
      motivo: v.motivo ?? '',
      marcando: PuntoPedido.destino,
      ubicacion: state.ubicacion,
      miUbicacion: aqui,
      permisoUbicacion: state.permisoUbicacion,
      origenEsMiUbicacion:
          v.origen.direccion == null && aqui != null && _metros(origen, aqui) <= cercaDeMiUbicacionMetros,
      enfoque: _enfoque([origen, v.destino.coordenada]),
    );
  }

  /// Ver [desdeViaje].
  static const cercaDeMiUbicacionMetros = 100.0;

  /// Pedido nuevo; si se conoce la ubicación actual, vuelve a ser el origen.
  void limpiar() {
    _tramoEncuadrado = null;
    state = BorradorPedido(
      ubicacion: state.ubicacion,
      miUbicacion: state.miUbicacion,
      enfoque: state.enfoque,
      permisoUbicacion: state.permisoUbicacion,
      marcando: state.ubicacion == EstadoUbicacion.noDisponible ? PuntoPedido.origen : PuntoPedido.destino,
    );
    if (state.miUbicacion != null) _origenEnMiUbicacion();
  }

  /// La dirección de origen escrita se conserva si el origen ya era la ubicación (p. ej. "Puerta 2" y
  /// después "Mi ubicación"); la de un origen marcado a mano no corresponde a la ubicación y se borra.
  void _origenEnMiUbicacion() => state = BorradorPedido(
    origen: state.miUbicacion,
    destino: state.destino,
    direccionOrigen: state.origenEsMiUbicacion ? state.direccionOrigen : '',
    direccionDestino: state.direccionDestino,
    motivo: state.motivo,
    chofer: state.chofer,
    marcando: PuntoPedido.destino,
    ubicacion: state.ubicacion,
    miUbicacion: state.miUbicacion,
    origenEsMiUbicacion: true,
    enfoque: state.enfoque,
    permisoUbicacion: state.permisoUbicacion,
  );

  /// Un enfoque nuevo (otra versión), aunque los puntos sean los mismos que el anterior.
  Enfoque _enfoque(List<Coordenada> puntos) => Enfoque.entre(puntos, version: (state.enfoque?.version ?? 0) + 1);

  /// Distancia aproximada (equirrectangular): alcanza para "a unos pasos".
  static double _metros(Coordenada a, Coordenada b) {
    const metrosPorGrado = 111320.0;
    final dLat = (a.lat - b.lat) * metrosPorGrado;
    final dLng = (a.lng - b.lng) * metrosPorGrado * math.cos(a.lat * math.pi / 180);
    return math.sqrt(dLat * dLat + dLng * dLng);
  }
}
