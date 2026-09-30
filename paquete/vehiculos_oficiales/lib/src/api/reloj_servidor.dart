import 'package:clock/clock.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import 'cliente_api.dart';

/// Hora del servidor estimada con el desfase de [ClienteApi.desfaseReloj]: un reloj del
/// teléfono adelantado o atrasado no cambia lo que falta para un `vence_en` del backend.
class RelojServidor {
  RelojServidor(this._cliente);

  final ClienteApi _cliente;

  DateTime ahora() => clock.now().toUtc().add(_cliente.desfaseReloj);

  /// Lo que falta para [vence] según el servidor; nunca negativo.
  Duration restante(DateTime vence) {
    final falta = vence.difference(ahora());
    return falta.isNegative ? Duration.zero : falta;
  }
}

/// Con el mismo cliente que [apiProvider] (en los tests, el de la API falsa).
final relojServidorProvider = Provider<RelojServidor>((ref) => RelojServidor(ref.watch(apiProvider).cliente));
