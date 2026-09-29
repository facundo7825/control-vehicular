# App Flutter: chofer — Vehículos Oficiales — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Estado de este plan:** encabezado, decisiones, estructura, lista de tareas con sus interfaces y los tests que cada una tiene que cubrir. El código completo de cada paso se escribe (y se verifica igual que el plan anterior: en un directorio aparte, con `flutter analyze`, `dart format` y `flutter test`) **después de ejecutar** `2026-09-30-flutter-base-solicitante.md`, contra el código que ese plan deja en el repo. Los fragmentos que sí aparecen acá (ajustes del GPS, JSON de los endpoints) ya están verificados.

**Goal:** Que un chofer abra el módulo, inicie su turno eligiendo un vehículo (con permiso de ubicación), comparta su posición en lotes mientras el turno está abierto (también en segundo plano, con la notificación fija "Turno activo – compartiendo ubicación", y guardando los puntos si no hay señal), reciba ofertas a pantalla completa con cuenta regresiva según el vencimiento del servidor (o el aviso "Viaje asignado" si es obligatorio), avance el viaje paso a paso con navegación externa, llamar y cancelar con motivo (salvo obligatorios), gestione su agenda de reservas y solicitudes, y finalice el turno.

**Architecture:** Sobre la base del plan anterior. `ViajeActualNotifier` ya escucha `chofer.{id}` (ofertas inmediatas, asignaciones y cambios de su viaje) y suma `aceptarOferta`, `rechazarOferta` y `avanzar`. Un `TurnoNotifier` maneja el turno y, mientras está abierto, un `RastreadorTurno` une el GPS (`Ubicador.seguir`, geolocator con servicio en primer plano) con una `ColaUbicaciones` y un `EmisorUbicacion` que manda lotes a `POST /api/ubicacion` cada `gps_turno_seg` (10 s) o `gps_viaje_seg` (5 s) con un viaje activo (`GET /api/configuracion`). Las pantallas del chofer cuelgan de `/chofer` en el mismo router del módulo.

