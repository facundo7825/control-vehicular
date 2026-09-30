import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../tiempo_real/respaldo.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';

/// Lo que se muestra del viaje en curso: el viaje (puede quedar en un estado final hasta que el usuario
/// lo descarte), la oferta pendiente del chofer y la última posición conocida del chofer asignado.
class SeguimientoViaje {
  const SeguimientoViaje({this.viaje, this.oferta, this.ubicacionChofer});

  final Viaje? viaje;
  final Oferta? oferta;
  final UbicacionChofer? ubicacionChofer;

  SeguimientoViaje conViaje(Viaje? v) => SeguimientoViaje(
    viaje: v,
    oferta: oferta,
    ubicacionChofer: v?.chofer?.id == viaje?.chofer?.id ? ubicacionChofer : null,
  );

  SeguimientoViaje conOferta(Oferta? o) => SeguimientoViaje(viaje: viaje, oferta: o, ubicacionChofer: ubicacionChofer);

  SeguimientoViaje conUbicacion(UbicacionChofer? u) =>
      SeguimientoViaje(viaje: viaje, oferta: oferta, ubicacionChofer: u);
}

final viajeActualProvider = AsyncNotifierProvider<ViajeActualNotifier, SeguimientoViaje>(ViajeActualNotifier.new);

/// Viaje actual sincronizado (spec 6): eventos de Reverb mientras el socket está conectado; con el socket
/// caído, `GET /viajes/actual` cada 10 s; al reconectar, estado completo otra vez.
class ViajeActualNotifier extends AsyncNotifier<SeguimientoViaje> {
  StreamSubscription<EventoTiempoReal>? _canalViaje;
  int? _canalViajeId;
  bool _consultando = false;

  late Usuario _usuario;
  late TiempoReal _tr;

  @override
  Future<SeguimientoViaje> build() async {
    _usuario = ref.watch(usuarioProvider);
    _tr = ref.watch(tiempoRealProvider);

    final canalChofer = _usuario.esChofer ? _tr.canal(Canales.chofer(_usuario.id)).listen(_alEvento) : null;
    final respaldo = Respaldo(tiempoReal: _tr, intervalo: ref.read(intervaloRespaldoProvider), refrescar: refrescar);
    ref.onDispose(() {
      respaldo.cerrar();
      unawaited(canalChofer?.cancel());
      unawaited(_canalViaje?.cancel());
    });

    final inicial = await _consultar(null);
    _seguir(inicial.viaje);
    return inicial;
  }

  /// Consulta la API y reemplaza el estado. La usan el respaldo, la reconexión y los avisos push.
  Future<void> refrescar() async {
    if (_consultando) return;
    _consultando = true;
    try {
      final nuevo = await _consultar(state.value?.viaje);
      if (!ref.mounted) return;
      state = AsyncData(nuevo);
      _seguir(nuevo.viaje);
    } on SesionInvalida {
      // `ClienteApi` ya avisó la sesión inválida (una sola vez); acá no hay nada más que hacer y este
      // método corre sin await desde el timer y el listener, así que no puede propagar el error.
    } on ErrorApi {
      // Sin red o error pasajero: se conserva lo último que se sabía y se reintenta en el próximo ciclo.
    } finally {
      _consultando = false;
    }
  }

  /// Pedido inmediato (spec 5.2 y 5.3). Los errores (422, etc.) llegan a la pantalla.
  Future<Viaje> pedir(PedidoViaje pedido) async {
    final v = await ref.read(apiProvider).pedirViaje(pedido);
    state = AsyncData(SeguimientoViaje(viaje: v));
    _seguir(v);
    return v;
  }

