import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/chofer/pasos_viaje.dart';

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
    expect(v.origen.descripcion, 'Ubicación marcada en el mapa'); // nunca coordenadas a la vista
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

  test('lee los datos del detalle: pedido, cancelación y metros recorridos', () {
    final v = Viaje.fromJson(p.json(p.viajeFinalizado));

    expect(v.pedidoEn, DateTime.utc(2026, 10, 1, 11, 58));
    expect(v.metrosRecorridos, 5300);
    expect(v.canceladoPor, isNull);
    expect(v.motivoCancelacion, isNull);

    final cancelado = Viaje.fromJson(
      p.json(p.viajeFinalizado)
        ..['estado'] = 'cancelado'
        ..['cancelado_por'] = 'admin'
        ..['motivo_cancelacion'] = 'Sin vehículos',
    );
    expect(cancelado.canceladoPor, CanceladoPor.admin);
    expect(cancelado.motivoCancelacion, 'Sin vehículos');
    expect(
      Viaje.fromJson(p.json(p.viajeFinalizado)..['cancelado_por'] = 'solicitante').canceladoPor,
      CanceladoPor.solicitante,
    );
    // Un valor nuevo que la app no conoce no rompe la lectura.
    expect(Viaje.fromJson(p.json(p.viajeFinalizado)..['cancelado_por'] = 'sistema').canceladoPor, isNull);
  });

  test('un JSON de un backend viejo sin los campos del detalle se lee igual', () {
    final v = Viaje.fromJson(p.json(p.viajeAceptado));

    expect(v.pedidoEn, isNull);
    expect(v.canceladoPor, isNull);
    expect(v.motivoCancelacion, isNull);
    expect(v.metrosRecorridos, isNull);
  });

  test('lee el recorrido real de un viaje', () {
    final r = RecorridoReal.fromJson(p.json(p.recorrido));

    expect(r.disponible, isTrue);
    expect(r.puntos, const [
      Coordenada(-26.8241, -65.2226),
      Coordenada(-26.8162, -65.2201),
      Coordenada(-26.8083, -65.2176),
    ]);
    final vacio = RecorridoReal.fromJson(p.json(p.recorridoNoDisponible));
    expect(vacio.disponible, isFalse);
    expect(vacio.puntos, isEmpty);
    expect(vacio.vencido, isFalse);
    expect(r.retencionDias, 90);
    final vencido = RecorridoReal.fromJson(p.json(p.recorridoVencido));
    expect(vencido.vencido, isTrue);
    expect(vencido.retencionDias, 30);
  });

  test('un recorrido sin vencido ni retención (payload viejo) es no vencido y sin retención', () {
    final r = RecorridoReal.fromJson({'puntos': <dynamic>[], 'disponible': false});

    expect(r.vencido, isFalse);
    expect(r.retencionDias, isNull);
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

  group('viaje largo', () {
    Map<String, dynamic> largo() => p.json(p.reservaCreada)
      ..['tipo'] = 'largo'
      ..['estado'] = 'aceptado'
      ..['regreso_estimado'] = '2026-10-03T21:30:00+00:00'
      ..['pasajeros'] = 'Dr. Ruiz y dos asesores';

    test('lee tipo, regreso estimado y pasajeros', () {
      final v = Viaje.fromJson(largo());

      expect(v.tipo, TipoViaje.largo);
      expect(v.tipo.etiqueta, 'Viaje largo');
      expect(v.regresoEstimado, DateTime.utc(2026, 10, 3, 21, 30));
      expect(v.regresoEstimado!.isUtc, isTrue);
      expect(v.pasajeros, 'Dr. Ruiz y dos asesores');
    });

    test('un JSON viejo, sin regreso ni pasajeros, queda en nulo', () {
      final v = Viaje.fromJson(p.json(p.reservaCreada));

      expect(v.regresoEstimado, isNull);
      expect(v.pasajeros, isNull);
    });

    test('un tipo que la app no conoce no rompe: se trata como reserva', () {
      final v = Viaje.fromJson(largo()..['tipo'] = 'excursion');

      expect(v.tipo, TipoViaje.reserva);
    });

    test('el chofer no puede cancelar un viaje largo asignado por el encargado', () {
      expect(Viaje.fromJson(largo()).cancelablePorChofer, isFalse);
      expect(Viaje.fromJson(largo()).cancelablePorSolicitante, isFalse);
      expect(Viaje.fromJson(p.json(p.reservaCreada)..['estado'] = 'aceptado').cancelablePorSolicitante, isTrue);
    });
  });
}
