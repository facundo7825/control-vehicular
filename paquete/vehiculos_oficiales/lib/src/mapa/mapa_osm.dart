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
class MapaOsm extends ConsumerWidget {
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

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final alTocar = datos.alTocarMapa;
    return FlutterMap(
      options: MapOptions(
        initialCenter: LatLng(datos.centro.lat, datos.centro.lng),
        initialZoom: 14,
        onTap: alTocar == null ? null : (_, p) => alTocar(Coordenada(p.latitude, p.longitude)),
      ),
      children: [
        TileLayer(urlTemplate: urlTeselas, userAgentPackageName: agenteUsuario, tileProvider: teselas),
        MarkerLayer(
          markers: [
            for (final m in datos.marcadores)
              Marker(
                point: LatLng(m.posicion.lat, m.posicion.lng),
                width: _tamano,
                height: _tamano,
                // La punta del ícono queda sobre la posición.
                alignment: Alignment.topCenter,
                child: _Marcador(key: Key('marcador-${m.id}'), marcador: m),
              ),
          ],
        ),
        SimpleAttributionWidget(
          source: const Text('OpenStreetMap contributors'),
          onTap: () => ref.read(lanzadorUrlProvider)(_derechos),
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
