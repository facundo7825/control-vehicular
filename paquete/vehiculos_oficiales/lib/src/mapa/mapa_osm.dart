import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

import '../chofer/turno.dart' show configuracionProvider;
import '../modelos/comunes.dart';
import '../modelos/configuracion.dart';
import '../ui/comunes/comunes.dart';
import 'mapa.dart';

/// Mapa con flutter_map, sin clave. Se usa cuando la app no configuró una clave de Google Maps. El mapa de
/// fondo (servidor de teselas, TMS, zoom máximo y créditos) llega del backend en `GET /configuracion`
/// (`MAPAS_TESELAS_*`); sin ese dato, o si el pedido falla, el OSM público, que sirve para desarrollo y demos
/// pero no para producción (sus servidores no admiten ese tráfico).
class MapaOsm extends ConsumerStatefulWidget {
  const MapaOsm({super.key, required this.datos, this.teselas});

  final DatosMapa datos;

  /// De dónde salen las teselas. Nulo = de la red; los tests pasan uno que no la usa.
  final TileProvider? teselas;

  /// Identifica a la app ante los servidores de teselas, como pide la política de uso de OpenStreetMap.
  static const agenteUsuario = 'ar.gob.pj.vehiculos_oficiales';

  /// Los mismos tonos que los marcadores de [MapaGoogle].
  static Color colorDe(TipoMarcador t) => t.color;

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
  static Future<void> _abrirCreditos(LanzadorUrl lanzar, Uri pagina) async {
    try {
      await lanzar(pagina);
    } catch (e) {
      debugPrint('No se pudo abrir los créditos del mapa (${e.runtimeType}).');
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
    // Mientras llega la configuración no se dibuja el fondo: así no se le piden teselas al OSM público para
    // después cambiar al servidor propio. Si falla, el OSM de siempre.
    final configuracion = ref.watch(configuracionProvider);
    final fondo = configuracion.isLoading && !configuracion.hasValue
        ? null
        : (configuracion.value?.teselas ?? MapaFondo.osm);
    final creditos = fondo?.atribucionUrl;
    return FlutterMap(
      mapController: _controlador,
      options: MapOptions(
        initialCenter: MapaOsm._latLng(puntoInicial ?? datos.centro),
        initialZoom: puntoInicial == null ? 14 : Enfoque.zoomPunto,
        initialCameraFit: inicial != null && puntoInicial == null ? MapaOsm._encuadre(inicial) : null,
        onTap: alTocar == null ? null : (_, p) => alTocar(Coordenada(p.latitude, p.longitude)),
      ),
      children: [
        if (fondo != null)
          TileLayer(
            urlTemplate: fondo.url,
            tms: fondo.tms,
            // Más cerca que eso se agrandan las teselas del último nivel (el mapa no queda en blanco).
            maxNativeZoom: fondo.maxZoom,
            userAgentPackageName: MapaOsm.agenteUsuario,
            tileProvider: widget.teselas,
          ),
        // Antes que los marcadores: quedan debajo.
        PolylineLayer(
          polylines: [
            for (final l in datos.lineas)
              if (l.visible)
                Polyline(points: [for (final p in l.puntos) MapaOsm._latLng(p)], color: l.color, strokeWidth: l.ancho),
          ],
        ),
        MarkerLayer(
          markers: [
            for (final m in datos.marcadores)
              Marker(
                point: LatLng(m.posicion.lat, m.posicion.lng),
                width: m.tipo.forma.lado,
                height: m.tipo.forma.lado,
                // El pin, con la punta sobre la posición; el auto y el punto, centrados en ella.
                alignment: m.tipo.forma == FormaMarcador.pin ? Alignment.topCenter : Alignment.center,
                child: _Marcador(key: Key('marcador-${m.id}'), marcador: m),
              ),
          ],
        ),
        // Los créditos del mapa de fondo (la licencia de OpenStreetMap los exige); sin enlace no se pueden tocar.
        if (fondo != null)
          SimpleAttributionWidget(
            source: Text(fondo.atribucion),
            onTap: creditos == null ? null : () => MapaOsm._abrirCreditos(ref.read(lanzadorUrlProvider), creditos),
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
          opacity: marcador.tipo.opacidad,
          child: _forma(marcador.tipo),
        ),
      ),
    );
  }

  static Widget _forma(TipoMarcador tipo) {
    final forma = tipo.forma;
    if (forma == FormaMarcador.pin) return Icon(Icons.location_on, size: forma.lado, color: tipo.color);
    return Container(
      width: forma.lado,
      height: forma.lado,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: tipo.color,
        border: Border.all(color: Colors.white, width: forma == FormaMarcador.auto ? 2 : 3),
        boxShadow: const [BoxShadow(color: Colors.black38, blurRadius: 3, offset: Offset(0, 1))],
      ),
      child: forma == FormaMarcador.auto
          ? Icon(Icons.directions_car, size: forma.lado * 0.6, color: Colors.white)
          : null,
    );
  }
}
