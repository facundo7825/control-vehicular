import 'json.dart';

/// Respuesta de `GET /viajes/{id}/eta`. `hacia` es `origen` (aceptado, en camino, llegó) o `destino` (en curso).
/// `segundos` es tiempo de manejo y `metros` distancia en línea recta; los dos son nulos si el chofer no tiene
/// ubicación reciente.
class Eta {
  const Eta({required this.hacia, required this.calculadoEn, this.segundos, this.metros, this.ubicacionActualizadaEn});

  factory Eta.fromJson(Json j) => Eta(
    hacia: j['hacia'] as String,
    segundos: (j['segundos'] as num?)?.toInt(),
    metros: (j['metros'] as num?)?.toInt(),
    calculadoEn: leerFecha(j['calculado_en']),
    ubicacionActualizadaEn: leerFechaOpcional(j['ubicacion_actualizada_en']),
  );

  static const origen = 'origen';
  static const destino = 'destino';

  final String hacia;
  final int? segundos;
  final int? metros;
  final DateTime calculadoEn;
  final DateTime? ubicacionActualizadaEn;
}