**Tech Stack:** el del plan anterior (Flutter 3.41.6 / Dart 3.11.4, flutter_riverpod 3.3.2, go_router 17.5.0, dio 5.11.1, dart_pusher_channels 1.3.1, google_maps_flutter 2.18.1, geolocator 14.1.1, url_launcher 6.3.2) más `http_parser` (ya transitiva de dio) para leer el encabezado `Date`. Sin paquetes de sonido en v1 (decisión 9).

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md` (secciones 4.1, 5.1–5.6, 6, 7 —chofer—, 9, 10 y 11)

**Plan anterior (obligatorio):** `docs/superpowers/plans/2026-09-30-flutter-base-solicitante.md` (paquete, sesión, API, Reverb con respaldo, push, router, `host_prueba`).

## Decisiones

Las marcadas **(A confirmar con el equipo de la app del PJ)** dependen de ese equipo (spec 12).

1. **Modelos y endpoints del chofer** (sobre `ApiVehiculos`): `vehiculosDisponibles()`, `turnoActual()`, `iniciarTurno(vehiculoId)`, `finalizarTurno()`, `enviarUbicacion(List<PuntoGps>)`, `aceptarOferta(id) → Viaje`, `rechazarOferta(id)` (204), `avanzarViaje(id, EstadoViaje) → Viaje` (`en_camino`, `llego`, `en_curso`, `finalizado`), `cancelarViaje(id, motivo:)` (ya existe; para el chofer el motivo es obligatorio) y `agenda() → Agenda`. `Turno {id, vehiculo, inicio, fin?}` se lee del JSON de Eloquent (fechas `…000000Z`, campos extra ignorados). `Agenda {reservas: List<Viaje>, solicitudes: List<Oferta>}`.
2. **Reloj del servidor.** La cuenta regresiva de una oferta usa **`vence_en` del servidor**, nunca "30 s desde que llegó". Para que un reloj del teléfono adelantado o atrasado no la rompa, `ClienteApi` guarda el desfase `Date (respuesta) − ahora (dispositivo)` de la última respuesta (`parseHttpDate` de `http_parser`, que también funciona en web) y `relojServidorProvider` expone `ahora()` corregido. Restante = `vence_en − reloj.ahora()`, recalculado cada segundo con un `Timer` (no se descuenta un contador).
3. **Permisos de ubicación.** Iniciar turno exige como mínimo "mientras se usa la app": el GPS corre en un **servicio en primer plano de tipo `location`** que se inicia con la app en primer plano, y así Android sigue entregando posiciones con la app en segundo plano sin `ACCESS_BACKGROUND_LOCATION`. Si el permiso se niega, la pantalla lo explica y ofrece "Abrir ajustes" (`Geolocator.openAppSettings`), spec 9. Pedir "permitir siempre" queda como mejora **(A confirmar con el equipo de la app del PJ:** política de Google Play para ubicación en segundo plano y si el PJ ya la justifica; en iOS, "Siempre" + `UIBackgroundModes: location` es necesario para seguir con la pantalla bloqueada).
4. **GPS del turno** (`Ubicador.seguir(intervalo)` sobre `Geolocator.getPositionStream`), con estos ajustes (verificados contra geolocator 14.1.1):

```dart
LocationSettings ajustesGpsTurno(Duration intervalo) {
  if (defaultTargetPlatform == TargetPlatform.android) {
    return AndroidSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 0,
      intervalDuration: intervalo,
      foregroundNotificationConfig: const ForegroundNotificationConfig(
        notificationTitle: 'Turno activo – compartiendo ubicación',
        notificationText: 'Vehículos oficiales',
        setOngoing: true,
        enableWakeLock: true,
      ),
    );
  }
  if (defaultTargetPlatform == TargetPlatform.iOS) {
    return AppleSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 0,
      activityType: ActivityType.automotiveNavigation,
      pauseLocationUpdatesAutomatically: false,
      allowBackgroundLocationUpdates: true,
      showBackgroundLocationIndicator: true,
    );
  }
  return const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 0);
}
```

   Al cambiar entre "en turno" (10 s) y "en viaje" (5 s) se cancela y se vuelve a abrir el stream con el intervalo nuevo. Privacidad (spec 10): el stream solo existe con el turno abierto y se cancela al finalizarlo (la notificación desaparece con él).
5. **Cola y envío (spec 6 y 9).** `ColaUbicaciones` en memoria, ordenada por `registrado_en`, sin duplicados (clave: `registrado_en` en milisegundos) y con tope de 5000 puntos (≈ 14 h a 10 s; si se llena se descartan los más viejos). `EmisorUbicacion` manda como máximo 500 puntos por pedido (el máximo que valida `UbicacionController`), de a un pedido por vez, en orden; **solo saca de la cola los puntos de un envío que respondió 204**. Sin conexión o 5xx: los puntos quedan y se reintenta en el ciclo siguiente. 422 "Iniciá un turno para compartir tu ubicación.": el turno se cerró desde afuera → se detiene el rastreo y se refresca el turno. 401: el aviso de sesión inválida de siempre. **(A confirmar:** persistir la cola en disco para no perder puntos si el sistema mata la app; en v1 no se persiste.) Límite conocido: si un envío llega al servidor pero se corta la respuesta, el reintento duplica esos puntos en `recorrido_viaje` (el backend no deduplica); es inofensivo para la posición actual y se puede resolver en el backend con un índice único `(viaje_id, registrado_en)`.
6. **Turno.** `TurnoNotifier` (`AsyncNotifier<Turno?>`): al abrir, `GET /api/turnos/actual`; si hay turno abierto, retoma el rastreo. `iniciar(vehiculoId)`: permiso → `POST /api/turnos` → rastreo. `finalizar()`: **primero vacía la cola** (un intento de envío), después `POST /api/turnos/actual/finalizar`; los 422 del backend ("Finalizá el viaje en curso antes de cerrar el turno.") se muestran y el turno sigue abierto; si finaliza, se detiene el rastreo y se descarta la cola. La pantalla "Iniciar turno" es la pieza que después reemplazará la asistencia (spec 7, chofer 1): no guarda lógica propia fuera de `TurnoNotifier`.
7. **Oferta entrante (spec 7, chofer 3).** Ruta `/chofer/oferta` a pantalla completa, abierta cuando `SeguimientoViaje.oferta` deja de ser nula (evento `oferta.creada` inmediato, push `tipo = oferta` o `GET /viajes/actual`). Muestra origen, destino, motivo, solicitante y la cuenta regresiva (decisión 2); "Aceptar" → `aceptarOferta` (pasa a "Viaje en curso"); "Rechazar" → `rechazarOferta`; al llegar a 0 muestra "La oferta venció" y vuelve al mapa. Un 422 "La oferta ya no está vigente." se muestra y cierra la pantalla. Los viajes **obligatorios** no generan oferta (el backend asigna directo): cuando llega por `chofer.{id}` un `viaje.actualizado` `aceptado` y `obligatorio` que no estaba, se muestra el aviso a pantalla completa **"Viaje asignado"** con un único botón "Ver viaje" (sin "Rechazar").
8. **Viaje en curso (spec 5.5, 5.6, 7 chofer 4).** Botón principal según el estado: `aceptado` → "Voy en camino", `en_camino` → "Llegué", `llego` → "Iniciar viaje", `en_curso` → "Finalizar" (`POST /viajes/{id}/estado`; los 422 —por ejemplo "Podés salir hacia esta reserva a partir de las 11:15."— se muestran). "Navegar" abre Google Maps (`google.navigation:q=lat,lng`, y si no se puede, `https://www.google.com/maps/dir/?api=1&destination=lat,lng`) o Waze (`https://waze.com/ul?ll=lat,lng&navigate=yes`) hacia el origen antes de `en_curso` y hacia el destino después. "Llamar" al solicitante si tiene teléfono. "Cancelar" pide un motivo obligatorio y **no se muestra** si el viaje es obligatorio, ni en `en_curso`, ni en una reserva que ya empezó (mismas reglas que `ServicioViaje::cancelarPorChofer`). Si el viaje deja de ser del chofer (cancelado por el solicitante o el admin, o reasignado), se muestra el motivo general ("El viaje fue cancelado" / "El viaje se reasignó") y "Volver al mapa".
9. **Sonido y vibración de la oferta.** v1: `HapticFeedback.vibrate()` y `SystemSound.play(SystemSoundType.alert)` al abrir la oferta y cada 5 s mientras está abierta (sin dependencias nuevas). **(A confirmar con el equipo de la app del PJ:** sonido propio y comportamiento con la app en segundo plano, que depende de la notificación FCM de alta prioridad que ya manda el backend y de cómo la muestre su app.)
10. **Mapa del chofer (spec 7, chofer 2).** Su posición (último punto del GPS), su estado (el suyo dentro de `choferesMapaProvider`: libre, en viaje, sin señal, reservado pronto), el vehículo del turno, la próxima reserva confirmada de la agenda destacada (fecha, destino, "Voy en camino" cuando falten menos de 45 min) y los accesos a "Agenda" y "Finalizar turno".
11. **Agenda (spec 7, chofer 5).** `GET /api/agenda`: reservas confirmadas y solicitudes pendientes, cada una con su vencimiento (reloj del servidor), "Aceptar" y "Rechazar". Se refresca con los avisos push `oferta_reserva`, `recordatorio_reserva` y `alerta_reserva`, con cualquier `viaje.actualizado` de una reserva y con "tirar para refrescar".
12. **Tests (spec 11).** Unitarios: modelos del chofer contra JSON reales, reloj del servidor, cola (orden, duplicados, tope), emisor (lotes de 500, reintento, 204/422/sin red) y `TurnoNotifier`, con `fake_async`, `ApiFalsa`, `TiempoRealFalso` y un `UbicadorFalso` que emite puntos a mano. Widgets: oferta entrante (cuenta regresiva desde `vence_en`, aceptar, rechazar, vencida, obligatorio → "Viaje asignado"), viaje en curso (pasos, navegar, llamar, cancelar con motivo, oculto si es obligatorio), iniciar turno (permiso denegado) y agenda.

