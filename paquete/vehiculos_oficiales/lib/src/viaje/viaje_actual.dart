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
  const SeguimientoViaje({this.viaje, this.oferta, this.ubicacionChofer, this.asignadoSinOferta = false});

  final Viaje? viaje;
  final Oferta? oferta;
  final UbicacionChofer? ubicacionChofer;

  /// Chofer: [viaje] le llegó ya asignado, sin oferta (obligatorio, o asignado por un administrador). Se
  /// muestra el aviso "Viaje asignado" hasta que lo vea (`verViajeAsignado`).
  final bool asignadoSinOferta;

  SeguimientoViaje conViaje(Viaje? v) => SeguimientoViaje(
    viaje: v,
    oferta: oferta,
    ubicacionChofer: v?.chofer?.id == viaje?.chofer?.id ? ubicacionChofer : null,
    asignadoSinOferta: asignadoSinOferta && v?.id == viaje?.id,
  );

  SeguimientoViaje conOferta(Oferta? o) =>
      SeguimientoViaje(viaje: viaje, oferta: o, ubicacionChofer: ubicacionChofer, asignadoSinOferta: asignadoSinOferta);

  SeguimientoViaje conUbicacion(UbicacionChofer? u) =>
      SeguimientoViaje(viaje: viaje, oferta: oferta, ubicacionChofer: u, asignadoSinOferta: asignadoSinOferta);

  SeguimientoViaje conAsignado(bool a) =>
      SeguimientoViaje(viaje: viaje, oferta: oferta, ubicacionChofer: ubicacionChofer, asignadoSinOferta: a);
}

final viajeActualProvider = AsyncNotifierProvider<ViajeActualNotifier, SeguimientoViaje>(ViajeActualNotifier.new);

/// Viaje actual sincronizado (spec 6): eventos de Reverb mientras el socket está conectado; con el socket
/// caído, `GET /viajes/actual` cada 10 s; al reconectar, estado completo otra vez.
class ViajeActualNotifier extends AsyncNotifier<SeguimientoViaje> {
  StreamSubscription<EventoTiempoReal>? _canalViaje;
  int? _canalViajeId;
  bool _consultando = false;

  /// Cambia con cada novedad del viaje o de la oferta que no vino de [refrescar] (respuesta de una acción,
  /// evento del socket). Una consulta que empezó antes y termina después no pisa esa novedad.
  int _version = 0;

  /// Se pidió un refresco mientras había uno en vuelo: al terminar se hace uno más (nunca más de uno).
  bool _pendiente = false;

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
  ///
  /// Si se pide con otra consulta en vuelo, o si la que estaba en vuelo se descartó porque una novedad
  /// cambió el estado, se hace una más al terminar (una sola): la reconexión refresca una única vez y no
  /// puede quedarse sin su estado completo.
  Future<void> refrescar() async {
    if (_consultando) {
      _pendiente = true;
      return;
    }
    _consultando = true;
    _pendiente = false;
    try {
      final descartada = await _refrescarUna();
      if (ref.mounted && (descartada || _pendiente)) await _refrescarUna();
    } finally {
      _consultando = false;
      _pendiente = false;
    }
  }

  /// Una consulta completa. Devuelve `true` si su resultado se descartó porque el estado cambió mientras
  /// tanto. Los errores de la API no se propagan (corre sin await desde el timer y el listener).
  Future<bool> _refrescarUna() async {
    final version = _version;
    final antes = state.value;
    try {
      var nuevo = await _consultar(antes?.viaje);
      if (!ref.mounted) return false;
      if (version != _version) return true;
      // Sin socket, un viaje asignado sin oferta aparece recién acá.
      if (nuevo.viaje case final v? when _llegoSinOferta(antes, v)) nuevo = nuevo.conAsignado(true);
      state = AsyncData(nuevo);
      _seguir(nuevo.viaje);
    } on SesionInvalida {
      // `ClienteApi` ya avisó la sesión inválida (una sola vez); acá no hay nada más que hacer.
    } on ErrorApi {
      // Sin red o error pasajero: se conserva lo último que se sabía y se reintenta en el próximo ciclo.
    }
    return false;
  }

