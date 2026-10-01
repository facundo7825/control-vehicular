import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../entorno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../../solicitante/busqueda_lugares.dart';
import '../../solicitante/choferes_mapa.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Mapa principal del solicitante con los choferes en turno y el pedido (spec 7, solicitante 1 y 2).
/// Al abrir, el origen es la ubicación actual y el mapa se centra ahí; sin permiso se marca a mano
/// (spec 9).
class InicioSolicitante extends ConsumerStatefulWidget {
  const InicioSolicitante({super.key});

  @override
  ConsumerState<InicioSolicitante> createState() => _InicioSolicitanteState();
}

class _InicioSolicitanteState extends ConsumerState<InicioSolicitante> {
  @override
  void initState() {
    super.initState();
    final b = ref.read(borradorPedidoProvider);
    // Un origen elegido a mano ("Elegir otro") se conserva, y el mapa sigue mirando ese pedido.
    unawaited(ref.read(borradorPedidoProvider.notifier).ubicar(centrar: b.origen == null || b.origenEsMiUbicacion));
  }

  Future<void> _miUbicacion() async {
    final ok = await ref.read(borradorPedidoProvider.notifier).ubicar();
    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('No pudimos obtener tu ubicación.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    // Al aparecer un viaje (el que había al abrir o uno recién pedido) se va a su pantalla. Solo cuando
    // aparece o cambia de viaje: las novedades del mismo (posición, estado) no deshacen el "atrás".
    ref.listen(viajeActualProvider, (anterior, siguiente) {
      final id = siguiente.value?.viaje?.id;
      if (id != null && id != anterior?.value?.viaje?.id) context.go(Rutas.viaje);
    });
    final hayViaje = ref.watch(viajeActualProvider).value?.viaje != null;
    final choferes = ref.watch(choferesMapaProvider).value ?? const <ChoferEnMapa>[];
    final borrador = ref.watch(borradorPedidoProvider);
    final mapa = ref.watch(constructorMapaProvider);
    final config = ref.watch(entornoProvider).config;
    // Con el teclado abierto el panel puede ocupar más: lo importante es el campo y sus sugerencias.
    final teclado = MediaQuery.viewInsetsOf(context).bottom > 0;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.history),
            tooltip: 'Mis viajes',
            onPressed: () => context.push(Rutas.misViajes),
          ),
        ],
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
            child: LayoutBuilder(
              builder: (context, espacio) => Column(
                children: [
                  Expanded(
                    child: Stack(
                      children: [
                        Positioned.fill(
                          child: mapa(
                            context,
                            DatosMapa(
                              centro: borrador.origen ?? Coordenada(config.centroMapaLat, config.centroMapaLng),
                              enfoque: borrador.enfoque,
                              alTocarMapa: ref.read(borradorPedidoProvider.notifier).marcar,
                              marcadores: [
                                for (final c in choferes)
                                  if (c.posicion != null)
                                    MarcadorMapa(
                                      id: 'chofer-${c.id}',
                                      posicion: c.posicion!,
                                      tipo: c.seleccionable
                                          ? TipoMarcador.choferLibre
                                          : TipoMarcador.choferNoDisponible,
                                      titulo: '${c.nombre} · ${c.estado.texto}',
                                      alTocar: () => _mostrarChofer(context, c),
                                    ),
                                if (borrador.origen != null)
                                  MarcadorMapa(
                                    id: 'origen',
                                    posicion: borrador.origen!,
                                    tipo: TipoMarcador.origen,
                                    titulo: 'Origen',
                                  ),
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
                        Positioned(right: 12, bottom: 12, child: BotonMiUbicacion(alTocar: _miUbicacion)),
                      ],
                    ),
                  ),
                  if (!hayViaje)
                    ConstrainedBox(
                      constraints: BoxConstraints(maxHeight: espacio.maxHeight * (teclado ? 0.75 : 0.55)),
                      child: _PanelPedido(conBotones: !teclado),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _mostrarChofer(BuildContext context, ChoferEnMapa c) => showModalBottomSheet<void>(
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
  const _PanelPedido({required this.conBotones});

  /// "Pedir" y "Reservar"; se ocultan mientras se escribe con el teclado abierto.
  final bool conBotones;

  @override
  ConsumerState<_PanelPedido> createState() => _PanelPedidoState();
}

class _PanelPedidoState extends ConsumerState<_PanelPedido> {
  final _buscar = TextEditingController();
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
    _buscar.dispose();
    _dirOrigen.dispose();
    _dirDestino.dispose();
    _motivo.dispose();
    super.dispose();
  }

  /// Los textos pueden cambiar desde afuera ("Elegir otro" los copia del viaje sin chofer; una sugerencia
  /// elegida fija la dirección).
  void _sincronizar(BorradorPedido b) {
    if (_dirOrigen.text != b.direccionOrigen) _dirOrigen.text = b.direccionOrigen;
    if (_dirDestino.text != b.direccionDestino) _dirDestino.text = b.direccionDestino;
    if (_motivo.text != b.motivo) _motivo.text = b.motivo;
  }

  void _limpiarBusqueda() {
    _buscar.clear();
    ref.read(busquedaLugaresProvider.notifier).limpiar();
  }

  void _elegir(LugarEncontrado lugar) {
    ref.read(borradorPedidoProvider.notifier).elegirLugar(lugar);
    _limpiarBusqueda();
    FocusScope.of(context).unfocus();
  }

  Future<void> _usarMiUbicacion() async {
    final ok = await ref.read(borradorPedidoProvider.notifier).ubicar(comoOrigen: true);
    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No pudimos obtener tu ubicación. Marcá el origen tocando el mapa.')),
      );
    }
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
    ref.listen(borradorPedidoProvider, (anterior, b) {
      _sincronizar(b);
      // Lo escrito buscaba el otro punto ("Cambiar origen" a mitad de la búsqueda del destino).
      if (anterior?.marcando != b.marcando) _limpiarBusqueda();
    });
    final b = ref.watch(borradorPedidoProvider);
    final busqueda = ref.watch(busquedaLugaresProvider);
    final notifier = ref.read(borradorPedidoProvider.notifier);
    final buscandoOrigen = b.marcando == PuntoPedido.origen;

    final sinOrigen = switch (b.ubicacion) {
      EstadoUbicacion.buscando => 'Buscando tu ubicación…',
      EstadoUbicacion.noDisponible => 'No pudimos obtener tu ubicación: tocá el mapa o buscá una dirección.',
      EstadoUbicacion.obtenida => 'Tocá el mapa o buscá una dirección',
    };

    return Material(
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
                  // Arriba de todo: con el teclado abierto, el campo y las sugerencias quedan a la vista.
                  TextField(
                    key: const Key('buscar-lugar'),
                    controller: _buscar,
                    inputFormatters: [LengthLimitingTextInputFormatter(BusquedaLugaresNotifier.maximo)],
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      labelText: buscandoOrigen ? '¿Desde dónde salís?' : '¿A dónde vas?',
                      hintText: 'Escribí una dirección o un lugar',
                      prefixIcon: const Icon(Icons.search),
                      suffixIcon: busqueda.texto.isEmpty
                          ? null
                          : IconButton(icon: const Icon(Icons.clear), tooltip: 'Borrar', onPressed: _limpiarBusqueda),
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
                  ListTile(
                    leading: const Icon(Icons.trip_origin),
                    title: const Text('Origen'),
                    subtitle: Text(b.descripcion(PuntoPedido.origen) ?? sinOrigen),
                    selected: buscandoOrigen,
                    onTap: () => notifier.marcarAhora(PuntoPedido.origen),
                    trailing: b.origenEsMiUbicacion && !buscandoOrigen
                        ? IconButton(
                            icon: const Icon(Icons.edit_location_alt),
                            tooltip: 'Cambiar origen',
                            onPressed: () => notifier.marcarAhora(PuntoPedido.origen),
                          )
                        : IconButton(
                            icon: const Icon(Icons.my_location),
                            tooltip: 'Usar mi ubicación',
                            onPressed: _usarMiUbicacion,
                          ),
                  ),
                  ListTile(
                    leading: const Icon(Icons.place),
                    title: const Text('Destino'),
                    subtitle: Text(b.descripcion(PuntoPedido.destino) ?? 'Escribilo arriba o tocá el mapa'),
                    selected: !buscandoOrigen,
                    onTap: () => notifier.marcarAhora(PuntoPedido.destino),
                  ),
                  ExpansionTile(
                    title: const Text('Direcciones y motivo (opcional)'),
                    children: [
                      TextField(
                        controller: _dirOrigen,
                        maxLength: largoMaximoDireccion, // límite del backend (ViajeController y ReservaController)
                        decoration: const InputDecoration(labelText: 'Dirección de origen'),
                        onChanged: (t) => notifier.direccion(PuntoPedido.origen, t),
                      ),
                      TextField(
                        controller: _dirDestino,
                        maxLength: largoMaximoDireccion,
                        decoration: const InputDecoration(labelText: 'Dirección de destino'),
                        onChanged: (t) => notifier.direccion(PuntoPedido.destino, t),
                      ),
                      TextField(
                        controller: _motivo,
                        maxLength: 255,
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
                  if (!widget.conBotones) const SizedBox(height: 12),
                ],
              ),
            ),
          ),
          // Fuera del desplazamiento: los botones siempre quedan a la vista (salvo mientras se escribe).
          if (widget.conBotones) ...[
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
              child: FilledButton(
                onPressed: b.completo && !_enviando ? _pedir : null,
                child: Text(b.chofer == null ? 'Pedir el más cercano' : 'Pedir a ${b.chofer!.nombre}'),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: TextButton(
                onPressed: b.completo && !_enviando ? () => context.push(Rutas.reservar) : null,
                child: const Text('Reservar para más tarde'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
