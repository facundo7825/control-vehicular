import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../modelos/modelos.dart';

/// Resultado de pedir el permiso de ubicación para el turno (spec 9).
enum PermisoUbicacion { concedido, denegado, denegadoParaSiempre, gpsApagado }

/// Ubicación del dispositivo: la posición actual (origen del pedido del solicitante) y el GPS continuo del
/// turno del chofer. Es la única costura con geolocator: los tests usan `UbicadorFalso`.
abstract interface class Ubicador {
  /// Posición actual, pidiendo permiso si hace falta. Nula si se negó el permiso o el GPS está apagado
  /// (spec 9: el solicitante puede marcar el origen a mano).
  Future<Coordenada?> actual();

  /// Pide el permiso "mientras se usa la app" si todavía no se decidió. Nunca lanza: un error de la
  /// plataforma se informa como [PermisoUbicacion.denegado].
  Future<PermisoUbicacion> pedirPermiso();

  /// GPS del turno cada [intervalo], con el servicio en primer plano en Android (spec 7). Los errores
  /// de la plataforma (permiso revocado, GPS apagado) llegan como errores del stream. El turno lo abre
  /// una sola vez (`RastreadorTurno`): reabrirlo con la app en segundo plano puede fallar. Para volver a
  /// abrirlo hay que cancelar antes la suscripción anterior: geolocator_android reutiliza el stream
  /// abierto mientras tenga alguien escuchando.
  Stream<PuntoGps> seguir(Duration intervalo);

  /// Ajustes del sistema: los de ubicación si el GPS está apagado, los de la app si no. Nunca lanza.
  Future<void> abrirAjustes(PermisoUbicacion motivo);
}

/// Ajustes del GPS del turno (verificados contra geolocator 14.1.1).
LocationSettings ajustesGpsTurno(Duration intervalo) {
  if (defaultTargetPlatform == TargetPlatform.android) {
    return AndroidSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 0,
      intervalDuration: intervalo,
      foregroundNotificationConfig: const ForegroundNotificationConfig(
        notificationTitle: 'Turno activo – compartiendo ubicación',
        notificationText: 'Vehículos oficiales',
        notificationChannelName: 'Ubicación del turno',
        setOngoing: true,
        enableWakeLock: true,
      ),
    );
  }
  if (defaultTargetPlatform == TargetPlatform.iOS) {
    return AppleSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 0,
      activityType: ActivityType.automotiveNavigation,
      pauseLocationUpdatesAutomatically: false,
      allowBackgroundLocationUpdates: true,
      showBackgroundLocationIndicator: true,
    );
  }
  return const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 0);
}

class UbicadorGeolocator implements Ubicador {
  @override
  Future<Coordenada?> actual() async {
    if (await pedirPermiso() != PermisoUbicacion.concedido) return null;
    try {
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)),
      );
      return Coordenada(p.latitude, p.longitude);
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo obtener la ubicación (${e.runtimeType}).');
      return null;
    }
  }

  @override
  Future<PermisoUbicacion> pedirPermiso() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return PermisoUbicacion.gpsApagado;
      var permiso = await Geolocator.checkPermission();
      if (permiso == LocationPermission.denied) permiso = await Geolocator.requestPermission();
      return switch (permiso) {
        LocationPermission.whileInUse || LocationPermission.always => PermisoUbicacion.concedido,
        LocationPermission.deniedForever => PermisoUbicacion.denegadoParaSiempre,
        LocationPermission.denied || LocationPermission.unableToDetermine => PermisoUbicacion.denegado,
      };
    } catch (e) {
      // P. ej. PermissionDefinitionsNotFoundException (falta el permiso en el manifiesto de la app) o un
      // pedido de permiso que ya estaba en curso.
      debugPrint('vehiculos_oficiales: no se pudo pedir el permiso de ubicación (${e.runtimeType}).');
      return PermisoUbicacion.denegado;
    }
  }

  @override
  Stream<PuntoGps> seguir(Duration intervalo) =>
      Geolocator.getPositionStream(locationSettings: ajustesGpsTurno(intervalo)).map(
        (p) => PuntoGps(
          posicion: Coordenada(p.latitude, p.longitude),
          rumbo: p.heading,
          velocidad: p.speed,
          registradoEn: p.timestamp.toUtc(),
        ),
      );

  @override
  Future<void> abrirAjustes(PermisoUbicacion motivo) async {
    try {
      if (motivo == PermisoUbicacion.gpsApagado) {
        await Geolocator.openLocationSettings();
      } else {
        await Geolocator.openAppSettings();
      }
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudieron abrir los ajustes (${e.runtimeType}).');
    }
  }
}

final ubicadorProvider = Provider<Ubicador>((ref) => UbicadorGeolocator());
