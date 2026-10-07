import 'package:clock/clock.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import 'cliente_api.dart';

/// Hora del servidor: el último `Date` que mandó más lo transcurrido desde esa respuesta con un cronómetro
/// monotónico ([ClienteApi.horaServidor]); antes de la primera, la del teléfono con [ClienteApi.desfaseReloj].
/// Un reloj del teléfono adelantado, atrasado o que cambia mientras no hay señal no cambia lo que falta para un
/// `vence_en` del backend ni el `momento` de una acción del chofer.
class RelojServidor {
  RelojServidor(this._cliente);

  final ClienteApi _cliente;

  DateTime ahora() => _cliente.horaServidor() ?? clock.now().toUtc().add(_cliente.desfaseReloj);

  /// Lo que falta para [vence] según el servidor; nunca negativo.
  Duration restante(DateTime vence) {
    final falta = vence.difference(ahora());
    return falta.isNegative ? Duration.zero : falta;
  }
}

/// Con el mismo cliente que [apiProvider] (en los tests, el de la API falsa).
final relojServidorProvider = Provider<RelojServidor>((ref) => RelojServidor(ref.watch(apiProvider).cliente));
