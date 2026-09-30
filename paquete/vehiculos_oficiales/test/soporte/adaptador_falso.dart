import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

class PedidoRegistrado {
  PedidoRegistrado(this.metodo, this.uri, this.headers, this.cuerpo);

  final String metodo;
  final Uri uri;
  final Map<String, dynamic> headers;
  final String cuerpo;
}

/// Adaptador HTTP de dio para tests: responde según "MÉTODO ruta" (ruta sin `/api/` ni query)
/// y registra cada pedido. Una respuesta nula simula falta de red.
class AdaptadorFalso implements HttpClientAdapter {
  final Map<String, List<(int, String?)>> _respuestas = {};
  final Map<String, Completer<(int, String?)>> _demorados = {};
  final List<PedidoRegistrado> pedidos = [];

  /// Encola una respuesta. Si queda una sola, se repite en los pedidos siguientes.
  void responder(String metodo, String ruta, int estado, [String? cuerpo]) =>
      (_respuestas['$metodo $ruta'] ??= []).add((estado, cuerpo));

  void sinRed(String metodo, String ruta) => (_respuestas['$metodo $ruta'] ??= []).add((-1, null));

  /// Los pedidos a esa ruta quedan esperando hasta que el test complete el `Completer` con (estado, cuerpo).
  Completer<(int, String?)> demorar(String metodo, String ruta) => _demorados['$metodo $ruta'] = Completer();

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final bytes = requestStream == null
        ? <int>[]
        : await requestStream.fold<List<int>>(<int>[], (a, b) => a..addAll(b));
    pedidos.add(PedidoRegistrado(options.method, options.uri, options.headers, utf8.decode(bytes)));

    final ruta = options.uri.path.replaceFirst(RegExp(r'^/api/'), '');
    final demorado = _demorados['${options.method} $ruta'];
    final cola = _respuestas['${options.method} $ruta'];
    if (demorado == null && (cola == null || cola.isEmpty)) {
      throw StateError('Sin respuesta preparada para ${options.method} $ruta');
    }
    final (estado, cuerpo) = demorado != null
        ? await demorado.future
        : cola!.length > 1
        ? cola.removeAt(0)
        : cola.first;
    if (estado == -1) {
      throw DioException.connectionError(requestOptions: options, reason: 'sin red');
    }
    return ResponseBody.fromString(
      cuerpo ?? '',
      estado,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
