import 'json.dart';

/// `GET /configuracion`: los parámetros de la spec 5.7 que usa la app.
class Configuracion {
  const Configuracion({required this.gpsTurnoSeg, required this.gpsViajeSeg, required this.ofertaSegundos});

  factory Configuracion.fromJson(Json j) => Configuracion(
    gpsTurnoSeg: j['gps_turno_seg'] as int,
    gpsViajeSeg: j['gps_viaje_seg'] as int,
    ofertaSegundos: j['oferta_segundos'] as int,
  );

  final int gpsTurnoSeg;
  final int gpsViajeSeg;
  final int ofertaSegundos;
}
