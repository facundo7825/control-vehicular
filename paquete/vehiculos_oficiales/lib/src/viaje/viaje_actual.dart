import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_vehiculos.dart';
import '../api/errores_api.dart';
import '../api/reloj_servidor.dart';
import '../avisos/notificaciones_locales.dart';
import '../chofer/cola_acciones.dart';
import '../chofer/estado_guardado.dart';
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

  /// Viaje de la última oferta que el chofer aceptó (desde que toca "Aceptar").
  int? _aceptandoViajeId;

  /// Viajes que se vieron `cancelado` o `finalizado` (finales en el backend; `sin_chofer` no: un administrador
  /// puede reasignarlo). Un evento, una consulta o una respuesta atrasados (reintento de la cola del backend,
  /// reinicio del servidor) no los devuelven a un estado activo. Acotado a los últimos [_maxFinales].
  final _finales = <int>{};
  static const _maxFinales = 50;

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

    // Las acciones que quedaron sin enviar (la app se cerró sin señal) se aplican sobre la primera consulta.
    final AlmacenJson? guardado = _usuario.esChofer ? ref.read(almacenViajeGuardadoProvider) : null;
    if (guardado != null) {
      await ref.read(colaAccionesProvider.future);
      if (!ref.mounted) return const SeguimientoViaje();
      // El viaje activo del chofer queda guardado para abrir sin señal; uno terminado (o sin viaje), se borra.
      final yo = _usuario.id;
      listenSelf((_, s) {
        if (s case AsyncData(:final value)) {
          final v = value.viaje;
          final activo = v != null && !v.estado.terminado && v.chofer?.id == yo;
          unawaited(guardarPara(guardado, yo, activo ? v.toJson() : null));
        }
      });
    }
    SeguimientoViaje inicial;
    try {
      inicial = await _consultar(null);
    } on SinConexion {
      // Chofer sin señal al abrir: sigue con el viaje guardado (y sus acciones sin enviar encima) hasta que
      // vuelva la señal; el respaldo y la reconexión traen el estado real.
      final viaje = guardado == null ? null : await leerGuardado(guardado, _usuario.id, Viaje.fromJson);
      if (viaje == null) rethrow;
      _bases[viaje.id] = viaje;
      inicial = SeguimientoViaje(viaje: _superponer(viaje, _pendientes()));
    }
    // Descartado mientras consultaba: el onDispose ya corrió y no cancelaría un canal abierto ahora.
    if (!ref.mounted) return inicial;
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
      // La consulta no trae la marca: la de un viaje que sigue siendo el mismo se conserva hasta que el
      // chofer lo vea (un push o el respaldo justo después del evento no la borran).
      if (antes != null && antes.asignadoSinOferta && nuevo.viaje != null && antes.viaje?.id == nuevo.viaje?.id) {
        nuevo = nuevo.conAsignado(true);
      }
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
    // Android 13+: los avisos en segundo plano necesitan permiso. Se pide con el primer pedido.
    unawaited(ref.read(notificacionesLocalesProvider).pedirPermiso());
    return v;
  }

  /// La cancelación la pidió este usuario (no suena el aviso de "cancelado"). Se marca antes de llamar a
  /// la API: el evento del socket puede llegar antes que la respuesta.
  bool canceladoPorMi(int viajeId) => _cancelandoViajeId == viajeId;
  int? _cancelandoViajeId;

  Future<void> cancelar({String? motivo}) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    _cancelandoViajeId = actual.id;
    try {
      final v = await ref.read(apiProvider).cancelarViaje(actual.id, motivo: motivo);
      if (ref.mounted) _aplicarViaje(v);
    } catch (e) {
      _cancelandoViajeId = null;
      // Si la pantalla quedó vieja (se perdieron eventos y el viaje ya pasó a `sin_chofer`, o ya no existe),
      // se trae el estado real para que se corrija; el error igual llega a la pantalla.
      if (ref.mounted && e is ErrorApi && e is! SinConexion && e is! SesionInvalida) await _refrescarSinFallar();
      rethrow;
    }
  }

  /// Un refresco de apoyo: nunca lanza (el error que importa es el de quien lo pidió).
  Future<void> _refrescarSinFallar() async {
    try {
      await refrescar();
    } catch (error) {
      debugPrint('vehiculos_oficiales: no se pudo refrescar el viaje tras un error: $error');
    }
  }

  /// Paso siguiente del chofer (spec 5.5): `en_camino`, `llego`, `en_curso` o `finalizado`. Funciona sin señal
  /// (decisión 3 del plan sin señal): cada paso es una acción de la cola ([ColaAccionesNotifier]), con la hora
  /// en que se tocó.
  ///
  /// "Llegué", "Iniciar viaje" y "Finalizar" se ven al instante y salen por detrás: si el servidor los
  /// rechaza, el viaje vuelve a su estado real y el mensaje llega por [avisoAccionProvider]. "Voy en camino"
  /// espera la respuesta ([salirHaciaReserva]).
  Future<void> avanzar(EstadoViaje hacia) async {
    final actual = state.value?.viaje;
    if (actual == null || actual.estado.terminado) return;
    if (hacia == EstadoViaje.enCamino) return salirHaciaReserva(actual);
    final accion = _accion(actual.id, hacia);
    _bases.putIfAbsent(actual.id, () => actual);
    _aplicarViaje(actual.avanzadoA(hacia, accion.momento));
    await ref.read(colaAccionesProvider.notifier).agregar(accion);
  }

  /// Chofer: "Voy en camino" hacia [viaje] (también una reserva confirmada de la agenda, spec 5.4, paso 7):
  /// con la respuesta pasa a ser el viaje actual. Se espera la respuesta porque el servidor decide si ya se
  /// puede salir: el 422 ("Podés salir hacia esta reserva a partir de las 11:15.") o el 403 llegan a la
  /// pantalla. Sin señal avanza igual y la acción queda en la cola.
  Future<void> salirHaciaReserva(Viaje viaje) async {
    final accion = _accion(viaje.id, EstadoViaje.enCamino);
    // La respuesta ya se aplicó (accionEnviada).
    final respuesta = await ref.read(colaAccionesProvider.notifier).agregarYEsperar(accion);
    if (respuesta != null || !ref.mounted) return;
    _bases.putIfAbsent(viaje.id, () => viaje);
    _aplicarViaje(viaje.avanzadoA(EstadoViaje.enCamino, accion.momento));
  }

  AccionViaje _accion(int viajeId, EstadoViaje estado) => AccionViaje(
    id: nuevoIdAccion(),
    viajeId: viajeId,
    estado: estado,
    momento: ref.read(relojServidorProvider).ahora(),
  );

  /// Lo último que dijo el servidor de cada viaje con acciones sin enviar: a eso vuelve si las rechaza.
  final _bases = <int, Viaje>{};

  /// Las acciones del chofer que todavía no llegaron al servidor.
  List<AccionViaje> _pendientes() =>
      _usuario.esChofer ? ref.read(colaAccionesProvider).value ?? const <AccionViaje>[] : const <AccionViaje>[];

  /// La cola mandó una acción y esta es la respuesta. Si el chofer ya avanzó más (otra acción pendiente),
  /// la respuesta no lo hace retroceder (`_atrasado`).
  void accionEnviada(Viaje v) {
    if (!ref.mounted) return;
    if (_pendientes().any((a) => a.viajeId == v.id)) {
      _bases[v.id] = v;
    } else {
      _bases.remove(v.id);
    }
    _aplicarViaje(v);
  }

  /// El servidor rechazó una acción de [viajeId] (cancelado o reasignado mientras tanto, una hora que no
  /// vale…) y la cola descartó las de ese viaje: se vuelve a lo último que dijo el servidor y se consulta
  /// cómo está ahora. Nunca lanza.
  Future<void> accionRechazada(int viajeId) async {
    if (!ref.mounted) return;
    final base = _bases.remove(viajeId);
    final actual = state.value;
    if (base != null && actual != null && actual.viaje?.id == viajeId) {
      // El "finalizado" local no era final: si de verdad terminó, la consulta lo vuelve a marcar.
      _finales.remove(viajeId);
      _fijar(actual.conViaje(base));
    }
    await _refrescarSinFallar();
  }

  /// Chofer: acepta la oferta pendiente (spec 5.1). Con la respuesta queda el viaje y se va la oferta. Si
  /// ya no está vigente (422) también se va, y el error llega a la pantalla.
  Future<void> aceptarOferta() async {
    final oferta = state.value?.oferta;
    if (oferta == null) return;
    // Lo que llegue de este viaje (la respuesta o el evento "aceptado", en cualquier orden, aunque la oferta
    // ya haya vencido en pantalla) lo aceptó el chofer: no es un viaje asignado sin oferta.
    _aceptandoViajeId = oferta.viaje.id;
    try {
      final v = await ref.read(apiProvider).aceptarOferta(oferta.id);
      if (!ref.mounted) return;
      _aplicarViaje(v);
      // Ya es el viaje actual: un evento "aceptado" que llegue después lo encuentra. Si más adelante se lo
      // vuelven a asignar sin oferta (lo canceló y un administrador se lo da), sí es un viaje asignado.
      _aceptandoViajeId = null;
    } on ErrorApi catch (e) {
      _aceptandoViajeId = null;
      if (e is ErrorNegocio) _quitarOferta(oferta.id);
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
    _aceptandoViajeId = null;
    _fijar(const SeguimientoViaje());
    _seguir(null);
  }

  Future<SeguimientoViaje> _consultar(Viaje? previo) async {
    final api = ref.read(apiProvider);
    final actual = await api.viajeActual();
    // Un viaje que ya se vio cancelado o finalizado no vuelve a estar activo: la consulta atrasada se ignora.
    var viaje = actual.viaje != null && _revive(actual.viaje!) ? null : actual.viaje;

    // Chofer con acciones sin enviar: el servidor todavía no las conoce y se vuelven a aplicar encima. Si no
    // lo devuelve (p. ej. un "finalizado" local), sigue lo que ya se mostraba.
    if (!ref.mounted) return SeguimientoViaje(viaje: viaje, oferta: actual.oferta);
    final pendientes = _pendientes();
    if (viaje case final v? when pendientes.any((a) => a.viajeId == v.id)) {
      _bases[v.id] = v;
      viaje = _superponer(v, pendientes);
    } else if (viaje == null && previo != null && pendientes.any((a) => a.viajeId == previo.id)) {
      viaje = previo;
    }

    // `viajes/actual` no devuelve viajes terminados: si el que se seguía desapareció mientras no había
    // socket, se busca en el historial para mostrar cómo terminó (p. ej. sin_chofer).
    if (viaje == null && previo != null && !previo.estado.terminado && !_usuario.esChofer) {
      final historial = (await api.misViajes()).historial;
      viaje = historial.where((v) => v.id == previo.id).firstOrNull;
    }
    // El chofer lo busca por su id (el historial no trae viajes que ya no son suyos).
    if (viaje == null && previo != null && !previo.estado.terminado && _esSuyo(previo) && ref.mounted) {
      viaje = await _comoTermino(api, previo);
    }
    // Un viaje ya terminado (o, para el chofer, que ya no es suyo) se queda en pantalla hasta que el
    // usuario lo descarte.
    if (viaje == null && previo != null && (previo.estado.terminado || _yaNoEsSuyo(previo))) viaje = previo;
    _recordarFinal(viaje);
    // Descartado mientras se consultaba: quien llamó ya no usa el resultado (y el estado no se puede leer).
    if (!ref.mounted) return SeguimientoViaje(viaje: viaje, oferta: actual.oferta);

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

  /// [v] (del servidor) con las acciones pendientes de ese viaje aplicadas, en orden. Uno cancelado o
  /// reasignado en el servidor se muestra así: la cola va a recibir el rechazo.
  Viaje _superponer(Viaje v, List<AccionViaje> pendientes) {
    var resultado = v;
    for (final a in pendientes.where((a) => a.viajeId == v.id)) {
      if (resultado.estado.terminado || !_esSuyo(resultado)) break;
      if (_avance(a.estado) > _avance(resultado.estado)) resultado = resultado.avanzadoA(a.estado, a.momento);
    }
    return resultado;
  }

  /// Chofer: el viaje es suyo (lo tiene asignado).
  bool _esSuyo(Viaje v) => _usuario.esChofer && v.chofer?.id == _usuario.id;

  /// Chofer: el viaje que seguía se lo reasignaron a otro o volvió a buscar chofer.
  bool _yaNoEsSuyo(Viaje v) => _usuario.esChofer && v.chofer?.id != _usuario.id;

  /// Chofer: el viaje que seguía ya no está en `viajes/actual` (terminó o se lo sacaron mientras no había
  /// socket). Devuelve cómo quedó para mostrar su pantalla de fin, o `null` si no se puede saber (se vuelve
  /// al mapa, como sin esta consulta). Un 403 es "ya no es tuyo": el mismo viaje, sin chofer.
  Future<Viaje?> _comoTermino(ApiVehiculos api, Viaje previo) async {
    try {
      final v = await api.viaje(previo.id);
      if (_revive(v)) return null;
      return v.estado.terminado || _yaNoEsSuyo(v) ? v : null;
    } on SesionInvalida {
      rethrow;
    } on AccesoDenegado {
      return _sinChofer(previo);
    } on ErrorApi {
      return null;
    }
  }

  static Viaje _sinChofer(Viaje v) => Viaje(
    id: v.id,
    tipo: v.tipo,
    modo: v.modo,
    estado: v.estado,
    obligatorio: v.obligatorio,
    origen: v.origen,
    destino: v.destino,
    motivo: v.motivo,
    programadoPara: v.programadoPara,
    duracionEstimadaMin: v.duracionEstimadaMin,
    solicitante: v.solicitante,
    aceptadoEn: v.aceptadoEn,
    llegoEn: v.llegoEn,
    iniciadoEn: v.iniciadoEn,
    finalizadoEn: v.finalizadoEn,
    canceladoEn: v.canceladoEn,
  );

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

  static bool _esFinal(EstadoViaje e) => e == EstadoViaje.cancelado || e == EstadoViaje.finalizado;

  /// Un viaje que ya se vio final y ahora aparece en un estado activo: es una novedad atrasada.
  bool _revive(Viaje v) => !_esFinal(v.estado) && _finales.contains(v.id);

  void _recordarFinal(Viaje? v) {
    if (v == null || !_esFinal(v.estado)) return;
    _finales.remove(v.id); // reinsertado al final: se descarta el más viejo
    _finales.add(v.id);
    if (_finales.length > _maxFinales) _finales.remove(_finales.first);
  }

  void _aplicarViaje(Viaje v) {
    if (_revive(v)) return;
    _recordarFinal(v);
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
      v.id != _aceptandoViajeId &&
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
