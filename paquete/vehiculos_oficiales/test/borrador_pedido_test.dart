import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/solicitante/borrador_pedido.dart';

import 'soporte/dobles.dart';

void main() {
  late ProviderContainer c;
  BorradorPedidoNotifier notifier() => c.read(borradorPedidoProvider.notifier);
  BorradorPedido borrador() => c.read(borradorPedidoProvider);

  setUp(() => c = ProviderContainer.test());

  test('el primer toque marca el origen y el segundo el destino', () {
    notifier().marcar(const Coordenada(1, 1));
    expect(borrador().marcando, PuntoPedido.destino);
    notifier().marcar(const Coordenada(2, 2));

    expect(borrador().origen, const Coordenada(1, 1));
    expect(borrador().destino, const Coordenada(2, 2));
    expect(borrador().completo, isTrue);
  });

  test('sin chofer pide el más cercano; con chofer, a ese chofer; los textos vacíos no se mandan', () {
    notifier()
      ..fijar(PuntoPedido.origen, const Coordenada(1, 1))
      ..fijar(PuntoPedido.destino, const Coordenada(2, 2))
      ..direccion(PuntoPedido.destino, '  Tribunales ')
      ..motivo('   ');

    expect(borrador().pedido().toJson(), {
      'modo': 'mas_cercano',
      'origen_lat': 1.0,
      'origen_lng': 1.0,
      'destino_lat': 2.0,
      'destino_lng': 2.0,
      'destino_direccion': 'Tribunales',
    });

    final carlos = ChoferEnMapa(
      id: 2,
      nombre: 'Carlos',
      estado: EstadoChofer.libre,
      vehiculo: const Vehiculo(patente: 'A', marca: 'B', modelo: 'C'),
    );
    notifier().elegirChofer(carlos);
    expect(borrador().pedido().toJson()['modo'], 'especifico');
    expect(borrador().pedido().toJson()['chofer_id'], 2);

    notifier().elegirChofer(null);
    expect(borrador().chofer, isNull);
  });

  test('desde un viaje sin chofer copia origen, destino y motivo', () {
    notifier().desdeViaje(viaje(estado: 'sin_chofer'));

    expect(borrador().lugarOrigen!.descripcion, 'Plaza Independencia');
    expect(borrador().lugarDestino!.descripcion, 'Tribunales');
    expect(borrador().motivo, 'Audiencia');
    expect(borrador().chofer, isNull);
  });
}
