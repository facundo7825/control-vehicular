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

  /// Cómo está el permiso, sin pedirlo (p. ej. para saber por qué [actual] no dio una posición). Nunca
  /// lanza: un error de la plataforma se informa como [PermisoUbicacion.denegado].
  Future<PermisoUbicacion> consultarPermiso();

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

/// Las llamadas a geolocator de [UbicadorGeolocator] para la posición y el permiso. Los tests la
/// reemplazan para no hablar con la plataforma.
class FuenteGeolocator {
  const FuenteGeolocator();

  Future<bool> gpsEncendido() => Geolocator.isLocationServiceEnabled();

  Future<LocationPermission> permiso() => Geolocator.checkPermission();

  Future<LocationPermission> pedirPermiso() => Geolocator.requestPermission();

  Future<Position?> ultimaConocida() => Geolocator.getLastKnownPosition();

  Future<Position> actual(LocationSettings ajustes) => Geolocator.getCurrentPosition(locationSettings: ajustes);
}

class UbicadorGeolocator implements Ubicador {
  UbicadorGeolocator({this.fuente = const FuenteGeolocator(), DateTime Function()? ahora})
    : _ahora = ahora ?? DateTime.now;

  final FuenteGeolocator fuente;
  final DateTime Function() _ahora;

  /// Hasta qué antigüedad sirve la última posición conocida del teléfono como "posición actual".
  static const ultimaReciente = Duration(minutes: 2);

  /// En el teléfono, la última posición conocida si es reciente (al instante); si no, una posición nueva
  /// de alta precisión (hasta 15 s). En la web no hay última conocida.
  @override
  Future<Coordenada?> actual() async {
    if (await pedirPermiso() != PermisoUbicacion.concedido) return null;
    final ultima = await _ultimaReciente();
    if (ultima != null) return ultima;
    try {
      final p = await fuente.actual(
        const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)),
      );
      return Coordenada(p.latitude, p.longitude);
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo obtener la ubicación (${e.runtimeType}).');
      return null;
    }
  }

  Future<Coordenada?> _ultimaReciente() async {
    final movil =
        !kIsWeb && (defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS);
    if (!movil) return null;
    try {
      final p = await fuente.ultimaConocida();
      if (p == null || _ahora().difference(p.timestamp) >= ultimaReciente) return null;
      return Coordenada(p.latitude, p.longitude);
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo leer la última ubicación (${e.runtimeType}).');
      return null;
    }
  }

  @override
  Future<PermisoUbicacion> pedirPermiso() => _permiso(pedir: true);

  @override
  Future<PermisoUbicacion> consultarPermiso() => _permiso(pedir: false);

  Future<PermisoUbicacion> _permiso({required bool pedir}) async {
    try {
      if (!await fuente.gpsEncendido()) return PermisoUbicacion.gpsApagado;
      var permiso = await fuente.permiso();
      if (pedir && permiso == LocationPermission.denied) permiso = await fuente.pedirPermiso();
      return switch (permiso) {
        LocationPermission.whileInUse || LocationPermission.always => PermisoUbicacion.concedido,
        LocationPermission.deniedForever => PermisoUbicacion.denegadoParaSiempre,
        LocationPermission.denied || LocationPermission.unableToDetermine => PermisoUbicacion.denegado,
      };
    } catch (e) {
      // P. ej. PermissionDefinitionsNotFoundException (falta el permiso en el manifiesto de la app) o un
      // pedido de permiso que ya estaba en curso.
      debugPrint('vehiculos_oficiales: no se pudo consultar el permiso de ubicación (${e.runtimeType}).');
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
