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

/// Un reproductor de audio (costura sobre `AudioPlayer`, para probar el orden de las operaciones).
abstract class JugadorSonido {
  Future<void> detener();

  /// Configura el contexto de audio y el modo, y empieza a sonar [archivo]. Termina cuando ya suena.
  Future<void> tocar(String archivo, {required bool enBucle, required AudioContext contexto});

  Future<void> liberar();
}

class _JugadorAudioplayers implements JugadorSonido {
  final _p = AudioPlayer()..audioCache = AudioCache(prefix: ReproductorAudioplayers.prefijo);

  @override
  Future<void> detener() => _p.stop();

  @override
  Future<void> tocar(String archivo, {required bool enBucle, required AudioContext contexto}) async {
    await _p.setAudioContext(contexto);
    await _p.setReleaseMode(enBucle ? ReleaseMode.loop : ReleaseMode.stop);
    await _p.play(AssetSource(archivo));
  }

  @override
  Future<void> liberar() => _p.dispose();
}

/// [ReproductorSonidos] real. Los reproductores se crean recién al primer sonido.
class ReproductorAudioplayers implements ReproductorSonidos {
  ReproductorAudioplayers({JugadorSonido Function()? crearJugador}) : _crear = crearJugador ?? _JugadorAudioplayers.new;

  /// Los assets de un paquete se publican con este prefijo (Android, iOS y web).
  static const prefijo = 'packages/vehiculos_oficiales/assets/sonidos/';

  /// El timbre de la oferta sigue el volumen del tono de llamada (no el multimedia) y baja la música.
  static final contextoBucle = _contexto(AndroidUsageType.notificationRingtone);

  /// Los avisos de una vez siguen el volumen de las notificaciones.
  static final contextoAviso = _contexto(AndroidUsageType.notification);

  static AudioContext _contexto(AndroidUsageType uso) => AudioContext(
    android: AudioContextAndroid(
      usageType: uso,
      contentType: AndroidContentType.sonification,
      audioFocus: AndroidAudioFocus.gainTransientMayDuck,
    ),
    iOS: AudioContextIOS(
      category: AVAudioSessionCategory.playback,
      options: const {AVAudioSessionOptions.mixWithOthers, AVAudioSessionOptions.duckOthers},
    ),
  );

  final JugadorSonido Function() _crear;
  JugadorSonido? _unaVez;
  JugadorSonido? _bucle;

  /// Cambia con cada arranque o corte del bucle. Un arranque que todavía estaba preparando el sonido
  /// (el nativo tarda) cuando llegó el corte, al terminar se calla: el timbre nunca queda sonando solo.
  int _generacionBucle = 0;

  @override
  Future<void> reproducir(Sonido sonido) => _intentar(() async {
    final p = _unaVez ??= _crear();
    await p.detener();
    await p.tocar(sonido.archivo, enBucle: false, contexto: contextoAviso);
  });

  @override
  Future<void> repetir(Sonido sonido) => _intentar(() async {
    final generacion = ++_generacionBucle;
    final p = _bucle ??= _crear();
    await p.detener();
    if (generacion != _generacionBucle) return;
    try {
      await p.tocar(sonido.archivo, enBucle: true, contexto: contextoBucle);
    } finally {
      if (generacion != _generacionBucle) await p.detener();
    }
  });

  @override
  Future<void> detenerBucle() {
    _generacionBucle++;
    return _intentar(() async => _bucle?.detener());
  }

  @override
  Future<void> vibrar() => _intentar(HapticFeedback.vibrate);

  @override
  Future<void> liberar() async {
    _generacionBucle++;
    final unaVez = _unaVez;
    final bucle = _bucle;
    _unaVez = null;
    _bucle = null;
    await _intentar(() async => unaVez?.liberar());
    await _intentar(() async => bucle?.liberar());
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
