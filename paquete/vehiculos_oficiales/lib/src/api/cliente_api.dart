import 'package:dio/dio.dart';

import '../modelos/json.dart';
import 'errores_api.dart';

/// HTTP contra `/api` del backend. Agrega `Accept: application/json` (sin él Laravel responde un 401
/// como redirección al login) y el token Sanctum, y traduce las respuestas de error a [ErrorApi].
class ClienteApi {
  ClienteApi({required Uri baseApi, required this.alRecibir401, HttpClientAdapter? adaptador})
    : _dio = Dio(
        BaseOptions(
          baseUrl: baseApi.toString(),
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 20),
          headers: {'Accept': 'application/json'},
        ),
      ) {
    if (adaptador != null) _dio.httpClientAdapter = adaptador;
  }

  final Dio _dio;

  /// Se llama con cada 401. Quien lo recibe decide qué hacer (ver `AvisoSesionInvalida`).
  final void Function() alRecibir401;

  /// Token Sanctum. Nulo hasta el intercambio.
  String? token;

  Future<Object?> get(String ruta, {Map<String, dynamic>? query}) =>
      _enviar(() => _dio.get<Object?>(ruta, queryParameters: query, options: _opciones()));

  Future<Object?> post(String ruta, {Object? datos}) =>
      _enviar(() => _dio.post<Object?>(ruta, data: datos ?? const <String, dynamic>{}, options: _opciones()));

  /// POST `application/x-www-form-urlencoded`, como lo pide `/broadcasting/auth` (protocolo Pusher).
  Future<Object?> postFormulario(String ruta, Map<String, String> campos) => _enviar(
    () => _dio.post<Object?>(
      ruta,
      data: campos,
      options: _opciones(contentType: Headers.formUrlEncodedContentType),
    ),
  );

  Future<Json> getMapa(String ruta, {Map<String, dynamic>? query}) async => leerMapa(await get(ruta, query: query));

  Future<Json> postMapa(String ruta, {Object? datos}) async => leerMapa(await post(ruta, datos: datos));

  Options _opciones({String? contentType}) => Options(
    contentType: contentType ?? Headers.jsonContentType,
    headers: {if (token != null) 'Authorization': 'Bearer $token'},
  );

  Future<Object?> _enviar(Future<Response<Object?>> Function() pedido) async {
    try {
      final r = await pedido();
      return r.statusCode == 204 ? null : r.data;
    } on DioException catch (e) {
      throw _traducir(e);
    }
  }

  ErrorApi _traducir(DioException e) {
    final r = e.response;
    if (r == null) {
      return switch (e.type) {
        DioExceptionType.connectionError ||
        DioExceptionType.connectionTimeout ||
        DioExceptionType.sendTimeout ||
        DioExceptionType.receiveTimeout => const SinConexion(),
        _ => const ErrorServidor(), // p. ej. una respuesta que no es JSON
      };
    }

    final cuerpo = r.data;
    final mensaje = cuerpo is Map && cuerpo['message'] is String && (cuerpo['message'] as String).isNotEmpty
        ? cuerpo['message'] as String
        : null;

    switch (r.statusCode) {
      case 401:
        alRecibir401();
        return SesionInvalida(mensaje ?? 'Tu sesión venció.');
      case 403:
        return AccesoDenegado(mensaje ?? 'No tenés permiso para esta acción.');
      case 404:
        return NoEncontrado(mensaje ?? 'No se encontró lo que buscabas.');
      case 422:
        return ErrorNegocio(mensaje ?? 'No se pudo completar la acción.', errores: _errores(cuerpo));
      case 503:
        return ServicioNoDisponible(mensaje ?? 'Servicio de identidad no disponible.');
      default:
        return const ErrorServidor();
    }
  }

  static Map<String, List<String>> _errores(Object? cuerpo) {
    if (cuerpo is! Map || cuerpo['errors'] is! Map) return const {};
    return (cuerpo['errors'] as Map).map(
      (k, v) => MapEntry(k.toString(), (v as List).map((m) => m.toString()).toList()),
    );
  }
}
