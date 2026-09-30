import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api/api_vehiculos.dart';
import 'api/cliente_api.dart';
import 'config.dart';
import 'puente_notificaciones.dart';
import 'sesion/almacen_token.dart';
import 'sesion/aviso_sesion.dart';

/// Lo que la app principal le pasa a `VehiculosOficiales.abrir`.
class EntornoModulo {
  const EntornoModulo({required this.config, required this.sesion, required this.push, required this.onSesionInvalida});

  final VehiculosOficialesConfig config;
  final SesionPJ sesion;
  final PuenteNotificaciones push;
  final void Function() onSesionInvalida;
}

/// Se sobreescribe en el `ProviderScope` que crea `VehiculosOficiales.abrir` (y en los tests).
final entornoProvider = Provider<EntornoModulo>((ref) => throw UnimplementedError('Falta el entorno del módulo'));

/// Adaptador HTTP de dio. Nulo = el real; los tests lo reemplazan.
final adaptadorHttpProvider = Provider<HttpClientAdapter?>((ref) => null);

final almacenTokenProvider = Provider<AlmacenToken>((ref) => AlmacenTokenSeguro());

final avisoSesionProvider = Provider<AvisoSesionInvalida>(
  (ref) => AvisoSesionInvalida(ref.watch(entornoProvider).onSesionInvalida),
);

final clienteApiProvider = Provider<ClienteApi>(
  (ref) => ClienteApi(
    baseApi: ref.watch(entornoProvider).config.apiUri,
    alRecibir401: () => ref.read(avisoSesionProvider).avisar(),
    adaptador: ref.watch(adaptadorHttpProvider),
  ),
);

final apiProvider = Provider<ApiVehiculos>((ref) => ApiVehiculos(ref.watch(clienteApiProvider)));
