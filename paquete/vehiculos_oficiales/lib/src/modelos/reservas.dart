import 'json.dart';

class ChoferDisponible {
  const ChoferDisponible({required this.id, required this.nombre, required this.reservasDelDia});

  factory ChoferDisponible.fromJson(Json j) =>
      ChoferDisponible(id: j['id'] as int, nombre: j['nombre'] as String, reservasDelDia: j['reservas_del_dia'] as int);

  final int id;
  final String nombre;
  final int reservasDelDia;
}

/// `GET /reservas/disponibles`.
class DisponiblesReserva {
  const DisponiblesReserva({required this.duracionEstimadaMin, required this.choferes});

  factory DisponiblesReserva.fromJson(Json j) => DisponiblesReserva(
    duracionEstimadaMin: j['duracion_estimada_min'] as int,
    choferes: leerLista(j['choferes']).map(ChoferDisponible.fromJson).toList(),
  );

  final int duracionEstimadaMin;
  final List<ChoferDisponible> choferes;
}
