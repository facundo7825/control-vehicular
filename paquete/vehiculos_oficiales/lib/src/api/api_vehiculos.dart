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

  /// Detalle de un viaje. 403 "Este viaje no es tuyo." si no es su solicitante, su chofer actual ni un admin.
  Future<Viaje> viaje(int id) => _leer(() async => Viaje.fromJson(await cliente.getMapa('viajes/$id')));

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

  /// Hasta 5 lugares para [texto] (3 a 200 caracteres, lo que valida `LugaresController`), sesgados
  /// hacia [cerca] si se conoce. Un fallo del proveedor llega como lista vacía.
  Future<List<LugarEncontrado>> buscarLugares(String texto, {Coordenada? cerca}) => _leer(
    () async => leerLista(
      await cliente.get(
        'lugares',
        query: {
          'q': texto,
          if (cerca != null) ...{'lat': cerca.lat, 'lng': cerca.lng},
        },
      ),
    ).map(LugarEncontrado.fromJson).toList(),
  );

  /// Recorrido en auto de [origen] a [destino], con indicaciones en español. Nulo si el proveedor no pudo
  /// armarlo (el backend responde `null`).
  Future<Ruta?> obtenerRuta(Coordenada origen, Coordenada destino) => _leer(() async {
    final j = await cliente.get(
      'ruta',
      query: {
        'origen_lat': origen.lat,
        'origen_lng': origen.lng,
        'destino_lat': destino.lat,
        'destino_lng': destino.lng,
      },
    );
    return j == null ? null : Ruta.fromJson(leerMapa(j));
  });

  Future<void> registrarTokenPush(String token) async {
    await cliente.post('push/token', datos: {'token': token});
  }

  // --- Chofer (rol:chofer en routes/api.php) ---

  Future<List<Vehiculo>> vehiculosDisponibles() =>
      _leer(() async => leerLista(await cliente.get('vehiculos/disponibles')).map(Vehiculo.fromJson).toList());

  /// `{"turno": null}` sin turno abierto.
  Future<Turno?> turnoActual() => _leer(() async {
    final j = await cliente.getMapa('turnos/actual');
    return j['turno'] == null ? null : Turno.fromJson(leerMapa(j['turno']));
  });

  Future<Turno> iniciarTurno(int vehiculoId) =>
      _leer(() async => Turno.fromJson(await cliente.postMapa('turnos', datos: {'vehiculo_id': vehiculoId})));

  Future<Turno> finalizarTurno() =>
      _leer(() async => Turno.fromJson(await cliente.postMapa('turnos/actual/finalizar')));

  /// 204. Hasta 500 puntos por pedido (lo que valida `UbicacionController`).
  Future<void> enviarUbicacion(List<PuntoGps> puntos) async {
    await cliente.post(
      'ubicacion',
      datos: {
        'puntos': [for (final p in puntos) p.toJson()],
      },
    );
  }

  /// Devuelve el viaje ya asignado. 422 "La oferta ya no está vigente." si venció o ya se respondió.
  Future<Viaje> aceptarOferta(int ofertaId) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('ofertas/$ofertaId/aceptar')));

  /// 204.
  Future<void> rechazarOferta(int ofertaId) async {
    await cliente.post('ofertas/$ofertaId/rechazar');
  }

  /// `en_camino`, `llego`, `en_curso` o `finalizado`.
  Future<Viaje> avanzarViaje(int viajeId, EstadoViaje estado) => _leer(
    () async => Viaje.fromJson(await cliente.postMapa('viajes/$viajeId/estado', datos: {'estado': estado.valor})),
  );

  Future<Agenda> agenda() => _leer(() async => Agenda.fromJson(await cliente.getMapa('agenda')));

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
