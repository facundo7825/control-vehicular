import 'json.dart';

/// `GET /configuracion`: los parámetros de la spec 5.7 que usa la app.
class Configuracion {
  const Configuracion({
    required this.gpsTurnoSeg,
    required this.gpsViajeSeg,
    required this.ofertaSegundos,
    this.lugaresAutocompletar = false,
  });

  factory Configuracion.fromJson(Json j) => Configuracion(
    gpsTurnoSeg: j['gps_turno_seg'] as int,
    gpsViajeSeg: j['gps_viaje_seg'] as int,
    ofertaSegundos: j['oferta_segundos'] as int,
    // Un backend anterior no lo manda: se busca solo al confirmar, que sirve con cualquier buscador.
    lugaresAutocompletar: j['lugares_autocompletar'] as bool? ?? false,
  );

  final int gpsTurnoSeg;
  final int gpsViajeSeg;
  final int ofertaSegundos;

  /// Si la búsqueda de lugares puede consultar mientras se escribe. Con Nominatim no (su política lo
  /// prohíbe): se busca al confirmar (tecla "buscar" o el botón del campo).
  final bool lugaresAutocompletar;
}
