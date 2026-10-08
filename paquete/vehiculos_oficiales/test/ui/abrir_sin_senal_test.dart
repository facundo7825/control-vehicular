import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_chofer.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/dobles_chofer.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

void main() {
  testWidgets(
    'la app se cierra en la ruta y abre sin señal: viaje, GPS y pasos funcionan; al volver se confirma todo',
    (tester) async {
      final e = entornoChofer();
      const tokenPJ = 'sim|200|Carlos Chofer|Chofer';
      // Lo que quedó de la última vez que abrió con señal.
      await e.almacen.guardar(tokenPJ, '2|guardado', usuario: chofer.toJson());
      final turno = Turno.fromJson(leerMapa(p.json(c.turnoActual)['turno']));
      final viaje = Viaje.fromJson(p.json(p.viajeAceptado)..['estado'] = 'en_camino');
      e
        ..turnoGuardado = (AlmacenJsonMemoria()..datos = {'usuario_id': 2, 'dato': turno.toJson()})
        ..viajeGuardado = (AlmacenJsonMemoria()..datos = {'usuario_id': 2, 'dato': viaje.toJson()});
      const rutas = [
        ('POST', 'auth/intercambio'),
        ('GET', 'configuracion'),
        ('GET', 'viajes/actual'),
        ('GET', 'choferes'),
        ('GET', 'turnos/actual'),
        ('GET', 'agenda'),
        ('POST', 'ubicacion'),
        ('POST', 'viajes/1/estado'),
      ];
      for (final (metodo, ruta) in rutas) {
        e.http
          ..limpiar(metodo, ruta)
          ..sinRed(metodo, ruta);
      }
      final tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      final gps = UbicadorFalso();

      await montarChofer(tester, e, tiempoReal: tr, ubicador: gps);

      expect(find.byType(ViajeChofer), findsOneWidget);
      expect(gps.siguiendo, isTrue);
      await tester.tap(find.widgetWithText(FilledButton, 'Llegué'));
      await esperar(tester);
      expect(find.widgetWithText(FilledButton, 'Iniciar viaje'), findsOneWidget);
      expect(find.text('Sin señal: 1 acción se enviará al reconectar'), findsOneWidget);

      // Vuelve la señal.
      for (final (metodo, ruta) in rutas) {
        e.http.limpiar(metodo, ruta);
      }
      e.http
        ..responder('POST', 'auth/intercambio', 200, intercambioChofer)
        ..responder('GET', 'configuracion', 200, p.configuracion)
        ..responder(
          'GET',
          'viajes/actual',
          200,
          '{"viaje":${jsonEncode(p.json(p.viajeAceptado)..['estado'] = 'llego')},"oferta":null}',
        )
        ..responder('GET', 'choferes', 200, p.choferes)
        ..responder('GET', 'turnos/actual', 200, c.turnoActual)
        ..responder('GET', 'agenda', 200, '{"reservas":[],"solicitudes":[]}')
        ..responder('POST', 'ubicacion', 204)
        ..responder('POST', 'viajes/1/estado', 200, jsonEncode(p.json(p.viajeAceptado)..['estado'] = 'llego'));
      tr.cambiar(EstadoConexion.conectado);
      await esperar(tester);

      final hechos = pedidosHechos(e);
      expect(hechos.where((h) => h == 'POST auth/intercambio'), hasLength(2));
      expect(hechos.where((h) => h == 'POST viajes/1/estado').length, greaterThanOrEqualTo(2));
      expect(find.textContaining('se enviará'), findsNothing);
      expect(find.widgetWithText(FilledButton, 'Iniciar viaje'), findsOneWidget);
      expect(await e.almacen.leer(tokenPJ), '2|x', reason: 'la sesión se confirmó con el token nuevo');
    },
  );
}
