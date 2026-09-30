import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../modelos/comunes.dart';

/// Ubicación del dispositivo. El plan del chofer le agrega el seguimiento continuo (GPS del turno).
abstract interface class Ubicador {
  /// Posición actual, pidiendo permiso si hace falta. Nula si se negó el permiso o el GPS está apagado
  /// (spec 9: el solicitante puede marcar el origen a mano).
  Future<Coordenada?> actual();
}

class UbicadorGeolocator implements Ubicador {
  @override
  Future<Coordenada?> actual() async {
    if (!await Geolocator.isLocationServiceEnabled()) return null;
    var permiso = await Geolocator.checkPermission();
    if (permiso == LocationPermission.denied) permiso = await Geolocator.requestPermission();
    if (permiso == LocationPermission.denied || permiso == LocationPermission.deniedForever) return null;
    try {
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)),
      );
      return Coordenada(p.latitude, p.longitude);
    } on Exception {
      return null;
    }
  }
}

final ubicadorProvider = Provider<Ubicador>((ref) => UbicadorGeolocator());
