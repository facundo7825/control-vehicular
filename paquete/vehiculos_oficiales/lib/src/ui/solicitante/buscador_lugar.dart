import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../../solicitante/busqueda_lugares.dart';

/// Campo para escribir una dirección con sus sugerencias (`GET /lugares`). Lo elegido fija el punto para
/// el que se empezó a escribir (el destino, salvo que se esté cambiando el origen o falte y no haya
/// ubicación).
class BuscadorLugar extends ConsumerStatefulWidget {
  const BuscadorLugar({super.key});

  @override
  ConsumerState<BuscadorLugar> createState() => _BuscadorLugarState();
}

class _BuscadorLugarState extends ConsumerState<BuscadorLugar> {
  final _texto = TextEditingController();

  @override
  void dispose() {
    _texto.dispose();
    super.dispose();
  }

  void _limpiar() {
    _texto.clear();
    ref.read(busquedaLugaresProvider.notifier).limpiar();
  }

  void _elegir(LugarEncontrado lugar) {
    ref.read(borradorPedidoProvider.notifier).elegirLugar(lugar, punto: ref.read(busquedaLugaresProvider).punto);
    _limpiar();
    FocusScope.of(context).unfocus();
  }

  @override
  Widget build(BuildContext context) {
    // Si la búsqueda se borró desde afuera (la persona eligió marcar el otro punto), el campo también.
    ref.listen(busquedaLugaresProvider, (_, s) {
      if (s.texto.isEmpty && _texto.text.trim().isNotEmpty) _texto.clear();
    });
    final busqueda = ref.watch(busquedaLugaresProvider);
    final punto = busqueda.punto ?? ref.watch(borradorPedidoProvider.select((b) => b.marcando));

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const Key('buscar-lugar'),
          controller: _texto,
          inputFormatters: [LengthLimitingTextInputFormatter(BusquedaLugaresNotifier.maximo)],
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(
            labelText: punto == PuntoPedido.origen ? '¿Desde dónde salís?' : '¿A dónde vas?',
            hintText: 'Escribí una dirección o un lugar',
            prefixIcon: const Icon(Icons.search),
            suffixIcon: busqueda.texto.isEmpty
                ? null
                : IconButton(icon: const Icon(Icons.clear), tooltip: 'Borrar', onPressed: _limpiar),
          ),
          onChanged: ref.read(busquedaLugaresProvider.notifier).escribir,
        ),
        ...switch (busqueda.estado) {
          EstadoBusqueda.inactiva => const <Widget>[],
          EstadoBusqueda.buscando => const [ListTile(dense: true, title: Text('Buscando…'))],
          EstadoBusqueda.lista when busqueda.resultados.isEmpty => const [
            ListTile(dense: true, leading: Icon(Icons.search_off), title: Text('Sin resultados')),
          ],
          EstadoBusqueda.lista => [
            for (final l in busqueda.resultados)
              ListTile(
                dense: true,
                leading: const Icon(Icons.place_outlined),
                title: Text(l.nombre),
                subtitle: Text(l.direccion, maxLines: 1, overflow: TextOverflow.ellipsis),
                onTap: () => _elegir(l),
              ),
          ],
        },
      ],
    );
  }
}
