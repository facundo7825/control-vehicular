import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/reloj_servidor.dart';
import '../modelos/modelos.dart';
import '../push/push_modulo.dart';
import '../sesion/sesion.dart';
import '../viaje/viaje_actual.dart';
import 'notificaciones_locales.dart';
import 'reproductor_sonidos.dart';

/// Avisos con sonido (y, con la app en segundo plano, notificación local) de las novedades del viaje
/// actual. Se dispara con cada transición del viaje (el mismo viaje, de un estado a otro): nunca al cargar
/// un viaje que ya estaba en ese estado, ni con un evento repetido o atrasado (`ViajeActualNotifier` ya los
/// descarta). Lo arranca la raíz del módulo con la sesión lista, para los dos roles.
final avisosViajeProvider = NotifierProvider<AvisosViaje, void>(AvisosViaje.new);

class AvisosViaje extends Notifier<void> {
  static const intervaloVibracion = Duration(seconds: 2);

  /// Id fijo de la notificación de la oferta: una oferta nueva la reemplaza y se quita al cortar el timbre.
  static const idNotificacionOferta = -1;

  /// Oferta cuyo timbre suena en bucle (nula si no suena ninguno).
  int? _ofertaSonando;
  ReproductorSonidos? _bucle;
  Timer? _vibracion;
  Timer? _vencimiento;

  /// Las notificaciones donde salió la oferta que suena (nulas si salió en primer plano, sin notificación).
  NotificacionesLocales? _notificacionOferta;

  /// Ofertas que el chofer ya respondió (o empezó a responder): no vuelven a sonar aunque una consulta,
  /// un push o la reconexión las traigan otra vez, ni si la respuesta falla. Se olvidan cuando la oferta se va.
  final _silenciadas = <int>{};

  /// Solicitante: reservas cuya aceptación ya se avisó (por push o por "Mis viajes").
  final _reservasAvisadas = <int>{};

  /// Solicitante: estado de cada próxima reserva en la última carga de "Mis viajes" (nulo: todavía no se cargó).
  Map<int, EstadoViaje>? _reservasVistas;

  late Usuario _usuario;

  @override
  void build() {
    _usuario = ref.watch(usuarioProvider);
    ref.onDispose(_cortarBucle);
    ref.listen(viajeActualProvider, (antes, ahora) => _alCambiar(antes?.value, ahora.value));
    final escucha = ref.watch(pushModuloProvider).avisos.listen(_usuario.esChofer ? _alPushChofer : _alPush);
    ref.onDispose(() => unawaited(escucha.cancel()));
  }

  /// Chofer: el fichaje abrió o cerró el turno (o dejó o anuló su cierre pendiente). En primer plano la
  /// pantalla cambia sola; en segundo plano sale la notificación, con los textos del push del backend (sin
  /// sonido: no es algo que haya que atender ya). Un estado desconocido no notifica (el turno igual se refresca).
  void _alPushChofer(AvisoPush aviso) {
    if (aviso.tipo != 'turno') return;
    final (titulo, texto) = switch (aviso.estado) {
      'abierto' => ('Tu turno empezó', 'Abrí la app para compartir tu ubicación'),
      'sin_vehiculo' => ('Fichaste la entrada', 'Abrí la app y elegí el vehículo para empezar el turno'),
      'cerrado' => ('Tu turno terminó', 'Se registró tu salida.'),
      'cierre_pendiente' => ('Fichaste la salida', 'Tu turno se cierra al terminar el viaje.'),
      'cierre_cancelado' => ('Seguís de turno', 'Fichaste la entrada: se anuló el cierre del turno.'),
      _ => (null, null),
    };
    if (titulo != null && texto != null) _notificar(titulo, texto);
  }

  /// Chofer: tocó "Aceptar" o "Rechazar" en la oferta [ofertaId]; el timbre se corta ya, sin esperar la
  /// respuesta. Si ya suena otra oferta, no la toca.
  void silenciarOferta(int ofertaId) {
    _silenciadas.add(ofertaId);
    if (_ofertaSonando == ofertaId) _cortarBucle();
  }

  /// Solicitante: "Mis viajes" se cargó. Una reserva que en la carga anterior estaba pendiente y ahora está
  /// aceptada suena (una vez, junto con el push). La primera carga solo se recuerda.
  void alCargarMisViajes(MisViajes mis) {
    if (_usuario.esChofer) return;
    final reservas = {
      for (final v in mis.proximas)
        if (v.tipo == TipoViaje.reserva) v.id: v.estado,
    };
    final antes = _reservasVistas;
    _reservasVistas = reservas;
    if (antes == null) return;
    for (final MapEntry(key: id, value: estado) in reservas.entries) {
      final previo = antes[id];
      final pendiente = previo != null && (previo.buscandoChofer || previo == EstadoViaje.sinChofer);
      if (pendiente && estado == EstadoViaje.aceptado) _avisarReserva(id);
    }
  }

  void _alCambiar(SeguimientoViaje? antes, SeguimientoViaje? ahora) {
    if (ahora == null) return;
    if (_usuario.esChofer) {
      _ofertas(ahora.oferta);
      if (ahora.asignadoSinOferta && !(antes?.asignadoSinOferta == true && antes?.viaje?.id == ahora.viaje?.id)) {
        _avisar(Sonido.oferta, 'Viaje asignado', 'Tenés un viaje asignado. Tocá para verlo.');
      }
    }
    final previo = antes?.viaje;
    final viaje = ahora.viaje;
    // Solo transiciones del mismo viaje: un viaje que aparece (carga inicial, pedido nuevo) no suena.
    if (previo == null || viaje == null || previo.id != viaje.id || previo.estado == viaje.estado) return;
    _transicion(previo.estado, viaje);
  }

