import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/push/push_modulo.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

class ApiConPush extends ApiFalsa {
  final tokens = <String>[];

  @override
  Future<void> registrarTokenPush(String token) async => tokens.add(token);
}

Future<void> vaciar() async {
  for (var i = 0; i < 10; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

void main() {
  late ApiConPush api;

  ProviderContainer crear(EntornoPrueba e) => e.contenedor([
    apiProvider.overrideWithValue(api),
    tiempoRealProvider.overrideWithValue(TiempoRealFalso()),
    usuarioProvider.overrideWithValue(solicitante),
  ]);

  setUp(() => api = ApiConPush());

  test('registra el token push del dispositivo', () async {
    final c = crear(EntornoPrueba(tokenPush: 'fcm-abc'));

    c.read(pushModuloProvider);
    await vaciar();

    expect(api.tokens, ['fcm-abc']);
  });

  test('sin token push no registra nada', () async {
    final c = crear(EntornoPrueba());

    c.read(pushModuloProvider);
    await vaciar();

    expect(api.tokens, isEmpty);
  });

  test('un push de viaje refresca el viaje actual y se publica tipado', () async {
    final e = EntornoPrueba();
    final c = crear(e);
    c.listen(viajeActualProvider, (_, _) {});
    final avisos = <AvisoPush>[];
    c.read(pushModuloProvider).avisos.listen(avisos.add);
    await vaciar();
    final antes = api.consultasActual;

    api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
    e.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'viaje', 'viaje_id': '1', 'estado': 'aceptado'});
    await vaciar();

    expect(api.consultasActual, antes + 1);
    expect(c.read(viajeActualProvider).requireValue.viaje!.estado, EstadoViaje.aceptado);
    expect(avisos.single.viajeId, 1);
    expect(avisos.single.estado, 'aceptado');
  });

  test('ignora los mensajes de otros módulos de la app principal', () async {
    final e = EntornoPrueba();
    final c = crear(e);
    final avisos = <AvisoPush>[];
    c.read(pushModuloProvider).avisos.listen(avisos.add);
    await vaciar();

    e.puente.controlador.add({'tipo': 'viaje', 'viaje_id': '1'});
    e.puente.controlador.add({'modulo': 'expedientes', 'tipo': 'viaje'});
    await vaciar();

    expect(avisos, isEmpty);
  });

  test('lee oferta_reserva con ids numéricos', () {
    final a = AvisoPush.desde({
      'modulo': 'vehiculos_oficiales',
      'tipo': 'oferta_reserva',
      'oferta_id': '3',
      'viaje_id': '2',
    })!;

    expect(a.tipo, 'oferta_reserva');
    expect(a.ofertaId, 3);
    expect(a.viajeId, 2);
  });
}
