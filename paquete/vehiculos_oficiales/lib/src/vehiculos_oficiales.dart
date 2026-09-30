import 'package:flutter/material.dart';

import 'config.dart';
import 'entorno.dart';
import 'puente_notificaciones.dart';
import 'ui/modulo_app.dart';

/// Punto de entrada del módulo (spec 3.1).
abstract final class VehiculosOficiales {
  /// Abre el módulo encima de la navegación de la app principal. El `Future` termina cuando se cierra.
  ///
  /// - [sesion]: token de sesión de la app del PJ, que el backend valida (spec 3.2).
  /// - [push]: puente FCM de la app principal (spec 12.3).
  /// - [onSesionInvalida]: se llama una sola vez si el backend responde 401 (token del PJ inválido o
  ///   vencido). La app principal decide qué hacer (normalmente, volver a su login).
  static Future<void> abrir(
    BuildContext context, {
    required SesionPJ sesion,
    required PuenteNotificaciones push,
    required VoidCallback onSesionInvalida,
    required VehiculosOficialesConfig config,
  }) {
    final navegador = Navigator.of(context);
    return navegador.push<void>(
      MaterialPageRoute(
        builder: (_) => ModuloVehiculos(
          entorno: EntornoModulo(config: config, sesion: sesion, push: push, onSesionInvalida: onSesionInvalida),
          alCerrar: () => navegador.pop(), // pop (no maybePop): el PopScope del módulo bloquea maybePop
        ),
      ),
    );
  }
}
