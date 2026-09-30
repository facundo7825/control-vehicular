import 'dart:async';

import 'tiempo_real.dart';

/// Spec 6: mientras el WebSocket no está conectado, llama a [refrescar] cada [intervalo]; cuando se
/// (re)conecta, corta el sondeo y llama a [refrescar] una vez para traer el estado completo.
class Respaldo {
  Respaldo({required TiempoReal tiempoReal, required this.intervalo, required this.refrescar}) : _tr = tiempoReal {
    _escucha = _tr.estados.listen(_alCambiar);
    if (_tr.estado != EstadoConexion.conectado) _sondear();
  }

  final TiempoReal _tr;
  final Duration intervalo;
  final Future<void> Function() refrescar;
  late final StreamSubscription<EstadoConexion> _escucha;
  Timer? _timer;

  bool get sondeando => _timer?.isActive ?? false;

  void _alCambiar(EstadoConexion estado) {
    if (estado == EstadoConexion.conectado) {
      _timer?.cancel();
      _timer = null;
      unawaited(refrescar());
    } else {
      _sondear();
    }
  }

  void _sondear() {
    if (sondeando) return;
    _timer = Timer.periodic(intervalo, (_) => unawaited(refrescar()));
  }

  void cerrar() {
    _timer?.cancel();
    unawaited(_escucha.cancel());
  }
}
