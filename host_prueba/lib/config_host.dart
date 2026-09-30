import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

/// Configuración por `--dart-define`. Los valores por defecto sirven para el emulador de Android contra el
/// backend local (`php artisan serve` en el puerto 8000 y `php artisan reverb:start` en el 8080):
/// `10.0.2.2` es el "localhost" de la PC vista desde el emulador.
const configHost = VehiculosOficialesConfig(
  apiBaseUrl: String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000'),
  reverbHost: String.fromEnvironment('REVERB_HOST', defaultValue: '10.0.2.2'),
  reverbPort: int.fromEnvironment('REVERB_PORT', defaultValue: 8080),
  reverbScheme: String.fromEnvironment('REVERB_SCHEME', defaultValue: 'http'),
  reverbKey: String.fromEnvironment('REVERB_APP_KEY'),
  googleMapsApiKey: String.fromEnvironment('MAPS_API_KEY'),
);
