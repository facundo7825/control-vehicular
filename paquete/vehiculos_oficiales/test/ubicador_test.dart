import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

// Solo los ajustes: el resto de Ubicador habla con la plataforma y se prueba a través de UbicadorFalso.
void main() {
  tearDown(() => debugDefaultTargetPlatformOverride = null);

  test('Android: servicio en primer plano con la notificación fija del turno', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;

    final a = ajustesGpsTurno(const Duration(seconds: 5)) as AndroidSettings;

    expect(a.intervalDuration, const Duration(seconds: 5));
    expect(a.accuracy, LocationAccuracy.high);
    expect(a.distanceFilter, 0);
    final n = a.foregroundNotificationConfig!;
    expect(n.notificationTitle, 'Turno activo – compartiendo ubicación');
    expect(n.setOngoing, isTrue);
    expect(n.enableWakeLock, isTrue);
  });

  test('iOS: sigue en segundo plano y no se pausa solo', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;

    final a = ajustesGpsTurno(const Duration(seconds: 10)) as AppleSettings;

    expect(a.allowBackgroundLocationUpdates, isTrue);
    expect(a.pauseLocationUpdatesAutomatically, isFalse);
    expect(a.showBackgroundLocationIndicator, isTrue);
    expect(a.activityType, ActivityType.automotiveNavigation);
  });

  test('otras plataformas (web): ajustes comunes', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.linux;

    final a = ajustesGpsTurno(const Duration(seconds: 10));

    expect(a, isNot(isA<AndroidSettings>()));
    expect(a, isNot(isA<AppleSettings>()));
    expect(a.accuracy, LocationAccuracy.high);
  });
}
