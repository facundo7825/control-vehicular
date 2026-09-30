import '../modelos/json.dart';

enum EstadoConexion { conectando, conectado, desconectado }

/// Un evento de Reverb: `canal` sin el prefijo `private-`, `nombre` = `broadcastAs()` del backend.
class EventoTiempoReal {
  const EventoTiempoReal(this.canal, this.nombre, this.datos);

  final String canal;
  final String nombre;
  final Json datos;
}

/// Nombres de canal y de evento del backend (routes/channels.php y app/Events).
abstract final class Canales {
  static const mapaChoferes = 'mapa.choferes';
  static String viaje(int id) => 'viaje.$id';
  static String chofer(int id) => 'chofer.$id';
}

abstract final class Eventos {
  static const viajeActualizado = 'viaje.actualizado';
  static const ofertaCreada = 'oferta.creada';
  static const choferUbicacion = 'chofer.ubicacion';
  static const choferEstado = 'chofer.estado';
}

/// Conexión de tiempo real. La implementación real usa el protocolo Pusher contra Reverb; los tests usan
/// un doble que permite simular caídas.
abstract interface class TiempoReal {
  EstadoConexion get estado;

  /// Cambios de estado de la conexión (broadcast).
  Stream<EstadoConexion> get estados;

  /// Eventos de un canal privado (nombre sin `private-`). Suscribe al escuchar y desuscribe al cancelar
  /// la última escucha. Tras una reconexión se vuelve a suscribir solo.
  Stream<EventoTiempoReal> canal(String nombre);

  void conectar();

  void cerrar();
}
