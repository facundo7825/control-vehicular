/// Lectura de los JSON del backend (Laravel): los números pueden llegar como int o double
/// y las fechas en ISO-8601 con offset (`2026-10-01T12:00:00+00:00`), que se guardan en UTC.
typedef Json = Map<String, dynamic>;

double leerDouble(Object? v) => (v as num).toDouble();

double? leerDoubleOpcional(Object? v) => v == null ? null : (v as num).toDouble();

DateTime leerFecha(Object? v) => DateTime.parse(v as String).toUtc();

DateTime? leerFechaOpcional(Object? v) => v == null ? null : leerFecha(v);

/// Formato de las fechas que se envían: ISO-8601 en UTC con `Z` (el backend respeta el offset).
String escribirFecha(DateTime d) => d.toUtc().toIso8601String();

Json leerMapa(Object? v) => (v as Map).cast<String, dynamic>();

List<Json> leerLista(Object? v) => (v as List).map(leerMapa).toList();
