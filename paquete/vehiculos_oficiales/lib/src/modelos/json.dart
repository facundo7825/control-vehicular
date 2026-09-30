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

/// Lo que lanza la lectura de un JSON que no tiene la forma esperada (un valor de enum nuevo, un campo que
/// falta o cambió de tipo). Quien lee datos del backend lo trata como "respuesta inválida", no como un bug.
bool esErrorDeLectura(Object e) => e is FormatException || e is TypeError || e is StateError || e is ArgumentError;
