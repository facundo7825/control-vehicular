import 'package:flutter/widgets.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../modelos/comunes.dart';
import 'mapa.dart';

/// Implementación real del mapa (google_maps_flutter). No se usa en los tests de widgets.
class MapaGoogle extends StatelessWidget {
  const MapaGoogle({super.key, required this.datos});

  final DatosMapa datos;

  static double _tono(TipoMarcador t) => switch (t) {
    TipoMarcador.choferLibre => BitmapDescriptor.hueGreen,
    TipoMarcador.choferNoDisponible => BitmapDescriptor.hueYellow,
    TipoMarcador.choferAsignado => BitmapDescriptor.hueAzure,
    TipoMarcador.origen => BitmapDescriptor.hueOrange,
    TipoMarcador.destino => BitmapDescriptor.hueRed,
  };

  @override
  Widget build(BuildContext context) {
    final alTocar = datos.alTocarMapa;
    return GoogleMap(
      initialCameraPosition: CameraPosition(target: LatLng(datos.centro.lat, datos.centro.lng), zoom: 14),
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
            icon: BitmapDescriptor.defaultMarkerWithHue(_tono(m.tipo)),
            onTap: m.alTocar,
          ),
      },
    );
  }
}
