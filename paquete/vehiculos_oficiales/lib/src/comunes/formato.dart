import 'dart:math' as math;

import 'package:intl/intl.dart';

import '../modelos/comunes.dart';
import '../modelos/ruta.dart';

/// Distancia en línea recta (haversine), en metros.
double distanciaMetros(Coordenada a, Coordenada b) {
  const radio = 6371000.0;
  double rad(double g) => g * math.pi / 180;
  final dLat = rad(b.lat - a.lat);
  final dLng = rad(b.lng - a.lng);
  final h =
      math.pow(math.sin(dLat / 2), 2) + math.cos(rad(a.lat)) * math.cos(rad(b.lat)) * math.pow(math.sin(dLng / 2), 2);
  return 2 * radio * math.asin(math.sqrt(h));
}

String formatearDistancia(double metros) =>
    metros < 1000 ? '${(metros / 10).round() * 10} m' : '${NumberFormat('0.0', 'es').format(metros / 1000)} km';

/// Duración en minutos redondeados hacia arriba (al menos 1), p. ej. "12 min"; desde una hora, "1 h 5 min".
String formatearDuracion(double segundos) {
  final minutos = math.max(1, (segundos / 60).ceil());
  if (minutos < 60) return '$minutos min';
  final resto = minutos % 60;
  return resto == 0 ? '${minutos ~/ 60} h' : '${minutos ~/ 60} h $resto min';
}

/// Cuánto lleva el recorrido, p. ej. "≈ 12 min · 5,3 km".
String resumenRuta(Ruta ruta) => '≈ ${formatearDuracion(ruta.duracionS)} · ${formatearDistancia(ruta.distanciaM)}';
