import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;
import 'fixtures/payloads_chofer.dart' as c;

void main() {
  test('vehículos disponibles: modelo Eloquent con campos extra', () {
    final v = Vehiculo.fromJson(leerMapa(p.jsonLista(c.vehiculosDisponibles).single));

    expect(v.id, 1);
    expect(v.descripcion, 'Toyota Corolla (AB123CD)');
    expect(v.color, 'Blanco');
  });

  test('turno iniciado, actual y finalizado (sin vehículo)', () {
    final iniciado = Turno.fromJson(p.json(c.turnoIniciado));
    expect(iniciado.id, 1);
    expect(iniciado.vehiculo!.patente, 'AB123CD');
    expect(iniciado.inicio, DateTime.utc(2026, 10, 1, 12));
    expect(iniciado.abierto, isTrue);

    final actual = Turno.fromJson(leerMapa(p.json(c.turnoActual)['turno']));
    expect(actual.abierto, isTrue);
    expect(actual.vehiculo!.id, 1);

    final finalizado = Turno.fromJson(p.json(c.turnoFinalizado));
    expect(finalizado.vehiculo, isNull);
    expect(finalizado.fin, DateTime.utc(2026, 10, 1, 12));
    expect(finalizado.abierto, isFalse);
  });

  test('turno: origen y cierre pendiente, opcionales', () {
    final fichaje = Turno.fromJson(leerMapa(p.json(c.turnoPorFichaje)['turno']));
    expect(fichaje.porFichaje, isTrue);
    expect(fichaje.cierrePendienteEn, DateTime.utc(2026, 10, 1, 18));

    final manual = Turno.fromJson(leerMapa(p.json(c.turnoActual)['turno']));
    expect(manual.porFichaje, isFalse);
    expect(manual.cierrePendienteEn, isNull);

    // Un backend anterior no manda ninguno de los dos.
    final viejo = Turno.fromJson(p.json(c.turnoActual)['turno'] as Json..remove('origen'));
    expect(viejo.porFichaje, isFalse);
  });

  test('agenda: reservas y solicitudes con su vencimiento', () {
    final a = Agenda.fromJson(p.json(c.agenda));

    expect(a.reservas, isEmpty);
    final s = a.solicitudes.single;
    expect(s.id, 3);
    expect(s.venceEn, DateTime.utc(2026, 10, 1, 12, 30));
    expect(s.viaje.tipo, TipoViaje.reserva);
    expect(s.viaje.programadoPara, DateTime.utc(2026, 10, 2, 13));

    final conReserva = Agenda.fromJson({
      'reservas': [p.json(c.reservaConfirmada)],
      'solicitudes': <Object>[],
    });
    expect(conReserva.reservas.single.chofer!.id, 2);
  });

  test('punto GPS: fecha en UTC con Z; rumbo y velocidad sin dato van nulos', () {
    final punto = PuntoGps(
      posicion: const Coordenada(-26.83, -65.2),
      rumbo: 90,
      velocidad: 0,
      registradoEn: DateTime.parse('2026-10-01T08:59:50-03:00'),
    );
    expect(punto.toJson(), {
      'lat': -26.83,
      'lng': -65.2,
      'rumbo': 90.0,
      'velocidad': 0.0,
      'registrado_en': '2026-10-01T11:59:50.000Z',
    });

    final sinDatos = PuntoGps(
      posicion: const Coordenada(1, 2),
      rumbo: -1,
      velocidad: -1,
      registradoEn: DateTime.utc(2026),
    ).toJson();
    expect(sinDatos['rumbo'], isNull);
    expect(sinDatos['velocidad'], isNull);
  });
}
