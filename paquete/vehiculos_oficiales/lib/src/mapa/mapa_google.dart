import 'dart:async';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../modelos/comunes.dart';
import 'mapa.dart';

/// Implementación real del mapa (google_maps_flutter). No se usa en los tests de widgets.
class MapaGoogle extends StatefulWidget {
  const MapaGoogle({super.key, required this.datos});

  final DatosMapa datos;

  static double _tono(TipoMarcador t) => switch (t) {
    TipoMarcador.choferLibre => BitmapDescriptor.hueGreen,
    TipoMarcador.choferNoDisponible => BitmapDescriptor.hueYellow,
    TipoMarcador.choferAsignado => BitmapDescriptor.hueAzure,
    TipoMarcador.origen => BitmapDescriptor.hueOrange,
    TipoMarcador.destino => BitmapDescriptor.hueRed,
  };

  /// Con qué ícono y qué anclaje se dibuja un marcador de [tipo]. El auto y el punto van centrados en
  /// la posición; mientras [iconos] no los tiene (todavía no se dibujaron, o falló el dibujo), y para el
  /// destino, se usa el pin de color por defecto con la punta sobre la posición.
  static ({BitmapDescriptor icono, Offset ancla}) aparienciaDe(
    TipoMarcador tipo,
    Map<TipoMarcador, BitmapDescriptor> iconos,
  ) {
    final icono = tipo.forma == FormaMarcador.pin ? null : iconos[tipo];
    if (icono == null) return (icono: BitmapDescriptor.defaultMarkerWithHue(_tono(tipo)), ancla: const Offset(0.5, 1));
    return (icono: icono, ancla: const Offset(0.5, 0.5));
  }

  /// Densidad a la que se dibujan los íconos: se muestran a [FormaMarcador.lado] píxeles lógicos.
  static const _densidad = 3.0;

  /// Dibuja (a PNG) el auto o el punto de cada tipo que no es un pin.
  static Future<Map<TipoMarcador, BitmapDescriptor>> dibujarIconos() async => {
    for (final t in TipoMarcador.values)
      if (t.forma != FormaMarcador.pin)
        t: BitmapDescriptor.bytes(await _dibujar(t), width: t.forma.lado, height: t.forma.lado),
  };

