/// Errores de la API traducidos desde el código HTTP. El backend siempre responde `{message}`
/// (y `errors` en las validaciones de Laravel).
sealed class ErrorApi implements Exception {
  const ErrorApi(this.mensaje);

  final String mensaje;

  @override
  String toString() => '$runtimeType: $mensaje';
}

/// 401: token del PJ inválido o token Sanctum vencido/revocado. Dispara `onSesionInvalida`.
class SesionInvalida extends ErrorApi {
  const SesionInvalida([super.mensaje = 'Tu sesión venció.']);
}

/// 403: sin permiso para la acción, usuario deshabilitado o viaje ajeno.
class AccesoDenegado extends ErrorApi {
  const AccesoDenegado(super.mensaje);
}

/// 404: recurso inexistente (p. ej. un viaje o una oferta que no existe).
class NoEncontrado extends ErrorApi {
  const NoEncontrado([super.mensaje = 'No se encontró lo que buscabas.']);
}

/// 422: validación o regla de negocio (`ReglaNegocio`/`TransicionInvalida` del backend).
class ErrorNegocio extends ErrorApi {
  const ErrorNegocio(super.mensaje, {this.errores = const {}});

  /// `errors` de una validación de Laravel (campo → mensajes). Vacío en reglas de negocio.
  final Map<String, List<String>> errores;
}

/// 503: el endpoint de identidad del PJ no responde (solo en `POST /auth/intercambio`).
class ServicioNoDisponible extends ErrorApi {
  const ServicioNoDisponible([super.mensaje = 'Servicio de identidad no disponible.']);
}

/// Sin respuesta: sin red, DNS, timeout o conexión rechazada.
class SinConexion extends ErrorApi {
  const SinConexion([super.mensaje = 'No hay conexión con el servidor.']);
}

/// Cualquier otra respuesta inesperada (5xx, JSON inválido).
class ErrorServidor extends ErrorApi {
  const ErrorServidor([super.mensaje = 'Ocurrió un error en el servidor.']);
}
