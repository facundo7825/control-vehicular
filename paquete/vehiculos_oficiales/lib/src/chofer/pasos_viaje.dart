import '../modelos/modelos.dart';

/// Reglas del viaje vistas desde el chofer (spec 5.5 y 5.6).
extension ViajeDelChofer on Viaje {
  /// El paso que marca el botón principal, o nulo si ya no le queda ninguno.
  EstadoViaje? get siguientePaso => switch (estado) {
    EstadoViaje.aceptado => EstadoViaje.enCamino,
    EstadoViaje.enCamino => EstadoViaje.llego,
    EstadoViaje.llego => EstadoViaje.enCurso,
    EstadoViaje.enCurso => EstadoViaje.finalizado,
    _ => null,
  };

  /// Mismas reglas que `ServicioViaje::cancelarPorChofer`: nunca un obligatorio ni un viaje largo (lo asignó el
  /// encargado); un inmediato hasta que empieza (`llego`); una reserva solo antes de salir (`aceptado`).
  bool get cancelablePorChofer =>
      !obligatorio &&
      !esLargo &&
      (estado == EstadoViaje.aceptado ||
          (tipo == TipoViaje.inmediato && (estado == EstadoViaje.enCamino || estado == EstadoViaje.llego)));

  /// A dónde navegar: al origen hasta que sube el pasajero, después al destino.
  Lugar get haciaDonde => estado == EstadoViaje.enCurso ? destino : origen;
}

/// Texto del botón principal para cada paso.
String textoPaso(EstadoViaje paso) => switch (paso) {
  EstadoViaje.enCamino => 'Voy en camino',
  EstadoViaje.llego => 'Llegué',
  EstadoViaje.enCurso => 'Iniciar viaje',
  EstadoViaje.finalizado => 'Finalizar',
  _ => paso.texto,
};

/// El estado contado para el chofer (`EstadoViaje.texto` está escrito para el solicitante).
String estadoParaChofer(EstadoViaje e) => switch (e) {
  EstadoViaje.aceptado => 'Viaje aceptado',
  EstadoViaje.enCamino => 'En camino al origen',
  EstadoViaje.llego => 'Esperando al pasajero',
  EstadoViaje.enCurso => 'En viaje al destino',
  _ => e.texto,
};

/// Navegación externa (spec 5.5): Google Maps (la app en Android; si no se puede, la web, que en iOS abre la app si está)
/// y Waze, hacia [c].
List<Uri> urlsGoogleMaps(Coordenada c) => [
  Uri.parse('google.navigation:q=${c.lat},${c.lng}'),
  Uri.parse('https://www.google.com/maps/dir/?api=1&destination=${c.lat},${c.lng}'),
];

Uri urlWaze(Coordenada c) => Uri.parse('https://waze.com/ul?ll=${c.lat},${c.lng}&navigate=yes');
