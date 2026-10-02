import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;

void main() {
  test('lee el usuario del intercambio', () {
    final u = Usuario.fromJson(leerMapa(p.json(p.intercambio)['usuario']));

    expect(u.id, 1);
    expect(u.nombre, 'Ana Pérez');
    expect(u.cargo, 'Secretaria');
    expect(u.rol, Rol.solicitante);
    expect(u.esChofer, isFalse);
  });

  test('lee un viaje ofrecido sin chofer', () {
    final v = Viaje.fromJson(p.json(p.viajeOfrecido));

    expect(v.id, 1);
    expect(v.tipo, TipoViaje.inmediato);
    expect(v.modo, ModoViaje.masCercano);
    expect(v.estado, EstadoViaje.ofrecido);
    expect(v.estado.buscandoChofer, isTrue);
    expect(v.obligatorio, isFalse);
    expect(v.origen.coordenada, const Coordenada(-26.8241, -65.2226));
    expect(v.origen.direccion, 'Plaza Independencia');
    expect(v.destino.descripcion, 'Tribunales');
    expect(v.chofer, isNull);
    expect(v.vehiculo, isNull);
    expect(v.programadoPara, isNull);
    expect(v.solicitante.nombre, 'Ana Pérez');
  });

  test('lee un viaje aceptado con chofer, vehículo y fechas en UTC', () {
    final v = Viaje.fromJson(p.json(p.viajeAceptado));

    expect(v.estado, EstadoViaje.aceptado);
    expect(v.estado.conChofer, isTrue);
    expect(v.chofer!.nombre, 'Carlos Gómez');
    expect(v.chofer!.telefono, '3815550000');
    expect(v.vehiculo!.descripcion, 'Toyota Corolla (AB123CD)');
    expect(v.aceptadoEn, DateTime.utc(2026, 10, 1, 12));
    expect(v.aceptadoEn!.isUtc, isTrue);
  });

  test('lee una reserva con programado_para y dirección de origen nula', () {
    final v = Viaje.fromJson(p.json(p.reservaCreada));

    expect(v.tipo, TipoViaje.reserva);
    expect(v.modo, ModoViaje.cualquieraDisponible);
    expect(v.programadoPara, DateTime.utc(2026, 10, 2, 13));
    expect(v.duracionEstimadaMin, 19);
    expect(v.origen.direccion, isNull);
    expect(v.origen.descripcion, '-26.82410, -65.22260');
  });

  test('lee viajes/actual del solicitante y del chofer', () {
    final sol = ViajeActual.fromJson(p.json(p.viajeActualSolicitante));
    expect(sol.viaje!.id, 1);
    expect(sol.oferta, isNull);

    final cho = ViajeActual.fromJson(p.json(p.viajeActualChofer));
    expect(cho.viaje, isNull);
    expect(cho.oferta!.id, 1);
    expect(cho.oferta!.venceEn, DateTime.utc(2026, 10, 1, 12, 0, 30));
    expect(cho.oferta!.viaje.estado, EstadoViaje.ofrecido);

    final vacio = ViajeActual.fromJson(p.json(p.viajeActualVacio));
    expect(vacio.viaje, isNull);
    expect(vacio.oferta, isNull);
  });

  test('lee la oferta del evento oferta.creada (clave oferta_id)', () {
    final o = Oferta.fromJson(p.json(p.eventoOfertaCreada));

    expect(o.id, 3);
    expect(o.venceEn, DateTime.utc(2026, 10, 1, 12, 30));
    expect(o.viaje.tipo, TipoViaje.reserva);
  });

  test('lee mis viajes', () {
    final m = MisViajes.fromJson(p.json(p.misViajes));

    expect(m.proximas.single.id, 2);
    expect(m.historial.single.estado, EstadoViaje.sinChofer);
    expect(m.historial.single.estado.terminado, isTrue);
  });

  test('lee los choferes del mapa y aplica eventos de ubicación y estado', () {
    final c = ChoferEnMapa.fromJson(leerMapa(p.jsonLista(p.choferes).single));

    expect(c.id, 2);
    expect(c.estado, EstadoChofer.libre);
    expect(c.seleccionable, isTrue);
    expect(c.posicion, const Coordenada(-26.8301, -65.2001));
    expect(c.rumbo, 91.5);
    expect(c.vehiculo.patente, 'AB123CD');

    final u = UbicacionChofer.fromJson(p.json(p.eventoUbicacion));
    expect(u.choferId, 2);
    expect(c.conUbicacion(u).actualizadoEn, DateTime.utc(2026, 10, 1, 12));

    final ocupado = c.conEstado(EstadoChofer.desde(p.json(p.eventoEstadoChofer)['estado'] as String));
    expect(ocupado.estado, EstadoChofer.enViaje);
    expect(ocupado.seleccionable, isFalse);
    expect(c.conEstado(EstadoChofer.reservadoPronto).seleccionable, isFalse);
  });

  test('un chofer sin ubicación todavía no tiene posición', () {
    final c = ChoferEnMapa.fromJson({
      'id': 5,
      'nombre': 'Sin GPS',
      'estado': 'sin_senal',
      'lat': null,
      'lng': null,
      'rumbo': null,
      'actualizado_en': null,
      'vehiculo': {'patente': 'X', 'marca': 'Fiat', 'modelo': 'Cronos', 'color': null},
    });

    expect(c.posicion, isNull);
    expect(c.estado, EstadoChofer.sinSenal);
  });

  test('lee configuración y disponibles de reserva', () {
    final c = Configuracion.fromJson(p.json(p.configuracion));
    expect([c.gpsTurnoSeg, c.gpsViajeSeg, c.ofertaSegundos], [10, 5, 30]);
    expect(c.lugaresAutocompletar, isTrue);
    expect(Configuracion.fromJson(p.json(p.configuracionSinAutocompletar)).lugaresAutocompletar, isFalse);
    // Un backend anterior no manda el dato: se busca solo al confirmar.
    final anterior = p.json(p.configuracion)..remove('lugares_autocompletar');
    expect(Configuracion.fromJson(anterior).lugaresAutocompletar, isFalse);

    final d = DisponiblesReserva.fromJson(p.json(p.reservasDisponibles));
    expect(d.duracionEstimadaMin, 19);
    expect(d.choferes.single.reservasDelDia, 0);
  });

  test('lee el mapa de fondo de la configuración', () {
    final osm = Configuracion.fromJson(p.json(p.configuracion)).teselas!;
    expect(osm.url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
    expect(osm.atribucion, '© OpenStreetMap contributors');
    expect(osm.atribucionUrl, Uri.parse('https://www.openstreetmap.org/copyright'));
    expect(osm.tms, isFalse);
    expect(osm.maxZoom, 19);

    final propias = Configuracion.fromJson(p.json(p.configuracionTeselasPropias)).teselas!;
    expect(propias.url, 'https://mapas.ejemplo.gob.ar/tms/{z}/{x}/{y}.png');
    expect(propias.atribucion, 'IGN');
    expect(propias.atribucionUrl, isNull);
    expect(propias.tms, isTrue);
    expect(propias.maxZoom, 15);

    // Un backend anterior no manda el mapa de fondo: el OSM público de siempre.
    final anterior = p.json(p.configuracion)..remove('teselas');
    final porDefecto = Configuracion.fromJson(anterior).teselas!;
    expect(porDefecto.url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
    expect(porDefecto.atribucion, '© OpenStreetMap contributors');
    expect(porDefecto.atribucionUrl, Uri.parse('https://www.openstreetmap.org/copyright'));
    expect(porDefecto.tms, isFalse);
    expect(porDefecto.maxZoom, 19);
  });

  test('el enlace de créditos del mapa de fondo solo puede ser http(s)', () {
    MapaFondo leer(Object? url) => MapaFondo.fromJson({
      'url': 'https://t/{z}/{x}/{y}.png',
      'atribucion': 'X',
      'atribucion_url': url,
      'tms': false,
      'max_zoom': 19,
    });
    expect(leer('http://mapas.local/creditos').atribucionUrl, Uri.parse('http://mapas.local/creditos'));
    expect(leer('javascript:alert(1)').atribucionUrl, isNull);
    expect(leer('').atribucionUrl, isNull);
    expect(leer(null).atribucionUrl, isNull);
  });

  test('lee la ETA con y sin datos', () {
    final e = Eta.fromJson({
      'hacia': 'origen',
      'segundos': 240,
      'metros': 1850,
      'calculado_en': '2026-10-01T12:00:00+00:00',
      'ubicacion_actualizada_en': '2026-10-01T08:59:55-03:00',
    });
    expect(e.hacia, 'origen');
    expect(e.segundos, 240);
    expect(e.metros, 1850);
    expect(e.calculadoEn, DateTime.utc(2026, 10, 1, 12));
    expect(e.ubicacionActualizadaEn, DateTime.utc(2026, 10, 1, 11, 59, 55));
    expect(e.ubicacionActualizadaEn!.isUtc, isTrue);

    final sin = Eta.fromJson({
      'hacia': 'destino',
      'segundos': null,
      'metros': null,
      'calculado_en': '2026-10-01T12:00:00+00:00',
      'ubicacion_actualizada_en': null,
    });
    expect(sin.hacia, 'destino');
    expect(sin.segundos, isNull);
    expect(sin.metros, isNull);
    expect(sin.ubicacionActualizadaEn, isNull);
  });

  test('acepta coordenadas enteras y escribe fechas en UTC con Z', () {
    expect(Lugar.fromJson({'lat': -26, 'lng': -65, 'direccion': null}).coordenada, const Coordenada(-26, -65));
    expect(escribirFecha(DateTime.utc(2026, 10, 2, 13)), '2026-10-02T13:00:00.000Z');
  });

  test('un estado desconocido es un error de formato', () {
    expect(() => EstadoViaje.desde('volando'), throwsFormatException);
    expect(() => ModoViaje.desde('teletransporte'), throwsFormatException);
  });
}