### Ajustes ya conocidos del backend (revisar)

- **B1.** `GET /api/turnos/actual`, `POST /api/turnos` y `POST /api/turnos/actual/finalizar` devuelven el modelo Eloquent (no un Resource): fechas con microsegundos (`2026-10-01T12:00:00.000000Z`), `chofer_id`, `vehiculo_id`, `origen`, `created_at`, `updated_at`; `vehiculo` completo (con `activo` y fechas) al iniciar y en `actual`, **sin `vehiculo`** al finalizar.
- **B2.** `POST /api/ofertas/{id}/rechazar` responde 204; `aceptar` responde el `ViajeResource` del viaje ya asignado. Una oferta vencida o ya respondida responde 422 "La oferta ya no está vigente.".
- **B3.** Cuando el chofer cancela, la respuesta es el viaje **ya sin chofer** (`buscando` si es inmediato y se reasigna, `sin_chofer` si es una reserva). `ViajeActualNotifier` lo trata como "ya no es tuyo".
- **B4.** `POST /api/ubicacion` descarta la posición actual si el punto más nuevo es anterior a la guardada (no retrocede), pero sí guarda en `recorrido_viaje` todos los puntos de un viaje `en_curso`, incluidos los reenviados (decisión 5).
- **B5.** `viajes/actual` del chofer solo trae ofertas de viajes **inmediatos** vigentes; las de reservas están en `GET /api/agenda` (`solicitudes`).

## Global Constraints

Las del plan anterior, más:

