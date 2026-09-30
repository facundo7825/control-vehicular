import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../modelos/modelos.dart';

enum PuntoPedido { origen, destino }

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
  });

  final Coordenada? origen;
  final Coordenada? destino;
  final String direccionOrigen;
  final String direccionDestino;
  final String motivo;

  /// Chofer elegido en el mapa ("Pedir a este chofer"); nulo = el más cercano.
  final ChoferEnMapa? chofer;

  /// Qué punto fija el próximo toque en el mapa.
  final PuntoPedido marcando;

  bool get completo => origen != null && destino != null;

  Lugar? get lugarOrigen => origen == null ? null : Lugar(origen!, direccion: _texto(direccionOrigen));

  Lugar? get lugarDestino => destino == null ? null : Lugar(destino!, direccion: _texto(direccionDestino));

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
  }) => BorradorPedido(
    origen: origen ?? this.origen,
    destino: destino ?? this.destino,
    direccionOrigen: direccionOrigen ?? this.direccionOrigen,
    direccionDestino: direccionDestino ?? this.direccionDestino,
    motivo: motivo ?? this.motivo,
    chofer: chofer == null ? this.chofer : chofer(),
    marcando: marcando ?? this.marcando,
  );
}

final borradorPedidoProvider = NotifierProvider<BorradorPedidoNotifier, BorradorPedido>(BorradorPedidoNotifier.new);

class BorradorPedidoNotifier extends Notifier<BorradorPedido> {
  @override
  BorradorPedido build() => const BorradorPedido();

  /// Toque en el mapa: fija el punto que se está marcando. Después del origen se pasa al destino.
  void marcar(Coordenada c) => fijar(state.marcando, c);

  void fijar(PuntoPedido punto, Coordenada c) => state = punto == PuntoPedido.origen
      ? state.copiar(origen: c, marcando: PuntoPedido.destino)
      : state.copiar(destino: c);

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
  );

  void limpiar() => state = const BorradorPedido();
}
