import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import 'dobles.dart';
import 'entorno_prueba.dart';
import 'montar.dart';

/// `POST /auth/intercambio` de un chofer (id 2).
const intercambioChofer =
    '{"token":"2|x","usuario":{"id":2,"nombre":"Carlos G\\u00f3mez","cargo":"Chofer","rol":"chofer"}}';

/// Entorno HTTP de un chofer: sesión, configuración, [viajeActual] (por defecto sin viaje ni oferta), él
/// mismo libre en el mapa y con o sin turno abierto. Cada test agrega lo suyo.
EntornoPrueba entornoChofer({bool conTurno = true, String viajeActual = p.viajeActualVacio}) {
  final e = EntornoPrueba(tokenPJ: 'sim|200|Carlos Chofer|Chofer');
  e.http
    ..responder('POST', 'auth/intercambio', 200, intercambioChofer)
    ..responder('GET', 'configuracion', 200, p.configuracion)
    ..responder('GET', 'viajes/actual', 200, viajeActual)
    ..responder('GET', 'choferes', 200, p.choferes)
    ..responder('GET', 'turnos/actual', 200, conTurno ? c.turnoActual : c.sinTurno)
    ..responder('POST', 'ubicacion', 204);
  return e;
}

/// Abre el módulo como chofer, con el GPS falso.
Future<void> montarChofer(
  WidgetTester tester,
  EntornoPrueba e, {
  TiempoRealFalso? tiempoReal,
  UbicadorFalso? ubicador,
  List<Override> extra = const [],
}) => montarModulo(
  tester,
  e,
  tiempoReal: tiempoReal,
  extra: [ubicadorProvider.overrideWithValue(ubicador ?? UbicadorFalso()), ...extra],
);

/// Rutas de los pedidos hechos, sin `/api/`, con su método (p. ej. `POST turnos`).
List<String> pedidosHechos(EntornoPrueba e) => [
  for (final r in e.http.pedidos) '${r.metodo} ${r.uri.path.replaceFirst('/api/', '')}',
];
