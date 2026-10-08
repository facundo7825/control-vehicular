import 'dart:collection';

import '../modelos/modelos.dart';

/// Puntos del GPS que todavía no llegaron al servidor (spec 6 y 9), en memoria.
///
/// Siempre ordenados por `registrado_en` (aunque llegue uno más viejo), sin repetidos (misma hora en
/// milisegundos) y con un [tope]: si se llena, se descartan los más viejos (5000 puntos son ~14 h a 10 s).
class ColaUbicaciones {
  ColaUbicaciones({this.tope = 5000});

  final int tope;
  final _puntos = SplayTreeMap<int, PuntoGps>();

  int get largo => _puntos.length;

  void agregar(PuntoGps p) {
    _puntos.putIfAbsent(p.registradoEn.millisecondsSinceEpoch, () => p);
    while (_puntos.length > tope) {
      _puntos.remove(_puntos.firstKey());
    }
  }

  /// Todos, en orden (para guardarlos en disco).
  List<PuntoGps> get puntos => _puntos.values.toList();

  /// Agrega puntos guardados (al retomar un turno), con las mismas reglas que [agregar].
  void cargar(Iterable<PuntoGps> puntos) => puntos.forEach(agregar);

  /// Los [n] más viejos, en orden.
  List<PuntoGps> primeros(int n) => _puntos.values.take(n).toList();

  /// Saca exactamente los puntos de un envío que el servidor recibió (aunque mientras tanto hayan
  /// entrado otros más viejos o el tope haya descartado alguno).
  void quitar(Iterable<PuntoGps> enviados) {
    for (final p in enviados) {
      _puntos.remove(p.registradoEn.millisecondsSinceEpoch);
    }
  }

  void vaciar() => _puntos.clear();

  /// Saca los registrados antes de [limite] (p. ej. los de más de 24 h, que el servidor ya no acepta).
  void descartarAnteriores(DateTime limite) {
    final corte = limite.millisecondsSinceEpoch;
    while (_puntos.isNotEmpty && _puntos.firstKey()! < corte) {
      _puntos.remove(_puntos.firstKey());
    }
  }
}
