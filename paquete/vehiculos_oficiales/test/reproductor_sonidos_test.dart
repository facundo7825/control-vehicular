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

    // Llega antes de que el reproductor termine de preparar el sonido (corre cuando el arranque termina).
    final corte = r.detenerBucle();
    j.demora!.complete();
    await corte;

    expect(j.sonando, isFalse);
  });

  test('un bucle nuevo después de detener sí suena', () async {
    unawaited(r.repetir(Sonido.oferta));
    await pumpEventQueue();
    final corte = r.detenerBucle();
    final j = jugadores.single..demora!.complete();
    await corte;

    j.demora = null;
    await r.repetir(Sonido.oferta);
    expect(j.sonando, isTrue);
    expect(j.enBucle, isTrue);
  });

  test('un arranque viejo que termina tarde no calla al bucle nuevo', () async {
    unawaited(r.repetir(Sonido.oferta)); // A: tarda en preparar el sonido
    await pumpEventQueue();
    final j = jugadores.single;
    final demoraA = j.demora!;
    j.demora = null;

    unawaited(r.detenerBucle());
    unawaited(r.repetir(Sonido.oferta)); // B
    await pumpEventQueue();
    demoraA.complete(); // recién ahora termina A
    await pumpEventQueue();

    expect(j.sonando, isTrue);
    expect(j.enBucle, isTrue);
  });

  test('el timbre usa el volumen de tono de llamada; los avisos, el de notificaciones', () async {
    unawaited(r.repetir(Sonido.oferta));
    unawaited(r.reproducir(Sonido.llego));
    await pumpEventQueue();

    final bucle = jugadores.singleWhere((j) => j.enBucle == true).contextos.single;
    final unaVez = jugadores.singleWhere((j) => j.enBucle == false).contextos.single;
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
