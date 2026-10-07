import 'package:dio/dio.dart';
import 'package:dio_cache_interceptor/dio_cache_interceptor.dart' show CacheOptions, CacheStore;
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_map_cache/flutter_map_cache.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

import '../entorno.dart';
import '../modelos/comunes.dart';
import '../modelos/configuracion.dart';
import '../ui/comunes/comunes.dart';
import 'cache_teselas.dart';
import 'corredor_teselas.dart';
import 'mapa.dart';
import 'mapa_fondo.dart';

/// Solo para tests: el cliente HTTP de las teselas con caché responde con esto en lugar de la red.
final adaptadorTeselasProvider = Provider<HttpClientAdapter?>((ref) => null);

/// Las teselas de [MapaOsm] con caché en disco ([almacenTeselasProvider]): las que ya se vieron (o se bajaron
/// para un viaje largo, ver [descargadorTeselasProvider]) se muestran sin señal. Una tesela guardada se usa sin
/// preguntar al servidor; [almacenTeselasProvider] las recorta por tamaño y edad. Nulo sin disco (web): el
/// proveedor de flutter_map de siempre, directo a la red.
final teselasConCacheProvider = Provider<CachedTileProvider?>((ref) {
  final almacen = ref.watch(almacenTeselasProvider);
  if (almacen == null) return null;
  final cliente = Dio(
    BaseOptions(connectTimeout: const Duration(seconds: 15), receiveTimeout: const Duration(seconds: 30)),
  );
  final adaptador = ref.watch(adaptadorTeselasProvider);
  if (adaptador != null) cliente.httpClientAdapter = adaptador;
  ref.onDispose(cliente.close);
  return CachedTileProvider(
    store: almacen,
    dio: cliente,
    // El mismo que pondría TileLayer; así lo llevan también las descargas anticipadas.
    headers: {'User-Agent': 'flutter_map (${MapaOsm.agenteUsuario})'},
  );
});

/// Baja al caché de [MapaOsm] las teselas del servidor configurado (las de un viaje largo, antes de quedarse
/// sin señal). Nulo si no hay a dónde bajarlas: el mapa es el de Google, no hay disco (web) o el mapa de fondo
/// todavía no se conoce. También con el OSM público (un backend sin `MAPAS_TESELAS_*`): su política de uso
/// prohíbe bajar teselas por adelantado.
final descargadorTeselasProvider = Provider.autoDispose<DescargadorTeselas?>((ref) {
  if (ref.watch(entornoProvider).config.googleMapsApiKey.isNotEmpty) return null;
  final fondo = ref.watch(mapaFondoProvider);
  final teselas = ref.watch(teselasConCacheProvider);
  if (fondo == null || teselas == null || fondo.url.contains('tile.openstreetmap.org')) return null;
  final almacen = ref.watch(almacenTeselasProvider);
  if (almacen == null) return null;
  return _DescargadorOsm(teselas, almacen, fondo);
});

class _DescargadorOsm implements DescargadorTeselas {
  _DescargadorOsm(this._teselas, this._almacen, MapaFondo fondo)
    : zoomMaximo = fondo.maxZoom,
      // Solo para armar las direcciones como las arma el mapa (TMS, subdominios): no se dibuja.
      _capa = TileLayer(urlTemplate: fondo.url, tms: fondo.tms, maxNativeZoom: fondo.maxZoom);

  final CachedTileProvider _teselas;
  final CacheStore _almacen;
  final TileLayer _capa;

  @override
  final int zoomMaximo;

  @override
  Future<void> descargar(Tesela tesela) async {
    final url = _teselas.getTileUrl(TileCoordinates(tesela.x, tesela.y, tesela.z), _capa);
    // Ya guardada: no hace falta leerla entera del disco (es lo que haría el caché del cliente).
    if (await _almacen.exists(CacheOptions.defaultCacheKeyBuilder(url: Uri.parse(url)))) return;
    // Por el mismo cliente que el mapa: la respuesta queda en su caché (o sale de ahí sin pedirla).
    await _teselas.dio.get<List<int>>(
      url,
      options: Options(responseType: ResponseType.bytes, headers: _teselas.headers),
    );
  }
}

/// Mapa con flutter_map, sin clave. Se usa cuando la app no configuró una clave de Google Maps. El mapa de
/// fondo (servidor de teselas, TMS, zoom máximo y créditos) llega del backend en `GET /configuracion`
/// (`MAPAS_TESELAS_*`, ver [mapaFondoProvider]); un backend anterior que no lo manda deja el OSM público, que
/// sirve para desarrollo y demos pero no para producción. Si el pedido falla, el mapa va sin fondo y se reintenta.
class MapaOsm extends ConsumerStatefulWidget {
  const MapaOsm({super.key, required this.datos, this.teselas});

  final DatosMapa datos;

  /// De dónde salen las teselas. Nulo = de la red, con el caché en disco de [teselasConCacheProvider]; los
  /// tests pasan uno que no la usa.
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
    // Mientras llega la configuración (o si falló, hasta que un reintento responda) no se dibuja el fondo: así
    // nunca se le piden teselas al OSM público en lugar del servidor configurado.
    final fondo = ref.watch(mapaFondoProvider);
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
            tileProvider: widget.teselas ?? ref.watch(teselasConCacheProvider),
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
