import 'package:flutter/widgets.dart';
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

  static LatLng _latLng(Coordenada c) => LatLng(c.lat, c.lng);

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

  @override
  State<MapaGoogle> createState() => _MapaGoogleState();
}

class _MapaGoogleState extends State<MapaGoogle> {
  final _seguidor = SeguidorEnfoque();
  GoogleMapController? _controlador;

  void _alCrear(GoogleMapController controlador) {
    _controlador = controlador;
    _mover(animar: false);
  }

  @override
  void didUpdateWidget(MapaGoogle anterior) {
    super.didUpdateWidget(anterior);
    // Sin controlador todavía, el enfoque se aplica al crearse el mapa.
    if (_controlador != null) _mover(animar: true);
  }

  void _mover({required bool animar}) {
    final controlador = _controlador;
    if (controlador == null) return;
    final enfoque = _seguidor.aMover(widget.datos.enfoque);
    if (enfoque == null) return;
    final actualizacion = MapaGoogle.actualizacionPara(enfoque);
    _ignorarError(animar ? controlador.animateCamera(actualizacion) : controlador.moveCamera(actualizacion));
  }

  /// Mover la cámara es cosmético: si el mapa ya no está (o el sistema falla), no pasa nada.
  static Future<void> _ignorarError(Future<void> movimiento) async {
    try {
      await movimiento;
    } catch (e) {
      debugPrint('No se pudo mover la cámara del mapa (${e.runtimeType}).');
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
      markers: {
        for (final m in datos.marcadores)
          Marker(
            markerId: MarkerId(m.id),
            position: LatLng(m.posicion.lat, m.posicion.lng),
            infoWindow: InfoWindow(title: m.titulo),
            // Spec 7 pide gris para los no disponibles: los marcadores por defecto no tienen gris,
            // se usan desvaídos hasta tener íconos propios.
            alpha: m.tipo == TipoMarcador.choferNoDisponible ? 0.45 : 1,
            icon: BitmapDescriptor.defaultMarkerWithHue(MapaGoogle._tono(m.tipo)),
            onTap: m.alTocar,
          ),
      },
    );
  }
}
