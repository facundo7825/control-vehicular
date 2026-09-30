import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../entorno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../../solicitante/choferes_mapa.dart';
import '../../ubicacion/ubicador.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Mapa principal del solicitante con los choferes en turno y el pedido (spec 7, solicitante 1 y 2).
class InicioSolicitante extends ConsumerWidget {
  const InicioSolicitante({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Al aparecer un viaje (el que había al abrir o uno recién pedido) se va a su pantalla.
    ref.listen(viajeActualProvider, (_, siguiente) {
      if (siguiente.value?.viaje != null) context.go(Rutas.viaje);
    });
    final hayViaje = ref.watch(viajeActualProvider).value?.viaje != null;
    final choferes = ref.watch(choferesMapaProvider).value ?? const <ChoferEnMapa>[];
    final borrador = ref.watch(borradorPedidoProvider);
    final mapa = ref.watch(constructorMapaProvider);
    final config = ref.watch(entornoProvider).config;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
      ),
      body: Column(
        children: [
          const BannerConexion(),
          if (hayViaje)
            MaterialBanner(
              content: const Text('Tenés un viaje en curso.'),
              actions: [TextButton(onPressed: () => context.go(Rutas.viaje), child: const Text('Ver'))],
            ),
          Expanded(
            child: mapa(
              context,
              DatosMapa(
                centro: borrador.origen ?? Coordenada(config.centroMapaLat, config.centroMapaLng),
                alTocarMapa: ref.read(borradorPedidoProvider.notifier).marcar,
                marcadores: [
                  for (final c in choferes)
                    if (c.posicion != null)
                      MarcadorMapa(
                        id: 'chofer-${c.id}',
                        posicion: c.posicion!,
                        tipo: c.seleccionable ? TipoMarcador.choferLibre : TipoMarcador.choferNoDisponible,
                        titulo: '${c.nombre} · ${c.estado.texto}',
                        alTocar: () => _mostrarChofer(context, ref, c),
                      ),
                  if (borrador.origen != null)
                    MarcadorMapa(id: 'origen', posicion: borrador.origen!, tipo: TipoMarcador.origen, titulo: 'Origen'),
                  if (borrador.destino != null)
                    MarcadorMapa(
                      id: 'destino',
                      posicion: borrador.destino!,
                      tipo: TipoMarcador.destino,
                      titulo: 'Destino',
                    ),
                ],
              ),
            ),
          ),
          if (!hayViaje) const _PanelPedido(),
        ],
      ),
    );
  }

  Future<void> _mostrarChofer(BuildContext context, WidgetRef ref, ChoferEnMapa c) => showModalBottomSheet<void>(
    context: context,
    builder: (hoja) => Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(c.nombre, style: Theme.of(hoja).textTheme.titleLarge),
          Text([c.vehiculo.descripcion, ?c.vehiculo.color].join(' · ')),
          const SizedBox(height: 8),
          Text(c.estado.texto),
          if (c.estado == EstadoChofer.reservadoPronto)
            const Text('Tiene una reserva en los próximos minutos: no se le pueden pedir viajes ahora.'),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: c.seleccionable
                ? () {
                    ref.read(borradorPedidoProvider.notifier).elegirChofer(c);
                    Navigator.pop(hoja);
                  }
                : null,
            child: const Text('Pedir a este chofer'),
          ),
        ],
      ),
    ),
  );
}

class _PanelPedido extends ConsumerStatefulWidget {
  const _PanelPedido();

  @override
  ConsumerState<_PanelPedido> createState() => _PanelPedidoState();
}

class _PanelPedidoState extends ConsumerState<_PanelPedido> {
  final _dirOrigen = TextEditingController();
  final _dirDestino = TextEditingController();
  final _motivo = TextEditingController();
  bool _enviando = false;

  @override
  void initState() {
    super.initState();
    _sincronizar(ref.read(borradorPedidoProvider));
  }

  @override
  void dispose() {
    _dirOrigen.dispose();
    _dirDestino.dispose();
    _motivo.dispose();
    super.dispose();
  }

