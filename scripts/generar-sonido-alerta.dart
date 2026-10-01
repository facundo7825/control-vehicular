// Genera el sonido de las alertas en vivo del panel (WAV PCM de 16 bits, mono, 22050 Hz), con los
// mismos sintetizadores que generar-sonidos.dart: sin licencias de terceros.
//
//   dart scripts/generar-sonido-alerta.dart
//
// Escribe backend/public/sonidos/alerta.wav.
import 'dart:io';

import 'generar-sonidos.dart' show mezclar, silencio, tono, wav;

void main() {
  final dir = Directory.fromUri(Platform.script.resolve('../backend/public/sonidos/'))..createSync(recursive: true);
  final archivo = File('${dir.path}alerta.wav')..writeAsBytesSync(wav(alerta()));
  print('${archivo.path}: ${archivo.lengthSync()} bytes');
}

/// Aviso de dos notas ascendentes y claras (988 Hz y 1319 Hz, Si-Mi): ~0,7 s.
List<double> alerta() {
  final s = silencio(0.7);
  for (final (inicio, hz) in [(0.0, 987.77), (0.22, 1318.51)]) {
    mezclar(s, tono(hz, inicio == 0 ? 0.2 : 0.45, ataque: 0.01, caida: 0.12, armonicos: [1, 0.3, 0.1]), inicio, 0.5);
  }
  return s;
}