  static Future<Uint8List> _dibujar(TipoMarcador tipo) async {
    final forma = tipo.forma;
    final lado = forma.lado;
    final grabador = ui.PictureRecorder();
    final lienzo = Canvas(grabador)..scale(_densidad);
    final centro = Offset(lado / 2, lado / 2);
    final borde = forma == FormaMarcador.auto ? 2.0 : 3.0;
    // Lugar para la sombra dentro del cuadro.
    final radio = lado / 2 - 1.5;
    lienzo
      ..drawCircle(
        centro.translate(0, 1),
        radio,
        Paint()
          ..color = Colors.black38
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 1.5),
      )
      ..drawCircle(centro, radio, Paint()..color = Colors.white)
      ..drawCircle(centro, radio - borde, Paint()..color = tipo.color);
    if (forma == FormaMarcador.auto) {
      const icono = Icons.directions_car;
      final texto = TextPainter(
        text: TextSpan(
          text: String.fromCharCode(icono.codePoint),
          style: TextStyle(
            fontFamily: icono.fontFamily,
            package: icono.fontPackage,
            fontSize: lado * 0.6,
            color: Colors.white,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();
      texto
        ..paint(lienzo, centro - Offset(texto.width / 2, texto.height / 2))
        ..dispose();
    }
    final dibujo = grabador.endRecording();
    final pixeles = (lado * _densidad).round();
    final ui.Image imagen;
    try {
      imagen = await dibujo.toImage(pixeles, pixeles);
    } finally {
      dibujo.dispose();
    }
    try {
      final datos = await imagen.toByteData(format: ui.ImageByteFormat.png);
      if (datos == null) throw StateError('no se pudo codificar el ícono');
      return datos.buffer.asUint8List();
    } finally {
      imagen.dispose();
    }
  }

  /// Los íconos se dibujan una sola vez para toda la app. Si falla, se reintenta con el próximo mapa.
  static Future<Map<TipoMarcador, BitmapDescriptor>>? _iconosCompartidos;

  static Future<Map<TipoMarcador, BitmapDescriptor>> _iconos() => _iconosCompartidos ??= () async {
    try {
      return await dibujarIconos();
    } catch (_) {
      _iconosCompartidos = null;
      rethrow;
    }
  }();

  static LatLng _latLng(Coordenada c) => LatLng(c.lat, c.lng);

  /// Las [lineas] que se dibujan, en orden. Google siempre pone los marcadores encima de las polilíneas;
  /// el `zIndex` solo ordena las líneas entre sí. El ancho de Google es en píxeles lógicos enteros.
  static List<Polyline> polilineasDe(List<LineaMapa> lineas) => [
    for (final (i, l) in lineas.indexed)
      if (l.visible)
        Polyline(
          polylineId: PolylineId(l.id),
          points: [for (final p in l.puntos) _latLng(p)],
          color: l.color,
          width: l.ancho.round(),
          zIndex: i,
        ),
  ];

  /// Dónde arranca la cámara: en el enfoque si es un solo punto; si no, en el centro (el encuadre de
  /// varios puntos necesita el mapa ya creado).
  static CameraPosition posicionInicial(DatosMapa datos) {
    final punto = datos.enfoque?.unico;
    return punto == null
        ? CameraPosition(target: _latLng(datos.centro), zoom: 14)
        : CameraPosition(target: _latLng(punto), zoom: Enfoque.zoomPunto);
  }

  /// El movimiento de cámara que aplica [enfoque].
  static CameraUpdate actualizacionPara(Enfoque enfoque) {
    final punto = enfoque.unico;
    if (punto != null) return CameraUpdate.newLatLngZoom(_latLng(punto), Enfoque.zoomPunto);
    return CameraUpdate.newLatLngBounds(
      LatLngBounds(southwest: LatLng(enfoque.sur, enfoque.oeste), northeast: LatLng(enfoque.norte, enfoque.este)),
      Enfoque.margen,
    );
  }

  /// Mueve la cámara a [enfoque] con [mover]. Un encuadre de puntos muy cercanos no acerca más que
  /// [Enfoque.zoomPunto] (como en OSM): Google no tiene un zoom máximo para el encuadre, así que se
  /// consulta el [zoom] resultante y se corrige. Si algo falla, el error le llega a quien llamó.
  static Future<void> aplicar(
    Enfoque enfoque, {
    required Future<void> Function(CameraUpdate) mover,
    required Future<double> Function() zoom,
  }) async {
    await mover(actualizacionPara(enfoque));
    if (enfoque.unico != null) return;
    if (await zoom() > Enfoque.zoomPunto) await mover(CameraUpdate.zoomTo(Enfoque.zoomPunto));
  }

  @override
  State<MapaGoogle> createState() => _MapaGoogleState();
}

class _MapaGoogleState extends State<MapaGoogle> {
  final _seguidor = SeguidorEnfoque();
  GoogleMapController? _controlador;

  /// Vacío hasta que se dibujan los autos y puntos: mientras tanto, pines de color.
  Map<TipoMarcador, BitmapDescriptor> _iconos = const {};

  @override
  void initState() {
    super.initState();
    unawaited(_cargarIconos());
  }

  Future<void> _cargarIconos() async {
    try {
      final iconos = await MapaGoogle._iconos();
      if (mounted) setState(() => _iconos = iconos);
    } catch (e) {
      debugPrint('No se pudieron dibujar los íconos del mapa (${e.runtimeType}).');
    }
  }

  void _alCrear(GoogleMapController controlador) {
    _controlador = controlador;
    unawaited(_mover(animar: false));
  }

  @override
  void didUpdateWidget(MapaGoogle anterior) {
    super.didUpdateWidget(anterior);
    // Sin controlador todavía, el enfoque se aplica al crearse el mapa.
    if (_controlador != null) unawaited(_mover(animar: true));
  }

  /// Si el movimiento falla (p. ej. el mapa todavía no tiene tamaño), el enfoque no se da por aplicado:
  /// se reintenta una vez después del próximo cuadro y, si no, con la próxima actualización.
  Future<void> _mover({required bool animar, bool reintento = false}) async {
    final controlador = _controlador;
    if (controlador == null) return;
    final enfoque = _seguidor.aMover(widget.datos.enfoque);
    if (enfoque == null) return;
    try {
      await MapaGoogle.aplicar(
        enfoque,
        mover: animar ? controlador.animateCamera : controlador.moveCamera,
        zoom: controlador.getZoomLevel,
      );
    } catch (e) {
      debugPrint('No se pudo mover la cámara del mapa (${e.runtimeType}).');
      _seguidor.fallo(enfoque);
      if (reintento || !mounted) return;
      SchedulerBinding.instance
        ..addPostFrameCallback((_) {
          if (mounted) unawaited(_mover(animar: false, reintento: true));
        })
        ..scheduleFrame();
    }
  }

  @override
  Widget build(BuildContext context) {
    final datos = widget.datos;
    final alTocar = datos.alTocarMapa;
    return GoogleMap(
      initialCameraPosition: MapaGoogle.posicionInicial(datos),
      onMapCreated: _alCrear,
      myLocationButtonEnabled: false,
      mapToolbarEnabled: false,
      onTap: alTocar == null ? null : (p) => alTocar(Coordenada(p.latitude, p.longitude)),
      markers: {for (final m in datos.marcadores) _marcador(m)},
      polylines: MapaGoogle.polilineasDe(datos.lineas).toSet(),
    );
  }

  Marker _marcador(MarcadorMapa m) {
    final (:icono, :ancla) = MapaGoogle.aparienciaDe(m.tipo, _iconos);
    return Marker(
      markerId: MarkerId(m.id),
      position: LatLng(m.posicion.lat, m.posicion.lng),
      infoWindow: InfoWindow(title: m.titulo),
      // Los no disponibles van desvaídos (ver TipoMarcador.opacidad).
      alpha: m.tipo.opacidad,
      icon: icono,
      anchor: ancla,
      onTap: m.alTocar,
    );
  }
}
