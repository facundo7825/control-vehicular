import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/iniciar_turno.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/mapa_chofer.dart';

import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/dobles_chofer.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

void main() {
  late UbicadorFalso gps;

  setUp(() => gps = UbicadorFalso());

  group('sin turno', () {
    late EntornoPrueba e;

    setUp(() {
      e = entornoChofer(conTurno: false);
      e.http.responder('GET', 'vehiculos/disponibles', 200, c.vehiculosDisponibles);
    });

    testWidgets('elige un vehículo, inicia el turno y pasa al mapa con el GPS andando', (tester) async {
      e.http.responder('POST', 'turnos', 201, c.turnoIniciado);

      await montarChofer(tester, e, ubicador: gps);
      expect(find.byType(IniciarTurno), findsOneWidget);
      expect(find.text('Toyota Corolla (AB123CD)'), findsOneWidget);

      await tester.tap(find.text('Toyota Corolla (AB123CD)'));
      await tester.pump();
      await tester.tap(find.widgetWithText(FilledButton, 'Iniciar turno'));
      await esperar(tester);

      final inicio = e.http.pedidos.singleWhere((r) => r.uri.path == '/api/turnos');
      expect(jsonDecode(inicio.cuerpo), {'vehiculo_id': 1});
      expect(find.byType(MapaChofer), findsOneWidget);
      expect(find.text('Libre'), findsOneWidget); // su estado, de GET /choferes
      expect(find.text('Toyota Corolla (AB123CD) · Blanco'), findsOneWidget);
      expect(gps.siguiendo, isTrue);
    });

    testWidgets('permiso denegado: lo explica, ofrece los ajustes y no llama a la API', (tester) async {
      gps.permiso = PermisoUbicacion.denegadoParaSiempre;

      await montarChofer(tester, e, ubicador: gps);
      await tester.tap(find.text('Toyota Corolla (AB123CD)'));
      await tester.pump();
      await tester.tap(find.widgetWithText(FilledButton, 'Iniciar turno'));
      await esperar(tester);

      expect(find.textContaining('Para iniciar el turno necesitamos tu ubicación'), findsOneWidget);
      expect(pedidosHechos(e), isNot(contains('POST turnos')));
      expect(gps.siguiendo, isFalse);

      await tester.tap(find.text('Abrir ajustes'));
      expect(gps.ajustesAbiertos, [PermisoUbicacion.denegadoParaSiempre]);
    });

    testWidgets('GPS apagado: pide activarlo', (tester) async {
      gps.permiso = PermisoUbicacion.gpsApagado;

      await montarChofer(tester, e, ubicador: gps);
      await tester.tap(find.text('Toyota Corolla (AB123CD)'));
      await tester.pump();
      await tester.tap(find.widgetWithText(FilledButton, 'Iniciar turno'));
      await esperar(tester);

      expect(find.text('La ubicación del teléfono está apagada. Activala para iniciar el turno.'), findsOneWidget);
    });

    testWidgets('vehículo tomado por otro: muestra el mensaje y recarga la lista', (tester) async {
      e.http.responder('POST', 'turnos', 422, c.vehiculoEnUso);

      await montarChofer(tester, e, ubicador: gps);
      await tester.tap(find.text('Toyota Corolla (AB123CD)'));
      await tester.pump();
      await tester.tap(find.widgetWithText(FilledButton, 'Iniciar turno'));
      await esperar(tester);

      expect(find.text('El vehículo está en uso por otro chofer.'), findsOneWidget);
      expect(pedidosHechos(e).where((r) => r == 'GET vehiculos/disponibles'), hasLength(2));
      expect(find.byType(IniciarTurno), findsOneWidget);
    });
  });

  group('con turno', () {
    testWidgets('finalizar con un viaje activo muestra el 422 y el turno sigue', (tester) async {
      final e = entornoChofer()..http.responder('POST', 'turnos/actual/finalizar', 422, c.finalizarConViaje);

      await montarChofer(tester, e, ubicador: gps);
      await tester.tap(find.text('Finalizar turno'));
      await esperar(tester);
      await tester.tap(find.text('Sí, finalizar'));
      await esperar(tester);

      expect(find.text('Finalizá el viaje en curso antes de cerrar el turno.'), findsOneWidget);
      expect(find.byType(MapaChofer), findsOneWidget);
      expect(gps.siguiendo, isTrue);
    });

    testWidgets('finalizar manda lo pendiente, cierra el turno, corta el GPS y vuelve a "Iniciar turno"', (
      tester,
    ) async {
      final e = entornoChofer()
        ..http.responder('POST', 'turnos/actual/finalizar', 200, c.turnoFinalizado)
        ..http.responder('GET', 'vehiculos/disponibles', 200, c.vehiculosDisponibles);

      await montarChofer(tester, e, ubicador: gps);
      gps.emitir(punto(0));
      await tester.pump();
      expect(find.byKey(const Key('marcador-yo')), findsOneWidget);

      await tester.tap(find.text('Finalizar turno'));
      await esperar(tester);
      await tester.tap(find.text('Sí, finalizar'));
      await esperar(tester);

      final orden = pedidosHechos(e).where((r) => r.startsWith('POST')).toList();
      expect(orden.sublist(orden.length - 2), ['POST ubicacion', 'POST turnos/actual/finalizar']);
      expect(find.byType(IniciarTurno), findsOneWidget);
      expect(gps.siguiendo, isFalse);
    });

    testWidgets('si el GPS falla lo avisa con "Abrir ajustes" y "Reintentar"', (tester) async {
      final e = entornoChofer();

      await montarChofer(tester, e, ubicador: gps);
      gps.fallar(Exception('GPS apagado'));
      await tester.pump();

      expect(find.textContaining('Sin señal de GPS por ahora'), findsOneWidget);
      await tester.tap(find.text('Reintentar'));
      await esperar(tester);
      expect(gps.intervalos, hasLength(2));
    });

    testWidgets('si el GPS no entrega posiciones avisa en el mapa y el aviso se va cuando vuelve', (tester) async {
      final e = entornoChofer();

      await montarChofer(tester, e, ubicador: gps);
      expect(find.text('Buscando tu ubicación…'), findsOneWidget);
      expect(find.textContaining('Sin señal de GPS por ahora'), findsNothing);

      await tester.pump(const Duration(seconds: 30));
      expect(find.textContaining('Sin señal de GPS por ahora'), findsOneWidget);
      expect(find.text('Buscando tu ubicación…'), findsNothing);
      expect(find.text('Abrir ajustes'), findsOneWidget);
      expect(find.text('Reintentar'), findsOneWidget);

      gps.emitir(punto(0));
      await tester.pump();
      expect(find.textContaining('Sin señal de GPS por ahora'), findsNothing);
    });

    testWidgets('cerrar el módulo con el turno abierto (X o "atrás") pide confirmación', (tester) async {
      final e = entornoChofer();

      await montarChofer(tester, e, ubicador: gps);
      await tester.binding.handlePopRoute();
      await esperar(tester);
      expect(find.text('Tu turno sigue abierto'), findsOneWidget);

      await tester.tap(find.text('Seguir acá'));
      await esperar(tester);
      expect(find.byType(MapaChofer), findsOneWidget);
      expect(gps.siguiendo, isTrue);

      await tester.tap(find.byTooltip('Cerrar'));
      await esperar(tester);
      expect(find.text('Tu turno sigue abierto'), findsOneWidget);
      await tester.tap(find.text('Seguir acá'));
      await esperar(tester);

      await tester.tap(find.byTooltip('Cerrar'));
      await esperar(tester);
      await tester.tap(find.text('Cerrar igual'));
      await tester.pumpAndSettle();
      expect(find.text('Herramientas: Vehículos oficiales'), findsOneWidget);
      expect(gps.siguiendo, isFalse);
    });
  });
}
