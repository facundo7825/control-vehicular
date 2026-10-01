import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../chofer/turno.dart' show configuracionProvider;
import '../entorno.dart';
import '../modelos/modelos.dart';
import 'borrador_pedido.dart';

/// `inactiva`: menos de [BusquedaLugaresNotifier.minimo] letras o, sin autocompletar, todavía no se pidió
/// buscar; `buscando`: esperando que deje de
/// escribir o la respuesta; `lista`: llegaron los [BusquedaLugares.resultados] (vacíos = "Sin resultados").
enum EstadoBusqueda { inactiva, buscando, lista }

class BusquedaLugares {
  const BusquedaLugares({
    this.texto = '',
    this.estado = EstadoBusqueda.inactiva,
    this.resultados = const [],
    this.punto,
  });

  final String texto;

  /// Para qué punto del pedido se empezó a escribir; nulo con el campo vacío. Si después cambia lo que se
  /// marca (p. ej. la ubicación no llegó), lo escrito sigue siendo para este punto.
  final PuntoPedido? punto;
  final EstadoBusqueda estado;
  final List<LugarEncontrado> resultados;
}

final busquedaLugaresProvider = NotifierProvider.autoDispose<BusquedaLugaresNotifier, BusquedaLugares>(
  BusquedaLugaresNotifier.new,
);

/// Sugerencias para el campo de dirección del solicitante (`GET /lugares`), sesgadas a su ubicación.
/// Si el backend lo permite (`Configuracion.lugaresAutocompletar`), consulta una sola vez cuando deja de
/// escribir durante [espera]; si no (Nominatim, o la configuración no llegó), solo con [buscarAhora].
class BusquedaLugaresNotifier extends Notifier<BusquedaLugares> {
  static const espera = Duration(milliseconds: 400);

  /// Lo mínimo que acepta `LugaresController`.
  static const minimo = 3;

  /// Lo máximo que acepta `LugaresController`.
  static const maximo = 200;

  Timer? _espera;

  @override
  BusquedaLugares build() {
    ref.onDispose(() => _espera?.cancel());
    return const BusquedaLugares();
  }

  void escribir(String texto) {
    _espera?.cancel();
    final t = texto.trim();
    final punto = t.isEmpty ? null : state.punto ?? ref.read(borradorPedidoProvider).marcando;
    if (t.length < minimo || !_autocompletar) {
      state = BusquedaLugares(texto: t, punto: punto);
      return;
    }
    state = BusquedaLugares(texto: t, estado: EstadoBusqueda.buscando, punto: punto);
    _espera = Timer(espera, () => _buscar(t));
  }

  /// Busca ya lo escrito (tecla "buscar" del teclado o el botón del campo), sin esperar.
  void buscarAhora() {
    _espera?.cancel();
    final t = state.texto;
    if (t.length < minimo) return;
    state = BusquedaLugares(texto: t, estado: EstadoBusqueda.buscando, punto: state.punto);
    unawaited(_buscar(t));
  }

  bool get _autocompletar => ref.read(configuracionProvider).value?.lugaresAutocompletar ?? false;

  void limpiar() {
    _espera?.cancel();
    state = const BusquedaLugares();
  }

  Future<void> _buscar(String texto) async {
    final cerca = ref.read(borradorPedidoProvider).miUbicacion;
    List<LugarEncontrado> resultados;
    try {
      resultados = await ref.read(apiProvider).buscarLugares(texto, cerca: cerca);
    } on Exception catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo buscar el lugar (${e.runtimeType}).');
      resultados = const [];
    }
    if (!ref.mounted || state.texto != texto || state.estado != EstadoBusqueda.buscando) return;
    state = BusquedaLugares(texto: texto, estado: EstadoBusqueda.lista, resultados: resultados, punto: state.punto);
  }
}
