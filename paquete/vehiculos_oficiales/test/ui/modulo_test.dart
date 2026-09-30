import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/inicio_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/modulo_app.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

const _usuarioChofer =
    '{"token":"2|x","usuario":{"id":2,"nombre":"Carlos G\\u00f3mez","cargo":"Chofer","rol":"chofer"}}';

void main() {
  late EntornoPrueba e;

  setUp(() => e = EntornoPrueba());

  testWidgets('un solicitante entra directo a su pantalla', (tester) async {
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);

    await montarModulo(tester, e);

    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  testWidgets('un chofer entra a la pantalla del chofer', (tester) async {
    e.http.responder('POST', 'auth/intercambio', 200, _usuarioChofer);

    await montarModulo(tester, e);

    expect(find.byType(InicioChofer), findsOneWidget);
  });

  testWidgets('identidad caída: mensaje y reintentar', (tester) async {
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);

    await montarModulo(tester, e);
    expect(find.text('Servicio de identidad no disponible'), findsOneWidget);

    await tester.tap(find.text('Reintentar'));
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  testWidgets('sesión del PJ inválida: avisa a la app principal y ofrece cerrar', (tester) async {
    e.http.responder('POST', 'auth/intercambio', 401, p.intercambioInvalido);

    await montarModulo(tester, e);

    expect(find.text('Tu sesión venció'), findsOneWidget);
    expect(e.sesionesInvalidas, 1);

    await tester.tap(find.text('Cerrar'));
    await tester.pumpAndSettle();
    expect(find.text('Herramientas: Vehículos oficiales'), findsOneWidget);
  });

  testWidgets('el botón cerrar y el "atrás" del sistema vuelven a la app principal', (tester) async {
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);

    await montarModulo(tester, e);
    await tester.tap(find.byTooltip('Cerrar'));
    await tester.pumpAndSettle();
    expect(find.text('Herramientas: Vehículos oficiales'), findsOneWidget);

    await tester.tap(find.text('Herramientas: Vehículos oficiales'));
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('Herramientas: Vehículos oficiales'), findsOneWidget);
  });

  testWidgets('si la app principal se reconstruye (cambia el tema) el módulo no se reinicia', (tester) async {
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    final host = GlobalKey<_HostQueCambiaState>();
    await tester.pumpWidget(_HostQueCambia(key: host, e: e));
    await tester.tap(find.text('Abrir'));
    await esperar(tester);
    expect(find.byType(InicioSolicitante), findsOneWidget);

    for (var i = 0; i < 3; i++) {
      host.currentState!.cambiarTema();
      await esperar(tester);
    }

    expect(tester.takeException(), isNull);
    expect(e.http.pedidos.where((r) => r.uri.path.endsWith('auth/intercambio')), hasLength(1));
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  testWidgets('cerrar el módulo cierra su propia ruta aunque haya otra encima', (tester) async {
    final host = GlobalKey<_HostQueCambiaState>();
    await tester.pumpWidget(_HostQueCambia(key: host, e: e, conAbrir: true));
    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();

    // La app principal abre algo encima del módulo (p. ej. un aviso propio) y el módulo se cierra.
    final navegador = host.currentState!.navegador.currentState!;
    unawaited(navegador.push(MaterialPageRoute<void>(builder: (_) => const Text('Aviso de la app principal'))));
    await tester.pumpAndSettle();
    tester.widget<ModuloVehiculos>(find.byType(ModuloVehiculos, skipOffstage: false)).alCerrar();
    await tester.pumpAndSettle();

    expect(find.text('Aviso de la app principal'), findsOneWidget);
    navegador.pop();
    await tester.pumpAndSettle();
    expect(find.text('Abrir'), findsOneWidget);
    expect(host.currentState!.cerrado, isTrue);
  });

  testWidgets('VehiculosOficiales.abrir abre el módulo desde la app principal', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) => FilledButton(
            onPressed: () => VehiculosOficiales.abrir(
              context,
              sesion: const SesionPJ('sim|1|A|B'),
              push: PuenteFalso(),
              onSesionInvalida: () {},
              config: configPrueba,
            ),
            child: const Text('Abrir'),
          ),
        ),
      ),
    );

    await tester.tap(find.text('Abrir'));
    await tester.pumpAndSettle();

    // En los tests de Flutter todo HTTP real responde 400: el módulo muestra el error y se puede cerrar.
    expect(find.text('Vehículos oficiales'), findsOneWidget);
    await tester.tap(find.byTooltip('Cerrar'));
    await tester.pumpAndSettle();
    expect(find.text('Abrir'), findsOneWidget);
  });
}

/// App principal que se reconstruye (cambia el tema) con el módulo abierto. Con [conAbrir] usa
/// `VehiculosOficiales.abrir` (HTTP real, que en los tests responde 400); si no, arma la ruta como la
/// app de prueba de `montarModulo`, con un entorno nuevo en cada construcción de la ruta.
class _HostQueCambia extends StatefulWidget {
  const _HostQueCambia({super.key, required this.e, this.conAbrir = false});

  final EntornoPrueba e;
  final bool conAbrir;

  @override
  State<_HostQueCambia> createState() => _HostQueCambiaState();
}

class _HostQueCambiaState extends State<_HostQueCambia> {
  final navegador = GlobalKey<NavigatorState>();
  var oscuro = false;
  var cerrado = false;

  void cambiarTema() => setState(() => oscuro = !oscuro);

  void _abrir(BuildContext context) {
    final Future<void> abierto;
    if (widget.conAbrir) {
      abierto = VehiculosOficiales.abrir(
        context,
        sesion: const SesionPJ('sim|1|A|B'),
        push: PuenteFalso(),
        onSesionInvalida: () {},
        config: configPrueba,
      );
    } else {
      final nav = Navigator.of(context);
      abierto = nav.push<void>(
        MaterialPageRoute(
          builder: (_) => ModuloVehiculos(
            entorno: widget.e.entorno,
            alCerrar: () => nav.pop(),
            overrides: widget.e.overridesDeModulo([
              tiempoRealProvider.overrideWithValue(TiempoRealFalso()),
              constructorMapaProvider.overrideWithValue(mapaDePrueba),
            ]),
          ),
        ),
      );
    }
    unawaited(abierto.then((_) => cerrado = true));
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    navigatorKey: navegador,
    theme: oscuro ? ThemeData.dark() : ThemeData.light(),
    home: Builder(
      builder: (context) => Scaffold(
        body: Center(
          child: FilledButton(onPressed: () => _abrir(context), child: const Text('Abrir')),
        ),
      ),
    ),
  );
}
