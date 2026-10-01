# Robustez del chofer: cola de ubicaciones persistente y fin de viaje sin conexión — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** (1) Que los puntos GPS pendientes de envío sobrevivan a que el sistema mate la app durante un turno. (2) Que el chofer vea cómo terminó su viaje (cancelado, finalizado o reasignado) aunque haya pasado mientras no tenía conexión en tiempo real, en vez de volver al mapa sin explicación.

**Architecture:** Backend Laravel en `backend/`; paquete Flutter `paquete/vehiculos_oficiales/` (Riverpod 3 sin generación de código, dio vía `ClienteApi`/`ApiVehiculos`, notifiers dueños de timers y streams). Se apoya en `ColaUbicaciones`/`EmisorUbicacion`/`RastreadorTurno`/`TurnoNotifier` (lib/src/chofer/) y en `ViajeActualNotifier` (lib/src/viaje/viaje_actual.dart).

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md` (6, 9, 10). Planes previos: `2026-09-30-flutter-chofer.md` (decisión 5 dejó "persistir la cola en disco" como pendiente; ajuste conocido: el chofer no consulta historial cuando un viaje desaparece sin socket).

## Decisiones

1. **Endpoint de detalle:** `GET /api/viajes/{viaje}` → `ViajeResource` (con `chofer`, `vehiculo`, `solicitante`). Puede verlo el solicitante del viaje, su chofer **actual** o un admin; cualquier otro recibe 403 `{"message": "Este viaje no es tuyo."}` (mismo texto que el resto). Un chofer al que le reasignaron el viaje recibe 403: la app lo interpreta como "ya no es tuyo".
2. **Fin de viaje sin conexión (chofer):** cuando el viaje que el chofer seguía desaparece de `GET /api/viajes/actual` (sondeo o refresco al reconectar), `ViajeActualNotifier` consulta `GET /api/viajes/{id}`:
   - si vuelve `cancelado` o `finalizado` → se muestra como viaje terminado (las pantallas de fin de `ViajeChofer` que ya existen) y se registra como final (regla de `_finales`);
   - si vuelve 403, o el viaje tiene otro chofer → se trata como "el viaje ya no es tuyo" (la pantalla de fin que ya existe para reasignado);
   - otros errores (`ErrorApi`) → se comporta como hoy (sin viaje), sin errores sin capturar.
   El solicitante sigue usando el historial como hoy.
3. **Cola persistente:** interfaz `AlmacenCola` con `leer(int turnoId)`, `guardar(int turnoId, List<PuntoGps>)` y `borrar()`. Implementación real en un archivo JSON (`cola_ubicaciones.json`) en el directorio de soporte de la app (`path_provider`, `getApplicationSupportDirectory`); en web (`kIsWeb`) no persiste (no-op). Los tests usan un almacén en memoria.
   - Se guarda la cola **completa** (tope 5000) **agrupando escrituras**: como mucho una escritura cada 5 s mientras entran puntos, y también después de que un envío confirmado saca puntos. No hay un aviso confiable de que el sistema va a matar la app, así que lo que protege es el guardado periódico (se pueden perder, como mucho, los últimos 5 s).
   - Al retomar un turno abierto (`TurnoNotifier.build` → `GET /turnos/actual`), si el archivo es del **mismo `turno_id`** se cargan sus puntos en la cola antes de abrir el GPS; si es de otro turno, se borra.
   - **Privacidad (spec 10):** el archivo se borra al finalizar el turno, cuando el backend dice que no hay turno (`sinTurno`) y al cerrar sesión (401). Nunca se guardan puntos sin turno abierto.
   - Errores de disco (lectura/escritura/JSON corrupto): se loguea el tipo con `debugPrint` y se sigue sin persistir; nunca rompen el rastreo.
4. **Dependencia nueva:** `path_provider` (la última compatible con Flutter 3.41 / Dart 3.11; `pub` la resuelve).

## Global Constraints

- Nombres, textos y tests en español; patrones existentes del paquete y del backend.
- Paquete: `flutter analyze` sin problemas, `dart format --output=none --set-exit-if-changed lib test` sin cambios y todos los tests en verde al final de cada task; `host_prueba` sigue pasando `flutter test` y `flutter analyze`. Backend: `./vendor/bin/pest` en verde.
- Nada de errores asíncronos sin capturar; `ref.mounted` después de cada `await`; timers/streams de los notifiers liberados en `onDispose`; los tests no instancian geolocator, Google Maps, red ni `path_provider` real.
- Toda llamada HTTP pasa por `ClienteApi`/`ApiVehiculos` (parseo con `_leer`).
- Cada commit con un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — detalle de un viaje

**Files:** `backend/app/Http/Controllers/ViajeController.php` (método `show`), `backend/routes/api.php` (dentro del grupo `auth:sanctum` + `activo`), test `backend/tests/Feature/DetalleViajeTest.php`.

Tests: el solicitante, el chofer actual y un admin reciben 200 con el `ViajeResource` (verificar `estado`, `chofer.id`); otro usuario y un chofer anterior (tras reasignación del admin — ver `ServicioViaje::reasignarPorAdmin`) reciben 403 con el mensaje exacto; un viaje cancelado sigue visible para su chofer (el chofer queda en `chofer_id` al cancelar el solicitante).

Commit: `feat: detalle de un viaje para su solicitante, su chofer o un admin`

### Task 2: Flutter — el chofer ve cómo terminó un viaje aunque no tuviera conexión

**Files:** `lib/src/api/api_vehiculos.dart` (`Future<Viaje> viaje(int id)`), `lib/src/viaje/viaje_actual.dart` (búsqueda del viaje desaparecido para el chofer, según la decisión 2), `test/soporte/dobles_chofer.dart` (o `dobles.dart`) para el doble, tests en `test/viaje_actual_chofer_test.dart` y un test de widget en `test/ui/viaje_chofer_test.dart` (el chofer sin socket: el viaje se cancela en el servidor → tras el sondeo ve la pantalla de "cancelado", no el mapa).

Tests: cancelado y finalizado sin socket → pantalla/estado terminal y registrado como final; 403 → "ya no es tuyo"; viaje con otro chofer → "ya no es tuyo"; error de red en la consulta → sin viaje, sin excepción; el solicitante no cambia.

Commit: `feat: el chofer ve cómo terminó su viaje aunque haya pasado sin conexión`

### Task 3: Flutter — cola de ubicaciones persistente

**Files:** `pubspec.yaml` (`path_provider`), nuevo `lib/src/chofer/almacen_cola.dart` (`AlmacenCola`, `AlmacenColaArchivo`, provider `almacenColaProvider`), cambios en `cola_ubicaciones.dart` (exportar/cargar puntos), `rastreador_turno.dart` y/o `turno.dart` (cargar al retomar, guardar agrupado, borrar según la decisión 3), doble `AlmacenColaMemoria` en `test/soporte/`, tests `test/almacen_cola_test.dart` (JSON ida y vuelta, archivo corrupto → lista vacía sin lanzar, en un directorio temporal) y casos nuevos en `test/turno_test.dart`.

Tests: puntos encolados y no enviados se guardan (a lo sumo una escritura cada 5 s); al "reiniciar" (nuevo contenedor con el mismo almacén y el mismo turno abierto) los puntos vuelven a la cola y se envían en orden; archivo de otro turno → se descarta; finalizar turno / `sinTurno` / 401 → se borra; un error del almacén no rompe el rastreo; tras un envío confirmado el archivo refleja la cola reducida.

Commit: `feat: la cola de ubicaciones del turno sobrevive a que el sistema cierre la app`
