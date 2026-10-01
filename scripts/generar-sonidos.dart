// Genera los sonidos del módulo (WAV PCM de 16 bits, mono, 22050 Hz), sintetizados acá: sin
// licencias de terceros.
//
//   dart scripts/generar-sonidos.dart
//
// Escribe en paquete/vehiculos_oficiales/assets/sonidos/.
import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

const frecuencia = 22050;

void main() {
  final dir = Directory.fromUri(Platform.script.resolve('../paquete/vehiculos_oficiales/assets/sonidos/'))
    ..createSync(recursive: true);
  final sonidos = {'oferta': oferta(), 'aceptado': aceptado(), 'llego': llego(), 'cancelado': cancelado()};
  for (final MapEntry(key: nombre, value: muestras) in sonidos.entries) {
    final archivo = File('${dir.path}$nombre.wav')..writeAsBytesSync(wav(muestras));
    print('${archivo.path}: ${archivo.lengthSync()} bytes');
  }
}

/// Timbre de dos tonos (880 Hz y 660 Hz, dos veces) y un silencio corto: ~1,2 s que se repiten bien en
/// bucle (empieza y termina en silencio).
List<double> oferta() {
  final s = silencio(1.2);
  for (final (inicio, hz) in [(0.0, 880.0), (0.28, 660.0), (0.56, 880.0), (0.84, 660.0)]) {
    mezclar(s, tono(hz, 0.24, ataque: 0.01, caida: 0.04, armonicos: [1, 0.35, 0.15]), inicio, 0.45);
  }
  return s;
}

/// Acorde ascendente Do-Mi-Sol (523, 659, 784 Hz), cada nota sostenida: ~0,8 s.
List<double> aceptado() {
  final s = silencio(0.8);
  for (final (inicio, hz) in [(0.0, 523.25), (0.15, 659.25), (0.3, 783.99)]) {
    mezclar(s, campana(hz, 0.8 - inicio, decaimiento: 4, parciales: [1, 0.3, 0.1]), inicio, 0.25);
  }
  return s;
}

/// Dos campanadas (con parciales inarmónicos, como una campana): ~1 s.
List<double> llego() {
  final s = silencio(1.0);
  for (final inicio in [0.0, 0.4]) {
    mezclar(
      s,
      campana(1046.5, 1.0 - inicio, decaimiento: 6, parciales: [1, 0.5, 0.25], razones: [1, 2.76, 5.4]),
      inicio,
      0.5,
    );
  }
  return s;
}

/// Tono que baja de 660 Hz a 330 Hz: ~0,7 s.
List<double> cancelado() {
  const dur = 0.7;
  final n = (dur * frecuencia).round();
  var fase = 0.0;
  return List.generate(n, (i) {
    final t = i / frecuencia;
    final hz = 660 * pow(0.5, t / dur);
    fase += 2 * pi * hz / frecuencia;
    return 0.5 * envolvente(t, dur, ataque: 0.01, caida: 0.15) * (sin(fase) + 0.25 * sin(2 * fase));
  });
}

List<double> silencio(double segundos) => List.filled((segundos * frecuencia).round(), 0.0);

List<double> tono(
  double hz,
  double dur, {
  required double ataque,
  required double caida,
  List<double> armonicos = const [1],
}) {
  final n = (dur * frecuencia).round();
  return List.generate(n, (i) {
    final t = i / frecuencia;
    var v = 0.0;
    for (var k = 0; k < armonicos.length; k++) {
      v += armonicos[k] * sin(2 * pi * hz * (k + 1) * t);
    }
    return v * envolvente(t, dur, ataque: ataque, caida: caida);
  });
}

List<double> campana(
  double hz,
  double dur, {
  required double decaimiento,
  required List<double> parciales,
  List<double> razones = const [1, 2, 3],
}) {
  final n = (dur * frecuencia).round();
  return List.generate(n, (i) {
    final t = i / frecuencia;
    var v = 0.0;
    for (var k = 0; k < parciales.length; k++) {
      v += parciales[k] * exp(-decaimiento * (k + 1) * t) * sin(2 * pi * hz * razones[k] * t);
    }
    return v * envolvente(t, dur, ataque: 0.005, caida: 0.05);
  });
}

/// Subida y bajada lineales para que no haya clics.
double envolvente(double t, double dur, {required double ataque, required double caida}) {
  if (t < ataque) return t / ataque;
  if (t > dur - caida) return max(0, (dur - t) / caida);
  return 1;
}

void mezclar(List<double> destino, List<double> fuente, double inicio, double volumen) {
  final desde = (inicio * frecuencia).round();
  for (var i = 0; i < fuente.length && desde + i < destino.length; i++) {
    destino[desde + i] += fuente[i] * volumen;
  }
}

Uint8List wav(List<double> muestras) {
  final datos = muestras.length * 2;
  final b = ByteData(44 + datos);
  void texto(int pos, String s) {
    for (var i = 0; i < s.length; i++) {
      b.setUint8(pos + i, s.codeUnitAt(i));
    }
  }

  texto(0, 'RIFF');
  b.setUint32(4, 36 + datos, Endian.little);
  texto(8, 'WAVE');
  texto(12, 'fmt ');
  b.setUint32(16, 16, Endian.little); // tamaño del bloque fmt
  b.setUint16(20, 1, Endian.little); // PCM
  b.setUint16(22, 1, Endian.little); // mono
  b.setUint32(24, frecuencia, Endian.little);
  b.setUint32(28, frecuencia * 2, Endian.little); // bytes por segundo
  b.setUint16(32, 2, Endian.little); // bytes por muestra
  b.setUint16(34, 16, Endian.little); // bits
  texto(36, 'data');
  b.setUint32(40, datos, Endian.little);
  for (var i = 0; i < muestras.length; i++) {
    b.setInt16(44 + i * 2, (muestras[i].clamp(-1.0, 1.0) * 32767).round(), Endian.little);
  }
  return b.buffer.asUint8List();
}
