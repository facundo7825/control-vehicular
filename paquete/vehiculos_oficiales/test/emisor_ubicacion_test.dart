import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_ubicaciones.dart';
import 'package:vehiculos_oficiales/src/chofer/emisor_ubicacion.dart';

import 'soporte/dobles_chofer.dart';

void main() {
  late ApiChofer api;
  late ColaUbicaciones cola;
  late EmisorUbicacion emisor;

  setUp(() {
    api = ApiChofer();
    cola = ColaUbicaciones();
    emisor = EmisorUbicacion(api: api, cola: cola);
  });

  test('con la cola vacía no manda nada', () async {
    expect(await emisor.enviar(), ResultadoEnvio.sinCambios);
    expect(api.lotes, isEmpty);
  });

  test('204: manda en orden y saca exactamente lo enviado', () async {
    cola
      ..agregar(punto(10))
      ..agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.enviado);

    expect(segundos(api.lotes.single), [0, 10]);
    expect(cola.largo, 0);
  });

  test('sin red y con 5xx no se pierde nada: el reintento manda lo mismo, en el mismo orden, más lo nuevo', () async {
    api.erroresUbicacion.addAll([const SinConexion(), const ErrorServidor()]);
    cola
      ..agregar(punto(0))
      ..agregar(punto(10));

    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    cola.agregar(punto(20));
    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    cola.agregar(punto(30));
    expect(await emisor.enviar(), ResultadoEnvio.enviado);

    expect(api.lotes.map(segundos), [
      [0, 10],
      [0, 10, 20],
      [0, 10, 20, 30],
    ]);
    expect(cola.largo, 0);
  });

  test('más de 500 puntos salen en lotes de 500; vaciarTodo manda hasta vaciar', () async {
    for (var s = 0; s < 1203; s++) {
      cola.agregar(punto(s));
    }

    expect(await emisor.vaciarTodo(), ResultadoEnvio.enviado);

    expect(api.lotes.map((l) => l.length), [500, 500, 203]);
    expect(segundos(api.lotes[1]).first, 500);
    expect(cola.largo, 0);
  });

  test('vaciarTodo se detiene en el primer error y deja el resto', () async {
    for (var s = 0; s < 700; s++) {
      cola.agregar(punto(s));
    }
    api.erroresUbicacion.addAll([const ErrorServidor()]);

    expect(await emisor.vaciarTodo(), ResultadoEnvio.reintentar);

    expect(api.lotes, hasLength(1));
    expect(cola.largo, 700);
  });

  test('422 "Iniciá un turno…" o 403: sinTurno, sin tocar la cola', () async {
    api.erroresUbicacion.addAll([
      const ErrorNegocio('Iniciá un turno para compartir tu ubicación.'),
      const AccesoDenegado('No tenés permiso para esta acción.'),
    ]);
    cola.agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.sinTurno);
    expect(await emisor.enviar(), ResultadoEnvio.sinTurno);
    expect(cola.largo, 1);
  });

  test('un lote que no pasa la validación se descarta para no trabar la cola', () async {
    api.erroresUbicacion.add(
      const ErrorNegocio(
        'El campo puntos.0.lat debe estar entre -90 y 90.',
        errores: {
          'puntos.0.lat': ['El campo puntos.0.lat debe estar entre -90 y 90.'],
        },
      ),
    );
    cola.agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    expect(cola.largo, 0);
  });

  test('un 401 no escapa: queda para reintentar', () async {
    api.erroresUbicacion.add(const SesionInvalida());
    cola.agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    expect(cola.largo, 1);
  });

  test('dos enviar() a la vez hacen un solo POST', () async {
    api.demoraUbicacion = Completer<void>();
    cola.agregar(punto(0));

    final a = emisor.enviar();
    final b = emisor.enviar();
    api.demoraUbicacion!.complete();

    expect(await a, ResultadoEnvio.enviado);
    expect(await b, ResultadoEnvio.enviado);
    expect(api.lotes, hasLength(1));
  });
}