- El GPS solo corre con el turno abierto y se detiene al finalizarlo o cuando el backend responde que no hay turno (spec 10). Ningún punto se manda fuera de `EmisorUbicacion`.
- Toda cuenta regresiva usa `relojServidorProvider` y el `vence_en` del backend.
- Las pantallas del chofer no llaman a la API para transiciones directamente: pasan por `ViajeActualNotifier` (`aceptarOferta`, `rechazarOferta`, `avanzar`, `cancelar`) o `TurnoNotifier`, que actualizan el estado con la respuesta.
- Los tests nunca instancian geolocator: `ubicadorProvider` siempre sobrescrito con `UbicadorFalso`.

## Review Focus

1. **La cuenta regresiva usa `vence_en` del servidor, no 30 s locales:** con `vence_en` a 12 s muestra 12; con el reloj del teléfono 1 min adelantado (desfase del encabezado `Date`) sigue mostrando lo que falta según el servidor; a 0 muestra "La oferta venció". Task 2 y Task 7.
2. **Los puntos guardados sin señal se mandan en orden y sin duplicados:** 3 ciclos sin red acumulan los puntos; al volver la red salen en un solo lote ordenado por `registrado_en`; un punto repetido del GPS no se encola dos veces; un lote que falló no se pierde ni se reordena; más de 500 se parten en lotes. Task 4.
3. **El GPS se corta al terminar el turno:** después de `finalizar()` (o de un 422 "Iniciá un turno…") el stream está cancelado, la cola vacía y no sale ningún `POST /api/ubicacion` más; `finalizar()` primero intenta vaciar la cola. Tasks 4 y 5.
4. **Un viaje obligatorio no se puede rechazar ni cancelar desde la app:** no hay pantalla de oferta sino "Viaje asignado", y el botón "Cancelar" no aparece. Tasks 7 y 8.
5. **El intervalo cambia con el viaje:** 10 s en turno, 5 s con un viaje activo (`aceptado` a `en_curso`), según `GET /api/configuracion`. Task 5.

## Estructura de archivos

```
paquete/vehiculos_oficiales/
  pubspec.yaml                                              # + http_parser
  lib/src/modelos/chofer.dart                               # Turno, Agenda, PuntoGps
  lib/src/modelos/modelos.dart                              # + export 'chofer.dart'
  lib/src/api/cliente_api.dart                              # + desfase del reloj (encabezado Date)
  lib/src/api/api_vehiculos.dart                            # + endpoints del chofer
  lib/src/api/reloj_servidor.dart                           # RelojServidor, relojServidorProvider
  lib/src/ubicacion/ubicador.dart                           # + seguir(), permiso(), pedirPermiso(), abrirAjustes(), ajustesGpsTurno()
  lib/src/chofer/cola_ubicaciones.dart                      # ColaUbicaciones
  lib/src/chofer/emisor_ubicacion.dart                      # EmisorUbicacion
  lib/src/chofer/rastreador_turno.dart                      # RastreadorTurno (GPS → cola → emisor, intervalo según viaje)
  lib/src/chofer/turno.dart                                 # TurnoNotifier, turnoProvider, configuracionProvider
  lib/src/chofer/agenda.dart                                # agendaProvider
  lib/src/viaje/viaje_actual.dart                           # + aceptarOferta, rechazarOferta, avanzar; asignación obligatoria
  lib/src/ui/modulo_app.dart                                # + rutas /chofer/*
  lib/src/ui/chofer/inicio_chofer.dart                      # reemplaza la provisoria: sin turno → iniciar; con turno → mapa
  lib/src/ui/chofer/{iniciar_turno,mapa_chofer,pantalla_oferta,viaje_asignado,viaje_chofer,agenda}.dart
  test/fixtures/payloads_chofer.dart                        # JSON reales de turnos, vehículos y agenda
  test/soporte/dobles.dart                                  # ApiFalsa + endpoints del chofer; UbicadorFalso + seguir()
  test/{modelos_chofer,reloj_servidor,cola_ubicaciones,emisor_ubicacion,turno}_test.dart
  test/ui/{pantalla_oferta,viaje_chofer,iniciar_turno,agenda}_test.dart
host_prueba/                                                # sin cambios de código; prueba manual como chofer
```

JSON reales para `test/fixtures/payloads_chofer.dart` (backend de `main`, reloj en 2026-10-01 12:00 UTC):

