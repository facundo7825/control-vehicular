import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:vehiculos_oficiales/src/avisos/notificaciones_locales.dart';
import 'package:vehiculos_oficiales/src/avisos/reproductor_sonidos.dart';
import 'package:vehiculos_oficiales/src/chofer/almacen_cola.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_acciones.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/mapa/cache_teselas.dart';
import 'package:vehiculos_oficiales/src/sesion/almacen_token.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'adaptador_falso.dart';
import 'avisos_falsos.dart';
import 'dobles_chofer.dart';

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

  /// Si no es nulo, `token()` lo lanza (p. ej. FCM sin APNs en iOS).
  Object? errorToken;

  /// Si no es nulo, reemplaza a `controlador.stream` (p. ej. un stream de una sola escucha ya usado).
  Stream<Map<String, dynamic>>? mensajesPropios;

  @override
  Future<String?> token() async => errorToken != null ? throw errorToken! : tokenPush;

  @override
  Stream<Map<String, dynamic>> get mensajes => mensajesPropios ?? controlador.stream;
}

/// Todo lo que un test necesita para armar el módulo sin red, sin Firebase y sin Google.
class EntornoPrueba {
  EntornoPrueba({this.tokenPJ = 'sim|100|Ana Pérez|Secretaria', String? tokenPush})
    : puente = PuenteFalso(tokenPush: tokenPush);

  final String tokenPJ;
  final PuenteFalso puente;
  final http = AdaptadorFalso();
  AlmacenToken almacen = AlmacenTokenMemoria();
  AlmacenCola almacenCola = AlmacenColaMemoria();
  AlmacenAcciones almacenAcciones = AlmacenAccionesMemoria();
  int sesionesInvalidas = 0;

  /// Nunca los plugins de audio y notificaciones.
  final sonidos = ReproductorFalso();
  final notificaciones = NotificacionesFalsas();

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
    almacenColaProvider.overrideWithValue(almacenCola),
    almacenAccionesProvider.overrideWithValue(almacenAcciones),
    // Sin disco para las teselas (ni path_provider): el mapa de prueba no las usa.
    almacenTeselasProvider.overrideWithValue(null),
    reproductorSonidosProvider.overrideWithValue(sonidos),
    notificacionesLocalesProvider.overrideWithValue(notificaciones),
    ...extra,
  ];

  ProviderContainer contenedor([List<Override> extra = const []]) =>
      ProviderContainer.test(overrides: overrides(extra), retry: (_, _) => null);
}
