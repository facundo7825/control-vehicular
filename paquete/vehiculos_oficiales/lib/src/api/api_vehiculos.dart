import 'package:flutter/foundation.dart';

import '../modelos/modelos.dart';
import 'cliente_api.dart';
import 'errores_api.dart';

/// Resultado de `POST /auth/intercambio`.
class Intercambio {
  const Intercambio(this.token, this.usuario);

  final String token;
  final Usuario usuario;
}

/// Endpoints del backend (routes/api.php) con tipos. Los tests de providers la reemplazan por un doble.
///
/// Todas las respuestas se leen con [_leer]: una que no se puede leer (un estado nuevo que la app no
/// conoce, un campo con otro tipo) es un [ErrorServidor], como cualquier otro error de la API, así quien
/// llama solo tiene que capturar [ErrorApi].
class ApiVehiculos {
  ApiVehiculos(this.cliente);

  final ClienteApi cliente;

  Future<Intercambio> intercambiar(String tokenExterno) => _leer(() async {
    final j = await cliente.postMapa('auth/intercambio', datos: {'token_externo': tokenExterno});
    return Intercambio(j['token'] as String, Usuario.fromJson(leerMapa(j['usuario'])));
  });

  Future<Usuario> yo() => _leer(() async => Usuario.fromJson(await cliente.getMapa('yo')));

  Future<Configuracion> configuracion() =>
      _leer(() async => Configuracion.fromJson(await cliente.getMapa('configuracion')));

  Future<List<ChoferEnMapa>> choferes() =>
      _leer(() async => leerLista(await cliente.get('choferes')).map(ChoferEnMapa.fromJson).toList());

  Future<ViajeActual> viajeActual() => _leer(() async => ViajeActual.fromJson(await cliente.getMapa('viajes/actual')));

  Future<MisViajes> misViajes() => _leer(() async => MisViajes.fromJson(await cliente.getMapa('viajes')));

  Future<Eta> eta(int viajeId) => _leer(() async => Eta.fromJson(await cliente.getMapa('viajes/$viajeId/eta')));

  Future<Viaje> pedirViaje(PedidoViaje pedido) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('viajes', datos: pedido.toJson())));

  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('viajes/$viajeId/cancelar', datos: {'motivo': ?motivo})));

  Future<DisponiblesReserva> disponiblesReserva(FranjaReserva franja) => _leer(
    () async => DisponiblesReserva.fromJson(await cliente.getMapa('reservas/disponibles', query: franja.toQuery())),
  );

  Future<Viaje> crearReserva(PedidoReserva pedido) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('reservas', datos: pedido.toJson())));

  Future<void> registrarTokenPush(String token) async {
    await cliente.post('push/token', datos: {'token': token});
  }

  /// Firma de un canal privado (`private-...`) para el socket [socketId]. Devuelve `auth`.
  Future<String> autorizarCanal({required String socketId, required String canal}) => _leer(() async {
    final j = leerMapa(
      await cliente.postFormulario('broadcasting/auth', {'socket_id': socketId, 'channel_name': canal}),
    );
    return j['auth'] as String;
  });

  static Future<T> _leer<T>(Future<T> Function() pedido) async {
    try {
      return await pedido();
    } catch (e) {
      if (esErrorDeLectura(e)) {
        debugPrint('vehiculos_oficiales: respuesta del servidor que no se pudo leer: $e');
        throw const ErrorServidor();
      }
      rethrow;
    }
  }
}