```text
GET  /api/vehiculos/disponibles -> 200
[{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}]

GET  /api/turnos/actual (sin turno) -> 200
{"turno":null}

POST /api/turnos {"vehiculo_id":1} -> 201
{"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","origen":"manual","updated_at":"2026-10-01T12:00:00.000000Z","created_at":"2026-10-01T12:00:00.000000Z","id":1,"vehiculo":{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}}

GET  /api/turnos/actual (con turno) -> 200
{"turno":{"id":1,"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","fin":null,"origen":"manual","created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z","vehiculo":{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}}}

POST /api/turnos/actual/finalizar -> 200
{"id":1,"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","fin":"2026-10-01T12:00:00.000000Z","origen":"manual","created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}

POST /api/ubicacion {"puntos":[{"lat":-26.83,"lng":-65.2,"rumbo":90,"velocidad":0,"registrado_en":"2026-10-01T11:59:50Z"}, …]} -> 204

POST /api/viajes/1/estado {"estado":"en_camino"} -> 200 (ViajeResource, "estado":"en_camino")

POST /api/viajes/1/cancelar {"motivo":"x"} (chofer) -> 200
{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"sin_chofer",…,"chofer":null,"vehiculo":null,…}

POST /api/viajes/1/estado (de otro chofer) -> 403 {"message":"Este viaje no es tuyo."}

GET  /api/agenda -> 200
{"reservas":[],"solicitudes":[{"id":3,"vence_en":"2026-10-01T12:30:00+00:00","viaje":{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}]}

GET  /api/agenda (como solicitante) -> 403 {"message":"No tenés permiso para esta acción."}
```

---

### Task 1: Modelos y endpoints del chofer

**Files:**
- Create: `lib/src/modelos/chofer.dart`, `test/fixtures/payloads_chofer.dart`
- Modify: `lib/src/modelos/modelos.dart`, `lib/src/api/api_vehiculos.dart`, `test/soporte/dobles.dart` (`ApiFalsa` con los endpoints nuevos)
- Test: `test/modelos_chofer_test.dart`, `test/api_test.dart` (casos nuevos)

**Interfaces:**
- Consumes: `ClienteApi`, `leerFecha`, `Vehiculo`, `Viaje`, `Oferta`, `EstadoViaje` (plan anterior).
- Produces:
  - `Turno({required int id, required Vehiculo? vehiculo, required DateTime inicio, DateTime? fin})` + `Turno.fromJson`, `bool get abierto`.
  - `Agenda({required List<Viaje> reservas, required List<Oferta> solicitudes})` + `Agenda.fromJson`.
  - `PuntoGps({required Coordenada posicion, double? rumbo, double? velocidad, required DateTime registradoEn})` + `Json toJson()` (`lat`, `lng`, `rumbo`, `velocidad`, `registrado_en` con `escribirFecha`).
  - En `ApiVehiculos`: `Future<List<Vehiculo>> vehiculosDisponibles()`, `Future<Turno?> turnoActual()`, `Future<Turno> iniciarTurno(int vehiculoId)`, `Future<Turno> finalizarTurno()`, `Future<void> enviarUbicacion(List<PuntoGps> puntos)`, `Future<Viaje> aceptarOferta(int ofertaId)`, `Future<void> rechazarOferta(int ofertaId)`, `Future<Viaje> avanzarViaje(int viajeId, EstadoViaje estado)`, `Future<Agenda> agenda()`.
- Tests: parseo de los JSON de arriba (incluido finalizar sin `vehiculo`); cuerpos exactos de `POST /turnos`, `POST /ubicacion` (fechas UTC con `Z`), `POST /viajes/{id}/estado` (`{"estado":"en_camino"}`); 204 de `rechazar` y `ubicacion`; 403 y 422 con su `message`.
- Commit: `feat: modelos y endpoints del chofer en el cliente de la API`

### Task 2: Reloj del servidor

**Files:**
- Create: `lib/src/api/reloj_servidor.dart`
- Modify: `pubspec.yaml` (`http_parser: ^4.1.2`), `lib/src/api/cliente_api.dart`
- Test: `test/reloj_servidor_test.dart`

**Interfaces:**
- Consumes: `ClienteApi` (plan anterior), `package:clock`.
- Produces:
  - En `ClienteApi`: `Duration desfaseReloj` (servidor − dispositivo, se actualiza con el encabezado `Date` de cada respuesta 2xx/4xx; sin encabezado no cambia).
  - `class RelojServidor { RelojServidor(ClienteApi cliente); DateTime ahora(); Duration restante(DateTime vence); }` (`restante` nunca negativa) y `relojServidorProvider`.
- Tests: sin `Date` el desfase es cero; con `Date` 60 s adelantado, `ahora()` = `clock.now() + 60 s` (con `withClock`); `restante` a 0 cuando ya venció.
- Commit: `feat: reloj del servidor para las cuentas regresivas`

### Task 3: GPS del turno y permisos

