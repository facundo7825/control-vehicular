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
    // Se arman una sola vez: la app principal puede reconstruir la ruta (tema, idioma) y el módulo no se
    // tiene que reiniciar.
    final entorno = EntornoModulo(config: config, sesion: sesion, push: push, onSesionInvalida: onSesionInvalida);
    late final MaterialPageRoute<void> ruta;
    void alCerrar() {
      // Cierra la ruta del módulo, no la que esté arriba. pop (no maybePop): el PopScope del módulo
      // bloquea maybePop. Si la app principal abrió algo encima, se la saca sin animación.
      if (ruta.isCurrent) {
        navegador.pop();
      } else if (ruta.isActive) {
        navegador.removeRoute(ruta);
      }
    }

    ruta = MaterialPageRoute(
      builder: (_) => ModuloVehiculos(entorno: entorno, alCerrar: alCerrar),
    );
    return navegador.push<void>(ruta);
  }
}
