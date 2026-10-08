import 'dart:convert';
import 'dart:io' show FileSystemException;

import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/almacen_token.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

/// Espera a que la sesión salga de "iniciando".
Future<EstadoSesion> sesionResuelta(ProviderContainer c) async {
  c.read(sesionProvider);
  for (var i = 0; i < 50 && c.read(sesionProvider) is SesionIniciando; i++) {
    await Future<void>.delayed(Duration.zero);
  }
  return c.read(sesionProvider);
}

void main() {
  late EntornoPrueba e;

  setUp(() => e = EntornoPrueba());

  test('intercambia el token del PJ, guarda el Sanctum y queda lista', () async {
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    final c = e.contenedor();

    final s = await sesionResuelta(c);

    expect(s, isA<SesionLista>().having((s) => s.usuario.rol, 'rol', Rol.solicitante));
    expect(c.read(clienteApiProvider).token, startsWith('1|'));
    expect(await e.almacen.leer('sim|100|Ana Pérez|Secretaria'), startsWith('1|'));
    expect(c.read(usuarioProvider).nombre, 'Ana Pérez');
  });

  test('token del PJ inválido: 401, estado vencida y onSesionInvalida una vez', () async {
    e.http.responder('POST', 'auth/intercambio', 401, p.intercambioInvalido);
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionVencida>());
    expect(e.sesionesInvalidas, 1);

    await c.read(sesionProvider.notifier).iniciar(); // un reintento no vuelve a llamar al host
    expect(e.sesionesInvalidas, 1);
    expect(e.http.pedidos, hasLength(1));
  });

  test('un 401 en varios pedidos a la vez avisa una sola vez y borra el token guardado', () async {
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 401, p.noAutenticado);
    e.http.responder('GET', 'choferes', 401, p.noAutenticado);
    e.http.responder('GET', 'viajes', 401, p.noAutenticado);
    final c = e.contenedor();
    await sesionResuelta(c);
    final api = c.read(apiProvider);

    final resultados = await Future.wait([
      api.viajeActual().then<Object?>((v) => v, onError: (Object err) => err),
      api.choferes().then<Object?>((v) => v, onError: (Object err) => err),
      api.misViajes().then<Object?>((v) => v, onError: (Object err) => err),
    ]);

    expect(resultados, everyElement(isA<SesionInvalida>()));
    expect(e.sesionesInvalidas, 1);
    expect(c.read(sesionProvider), isA<SesionVencida>());
    expect(await e.almacen.leer('sim|100|Ana Pérez|Secretaria'), isNull);
  });

  test('un 401 borra la cola de ubicaciones guardada (spec 10)', () async {
    final cola = AlmacenColaMemoria()
      ..usuarioId =
          1 // la de esta misma sesión (Ana)
      ..turnoId = 1
      ..puntos = [punto(0)];
    e.almacenCola = cola;
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 401, p.noAutenticado);
    final c = e.contenedor();
    await sesionResuelta(c);

    await expectLater(c.read(apiProvider).viajeActual(), throwsA(isA<SesionInvalida>()));
    await Future<void>.delayed(Duration.zero);

    expect(cola.guardado, isFalse);
  });

  group('cola de ubicaciones guardada (de otro chofer) al quedar lista (spec 10)', () {
    late AlmacenColaMemoria cola;

    setUp(() {
      cola = AlmacenColaMemoria()
        ..turnoId = 1
        ..puntos = [punto(0)];
      e.almacenCola = cola;
    });

    test('un usuario que no es chofer no la toca (es de otro chofer) y se purga lo viejo', () async {
      e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
      final c = e.contenedor();

      expect(await sesionResuelta(c), isA<SesionLista>());
      await Future<void>.delayed(Duration.zero);

      expect(cola.guardado, isTrue);
      expect(e.purgas, 1);
    });

    test('tampoco con el token guardado (503 del intercambio)', () async {
      await e.almacen.guardar('sim|100|Ana Pérez|Secretaria', '7|guardado');
      e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
      e.http.responder('GET', 'yo', 200, '{"id":1,"nombre":"Ana","cargo":null,"rol":"admin"}');
      final c = e.contenedor();

      expect(await sesionResuelta(c), isA<SesionLista>());
      await Future<void>.delayed(Duration.zero);

      expect(cola.guardado, isTrue);
    });

    test('un chofer la conserva (el turno la retoma)', () async {
      e.http.responder(
        'POST',
        'auth/intercambio',
        200,
        '{"token":"1|abc","usuario":{"id":2,"nombre":"Carlos","cargo":null,"rol":"chofer"}}',
      );
      final c = e.contenedor();

      expect(await sesionResuelta(c), isA<SesionLista>());
      await Future<void>.delayed(Duration.zero);

      expect(cola.guardado, isTrue);
      expect(cola.borrados, 0);
    });

    test('si el borrado falla la sesión queda lista igual', () async {
      cola.error = const FileSystemException('disco');
      e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
      final c = e.contenedor();

      expect(await sesionResuelta(c), isA<SesionLista>());
      await Future<void>.delayed(Duration.zero);
    });
  });

  test('503 sin token guardado: identidad no disponible, y reintentar funciona', () async {
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionIdentidadNoDisponible>());

    await c.read(sesionProvider.notifier).iniciar();
    expect(c.read(sesionProvider), isA<SesionLista>());
    expect(e.sesionesInvalidas, 0);
  });

  test('503 con el token Sanctum guardado de esta sesión: sigue funcionando', () async {
    await e.almacen.guardar('sim|100|Ana Pérez|Secretaria', '7|guardado');
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    e.http.responder('GET', 'yo', 200, '{"id":1,"nombre":"Ana P\\u00e9rez","cargo":"Secretaria","rol":"solicitante"}');
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionLista>());
    expect(c.read(clienteApiProvider).token, '7|guardado');
    expect(e.http.pedidos.last.headers['Authorization'], 'Bearer 7|guardado');
  });

  test('503 con un token guardado vencido no llama a onSesionInvalida', () async {
    await e.almacen.guardar('sim|100|Ana Pérez|Secretaria', '7|vencido');
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    e.http.responder('GET', 'yo', 401, p.noAutenticado);
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionIdentidadNoDisponible>());
    expect(e.sesionesInvalidas, 0);
    expect(c.read(clienteApiProvider).token, isNull);
  });

  test('el token guardado de otra sesión del PJ no se usa', () async {
    await e.almacen.guardar('sim|999|Otro|Juez', '9|de-otro');
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionIdentidadNoDisponible>());
    expect(e.http.pedidos, hasLength(1));
  });

  test('usuario deshabilitado (403) y sin red', () async {
    e.http.responder('POST', 'auth/intercambio', 403, '{"message":"Usuario deshabilitado."}');
    e.http.sinRed('POST', 'auth/intercambio');
    final c = e.contenedor();

    expect(
      await sesionResuelta(c),
      isA<SesionDeshabilitada>().having((s) => s.mensaje, 'mensaje', 'Usuario deshabilitado.'),
    );

    await c.read(sesionProvider.notifier).iniciar();
    expect(c.read(sesionProvider), isA<SesionConError>());
  });

  group('sin señal al abrir', () {
    const tokenPJ = 'sim|100|Ana Pérez|Secretaria';
    const usuario = {'id': 2, 'nombre': 'Carlos Gómez', 'cargo': 'Chofer', 'rol': 'chofer'};
    late TiempoRealFalso tr;

    setUp(() => tr = TiempoRealFalso(estado: EstadoConexion.desconectado));

    ProviderContainer crear() => e.contenedor([tiempoRealProvider.overrideWithValue(tr)]);

    test('con la sesión guardada queda lista con el usuario guardado, sin preguntar /yo', () async {
      await e.almacen.guardar(tokenPJ, '7|guardado', usuario: usuario);
      e.http.sinRed('POST', 'auth/intercambio');
      final c = crear();

      final s = await sesionResuelta(c);
      expect(s, isA<SesionLista>().having((s) => s.usuario.id, 'usuario', 2));
      expect((s as SesionLista).usuario.esChofer, isTrue);
      expect(c.read(clienteApiProvider).token, '7|guardado');
      expect(e.http.pedidos.map((r) => r.uri.path), ['/api/auth/intercambio']);
    });

    test(
      'al reconectar se vuelve a intercambiar: el mismo usuario sigue con la misma sesión y el token nuevo',
      () async {
        await e.almacen.guardar(tokenPJ, '7|guardado', usuario: usuario);
        e.http.sinRed('POST', 'auth/intercambio');
        final c = crear();
        final antes = await sesionResuelta(c);

        e.http
          ..limpiar('POST', 'auth/intercambio')
          ..responder('POST', 'auth/intercambio', 200, '{"token":"8|nuevo","usuario":${jsonEncode(usuario)}}');
        tr.cambiar(EstadoConexion.conectado);
        for (var i = 0; i < 10; i++) {
          await Future<void>.delayed(Duration.zero);
        }

        expect(identical(c.read(sesionProvider), antes), isTrue);
        expect(c.read(clienteApiProvider).token, '8|nuevo');
        expect(await e.almacen.leer(tokenPJ), '8|nuevo');
        expect(await e.almacen.leerUsuario(tokenPJ), usuario);
      },
    );

    test('al reconectar con la sesión vencida (401) se avisa y se borra', () async {
      await e.almacen.guardar(tokenPJ, '7|guardado', usuario: usuario);
      e.http.sinRed('POST', 'auth/intercambio');
      final c = crear();
      await sesionResuelta(c);

      e.http
        ..limpiar('POST', 'auth/intercambio')
        ..responder('POST', 'auth/intercambio', 401, p.noAutenticado);
      tr.cambiar(EstadoConexion.conectado);
      for (var i = 0; i < 10; i++) {
        await Future<void>.delayed(Duration.zero);
      }

      expect(c.read(sesionProvider), isA<SesionVencida>());
      expect(await e.almacen.leer(tokenPJ), isNull);
    });

    Future<void> pasar() async {
      for (var i = 0; i < 10; i++) {
        await Future<void>.delayed(Duration.zero);
      }
    }

    test('un 401 de otro pedido mientras se confirma: la sesión queda vencida y no se vuelve a guardar', () async {
      await e.almacen.guardar(tokenPJ, '7|guardado', usuario: usuario);
      e.http
        ..sinRed('POST', 'auth/intercambio')
        ..responder('GET', 'viajes/actual', 401, p.noAutenticado);
      final c = crear();
      await sesionResuelta(c);

      e.http.limpiar('POST', 'auth/intercambio');
      final intercambio = e.http.demorar('POST', 'auth/intercambio');
      tr.cambiar(EstadoConexion.conectado);
      await pasar();
      await expectLater(c.read(apiProvider).viajeActual(), throwsA(isA<SesionInvalida>()));
      intercambio.complete((200, '{"token":"8|nuevo","usuario":${jsonEncode(usuario)}}'));
      await pasar();
      // Ni el timer ni otra reconexión la reviven.
      tr
        ..cambiar(EstadoConexion.desconectado)
        ..cambiar(EstadoConexion.conectado);
      await pasar();

      expect(c.read(sesionProvider), isA<SesionVencida>());
      expect(await e.almacen.leer(tokenPJ), isNull);
      expect(await e.almacen.leerUsuario(tokenPJ), isNull);
      expect(e.sesionesInvalidas, 1);
      expect(e.http.pedidos.where((r) => r.uri.path == '/api/auth/intercambio'), hasLength(2));
    });

    test('un rechazo que no es de red (422) deja de reintentar la confirmación', () async {
      await e.almacen.guardar(tokenPJ, '7|guardado', usuario: usuario);
      e.http.sinRed('POST', 'auth/intercambio');
      final c = crear();
      await sesionResuelta(c);

      e.http
        ..limpiar('POST', 'auth/intercambio')
        ..responder('POST', 'auth/intercambio', 422, p.validacion);
      tr.cambiar(EstadoConexion.conectado);
      await pasar();
      tr
        ..cambiar(EstadoConexion.desconectado)
        ..cambiar(EstadoConexion.conectado);
      await pasar();

      expect(e.http.pedidos.where((r) => r.uri.path == '/api/auth/intercambio'), hasLength(2));
      expect(c.read(sesionProvider), isA<SesionLista>());
    });

    test('sin usuario guardado (una sesión de antes) es el error de siempre', () async {
      await e.almacen.guardar(tokenPJ, '7|guardado');
      e.http.sinRed('POST', 'auth/intercambio');
      final c = crear();

      expect(await sesionResuelta(c), isA<SesionConError>());
    });

    test('el intercambio guarda el usuario junto al token', () async {
      e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
      final c = crear();
      await sesionResuelta(c);

      expect((await e.almacen.leerUsuario(tokenPJ))!['id'], 1);
    });
  });

  almacenQueFalla();
}