  void _transicion(EstadoViaje antes, Viaje viaje) {
    final propio = ref.read(viajeActualProvider.notifier).canceladoPorMi(viaje.id);
    switch (viaje.estado) {
      case EstadoViaje.cancelado when !propio:
        _avisar(
          Sonido.cancelado,
          'Viaje cancelado',
          _usuario.esChofer ? 'Te cancelaron el viaje.' : 'Tu viaje fue cancelado.',
        );
      case EstadoViaje.sinChofer when !_usuario.esChofer:
        _avisar(Sonido.cancelado, 'Viaje cancelado', 'No hay choferes disponibles. Podés volver a pedirlo.');
      // Inmediato: el chofer canceló y el viaje vuelve a buscar otro.
      case EstadoViaje.buscando || EstadoViaje.ofrecido when !_usuario.esChofer && antes.conChofer:
        _avisar(Sonido.cancelado, 'Tu chofer canceló', 'Estamos buscando otro chofer.');
      case EstadoViaje.aceptado || EstadoViaje.enCamino when !_usuario.esChofer && !antes.conChofer:
        _avisar(Sonido.aceptado, 'Tu viaje fue aceptado', _conChofer(viaje));
      case EstadoViaje.llego when !_usuario.esChofer:
        _avisar(Sonido.llego, 'El chofer llegó', 'Te está esperando en ${viaje.origen.descripcion}.');
      default:
        break;
    }
  }

  static String _conChofer(Viaje v) => v.chofer == null ? 'Ya tenés chofer.' : 'Te busca ${v.chofer!.nombre}.';

  /// Solicitante: una reserva aceptada (llega por push: la reserva no es el viaje actual hasta que arranca).
  void _alPush(AvisoPush aviso) {
    final id = aviso.viajeId;
    if (aviso.tipo != 'viaje' || aviso.estado != EstadoViaje.aceptado.valor || id == null) return;
    // El viaje actual se avisa con su transición (el push solo lo hace refrescar).
    if (ref.read(viajeActualProvider).value?.viaje?.id == id) return;
    _avisarReserva(id);
  }

  void _avisarReserva(int id) {
    if (!_reservasAvisadas.add(id)) return;
    _avisar(Sonido.aceptado, 'Tu reserva fue aceptada', 'Ya tenés chofer para la reserva.');
  }

  /// Chofer: una oferta nueva suena en bucle, con vibración cada 2 s, hasta que se responde, vence o se va.
  void _ofertas(Oferta? oferta) {
    _silenciadas.removeWhere((id) => id != oferta?.id);
    if (oferta?.id == _ofertaSonando) return;
    _cortarBucle();
    if (oferta == null || _silenciadas.contains(oferta.id)) return;
    final restante = ref.read(relojServidorProvider).restante(oferta.venceEn);
    if (restante <= Duration.zero) return;

    _ofertaSonando = oferta.id;
    final r = ref.read(reproductorSonidosProvider);
    _bucle = r;
    unawaited(r.repetir(Sonido.oferta));
    unawaited(r.vibrar());
    _vibracion = Timer.periodic(intervaloVibracion, (_) => unawaited(r.vibrar()));
    // Por si nadie la da por vencida (sin la pantalla de la oferta, p. ej. en segundo plano).
    _vencimiento = Timer(restante, _cortarBucle);
    _notificacionOferta = _notificar(
      'Nuevo viaje ofrecido',
      'Hacia ${oferta.viaje.destino.descripcion}. Respondé antes de que venza.',
      id: idNotificacionOferta,
    );
  }

  void _cortarBucle() {
    _vibracion?.cancel();
    _vencimiento?.cancel();
    _vibracion = null;
    _vencimiento = null;
    _ofertaSonando = null;
    // El mismo reproductor que lo arrancó (en onDispose no se puede leer otro provider).
    final r = _bucle;
    _bucle = null;
    if (r != null) unawaited(r.detenerBucle());
    // La oferta ya no se puede responder: su notificación tampoco queda a la vista.
    final n = _notificacionOferta;
    _notificacionOferta = null;
    if (n != null) unawaited(n.cancelar(idNotificacionOferta));
  }

  void _avisar(Sonido sonido, String titulo, String texto) {
    unawaited(ref.read(reproductorSonidosProvider).reproducir(sonido));
    _notificar(titulo, texto);
  }

  /// La notificación sale solo con la app en segundo plano (`paused`, `hidden` o `detached`). `inactive` es
  /// primer plano: la app se ve, tapada un momento (panel de notificaciones, diálogo del sistema, llamada).
  /// Devuelve dónde se mostró (nulo si no salió).
  NotificacionesLocales? _notificar(String titulo, String texto, {int? id}) {
    final ciclo = WidgetsBinding.instance.lifecycleState;
    if (ciclo == null || ciclo == AppLifecycleState.resumed || ciclo == AppLifecycleState.inactive) return null;
    final n = ref.read(notificacionesLocalesProvider);
    unawaited(n.mostrar(titulo: titulo, texto: texto, id: id));
    return n;
  }
}
