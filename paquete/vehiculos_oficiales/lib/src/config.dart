/// Datos de conexión del módulo. Los define la app que lo embebe.
class VehiculosOficialesConfig {
  const VehiculosOficialesConfig({
    required this.apiBaseUrl,
    required this.reverbHost,
    required this.reverbKey,
    this.reverbPort = 443,
    this.reverbScheme = 'https',
    this.googleMapsApiKey = '',
    this.centroMapaLat = -34.6037,
    this.centroMapaLng = -58.3816,
  });

  /// Dónde se centra el mapa al abrir, si todavía no hay choferes ni ubicación propia.
  final double centroMapaLat;
  final double centroMapaLng;

  /// URL del backend sin `/api`, por ejemplo `https://vehiculos.pj.gob.ar`.
  final String apiBaseUrl;

  final String reverbHost;
  final int reverbPort;

  /// `http` o `https` (se traduce a `ws` o `wss`).
  final String reverbScheme;
  final String reverbKey;

  /// Solo informativa: en Android e iOS la clave se declara en el manifiesto / AppDelegate.
  final String googleMapsApiKey;

  Uri get apiUri => Uri.parse('${_sinBarraFinal(apiBaseUrl)}/api/');

  Uri get autorizacionCanalesUri => apiUri.resolve('broadcasting/auth');

  String get reverbWsScheme => reverbScheme == 'https' ? 'wss' : 'ws';

  static String _sinBarraFinal(String url) => url.endsWith('/') ? url.substring(0, url.length - 1) : url;
}

/// Sesión de la app del Poder Judicial: el único dato que el módulo toma de ella.
class SesionPJ {
  const SesionPJ(this.token);

  final String token;
}
