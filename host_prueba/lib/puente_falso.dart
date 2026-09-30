import 'dart:async';

import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

/// host_prueba no usa Firebase: no hay token push y los "mensajes" se inyectan a mano (útil para probar
/// la reacción del módulo a un push). En la app del PJ esto lo implementa su integración con FCM.
class PuenteNotificacionesFalso implements PuenteNotificaciones {
  // Broadcast, como lo exige `PuenteNotificaciones.mensajes`: el módulo lo escucha en cada apertura.
  final _mensajes = StreamController<Map<String, dynamic>>.broadcast();

  @override
  Future<String?> token() async => null;

  @override
  Stream<Map<String, dynamic>> get mensajes => _mensajes.stream;

  void simular(Map<String, dynamic> data) => _mensajes.add(data);
}
