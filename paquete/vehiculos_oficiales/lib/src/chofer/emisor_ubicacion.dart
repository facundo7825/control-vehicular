import 'package:flutter/foundation.dart';

import '../api/api_vehiculos.dart';
import '../api/errores_api.dart';
import 'cola_ubicaciones.dart';

enum ResultadoEnvio {
  /// El servidor recibió el lote (204) y salió de la cola.
  enviado,

  /// No había nada que mandar.
  sinCambios,

  /// Sin red, 5xx, 401…: el lote queda en la cola y se reintenta en el ciclo siguiente.
  reintentar,

  /// Hay acciones del viaje (llegué, iniciar, finalizar) sin enviar: los puntos esperan en la cola a que salgan,
  /// así el servidor ya conoce el intervalo del viaje al recibirlos (decisión 3 del plan sin señal).
  retenido,

  /// El backend dice que no hay turno abierto (422 "Iniciá un turno…") o que ya no es chofer (403):
  /// hay que dejar de rastrear.
  sinTurno,
}

/// Único lugar desde donde sale `POST /ubicacion` (spec 6 y 9). Un pedido por vez, en orden, de a
/// [lote] puntos como máximo; solo saca de la cola lo que el servidor confirmó.
class EmisorUbicacion {
  EmisorUbicacion({required this.api, required this.cola, this.lote = 500, this.retener});

  final ApiVehiculos api;
  final ColaUbicaciones cola;
  final int lote;

  /// Verdadero mientras no se puede mandar: los puntos esperan en la cola ([ResultadoEnvio.retenido]).
  final bool Function()? retener;

  Future<ResultadoEnvio>? _enCurso;

  /// Manda el lote más viejo. Si ya hay un envío en curso, devuelve ese mismo (no sale otro pedido).
  /// Nunca lanza un [ErrorApi].
  Future<ResultadoEnvio> enviar() {
    final enCurso = _enCurso;
    if (enCurso != null) return enCurso;
    if (cola.largo == 0) return Future.value(ResultadoEnvio.sinCambios);
    if (retener?.call() ?? false) return Future.value(ResultadoEnvio.retenido);
    final envio = _enviarLote();
    _enCurso = envio;
    return envio.whenComplete(() => _enCurso = null);
  }

  /// Manda lotes hasta vaciar la cola o hasta el primer envío que no salió. Devuelve el último resultado.
  Future<ResultadoEnvio> vaciarTodo() async {
    var resultado = ResultadoEnvio.sinCambios;
    while (cola.largo > 0) {
      resultado = await enviar();
      if (resultado != ResultadoEnvio.enviado) return resultado;
    }
    return resultado;
  }

  /// Para los puntos de un turno que ya se cerró: manda lotes hasta vaciar la cola. El servidor acepta los que
  /// caen en un viaje del chofer aunque no haya turno; un lote que responde [ResultadoEnvio.sinTurno] no tiene
  /// ninguno y se descarta. Devuelve [ResultadoEnvio.enviado] (o `sinCambios`) con la cola vacía; si no,
  /// el resultado que cortó (sin red, retenido): lo que queda sigue en la cola.
  Future<ResultadoEnvio> vaciarSinTurno() async {
    var resultado = ResultadoEnvio.sinCambios;
    while (cola.largo > 0) {
      final lote = cola.primeros(this.lote);
      resultado = await enviar();
      if (resultado == ResultadoEnvio.sinTurno) {
        cola.quitar(lote);
        resultado = ResultadoEnvio.enviado;
        continue;
      }
      if (resultado != ResultadoEnvio.enviado) return resultado;
    }
    return resultado;
  }

  Future<ResultadoEnvio> _enviarLote() async {
    final puntos = cola.primeros(lote);
    try {
      await api.enviarUbicacion(puntos);
      cola.quitar(puntos);
      return ResultadoEnvio.enviado;
    } on ErrorNegocio catch (e) {
      if (e.errores.isEmpty) return ResultadoEnvio.sinTurno; // la única regla de negocio de ServicioUbicacion
      // Validación de Laravel: el lote nunca va a pasar. Se descarta para no trabar la cola para siempre.
      debugPrint('vehiculos_oficiales: lote de ubicaciones rechazado y descartado: ${e.errores.keys.first}');
      cola.quitar(puntos);
      return ResultadoEnvio.reintentar;
    } on AccesoDenegado {
      return ResultadoEnvio.sinTurno;
    } on ErrorApi {
      // Incluye SesionInvalida: ClienteApi ya avisó a la app principal.
      return ResultadoEnvio.reintentar;
    }
  }
}