**Files:**
- Modify: `lib/src/ubicacion/ubicador.dart`, `test/soporte/dobles.dart` (`UbicadorFalso` con `seguir`, `permiso`, `emitir(PuntoGps)`)
- Test: `test/ubicador_test.dart` (solo `ajustesGpsTurno` con `debugDefaultTargetPlatformOverride`; el resto se prueba a través de los dobles)

**Interfaces:**
- Consumes: `Ubicador`, `ubicadorProvider` (plan anterior), geolocator 14.1.1.
- Produces:
  - `enum PermisoUbicacion { concedido, denegado, denegadoParaSiempre, gpsApagado }`.
  - En `Ubicador`: `Future<PermisoUbicacion> pedirPermiso()`, `Stream<PuntoGps> seguir(Duration intervalo)`, `Future<void> abrirAjustes()`.
  - `LocationSettings ajustesGpsTurno(Duration intervalo)` (decisión 4).
- Tests: en Android los ajustes son `AndroidSettings` con `intervalDuration` pedido y la notificación "Turno activo – compartiendo ubicación" con `setOngoing: true`; en iOS, `AppleSettings` con `allowBackgroundLocationUpdates: true` y `pauseLocationUpdatesAutomatically: false`.
- Commit: `feat: GPS del turno con servicio en primer plano y permisos`

### Task 4: Cola y envío de ubicaciones

**Files:**
- Create: `lib/src/chofer/cola_ubicaciones.dart`, `lib/src/chofer/emisor_ubicacion.dart`
- Test: `test/cola_ubicaciones_test.dart`, `test/emisor_ubicacion_test.dart`

**Interfaces:**
- Consumes: `ApiVehiculos.enviarUbicacion`, `PuntoGps`, errores de la API.
- Produces:
  - `ColaUbicaciones({int tope = 5000})`: `void agregar(PuntoGps)` (ignora un `registradoEn` repetido, mantiene el orden aunque llegue uno más viejo, descarta los más viejos al pasar el tope), `List<PuntoGps> primeros(int n)`, `void quitarPrimeros(int n)`, `int get largo`, `void vaciar()`.
  - `enum ResultadoEnvio { enviado, sinCambios, reintentar, sinTurno }` y `EmisorUbicacion({required ApiVehiculos api, required ColaUbicaciones cola, int lote = 500})`: `Future<ResultadoEnvio> enviar()` (un pedido por vez; si ya hay uno en curso devuelve `sinCambios`), `Future<void> vaciarTodo()` (manda lotes hasta vaciar o hasta el primer error).
- Tests (con `ApiFalsa` que registra los lotes): orden por `registrado_en`; sin duplicados; lote de 500 + resto; 204 quita exactamente lo enviado; `SinConexion` y `ErrorServidor` no quitan nada y el próximo envío manda lo mismo en el mismo orden; 422 "Iniciá un turno…" → `sinTurno`; dos `enviar()` simultáneos → un solo POST.
- Commit: `feat: cola ordenada de ubicaciones con envío por lotes y reintento`

### Task 5: Turno y rastreo

**Files:**
- Create: `lib/src/chofer/rastreador_turno.dart`, `lib/src/chofer/turno.dart`
- Test: `test/turno_test.dart`

**Interfaces:**
- Consumes: `Ubicador.seguir/pedirPermiso` (Task 3), `ColaUbicaciones`, `EmisorUbicacion` (Task 4), `viajeActualProvider`, `apiProvider.configuracion/turnoActual/iniciarTurno/finalizarTurno`.
- Produces:
  - `configuracionProvider` (`FutureProvider<Configuracion>`, `GET /api/configuracion`).
  - `RastreadorTurno({ubicador, emisor, cola, Duration intervaloTurno, Duration intervaloViaje})`: `void iniciar()`, `void enViaje(bool activo)` (reabre el stream con el otro intervalo y cambia el ritmo de envío), `Future<void> detener()` (cancela stream y timer), `Stream<PuntoGps> get ultimaPosicion`, `bool get activo`.
  - `turnoProvider` (`AsyncNotifier<Turno?>`) con `Future<PermisoUbicacion> iniciar(int vehiculoId)` (si el permiso no es `concedido` no llama a la API y lo devuelve), `Future<void> finalizar()`; escucha `viajeActualProvider` para `enViaje` (activo en `aceptado`, `en_camino`, `llego`, `en_curso`).
- Tests (`fake_async`): sin turno no se sigue el GPS; con turno abierto al abrir, retoma; los puntos del `UbicadorFalso` salen cada 10 s y cada 5 s con un viaje activo; `finalizar()` vacía la cola antes de `POST /turnos/actual/finalizar` y después no sale ningún `POST /ubicacion`; un 422 al finalizar deja el turno y el rastreo como estaban; un 422 "Iniciá un turno…" del emisor detiene el rastreo y refresca el turno; permiso denegado → no se llama a `POST /turnos`.
- Commit: `feat: turno del chofer con rastreo GPS mientras está abierto`

