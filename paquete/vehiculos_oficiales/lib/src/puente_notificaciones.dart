/// Puente de notificaciones push que implementa la app principal (spec 12.3).
///
/// La app principal es dueña de Firebase: le pasa al módulo el token FCM del dispositivo
/// y le reenvía los mensajes recibidos (el `data` del mensaje FCM, todo en strings).
abstract interface class PuenteNotificaciones {
  /// Token FCM del dispositivo, o nulo si no hay. Si falla, el módulo sigue sin registrarlo.
  Future<String?> token();

  /// Mensajes recibidos. **Tiene que ser un stream broadcast** (`StreamController.broadcast()`): el
  /// módulo lo escucha en cada apertura, y un stream de una sola escucha falla la segunda vez.
  Stream<Map<String, dynamic>> get mensajes;
}
