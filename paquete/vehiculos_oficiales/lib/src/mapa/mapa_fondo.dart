import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../chofer/turno.dart' show configuracionProvider;
import '../modelos/configuracion.dart';

/// El mapa de fondo de `MapaOsm`, de `GET /configuracion`. Nulo = sin fondo: mientras la configuración carga o
/// si no llegó (nunca el OSM público en su lugar, que en producción no corresponde).
///
/// Si `GET /configuracion` falló, lo vuelve a pedir cada [reintentoConfiguracion] y al volver a la app,
/// mientras haya un mapa a la vista; cuando responde, el mapa toma el fondo configurado (y la búsqueda de
/// lugares, si puede autocompletar).
final mapaFondoProvider = Provider.autoDispose<MapaFondo?>((ref) {
  final configuracion = ref.watch(configuracionProvider);
  final fondo = configuracion.value?.teselas;
  if (fondo != null) return fondo;
  // Primera carga o un reintento en curso: se espera el resultado.
  if (configuracion.isLoading) return null;

  void reintentar() => ref.invalidate(configuracionProvider);
  final espera = Timer(reintentoConfiguracion, reintentar);
  final ciclo = AppLifecycleListener(onResume: reintentar);
  ref.onDispose(() {
    espera.cancel();
    ciclo.dispose();
  });
  return null;
});

/// Cada cuánto se vuelve a pedir la configuración mientras no llegue.
const reintentoConfiguracion = Duration(seconds: 30);