### Task 6: Pantallas de turno y mapa del chofer

**Files:**
- Create: `lib/src/ui/chofer/iniciar_turno.dart`, `lib/src/ui/chofer/mapa_chofer.dart`
- Modify (reemplazar): `lib/src/ui/chofer/inicio_chofer.dart`; Modify: `lib/src/ui/modulo_app.dart` (rutas `/chofer/agenda`, `/chofer/oferta`, `/chofer/asignado`, `/chofer/viaje`)
- Test: `test/ui/iniciar_turno_test.dart`

**Interfaces:**
- Consumes: `turnoProvider`, `apiProvider.vehiculosDisponibles`, `choferesMapaProvider` (estado propio), `agendaProvider` (Task 9: hasta entonces la próxima reserva no se muestra), `constructorMapaProvider`, `viajeActualProvider`.
- Produces:
  - `InicioChofer`: cargando → `IniciarTurno` si no hay turno, `MapaChofer` si hay; como `InicioSolicitante`, pasa a `/chofer/oferta`, `/chofer/asignado` o `/chofer/viaje` cuando aparece una oferta, una asignación obligatoria o un viaje.
  - `IniciarTurno`: lista de `vehiculosDisponibles` (patente, marca, modelo, color), "Iniciar turno"; permiso denegado → texto de spec 9 + "Abrir ajustes"; 422 ("El vehículo está en uso por otro chofer.") → mensaje y recarga la lista.
  - `MapaChofer`: decisión 10, con "Finalizar turno" (confirmación; 422 visible).
- Tests: lista de vehículos y `POST /turnos` con el elegido; permiso denegado muestra "Abrir ajustes" y no llama a la API; vehículo ocupado muestra el mensaje; finalizar turno con viaje activo muestra "Finalizá el viaje en curso antes de cerrar el turno.".
- Commit: `feat: iniciar turno y mapa del chofer`

### Task 7: Oferta entrante y "Viaje asignado"

**Files:**
- Create: `lib/src/ui/chofer/pantalla_oferta.dart`, `lib/src/ui/chofer/viaje_asignado.dart`
- Modify: `lib/src/viaje/viaje_actual.dart` (`aceptarOferta`, `rechazarOferta`, `obligatorioNuevo`)
- Test: `test/ui/pantalla_oferta_test.dart`, `test/viaje_actual_test.dart` (casos nuevos)

**Interfaces:**
- Consumes: `viajeActualProvider` (plan anterior), `relojServidorProvider` (Task 2), `ApiVehiculos.aceptarOferta/rechazarOferta` (Task 1).
- Produces:
  - En `ViajeActualNotifier`: `Future<void> aceptarOferta()` (usa la oferta del estado; con la respuesta fija el viaje y borra la oferta), `Future<void> rechazarOferta()` (borra la oferta), y en `SeguimientoViaje` el campo `bool asignadoSinOferta` (un `aceptado` obligatorio que llegó sin oferta previa; se limpia con `verViajeAsignado()`).
  - `PantallaOferta` (`/chofer/oferta`) y `ViajeAsignado` (`/chofer/asignado`), decisión 7 y 9.
- Tests: con `vence_en` 12 s en el futuro muestra "12 s" y un segundo después "11 s" (no "30"); con el reloj del servidor adelantado 60 s y `vence_en` a 70 s del reloj del teléfono muestra "10 s"; "Aceptar" → `POST /ofertas/1/aceptar` y pasa a "Viaje en curso"; "Rechazar" → `POST /ofertas/1/rechazar` y vuelve al mapa; a 0 → "La oferta venció"; 422 "La oferta ya no está vigente." → mensaje y vuelve al mapa; un `viaje.actualizado` obligatorio `aceptado` → "Viaje asignado" sin botón "Rechazar".
- Commit: `feat: oferta entrante a pantalla completa con cuenta regresiva del servidor`

### Task 8: Viaje en curso del chofer

**Files:**
- Create: `lib/src/ui/chofer/viaje_chofer.dart`
- Modify: `lib/src/viaje/viaje_actual.dart` (`avanzar(EstadoViaje)`)
- Test: `test/ui/viaje_chofer_test.dart`

