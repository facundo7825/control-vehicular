import 'package:clock/clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/api/reloj_servidor.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/adaptador_falso.dart';

void main() {
  final ahora = DateTime.utc(2026, 10, 1, 12);
  late AdaptadorFalso http;
  late ApiVehiculos api;
  late RelojServidor reloj;

  setUp(() {
    http = AdaptadorFalso();
    api = ApiVehiculos(
      ClienteApi(baseApi: Uri.parse('http://10.0.2.2:8000/api/'), alRecibir401: () {}, adaptador: http),
    );
    reloj = RelojServidor(api.cliente);
  });

  test('sin encabezado Date el desfase es cero', () async {
    http.responder('GET', 'configuracion', 200, p.configuracion);

    await withClock(Clock.fixed(ahora), () async {
      await api.configuracion();
      expect(api.cliente.desfaseReloj, Duration.zero);
      expect(reloj.ahora(), ahora);
    });
  });

  test('con el servidor 60 s adelantado, ahora() es la hora del servidor', () async {
    http.responder('GET', 'configuracion', 200, p.configuracion);
    http.fechaServidor = ahora.add(const Duration(seconds: 60));

    await withClock(Clock.fixed(ahora), () async {
      await api.configuracion();
      expect(api.cliente.desfaseReloj, const Duration(seconds: 60));
      expect(reloj.ahora(), ahora.add(const Duration(seconds: 60)));
      expect(reloj.restante(ahora.add(const Duration(seconds: 70))), const Duration(seconds: 10));
    });
  });

  test('también toma el Date de una respuesta de error (reloj del teléfono adelantado)', () async {
    http.responder('POST', 'ofertas/1/aceptar', 422, '{"message":"La oferta ya no está vigente."}');
    http.fechaServidor = ahora.subtract(const Duration(seconds: 30));

    await withClock(Clock.fixed(ahora), () async {
      await expectLater(api.aceptarOferta(1), throwsA(isA<ErrorNegocio>()));
      expect(api.cliente.desfaseReloj, const Duration(seconds: -30));
    });
  });

  test('restante nunca es negativo', () {
    withClock(Clock.fixed(ahora), () {
      expect(reloj.restante(ahora.subtract(const Duration(seconds: 5))), Duration.zero);
      expect(reloj.restante(ahora.add(const Duration(milliseconds: 1500))), const Duration(milliseconds: 1500));
    });
  });
}