  Future<void> cancelar({String? motivo}) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).cancelarViaje(actual.id, motivo: motivo);
    _aplicarViaje(v);
  }

  /// El usuario ya vio el estado final (finalizado, cancelado, sin chofer): se vuelve al mapa.
  void descartar() {
    state = const AsyncData(SeguimientoViaje());
    _seguir(null);
  }

  Future<SeguimientoViaje> _consultar(Viaje? previo) async {
    final api = ref.read(apiProvider);
    final actual = await api.viajeActual();
    var viaje = actual.viaje;

    // `viajes/actual` no devuelve viajes terminados: si el que se seguía desapareció mientras no había
    // socket, se busca en el historial para mostrar cómo terminó (p. ej. sin_chofer).
    if (viaje == null && previo != null && !previo.estado.terminado && !_usuario.esChofer) {
      final historial = (await api.misViajes()).historial;
      viaje = historial.where((v) => v.id == previo.id).firstOrNull;
    }
    // Un viaje ya terminado se queda en pantalla hasta que el usuario lo descarte.
    if (viaje == null && previo != null && previo.estado.terminado) viaje = previo;

    UbicacionChofer? ubicacion = viaje?.chofer?.id == state.value?.viaje?.chofer?.id
        ? state.value?.ubicacionChofer
        : null;
    final choferId = viaje?.chofer?.id;
    if (choferId != null && !_usuario.esChofer && _tr.estado != EstadoConexion.conectado) {
      // Sin socket tampoco llegan las posiciones: se toman de GET /choferes.
      final c = (await api.choferes()).where((c) => c.id == choferId).firstOrNull;
      if (c?.posicion != null) {
        ubicacion = UbicacionChofer(
          choferId: c!.id,
          posicion: c.posicion!,
          rumbo: c.rumbo,
          actualizadoEn: c.actualizadoEn ?? DateTime.now().toUtc(),
        );
      }
    }
    return SeguimientoViaje(viaje: viaje, oferta: actual.oferta, ubicacionChofer: ubicacion);
  }

  /// Un evento que no se puede leer (p. ej. un estado nuevo del backend) se ignora: el respaldo o el
  /// próximo evento traen el estado.
  void _alEvento(EventoTiempoReal e) {
    try {
      _aplicarEvento(e);
    } catch (error) {
      if (!esErrorDeLectura(error)) rethrow;
      debugPrint('vehiculos_oficiales: evento ${e.nombre} ignorado, no se pudo leer: $error');
    }
  }

  void _aplicarEvento(EventoTiempoReal e) {
    final actual = state.value;
    if (actual == null) return;

    switch (e.nombre) {
      case Eventos.viajeActualizado:
        _aplicarViaje(Viaje.fromJson(e.datos));
      case Eventos.ofertaCreada:
        final oferta = Oferta.fromJson(e.datos);
        // Las solicitudes de reserva van a la agenda, no a la pantalla de oferta (AvisosViaje / ViajeController::actual).
        if (oferta.viaje.tipo == TipoViaje.inmediato) state = AsyncData(actual.conOferta(oferta));
      case Eventos.choferUbicacion:
        final u = UbicacionChofer.fromJson(e.datos);
        if (actual.viaje?.chofer?.id == u.choferId) state = AsyncData(actual.conUbicacion(u));
    }
  }

  void _aplicarViaje(Viaje v) {
    final actual = state.value ?? const SeguimientoViaje();
    var nuevo = actual;

    if (_usuario.esChofer) {
      // La oferta ya se respondió, venció o se la llevó otro.
      if (actual.oferta?.viaje.id == v.id && v.estado != EstadoViaje.ofrecido) nuevo = nuevo.conOferta(null);
      // Mismo viaje (incluso si se lo reasignaron a otro o lo cancelaron: el chofer lo ve y lo descarta)
      // o uno nuevo que lo ocupa ahora (obligatorio asignado, reserva que arrancó).
      final ocupaAhora =
          v.chofer?.id == _usuario.id &&
          !v.estado.terminado &&
          (v.tipo == TipoViaje.inmediato || v.estado != EstadoViaje.aceptado);
      if (actual.viaje?.id == v.id || ocupaAhora) nuevo = nuevo.conViaje(v);
    } else if (actual.viaje == null || actual.viaje!.id == v.id) {
      nuevo = nuevo.conViaje(v);
    }

    state = AsyncData(nuevo);
    _seguir(nuevo.viaje);
  }

  /// El solicitante escucha `viaje.{id}` (estado y posición del chofer) mientras el viaje no terminó.
  /// El chofer ya recibe `viaje.actualizado` por `chofer.{id}`.
  void _seguir(Viaje? viaje) {
    final id = viaje != null && !viaje.estado.terminado && !_usuario.esChofer ? viaje.id : null;
    if (id == _canalViajeId) return;
    unawaited(_canalViaje?.cancel());
    _canalViaje = null;
    _canalViajeId = id;
    if (id != null) _canalViaje = _tr.canal(Canales.viaje(id)).listen(_alEvento);
  }
}