**Interfaces:**
- Consumes: `viajeActualProvider.avanzar/cancelar/descartar`, `lanzadorUrlProvider`, `constructorMapaProvider`, `RastreadorTurno.ultimaPosicion` (posición propia en el mapa).
- Produces: `ViajeChofer` (`/chofer/viaje`), decisión 8; en `ViajeActualNotifier`: `Future<void> avanzar(EstadoViaje hacia)`.
- Tests: la secuencia "Voy en camino" → "Llegué" → "Iniciar viaje" → "Finalizar" manda cada `POST /viajes/1/estado` y termina en "Viaje finalizado"; "Navegar" abre `google.navigation:q=<origen>` antes de `en_curso` y `<destino>` después (y Waze con su URL); "Llamar" abre `tel:` del solicitante; "Cancelar" pide motivo, no deja confirmar vacío y manda `{"motivo": …}`; con `obligatorio: true` no hay "Cancelar"; en `en_curso` tampoco; el 422 de una reserva que todavía no se puede empezar se muestra; si el viaje vuelve con `chofer: null` (cancelado por el solicitante o reasignado) se muestra el aviso y "Volver al mapa".
- Commit: `feat: viaje en curso del chofer con pasos, navegación externa y cancelación`

### Task 9: Agenda

**Files:**
- Create: `lib/src/chofer/agenda.dart`, `lib/src/ui/chofer/agenda.dart`
- Test: `test/ui/agenda_test.dart`

**Interfaces:**
- Consumes: `apiProvider.agenda/aceptarOferta/rechazarOferta`, `pushModuloProvider.avisos`, `viajeActualProvider` (eventos de reservas), `relojServidorProvider`.
- Produces: `agendaProvider` (`FutureProvider.autoDispose<Agenda>`, se invalida con avisos `oferta_reserva`, `recordatorio_reserva`, `alerta_reserva` y `viaje`), `AgendaPantalla` (`/chofer/agenda`), decisión 11.
- Tests: muestra reservas y solicitudes con su vencimiento; "Aceptar" y "Rechazar" llaman a la API y recargan; un 422 ("La reserva ya no está disponible o se superpone con otra de tu agenda.") se muestra; un push `oferta_reserva` recarga.
- Commit: `feat: agenda del chofer con reservas y solicitudes`

### Task 10: Prueba punta a punta como chofer

**Files:**
- Modify (si hiciera falta): `host_prueba/android/app/src/main/AndroidManifest.xml`, `host_prueba/ios/Runner/Info.plist` (ya tienen los permisos desde el plan anterior)

**Pasos:**
- Correr la suite completa del paquete y de `host_prueba`, `flutter analyze` en los dos y `flutter build apk --debug`.
- Manual (spec 11): en el panel, darle rol `chofer` a "Carlos Chofer" (id externo 200) y crear un vehículo; en un teléfono o emulador con `host_prueba`, entrar como Carlos, iniciar turno, verificar la notificación fija "Turno activo – compartiendo ubicación" con la app en segundo plano y los puntos en el panel (mapa en vivo); desde Chrome (`flutter run -d chrome`) entrar como "Ana Pérez" y pedir un auto; aceptar la oferta en el teléfono, recorrer los pasos con navegación externa y finalizar; repetir con "Jorge Juez" marcado como cargo obligatorio para ver "Viaje asignado" sin "Cancelar"; activar el modo avión 1 minuto durante el viaje y comprobar que al volver los puntos llegan en orden (panel: recorrido del viaje); finalizar el turno y verificar que la notificación desaparece.
- Commit: `test: prueba punta a punta del chofer con host_prueba` (solo si hubo cambios)

## Cobertura del spec en este plan

| Spec | Task |
|---|---|
| 4.1 Estado del chofer (se muestra el calculado por el backend) | 6 |
| 5.1 / 5.2 Aceptar o rechazar una oferta; obligatorio asignado directo | 7 |
| 5.4 Reserva: aceptar/rechazar solicitudes; salir hacia la reserva (no antes de tiempo) | 8, 9 |
| 5.5 Pasos del viaje, navegación externa, recorrido en `en_curso` | 4, 5, 8 |
| 5.6 Chofer cancela con motivo; obligatorio no | 8 |
| 6 Envío de ubicación por lotes; `chofer.{id}`; reconexión | 4, 5, 7 |
| 7 Chofer 1–6; GPS en segundo plano con notificación fija | 3, 5, 6, 7, 8, 9 |
| 9 Sin señal: guardar y enviar al reconectar; permiso denegado: no se inicia turno | 4, 6 |
| 10 La ubicación solo se envía con turno abierto | 5 |
| 11 Tests de providers y widgets de la oferta entrante; prueba manual | todas, 10 |

**Fuera de este plan:** `TurnoAsistencia` (spec 13), sonido propio de la oferta y persistencia de la cola en disco (decisiones 5 y 9, a confirmar), deduplicación del recorrido en el backend (decisión 5).
