import 'dart:async';
import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import '../mapa/mapa.dart';
import '../mapa/ruta.dart';
import '../modelos/modelos.dart';
import '../ubicacion/ubicador.dart';

enum PuntoPedido { origen, destino }

/// Si ya se sabe dónde está el solicitante. Sin permiso o sin GPS, el origen se marca a mano (spec 9).
enum EstadoUbicacion { buscando, obtenida, noDisponible }

/// Largo máximo de las direcciones (ViajeController y ReservaController).
const largoMaximoDireccion = 255;

/// Lo que se muestra mientras se pide la dirección de un punto (`GET lugares/inverso`).
const textoBuscandoDireccion = 'Buscando la dirección…';

/// El origen cuando es la ubicación del dispositivo (debajo va su dirección, si se encontró).
const textoMiUbicacion = 'Tu ubicación actual';

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
    this.direccionMiUbicacion,
    this.direccionPendienteOrigen,
    this.direccionPendienteDestino,
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

  /// La dirección encontrada para el origen cuando es la ubicación (se muestra debajo de "Tu ubicación
  /// actual" y se manda como `origen_direccion` si no se escribió otra).
  final String? direccionMiUbicacion;

  /// El punto cuya dirección se está pidiendo, por punto. Solo cuenta si sigue siendo el punto fijado.
  final Coordenada? direccionPendienteOrigen;
  final Coordenada? direccionPendienteDestino;

  /// A dónde tiene que mirar el mapa.
  final Enfoque? enfoque;

  /// Cómo estaba el permiso la última vez que se pidió la ubicación (nulo: todavía no se pidió). Si se
  /// negó para siempre, la pantalla ofrece abrir los ajustes.
  final PermisoUbicacion? permisoUbicacion;

  bool get completo => origen != null && destino != null;

  /// El tramo del recorrido a mostrar; nulo mientras falte el origen o el destino.
  TramoRuta? get tramo => completo ? TramoRuta(origen!, destino!) : null;

  /// Lo escrito gana; si no se escribió nada y el origen es la ubicación, va la dirección encontrada.
  Lugar? get lugarOrigen => origen == null
      ? null
      : Lugar(origen!, direccion: _texto(direccionOrigen) ?? (origenEsMiUbicacion ? direccionMiUbicacion : null));

  Lugar? get lugarDestino => destino == null ? null : Lugar(destino!, direccion: _texto(direccionDestino));

  /// Si se está pidiendo la dirección del punto fijado ahora.
  bool buscandoDireccion(PuntoPedido punto) {
    final (fijado, pendiente) = punto == PuntoPedido.origen
        ? (origen, direccionPendienteOrigen)
        : (destino, direccionPendienteDestino);
    return fijado != null && pendiente == fijado;
  }

  bool get _origenMuestraMiUbicacion => origen != null && origenEsMiUbicacion && _texto(direccionOrigen) == null;

  /// Texto para mostrar el punto ya fijado: la dirección, "Tu ubicación actual", "Buscando la dirección…"
  /// o "Ubicación marcada en el mapa" (nunca las coordenadas). Nulo si todavía no se fijó.
  String? descripcion(PuntoPedido punto) {
    final lugar = punto == PuntoPedido.origen ? lugarOrigen : lugarDestino;
    if (lugar == null) return null;
    if (punto == PuntoPedido.origen && _origenMuestraMiUbicacion) return textoMiUbicacion;
    if (lugar.direccion == null && buscandoDireccion(punto)) return textoBuscandoDireccion;
    return lugar.descripcion;
  }

  /// Lo que va debajo de "Tu ubicación actual": su dirección o "Buscando la dirección…". Nulo si el origen
  /// no se muestra como la ubicación o si no se encontró la dirección.
  String? get detalleOrigen {
    if (!_origenMuestraMiUbicacion) return null;
    return buscandoDireccion(PuntoPedido.origen) ? textoBuscandoDireccion : direccionMiUbicacion;
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
    String? Function()? direccionMiUbicacion,
    Coordenada? Function()? direccionPendienteOrigen,
    Coordenada? Function()? direccionPendienteDestino,
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
    direccionMiUbicacion: direccionMiUbicacion == null ? this.direccionMiUbicacion : direccionMiUbicacion(),
    direccionPendienteOrigen: direccionPendienteOrigen == null
        ? this.direccionPendienteOrigen
        : direccionPendienteOrigen(),
    direccionPendienteDestino: direccionPendienteDestino == null
        ? this.direccionPendienteDestino
        : direccionPendienteDestino(),
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

  /// Toque en el mapa: fija el punto que se está marcando y pide su dirección. La dirección que había
  /// puesto la app (de una sugerencia o de otro toque) ya no corresponde y se borra; la escrita a mano se
  /// respeta (y entonces no hace falta pedirla).
  void marcar(Coordenada c) {
    final punto = state.marcando;
    fijar(punto, c);
    final actual = _direccionDe(punto);
    if (actual.trim().isNotEmpty && actual != _direccionAutomatica[punto]) return;
    direccion(punto, '');
    unawaited(_buscarDireccion(punto, c));
  }

  /// Después del origen se pasa al destino; después del destino, al origen si falta y no hay ubicación.
  /// Una búsqueda de dirección en curso para el punto anterior deja de contar (si el punto nuevo la
  /// necesita, [marcar] pide otra).
  ///
  /// La dirección de origen escrita se conserva al pasar de "mi ubicación" a un origen elegido a mano: lo
  /// escrito siempre gana y solo lo borra la persona (o volver a "mi ubicación" desde un origen a mano).
  void fijar(PuntoPedido punto, Coordenada c) => state = punto == PuntoPedido.origen
      ? state.copiar(
          origen: c,
          origenEsMiUbicacion: false,
          marcando: PuntoPedido.destino,
          direccionPendienteOrigen: () => null,
        )
      : state.copiar(
          destino: c,
          marcando: state.origen == null && state.ubicacion == EstadoUbicacion.noDisponible ? PuntoPedido.origen : null,
          direccionPendienteDestino: () => null,
        );

  /// Sugerencia de la búsqueda: fija [punto] (por defecto, el que se está marcando) con su dirección y
  /// encuadra el pedido.
  void elegirLugar(LugarEncontrado lugar, {PuntoPedido? punto}) {
    final p = punto ?? state.marcando;
    var direccion = lugar.direccion.trim();
    if (direccion.length > largoMaximoDireccion) direccion = direccion.substring(0, largoMaximoDireccion);
    fijar(p, lugar.coordenada);
    this.direccion(p, direccion);
    _direccionAutomatica[p] = direccion;
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
  /// actual" (así se pidió). Un punto sin dirección la pide (nunca se muestran coordenadas).
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
    _direccionAutomatica
      ..clear()
      ..[PuntoPedido.origen] = state.direccionOrigen
      ..[PuntoPedido.destino] = state.direccionDestino;
    if (v.origen.direccion == null) unawaited(_buscarDireccion(PuntoPedido.origen, origen));
    if (v.destino.direccion == null) unawaited(_buscarDireccion(PuntoPedido.destino, v.destino.coordenada));
  }

  /// Ver [desdeViaje].
  static const cercaDeMiUbicacionMetros = 100.0;

  /// Pedido nuevo; si se conoce la ubicación actual, vuelve a ser el origen.
  void limpiar() {
    _tramoEncuadrado = null;
    _direccionAutomatica.clear();
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
  ///
  /// La dirección de la ubicación se pide una vez por punto: si ya se conoce (p. ej. después de pedir un
  /// viaje sin moverse), se usa esa.
  void _origenEnMiUbicacion() {
    final aqui = state.miUbicacion!;
    final conocida = _miUbicacionConocida?.$1 == aqui ? _miUbicacionConocida!.$2 : null;
    state = BorradorPedido(
      origen: aqui,
      destino: state.destino,
      direccionOrigen: state.origenEsMiUbicacion ? state.direccionOrigen : '',
      direccionDestino: state.direccionDestino,
      motivo: state.motivo,
      chofer: state.chofer,
      marcando: PuntoPedido.destino,
      ubicacion: state.ubicacion,
      miUbicacion: aqui,
      origenEsMiUbicacion: true,
      direccionMiUbicacion: conocida,
      direccionPendienteOrigen: state.direccionPendienteOrigen,
      direccionPendienteDestino: state.direccionPendienteDestino,
      enfoque: state.enfoque,
      permisoUbicacion: state.permisoUbicacion,
    );
    if (conocida == null && !state.buscandoDireccion(PuntoPedido.origen)) {
      unawaited(_buscarDireccion(PuntoPedido.origen, aqui));
    }
  }

  /// La última dirección encontrada para la ubicación (punto, dirección).
  (Coordenada, String)? _miUbicacionConocida;

  /// Las direcciones que puso la app (sugerencia, viaje copiado o geocodificación), no la persona: un toque
  /// nuevo en el mapa las reemplaza.
  final _direccionAutomatica = <PuntoPedido, String>{};

  String _direccionDe(PuntoPedido punto) =>
      punto == PuntoPedido.origen ? state.direccionOrigen : state.direccionDestino;

  /// Pide la dirección de [c] para [punto] y la fija si al llegar el punto sigue siendo el mismo (una
  /// respuesta vieja no pisa un punto más nuevo). Para la ubicación queda aparte ([direccionMiUbicacion]);
  /// para un punto marcado, va como su dirección si mientras tanto no se escribió otra. Sin dirección (falla,
  /// sin conexión) se sigue sin ella: el servidor la completa al crear el viaje.
  Future<void> _buscarDireccion(PuntoPedido punto, Coordenada c) async {
    final esOrigen = punto == PuntoPedido.origen;
    final miUbicacion = esOrigen && state.origenEsMiUbicacion;
    bool vigente() =>
        (esOrigen ? state.origen : state.destino) == c && (!esOrigen || state.origenEsMiUbicacion == miUbicacion);

    state = esOrigen
        ? state.copiar(direccionPendienteOrigen: () => c)
        : state.copiar(direccionPendienteDestino: () => c);
    var encontrada = await ref.read(apiProvider).direccionDe(c);
    if (!ref.mounted) return;
    if (encontrada != null && encontrada.length > largoMaximoDireccion) {
      encontrada = encontrada.substring(0, largoMaximoDireccion);
    }
    if (encontrada != null && miUbicacion) _miUbicacionConocida = (c, encontrada);
    // La búsqueda de c terminó aunque se descarte: el aviso no queda trabado (y se puede volver a pedir).
    if (esOrigen && state.direccionPendienteOrigen == c) {
      state = state.copiar(direccionPendienteOrigen: () => null);
    } else if (!esOrigen && state.direccionPendienteDestino == c) {
      state = state.copiar(direccionPendienteDestino: () => null);
    }
    if (!vigente() || encontrada == null) return;
    if (miUbicacion) {
      state = state.copiar(direccionMiUbicacion: () => encontrada);
    } else if (_direccionDe(punto).trim().isEmpty) {
      direccion(punto, encontrada);
      _direccionAutomatica[punto] = encontrada;
    }
  }

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
