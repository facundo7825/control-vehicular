import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';
import 'package:vehiculos_oficiales/src/ui/modulo_app.dart';

import 'dobles.dart';
import 'entorno_prueba.dart';

/// Mapa de prueba: una lista de botones, uno por marcador, y un botón que "toca" el mapa en
/// [puntoTocado]. Así las pantallas se prueban sin Google Maps. A dónde mira se lee con
/// [enfoqueDelMapa].
const puntoTocado = Coordenada(-26.8083, -65.2176);

Widget mapaDePrueba(BuildContext context, DatosMapa datos) {
  return ListView(
    key: const Key('mapa'),
    children: [
      _EnfoqueDePrueba(datos.enfoque),
      for (final m in datos.marcadores)
        TextButton(key: Key('marcador-${m.id}'), onPressed: m.alTocar, child: Text('${m.tipo.name}: ${m.titulo}')),
      if (datos.alTocarMapa != null)
        TextButton(
          key: const Key('tocar-mapa'),
          onPressed: () => datos.alTocarMapa!(puntoTocado),
          child: const Text('tocar mapa'),
        ),
    ],
  );
}

class _EnfoqueDePrueba extends StatelessWidget {
  const _EnfoqueDePrueba(this.enfoque);

  final Enfoque? enfoque;

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}

/// El enfoque que la pantalla le pasó a [mapaDePrueba] (nulo si no pidió mirar a ningún lado).
Enfoque? enfoqueDelMapa(WidgetTester tester) =>
    tester.widget<_EnfoqueDePrueba>(find.byType(_EnfoqueDePrueba, skipOffstage: false)).enfoque;

/// App principal de prueba con un botón que abre el módulo.
Future<void> montarModulo(
  WidgetTester tester,
  EntornoPrueba e, {
  TiempoRealFalso? tiempoReal,
  Ubicador? ubicador,
  List<Override> extra = const [],
}) async {
  // Pantalla de teléfono (390 x 844) para que el panel de pedido entre sin desplazar.
  tester.view.physicalSize = const Size(1170, 2532);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      home: Builder(
        builder: (context) => Scaffold(
          body: Center(
            child: FilledButton(
              onPressed: () {
                final navegador = Navigator.of(context);
                navegador.push<void>(
                  MaterialPageRoute(
                    builder: (_) => ModuloVehiculos(
                      entorno: e.entorno,
                      alCerrar: () => navegador.pop(),
                      overrides: e.overridesDeModulo([
                        tiempoRealProvider.overrideWithValue(tiempoReal ?? TiempoRealFalso()),
                        constructorMapaProvider.overrideWithValue(mapaDePrueba),
                        // Sin ubicación salvo que el test la dé: nunca el plugin real.
                        ubicadorProvider.overrideWithValue(ubicador ?? UbicadorFalso()),
                        ...extra,
                      ]),
                    ),
                  ),
                );
              },
              child: const Text('Herramientas: Vehículos oficiales'),
            ),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('Herramientas: Vehículos oficiales'));
  await esperar(tester);
}

/// Como pumpAndSettle, pero termina aunque haya animaciones infinitas (indicadores de progreso).
Future<void> esperar(WidgetTester tester) async {
  for (var i = 0; i < 20; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}
