import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../viaje/viaje_actual.dart';

/// `data` de un push del módulo (NotificadorFcm: todo string, con `modulo = vehiculos_oficiales`).
class AvisoPush {
  const AvisoPush({required this.tipo, this.viajeId, this.ofertaId, this.estado});

  static const modulo = 'vehiculos_oficiales';

  /// `oferta`, `oferta_reserva`, `viaje`, `recordatorio_reserva` o `alerta_reserva` (AvisosViaje y jobs).
  final String tipo;
  final int? viajeId;
  final int? ofertaId;
  final String? estado;

  /// Nulo si el mensaje no es de este módulo.
  static AvisoPush? desde(Map<String, dynamic> data) {
    if (data['modulo']?.toString() != modulo || data['tipo'] == null) return null;
    int? entero(String clave) => int.tryParse(data[clave]?.toString() ?? '');
    return AvisoPush(
      tipo: data['tipo'].toString(),
      viajeId: entero('viaje_id'),
      ofertaId: entero('oferta_id'),
      estado: data['estado']?.toString(),
    );
  }
}

/// Registra el token push y reacciona a los mensajes que reenvía la app principal (spec 12.3).
class PushModulo {
  PushModulo(this._ref);

  final Ref _ref;
  final _avisos = StreamController<AvisoPush>.broadcast();
  StreamSubscription<Map<String, dynamic>>? _escucha;

  /// Avisos del módulo, para que otras pantallas (mis viajes, agenda) se refresquen.
  Stream<AvisoPush> get avisos => _avisos.stream;

  Future<void> iniciar() async {
    final puente = _ref.read(entornoProvider).push;
    // El puente es código de la app principal: nada de lo que haga mal debe romper el módulo.
    try {
      _escucha = puente.mensajes.listen(
        _alRecibir,
        onError: (Object e) => debugPrint('vehiculos_oficiales: error en los mensajes push (${e.runtimeType}).'),
      );
    } catch (e) {
      // P. ej. un stream de una sola escucha que ya escuchó una apertura anterior del módulo.
      debugPrint('vehiculos_oficiales: no se pudo escuchar los mensajes push (${e.runtimeType}).');
    }
    try {
      final token = await puente.token();
      if (token != null && token.isNotEmpty) await _ref.read(apiProvider).registrarTokenPush(token);
    } on ErrorApi catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo registrar el token push: $e');
    } catch (e) {
      // P. ej. FCM en iOS sin token APNs. No se imprime el error completo: podría traer el token.
      debugPrint('vehiculos_oficiales: no se pudo obtener el token push (${e.runtimeType}).');
    }
  }

  void _alRecibir(Map<String, dynamic> data) {
    final aviso = AvisoPush.desde(data);
    if (aviso == null) return;
    if (aviso.tipo == 'viaje' || aviso.tipo == 'oferta') {
      // El push puede llegar antes que el evento del socket (o sin socket): se pide el estado a la API.
      unawaited(_ref.read(viajeActualProvider.notifier).refrescar());
    }
    _avisos.add(aviso);
  }

  void cerrar() {
    unawaited(_escucha?.cancel());
    unawaited(_avisos.close());
  }
}

/// Se activa al leerlo desde la pantalla raíz con sesión lista.
final pushModuloProvider = Provider<PushModulo>((ref) {
  final push = PushModulo(ref);
  ref.onDispose(push.cerrar);
  unawaited(push.iniciar());
  return push;
});
