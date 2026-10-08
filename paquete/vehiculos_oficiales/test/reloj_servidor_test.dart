import 'package:clock/clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http_parser/http_parser.dart';
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
      ClienteApi(
        baseApi: Uri.parse('http://10.0.2.2:8000/api/'),
        alRecibir401: () {},
        adaptador: http,
        cronometro: () => clock.stopwatch(),
      ),
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

  test('un Date repetido no rompe el pedido: se usa el primero', () async {
    http.responder('GET', 'configuracion', 200, p.configuracion);
    final servidor = formatHttpDate(ahora.add(const Duration(seconds: 60)));
    http.encabezadoDate = [servidor, servidor];

    await withClock(Clock.fixed(ahora), () async {
      await api.configuracion();
      expect(api.cliente.desfaseReloj, const Duration(seconds: 60));
    });
  });

  test('un Date que no se puede leer no rompe el pedido y conserva el desfase anterior', () async {
    http.responder('GET', 'configuracion', 200, p.configuracion);
    http.fechaServidor = ahora.add(const Duration(seconds: 60));

    await withClock(Clock.fixed(ahora), () async {
      await api.configuracion();
      http.encabezadoDate = ['ayer a la tarde'];
      await api.configuracion();
      http.encabezadoDate = [];
      await api.configuracion();
      expect(api.cliente.desfaseReloj, const Duration(seconds: 60));
    });
  });

  test('restante nunca es negativo', () {
    withClock(Clock.fixed(ahora), () {
      expect(reloj.restante(ahora.subtract(const Duration(seconds: 5))), Duration.zero);
      expect(reloj.restante(ahora.add(const Duration(milliseconds: 1500))), const Duration(milliseconds: 1500));
    });
  });

  group('hora del servidor sin señal', () {
    late CronometroFalso cronometro;

    setUp(() async {
      cronometro = CronometroFalso();
      api = ApiVehiculos(
        ClienteApi(
          baseApi: Uri.parse('http://10.0.2.2:8000/api/'),
          alRecibir401: () {},
          adaptador: http,
          cronometro: () => cronometro,
        ),
      );
      reloj = RelojServidor(api.cliente);
      http.responder('GET', 'configuracion', 200, p.configuracion);
      http.fechaServidor = ahora;
      await withClock(Clock.fixed(ahora), () async {
        await api.configuracion();
      });
    });

    test('si el reloj del teléfono vuelve atrás, cuenta el cronómetro', () {
      // Sin señal, el teléfono corrige su hora 2 h para atrás; mientras tanto pasaron 5 min.
      cronometro.transcurrido = const Duration(minutes: 5);
      withClock(Clock.fixed(ahora.subtract(const Duration(hours: 2))), () {
        expect(reloj.ahora(), ahora.add(const Duration(minutes: 5)));
      });
    });

    test('con el teléfono dormido (el cronómetro de Android no avanza) cuenta el reloj', () {
      cronometro.transcurrido = const Duration(minutes: 5);
      withClock(Clock.fixed(ahora.add(const Duration(minutes: 40))), () {
        expect(reloj.ahora(), ahora.add(const Duration(minutes: 40)));
      });
    });

    test('con el reloj quieto o atrasado respecto del cronómetro, cuenta el cronómetro', () {
      cronometro.transcurrido = const Duration(minutes: 5);
      withClock(Clock.fixed(ahora.add(const Duration(minutes: 2))), () {
        expect(reloj.ahora(), ahora.add(const Duration(minutes: 5)));
      });
    });
  });
}

/// Un cronómetro cuyo tiempo transcurrido fija el test.
class CronometroFalso implements Stopwatch {
  Duration transcurrido = Duration.zero;
  bool _corriendo = false;

  @override
  Duration get elapsed => transcurrido;

  @override
  int get elapsedMicroseconds => transcurrido.inMicroseconds;

  @override
  int get elapsedMilliseconds => transcurrido.inMilliseconds;

  @override
  int get elapsedTicks => transcurrido.inMicroseconds;

  @override
  int get frequency => 1000000;

  @override
  bool get isRunning => _corriendo;

  @override
  void reset() => transcurrido = Duration.zero;

  @override
  void start() => _corriendo = true;

  @override
  void stop() => _corriendo = false;
}
