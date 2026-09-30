import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

void main() {
  test('arma las URLs de la API y de autorización de canales', () {
    const config = VehiculosOficialesConfig(
      apiBaseUrl: 'http://10.0.2.2:8000/',
      reverbHost: '10.0.2.2',
      reverbPort: 8080,
      reverbScheme: 'http',
      reverbKey: 'clave',
    );

    expect(config.apiUri.toString(), 'http://10.0.2.2:8000/api/');
    expect(config.apiUri.resolve('viajes/actual').toString(), 'http://10.0.2.2:8000/api/viajes/actual');
    expect(config.autorizacionCanalesUri.toString(), 'http://10.0.2.2:8000/api/broadcasting/auth');
    expect(config.reverbWsScheme, 'ws');
  });

  test('https usa wss', () {
    const config = VehiculosOficialesConfig(
      apiBaseUrl: 'https://vehiculos.pj.gob.ar',
      reverbHost: 'vehiculos.pj.gob.ar',
      reverbKey: 'clave',
    );

    expect(config.reverbWsScheme, 'wss');
    expect(config.reverbPort, 443);
  });
}