/// Almacén seguro que falla (p. ej. `PlatformException` del keystore).
class AlmacenQueFalla implements AlmacenToken {
  @override
  Future<String?> leer(String tokenPJ) async => throw PlatformException(code: 'leer');

  @override
  Future<Map<String, dynamic>?> leerUsuario(String tokenPJ) async => throw PlatformException(code: 'leer');

  @override
  Future<void> guardar(String tokenPJ, String tokenSanctum, {Map<String, dynamic>? usuario}) async =>
      throw PlatformException(code: 'guardar');

  @override
  Future<void> borrar() async => throw PlatformException(code: 'borrar');
}

/// La API responde algo que no se esperaba (un error que no es `ErrorApi`).
class ApiQueRompe extends ApiFalsa {
  @override
  Future<Intercambio> intercambiar(String tokenExterno) async => throw StateError('inesperado');
}

void almacenQueFalla() {
  group('almacén seguro que falla', () {
    late EntornoPrueba e;

    setUp(() => e = EntornoPrueba()..almacen = AlmacenQueFalla());

    ProviderContainer crear([List<Override> extra = const []]) => e.contenedor(extra);

    test('si no puede guardar el token, la sesión igual queda lista', () async {
      e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
      final c = crear();

      expect(await sesionResuelta(c), isA<SesionLista>());
      expect(c.read(clienteApiProvider).token, startsWith('1|'));
    });

    test('503 y no puede leer el token guardado: identidad no disponible', () async {
      e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
      final c = crear();

      expect(await sesionResuelta(c), isA<SesionIdentidadNoDisponible>());
    });

    test('un 401 con un almacén que no puede borrar no deja un error sin capturar', () async {
      e.http.responder('POST', 'auth/intercambio', 401, p.intercambioInvalido);
      final c = crear();

      expect(await sesionResuelta(c), isA<SesionVencida>());
      await Future<void>.delayed(Duration.zero);
      expect(e.sesionesInvalidas, 1);
    });

    test('un error inesperado queda en error con reintentar', () async {
      final c = crear([apiProvider.overrideWithValue(ApiQueRompe())]);

      expect(await sesionResuelta(c), isA<SesionConError>());
    });

    test('una respuesta mal formada del intercambio queda en error con reintentar', () async {
      e.http.responder('POST', 'auth/intercambio', 200, '{"token":1}');
      final c = crear();

      expect(await sesionResuelta(c), isA<SesionConError>());
    });
  });
}
