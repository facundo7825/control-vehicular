import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:host_prueba/login_falso.dart';
import 'package:host_prueba/main.dart';

void main() {
  test('arma el token simulado que acepta IdentidadSimulada', () {
    expect(tokenSimulado(id: ' 100 ', nombre: 'Ana Pérez', cargo: 'Secretaria'), 'sim|100|Ana Pérez|Secretaria');
  });

  testWidgets('login falso → Herramientas → abre el módulo y lo cierra', (tester) async {
    await tester.pumpWidget(const HostPrueba());

    await tester.tap(find.text('Jorge Juez'));
    await tester.pump();
    await tester.tap(find.text('Ingresar'));
    await tester.pumpAndSettle();

    expect(find.text('Herramientas'), findsOneWidget);
    expect(find.text('sim|101|Jorge Juez|Juez'), findsOneWidget);

    await tester.tap(find.text('Vehículos oficiales'));
    await tester.pumpAndSettle();

    // Sin backend (en los tests todo HTTP responde 400) el módulo muestra el error y se puede cerrar.
    expect(find.byTooltip('Cerrar'), findsOneWidget);
    await tester.tap(find.byTooltip('Cerrar'));
    await tester.pumpAndSettle();
    expect(find.text('Herramientas'), findsOneWidget);
  });

  testWidgets('no deja ingresar con campos vacíos o con "|"', (tester) async {
    await tester.pumpWidget(const HostPrueba());

    await tester.enterText(find.widgetWithText(TextFormField, 'Nombre'), 'Ana|X');
    await tester.enterText(find.widgetWithText(TextFormField, 'Cargo'), '');
    await tester.tap(find.text('Ingresar'));
    await tester.pump();

    expect(find.text('No puede tener "|"'), findsOneWidget);
    expect(find.text('Obligatorio'), findsOneWidget);
  });
}
