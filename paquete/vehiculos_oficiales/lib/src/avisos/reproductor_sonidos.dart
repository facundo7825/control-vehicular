import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Sonidos del módulo (WAV generados por `scripts/generar-sonidos.dart`, en `assets/sonidos/`).
enum Sonido {
  /// Timbre de dos tonos que se repite: oferta nueva o viaje asignado.
  oferta,

  /// Acorde ascendente: le aceptaron el viaje al solicitante.
  aceptado,

  /// Dos campanadas: el chofer llegó.
  llego,

  /// Tono descendente: viaje cancelado o sin chofer.
  cancelado;

  String get archivo => '$name.wav';
}

/// Costura sobre `audioplayers` y la vibración. Ningún método lanza: sin audio (web sin interacción,
/// plataforma sin soporte) o sin vibrador no pasa nada.
abstract class ReproductorSonidos {
  /// Suena una vez (corta lo que estuviera sonando una vez).
  Future<void> reproducir(Sonido sonido);

  /// Suena en bucle hasta [detenerBucle].
  Future<void> repetir(Sonido sonido);

  Future<void> detenerBucle();

  Future<void> vibrar();

  Future<void> liberar();
}

final reproductorSonidosProvider = Provider<ReproductorSonidos>((ref) {
  final r = ReproductorAudioplayers();
  ref.onDispose(() => unawaited(r.liberar()));
  return r;
});

/// [ReproductorSonidos] real. Los reproductores se crean recién al primer sonido.
class ReproductorAudioplayers implements ReproductorSonidos {
  /// Los assets de un paquete se publican con este prefijo (Android, iOS y web).
  static const prefijo = 'packages/vehiculos_oficiales/assets/sonidos/';

  AudioPlayer? _unaVez;
  AudioPlayer? _bucle;

  AudioPlayer _crear() => AudioPlayer()..audioCache = AudioCache(prefix: prefijo);

  @override
  Future<void> reproducir(Sonido sonido) => _intentar(() async {
    final p = _unaVez ??= _crear();
    await p.stop();
    await p.setReleaseMode(ReleaseMode.stop);
    await p.play(AssetSource(sonido.archivo));
  });

  @override
  Future<void> repetir(Sonido sonido) => _intentar(() async {
    final p = _bucle ??= _crear();
    await p.stop();
    await p.setReleaseMode(ReleaseMode.loop);
    await p.play(AssetSource(sonido.archivo));
  });

  @override
  Future<void> detenerBucle() => _intentar(() async => _bucle?.stop());

  @override
  Future<void> vibrar() => _intentar(HapticFeedback.vibrate);

  @override
  Future<void> liberar() async {
    final unaVez = _unaVez;
    final bucle = _bucle;
    _unaVez = null;
    _bucle = null;
    await _intentar(() async => unaVez?.dispose());
    await _intentar(() async => bucle?.dispose());
  }

  static Future<void> _intentar(Future<void> Function() accion) async {
    try {
      await accion();
    } catch (e) {
      // P. ej. el navegador rechaza reproducir sin interacción previa, o no hay audio en la plataforma.
      debugPrint('vehiculos_oficiales: no se pudo reproducir el aviso (${e.runtimeType}).');
    }
  }
}
