import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/avisos/reproductor_sonidos.dart';

/// Reproductor de audio falso: `tocar` puede demorar (como `setSource`, que espera al nativo).
class JugadorFalso implements JugadorSonido {
  bool sonando = false;
  bool? enBucle;
  final contextos = <AudioContext>[];

  /// Si no es nulo, `tocar` espera a que el test lo complete antes de sonar.
  Completer<void>? demora;

  @override
  Future<void> detener() async => sonando = false;

  @override
  Future<void> tocar(String archivo, {required bool enBucle, required AudioContext contexto}) async {
    contextos.add(contexto);
    this.enBucle = enBucle;
    await demora?.future;
    sonando = true;
  }

  @override
  Future<void> liberar() async => sonando = false;
}

void main() {
  late List<JugadorFalso> jugadores;
  late ReproductorAudioplayers r;

  setUp(() {
    jugadores = [];
    r = ReproductorAudioplayers(
      crearJugador: () {
        final j = JugadorFalso()..demora = Completer<void>();
        jugadores.add(j);
        return j;
      },
    );
  });

  test('detener el bucle mientras todavía está arrancando: termina callado', () async {
    unawaited(r.repetir(Sonido.oferta));
    await pumpEventQueue();
    final j = jugadores.single;

    await r.detenerBucle(); // llega antes de que el reproductor termine de preparar el sonido
    j.demora!.complete();
    await pumpEventQueue();

    expect(j.sonando, isFalse);
  });

  test('un bucle nuevo después de detener sí suena', () async {
    unawaited(r.repetir(Sonido.oferta));
    await pumpEventQueue();
    await r.detenerBucle();
    final j = jugadores.single..demora!.complete();
    await pumpEventQueue();

    j.demora = null;
    await r.repetir(Sonido.oferta);
    expect(j.sonando, isTrue);
    expect(j.enBucle, isTrue);
  });

  test('el timbre usa el volumen de tono de llamada; los avisos, el de notificaciones', () async {
    unawaited(r.repetir(Sonido.oferta));
    unawaited(r.reproducir(Sonido.llego));
    await pumpEventQueue();

    final bucle = jugadores[0].contextos.single;
    final unaVez = jugadores[1].contextos.single;
    expect(bucle.android.usageType, AndroidUsageType.notificationRingtone);
    expect(unaVez.android.usageType, AndroidUsageType.notification);
    for (final c in [bucle, unaVez]) {
      expect(c.android.contentType, AndroidContentType.sonification);
      expect(c.android.audioFocus, AndroidAudioFocus.gainTransientMayDuck);
      expect(c.iOS.category, AVAudioSessionCategory.playback);
      expect(c.iOS.options, containsAll([AVAudioSessionOptions.mixWithOthers, AVAudioSessionOptions.duckOthers]));
    }
  });
}
