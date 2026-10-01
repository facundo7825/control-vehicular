import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

// UbicadorGeolocator se prueba con una FuenteGeolocator falsa (sin la plataforma); las pantallas usan
// UbicadorFalso.
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

  group('UbicadorGeolocator.actual', () {
    final ahora = DateTime.utc(2026, 10, 1, 12);
    late _FuenteFalsa fuente;
    UbicadorGeolocator ubicador() => UbicadorGeolocator(fuente: fuente, ahora: () => ahora);

    setUp(() {
      debugDefaultTargetPlatformOverride = TargetPlatform.android;
      fuente = _FuenteFalsa();
    });

    test('en el teléfono usa la última posición conocida si es reciente (menos de 2 minutos)', () async {
      fuente.ultima = _posicion(1, 2, ahora.subtract(const Duration(seconds: 90)));

      expect(await ubicador().actual(), const Coordenada(1, 2));
      expect(fuente.pedidosActual, isEmpty);
    });

    test('si la última es vieja o no hay, pide la actual con alta precisión y 15 s de límite', () async {
      fuente
        ..ultima = _posicion(1, 2, ahora.subtract(const Duration(minutes: 3)))
        ..actualRespuesta = _posicion(3, 4, ahora);

      expect(await ubicador().actual(), const Coordenada(3, 4));
      expect(fuente.pedidosActual.single.accuracy, LocationAccuracy.high);
      expect(fuente.pedidosActual.single.timeLimit, const Duration(seconds: 15));

      fuente.ultima = null;
      expect(await ubicador().actual(), const Coordenada(3, 4));
    });

    test('si la última falla, pide la actual; si la actual falla, nula sin lanzar', () async {
      fuente
        ..fallarUltima = true
        ..actualRespuesta = _posicion(3, 4, ahora);
      expect(await ubicador().actual(), const Coordenada(3, 4));

      fuente.fallarActual = true;
      expect(await ubicador().actual(), isNull);
    });

    test('sin permiso no pide ninguna posición', () async {
      fuente.permisoActual = LocationPermission.deniedForever;

      expect(await ubicador().actual(), isNull);
      expect(fuente.pedidosUltima, 0);
      expect(fuente.pedidosActual, isEmpty);
    });

    test('fuera del teléfono (web) no usa la última conocida', () async {
      debugDefaultTargetPlatformOverride = TargetPlatform.linux;
      fuente
        ..ultima = _posicion(1, 2, ahora)
        ..actualRespuesta = _posicion(3, 4, ahora);

      expect(await ubicador().actual(), const Coordenada(3, 4));
      expect(fuente.pedidosUltima, 0);
    });
  });

  group('UbicadorGeolocator.consultarPermiso', () {
    late _FuenteFalsa fuente;
    setUp(() => fuente = _FuenteFalsa());

    test('informa el estado sin pedir el permiso', () async {
      fuente.permisoActual = LocationPermission.denied;
      expect(await UbicadorGeolocator(fuente: fuente).consultarPermiso(), PermisoUbicacion.denegado);
      fuente.permisoActual = LocationPermission.deniedForever;
      expect(await UbicadorGeolocator(fuente: fuente).consultarPermiso(), PermisoUbicacion.denegadoParaSiempre);
      fuente.gpsEncendidoRespuesta = false;
      expect(await UbicadorGeolocator(fuente: fuente).consultarPermiso(), PermisoUbicacion.gpsApagado);
      expect(fuente.pedidosPermiso, 0);
    });

    test('ante un error de la plataforma, denegado', () async {
      fuente.fallarPermiso = true;
      expect(await UbicadorGeolocator(fuente: fuente).consultarPermiso(), PermisoUbicacion.denegado);
    });
  });
}

Position _posicion(double lat, double lng, DateTime cuando) => Position(
  latitude: lat,
  longitude: lng,
  timestamp: cuando,
  accuracy: 10,
  altitude: 0,
  altitudeAccuracy: 0,
  heading: 0,
  headingAccuracy: 0,
  speed: 0,
  speedAccuracy: 0,
);

class _FuenteFalsa implements FuenteGeolocator {
  bool gpsEncendidoRespuesta = true;
  LocationPermission permisoActual = LocationPermission.whileInUse;
  bool fallarPermiso = false;
  int pedidosPermiso = 0;

  Position? ultima;
  bool fallarUltima = false;
  int pedidosUltima = 0;

  Position? actualRespuesta;
  bool fallarActual = false;
  final pedidosActual = <LocationSettings>[];

  @override
  Future<bool> gpsEncendido() async => gpsEncendidoRespuesta;

  @override
  Future<LocationPermission> permiso() async {
    if (fallarPermiso) throw Exception('plataforma');
    return permisoActual;
  }

  @override
  Future<LocationPermission> pedirPermiso() async {
    pedidosPermiso++;
    return permisoActual;
  }

  @override
  Future<Position?> ultimaConocida() async {
    pedidosUltima++;
    if (fallarUltima) throw Exception('plataforma');
    return ultima;
  }

  @override
  Future<Position> actual(LocationSettings ajustes) async {
    pedidosActual.add(ajustes);
    if (fallarActual || actualRespuesta == null) throw Exception('timeout');
    return actualRespuesta!;
  }
}
