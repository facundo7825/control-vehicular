import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

import '../modelos/comunes.dart';
import '../ui/comunes/comunes.dart';
import 'mapa.dart';

/// Mapa de OpenStreetMap (flutter_map), sin clave. Se usa cuando la app no configuró una clave de
/// Google Maps: sirve para desarrollo y demos, no para producción (los servidores públicos de teselas
/// de OpenStreetMap no admiten tráfico de producción).
class MapaOsm extends ConsumerStatefulWidget {
  const MapaOsm({super.key, required this.datos, this.teselas});

  final DatosMapa datos;

  /// De dónde salen las teselas. Nulo = de la red; los tests pasan uno que no la usa.
  final TileProvider? teselas;

  static const urlTeselas = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

  /// Identifica a la app ante los servidores de teselas, como pide su política de uso.
  static const agenteUsuario = 'ar.gob.pj.vehiculos_oficiales';

  static final _derechos = Uri.parse('https://www.openstreetmap.org/copyright');

  /// Los mismos tonos que los marcadores de [MapaGoogle].
  static Color colorDe(TipoMarcador t) => switch (t) {
    TipoMarcador.choferLibre => Colors.green,
    TipoMarcador.choferNoDisponible => Colors.amber,
    TipoMarcador.choferAsignado => Colors.lightBlue,
    TipoMarcador.origen => Colors.orange,
    TipoMarcador.destino => Colors.red,
  };

  static const _tamano = 40.0;

  static LatLng _latLng(Coordenada c) => LatLng(c.lat, c.lng);

  /// Encuadre de varios puntos (los de un solo punto se centran con [MapController.move]).
  static CameraFit _encuadre(Enfoque enfoque) => CameraFit.coordinates(
    coordinates: [for (final p in enfoque.puntos) _latLng(p)],
    padding: const EdgeInsets.all(Enfoque.margen),
    maxZoom: Enfoque.zoomPunto,
  );

  @override
  ConsumerState<MapaOsm> createState() => _MapaOsmState();

  /// Sin navegador (o si el sistema rechaza abrirla) no pasa nada: es solo la página de créditos.
  static Future<void> _abrirDerechos(LanzadorUrl lanzar) async {
    try {
      await lanzar(_derechos);
    } catch (e) {
      debugPrint('No se pudo abrir los créditos de OpenStreetMap (${e.runtimeType}).');
    }
  }
}

class _MapaOsmState extends ConsumerState<MapaOsm> {
  final _controlador = MapController();
  final _seguidor = SeguidorEnfoque();

  /// El enfoque con que nace el mapa: se aplica como cámara inicial.
  late final Enfoque? _inicial;

  @override
  void initState() {
    super.initState();
    _inicial = _seguidor.aMover(widget.datos.enfoque);
  }

  @override
  void didUpdateWidget(MapaOsm anterior) {
    super.didUpdateWidget(anterior);
    final enfoque = _seguidor.aMover(widget.datos.enfoque);
    if (enfoque == null) return;
    final punto = enfoque.unico;
    if (punto != null) {
      _controlador.move(MapaOsm._latLng(punto), Enfoque.zoomPunto);
    } else {
      _controlador.fitCamera(MapaOsm._encuadre(enfoque));
    }
  }

  @override
  void dispose() {
    _controlador.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final datos = widget.datos;
    final alTocar = datos.alTocarMapa;
    final inicial = _inicial;
    final puntoInicial = inicial?.unico;
    return FlutterMap(
      mapController: _controlador,
      options: MapOptions(
        initialCenter: MapaOsm._latLng(puntoInicial ?? datos.centro),
        initialZoom: puntoInicial == null ? 14 : Enfoque.zoomPunto,
        initialCameraFit: inicial != null && puntoInicial == null ? MapaOsm._encuadre(inicial) : null,
        onTap: alTocar == null ? null : (_, p) => alTocar(Coordenada(p.latitude, p.longitude)),
      ),
      children: [
        TileLayer(
          urlTemplate: MapaOsm.urlTeselas,
          userAgentPackageName: MapaOsm.agenteUsuario,
          tileProvider: widget.teselas,
        ),
        MarkerLayer(
          markers: [
            for (final m in datos.marcadores)
              Marker(
                point: LatLng(m.posicion.lat, m.posicion.lng),
                width: MapaOsm._tamano,
                height: MapaOsm._tamano,
                // La punta del ícono queda sobre la posición.
                alignment: Alignment.topCenter,
                child: _Marcador(key: Key('marcador-${m.id}'), marcador: m),
              ),
          ],
        ),
        SimpleAttributionWidget(
          source: const Text('OpenStreetMap contributors'),
          onTap: () => MapaOsm._abrirDerechos(ref.read(lanzadorUrlProvider)),
        ),
      ],
    );
  }
}

class _Marcador extends StatelessWidget {
  const _Marcador({super.key, required this.marcador});

  final MarcadorMapa marcador;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: marcador.titulo,
      child: GestureDetector(
        onTap: marcador.alTocar,
        child: Opacity(
          // Como en MapaGoogle: los no disponibles van desvaídos.
          opacity: marcador.tipo == TipoMarcador.choferNoDisponible ? 0.45 : 1,
          child: Icon(Icons.location_on, size: MapaOsm._tamano, color: MapaOsm.colorDe(marcador.tipo)),
        ),
      ),
    );
  }
}
