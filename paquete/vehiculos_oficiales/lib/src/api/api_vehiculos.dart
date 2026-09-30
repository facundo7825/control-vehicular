import '../modelos/modelos.dart';
import 'cliente_api.dart';

/// Resultado de `POST /auth/intercambio`.
class Intercambio {
  const Intercambio(this.token, this.usuario);

  final String token;
  final Usuario usuario;
}

/// Endpoints del backend (routes/api.php) con tipos. Los tests de providers la reemplazan por un doble.
class ApiVehiculos {
  ApiVehiculos(this.cliente);

  final ClienteApi cliente;

  Future<Intercambio> intercambiar(String tokenExterno) async {
    final j = await cliente.postMapa('auth/intercambio', datos: {'token_externo': tokenExterno});
    return Intercambio(j['token'] as String, Usuario.fromJson(leerMapa(j['usuario'])));
  }

  Future<Usuario> yo() async => Usuario.fromJson(await cliente.getMapa('yo'));

  Future<Configuracion> configuracion() async => Configuracion.fromJson(await cliente.getMapa('configuracion'));

  Future<List<ChoferEnMapa>> choferes() async =>
      leerLista(await cliente.get('choferes')).map(ChoferEnMapa.fromJson).toList();

  Future<ViajeActual> viajeActual() async => ViajeActual.fromJson(await cliente.getMapa('viajes/actual'));

  Future<MisViajes> misViajes() async => MisViajes.fromJson(await cliente.getMapa('viajes'));

  Future<Viaje> pedirViaje(PedidoViaje pedido) async =>
      Viaje.fromJson(await cliente.postMapa('viajes', datos: pedido.toJson()));

  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) async =>
      Viaje.fromJson(await cliente.postMapa('viajes/$viajeId/cancelar', datos: {'motivo': ?motivo}));

  Future<DisponiblesReserva> disponiblesReserva(FranjaReserva franja) async =>
      DisponiblesReserva.fromJson(await cliente.getMapa('reservas/disponibles', query: franja.toQuery()));

  Future<Viaje> crearReserva(PedidoReserva pedido) async =>
      Viaje.fromJson(await cliente.postMapa('reservas', datos: pedido.toJson()));

  Future<void> registrarTokenPush(String token) async {
    await cliente.post('push/token', datos: {'token': token});
  }

  /// Firma de un canal privado (`private-...`) para el socket [socketId]. Devuelve `auth`.
  Future<String> autorizarCanal({required String socketId, required String canal}) async {
    final j = leerMapa(
      await cliente.postFormulario('broadcasting/auth', {'socket_id': socketId, 'channel_name': canal}),
    );
    return j['auth'] as String;
  }
}