  /// Los textos pueden cambiar desde afuera ("Elegir otro" los copia del viaje sin chofer).
  void _sincronizar(BorradorPedido b) {
    if (_dirOrigen.text != b.direccionOrigen) _dirOrigen.text = b.direccionOrigen;
    if (_dirDestino.text != b.direccionDestino) _dirDestino.text = b.direccionDestino;
    if (_motivo.text != b.motivo) _motivo.text = b.motivo;
  }

  Future<void> _usarMiUbicacion() async {
    final aqui = await ref.read(ubicadorProvider).actual();
    if (!mounted) return;
    if (aqui == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No pudimos obtener tu ubicación. Marcá el origen tocando el mapa.')),
      );
      return;
    }
    ref.read(borradorPedidoProvider.notifier).fijar(PuntoPedido.origen, aqui);
  }

  Future<void> _pedir() async {
    setState(() => _enviando = true);
    final borrador = ref.read(borradorPedidoProvider.notifier); // la pantalla se va apenas hay viaje
    try {
      await ref.read(viajeActualProvider.notifier).pedir(ref.read(borradorPedidoProvider).pedido());
      borrador.limpiar();
    } on Exception catch (e) {
      if (mounted) mostrarError(context, e);
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(borradorPedidoProvider, (_, b) => _sincronizar(b));
    final b = ref.watch(borradorPedidoProvider);
    final notifier = ref.read(borradorPedidoProvider.notifier);

    String descripcion(Coordenada? c, String direccion) => c == null
        ? 'Tocá el mapa para marcarlo'
        : direccion.trim().isNotEmpty
        ? direccion
        : Lugar(c).descripcion;

    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.5),
      child: Material(
        elevation: 8,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    ListTile(
                      leading: const Icon(Icons.trip_origin),
                      title: const Text('Origen'),
                      subtitle: Text(descripcion(b.origen, b.direccionOrigen)),
                      selected: b.marcando == PuntoPedido.origen,
                      onTap: () => notifier.marcarAhora(PuntoPedido.origen),
                      trailing: IconButton(
                        icon: const Icon(Icons.my_location),
                        tooltip: 'Usar mi ubicación',
                        onPressed: _usarMiUbicacion,
                      ),
                    ),
                    ListTile(
                      leading: const Icon(Icons.place),
                      title: const Text('Destino'),
                      subtitle: Text(descripcion(b.destino, b.direccionDestino)),
                      selected: b.marcando == PuntoPedido.destino,
                      onTap: () => notifier.marcarAhora(PuntoPedido.destino),
                    ),
                    ExpansionTile(
                      title: const Text('Direcciones y motivo (opcional)'),
                      children: [
                        TextField(
                          controller: _dirOrigen,
                          decoration: const InputDecoration(labelText: 'Dirección de origen'),
                          onChanged: (t) => notifier.direccion(PuntoPedido.origen, t),
                        ),
                        TextField(
                          controller: _dirDestino,
                          decoration: const InputDecoration(labelText: 'Dirección de destino'),
                          onChanged: (t) => notifier.direccion(PuntoPedido.destino, t),
                        ),
                        TextField(
                          controller: _motivo,
                          decoration: const InputDecoration(labelText: 'Motivo'),
                          onChanged: notifier.motivo,
                        ),
                      ],
                    ),
                    if (b.chofer != null)
                      Align(
                        alignment: Alignment.centerLeft,
                        child: InputChip(
                          label: Text('Chofer: ${b.chofer!.nombre}'),
                          onDeleted: () => notifier.elegirChofer(null),
                        ),
                      )
                    else
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 4),
                        child: Text('O tocá un chofer verde en el mapa para pedírselo a él.'),
                      ),
                  ],
                ),
              ),
            ),
            // Fuera del desplazamiento: el botón principal siempre queda a la vista.
            Padding(
              padding: const EdgeInsets.all(12),
              child: FilledButton(
                onPressed: b.completo && !_enviando ? _pedir : null,
                child: Text(b.chofer == null ? 'Pedir el más cercano' : 'Pedir a ${b.chofer!.nombre}'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
