import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/sesion/almacen_token.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'adaptador_falso.dart';

const configPrueba = VehiculosOficialesConfig(
  apiBaseUrl: 'http://10.0.2.2:8000',
  reverbHost: '10.0.2.2',
  reverbPort: 8080,
  reverbScheme: 'http',
  reverbKey: 'clave',
);

class PuenteFalso implements PuenteNotificaciones {
  PuenteFalso({this.tokenPush});

  String? tokenPush;
  final controlador = StreamController<Map<String, dynamic>>.broadcast();

  @override
  Future<String?> token() async => tokenPush;

  @override
  Stream<Map<String, dynamic>> get mensajes => controlador.stream;
}

/// Todo lo que un test necesita para armar el módulo sin red, sin Firebase y sin Google.
class EntornoPrueba {
  EntornoPrueba({this.tokenPJ = 'sim|100|Ana Pérez|Secretaria', String? tokenPush})
    : puente = PuenteFalso(tokenPush: tokenPush);

  final String tokenPJ;
  final PuenteFalso puente;
  final http = AdaptadorFalso();
  final almacen = AlmacenTokenMemoria();
  int sesionesInvalidas = 0;

  EntornoModulo get entorno => EntornoModulo(
    config: configPrueba,
    sesion: SesionPJ(tokenPJ),
    push: puente,
    onSesionInvalida: () => sesionesInvalidas++,
  );

  List<Override> overrides([List<Override> extra = const []]) => [
    entornoProvider.overrideWithValue(entorno),
    ...overridesDeModulo(extra),
  ];

  /// Sin el entorno, que lo agrega `ModuloVehiculos`.
  List<Override> overridesDeModulo([List<Override> extra = const []]) => [
    adaptadorHttpProvider.overrideWithValue(http),
    almacenTokenProvider.overrideWithValue(almacen),
    ...extra,
  ];

  ProviderContainer contenedor([List<Override> extra = const []]) =>
      ProviderContainer.test(overrides: overrides(extra), retry: (_, _) => null);
}
