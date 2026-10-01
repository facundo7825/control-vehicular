import 'comunes.dart';
import 'json.dart';

/// Una maniobra de [Ruta]: `{instruccion, distancia_m, indice, lat, lng, tipo}`. [indice] es la posición del
/// punto de la maniobra dentro de [Ruta.puntos].
class PasoRuta {
  const PasoRuta({
    required this.instruccion,
    required this.distanciaM,
    required this.indice,
    required this.coordenada,
    required this.tipo,
  });

  factory PasoRuta.fromJson(Json j) => PasoRuta(
    instruccion: j['instruccion'] as String,
    distanciaM: leerDouble(j['distancia_m']),
    indice: j['indice'] as int,
    coordenada: Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])),
    tipo: j['tipo'] as String,
  );

  /// En español, p. ej. "Doblá a la derecha por San Martín".
  final String instruccion;
  final double distanciaM;
  final int indice;
  final Coordenada coordenada;
  final String tipo;
}

/// Respuesta de `GET /ruta`: `{distancia_m, duracion_s, puntos: [[lat,lng],…], pasos: […]}`.
class Ruta {
  const Ruta({required this.distanciaM, required this.duracionS, required this.puntos, required this.pasos});

  /// Un paso que apunta fuera de [puntos] es una respuesta inválida ([FormatException]).
  factory Ruta.fromJson(Json j) {
    final puntos = [for (final p in j['puntos'] as List) Coordenada(leerDouble((p as List)[0]), leerDouble(p[1]))];
    final pasos = leerLista(j['pasos']).map(PasoRuta.fromJson).toList();
    for (final paso in pasos) {
      if (paso.indice < 0 || paso.indice >= puntos.length) {
        throw FormatException('paso fuera del recorrido', paso.indice);
      }
    }
    return Ruta(
      distanciaM: leerDouble(j['distancia_m']),
      duracionS: leerDouble(j['duracion_s']),
      puntos: List.unmodifiable(puntos),
      pasos: List.unmodifiable(pasos),
    );
  }

  final double distanciaM;
  final double duracionS;
  final List<Coordenada> puntos;
  final List<PasoRuta> pasos;
}
