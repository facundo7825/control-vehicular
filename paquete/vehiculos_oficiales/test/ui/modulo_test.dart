import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/inicio_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import '../fixtures/payloads.dart' as p;
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
