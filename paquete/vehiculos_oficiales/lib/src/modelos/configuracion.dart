import 'json.dart';

/// `GET /configuracion`: los parámetros de la spec 5.7 que usa la app.
class Configuracion {
  const Configuracion({
    required this.gpsTurnoSeg,
    required this.gpsViajeSeg,
    required this.ofertaSegundos,
    this.lugaresAutocompletar = false,
    this.teselas = MapaFondo.osm,
  });

  factory Configuracion.fromJson(Json j) => Configuracion(
    gpsTurnoSeg: j['gps_turno_seg'] as int,
    gpsViajeSeg: j['gps_viaje_seg'] as int,
    ofertaSegundos: j['oferta_segundos'] as int,
    // Un backend anterior no lo manda: se busca solo al confirmar, que sirve con cualquier buscador.
    lugaresAutocompletar: j['lugares_autocompletar'] as bool? ?? false,
    // Un backend anterior no lo manda: el OSM público de siempre.
    teselas: j['teselas'] == null ? MapaFondo.osm : MapaFondo.fromJson(leerMapa(j['teselas'])),
  );

  final int gpsTurnoSeg;
  final int gpsViajeSeg;
  final int ofertaSegundos;

  /// Si la búsqueda de lugares puede consultar mientras se escribe. Con Nominatim no (su política lo
  /// prohíbe): se busca al confirmar (tecla "buscar" o el botón del campo).
  final bool lugaresAutocompletar;

  /// El mapa de fondo de `MapaOsm` (`MAPAS_TESELAS_*` en el backend): se cambia sin recompilar la app.
  /// Nulo = no se sabe (la configuración no llegó): el mapa va sin fondo hasta que llegue, nunca con el OSM
  /// público, que en producción expondría a quién usa la app y no admite ese tráfico.
  final MapaFondo? teselas;
}

/// De dónde salen las teselas del mapa de fondo y qué créditos lleva.
class MapaFondo {
  const MapaFondo({
    required this.url,
    required this.atribucion,
    String? atribucionUrl,
    this.tms = false,
    this.maxZoom = 19,
  }) : _atribucionUrl = atribucionUrl;

  factory MapaFondo.fromJson(Json j) => MapaFondo(
    url: j['url'] as String,
    atribucion: j['atribucion'] as String,
    atribucionUrl: j['atribucion_url'] as String?,
    tms: j['tms'] as bool? ?? false,
    maxZoom: (j['max_zoom'] as num?)?.toInt() ?? 19,
  );

  /// Los servidores públicos de OpenStreetMap: solo para desarrollo y demos (su política no admite tráfico de
  /// producción). Es el valor por defecto del backend y el de un backend que todavía no manda el mapa de fondo.
  static const osm = MapaFondo(
    url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    atribucion: '© OpenStreetMap contributors',
    atribucionUrl: 'https://www.openstreetmap.org/copyright',
  );

  /// Plantilla con `{z}`, `{x}` e `{y}`.
  final String url;

  /// El texto de créditos que se muestra sobre el mapa.
  final String atribucion;

  final String? _atribucionUrl;

  /// La página que abre tocar los créditos; nula (o si no es http(s)), los créditos no se pueden tocar.
  Uri? get atribucionUrl => _enlace(_atribucionUrl);

  /// Si el servidor numera la Y de abajo hacia arriba (TMS), como el IGN.
  final bool tms;

  /// El zoom más alto que tiene el servidor; más cerca se agrandan esas teselas.
  final int maxZoom;

  /// Solo enlaces http(s): el valor viene de la configuración del backend.
  static Uri? _enlace(String? texto) {
    final uri = Uri.tryParse(texto?.trim() ?? '');
    return uri != null && (uri.isScheme('http') || uri.isScheme('https')) && uri.host.isNotEmpty ? uri : null;
  }
}