  /// Pedido inmediato (spec 5.2 y 5.3). Los errores (422, etc.) llegan a la pantalla.
  Future<Viaje> pedir(PedidoViaje pedido) async {
    final v = await ref.read(apiProvider).pedirViaje(pedido);
    _fijar(SeguimientoViaje(viaje: v));
    _seguir(v);
    return v;
  }

  Future<void> cancelar({String? motivo}) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).cancelarViaje(actual.id, motivo: motivo);
    if (ref.mounted) _aplicarViaje(v);
  }

  /// Paso siguiente del chofer (spec 5.5): `en_camino`, `llego`, `en_curso` o `finalizado`. Los errores
  /// (422 de una reserva que todavía no puede empezar, 403 si el viaje ya no es suyo) llegan a la pantalla.
  Future<void> avanzar(EstadoViaje hacia) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).avanzarViaje(actual.id, hacia);
    if (ref.mounted) _aplicarViaje(v);
  }

  /// Chofer: acepta la oferta pendiente (spec 5.1). Con la respuesta queda el viaje y se va la oferta. Si
  /// ya no está vigente (422) también se va, y el error llega a la pantalla.
  Future<void> aceptarOferta() async {
    final oferta = state.value?.oferta;
    if (oferta == null) return;
    try {
      final v = await ref.read(apiProvider).aceptarOferta(oferta.id);
      if (ref.mounted) _aplicarViaje(v);
    } on ErrorNegocio {
      _quitarOferta(oferta.id);
      rethrow;
    }
  }

  /// Chofer: rechaza la oferta pendiente. Sin red, la oferta queda (se puede reintentar hasta que venza).
  Future<void> rechazarOferta() async {
    final oferta = state.value?.oferta;
    if (oferta == null) return;
    try {
      await ref.read(apiProvider).rechazarOferta(oferta.id);
    } on ErrorNegocio {
      _quitarOferta(oferta.id);
      rethrow;
    }
    _quitarOferta(oferta.id);
  }

  /// La cuenta regresiva de la oferta llegó a cero (según el reloj del servidor).
  void ofertaVencida(int ofertaId) => _quitarOferta(ofertaId);

  /// El chofer vio el aviso "Viaje asignado".
  void verViajeAsignado() {
    final actual = state.value;
    if (actual != null && actual.asignadoSinOferta) _fijar(actual.conAsignado(false));
  }

  void _quitarOferta(int ofertaId) {
    final actual = state.value;
    if (ref.mounted && actual?.oferta?.id == ofertaId) _fijar(actual!.conOferta(null));
  }

  /// El usuario ya vio el estado final (finalizado, cancelado, sin chofer): se vuelve al mapa.
  void descartar() {
    _fijar(const SeguimientoViaje());
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
      // Sin socket tampoco llegan las posiciones: se toman de GET /choferes. Es un extra: si falla, se
      // conserva la última posición conocida y el viaje igual se actualiza.
      try {
        final c = (await api.choferes()).where((c) => c.id == choferId).firstOrNull;
        if (c?.posicion != null) {
          ubicacion = UbicacionChofer(
            choferId: c!.id,
            posicion: c.posicion!,
            rumbo: c.rumbo,
            actualizadoEn: c.actualizadoEn ?? DateTime.now().toUtc(),
          );
        }
      } on ErrorApi {
        // Incluye un 401: `ClienteApi` ya avisó la sesión inválida.
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
        if (oferta.viaje.tipo == TipoViaje.inmediato) _fijar(actual.conOferta(oferta));
      case Eventos.choferUbicacion:
        final u = UbicacionChofer.fromJson(e.datos);
        if (actual.viaje?.chofer?.id == u.choferId) state = AsyncData(actual.conUbicacion(u));
    }
  }

  void _aplicarViaje(Viaje v) {
    final actual = state.value ?? const SeguimientoViaje();
    if (_atrasado(actual.viaje, v)) return;
    var nuevo = actual;

    if (_usuario.esChofer) {
      final sinOferta = _llegoSinOferta(actual, v);
      // La oferta ya se respondió, venció o se la llevó otro.
      if (actual.oferta?.viaje.id == v.id && v.estado != EstadoViaje.ofrecido) nuevo = nuevo.conOferta(null);
      // Mismo viaje (incluso si se lo reasignaron a otro o lo cancelaron: el chofer lo ve y lo descarta)
      // o uno nuevo que lo ocupa ahora (obligatorio asignado, reserva que arrancó).
      final ocupaAhora =
          v.chofer?.id == _usuario.id &&
          !v.estado.terminado &&
          (v.tipo == TipoViaje.inmediato || v.estado != EstadoViaje.aceptado);
      if (actual.viaje?.id == v.id || ocupaAhora) nuevo = nuevo.conViaje(v);
      if (sinOferta) nuevo = nuevo.conAsignado(true);
    } else if (actual.viaje == null || actual.viaje!.id == v.id) {
      nuevo = nuevo.conViaje(v);
    }

    _fijar(nuevo);
    _seguir(nuevo.viaje);
  }

  /// Chofer: un inmediato que ya le llega aceptado, sin haber tenido la oferta (spec 5.2 y 5.6). Uno que el
  /// chofer aceptó siempre tuvo su oferta en el estado (el evento o la respuesta de aceptar la encuentran).
  bool _llegoSinOferta(SeguimientoViaje? antes, Viaje v) =>
      _usuario.esChofer &&
      v.tipo == TipoViaje.inmediato &&
      v.estado == EstadoViaje.aceptado &&
      v.chofer?.id == _usuario.id &&
      antes?.viaje?.id != v.id &&
      antes?.oferta?.viaje.id != v.id;

  /// Aplica una novedad. Solo cuenta como cambio (y descarta las consultas en vuelo) si el viaje, la oferta
  /// o el aviso de asignado son otros: un evento repetido no tira una consulta que trae el estado completo.
  void _fijar(SeguimientoViaje s) {
    if (!_equivalente(state.value, s)) _version++;
    state = AsyncData(s);
  }

  static bool _equivalente(SeguimientoViaje? a, SeguimientoViaje b) =>
      a != null &&
      a.asignadoSinOferta == b.asignadoSinOferta &&
      a.oferta?.id == b.oferta?.id &&
      a.viaje?.id == b.viaje?.id &&
      a.viaje?.estado == b.viaje?.estado &&
      a.viaje?.chofer?.id == b.viaje?.chofer?.id;

  /// Cuánto avanzó un viaje con chofer (0 = todavía sin chofer).
  static int _avance(EstadoViaje e) => switch (e) {
    EstadoViaje.buscando || EstadoViaje.ofrecido => 0,
    EstadoViaje.aceptado => 1,
    EstadoViaje.enCamino => 2,
    EstadoViaje.llego => 3,
    EstadoViaje.enCurso => 4,
    EstadoViaje.finalizado || EstadoViaje.cancelado || EstadoViaje.sinChofer => 5,
  };

  /// Una respuesta o un evento que llega tarde (p. ej. la respuesta de "Iniciar viaje" después del evento
  /// "cancelado") no hace retroceder el mismo viaje con el mismo chofer. Con otro chofer (reasignado,
  /// cancelado por el chofer) sí se aplica.
  static bool _atrasado(Viaje? actual, Viaje v) =>
      actual != null &&
      actual.id == v.id &&
      actual.chofer?.id == v.chofer?.id &&
      _avance(v.estado) > 0 &&
      _avance(v.estado) < _avance(actual.estado);

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
