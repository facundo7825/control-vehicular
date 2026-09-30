import 'comunes.dart';
import 'json.dart';
import 'viaje.dart';

/// Cuerpo de `POST /viajes` (pedido inmediato).
class PedidoViaje {
  const PedidoViaje({required this.modo, this.choferId, required this.origen, required this.destino, this.motivo})
    : assert(modo != ModoViaje.cualquieraDisponible, 'Solo las reservas usan cualquiera_disponible'),
      assert(modo != ModoViaje.especifico || choferId != null, 'Falta chofer_id');

  final ModoViaje modo;
  final int? choferId;
  final Lugar origen;
  final Lugar destino;
  final String? motivo;

  Json toJson() => {
    'modo': modo.valor,
    if (choferId != null) 'chofer_id': choferId,
    ..._lugares(origen, destino),
    if (motivo != null && motivo!.isNotEmpty) 'motivo': motivo,
  };
}

/// Franja de una reserva: query de `GET /reservas/disponibles`.
class FranjaReserva {
  const FranjaReserva({required this.programadoPara, required this.origen, required this.destino});

  final DateTime programadoPara;
  final Coordenada origen;
  final Coordenada destino;

  Json toQuery() => {
    'programado_para': escribirFecha(programadoPara),
    'origen_lat': origen.lat,
    'origen_lng': origen.lng,
    'destino_lat': destino.lat,
    'destino_lng': destino.lng,
  };
}

/// Cuerpo de `POST /reservas`.
class PedidoReserva {
  const PedidoReserva({
    required this.programadoPara,
    required this.modo,
    this.choferId,
    required this.origen,
    required this.destino,
    this.motivo,
  }) : assert(modo != ModoViaje.masCercano, 'Las reservas no usan mas_cercano'),
       assert(modo != ModoViaje.especifico || choferId != null, 'Falta chofer_id');

  final DateTime programadoPara;
  final ModoViaje modo;
  final int? choferId;
  final Lugar origen;
  final Lugar destino;
  final String? motivo;

  Json toJson() => {
    'programado_para': escribirFecha(programadoPara),
    'modo': modo.valor,
    if (choferId != null) 'chofer_id': choferId,
    ..._lugares(origen, destino),
    if (motivo != null && motivo!.isNotEmpty) 'motivo': motivo,
  };
}

Json _lugares(Lugar origen, Lugar destino) => {
  'origen_lat': origen.coordenada.lat,
  'origen_lng': origen.coordenada.lng,
  if (origen.direccion != null && origen.direccion!.isNotEmpty) 'origen_direccion': origen.direccion,
  'destino_lat': destino.coordenada.lat,
  'destino_lng': destino.coordenada.lng,
  if (destino.direccion != null && destino.direccion!.isNotEmpty) 'destino_direccion': destino.direccion,
};
