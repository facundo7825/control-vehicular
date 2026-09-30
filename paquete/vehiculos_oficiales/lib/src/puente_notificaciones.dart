/// Puente de notificaciones push que implementa la app principal (spec 12.3).
///
/// La app principal es dueña de Firebase: le pasa al módulo el token FCM del dispositivo
/// y le reenvía los mensajes recibidos (el `data` del mensaje FCM, todo en strings).
abstract interface class PuenteNotificaciones {
  Future<String?> token();

  Stream<Map<String, dynamic>> get mensajes;
}
