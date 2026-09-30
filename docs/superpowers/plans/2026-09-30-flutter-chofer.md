# App Flutter: chofer — Vehículos Oficiales — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Estado de este plan:** completo y **verificado**. Todo el código de los pasos se escribió y se probó, tarea por tarea y en este orden, en un directorio aparte con una copia de `paquete/` y `host_prueba/` de `main` (después de ejecutar `2026-09-30-flutter-base-solicitante.md` y sus correcciones), con Flutter 3.41.6 / Dart 3.11.4. Los bloques de código de este archivo se copiaron por script desde esos archivos (no se tipearon de nuevo). El backend de la Task 10 se probó en una copia de `backend/` con la suite completa de Pest. Detalle en "Verificación previa".

**Goal:** Que un chofer abra el módulo, inicie su turno eligiendo un vehículo (con permiso de ubicación), comparta su posición en lotes mientras el turno está abierto (también en segundo plano, con la notificación fija "Turno activo – compartiendo ubicación", y guardando los puntos si no hay señal), reciba ofertas a pantalla completa con cuenta regresiva según el vencimiento del servidor (o el aviso "Viaje asignado" si es obligatorio), avance el viaje paso a paso con navegación externa, llamar y cancelar con motivo (salvo obligatorios), gestione su agenda de reservas y solicitudes, y finalice el turno.

**Architecture:** Sobre la base del plan anterior. `ViajeActualNotifier` ya escucha `chofer.{id}` (ofertas inmediatas, asignaciones y cambios de su viaje) y suma `aceptarOferta`, `rechazarOferta`, `avanzar`, `salirHaciaReserva` y el aviso de "asignado sin oferta". Un `TurnoNotifier` maneja el turno y, mientras está abierto, un `RastreadorTurno` une el GPS (`Ubicador.seguir`, geolocator con servicio en primer plano) con una `ColaUbicaciones` y un `EmisorUbicacion` que manda lotes a `POST /api/ubicacion` cada `gps_turno_seg` (10 s) o `gps_viaje_seg` (5 s) con un viaje activo (`GET /api/configuracion`). Las pantallas del chofer cuelgan de `/chofer` en el mismo router del módulo. El backend suma un índice único en `recorrido_viaje` para que un lote reenviado no duplique puntos.

**Tech Stack:** el del plan anterior (Flutter 3.41.6 / Dart 3.11.4, flutter_riverpod 3.3.2, go_router 17.5.0, dio 5.11.1, dart_pusher_channels 1.3.1, google_maps_flutter 2.18.1, geolocator 14.1.1, url_launcher 6.3.2, clock 1.1.2, fake_async 1.3.3) más `http_parser` 4.1.2 como dependencia directa (ya estaba en el `pubspec.lock` como transitiva de dio; se usa para leer el encabezado `Date`). Sin paquetes de sonido en v1 (decisión 9). Backend: Laravel 12 / Pest de `backend/` (PHP 8.2).

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md` (secciones 4.1, 5.1–5.6, 6, 7 —chofer—, 9, 10 y 11)

**Plan anterior (obligatorio):** `docs/superpowers/plans/2026-09-30-flutter-base-solicitante.md` (paquete, sesión, API, Reverb con respaldo, push, router, `host_prueba`). Este plan parte del código que quedó en `main` después de ejecutarlo y corregirlo (el texto de ese plan difiere del código en algunos lugares: manda el código).

**Verificación previa:** en `paquete/vehiculos_oficiales`, al final de cada task: `flutter analyze` sin problemas, `dart format --output=none --set-exit-if-changed lib test` sin cambios y todos los tests en verde. Cantidad de tests del paquete (partiendo de los 123 de `main`): Task 1 → 137, Task 2 → 141, Task 3 → 144, Task 4 → 157, Task 5 → 170, Task 6 → 178, Task 7 → 192, Task 8 → 211, Task 9 → 218. Cada estado intermedio se volvió a construir desde cero (checkout de cada task) y se corrió de nuevo. En `host_prueba` (sin cambios de código): `flutter analyze` sin problemas, 3 tests en verde, `flutter build web` correcto y `flutter build apk --debug` correcto (~90 s). Backend (Task 10): suite completa de Pest en una copia de `backend/` (vendor copiado, SQLite en memoria): de 318 a **321 tests en verde**; los tests nuevos fallan sin el cambio (sin índice: se duplican los puntos; con el índice pero sin `insertOrIgnore`: el reenvío responde 500).

## Decisiones

Las marcadas **(A confirmar con el equipo de la app del PJ)** dependen de ese equipo (spec 12). El plan las implementa tal cual, salvo lo que se detalla en "Ajustes que obligó el código real".

1. **Modelos y endpoints del chofer** (sobre `ApiVehiculos`): `vehiculosDisponibles()`, `turnoActual()`, `iniciarTurno(vehiculoId)`, `finalizarTurno()`, `enviarUbicacion(List<PuntoGps>)`, `aceptarOferta(id) → Viaje`, `rechazarOferta(id)` (204), `avanzarViaje(id, EstadoViaje) → Viaje` (`en_camino`, `llego`, `en_curso`, `finalizado`), `cancelarViaje(id, motivo:)` (ya existe; para el chofer el motivo es obligatorio) y `agenda() → Agenda`. `Turno {id, vehiculo, inicio, fin?}` se lee del JSON de Eloquent (fechas `…000000Z`, campos extra ignorados). `Agenda {reservas: List<Viaje>, solicitudes: List<Oferta>}`.
2. **Reloj del servidor.** La cuenta regresiva de una oferta usa **`vence_en` del servidor**, nunca "30 s desde que llegó". Para que un reloj del teléfono adelantado o atrasado no la rompa, `ClienteApi` guarda el desfase `Date (respuesta) − ahora (dispositivo)` de la última respuesta (`parseHttpDate` de `http_parser`) y `relojServidorProvider` expone `ahora()` corregido. Restante = `vence_en − reloj.ahora()`, recalculado cada segundo con un `Timer` (no se descuenta un contador).
3. **Permisos de ubicación.** Iniciar turno exige como mínimo "mientras se usa la app": el GPS corre en un **servicio en primer plano de tipo `location`** que se inicia con la app en primer plano, y así Android sigue entregando posiciones con la app en segundo plano sin `ACCESS_BACKGROUND_LOCATION`. Si el permiso se niega, la pantalla lo explica y ofrece "Abrir ajustes" (`Geolocator.openAppSettings`, o los de ubicación si el GPS está apagado), spec 9. Pedir "permitir siempre" queda como mejora **(A confirmar con el equipo de la app del PJ:** política de Google Play para ubicación en segundo plano y si el PJ ya la justifica; en iOS, "Siempre" + `UIBackgroundModes: location` es necesario para seguir con la pantalla bloqueada).
4. **GPS del turno** (`Ubicador.seguir(intervalo)` sobre `Geolocator.getPositionStream`), con los ajustes de `ajustesGpsTurno` (Task 3; verificados contra geolocator 14.1.1): en Android `AndroidSettings` con `intervalDuration` y `ForegroundNotificationConfig` ("Turno activo – compartiendo ubicación", `setOngoing: true`, `enableWakeLock: true`); en iOS `AppleSettings` con `allowBackgroundLocationUpdates: true`, `pauseLocationUpdatesAutomatically: false` y `ActivityType.automotiveNavigation`; en el resto (web) `LocationSettings` comunes. Al cambiar entre "en turno" (10 s) y "en viaje" (5 s) se cancela y se vuelve a abrir el stream con el intervalo nuevo. Privacidad (spec 10): el stream solo existe con el turno abierto y se cancela al finalizarlo (la notificación desaparece con él).
5. **Cola y envío (spec 6 y 9).** `ColaUbicaciones` en memoria, ordenada por `registrado_en`, sin duplicados (clave: `registrado_en` en milisegundos) y con tope de 5000 puntos (≈ 14 h a 10 s; si se llena se descartan los más viejos). `EmisorUbicacion` manda como máximo 500 puntos por pedido (el máximo que valida `UbicacionController`), de a un pedido por vez, en orden; **solo saca de la cola los puntos de un envío que respondió 204**. Sin conexión o 5xx: los puntos quedan y se reintenta en el ciclo siguiente. 422 "Iniciá un turno para compartir tu ubicación.": el turno se cerró desde afuera → se detiene el rastreo y se refresca el turno. 401: el aviso de sesión inválida de siempre. **(A confirmar:** persistir la cola en disco para no perder puntos si el sistema mata la app; en v1 no se persiste.) Si un envío llega al servidor pero se corta la respuesta, el reintento manda de nuevo esos puntos: desde la Task 10 el backend los ignora (índice único `(viaje_id, registrado_en)` en `recorrido_viaje`).
6. **Turno.** `TurnoNotifier` (`AsyncNotifier<Turno?>`): al abrir, `GET /api/turnos/actual`; si hay turno abierto, retoma el rastreo. `iniciar(vehiculoId)`: permiso → `POST /api/turnos` → rastreo. `finalizar()`: **primero vacía la cola** (un intento de envío), después `POST /api/turnos/actual/finalizar`; los 422 del backend ("Finalizá el viaje en curso antes de cerrar el turno.") se muestran y el turno sigue abierto; si finaliza, se detiene el rastreo y se descarta la cola. La pantalla "Iniciar turno" es la pieza que después reemplazará la asistencia (spec 7, chofer 1): no guarda lógica propia fuera de `TurnoNotifier`.
7. **Oferta entrante (spec 7, chofer 3).** Ruta `/chofer/oferta` a pantalla completa, abierta cuando `SeguimientoViaje.oferta` deja de ser nula (evento `oferta.creada` inmediato, push `tipo = oferta` o `GET /viajes/actual`). Muestra origen, destino, motivo, solicitante y la cuenta regresiva (decisión 2); "Aceptar" → `aceptarOferta` (pasa a "Viaje en curso"); "Rechazar" → `rechazarOferta`; al llegar a 0 muestra "La oferta venció" y vuelve al mapa. Un 422 "La oferta ya no está vigente." se muestra y cierra la pantalla. Los viajes **obligatorios** no generan oferta (el backend asigna directo): cuando llega un `aceptado` que no estaba y del que no hubo oferta, se muestra el aviso a pantalla completa **"Viaje asignado"** con un único botón "Ver viaje" (sin "Rechazar").
8. **Viaje en curso (spec 5.5, 5.6, 7 chofer 4).** Botón principal según el estado: `aceptado` → "Voy en camino", `en_camino` → "Llegué", `llego` → "Iniciar viaje", `en_curso` → "Finalizar" (`POST /viajes/{id}/estado`; los 422 —por ejemplo "Podés salir hacia esta reserva a partir de las 11:15."— se muestran). "Navegar" abre Google Maps (`google.navigation:q=lat,lng`, y si no se puede, `https://www.google.com/maps/dir/?api=1&destination=lat,lng`) o Waze (`https://waze.com/ul?ll=lat,lng&navigate=yes`) hacia el origen antes de `en_curso` y hacia el destino después. "Llamar" al solicitante si tiene teléfono. "Cancelar" pide un motivo obligatorio y **no se muestra** si el viaje es obligatorio, ni en `en_curso`, ni en una reserva que ya empezó (mismas reglas que `ServicioViaje::cancelarPorChofer`). Si el viaje deja de ser del chofer (cancelado por el solicitante o el admin, o reasignado), se muestra el motivo general ("El viaje fue cancelado" / "El viaje se reasignó a otro chofer") y "Volver al mapa".
9. **Sonido y vibración de la oferta.** v1: `HapticFeedback.vibrate()` y `SystemSound.play(SystemSoundType.alert)` al abrir la oferta y cada 5 s mientras está abierta (sin dependencias nuevas). **(A confirmar con el equipo de la app del PJ:** sonido propio y comportamiento con la app en segundo plano, que depende de la notificación FCM de alta prioridad que ya manda el backend y de cómo la muestre su app.)
10. **Mapa del chofer (spec 7, chofer 2).** Su posición (último punto del GPS), su estado (el suyo dentro de `choferesMapaProvider`: libre, en viaje, sin señal, reservado pronto), el vehículo del turno, la próxima reserva confirmada de la agenda destacada (fecha, destino y "Voy en camino") y los accesos a "Agenda" y "Finalizar turno".
11. **Agenda (spec 7, chofer 5).** `GET /api/agenda`: reservas confirmadas y solicitudes pendientes, cada una con su vencimiento, "Aceptar" y "Rechazar". Se refresca con los avisos push `oferta_reserva`, `recordatorio_reserva`, `alerta_reserva` y `viaje`, con cualquier evento de una reserva en `chofer.{id}` y con "tirar para refrescar".
12. **Tests (spec 11).** Unitarios: modelos del chofer contra JSON reales, reloj del servidor, cola (orden, duplicados, tope), emisor (lotes de 500, reintento, 204/422/sin red) y `TurnoNotifier`, con `fake_async`, `ApiFalsa`, `TiempoRealFalso` y un `UbicadorFalso` que emite puntos a mano. Widgets: oferta entrante (cuenta regresiva desde `vence_en`, aceptar, rechazar, vencida, obligatorio → "Viaje asignado"), viaje en curso (pasos, navegar, llamar, cancelar con motivo, oculto si es obligatorio), iniciar turno (permiso denegado) y agenda.

### Ajustes que obligó el código real (revisar)

Lo que cambió respecto de las decisiones y de la lista de tareas que tenía este plan antes de escribir el código. Los que tocan una decisión lo dicen.

- **C1. Orden de las tareas.** "Viaje en curso" (Task 7) va **antes** que "Oferta entrante" (Task 8): al aceptar una oferta la app pasa a la pantalla del viaje, que tiene que existir. La deduplicación del backend es la Task 10 y la prueba punta a punta, la 11. Cada task agrega sus rutas (`/chofer/viaje` en la 7, `/chofer/oferta` y `/chofer/asignado` en la 8, `/chofer/agenda` en la 9) y el paso automático de `InicioChofer` a esas pantallas crece con ellas.
- **C2. `ColaUbicaciones.quitar(enviados)` en vez de `quitarPrimeros(n)`** (decisión 5). Saca exactamente los puntos de un envío confirmado aunque mientras tanto haya entrado uno más viejo (el GPS puede entregar uno atrasado) o el tope haya descartado alguno: con `quitarPrimeros(n)` se hubiera borrado un punto sin mandar.
- **C3. Qué hace el emisor con cada error** (decisión 5). El 422 "Iniciá un turno…" se reconoce por ser una regla de negocio (`ErrorNegocio` sin `errores` por campo, la única de `ServicioUbicacion`), no por el texto: las validaciones ahora salen en español (`APP_LOCALE=es` desde el plan de ETA). Un 422 **de validación** (con `errors`) descarta ese lote, porque nunca va a pasar y trabaría la cola para siempre. Un 403 (ya no es chofer) también detiene el rastreo. `SesionInvalida` queda para reintentar (`ClienteApi` ya avisó). Dos `enviar()` simultáneos devuelven el mismo `Future` (un solo POST, los dos ven el resultado).
- **C4. `PuntoGps.toJson` limpia rumbo y velocidad.** El backend valida `rumbo` entre 0 y 360 y `velocidad` ≥ 0; iOS informa -1 cuando no los tiene. Sin esto, un solo punto así rechazaba el lote entero con 422.
- **C5. Cancelar el GPS sin esperar el `Future` de `cancel()`.** `geolocator_android` reutiliza el stream abierto mientras alguien lo escuche (hay que cancelar antes de abrir uno con otro intervalo); la cancelación lo suelta en el momento (lo hace el `onCancel` de `asBroadcastStream`, sincrónico). Además, el `Future` de `cancel()` de un `StreamController` sin `onCancel` asíncrono es el `_nullFuture` de la zona raíz, que `fake_async` no avanza: con `await` los tests quedaban colgados. `RastreadorTurno.detener()` es sincrónico.
- **C6. Posición propia en `posicionPropiaProvider`** (`PosicionPropia {punto, sinGps}`) en lugar de `RastreadorTurno.ultimaPosicion` (stream). Un error del GPS (permiso revocado, GPS apagado) no corta el rastreo ni se escapa: marca `sinGps`, el mapa muestra "No podemos obtener tu ubicación…" con "Abrir ajustes" y "Reintentar" (`TurnoNotifier.reintentarGps`, que vuelve a pedir permiso y reabre el GPS **sin tocar la cola**). Al retomar un turno abierto se vuelve a pedir el permiso.
- **C7. `configuracionProvider` cae a los valores por defecto** (10 s / 5 s / 30 s, los del backend) si `GET /configuracion` falla: el GPS del turno no puede depender de ese pedido.
- **C8. El GPS vive mientras el módulo está abierto** (decisiones 3 y 4). El rastreo es de `TurnoNotifier`, dentro del `ProviderScope` del módulo: con el módulo abierto sigue con la app en segundo plano (servicio en primer plano), pero si el chofer **cierra el módulo** se corta hasta que lo vuelva a abrir (el turno sigue abierto y el backend lo marcará "sin señal"). Por eso cerrar con el turno abierto —con la X o con "atrás" desde el mapa— pide confirmación ("Tu turno sigue abierto…"). **(A confirmar con el equipo de la app del PJ:** si el GPS debe seguir con el módulo cerrado, hay que sacar el rastreo del `ProviderScope` del módulo a un servicio de la app principal.)
- **C9. Nada viejo pisa algo nuevo en `ViajeActualNotifier`.** (a) `_version`: una consulta (respaldo, push, reconexión) que empezó antes de una novedad local (respuesta de una acción, evento del socket) y termina después, se descarta. (b) `_atrasado`: una respuesta o evento que llega tarde no hace retroceder el mismo viaje con el mismo chofer (p. ej. la respuesta de "Iniciar viaje" después del evento "cancelado"); con otro chofer (reasignado, cancelado por el chofer) sí se aplica. Los dos aplican también al solicitante; los tests del plan anterior siguen en verde.
- **C10. "Viaje asignado" para cualquier inmediato que llega `aceptado` sin oferta** (decisión 7): obligatorios y también los que asigna un administrador desde el panel (spec 5.6, reasignación). El texto cambia ("Es un viaje obligatorio: no se puede rechazar." / "Te lo asignó un administrador."). Se detecta tanto por el evento como por la consulta (sin socket). El viaje que ya había al abrir el módulo no se marca (se va directo a su pantalla). El campo es `SeguimientoViaje.asignadoSinOferta` y se limpia con `verViajeAsignado()`.
- **C11. La oferta no se cierra con "atrás"** (`PopScope(canPop: false)`): se acepta, se rechaza o vence. La vibración y el sonido van por `alertaOfertaProvider` (costura para los tests, nunca lanza) al abrir y cada 5 ticks de la cuenta regresiva.
- **C12. Cuenta regresiva en `restanteProvider`** (`NotifierProvider.autoDispose.family` por `vence_en`): el `Timer` vive en el notifier, no en la pantalla. Se muestra `ceil` en segundos. Límites conocidos: el `Date` tiene precisión de segundos y se escribe antes de viajar, así que la cuenta puede mostrar ~1 s de más; si el chofer acepta en ese margen el backend responde "La oferta ya no está vigente." y se muestra. En **web** el navegador no deja leer `Date` en un pedido a otro origen (no es un encabezado "CORS-safelisted"): el desfase queda en 0 salvo que el backend lo exponga (`'exposed_headers' => ['Date']` en `config/cors.php`) **(A confirmar** si el chofer va a usar web; hoy es solo para desarrollo).
- **C13. "Voy en camino" de una reserva siempre visible** (decisión 10): el backend decide si ya se puede salir y su 422 ("Podés salir hacia esta reserva a partir de las 11:15.") se muestra; la app no copia el parámetro `bloqueo_antes_reserva_min` (45 min), que el admin puede cambiar. La agenda muestra el vencimiento de cada solicitud como hora local ("Responder antes de vie 2/10 12:30"): una hora absoluta no necesita el reloj del servidor (decisión 11); la cuenta regresiva con reloj del servidor queda para la oferta inmediata.
- **C14. La agenda pasa por `AgendaNotifier`** (`aceptar`, `rechazar`, `salir`), no por la API desde la pantalla (Global Constraints). Se recarga con los avisos push y con los eventos de `chofer.{id}` cuyo viaje es una reserva: se mira solo `tipo`, sin leer el resto, así un evento raro no rompe nada. `salir` usa `ViajeActualNotifier.salirHaciaReserva` y la reserva pasa a ser el viaje actual.
- **C15. Al cancelar, el chofer vuelve al mapa** con "Cancelaste el viaje." (`descartar()` después de la respuesta, B3). El motivo se manda sin espacios de más y con `maxLength` 255 (lo que valida el backend).
- **C16. `telefonoMarcable` pasa a `ui/comunes/comunes.dart`**: lo usan el solicitante (llamar al chofer) y el chofer (llamar al solicitante). La navegación y "Llamar" del chofer pasan por un `_abrir` que atrapa los errores de la plataforma (sin app instalada) y prueba la URL siguiente.
- **C17. Dobles y soporte de tests.** `ApiChofer extends ApiFalsa` en un archivo nuevo (`test/soporte/dobles_chofer.dart`); `test/soporte/montar_chofer.dart` con `entornoChofer(...)` y `montarChofer(...)`: como `AdaptadorFalso` usa la **primera** respuesta encolada, `viajes/actual` y `agenda` son parámetros de `entornoChofer` (encolar otra después no la reemplaza). `AdaptadorFalso.fechaServidor` agrega el encabezado `Date`. Los tests nuevos de la API del chofer van en `test/api_chofer_test.dart` y los del notifier en `test/viaje_actual_chofer_test.dart` (sin tocar los del plan anterior).
- **C18. `UbicadorGeolocator.actual()` usa `pedirPermiso()`** y atrapa cualquier error del plugin (antes `getCurrentPosition` podía lanzar algo que no fuera `Exception`).
- **C19. Canal de la notificación en Android:** `notificationChannelName: 'Ubicación del turno'` (el valor por defecto es "Background Location", que el chofer ve en los ajustes de notificaciones).
- **C20. Notificaciones en Android 13+:** geolocator no pide `POST_NOTIFICATIONS`; sin ese permiso el servicio en primer plano igual corre pero la notificación fija puede no verse. `host_prueba` ya declara el permiso; **(A confirmar con el equipo de la app del PJ:** que su app lo pida).
- **C21. Deduplicación en el backend (Task 10)**, que antes estaba "fuera de este plan": índice único `(viaje_id, registrado_en)` con una migración **nueva** (las anteriores ya están mergeadas; primero borra repetidos existentes) e `insertOrIgnore` en `ServicioUbicacion`. `registrado_en` es `timestamp` de precisión de segundos: dos puntos del mismo viaje en el mismo segundo quedan en uno (el GPS manda cada 5 s o más). La columna no se toca (sigue NOT NULL con `useCurrent()`, que MariaDB exige).
- **C22. Fixtures.** Los JSON de `payloads_chofer.dart` son respuestas reales, salvo dos marcados como "armado sobre ViajeResource" (el viaje que el chofer canceló con otro chofer libre —`buscando`— y una reserva `aceptado` de la agenda), que tienen exactamente los campos del Resource.
- **C23. `onDispose` de `TurnoNotifier` no usa `ref`** (Riverpod no lo permite ahí): corta el rastreo directo; el resto de los caminos usa `_detenerRastreo()`.

### Ajustes ya conocidos del backend (revisar)

- **B1.** `GET /api/turnos/actual`, `POST /api/turnos` y `POST /api/turnos/actual/finalizar` devuelven el modelo Eloquent (no un Resource): fechas con microsegundos (`2026-10-01T12:00:00.000000Z`), `chofer_id`, `vehiculo_id`, `origen`, `created_at`, `updated_at`; `vehiculo` completo (con `activo` y fechas) al iniciar y en `actual`, **sin `vehiculo`** al finalizar.
- **B2.** `POST /api/ofertas/{id}/rechazar` responde 204; `aceptar` responde el `ViajeResource` del viaje ya asignado. Una oferta vencida o ya respondida responde 422 "La oferta ya no está vigente.".
- **B3.** Cuando el chofer cancela, la respuesta es el viaje **ya sin chofer** (`buscando` si es inmediato y hay a quién reasignarlo, `sin_chofer` si no o si es una reserva). La app vuelve al mapa (C15).
- **B4.** `POST /api/ubicacion` descarta la posición actual si el punto más nuevo es anterior a la guardada (no retrocede) y guarda en `recorrido_viaje` los puntos de un viaje `en_curso`; hasta la Task 10 también los reenviados (duplicados).
- **B5.** `viajes/actual` del chofer solo trae ofertas de viajes **inmediatos** vigentes; las de reservas están en `GET /api/agenda` (`solicitudes`). Una reserva `aceptado` ya vencida (`programado_para` pasado) sí aparece como viaje actual (`Viaje::activos`).
- **B6.** `ViajeActualizado` se transmite a `chofer.{id}` del chofer asignado, del anterior (reasignación o cancelación del chofer) y de los que tenían una oferta pendiente: así el chofer se entera de que su oferta se la llevó otro o de que lo reasignaron.

## Global Constraints

Las del plan anterior (español, comandos desde `paquete/vehiculos_oficiales/`, `flutter analyze` + `dart format` + tests al final de cada task, Google Maps solo en `mapa_google.dart`, HTTP solo por `ClienteApi`, nada de red ni plugins en los tests, fechas en UTC, `Co-Authored-By` en cada commit), más las que dejaron las revisiones del plan anterior, que acá son obligatorias:

- **Ningún error asíncrono sin capturar.** Todo lo que corre sin `await` (timers, listeners de streams y del socket, `unawaited(...)`, callbacks de botones que devuelven un `Future`) atrapa `ErrorApi` —incluido `SesionInvalida`, que `ClienteApi` ya avisó una sola vez—. Los caminos de refresco (respaldo, push, reintentos del emisor) se tragan `SesionInvalida`. Los eventos del socket se leen protegidos (un evento que no se puede leer se ignora, o se mira solo el campo que hace falta).
- **Los timers, el GPS y los canales tienen dueño.** Los abre un notifier y se liberan en `ref.onDispose` (o en el `detener()` del objeto que es de ese notifier). Después de cada `await`, `ref.mounted` (y, donde puede haber un arranque viejo esperando, un contador de generación/versión). Una respuesta vieja no pisa un estado más nuevo (C9).
- **Las excepciones del host** (geolocator, `url_launcher`, vibración/sonido) se atrapan y se convierten en un estado de la pantalla (`PermisoUbicacion`, `sinGps`, "No se pudo abrir la navegación."): nunca se escapan.
- Las pantallas del chofer **no llaman a la API para transiciones**: pasan por `ViajeActualNotifier` (`aceptarOferta`, `rechazarOferta`, `avanzar`, `cancelar`, `salirHaciaReserva`), `TurnoNotifier` (`iniciar`, `finalizar`, `reintentarGps`) o `AgendaNotifier` (`aceptar`, `rechazar`, `salir`), que actualizan el estado con la respuesta. Leer listas (vehículos, agenda) es por providers.
- El GPS solo corre con el turno abierto y se detiene al finalizarlo o cuando el backend responde que no hay turno (spec 10). Ningún punto se manda fuera de `EmisorUbicacion`.
- Toda cuenta regresiva usa `relojServidorProvider` y el `vence_en` del backend.
- Los tests nunca instancian geolocator, Google Maps ni red: `ubicadorProvider` siempre sobrescrito con `UbicadorFalso` (lo hace `montarChofer`), mapa con `mapaDePrueba`, HTTP con `AdaptadorFalso` o `ApiChofer`, `lanzadorUrlProvider` y `alertaOfertaProvider` sobrescritos donde se usan.

## Review Focus

1. **La cuenta regresiva usa `vence_en` del servidor, no 30 s locales:** con `vence_en` a 12 s muestra "12 s" y un segundo después "11 s"; con el reloj del servidor 60 s adelantado y `vence_en` a 70 s del reloj del teléfono muestra "10 s"; a 0 muestra "La oferta venció". Tests en Task 2 (`reloj_servidor_test`) y Task 8 (`pantalla_oferta_test`).
2. **Los puntos guardados sin señal se mandan en orden y sin duplicados:** 3 ciclos sin red acumulan los puntos y al volver salen en un solo lote ordenado; un punto repetido del GPS no se encola dos veces; un lote que falló no se pierde ni se reordena; más de 500 se parten en lotes; un lote reenviado no duplica el recorrido en el backend. Tests en Task 4 (`cola_ubicaciones_test`, `emisor_ubicacion_test`), Task 5 (`turno_test`: "sin red los puntos se acumulan…") y Task 10 (Pest).
3. **El GPS se corta al terminar el turno:** después de `finalizar()` (o de un 422 "Iniciá un turno…", o al cerrar el módulo) el stream está cancelado, la cola vacía y no sale ningún `POST /api/ubicacion` más; `finalizar()` primero intenta vaciar la cola. Tests en Task 5 (`turno_test`) y Task 6 (`iniciar_turno_test`).
4. **Un viaje obligatorio no se puede rechazar ni cancelar desde la app:** no hay pantalla de oferta sino "Viaje asignado", y el botón "Cancelar viaje" no aparece. Tests en Task 7 (`viaje_chofer_test`) y Task 8 (`pantalla_oferta_test`, `viaje_actual_chofer_test`).
5. **El intervalo cambia con el viaje:** 10 s en turno, 5 s con un viaje activo (`aceptado` a `en_curso`), según `GET /api/configuracion`. Tests en Task 5.
6. **Nada se escapa ni se pisa:** un 401 del emisor o del sondeo no produce un error sin capturar; una consulta vieja o una respuesta tardía no hace retroceder el viaje. Tests en Task 5 y Task 7 (`viaje_actual_chofer_test`).

## Estructura de archivos

```
paquete/vehiculos_oficiales/
  pubspec.yaml                                              # + http_parser (Task 2)
  lib/src/modelos/chofer.dart                               # Turno, Agenda, PuntoGps (Task 1)
  lib/src/modelos/modelos.dart                              # + export 'chofer.dart'
  lib/src/api/api_vehiculos.dart                            # + endpoints del chofer (Task 1)
  lib/src/api/cliente_api.dart                              # + desfaseReloj (encabezado Date) (Task 2)
  lib/src/api/reloj_servidor.dart                           # RelojServidor, relojServidorProvider (Task 2)
  lib/src/ubicacion/ubicador.dart                           # + PermisoUbicacion, pedirPermiso, seguir, abrirAjustes, ajustesGpsTurno (Task 3)
  lib/src/chofer/cola_ubicaciones.dart                      # ColaUbicaciones (Task 4)
  lib/src/chofer/emisor_ubicacion.dart                      # EmisorUbicacion, ResultadoEnvio (Task 4)
  lib/src/chofer/rastreador_turno.dart                      # RastreadorTurno (Task 5)
  lib/src/chofer/turno.dart                                 # turnoProvider, configuracionProvider, posicionPropiaProvider, vehiculosDisponiblesProvider (Task 5)
  lib/src/chofer/pasos_viaje.dart                           # siguientePaso, cancelablePorChofer, URLs de navegación (Task 7)
  lib/src/chofer/cuenta_regresiva.dart                      # restanteProvider (Task 8)
  lib/src/chofer/agenda.dart                                # agendaProvider (AgendaNotifier) (Task 9)
  lib/src/viaje/viaje_actual.dart                           # + avanzar, versión/atrasado (7); ofertas y asignado (8); salirHaciaReserva (9)
  lib/src/ui/modulo_app.dart                                # + rutas /chofer/viaje (7), /oferta y /asignado (8), /agenda (9)
  lib/src/ui/comunes/comunes.dart                           # + telefonoMarcable (movido desde pantalla_viaje.dart) (Task 7)
  lib/src/ui/solicitante/pantalla_viaje.dart                # - telefonoMarcable (Task 7)
  lib/src/ui/chofer/inicio_chofer.dart                      # reemplaza la provisoria (6); navegación a viaje (7), oferta y asignado (8)
  lib/src/ui/chofer/iniciar_turno.dart                      # (Task 6)
  lib/src/ui/chofer/mapa_chofer.dart                        # (6); aviso de viaje en curso (7); agenda y próxima reserva (9)
  lib/src/ui/chofer/viaje_chofer.dart                       # (Task 7)
  lib/src/ui/chofer/pantalla_oferta.dart                    # + alertaOfertaProvider (Task 8)
  lib/src/ui/chofer/viaje_asignado.dart                     # (Task 8)
  lib/src/ui/chofer/agenda.dart                             # AgendaPantalla, TarjetaReserva (Task 9)
  test/fixtures/payloads_chofer.dart                        # JSON de turnos, vehículos, agenda y errores (Task 1)
  test/soporte/dobles_chofer.dart                           # ApiChofer, turnoDePrueba, punto, segundos (1, 4, 7)
  test/soporte/dobles.dart                                  # UbicadorFalso con permiso y GPS a mano (Task 3)
  test/soporte/adaptador_falso.dart                         # + fechaServidor (encabezado Date) (Task 2)
  test/soporte/montar_chofer.dart                           # entornoChofer, montarChofer, pedidosHechos (6, 7, 9)
  test/{modelos_chofer,api_chofer,reloj_servidor,ubicador,cola_ubicaciones,emisor_ubicacion,turno,viaje_actual_chofer}_test.dart
  test/ui/{iniciar_turno,viaje_chofer,pantalla_oferta,agenda}_test.dart
host_prueba/                                                # sin cambios de código; prueba manual como chofer (Task 11)
backend/
  database/migrations/2026_09_30_000001_indice_unico_recorrido_viaje.php   # (Task 10)
  app/Servicios/ServicioUbicacion.php                       # insertOrIgnore en recorrido_viaje (Task 10)
  tests/Feature/UbicacionTest.php                           # + 3 tests (Task 10)
```

JSON reales de los endpoints del chofer (backend de `main`, reloj en 2026-10-01 12:00 UTC); van a `test/fixtures/payloads_chofer.dart` en la Task 1:

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
POST /api/ubicacion (sin turno) -> 422 {"message":"Iniciá un turno para compartir tu ubicación."}

POST /api/viajes/1/estado {"estado":"en_camino"} -> 200 (ViajeResource, "estado":"en_camino")
POST /api/viajes/1/estado (de otro chofer) -> 403 {"message":"Este viaje no es tuyo."}
POST /api/viajes/2/estado (reserva, antes de tiempo) -> 422 {"message":"Podés salir hacia esta reserva a partir de las 11:15."}

POST /api/viajes/1/cancelar {"motivo":"x"} (chofer) -> 200
{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"sin_chofer",…,"chofer":null,"vehiculo":null,…}

POST /api/ofertas/1/aceptar (vencida) -> 422 {"message":"La oferta ya no está vigente."}
POST /api/ofertas/3/aceptar (reserva superpuesta) -> 422 {"message":"La reserva ya no está disponible o se superpone con otra de tu agenda."}

GET  /api/agenda -> 200
{"reservas":[],"solicitudes":[{"id":3,"vence_en":"2026-10-01T12:30:00+00:00","viaje":{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}]}

GET  /api/agenda (como solicitante) -> 403 {"message":"No tenés permiso para esta acción."}
```

---

### Task 1: Modelos y endpoints del chofer

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/modelos/chofer.dart`, `test/fixtures/payloads_chofer.dart`, `test/soporte/dobles_chofer.dart`
- Modify: `lib/src/modelos/modelos.dart`, `lib/src/api/api_vehiculos.dart`
- Test: `test/modelos_chofer_test.dart`, `test/api_chofer_test.dart`

**Interfaces:**
- Consumes: `ClienteApi`, `leerFecha`, `escribirFecha`, `Vehiculo`, `Viaje`, `Oferta`, `EstadoViaje`, `ApiFalsa`, `viaje()` y `jsonViaje()` de `test/soporte/dobles.dart` (plan anterior).
- Produces:
  - `Turno({required int id, required Vehiculo? vehiculo, required DateTime inicio, DateTime? fin})` + `Turno.fromJson`, `bool get abierto`.
  - `Agenda({required List<Viaje> reservas, required List<Oferta> solicitudes})` + `Agenda.fromJson`, `Agenda.vacia`.
  - `PuntoGps({required Coordenada posicion, double? rumbo, double? velocidad, required DateTime registradoEn})` + `Json toJson()` (`lat`, `lng`, `rumbo`, `velocidad`, `registrado_en` con `escribirFecha`; rumbo fuera de 0–360 y velocidad negativa van nulos, C4).
  - En `ApiVehiculos`: `Future<List<Vehiculo>> vehiculosDisponibles()`, `Future<Turno?> turnoActual()`, `Future<Turno> iniciarTurno(int vehiculoId)`, `Future<Turno> finalizarTurno()`, `Future<void> enviarUbicacion(List<PuntoGps> puntos)`, `Future<Viaje> aceptarOferta(int ofertaId)`, `Future<void> rechazarOferta(int ofertaId)`, `Future<Viaje> avanzarViaje(int viajeId, EstadoViaje estado)`, `Future<Agenda> agenda()`. Todos los que leen JSON pasan por `_leer` (una respuesta que no se puede leer es `ErrorServidor`).
  - Tests: `ApiChofer extends ApiFalsa` (registra `llamadas` en orden, `lotes` de ubicación, errores programables por endpoint), `turnoDePrueba()`, `punto(segundo)`.

- [ ] **Step 1: Fixtures del chofer**

`paquete/vehiculos_oficiales/test/fixtures/payloads_chofer.dart`:

```dart
// Respuestas reales del backend para el chofer (rama main, reloj en 2026-10-01 12:00 UTC), copiadas tal cual.
// Los turnos y vehículos son modelos Eloquent (sin Resource): fechas con microsegundos y campos extra.

// GET /api/vehiculos/disponibles -> 200
const vehiculosDisponibles =
    r'''[{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}]''';

// GET /api/turnos/actual (sin turno) -> 200
const sinTurno = r'''{"turno":null}''';

// POST /api/turnos {"vehiculo_id":1} -> 201
const turnoIniciado =
    r'''{"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","origen":"manual","updated_at":"2026-10-01T12:00:00.000000Z","created_at":"2026-10-01T12:00:00.000000Z","id":1,"vehiculo":{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}}''';

// GET /api/turnos/actual (con turno) -> 200
const turnoActual =
    r'''{"turno":{"id":1,"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","fin":null,"origen":"manual","created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z","vehiculo":{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}}}''';

// POST /api/turnos/actual/finalizar -> 200 (sin `vehiculo`)
const turnoFinalizado =
    r'''{"id":1,"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","fin":"2026-10-01T12:00:00.000000Z","origen":"manual","created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}''';

// POST /api/turnos con el vehículo tomado -> 422
const vehiculoEnUso = r'''{"message":"El vehículo está en uso por otro chofer."}''';

// POST /api/turnos/actual/finalizar con un viaje activo -> 422
const finalizarConViaje = r'''{"message":"Finalizá el viaje en curso antes de cerrar el turno."}''';

// POST /api/ubicacion sin turno abierto -> 422
const ubicacionSinTurno = r'''{"message":"Iniciá un turno para compartir tu ubicación."}''';

// POST /api/ofertas/1/aceptar (vencida o ya respondida) -> 422
const ofertaNoVigente = r'''{"message":"La oferta ya no está vigente."}''';

// POST /api/viajes/1/estado (de otro chofer) -> 403
const viajeAjeno = r'''{"message":"Este viaje no es tuyo."}''';

// POST /api/viajes/2/estado {"estado":"en_camino"} (reserva, antes de tiempo) -> 422
const reservaAntesDeTiempo = r'''{"message":"Podés salir hacia esta reserva a partir de las 11:15."}''';

// POST /api/viajes/1/cancelar {"motivo":"x"} (chofer, inmediato con otro chofer libre: vuelve a buscar) -> 200.
// Armado sobre ViajeResource: sin chofer ni vehículo; si no hay otro chofer libre llega `sin_chofer` (B3).
const viajeCanceladoPorChofer =
    r'''{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"buscando","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// GET /api/agenda -> 200
const agenda =
    r'''{"reservas":[],"solicitudes":[{"id":3,"vence_en":"2026-10-01T12:30:00+00:00","viaje":{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}]}''';

// Armado sobre ViajeResource: reserva `aceptado` del chofer 2, como viene en `reservas` de la agenda.
const reservaConfirmada =
    r'''{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"aceptado","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":{"id":2,"nombre":"Carlos Gómez","telefono":"3815550000"},"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":"3815551111"},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// POST /api/ofertas/3/aceptar (reserva superpuesta) -> 422
const reservaNoDisponible = r'''{"message":"La reserva ya no está disponible o se superpone con otra de tu agenda."}''';
```

- [ ] **Step 2: Escribir los tests que fallan**

`paquete/vehiculos_oficiales/test/modelos_chofer_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;
import 'fixtures/payloads_chofer.dart' as c;

void main() {
  test('vehículos disponibles: modelo Eloquent con campos extra', () {
    final v = Vehiculo.fromJson(leerMapa(p.jsonLista(c.vehiculosDisponibles).single));

    expect(v.id, 1);
    expect(v.descripcion, 'Toyota Corolla (AB123CD)');
    expect(v.color, 'Blanco');
  });

  test('turno iniciado, actual y finalizado (sin vehículo)', () {
    final iniciado = Turno.fromJson(p.json(c.turnoIniciado));
    expect(iniciado.id, 1);
    expect(iniciado.vehiculo!.patente, 'AB123CD');
    expect(iniciado.inicio, DateTime.utc(2026, 10, 1, 12));
    expect(iniciado.abierto, isTrue);

    final actual = Turno.fromJson(leerMapa(p.json(c.turnoActual)['turno']));
    expect(actual.abierto, isTrue);
    expect(actual.vehiculo!.id, 1);

    final finalizado = Turno.fromJson(p.json(c.turnoFinalizado));
    expect(finalizado.vehiculo, isNull);
    expect(finalizado.fin, DateTime.utc(2026, 10, 1, 12));
    expect(finalizado.abierto, isFalse);
  });

  test('agenda: reservas y solicitudes con su vencimiento', () {
    final a = Agenda.fromJson(p.json(c.agenda));

    expect(a.reservas, isEmpty);
    final s = a.solicitudes.single;
    expect(s.id, 3);
    expect(s.venceEn, DateTime.utc(2026, 10, 1, 12, 30));
    expect(s.viaje.tipo, TipoViaje.reserva);
    expect(s.viaje.programadoPara, DateTime.utc(2026, 10, 2, 13));

    final conReserva = Agenda.fromJson({
      'reservas': [p.json(c.reservaConfirmada)],
      'solicitudes': <Object>[],
    });
    expect(conReserva.reservas.single.chofer!.id, 2);
  });

  test('punto GPS: fecha en UTC con Z; rumbo y velocidad sin dato van nulos', () {
    final punto = PuntoGps(
      posicion: const Coordenada(-26.83, -65.2),
      rumbo: 90,
      velocidad: 0,
      registradoEn: DateTime.parse('2026-10-01T08:59:50-03:00'),
    );
    expect(punto.toJson(), {
      'lat': -26.83,
      'lng': -65.2,
      'rumbo': 90.0,
      'velocidad': 0.0,
      'registrado_en': '2026-10-01T11:59:50.000Z',
    });

    final sinDatos = PuntoGps(
      posicion: const Coordenada(1, 2),
      rumbo: -1,
      velocidad: -1,
      registradoEn: DateTime.utc(2026),
    ).toJson();
    expect(sinDatos['rumbo'], isNull);
    expect(sinDatos['velocidad'], isNull);
  });
}
```

`paquete/vehiculos_oficiales/test/api_chofer_test.dart`:

```dart
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;
import 'fixtures/payloads_chofer.dart' as c;
import 'soporte/adaptador_falso.dart';

void main() {
  late AdaptadorFalso http;
  late ApiVehiculos api;

  setUp(() {
    http = AdaptadorFalso();
    api = ApiVehiculos(
      ClienteApi(baseApi: Uri.parse('http://10.0.2.2:8000/api/'), alRecibir401: () {}, adaptador: http),
    );
    api.cliente.token = '2|chofer';
  });

  Object? cuerpo([int i = 0]) => jsonDecode(http.pedidos[i].cuerpo);

  test('vehículos disponibles y turno actual (con y sin turno)', () async {
    http.responder('GET', 'vehiculos/disponibles', 200, c.vehiculosDisponibles);
    http.responder('GET', 'turnos/actual', 200, c.sinTurno);
    http.responder('GET', 'turnos/actual', 200, c.turnoActual);

    expect((await api.vehiculosDisponibles()).single.patente, 'AB123CD');
    expect(await api.turnoActual(), isNull);
    expect((await api.turnoActual())!.vehiculo!.id, 1);
    expect(http.pedidos.first.headers['Authorization'], 'Bearer 2|chofer');
  });

  test('iniciar turno manda el vehículo; finalizar lee la respuesta sin vehículo', () async {
    http.responder('POST', 'turnos', 201, c.turnoIniciado);
    http.responder('POST', 'turnos/actual/finalizar', 200, c.turnoFinalizado);

    final t = await api.iniciarTurno(1);
    final f = await api.finalizarTurno();

    expect(t.abierto, isTrue);
    expect(cuerpo(0), {'vehiculo_id': 1});
    expect(f.abierto, isFalse);
    expect(f.vehiculo, isNull);
  });

  test('los 422 del turno llegan con el message del backend', () async {
    http.responder('POST', 'turnos', 422, c.vehiculoEnUso);
    http.responder('POST', 'turnos/actual/finalizar', 422, c.finalizarConViaje);

    await expectLater(
      api.iniciarTurno(1),
      throwsA(isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'El vehículo está en uso por otro chofer.')),
    );
    await expectLater(
      api.finalizarTurno(),
      throwsA(
        isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'Finalizá el viaje en curso antes de cerrar el turno.'),
      ),
    );
  });

  test('ubicación: lote de puntos con fechas UTC y 204', () async {
    http.responder('POST', 'ubicacion', 204);

    await api.enviarUbicacion([
      PuntoGps(
        posicion: const Coordenada(-26.83, -65.2),
        rumbo: 90,
        velocidad: 0,
        registradoEn: DateTime.utc(2026, 10, 1, 11, 59, 50),
      ),
      PuntoGps(posicion: const Coordenada(-26.84, -65.21), registradoEn: DateTime.utc(2026, 10, 1, 12)),
    ]);

    expect(cuerpo(), {
      'puntos': [
        {'lat': -26.83, 'lng': -65.2, 'rumbo': 90.0, 'velocidad': 0.0, 'registrado_en': '2026-10-01T11:59:50.000Z'},
        {'lat': -26.84, 'lng': -65.21, 'rumbo': null, 'velocidad': null, 'registrado_en': '2026-10-01T12:00:00.000Z'},
      ],
    });
  });

  test('ubicación sin turno: 422 de regla de negocio (sin errores por campo)', () async {
    http.responder('POST', 'ubicacion', 422, c.ubicacionSinTurno);

    await expectLater(
      api.enviarUbicacion([PuntoGps(posicion: const Coordenada(0, 0), registradoEn: DateTime.utc(2026))]),
      throwsA(
        isA<ErrorNegocio>()
            .having((e) => e.mensaje, 'mensaje', 'Iniciá un turno para compartir tu ubicación.')
            .having((e) => e.errores, 'errores', isEmpty),
      ),
    );
  });

  test('aceptar una oferta devuelve el viaje; rechazar es 204; una vencida es 422', () async {
    http.responder('POST', 'ofertas/1/aceptar', 200, p.viajeAceptado);
    http.responder('POST', 'ofertas/1/rechazar', 204);
    http.responder('POST', 'ofertas/2/aceptar', 422, c.ofertaNoVigente);

    final v = await api.aceptarOferta(1);
    await api.rechazarOferta(1);

    expect(v.estado, EstadoViaje.aceptado);
    expect(v.chofer!.id, 2);
    expect(http.pedidos.map((r) => r.uri.path), ['/api/ofertas/1/aceptar', '/api/ofertas/1/rechazar']);
    await expectLater(
      api.aceptarOferta(2),
      throwsA(isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'La oferta ya no está vigente.')),
    );
  });

  test('avanzar el viaje manda el estado; 403 si no es suyo', () async {
    http.responder('POST', 'viajes/1/estado', 200, p.viajeAceptado.replaceFirst('"aceptado"', '"en_camino"'));
    http.responder('POST', 'viajes/9/estado', 403, c.viajeAjeno);

    final v = await api.avanzarViaje(1, EstadoViaje.enCamino);

    expect(v.estado, EstadoViaje.enCamino);
    expect(cuerpo(), {'estado': 'en_camino'});
    await expectLater(
      api.avanzarViaje(9, EstadoViaje.llego),
      throwsA(isA<AccesoDenegado>().having((e) => e.mensaje, 'mensaje', 'Este viaje no es tuyo.')),
    );
  });

  test('cancelar como chofer manda el motivo y recibe el viaje ya sin chofer', () async {
    http.responder('POST', 'viajes/1/cancelar', 200, c.viajeCanceladoPorChofer);

    final v = await api.cancelarViaje(1, motivo: 'Se rompió el auto');

    expect(cuerpo(), {'motivo': 'Se rompió el auto'});
    expect(v.chofer, isNull);
    expect(v.estado, EstadoViaje.buscando);
  });

  test('agenda del chofer; como solicitante es 403', () async {
    http.responder('GET', 'agenda', 200, c.agenda);
    http.responder('GET', 'agenda', 403, p.sinPermiso);

    expect((await api.agenda()).solicitudes.single.id, 3);
    await expectLater(api.agenda(), throwsA(isA<AccesoDenegado>()));
  });

  test('una respuesta del chofer que no se puede leer es ErrorServidor', () async {
    http.responder('GET', 'turnos/actual', 200, '{"turno":{"id":"uno"}}');
    http.responder('GET', 'agenda', 200, '{"reservas":null}');

    await expectLater(api.turnoActual(), throwsA(isA<ErrorServidor>()));
    await expectLater(api.agenda(), throwsA(isA<ErrorServidor>()));
  });
}
```

- [ ] **Step 3: Correr y ver que fallan**

Run: `flutter test test/modelos_chofer_test.dart test/api_chofer_test.dart`
Expected: FAIL de compilación (no existen `Turno`, `Agenda`, `PuntoGps` ni los métodos nuevos de `ApiVehiculos`).

- [ ] **Step 4: Modelos**

`paquete/vehiculos_oficiales/lib/src/modelos/chofer.dart`:

```dart
import 'comunes.dart';
import 'json.dart';
import 'viaje.dart';

/// Turno del chofer. `GET /turnos/actual`, `POST /turnos` y `POST /turnos/actual/finalizar` devuelven el
/// modelo Eloquent (no un Resource): fechas con microsegundos y campos extra que se ignoran. Al finalizar
/// no viene `vehiculo`.
class Turno {
  const Turno({required this.id, required this.vehiculo, required this.inicio, this.fin});

  factory Turno.fromJson(Json j) => Turno(
    id: j['id'] as int,
    vehiculo: j['vehiculo'] == null ? null : Vehiculo.fromJson(leerMapa(j['vehiculo'])),
    inicio: leerFecha(j['inicio']),
    fin: leerFechaOpcional(j['fin']),
  );

  final int id;
  final Vehiculo? vehiculo;
  final DateTime inicio;
  final DateTime? fin;

  bool get abierto => fin == null;
}

/// `GET /agenda`: reservas confirmadas del chofer y solicitudes de reserva por responder.
class Agenda {
  const Agenda({required this.reservas, required this.solicitudes});

  factory Agenda.fromJson(Json j) => Agenda(
    reservas: leerLista(j['reservas']).map(Viaje.fromJson).toList(),
    solicitudes: leerLista(j['solicitudes']).map(Oferta.fromJson).toList(),
  );

  static const vacia = Agenda(reservas: [], solicitudes: []);

  final List<Viaje> reservas;
  final List<Oferta> solicitudes;
}

/// Un punto del GPS del turno, tal como se manda en `POST /ubicacion`.
class PuntoGps {
  const PuntoGps({required this.posicion, this.rumbo, this.velocidad, required this.registradoEn});

  final Coordenada posicion;
  final double? rumbo;
  final double? velocidad;
  final DateTime registradoEn;

  /// El backend valida `rumbo` entre 0 y 360 y `velocidad` no negativa. El GPS informa -1 (iOS) o valores
  /// sin dato cuando no los tiene: esos van como nulos, así un punto así no rechaza el lote entero.
  Json toJson() => {
    'lat': posicion.lat,
    'lng': posicion.lng,
    'rumbo': rumbo != null && rumbo! >= 0 && rumbo! <= 360 ? rumbo : null,
    'velocidad': velocidad != null && velocidad! >= 0 ? velocidad : null,
    'registrado_en': escribirFecha(registradoEn),
  };
}
```

En `lib/src/modelos/modelos.dart`, agregar como primera línea:

```dart
export 'chofer.dart';
```

- [ ] **Step 5: Endpoints**

`paquete/vehiculos_oficiales/lib/src/api/api_vehiculos.dart` queda así (los métodos nuevos van entre `registrarTokenPush` y `autorizarCanal`):

```dart
import 'package:flutter/foundation.dart';

import '../modelos/modelos.dart';
import 'cliente_api.dart';
import 'errores_api.dart';

/// Resultado de `POST /auth/intercambio`.
class Intercambio {
  const Intercambio(this.token, this.usuario);

  final String token;
  final Usuario usuario;
}

/// Endpoints del backend (routes/api.php) con tipos. Los tests de providers la reemplazan por un doble.
///
/// Todas las respuestas se leen con [_leer]: una que no se puede leer (un estado nuevo que la app no
/// conoce, un campo con otro tipo) es un [ErrorServidor], como cualquier otro error de la API, así quien
/// llama solo tiene que capturar [ErrorApi].
class ApiVehiculos {
  ApiVehiculos(this.cliente);

  final ClienteApi cliente;

  Future<Intercambio> intercambiar(String tokenExterno) => _leer(() async {
    final j = await cliente.postMapa('auth/intercambio', datos: {'token_externo': tokenExterno});
    return Intercambio(j['token'] as String, Usuario.fromJson(leerMapa(j['usuario'])));
  });

  Future<Usuario> yo() => _leer(() async => Usuario.fromJson(await cliente.getMapa('yo')));

  Future<Configuracion> configuracion() =>
      _leer(() async => Configuracion.fromJson(await cliente.getMapa('configuracion')));

  Future<List<ChoferEnMapa>> choferes() =>
      _leer(() async => leerLista(await cliente.get('choferes')).map(ChoferEnMapa.fromJson).toList());

  Future<ViajeActual> viajeActual() => _leer(() async => ViajeActual.fromJson(await cliente.getMapa('viajes/actual')));

  Future<MisViajes> misViajes() => _leer(() async => MisViajes.fromJson(await cliente.getMapa('viajes')));

  Future<Eta> eta(int viajeId) => _leer(() async => Eta.fromJson(await cliente.getMapa('viajes/$viajeId/eta')));

  Future<Viaje> pedirViaje(PedidoViaje pedido) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('viajes', datos: pedido.toJson())));

  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('viajes/$viajeId/cancelar', datos: {'motivo': ?motivo})));

  Future<DisponiblesReserva> disponiblesReserva(FranjaReserva franja) => _leer(
    () async => DisponiblesReserva.fromJson(await cliente.getMapa('reservas/disponibles', query: franja.toQuery())),
  );

  Future<Viaje> crearReserva(PedidoReserva pedido) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('reservas', datos: pedido.toJson())));

  Future<void> registrarTokenPush(String token) async {
    await cliente.post('push/token', datos: {'token': token});
  }

  // --- Chofer (rol:chofer en routes/api.php) ---

  Future<List<Vehiculo>> vehiculosDisponibles() =>
      _leer(() async => leerLista(await cliente.get('vehiculos/disponibles')).map(Vehiculo.fromJson).toList());

  /// `{"turno": null}` sin turno abierto.
  Future<Turno?> turnoActual() => _leer(() async {
    final j = await cliente.getMapa('turnos/actual');
    return j['turno'] == null ? null : Turno.fromJson(leerMapa(j['turno']));
  });

  Future<Turno> iniciarTurno(int vehiculoId) =>
      _leer(() async => Turno.fromJson(await cliente.postMapa('turnos', datos: {'vehiculo_id': vehiculoId})));

  Future<Turno> finalizarTurno() =>
      _leer(() async => Turno.fromJson(await cliente.postMapa('turnos/actual/finalizar')));

  /// 204. Hasta 500 puntos por pedido (lo que valida `UbicacionController`).
  Future<void> enviarUbicacion(List<PuntoGps> puntos) async {
    await cliente.post(
      'ubicacion',
      datos: {
        'puntos': [for (final p in puntos) p.toJson()],
      },
    );
  }

  /// Devuelve el viaje ya asignado. 422 "La oferta ya no está vigente." si venció o ya se respondió.
  Future<Viaje> aceptarOferta(int ofertaId) =>
      _leer(() async => Viaje.fromJson(await cliente.postMapa('ofertas/$ofertaId/aceptar')));

  /// 204.
  Future<void> rechazarOferta(int ofertaId) async {
    await cliente.post('ofertas/$ofertaId/rechazar');
  }

  /// `en_camino`, `llego`, `en_curso` o `finalizado`.
  Future<Viaje> avanzarViaje(int viajeId, EstadoViaje estado) => _leer(
    () async => Viaje.fromJson(await cliente.postMapa('viajes/$viajeId/estado', datos: {'estado': estado.valor})),
  );

  Future<Agenda> agenda() => _leer(() async => Agenda.fromJson(await cliente.getMapa('agenda')));

  /// Firma de un canal privado (`private-...`) para el socket [socketId]. Devuelve `auth`.
  Future<String> autorizarCanal({required String socketId, required String canal}) => _leer(() async {
    final j = leerMapa(
      await cliente.postFormulario('broadcasting/auth', {'socket_id': socketId, 'channel_name': canal}),
    );
    return j['auth'] as String;
  });

  static Future<T> _leer<T>(Future<T> Function() pedido) async {
    try {
      return await pedido();
    } catch (e) {
      if (esErrorDeLectura(e)) {
        debugPrint('vehiculos_oficiales: respuesta del servidor que no se pudo leer: $e');
        throw const ErrorServidor();
      }
      rethrow;
    }
  }
}
```

- [ ] **Step 6: Doble de la API del chofer**

`paquete/vehiculos_oficiales/test/soporte/dobles_chofer.dart` (lo usan los tests desde la Task 4; la Task 4 y la Task 7 le agregan cosas):

```dart
import 'dart:async';

import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import 'dobles.dart';

Turno turnoDePrueba() => Turno.fromJson(leerMapa(p.json(c.turnoActual)['turno']));

PuntoGps punto(int segundo, {double lat = -26.83}) => PuntoGps(
  posicion: Coordenada(lat, -65.2),
  registradoEn: DateTime.utc(2026, 10, 1, 12).add(Duration(seconds: segundo)),
);

/// [ApiFalsa] con los endpoints del chofer. Registra lo que se llama y en qué orden ([llamadas]).
class ApiChofer extends ApiFalsa {
  final llamadas = <String>[];

  Configuracion configuracionRespuesta = const Configuracion(gpsTurnoSeg: 10, gpsViajeSeg: 5, ofertaSegundos: 30);

  List<Vehiculo> vehiculos = [];
  Turno? turno;
  ErrorApi? errorTurnoActual;
  ErrorApi? errorIniciar;
  ErrorApi? errorFinalizar;

  /// Lotes recibidos por `POST /ubicacion` (también los que fallaron).
  final lotes = <List<PuntoGps>>[];

  /// Errores de los próximos envíos de ubicación, en orden; sin errores pendientes responde 204.
  final erroresUbicacion = <ErrorApi>[];

  /// Si no es nulo, `enviarUbicacion` espera a que el test lo complete.
  Completer<void>? demoraUbicacion;

  Viaje? respuestaAceptar;
  ErrorApi? errorOferta;
  Viaje? respuestaAvance;
  ErrorApi? errorAvance;
  final avances = <(int, EstadoViaje)>[];
  Viaje? respuestaCancelar;
  Agenda agendaRespuesta = Agenda.vacia;
  ErrorApi? errorAgenda;

  @override
  Future<Configuracion> configuracion() async {
    llamadas.add('configuracion');
    return configuracionRespuesta;
  }

  @override
  Future<List<Vehiculo>> vehiculosDisponibles() async {
    llamadas.add('vehiculos');
    return vehiculos;
  }

  @override
  Future<Turno?> turnoActual() async {
    llamadas.add('turnoActual');
    if (errorTurnoActual != null) throw errorTurnoActual!;
    return turno;
  }

  @override
  Future<Turno> iniciarTurno(int vehiculoId) async {
    llamadas.add('iniciar:$vehiculoId');
    if (errorIniciar != null) throw errorIniciar!;
    return turno = turnoDePrueba();
  }

  @override
  Future<Turno> finalizarTurno() async {
    llamadas.add('finalizar');
    if (errorFinalizar != null) throw errorFinalizar!;
    turno = null;
    return Turno.fromJson(p.json(c.turnoFinalizado));
  }

  @override
  Future<void> enviarUbicacion(List<PuntoGps> puntos) async {
    llamadas.add('ubicacion:${puntos.length}');
    lotes.add(List.of(puntos));
    if (demoraUbicacion != null) await demoraUbicacion!.future;
    if (erroresUbicacion.isNotEmpty) throw erroresUbicacion.removeAt(0);
  }

  @override
  Future<Viaje> aceptarOferta(int ofertaId) async {
    llamadas.add('aceptar:$ofertaId');
    if (errorOferta != null) throw errorOferta!;
    return respuestaAceptar ?? viaje(estado: 'aceptado', conChofer: true);
  }

  @override
  Future<void> rechazarOferta(int ofertaId) async {
    llamadas.add('rechazar:$ofertaId');
    if (errorOferta != null) throw errorOferta!;
  }

  @override
  Future<Viaje> avanzarViaje(int viajeId, EstadoViaje estado) async {
    llamadas.add('avanzar:$viajeId:${estado.valor}');
    avances.add((viajeId, estado));
    if (errorAvance != null) throw errorAvance!;
    return respuestaAvance ?? viaje(id: viajeId, estado: estado.valor, conChofer: true);
  }

  @override
  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) async {
    cancelaciones.add((viajeId, motivo));
    return respuestaCancelar ?? Viaje.fromJson(p.json(c.viajeCanceladoPorChofer)..['id'] = viajeId);
  }

  @override
  Future<Agenda> agenda() async {
    llamadas.add('agenda');
    if (errorAgenda != null) throw errorAgenda!;
    return agendaRespuesta;
  }
}
```

- [ ] **Step 7: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **137 PASS** (123 + 4 de modelos + 10 de la API), `No issues found!`, `0 changed`.

- [ ] **Step 8: Commit**

```bash
git add paquete
git commit -m "feat: modelos y endpoints del chofer en el cliente de la API" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Reloj del servidor

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/api/reloj_servidor.dart`
- Modify: `pubspec.yaml`, `lib/src/api/cliente_api.dart`, `test/soporte/adaptador_falso.dart`
- Test: `test/reloj_servidor_test.dart`

**Interfaces:**
- Consumes: `ClienteApi`, `apiProvider` (plan anterior), `package:clock`, `package:http_parser`.
- Produces:
  - En `ClienteApi`: `Duration desfaseReloj` (servidor − dispositivo; se actualiza con el encabezado `Date` de cada respuesta, también las de error; sin encabezado o inválido, no cambia).
  - `class RelojServidor { RelojServidor(ClienteApi cliente); DateTime ahora(); Duration restante(DateTime vence); }` (`restante` nunca negativa) y `relojServidorProvider` (con el cliente de `apiProvider`, así en los tests es el de la API falsa).
  - En `AdaptadorFalso`: `DateTime? fechaServidor` (si no es nula, cada respuesta lleva `Date`).

- [ ] **Step 1: Dependencia**

En `paquete/vehiculos_oficiales/pubspec.yaml`, en `dependencies`, después de `google_maps_flutter`:

```yaml
  http_parser: ^4.1.2
```

Run: `flutter pub get`
Expected: `http_parser 4.1.2` (la misma que ya resolvía dio).

- [ ] **Step 2: Encabezado `Date` en el adaptador de prueba**

`paquete/vehiculos_oficiales/test/soporte/adaptador_falso.dart` queda así:

```dart
import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:http_parser/http_parser.dart';

class PedidoRegistrado {
  PedidoRegistrado(this.metodo, this.uri, this.headers, this.cuerpo);

  final String metodo;
  final Uri uri;
  final Map<String, dynamic> headers;
  final String cuerpo;
}

/// Adaptador HTTP de dio para tests: responde según "MÉTODO ruta" (ruta sin `/api/` ni query)
/// y registra cada pedido. Una respuesta nula simula falta de red.
class AdaptadorFalso implements HttpClientAdapter {
  final Map<String, List<(int, String?)>> _respuestas = {};
  final Map<String, Completer<(int, String?)>> _demorados = {};
  final List<PedidoRegistrado> pedidos = [];

  /// Si no es nula, cada respuesta lleva el encabezado `Date` con esta hora (reloj del servidor).
  DateTime? fechaServidor;

  /// Encola una respuesta. Si queda una sola, se repite en los pedidos siguientes.
  void responder(String metodo, String ruta, int estado, [String? cuerpo]) =>
      (_respuestas['$metodo $ruta'] ??= []).add((estado, cuerpo));

  void sinRed(String metodo, String ruta) => (_respuestas['$metodo $ruta'] ??= []).add((-1, null));

  /// Los pedidos a esa ruta quedan esperando hasta que el test complete el `Completer` con (estado, cuerpo).
  Completer<(int, String?)> demorar(String metodo, String ruta) => _demorados['$metodo $ruta'] = Completer();

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final bytes = requestStream == null
        ? <int>[]
        : await requestStream.fold<List<int>>(<int>[], (a, b) => a..addAll(b));
    pedidos.add(PedidoRegistrado(options.method, options.uri, options.headers, utf8.decode(bytes)));

    final ruta = options.uri.path.replaceFirst(RegExp(r'^/api/'), '');
    final demorado = _demorados['${options.method} $ruta'];
    final cola = _respuestas['${options.method} $ruta'];
    if (demorado == null && (cola == null || cola.isEmpty)) {
      throw StateError('Sin respuesta preparada para ${options.method} $ruta');
    }
    final (estado, cuerpo) = demorado != null
        ? await demorado.future
        : cola!.length > 1
        ? cola.removeAt(0)
        : cola.first;
    if (estado == -1) {
      throw DioException.connectionError(requestOptions: options, reason: 'sin red');
    }
    return ResponseBody.fromString(
      cuerpo ?? '',
      estado,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
        if (fechaServidor != null) 'date': [formatHttpDate(fechaServidor!)],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
```

- [ ] **Step 3: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/reloj_servidor_test.dart`:

```dart
import 'package:clock/clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/api/reloj_servidor.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/adaptador_falso.dart';

void main() {
  final ahora = DateTime.utc(2026, 10, 1, 12);
  late AdaptadorFalso http;
  late ApiVehiculos api;
  late RelojServidor reloj;

  setUp(() {
    http = AdaptadorFalso();
    api = ApiVehiculos(
      ClienteApi(baseApi: Uri.parse('http://10.0.2.2:8000/api/'), alRecibir401: () {}, adaptador: http),
    );
    reloj = RelojServidor(api.cliente);
  });

  test('sin encabezado Date el desfase es cero', () async {
    http.responder('GET', 'configuracion', 200, p.configuracion);

    await withClock(Clock.fixed(ahora), () async {
      await api.configuracion();
      expect(api.cliente.desfaseReloj, Duration.zero);
      expect(reloj.ahora(), ahora);
    });
  });

  test('con el servidor 60 s adelantado, ahora() es la hora del servidor', () async {
    http.responder('GET', 'configuracion', 200, p.configuracion);
    http.fechaServidor = ahora.add(const Duration(seconds: 60));

    await withClock(Clock.fixed(ahora), () async {
      await api.configuracion();
      expect(api.cliente.desfaseReloj, const Duration(seconds: 60));
      expect(reloj.ahora(), ahora.add(const Duration(seconds: 60)));
      expect(reloj.restante(ahora.add(const Duration(seconds: 70))), const Duration(seconds: 10));
    });
  });

  test('también toma el Date de una respuesta de error (reloj del teléfono adelantado)', () async {
    http.responder('POST', 'ofertas/1/aceptar', 422, '{"message":"La oferta ya no está vigente."}');
    http.fechaServidor = ahora.subtract(const Duration(seconds: 30));

    await withClock(Clock.fixed(ahora), () async {
      await expectLater(api.aceptarOferta(1), throwsA(isA<ErrorNegocio>()));
      expect(api.cliente.desfaseReloj, const Duration(seconds: -30));
    });
  });

  test('restante nunca es negativo', () {
    withClock(Clock.fixed(ahora), () {
      expect(reloj.restante(ahora.subtract(const Duration(seconds: 5))), Duration.zero);
      expect(reloj.restante(ahora.add(const Duration(milliseconds: 1500))), const Duration(milliseconds: 1500));
    });
  });
}
```

- [ ] **Step 4: Correr y ver que falla**

Run: `flutter test test/reloj_servidor_test.dart`
Expected: FAIL de compilación (no existen `reloj_servidor.dart` ni `desfaseReloj`).

- [ ] **Step 5: Implementación**

`paquete/vehiculos_oficiales/lib/src/api/cliente_api.dart` queda así:

```dart
import 'package:clock/clock.dart';
import 'package:dio/dio.dart';
import 'package:http_parser/http_parser.dart';

import '../modelos/json.dart';
import 'errores_api.dart';

/// HTTP contra `/api` del backend. Agrega `Accept: application/json` (sin él Laravel responde un 401
/// como redirección al login) y el token Sanctum, y traduce las respuestas de error a [ErrorApi].
class ClienteApi {
  ClienteApi({required Uri baseApi, required this.alRecibir401, HttpClientAdapter? adaptador})
    : _dio = Dio(
        BaseOptions(
          baseUrl: baseApi.toString(),
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 20),
          headers: {'Accept': 'application/json'},
        ),
      ) {
    if (adaptador != null) _dio.httpClientAdapter = adaptador;
  }

  final Dio _dio;

  /// Se llama con cada 401. Quien lo recibe decide qué hacer (ver `AvisoSesionInvalida`).
  final void Function() alRecibir401;

  /// Token Sanctum. Nulo hasta el intercambio.
  String? token;

  /// Reloj del servidor menos reloj del dispositivo, según el encabezado `Date` de la última respuesta
  /// que lo trajo (cero hasta entonces). Lo usa `RelojServidor` para las cuentas regresivas.
  Duration desfaseReloj = Duration.zero;

  Future<Object?> get(String ruta, {Map<String, dynamic>? query}) =>
      _enviar(() => _dio.get<Object?>(ruta, queryParameters: query, options: _opciones()));

  Future<Object?> post(String ruta, {Object? datos}) =>
      _enviar(() => _dio.post<Object?>(ruta, data: datos ?? const <String, dynamic>{}, options: _opciones()));

  /// POST `application/x-www-form-urlencoded`, como lo pide `/broadcasting/auth` (protocolo Pusher).
  Future<Object?> postFormulario(String ruta, Map<String, String> campos) => _enviar(
    () => _dio.post<Object?>(
      ruta,
      data: campos,
      options: _opciones(contentType: Headers.formUrlEncodedContentType),
    ),
  );

  Future<Json> getMapa(String ruta, {Map<String, dynamic>? query}) async => leerMapa(await get(ruta, query: query));

  Future<Json> postMapa(String ruta, {Object? datos}) async => leerMapa(await post(ruta, datos: datos));

  Options _opciones({String? contentType}) => Options(
    contentType: contentType ?? Headers.jsonContentType,
    headers: {if (token != null) 'Authorization': 'Bearer $token'},
  );

  Future<Object?> _enviar(Future<Response<Object?>> Function() pedido) async {
    try {
      final r = await pedido();
      _leerReloj(r);
      return r.statusCode == 204 ? null : r.data;
    } on DioException catch (e) {
      if (e.response case final r?) _leerReloj(r);
      throw _traducir(e);
    }
  }

  /// `Date` tiene precisión de segundos y se escribe antes de viajar: el desfase puede quedar corto por
  /// ~1 s más la latencia, y la cuenta regresiva mostrar ese tiempo de más. Si el chofer acepta en ese
  /// margen, el backend responde "La oferta ya no está vigente." y la pantalla lo muestra.
  /// Un encabezado ausente o inválido no cambia nada.
  void _leerReloj(Response<Object?> r) {
    final fecha = r.headers.value('date');
    if (fecha == null) return;
    try {
      desfaseReloj = parseHttpDate(fecha).difference(clock.now().toUtc());
    } on FormatException {
      // Se conserva el desfase anterior.
    }
  }

  ErrorApi _traducir(DioException e) {
    final r = e.response;
    if (r == null) {
      return switch (e.type) {
        DioExceptionType.connectionError ||
        DioExceptionType.connectionTimeout ||
        DioExceptionType.sendTimeout ||
        DioExceptionType.receiveTimeout => const SinConexion(),
        _ => const ErrorServidor(), // p. ej. una respuesta que no es JSON
      };
    }

    final cuerpo = r.data;
    final mensaje = cuerpo is Map && cuerpo['message'] is String && (cuerpo['message'] as String).isNotEmpty
        ? cuerpo['message'] as String
        : null;

    switch (r.statusCode) {
      case 401:
        alRecibir401();
        return SesionInvalida(mensaje ?? 'Tu sesión venció.');
      case 403:
        return AccesoDenegado(mensaje ?? 'No tenés permiso para esta acción.');
      case 404:
        return NoEncontrado(mensaje ?? 'No se encontró lo que buscabas.');
      case 422:
        return ErrorNegocio(mensaje ?? 'No se pudo completar la acción.', errores: _errores(cuerpo));
      case 503:
        return ServicioNoDisponible(mensaje ?? 'Servicio de identidad no disponible.');
      default:
        return const ErrorServidor();
    }
  }

  static Map<String, List<String>> _errores(Object? cuerpo) {
    if (cuerpo is! Map || cuerpo['errors'] is! Map) return const {};
    return (cuerpo['errors'] as Map).map(
      (k, v) => MapEntry(k.toString(), (v as List).map((m) => m.toString()).toList()),
    );
  }
}
```

`paquete/vehiculos_oficiales/lib/src/api/reloj_servidor.dart`:

```dart
import 'package:clock/clock.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import 'cliente_api.dart';

/// Hora del servidor estimada con el desfase de [ClienteApi.desfaseReloj]: un reloj del
/// teléfono adelantado o atrasado no cambia lo que falta para un `vence_en` del backend.
class RelojServidor {
  RelojServidor(this._cliente);

  final ClienteApi _cliente;

  DateTime ahora() => clock.now().toUtc().add(_cliente.desfaseReloj);

  /// Lo que falta para [vence] según el servidor; nunca negativo.
  Duration restante(DateTime vence) {
    final falta = vence.difference(ahora());
    return falta.isNegative ? Duration.zero : falta;
  }
}

/// Con el mismo cliente que [apiProvider] (en los tests, el de la API falsa).
final relojServidorProvider = Provider<RelojServidor>((ref) => RelojServidor(ref.watch(apiProvider).cliente));
```

- [ ] **Step 6: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **141 PASS**, sin problemas, `0 changed`.

- [ ] **Step 7: Commit**

```bash
git add paquete
git commit -m "feat: reloj del servidor para las cuentas regresivas" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: GPS del turno y permisos

**Files:**
- Modify: `paquete/vehiculos_oficiales/lib/src/ubicacion/ubicador.dart`, `test/soporte/dobles.dart` (`UbicadorFalso`)
- Test: `test/ubicador_test.dart` (solo `ajustesGpsTurno`, con `debugDefaultTargetPlatformOverride`; el resto habla con la plataforma y se prueba a través de `UbicadorFalso`)

**Interfaces:**
- Consumes: `Ubicador`, `ubicadorProvider` (plan anterior), geolocator 14.1.1, `PuntoGps` (Task 1).
- Produces:
  - `enum PermisoUbicacion { concedido, denegado, denegadoParaSiempre, gpsApagado }`.
  - En `Ubicador`: `Future<PermisoUbicacion> pedirPermiso()` (nunca lanza), `Stream<PuntoGps> seguir(Duration intervalo)` (errores de la plataforma como errores del stream), `Future<void> abrirAjustes(PermisoUbicacion motivo)` (nunca lanza).
  - `LocationSettings ajustesGpsTurno(Duration intervalo)` (decisión 4, C19).
  - `UbicadorFalso`: `permiso`, `pedidosDePermiso`, `ajustesAbiertos`, `intervalos` (de cada `seguir`), `siguiendo`, `emitir(PuntoGps)`, `fallar(Object)`.

- [ ] **Step 1: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/ubicador_test.dart`:

```dart
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

// Solo los ajustes: el resto de Ubicador habla con la plataforma y se prueba a través de UbicadorFalso.
void main() {
  tearDown(() => debugDefaultTargetPlatformOverride = null);

  test('Android: servicio en primer plano con la notificación fija del turno', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;

    final a = ajustesGpsTurno(const Duration(seconds: 5)) as AndroidSettings;

    expect(a.intervalDuration, const Duration(seconds: 5));
    expect(a.accuracy, LocationAccuracy.high);
    expect(a.distanceFilter, 0);
    final n = a.foregroundNotificationConfig!;
    expect(n.notificationTitle, 'Turno activo – compartiendo ubicación');
    expect(n.setOngoing, isTrue);
    expect(n.enableWakeLock, isTrue);
  });

  test('iOS: sigue en segundo plano y no se pausa solo', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;

    final a = ajustesGpsTurno(const Duration(seconds: 10)) as AppleSettings;

    expect(a.allowBackgroundLocationUpdates, isTrue);
    expect(a.pauseLocationUpdatesAutomatically, isFalse);
    expect(a.showBackgroundLocationIndicator, isTrue);
    expect(a.activityType, ActivityType.automotiveNavigation);
  });

  test('otras plataformas (web): ajustes comunes', () {
    debugDefaultTargetPlatformOverride = TargetPlatform.linux;

    final a = ajustesGpsTurno(const Duration(seconds: 10));

    expect(a, isNot(isA<AndroidSettings>()));
    expect(a, isNot(isA<AppleSettings>()));
    expect(a.accuracy, LocationAccuracy.high);
  });
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `flutter test test/ubicador_test.dart`
Expected: FAIL de compilación (no existe `ajustesGpsTurno`).

- [ ] **Step 3: Implementación**

`paquete/vehiculos_oficiales/lib/src/ubicacion/ubicador.dart` queda así:

```dart
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../modelos/modelos.dart';

/// Resultado de pedir el permiso de ubicación para el turno (spec 9).
enum PermisoUbicacion { concedido, denegado, denegadoParaSiempre, gpsApagado }

/// Ubicación del dispositivo: la posición actual (origen del pedido del solicitante) y el GPS continuo del
/// turno del chofer. Es la única costura con geolocator: los tests usan `UbicadorFalso`.
abstract interface class Ubicador {
  /// Posición actual, pidiendo permiso si hace falta. Nula si se negó el permiso o el GPS está apagado
  /// (spec 9: el solicitante puede marcar el origen a mano).
  Future<Coordenada?> actual();

  /// Pide el permiso "mientras se usa la app" si todavía no se decidió. Nunca lanza: un error de la
  /// plataforma se informa como [PermisoUbicacion.denegado].
  Future<PermisoUbicacion> pedirPermiso();

  /// GPS del turno cada [intervalo], con el servicio en primer plano en Android (spec 7). Los errores
  /// de la plataforma (permiso revocado, GPS apagado) llegan como errores del stream. Para cambiar el
  /// intervalo hay que cancelar la suscripción anterior antes de volver a llamar: geolocator_android
  /// reutiliza el stream abierto mientras tenga alguien escuchando.
  Stream<PuntoGps> seguir(Duration intervalo);

  /// Ajustes del sistema: los de ubicación si el GPS está apagado, los de la app si no. Nunca lanza.
  Future<void> abrirAjustes(PermisoUbicacion motivo);
}

/// Ajustes del GPS del turno (verificados contra geolocator 14.1.1).
LocationSettings ajustesGpsTurno(Duration intervalo) {
  if (defaultTargetPlatform == TargetPlatform.android) {
    return AndroidSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: 0,
      intervalDuration: intervalo,
      foregroundNotificationConfig: const ForegroundNotificationConfig(
        notificationTitle: 'Turno activo – compartiendo ubicación',
        notificationText: 'Vehículos oficiales',
        notificationChannelName: 'Ubicación del turno',
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

class UbicadorGeolocator implements Ubicador {
  @override
  Future<Coordenada?> actual() async {
    if (await pedirPermiso() != PermisoUbicacion.concedido) return null;
    try {
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)),
      );
      return Coordenada(p.latitude, p.longitude);
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo obtener la ubicación (${e.runtimeType}).');
      return null;
    }
  }

  @override
  Future<PermisoUbicacion> pedirPermiso() async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) return PermisoUbicacion.gpsApagado;
      var permiso = await Geolocator.checkPermission();
      if (permiso == LocationPermission.denied) permiso = await Geolocator.requestPermission();
      return switch (permiso) {
        LocationPermission.whileInUse || LocationPermission.always => PermisoUbicacion.concedido,
        LocationPermission.deniedForever => PermisoUbicacion.denegadoParaSiempre,
        LocationPermission.denied || LocationPermission.unableToDetermine => PermisoUbicacion.denegado,
      };
    } catch (e) {
      // P. ej. PermissionDefinitionsNotFoundException (falta el permiso en el manifiesto de la app) o un
      // pedido de permiso que ya estaba en curso.
      debugPrint('vehiculos_oficiales: no se pudo pedir el permiso de ubicación (${e.runtimeType}).');
      return PermisoUbicacion.denegado;
    }
  }

  @override
  Stream<PuntoGps> seguir(Duration intervalo) =>
      Geolocator.getPositionStream(locationSettings: ajustesGpsTurno(intervalo)).map(
        (p) => PuntoGps(
          posicion: Coordenada(p.latitude, p.longitude),
          rumbo: p.heading,
          velocidad: p.speed,
          registradoEn: p.timestamp.toUtc(),
        ),
      );

  @override
  Future<void> abrirAjustes(PermisoUbicacion motivo) async {
    try {
      if (motivo == PermisoUbicacion.gpsApagado) {
        await Geolocator.openLocationSettings();
      } else {
        await Geolocator.openAppSettings();
      }
    } catch (e) {
      debugPrint('vehiculos_oficiales: no se pudieron abrir los ajustes (${e.runtimeType}).');
    }
  }
}

final ubicadorProvider = Provider<Ubicador>((ref) => UbicadorGeolocator());
```

- [ ] **Step 4: Doble del ubicador**

En `paquete/vehiculos_oficiales/test/soporte/dobles.dart`, reemplazar la clase `UbicadorFalso` (al final del archivo) por:

```dart
/// Ubicador sin geolocator: la posición actual es fija y el GPS del turno emite los puntos que el test
/// mande con [emitir] (o un error con [fallar]).
class UbicadorFalso implements Ubicador {
  UbicadorFalso([this.posicion]);

  /// Nula = permiso denegado o GPS apagado.
  Coordenada? posicion;

  /// Lo que responde [pedirPermiso].
  PermisoUbicacion permiso = PermisoUbicacion.concedido;
  int pedidosDePermiso = 0;
  final ajustesAbiertos = <PermisoUbicacion>[];

  /// Intervalo de cada `seguir()`, en orden.
  final intervalos = <Duration>[];
  StreamController<PuntoGps>? _gps;

  /// Hay alguien escuchando el GPS del turno.
  bool get siguiendo => _gps?.hasListener ?? false;

  void emitir(PuntoGps p) => _gps?.add(p);

  void fallar(Object error) => _gps?.addError(error);

  @override
  Future<Coordenada?> actual() async => posicion;

  @override
  Future<PermisoUbicacion> pedirPermiso() async {
    pedidosDePermiso++;
    return permiso;
  }

  @override
  Stream<PuntoGps> seguir(Duration intervalo) {
    intervalos.add(intervalo);
    final gps = _gps = StreamController<PuntoGps>(sync: true);
    gps.onCancel = () {
      if (identical(_gps, gps)) _gps = null;
    };
    return gps.stream;
  }

  @override
  Future<void> abrirAjustes(PermisoUbicacion motivo) async => ajustesAbiertos.add(motivo);
}
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **144 PASS** (los tests del solicitante que usan `UbicadorFalso([posicion])` siguen igual), sin problemas, `0 changed`.

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: GPS del turno con servicio en primer plano y permisos" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Cola y envío de ubicaciones

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/chofer/cola_ubicaciones.dart`, `lib/src/chofer/emisor_ubicacion.dart`
- Modify: `test/soporte/dobles_chofer.dart` (`segundos`)
- Test: `test/cola_ubicaciones_test.dart`, `test/emisor_ubicacion_test.dart`

**Interfaces:**
- Consumes: `ApiVehiculos.enviarUbicacion`, `PuntoGps` (Task 1), errores de la API.
- Produces:
  - `ColaUbicaciones({int tope = 5000})`: `void agregar(PuntoGps)` (ignora un `registradoEn` repetido, mantiene el orden aunque llegue uno más viejo, descarta los más viejos al pasar el tope), `List<PuntoGps> primeros(int n)`, `void quitar(Iterable<PuntoGps> enviados)` (C2), `int get largo`, `void vaciar()`.
  - `enum ResultadoEnvio { enviado, sinCambios, reintentar, sinTurno }` y `EmisorUbicacion({required ApiVehiculos api, required ColaUbicaciones cola, int lote = 500})`: `Future<ResultadoEnvio> enviar()` (un pedido por vez; si ya hay uno en curso devuelve ese mismo `Future`; nunca lanza `ErrorApi`), `Future<ResultadoEnvio> vaciarTodo()` (manda lotes hasta vaciar o hasta el primer envío que no salió). C3.

- [ ] **Step 1: Ayuda para los tests**

En `paquete/vehiculos_oficiales/test/soporte/dobles_chofer.dart`, antes de `class ApiChofer`:

```dart
/// Segundos de cada punto desde las 12:00 (la hora de [punto]).
List<int> segundos(List<PuntoGps> puntos) => [
  for (final p in puntos) p.registradoEn.difference(DateTime.utc(2026, 10, 1, 12)).inSeconds,
];
```

- [ ] **Step 2: Escribir los tests que fallan**

`paquete/vehiculos_oficiales/test/cola_ubicaciones_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_ubicaciones.dart';

import 'soporte/dobles_chofer.dart';

void main() {
  test('queda ordenada por registrado_en aunque llegue uno más viejo', () {
    final cola = ColaUbicaciones()
      ..agregar(punto(20))
      ..agregar(punto(0))
      ..agregar(punto(10));

    expect(segundos(cola.primeros(10)), [0, 10, 20]);
  });

  test('un punto repetido del GPS (misma hora) no se encola dos veces', () {
    final cola = ColaUbicaciones()
      ..agregar(punto(0))
      ..agregar(punto(0, lat: -26.9))
      ..agregar(punto(10));

    expect(cola.largo, 2);
    expect(cola.primeros(1).single.posicion.lat, -26.83);
  });

  test('al pasar el tope se descartan los más viejos', () {
    final cola = ColaUbicaciones(tope: 3);
    for (var s = 0; s < 5; s++) {
      cola.agregar(punto(s));
    }

    expect(segundos(cola.primeros(10)), [2, 3, 4]);
  });

  test('quitar saca exactamente lo enviado aunque mientras tanto entre uno más viejo', () {
    final cola = ColaUbicaciones()
      ..agregar(punto(10))
      ..agregar(punto(20));
    final enviados = cola.primeros(2);
    cola.agregar(punto(5));

    cola.quitar(enviados);

    expect(segundos(cola.primeros(10)), [5]);
    cola.vaciar();
    expect(cola.largo, 0);
  });
}
```

`paquete/vehiculos_oficiales/test/emisor_ubicacion_test.dart`:

```dart
import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/cola_ubicaciones.dart';
import 'package:vehiculos_oficiales/src/chofer/emisor_ubicacion.dart';

import 'soporte/dobles_chofer.dart';

void main() {
  late ApiChofer api;
  late ColaUbicaciones cola;
  late EmisorUbicacion emisor;

  setUp(() {
    api = ApiChofer();
    cola = ColaUbicaciones();
    emisor = EmisorUbicacion(api: api, cola: cola);
  });

  test('con la cola vacía no manda nada', () async {
    expect(await emisor.enviar(), ResultadoEnvio.sinCambios);
    expect(api.lotes, isEmpty);
  });

  test('204: manda en orden y saca exactamente lo enviado', () async {
    cola
      ..agregar(punto(10))
      ..agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.enviado);

    expect(segundos(api.lotes.single), [0, 10]);
    expect(cola.largo, 0);
  });

  test('sin red y con 5xx no se pierde nada: el reintento manda lo mismo, en el mismo orden, más lo nuevo', () async {
    api.erroresUbicacion.addAll([const SinConexion(), const ErrorServidor()]);
    cola
      ..agregar(punto(0))
      ..agregar(punto(10));

    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    cola.agregar(punto(20));
    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    cola.agregar(punto(30));
    expect(await emisor.enviar(), ResultadoEnvio.enviado);

    expect(api.lotes.map(segundos), [
      [0, 10],
      [0, 10, 20],
      [0, 10, 20, 30],
    ]);
    expect(cola.largo, 0);
  });

  test('más de 500 puntos salen en lotes de 500; vaciarTodo manda hasta vaciar', () async {
    for (var s = 0; s < 1203; s++) {
      cola.agregar(punto(s));
    }

    expect(await emisor.vaciarTodo(), ResultadoEnvio.enviado);

    expect(api.lotes.map((l) => l.length), [500, 500, 203]);
    expect(segundos(api.lotes[1]).first, 500);
    expect(cola.largo, 0);
  });

  test('vaciarTodo se detiene en el primer error y deja el resto', () async {
    for (var s = 0; s < 700; s++) {
      cola.agregar(punto(s));
    }
    api.erroresUbicacion.addAll([const ErrorServidor()]);

    expect(await emisor.vaciarTodo(), ResultadoEnvio.reintentar);

    expect(api.lotes, hasLength(1));
    expect(cola.largo, 700);
  });

  test('422 "Iniciá un turno…" o 403: sinTurno, sin tocar la cola', () async {
    api.erroresUbicacion.addAll([
      const ErrorNegocio('Iniciá un turno para compartir tu ubicación.'),
      const AccesoDenegado('No tenés permiso para esta acción.'),
    ]);
    cola.agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.sinTurno);
    expect(await emisor.enviar(), ResultadoEnvio.sinTurno);
    expect(cola.largo, 1);
  });

  test('un lote que no pasa la validación se descarta para no trabar la cola', () async {
    api.erroresUbicacion.add(
      const ErrorNegocio(
        'El campo puntos.0.lat debe estar entre -90 y 90.',
        errores: {
          'puntos.0.lat': ['El campo puntos.0.lat debe estar entre -90 y 90.'],
        },
      ),
    );
    cola.agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    expect(cola.largo, 0);
  });

  test('un 401 no escapa: queda para reintentar', () async {
    api.erroresUbicacion.add(const SesionInvalida());
    cola.agregar(punto(0));

    expect(await emisor.enviar(), ResultadoEnvio.reintentar);
    expect(cola.largo, 1);
  });

  test('dos enviar() a la vez hacen un solo POST', () async {
    api.demoraUbicacion = Completer<void>();
    cola.agregar(punto(0));

    final a = emisor.enviar();
    final b = emisor.enviar();
    api.demoraUbicacion!.complete();

    expect(await a, ResultadoEnvio.enviado);
    expect(await b, ResultadoEnvio.enviado);
    expect(api.lotes, hasLength(1));
  });
}
```

- [ ] **Step 3: Correr y ver que fallan**

Run: `flutter test test/cola_ubicaciones_test.dart test/emisor_ubicacion_test.dart`
Expected: FAIL de compilación (no existe `lib/src/chofer/`).

- [ ] **Step 4: Implementación**

`paquete/vehiculos_oficiales/lib/src/chofer/cola_ubicaciones.dart`:

```dart
import 'dart:collection';

import '../modelos/modelos.dart';

/// Puntos del GPS que todavía no llegaron al servidor (spec 6 y 9), en memoria.
///
/// Siempre ordenados por `registrado_en` (aunque llegue uno más viejo), sin repetidos (misma hora en
/// milisegundos) y con un [tope]: si se llena, se descartan los más viejos (5000 puntos son ~14 h a 10 s).
class ColaUbicaciones {
  ColaUbicaciones({this.tope = 5000});

  final int tope;
  final _puntos = SplayTreeMap<int, PuntoGps>();

  int get largo => _puntos.length;

  void agregar(PuntoGps p) {
    _puntos.putIfAbsent(p.registradoEn.millisecondsSinceEpoch, () => p);
    while (_puntos.length > tope) {
      _puntos.remove(_puntos.firstKey());
    }
  }

  /// Los [n] más viejos, en orden.
  List<PuntoGps> primeros(int n) => _puntos.values.take(n).toList();

  /// Saca exactamente los puntos de un envío que el servidor recibió (aunque mientras tanto hayan
  /// entrado otros más viejos o el tope haya descartado alguno).
  void quitar(Iterable<PuntoGps> enviados) {
    for (final p in enviados) {
      _puntos.remove(p.registradoEn.millisecondsSinceEpoch);
    }
  }

  void vaciar() => _puntos.clear();
}
```

`paquete/vehiculos_oficiales/lib/src/chofer/emisor_ubicacion.dart`:

```dart
import 'package:flutter/foundation.dart';

import '../api/api_vehiculos.dart';
import '../api/errores_api.dart';
import 'cola_ubicaciones.dart';

enum ResultadoEnvio {
  /// El servidor recibió el lote (204) y salió de la cola.
  enviado,

  /// No había nada que mandar.
  sinCambios,

  /// Sin red, 5xx, 401…: el lote queda en la cola y se reintenta en el ciclo siguiente.
  reintentar,

  /// El backend dice que no hay turno abierto (422 "Iniciá un turno…") o que ya no es chofer (403):
  /// hay que dejar de rastrear.
  sinTurno,
}

/// Único lugar desde donde sale `POST /ubicacion` (spec 6 y 9). Un pedido por vez, en orden, de a
/// [lote] puntos como máximo; solo saca de la cola lo que el servidor confirmó.
class EmisorUbicacion {
  EmisorUbicacion({required this.api, required this.cola, this.lote = 500});

  final ApiVehiculos api;
  final ColaUbicaciones cola;
  final int lote;

  Future<ResultadoEnvio>? _enCurso;

  /// Manda el lote más viejo. Si ya hay un envío en curso, devuelve ese mismo (no sale otro pedido).
  /// Nunca lanza un [ErrorApi].
  Future<ResultadoEnvio> enviar() {
    final enCurso = _enCurso;
    if (enCurso != null) return enCurso;
    if (cola.largo == 0) return Future.value(ResultadoEnvio.sinCambios);
    final envio = _enviarLote();
    _enCurso = envio;
    return envio.whenComplete(() => _enCurso = null);
  }

  /// Manda lotes hasta vaciar la cola o hasta el primer envío que no salió. Devuelve el último resultado.
  Future<ResultadoEnvio> vaciarTodo() async {
    var resultado = ResultadoEnvio.sinCambios;
    while (cola.largo > 0) {
      resultado = await enviar();
      if (resultado != ResultadoEnvio.enviado) return resultado;
    }
    return resultado;
  }

  Future<ResultadoEnvio> _enviarLote() async {
    final puntos = cola.primeros(lote);
    try {
      await api.enviarUbicacion(puntos);
      cola.quitar(puntos);
      return ResultadoEnvio.enviado;
    } on ErrorNegocio catch (e) {
      if (e.errores.isEmpty) return ResultadoEnvio.sinTurno; // la única regla de negocio de ServicioUbicacion
      // Validación de Laravel: el lote nunca va a pasar. Se descarta para no trabar la cola para siempre.
      debugPrint('vehiculos_oficiales: lote de ubicaciones rechazado y descartado: ${e.errores.keys.first}');
      cola.quitar(puntos);
      return ResultadoEnvio.reintentar;
    } on AccesoDenegado {
      return ResultadoEnvio.sinTurno;
    } on ErrorApi {
      // Incluye SesionInvalida: ClienteApi ya avisó a la app principal.
      return ResultadoEnvio.reintentar;
    }
  }
}
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **157 PASS**, sin problemas, `0 changed`.

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: cola ordenada de ubicaciones con envío por lotes y reintento" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Turno y rastreo

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/chofer/rastreador_turno.dart`, `lib/src/chofer/turno.dart`
- Test: `test/turno_test.dart`

**Interfaces:**
- Consumes: `Ubicador.seguir/pedirPermiso` (Task 3), `ColaUbicaciones`, `EmisorUbicacion` (Task 4), `viajeActualProvider`, `apiProvider` (`configuracion`, `turnoActual`, `iniciarTurno`, `finalizarTurno`, `vehiculosDisponibles`).
- Produces:
  - `configuracionProvider` (`FutureProvider<Configuracion>`, cae a `configuracionPorDefecto` si falla, C7), `vehiculosDisponiblesProvider` (`FutureProvider.autoDispose<List<Vehiculo>>`).
  - `posicionPropiaProvider` (`PosicionPropia {PuntoGps? punto, bool sinGps}`, C6) y `bool viajeActivo(Viaje?)` (aceptado a en curso).
  - `RastreadorTurno({ubicador, cola, emisor, intervaloTurno, intervaloViaje, alPunto, alErrorGps, alQuedarSinTurno})`: `void iniciar({bool enViaje})`, `void enViaje(bool)` (reabre el GPS con el otro intervalo y cambia el ritmo de envío), `void reabrirGps()`, `Future<ResultadoEnvio> vaciar()`, `void detener()` (corta GPS y timer y vacía la cola), `bool get activo`.
  - `turnoProvider` (`AsyncNotifier<Turno?>`) con `Future<PermisoUbicacion> iniciar(int vehiculoId)` (si el permiso no es `concedido` no llama a la API y lo devuelve), `Future<void> finalizar()`, `Future<void> reintentarGps()`; escucha `viajeActualProvider` para `enViaje`.

- [ ] **Step 1: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/turno_test.dart`:

```dart
import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/chofer/turno.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import 'soporte/dobles.dart';
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  late ApiChofer api;
  late UbicadorFalso gps;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiChofer();
    gps = UbicadorFalso();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      ubicadorProvider.overrideWithValue(gps),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(chofer),
    ]);
    c.listen(turnoProvider, (_, _) {});
    return c;
  }

  int envios() => api.llamadas.where((l) => l.startsWith('ubicacion')).length;

  test('sin turno no se sigue el GPS', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      expect(c.read(turnoProvider).value, isNull);
      expect(gps.intervalos, isEmpty);
      expect(gps.siguiendo, isFalse);
    });
  });

  test('con un turno abierto al abrir, retoma el rastreo y manda lotes cada 10 s', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();

      expect(c.read(turnoProvider).value!.id, 1);
      expect(gps.intervalos, [const Duration(seconds: 10)]);
      gps
        ..emitir(punto(0))
        ..emitir(punto(5));
      expect(c.read(posicionPropiaProvider).punto!.registradoEn, punto(5).registradoEn);

      async.elapse(const Duration(seconds: 9));
      expect(envios(), 0);
      async.elapse(const Duration(seconds: 1));
      expect(segundos(api.lotes.single), [0, 5]);

      async.elapse(const Duration(seconds: 10)); // cola vacía: no sale nada
      expect(envios(), 1);
    });
  });

  test('con un viaje activo el GPS y los envíos pasan a 5 s, y vuelven a 10 s al terminar', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      crear();
      async.flushMicrotasks();

      expect(gps.intervalos.last, const Duration(seconds: 5));
      gps.emitir(punto(0));
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 1);

      tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'finalizado', conChofer: true)));
      async.flushMicrotasks();
      expect(gps.intervalos.last, const Duration(seconds: 10));
      expect(gps.siguiendo, isTrue);

      gps.emitir(punto(10));
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 1);
      async.elapse(const Duration(seconds: 5));
      expect(envios(), 2);
    });
  });

  test('los intervalos salen de GET /configuracion', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      api.configuracionRespuesta = const Configuracion(gpsTurnoSeg: 15, gpsViajeSeg: 3, ofertaSegundos: 30);
      crear();
      async.flushMicrotasks();

      expect(gps.intervalos, [const Duration(seconds: 15)]);
    });
  });

  test('iniciar: pide permiso, POST /turnos y arranca el GPS', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      PermisoUbicacion? permiso;
      c.read(turnoProvider.notifier).iniciar(1).then((p) => permiso = p);
      async.flushMicrotasks();

      expect(permiso, PermisoUbicacion.concedido);
      expect(api.llamadas, contains('iniciar:1'));
      expect(c.read(turnoProvider).value!.abierto, isTrue);
      expect(gps.siguiendo, isTrue);
    });
  });

  test('permiso denegado: no llama a POST /turnos ni sigue el GPS', () {
    fakeAsync((async) {
      gps.permiso = PermisoUbicacion.denegadoParaSiempre;
      final c = crear();
      async.flushMicrotasks();

      PermisoUbicacion? permiso;
      c.read(turnoProvider.notifier).iniciar(1).then((p) => permiso = p);
      async.flushMicrotasks();

      expect(permiso, PermisoUbicacion.denegadoParaSiempre);
      expect(api.llamadas.where((l) => l.startsWith('iniciar')), isEmpty);
      expect(gps.siguiendo, isFalse);
    });
  });

  test('finalizar vacía la cola antes de cerrar el turno y después no sale ningún punto', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();
      gps
        ..emitir(punto(0))
        ..emitir(punto(3));

      c.read(turnoProvider.notifier).finalizar();
      async.flushMicrotasks();

      expect(api.llamadas.skipWhile((l) => l != 'ubicacion:2'), ['ubicacion:2', 'finalizar']);
      expect(c.read(turnoProvider).value, isNull);
      expect(gps.siguiendo, isFalse);
      expect(c.read(posicionPropiaProvider).punto, isNull);

      async.elapse(const Duration(minutes: 1));
      expect(envios(), 1);
    });
  });

  test('un 422 al finalizar deja el turno y el rastreo como estaban', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      api.errorFinalizar = const ErrorNegocio('Finalizá el viaje en curso antes de cerrar el turno.');
      final c = crear();
      async.flushMicrotasks();

      Object? error;
      c.read(turnoProvider.notifier).finalizar().catchError((Object e) => error = e);
      async.flushMicrotasks();

      expect(error, isA<ErrorNegocio>());
      expect(c.read(turnoProvider).value, isNotNull);
      expect(gps.siguiendo, isTrue);
      gps.emitir(punto(20));
      async.elapse(const Duration(seconds: 10));
      expect(envios(), 1);
    });
  });

  test('un 422 "Iniciá un turno…" del envío detiene el rastreo y vuelve a preguntar el turno', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();

      api
        ..turno = null
        ..erroresUbicacion.add(const ErrorNegocio('Iniciá un turno para compartir tu ubicación.'));
      gps.emitir(punto(0));
      async.elapse(const Duration(seconds: 10));

      expect(gps.siguiendo, isFalse);
      expect(api.llamadas.where((l) => l == 'turnoActual'), hasLength(2));
      expect(c.read(turnoProvider).value, isNull);
      async.elapse(const Duration(minutes: 1));
      expect(envios(), 1);
    });
  });

  test('sin red los puntos se acumulan y al volver salen juntos y en orden', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      crear();
      async.flushMicrotasks();

      api.erroresUbicacion.addAll([const SinConexion(), const SinConexion(), const SinConexion()]);
      for (var ciclo = 0; ciclo < 4; ciclo++) {
        gps.emitir(punto(ciclo * 10 + 5));
        gps.emitir(punto(ciclo * 10 + 5)); // repetido del GPS
        async.elapse(const Duration(seconds: 10));
      }

      expect(segundos(api.lotes.last), [5, 15, 25, 35]);
      expect(api.lotes, hasLength(4));
    });
  });

  test('un error del GPS se avisa, Reintentar reabre el GPS sin perder la cola y un 401 no escapa', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();

      gps.fallar(Exception('GPS apagado'));
      expect(c.read(posicionPropiaProvider).sinGps, isTrue);
      gps.emitir(punto(1));
      expect(c.read(posicionPropiaProvider).sinGps, isFalse);

      gps.fallar(Exception('permiso revocado'));
      c.read(turnoProvider.notifier).reintentarGps();
      async.flushMicrotasks();
      expect(gps.pedidosDePermiso, 2);
      expect(gps.intervalos, hasLength(2));
      expect(gps.siguiendo, isTrue);

      api.erroresUbicacion.add(const SesionInvalida());
      async.elapse(const Duration(seconds: 10));
      expect(gps.siguiendo, isTrue);
      async.elapse(const Duration(seconds: 10));
      expect(segundos(api.lotes.last), [1]); // el punto de antes de reabrir no se perdió
    });
  });

  test('un error al leer el turno queda como error de la pantalla, sin GPS', () {
    fakeAsync((async) {
      api.errorTurnoActual = const SinConexion();
      final c = crear();
      async.flushMicrotasks();

      expect(c.read(turnoProvider).hasError, isTrue);
      expect(gps.intervalos, isEmpty);
    });
  });

  test('al descartar el contenedor (cerrar el módulo) se corta el GPS', () {
    fakeAsync((async) {
      api.turno = turnoDePrueba();
      final c = crear();
      async.flushMicrotasks();
      expect(gps.siguiendo, isTrue);

      c.dispose();
      async.flushMicrotasks();

      expect(gps.siguiendo, isFalse);
      async.elapse(const Duration(minutes: 1));
      expect(envios(), 0);
    });
  });
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `flutter test test/turno_test.dart`
Expected: FAIL de compilación (no existe `lib/src/chofer/turno.dart`).

- [ ] **Step 3: Rastreador**

`paquete/vehiculos_oficiales/lib/src/chofer/rastreador_turno.dart`:

```dart
import 'dart:async';

import 'package:flutter/foundation.dart';

import '../modelos/modelos.dart';
import '../ubicacion/ubicador.dart';
import 'cola_ubicaciones.dart';
import 'emisor_ubicacion.dart';

/// Une el GPS del turno con la cola y el emisor (decisiones 4 y 5): cada punto va a la cola y cada
/// [intervaloTurno] (o [intervaloViaje] con un viaje activo) sale un lote. Es dueño del stream del GPS
/// y del timer de envío: [detener] los libera.
class RastreadorTurno {
  RastreadorTurno({
    required this.ubicador,
    required this.cola,
    required this.emisor,
    required this.intervaloTurno,
    required this.intervaloViaje,
    required this.alPunto,
    required this.alErrorGps,
    required this.alQuedarSinTurno,
  });

  final Ubicador ubicador;
  final ColaUbicaciones cola;
  final EmisorUbicacion emisor;
  final Duration intervaloTurno;
  final Duration intervaloViaje;

  /// Cada punto del GPS (para mostrar la posición propia).
  final void Function(PuntoGps punto) alPunto;

  /// El GPS falló (permiso revocado, GPS apagado…). El envío de lo pendiente sigue.
  final void Function(Object error) alErrorGps;

  /// El backend respondió que no hay turno (o que ya no es chofer). El rastreo ya está detenido.
  final void Function() alQuedarSinTurno;

  StreamSubscription<PuntoGps>? _gps;
  Timer? _envio;
  bool _activo = false;
  bool _enViaje = false;

  bool get activo => _activo;

  Duration get intervalo => _enViaje ? intervaloViaje : intervaloTurno;

  void iniciar({bool enViaje = false}) {
    if (_activo) return;
    _activo = true;
    _enViaje = enViaje;
    _abrir();
  }

  /// Con un viaje activo el GPS y el envío pasan a [intervaloViaje]; sin viaje, a [intervaloTurno].
  void enViaje(bool activo) {
    if (_enViaje == activo) return;
    _enViaje = activo;
    if (_activo) _abrir();
  }

  /// Vuelve a abrir el GPS (después de que falló) sin tocar la cola.
  void reabrirGps() {
    if (_activo) _abrir();
  }

  void _abrir() {
    _envio?.cancel();
    // geolocator_android reutiliza el stream mientras alguien lo escuche: se cancela el anterior antes de
    // abrir otro con el intervalo nuevo. La cancelación suelta el stream en el momento (no hace falta
    // esperar el Future de `cancel`).
    _cancelar(_gps);
    _gps = null;
    try {
      _gps = ubicador.seguir(intervalo).listen(_alPunto, onError: alErrorGps);
    } catch (e) {
      alErrorGps(e); // un plugin que lanza al abrir el stream (p. ej. sin implementación en la plataforma)
    }
    _envio = Timer.periodic(intervalo, (_) => unawaited(_enviar()));
  }

  void _alPunto(PuntoGps p) {
    cola.agregar(p);
    alPunto(p);
  }

  Future<void> _enviar() async {
    final resultado = await emisor.enviar();
    if (resultado == ResultadoEnvio.sinTurno && _activo) {
      detener();
      alQuedarSinTurno();
    }
  }

  /// Un intento de mandar todo lo pendiente (antes de finalizar el turno).
  Future<ResultadoEnvio> vaciar() => emisor.vaciarTodo();

  /// Corta el GPS (y con él la notificación fija de Android) y el envío. Lo pendiente se descarta.
  void detener() {
    _activo = false;
    _envio?.cancel();
    _envio = null;
    _cancelar(_gps);
    _gps = null;
    cola.vaciar();
  }

  static void _cancelar(StreamSubscription<PuntoGps>? gps) {
    if (gps == null) return;
    unawaited(gps.cancel().catchError((Object e) => debugPrint('vehiculos_oficiales: error al cortar el GPS ($e).')));
  }
}
```

- [ ] **Step 4: Turno**

`paquete/vehiculos_oficiales/lib/src/chofer/turno.dart`:

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../ubicacion/ubicador.dart';
import '../viaje/viaje_actual.dart';
import 'cola_ubicaciones.dart';
import 'emisor_ubicacion.dart';
import 'rastreador_turno.dart';

/// Los valores por defecto del backend (spec 5.7), si `GET /configuracion` no responde.
const configuracionPorDefecto = Configuracion(gpsTurnoSeg: 10, gpsViajeSeg: 5, ofertaSegundos: 30);

/// `GET /configuracion`. Si falla se usan los valores por defecto: el GPS del turno no puede depender de
/// que ese pedido salga bien.
final configuracionProvider = FutureProvider<Configuracion>((ref) async {
  try {
    return await ref.watch(apiProvider).configuracion();
  } on ErrorApi {
    return configuracionPorDefecto;
  }
});

/// `GET /vehiculos/disponibles` para la pantalla "Iniciar turno".
final vehiculosDisponiblesProvider = FutureProvider.autoDispose<List<Vehiculo>>(
  (ref) => ref.watch(apiProvider).vehiculosDisponibles(),
);

/// Última posición propia del GPS del turno, y si el GPS está fallando (para avisar en el mapa).
class PosicionPropia {
  const PosicionPropia({this.punto, this.sinGps = false});

  final PuntoGps? punto;
  final bool sinGps;
}

final posicionPropiaProvider = NotifierProvider<PosicionPropiaNotifier, PosicionPropia>(PosicionPropiaNotifier.new);

class PosicionPropiaNotifier extends Notifier<PosicionPropia> {
  @override
  PosicionPropia build() => const PosicionPropia();

  void punto(PuntoGps p) => state = PosicionPropia(punto: p);

  void sinGps() => state = PosicionPropia(punto: state.punto, sinGps: true);

  void limpiar() => state = const PosicionPropia();
}

/// Un viaje activo (aceptado a en curso) cambia el ritmo del GPS (spec 5.7).
bool viajeActivo(Viaje? v) => v != null && v.estado.conChofer;

final turnoProvider = AsyncNotifierProvider<TurnoNotifier, Turno?>(TurnoNotifier.new);

/// Turno del chofer (spec 7, chofer 1 y 6). Mientras está abierto, y solo entonces, corre un [RastreadorTurno]
/// (spec 10). Es el único dueño del rastreo: lo arranca, lo detiene y lo libera en `onDispose`.
class TurnoNotifier extends AsyncNotifier<Turno?> {
  RastreadorTurno? _rastreador;

  /// Cambia al detener el rastreo o descartar el notifier: un arranque que quedó esperando la
  /// configuración no arranca un rastreo viejo.
  int _generacion = 0;

  @override
  Future<Turno?> build() async {
    ref.onDispose(() {
      // Al cerrar el módulo (o recargar el turno) se corta el GPS. En onDispose no se puede usar `ref`.
      _generacion++;
      final r = _rastreador;
      _rastreador = null;
      r?.detener();
    });
    ref.listen(
      viajeActualProvider.select((s) => viajeActivo(s.value?.viaje)),
      (_, activo) => _rastreador?.enViaje(activo),
    );

    final turno = await ref.read(apiProvider).turnoActual();
    if (turno != null) {
      // Turno abierto de antes (la app se cerró o se reabrió el módulo): se retoma el rastreo. Si el
      // permiso ya no está, el GPS falla y el mapa lo avisa.
      await ref.read(ubicadorProvider).pedirPermiso();
      await _iniciarRastreo();
    }
    return turno;
  }

  /// Spec 7, chofer 1. Sin permiso de ubicación no se llama a la API y se devuelve el motivo. Los errores
  /// del backend (422 "El vehículo está en uso por otro chofer.") llegan a la pantalla.
  Future<PermisoUbicacion> iniciar(int vehiculoId) async {
    final permiso = await ref.read(ubicadorProvider).pedirPermiso();
    if (permiso != PermisoUbicacion.concedido) return permiso;

    final turno = await ref.read(apiProvider).iniciarTurno(vehiculoId);
    if (!ref.mounted) return permiso;
    state = AsyncData(turno);
    await _iniciarRastreo();
    return permiso;
  }

  /// Primero intenta mandar lo pendiente, después cierra el turno. Un 422 ("Finalizá el viaje en curso
  /// antes de cerrar el turno.") llega a la pantalla y el turno y el rastreo siguen como estaban.
  Future<void> finalizar() async {
    await _rastreador?.vaciar();
    await ref.read(apiProvider).finalizarTurno();
    if (!ref.mounted) return;
    _detenerRastreo();
    state = const AsyncData(null);
  }

  /// "Reintentar" del aviso de GPS: vuelve a pedir permiso y reabre el GPS (un stream que falló no se
  /// recupera solo). Lo pendiente en la cola se conserva.
  Future<void> reintentarGps() async {
    await ref.read(ubicadorProvider).pedirPermiso();
    if (ref.mounted) _rastreador?.reabrirGps();
  }

  Future<void> _iniciarRastreo() async {
    if (_rastreador != null) return;
    final generacion = _generacion;
    final conf = await ref.read(configuracionProvider.future);
    if (!ref.mounted || generacion != _generacion || _rastreador != null) return;

    final cola = ColaUbicaciones();
    final posicion = ref.read(posicionPropiaProvider.notifier);
    _rastreador = RastreadorTurno(
      ubicador: ref.read(ubicadorProvider),
      cola: cola,
      emisor: EmisorUbicacion(api: ref.read(apiProvider), cola: cola),
      intervaloTurno: Duration(seconds: conf.gpsTurnoSeg),
      intervaloViaje: Duration(seconds: conf.gpsViajeSeg),
      alPunto: posicion.punto,
      alErrorGps: (_) => posicion.sinGps(),
      alQuedarSinTurno: () => unawaited(_alQuedarSinTurno()),
    )..iniciar(enViaje: viajeActivo(ref.read(viajeActualProvider).value?.viaje));
  }

  void _detenerRastreo() {
    _generacion++;
    _rastreador?.detener();
    _rastreador = null;
    ref.read(posicionPropiaProvider.notifier).limpiar();
  }

  /// El backend dice que no hay turno (lo cerró un admin, o se cerró en otro dispositivo): se deja de
  /// rastrear y se vuelve a preguntar. Corre sin await desde el timer: no puede propagar errores.
  Future<void> _alQuedarSinTurno() async {
    if (!ref.mounted) return;
    _detenerRastreo();
    try {
      final turno = await ref.read(apiProvider).turnoActual();
      if (!ref.mounted) return;
      state = AsyncData(turno);
      if (turno != null) await _iniciarRastreo();
    } on ErrorApi {
      if (ref.mounted) state = const AsyncData(null);
    }
  }
}
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **170 PASS**, sin problemas, `0 changed`. Si `_cancelar` hiciera `await` del `cancel()`, varios tests de este archivo quedan colgados con el rastreo a medio cortar (C5).

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: turno del chofer con rastreo GPS mientras está abierto" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Pantallas de turno y mapa del chofer

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/ui/chofer/iniciar_turno.dart`, `lib/src/ui/chofer/mapa_chofer.dart`, `test/soporte/montar_chofer.dart`
- Modify (reemplazar la provisoria): `lib/src/ui/chofer/inicio_chofer.dart`
- Test: `test/ui/iniciar_turno_test.dart`

**Interfaces:**
- Consumes: `turnoProvider`, `vehiculosDisponiblesProvider`, `posicionPropiaProvider` (Task 5), `choferesMapaProvider` (estado propio), `constructorMapaProvider`, `ubicadorProvider`, `cerrarModuloProvider`, `montarModulo`/`esperar`/`mapaDePrueba` (plan anterior).
- Produces:
  - `InicioChofer`: cargando → `IniciarTurno` si no hay turno, `MapaChofer` si hay (con error: mensaje y "Reintentar"). "Atrás" desde el mapa con turno abierto pide la misma confirmación que la X (C8).
  - `BotonCerrarModulo` y `cerrarModuloChofer(context, ref)`: con el turno abierto pregunta antes de cerrar.
  - `IniciarTurno`: lista de `vehiculosDisponibles` (marca, modelo, patente, color), "Iniciar turno"; permiso denegado → texto de spec 9 + "Abrir ajustes"; 422 ("El vehículo está en uso por otro chofer.") → mensaje y recarga la lista.
  - `MapaChofer`: decisión 10 sin la agenda (llega en la Task 9): posición propia, estado propio, vehículo, aviso de GPS con "Abrir ajustes"/"Reintentar" y "Finalizar turno" (confirmación; 422 visible).
  - Tests: `entornoChofer({conTurno, viajeActual, agenda})`, `montarChofer(tester, e, {tiempoReal, ubicador, extra})`, `pedidosHechos(e)`.

- [ ] **Step 1: Montaje de prueba del chofer**

`paquete/vehiculos_oficiales/test/soporte/montar_chofer.dart` (la Task 7 y la Task 9 le agregan parámetros):

```dart
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import 'dobles.dart';
import 'entorno_prueba.dart';
import 'montar.dart';

/// `POST /auth/intercambio` de un chofer (id 2).
const intercambioChofer =
    '{"token":"2|x","usuario":{"id":2,"nombre":"Carlos G\\u00f3mez","cargo":"Chofer","rol":"chofer"}}';

/// Entorno HTTP de un chofer: sesión, configuración, sin viaje ni oferta, él mismo libre en el mapa y
/// con o sin turno abierto. Cada test agrega lo suyo.
EntornoPrueba entornoChofer({bool conTurno = true}) {
  final e = EntornoPrueba(tokenPJ: 'sim|200|Carlos Chofer|Chofer');
  e.http
    ..responder('POST', 'auth/intercambio', 200, intercambioChofer)
    ..responder('GET', 'configuracion', 200, p.configuracion)
    ..responder('GET', 'viajes/actual', 200, p.viajeActualVacio)
    ..responder('GET', 'choferes', 200, p.choferes)
    ..responder('GET', 'turnos/actual', 200, conTurno ? c.turnoActual : c.sinTurno)
    ..responder('POST', 'ubicacion', 204);
  return e;
}

/// Abre el módulo como chofer, con el GPS falso.
Future<void> montarChofer(
  WidgetTester tester,
  EntornoPrueba e, {
  TiempoRealFalso? tiempoReal,
  UbicadorFalso? ubicador,
  List<Override> extra = const [],
}) => montarModulo(
  tester,
  e,
  tiempoReal: tiempoReal,
  extra: [ubicadorProvider.overrideWithValue(ubicador ?? UbicadorFalso()), ...extra],
);

/// Rutas de los pedidos hechos, sin `/api/`, con su método (p. ej. `POST turnos`).
List<String> pedidosHechos(EntornoPrueba e) => [
  for (final r in e.http.pedidos) '${r.metodo} ${r.uri.path.replaceFirst('/api/', '')}',
];
```

- [ ] **Step 2: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/ui/iniciar_turno_test.dart`:

```dart
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

      expect(find.textContaining('No podemos obtener tu ubicación'), findsOneWidget);
      await tester.tap(find.text('Reintentar'));
      await esperar(tester);
      expect(gps.intervalos, hasLength(2));
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
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test test/ui/iniciar_turno_test.dart`
Expected: FAIL de compilación (no existen `iniciar_turno.dart` ni `mapa_chofer.dart`).

- [ ] **Step 4: Iniciar turno**

`paquete/vehiculos_oficiales/lib/src/ui/chofer/iniciar_turno.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/errores_api.dart';
import '../../chofer/turno.dart';
import '../../ubicacion/ubicador.dart';
import '../comunes/comunes.dart';
import 'inicio_chofer.dart';

/// Spec 7, chofer 1: elegir el vehículo e iniciar el turno. Es la pieza que después reemplazará la
/// asistencia: toda la lógica está en `TurnoNotifier`.
class IniciarTurno extends ConsumerStatefulWidget {
  const IniciarTurno({super.key});

  @override
  ConsumerState<IniciarTurno> createState() => _IniciarTurnoState();
}

class _IniciarTurnoState extends ConsumerState<IniciarTurno> {
  int? _elegido;
  bool _iniciando = false;

  /// Por qué no se pudo iniciar (permiso de ubicación), si pasó.
  PermisoUbicacion? _permiso;

  Future<void> _iniciar() async {
    setState(() {
      _iniciando = true;
      _permiso = null;
    });
    try {
      final permiso = await ref.read(turnoProvider.notifier).iniciar(_elegido!);
      // Con el turno abierto esta pantalla se reemplaza por el mapa.
      if (mounted && permiso != PermisoUbicacion.concedido) setState(() => _permiso = permiso);
    } on ErrorApi catch (e) {
      // P. ej. "El vehículo está en uso por otro chofer.": se avisa y se recarga la lista.
      if (!mounted) return;
      mostrarError(context, e);
      setState(() => _elegido = null);
      ref.invalidate(vehiculosDisponiblesProvider);
    } finally {
      if (mounted) setState(() => _iniciando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final vehiculos = ref.watch(vehiculosDisponiblesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Iniciar turno'), leading: const BotonCerrarModulo()),
      body: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (_permiso case final permiso?) _AvisoPermiso(permiso: permiso),
          const Padding(padding: EdgeInsets.fromLTRB(16, 16, 16, 8), child: Text('Elegí el vehículo de hoy:')),
          Expanded(
            child: switch (vehiculos) {
              AsyncData(:final value) => RefreshIndicator(
                onRefresh: () => ref.refresh(vehiculosDisponiblesProvider.future),
                child: value.isEmpty
                    ? ListView(
                        children: const [
                          ListTile(title: Text('No hay vehículos disponibles. Consultá con el administrador.')),
                        ],
                      )
                    : ListView(
                        children: [
                          for (final v in value)
                            ListTile(
                              leading: const Icon(Icons.directions_car),
                              title: Text(v.descripcion),
                              subtitle: v.color == null ? null : Text(v.color!),
                              selected: v.id == _elegido,
                              trailing: v.id == _elegido ? const Icon(Icons.check_circle) : null,
                              onTap: _iniciando ? null : () => setState(() => _elegido = v.id),
                            ),
                        ],
                      ),
              ),
              AsyncError(:final error) => Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(mensajeDeError(error)),
                    TextButton(
                      onPressed: () => ref.invalidate(vehiculosDisponiblesProvider),
                      child: const Text('Reintentar'),
                    ),
                  ],
                ),
              ),
              _ => const Center(child: CircularProgressIndicator()),
            },
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: FilledButton(
              onPressed: _elegido != null && !_iniciando ? _iniciar : null,
              child: const Text('Iniciar turno'),
            ),
          ),
        ],
      ),
    );
  }
}

/// Spec 9: sin permiso de ubicación no hay turno; se explica y se ofrece ir a los ajustes.
class _AvisoPermiso extends ConsumerWidget {
  const _AvisoPermiso({required this.permiso});

  final PermisoUbicacion permiso;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final gpsApagado = permiso == PermisoUbicacion.gpsApagado;
    return MaterialBanner(
      leading: const Icon(Icons.location_off),
      content: Text(
        gpsApagado
            ? 'La ubicación del teléfono está apagada. Activala para iniciar el turno.'
            : 'Para iniciar el turno necesitamos tu ubicación: mientras el turno esté abierto se comparte '
                  'con el sistema de vehículos oficiales. Permití el acceso a la ubicación en los ajustes.',
      ),
      actions: [
        TextButton(
          onPressed: () => ref.read(ubicadorProvider).abrirAjustes(permiso),
          child: const Text('Abrir ajustes'),
        ),
      ],
    );
  }
}
```

- [ ] **Step 5: Mapa del chofer**

`paquete/vehiculos_oficiales/lib/src/ui/chofer/mapa_chofer.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/errores_api.dart';
import '../../chofer/turno.dart';
import '../../entorno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../sesion/sesion.dart';
import '../../solicitante/choferes_mapa.dart';
import '../../ubicacion/ubicador.dart';
import '../comunes/comunes.dart';
import 'inicio_chofer.dart';

/// Spec 7, chofer 2 y 6: su posición, su estado, el vehículo del turno y "Finalizar turno".
class MapaChofer extends ConsumerWidget {
  const MapaChofer({super.key, required this.turno});

  final Turno turno;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final posicion = ref.watch(posicionPropiaProvider);
    final usuario = ref.watch(usuarioProvider);
    // El estado lo calcula el backend (spec 4.1) y llega en el mismo listado que ve el solicitante.
    final yo = ref.watch(choferesMapaProvider).value?.where((c) => c.id == usuario.id).firstOrNull;
    final mapa = ref.watch(constructorMapaProvider);
    final config = ref.watch(entornoProvider).config;
    final aqui = posicion.punto?.posicion;
    final texto = Theme.of(context).textTheme;

    return Scaffold(
      appBar: AppBar(title: const Text('Vehículos oficiales'), leading: const BotonCerrarModulo()),
      body: Column(
        children: [
          const BannerConexion(),
          if (posicion.sinGps)
            MaterialBanner(
              leading: const Icon(Icons.gps_off),
              content: const Text(
                'No podemos obtener tu ubicación. Revisá que la ubicación del teléfono esté activa y el permiso '
                'concedido.',
              ),
              actions: [
                TextButton(
                  onPressed: () => ref.read(ubicadorProvider).abrirAjustes(PermisoUbicacion.denegado),
                  child: const Text('Abrir ajustes'),
                ),
                TextButton(
                  onPressed: () => ref.read(turnoProvider.notifier).reintentarGps(),
                  child: const Text('Reintentar'),
                ),
              ],
            ),
          Expanded(
            child: mapa(
              context,
              DatosMapa(
                centro: aqui ?? Coordenada(config.centroMapaLat, config.centroMapaLng),
                marcadores: [
                  if (aqui != null)
                    MarcadorMapa(id: 'yo', posicion: aqui, tipo: TipoMarcador.choferAsignado, titulo: 'Vos'),
                ],
              ),
            ),
          ),
          Material(
            elevation: 8,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(yo?.estado.texto ?? 'En turno', style: texto.titleLarge),
                  if (turno.vehiculo case final v?) Text([v.descripcion, ?v.color].join(' · ')),
                  if (aqui == null && !posicion.sinGps) const Text('Buscando tu ubicación…'),
                  const SizedBox(height: 16),
                  OutlinedButton(onPressed: () => _finalizar(context, ref), child: const Text('Finalizar turno')),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _finalizar(BuildContext context, WidgetRef ref) async {
    final confirma = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('¿Finalizar el turno?'),
        content: const Text('Se deja de compartir tu ubicación y no vas a recibir viajes.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, finalizar')),
        ],
      ),
    );
    if (confirma != true || !context.mounted) return;
    try {
      await ref.read(turnoProvider.notifier).finalizar();
    } on ErrorApi catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }
}
```

- [ ] **Step 6: Entrada del chofer**

`paquete/vehiculos_oficiales/lib/src/ui/chofer/inicio_chofer.dart` (reemplaza la provisoria):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../chofer/turno.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import 'iniciar_turno.dart';
import 'mapa_chofer.dart';

/// Entrada del chofer: sin turno, "Iniciar turno"; con turno, su mapa.
class InicioChofer extends ConsumerWidget {
  const InicioChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final turno = ref.watch(turnoProvider);

    if (turno.hasValue) {
      final t = turno.value;
      if (t == null) return const IniciarTurno();
      // "Atrás" en el mapa cerraría el módulo sin preguntar: pasa por la misma confirmación que la X.
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (cerro, _) {
          if (!cerro) cerrarModuloChofer(context, ref);
        },
        child: MapaChofer(turno: t),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Vehículos oficiales'), leading: const BotonCerrarModulo()),
      body: Center(
        child: turno.hasError
            ? Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(mensajeDeError(turno.error!)),
                  TextButton(onPressed: () => ref.invalidate(turnoProvider), child: const Text('Reintentar')),
                ],
              )
            : const CircularProgressIndicator(),
      ),
    );
  }
}

/// Cierra el módulo. Con el turno abierto pregunta antes: el GPS vive con el módulo y se corta al cerrarlo.
Future<void> cerrarModuloChofer(BuildContext context, WidgetRef ref) async {
  final cerrar = ref.read(cerrarModuloProvider);
  if (ref.read(turnoProvider).value == null) return cerrar();
  final confirma = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Tu turno sigue abierto'),
      content: const Text(
        'Si cerrás Vehículos oficiales dejás de compartir tu ubicación hasta que lo vuelvas a abrir. '
        'Para terminar el día usá "Finalizar turno".',
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Seguir acá')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Cerrar igual')),
      ],
    ),
  );
  if (confirma == true) cerrar();
}

class BotonCerrarModulo extends ConsumerWidget {
  const BotonCerrarModulo({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) =>
      IconButton(icon: const Icon(Icons.close), tooltip: 'Cerrar', onPressed: () => cerrarModuloChofer(context, ref));
}
```

- [ ] **Step 7: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **178 PASS**, sin problemas, `0 changed`. El test del plan anterior "un chofer entra a la pantalla del chofer" (`modulo_test.dart`) sigue en verde: sin respuesta preparada para `turnos/actual`, `InicioChofer` muestra el error con "Reintentar".

- [ ] **Step 8: Commit**

```bash
git add paquete
git commit -m "feat: iniciar turno y mapa del chofer" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Viaje en curso del chofer

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/chofer/pasos_viaje.dart`, `lib/src/ui/chofer/viaje_chofer.dart`
- Modify: `lib/src/viaje/viaje_actual.dart` (`avanzar`, `_version`, `_atrasado`), `lib/src/ui/modulo_app.dart` (ruta `/chofer/viaje`), `lib/src/ui/chofer/inicio_chofer.dart` (paso automático al viaje), `lib/src/ui/chofer/mapa_chofer.dart` (aviso "Tenés un viaje en curso."), `lib/src/ui/comunes/comunes.dart` + `lib/src/ui/solicitante/pantalla_viaje.dart` (mover `telefonoMarcable`, C16), `test/soporte/dobles_chofer.dart` (`demoraActual`), `test/soporte/montar_chofer.dart` (`viajeActual`)
- Test: `test/ui/viaje_chofer_test.dart`, `test/viaje_actual_chofer_test.dart`

**Interfaces:**
- Consumes: `viajeActualProvider` (`cancelar`, `descartar`, `refrescar`), `usuarioProvider`, `posicionPropiaProvider` (Task 5), `lanzadorUrlProvider`, `constructorMapaProvider`, `BannerConexion`, `mostrarError`, `formatearFechaHora`.
- Produces:
  - En `ViajeActualNotifier`: `Future<void> avanzar(EstadoViaje hacia)` (con la respuesta actualiza el estado; los errores llegan a la pantalla); `_fijar` + `_version` y `_atrasado` (C9).
  - `extension ViajeDelChofer on Viaje { EstadoViaje? get siguientePaso; bool get cancelablePorChofer; Lugar get haciaDonde; }`, `textoPaso(EstadoViaje)`, `estadoParaChofer(EstadoViaje)`, `urlsGoogleMaps(Coordenada)`, `urlWaze(Coordenada)`.
  - `ViajeChofer` (`/chofer/viaje`, `Rutas.viajeChofer`), decisión 8.
  - `telefonoMarcable` ahora en `ui/comunes/comunes.dart`.

- [ ] **Step 1: Soporte de tests**

En `paquete/vehiculos_oficiales/test/soporte/dobles_chofer.dart`, dentro de `ApiChofer`, antes de `Viaje? respuestaAceptar;`:

```dart
  /// Si no es nulo, `viajeActual` espera a que el test lo complete (una consulta que tarda).
  Completer<void>? demoraActual;
```

y antes del `@override` de `configuracion()`:

```dart
  @override
  Future<ViajeActual> viajeActual() async {
    final consulta = super.viajeActual(); // lee `actual` al empezar, como el servidor
    if (demoraActual != null) await demoraActual!.future;
    return consulta;
  }
```

`paquete/vehiculos_oficiales/test/soporte/montar_chofer.dart` queda así (`viajeActual` como parámetro, C17):

```dart
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import 'dobles.dart';
import 'entorno_prueba.dart';
import 'montar.dart';

/// `POST /auth/intercambio` de un chofer (id 2).
const intercambioChofer =
    '{"token":"2|x","usuario":{"id":2,"nombre":"Carlos G\\u00f3mez","cargo":"Chofer","rol":"chofer"}}';

/// Entorno HTTP de un chofer: sesión, configuración, [viajeActual] (por defecto sin viaje ni oferta), él
/// mismo libre en el mapa y con o sin turno abierto. Cada test agrega lo suyo.
EntornoPrueba entornoChofer({bool conTurno = true, String viajeActual = p.viajeActualVacio}) {
  final e = EntornoPrueba(tokenPJ: 'sim|200|Carlos Chofer|Chofer');
  e.http
    ..responder('POST', 'auth/intercambio', 200, intercambioChofer)
    ..responder('GET', 'configuracion', 200, p.configuracion)
    ..responder('GET', 'viajes/actual', 200, viajeActual)
    ..responder('GET', 'choferes', 200, p.choferes)
    ..responder('GET', 'turnos/actual', 200, conTurno ? c.turnoActual : c.sinTurno)
    ..responder('POST', 'ubicacion', 204);
  return e;
}

/// Abre el módulo como chofer, con el GPS falso.
Future<void> montarChofer(
  WidgetTester tester,
  EntornoPrueba e, {
  TiempoRealFalso? tiempoReal,
  UbicadorFalso? ubicador,
  List<Override> extra = const [],
}) => montarModulo(
  tester,
  e,
  tiempoReal: tiempoReal,
  extra: [ubicadorProvider.overrideWithValue(ubicador ?? UbicadorFalso()), ...extra],
);

/// Rutas de los pedidos hechos, sin `/api/`, con su método (p. ej. `POST turnos`).
List<String> pedidosHechos(EntornoPrueba e) => [
  for (final r in e.http.pedidos) '${r.metodo} ${r.uri.path.replaceFirst('/api/', '')}',
];
```

- [ ] **Step 2: Escribir los tests que fallan**

`paquete/vehiculos_oficiales/test/viaje_actual_chofer_test.dart`:

```dart
import 'dart:async';

import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'soporte/dobles.dart';
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  late ApiChofer api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiChofer();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(chofer),
    ]);
    c.listen(viajeActualProvider, (_, _) {});
    return c;
  }

  SeguimientoViaje leer(ProviderContainer c) => c.read(viajeActualProvider).requireValue;

  group('avanzar', () {
    test('manda el paso y aplica la respuesta', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.enCamino);
        async.flushMicrotasks();

        expect(api.avances.single, (1, EstadoViaje.enCamino));
        expect(leer(c).viaje!.estado, EstadoViaje.enCamino);
      });
    });

    test('una respuesta que llega después de un "cancelado" no lo pisa', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'cancelado', conChofer: true)));
        c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.llego);
        async.flushMicrotasks();

        expect(leer(c).viaje!.estado, EstadoViaje.cancelado);
      });
    });

    test('cancelar como chofer: el viaje vuelve sin chofer (no cuenta como retroceso)', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        c.read(viajeActualProvider.notifier).cancelar(motivo: 'Se rompió el auto');
        async.flushMicrotasks();

        expect(api.cancelaciones.single, (1, 'Se rompió el auto'));
        expect(leer(c).viaje!.chofer, isNull);
        expect(leer(c).viaje!.estado, EstadoViaje.buscando);
      });
    });
  });

  test('una consulta del respaldo que empezó antes de una novedad no la pisa', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      final c = crear();
      async.flushMicrotasks();

      api.demoraActual = Completer<void>();
      async.elapse(const Duration(seconds: 10)); // empieza la consulta, que ve "aceptado"
      c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.enCamino);
      async.flushMicrotasks();
      api.demoraActual!.complete();
      async.flushMicrotasks();

      expect(leer(c).viaje!.estado, EstadoViaje.enCamino);

      api
        ..demoraActual = null
        ..actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
      async.elapse(const Duration(seconds: 10)); // la siguiente sí se aplica
      expect(leer(c).viaje!.estado, EstadoViaje.llego);
    });
  });
}
```

`paquete/vehiculos_oficiales/test/ui/viaje_chofer_test.dart`:

```dart
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/mapa_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

/// El viaje del fixture (chofer 2 = el usuario) con otros datos.
String viajeJson({
  String estado = 'aceptado',
  bool obligatorio = false,
  String tipo = 'inmediato',
  String? telefonoSolicitante = '3815551111',
}) {
  final j = p.json(p.viajeAceptado)
    ..['estado'] = estado
    ..['obligatorio'] = obligatorio
    ..['tipo'] = tipo;
  (j['solicitante'] as Map<String, dynamic>)['telefono'] = telefonoSolicitante;
  return jsonEncode(j);
}

String actualCon(String viaje) => '{"viaje":$viaje,"oferta":null}';

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;
  late List<Uri> lanzadas;
  late bool abreGoogleMaps;

  setUp(() {
    tr = TiempoRealFalso();
    lanzadas = [];
    abreGoogleMaps = true;
  });

  /// [preparar] agrega respuestas antes de abrir.
  Future<void> abrir(WidgetTester tester, String viaje, [void Function(EntornoPrueba e)? preparar]) async {
    e = entornoChofer(viajeActual: actualCon(viaje));
    preparar?.call(e);
    await montarChofer(
      tester,
      e,
      tiempoReal: tr,
      extra: [
        lanzadorUrlProvider.overrideWithValue((uri) async {
          lanzadas.add(uri);
          return uri.scheme != 'google.navigation' || abreGoogleMaps;
        }),
      ],
    );
  }

  Map<String, dynamic> cuerpo(String ruta) =>
      jsonDecode(e.http.pedidos.lastWhere((r) => r.uri.path == '/api/$ruta').cuerpo) as Map<String, dynamic>;

  testWidgets('al abrir con un viaje va a su pantalla y recorre los pasos hasta "Viaje finalizado"', (tester) async {
    await abrir(tester, viajeJson(), (e) {
      for (final estado in ['en_camino', 'llego', 'en_curso', 'finalizado']) {
        e.http.responder('POST', 'viajes/1/estado', 200, viajeJson(estado: estado));
      }
    });
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Ana Pérez'), findsOneWidget);

    final pasos = {
      'Voy en camino': 'en_camino',
      'Llegué': 'llego',
      'Iniciar viaje': 'en_curso',
      'Finalizar': 'finalizado',
    };
    for (final MapEntry(key: boton, value: estado) in pasos.entries) {
      await tester.tap(find.widgetWithText(FilledButton, boton));
      await esperar(tester);
      expect(cuerpo('viajes/1/estado'), {'estado': estado});
    }

    expect(find.text('Viaje finalizado'), findsWidgets);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('navegar: al origen antes de en_curso y al destino después; Waze; y la web si falta la app', (
    tester,
  ) async {
    await abrir(tester, viajeJson(estado: 'en_camino'));

    await tester.tap(find.text('Navegar'));
    await esperar(tester);
    await tester.tap(find.text('Google Maps'));
    await esperar(tester);
    expect(lanzadas.last.toString(), 'google.navigation:q=-26.8241,-65.2226');

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_curso')));
    await esperar(tester);
    await tester.tap(find.text('Navegar'));
    await esperar(tester);
    await tester.tap(find.text('Waze'));
    await esperar(tester);
    expect(lanzadas.last.toString(), 'https://waze.com/ul?ll=-26.8083,-65.2176&navigate=yes');

    abreGoogleMaps = false;
    await tester.tap(find.text('Navegar'));
    await esperar(tester);
    await tester.tap(find.text('Google Maps'));
    await esperar(tester);
    expect(lanzadas.sublist(lanzadas.length - 2).map((u) => u.toString()), [
      'google.navigation:q=-26.8083,-65.2176',
      'https://www.google.com/maps/dir/?api=1&destination=-26.8083,-65.2176',
    ]);
  });

  testWidgets('llamar al solicitante; sin teléfono no hay botón', (tester) async {
    await abrir(tester, viajeJson());

    await tester.tap(find.text('Llamar'));
    await esperar(tester);
    expect(lanzadas.single.toString(), 'tel:3815551111');

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(viajeJson(telefonoSolicitante: null)));
    await esperar(tester);
    expect(find.text('Llamar'), findsNothing);
  });

  testWidgets('cancelar pide un motivo, no deja confirmar vacío, lo manda y vuelve al mapa', (tester) async {
    await abrir(
      tester,
      viajeJson(),
      (e) => e.http.responder('POST', 'viajes/1/cancelar', 200, c.viajeCanceladoPorChofer),
    );
    await tester.tap(find.text('Cancelar viaje'));
    await esperar(tester);

    final confirmar = find.widgetWithText(FilledButton, 'Cancelar viaje');
    expect(tester.widget<FilledButton>(confirmar).onPressed, isNull);
    await tester.enterText(find.byType(TextField), '  Se rompió el auto  ');
    await tester.pump();
    await tester.tap(confirmar);
    await esperar(tester);

    expect(cuerpo('viajes/1/cancelar'), {'motivo': 'Se rompió el auto'});
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(find.text('Cancelaste el viaje.'), findsOneWidget);
  });

  testWidgets('un obligatorio no se puede cancelar', (tester) async {
    await abrir(tester, viajeJson(obligatorio: true));

    expect(find.text('Viaje obligatorio'), findsOneWidget);
    expect(find.text('Cancelar viaje'), findsNothing);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('en curso tampoco; una reserva que ya salió tampoco', (tester) async {
    await abrir(tester, viajeJson(estado: 'en_curso'));
    expect(find.text('Cancelar viaje'), findsNothing);

    // Otro viaje (id 5): una reserva que arrancó.
    tr.emitir(
      'chofer.2',
      Eventos.viajeActualizado,
      p.json(viajeJson(estado: 'en_camino', tipo: 'reserva'))..['id'] = 5,
    );
    await esperar(tester);
    expect(find.text('Llegué'), findsOneWidget);
    expect(find.text('Cancelar viaje'), findsNothing);
  });

  testWidgets('el 422 de una reserva que todavía no puede empezar se muestra', (tester) async {
    await abrir(
      tester,
      viajeJson(tipo: 'reserva'),
      (e) => e.http.responder('POST', 'viajes/1/estado', 422, c.reservaAntesDeTiempo),
    );
    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(find.text('Podés salir hacia esta reserva a partir de las 11:15.'), findsOneWidget);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('cancelado por el solicitante o reasignado: lo avisa y vuelve al mapa', (tester) async {
    await abrir(tester, viajeJson());

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(viajeJson(estado: 'cancelado')));
    await esperar(tester);
    expect(find.text('El viaje fue cancelado'), findsOneWidget);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('reasignado por un administrador', (tester) async {
    await abrir(tester, viajeJson());

    final reasignado = p.json(viajeJson())..['chofer'] = {'id': 9, 'nombre': 'Otro', 'telefono': null};
    tr.emitir('chofer.2', Eventos.viajeActualizado, reasignado);
    await esperar(tester);

    expect(find.text('El viaje se reasignó a otro chofer'), findsOneWidget);
  });

  testWidgets('"atrás" vuelve al mapa, que ofrece volver al viaje', (tester) async {
    await abrir(tester, viajeJson());

    await tester.binding.handlePopRoute();
    await esperar(tester);
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(find.text('Tenés un viaje en curso.'), findsOneWidget);

    await tester.tap(find.text('Ver'));
    await esperar(tester);
    expect(find.byType(ViajeChofer), findsOneWidget);
  });
}
```

- [ ] **Step 3: Correr y ver que fallan**

Run: `flutter test test/viaje_actual_chofer_test.dart test/ui/viaje_chofer_test.dart`
Expected: FAIL de compilación (no existen `avanzar`, `viaje_chofer.dart` ni `Rutas.viajeChofer`).

- [ ] **Step 4: Reglas del viaje para el chofer**

`paquete/vehiculos_oficiales/lib/src/chofer/pasos_viaje.dart`:

```dart
import '../modelos/modelos.dart';

/// Reglas del viaje vistas desde el chofer (spec 5.5 y 5.6).
extension ViajeDelChofer on Viaje {
  /// El paso que marca el botón principal, o nulo si ya no le queda ninguno.
  EstadoViaje? get siguientePaso => switch (estado) {
    EstadoViaje.aceptado => EstadoViaje.enCamino,
    EstadoViaje.enCamino => EstadoViaje.llego,
    EstadoViaje.llego => EstadoViaje.enCurso,
    EstadoViaje.enCurso => EstadoViaje.finalizado,
    _ => null,
  };

  /// Mismas reglas que `ServicioViaje::cancelarPorChofer`: nunca un obligatorio; un inmediato hasta que
  /// empieza (`llego`); una reserva solo antes de salir (`aceptado`).
  bool get cancelablePorChofer =>
      !obligatorio &&
      (estado == EstadoViaje.aceptado ||
          (tipo == TipoViaje.inmediato && (estado == EstadoViaje.enCamino || estado == EstadoViaje.llego)));

  /// A dónde navegar: al origen hasta que sube el pasajero, después al destino.
  Lugar get haciaDonde => estado == EstadoViaje.enCurso ? destino : origen;
}

/// Texto del botón principal para cada paso.
String textoPaso(EstadoViaje paso) => switch (paso) {
  EstadoViaje.enCamino => 'Voy en camino',
  EstadoViaje.llego => 'Llegué',
  EstadoViaje.enCurso => 'Iniciar viaje',
  EstadoViaje.finalizado => 'Finalizar',
  _ => paso.texto,
};

/// El estado contado para el chofer (`EstadoViaje.texto` está escrito para el solicitante).
String estadoParaChofer(EstadoViaje e) => switch (e) {
  EstadoViaje.aceptado => 'Viaje aceptado',
  EstadoViaje.enCamino => 'En camino al origen',
  EstadoViaje.llego => 'Esperando al pasajero',
  EstadoViaje.enCurso => 'En viaje al destino',
  _ => e.texto,
};

/// Navegación externa (spec 5.5): Google Maps (la app en Android; si no se puede, la web, que en iOS abre la app si está)
/// y Waze, hacia [c].
List<Uri> urlsGoogleMaps(Coordenada c) => [
  Uri.parse('google.navigation:q=${c.lat},${c.lng}'),
  Uri.parse('https://www.google.com/maps/dir/?api=1&destination=${c.lat},${c.lng}'),
];

Uri urlWaze(Coordenada c) => Uri.parse('https://waze.com/ul?ll=${c.lat},${c.lng}&navigate=yes');
```

- [ ] **Step 5: `avanzar` y nada viejo pisa algo nuevo**

`paquete/vehiculos_oficiales/lib/src/viaje/viaje_actual.dart` queda así (cambia: `_version` y su uso en `refrescar`; `pedir`, `descartar`, la oferta del evento y `_aplicarViaje` pasan por `_fijar`; `cancelar` controla `ref.mounted`; `avanzar`, `_avance` y `_atrasado` son nuevos):

```dart
import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../tiempo_real/respaldo.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';

/// Lo que se muestra del viaje en curso: el viaje (puede quedar en un estado final hasta que el usuario
/// lo descarte), la oferta pendiente del chofer y la última posición conocida del chofer asignado.
class SeguimientoViaje {
  const SeguimientoViaje({this.viaje, this.oferta, this.ubicacionChofer});

  final Viaje? viaje;
  final Oferta? oferta;
  final UbicacionChofer? ubicacionChofer;

  SeguimientoViaje conViaje(Viaje? v) => SeguimientoViaje(
    viaje: v,
    oferta: oferta,
    ubicacionChofer: v?.chofer?.id == viaje?.chofer?.id ? ubicacionChofer : null,
  );

  SeguimientoViaje conOferta(Oferta? o) => SeguimientoViaje(viaje: viaje, oferta: o, ubicacionChofer: ubicacionChofer);

  SeguimientoViaje conUbicacion(UbicacionChofer? u) =>
      SeguimientoViaje(viaje: viaje, oferta: oferta, ubicacionChofer: u);
}

final viajeActualProvider = AsyncNotifierProvider<ViajeActualNotifier, SeguimientoViaje>(ViajeActualNotifier.new);

/// Viaje actual sincronizado (spec 6): eventos de Reverb mientras el socket está conectado; con el socket
/// caído, `GET /viajes/actual` cada 10 s; al reconectar, estado completo otra vez.
class ViajeActualNotifier extends AsyncNotifier<SeguimientoViaje> {
  StreamSubscription<EventoTiempoReal>? _canalViaje;
  int? _canalViajeId;
  bool _consultando = false;

  /// Cambia con cada novedad del viaje o de la oferta que no vino de [refrescar] (respuesta de una acción,
  /// evento del socket). Una consulta que empezó antes y termina después no pisa esa novedad.
  int _version = 0;

  late Usuario _usuario;
  late TiempoReal _tr;

  @override
  Future<SeguimientoViaje> build() async {
    _usuario = ref.watch(usuarioProvider);
    _tr = ref.watch(tiempoRealProvider);

    final canalChofer = _usuario.esChofer ? _tr.canal(Canales.chofer(_usuario.id)).listen(_alEvento) : null;
    final respaldo = Respaldo(tiempoReal: _tr, intervalo: ref.read(intervaloRespaldoProvider), refrescar: refrescar);
    ref.onDispose(() {
      respaldo.cerrar();
      unawaited(canalChofer?.cancel());
      unawaited(_canalViaje?.cancel());
    });

    final inicial = await _consultar(null);
    _seguir(inicial.viaje);
    return inicial;
  }

  /// Consulta la API y reemplaza el estado. La usan el respaldo, la reconexión y los avisos push.
  Future<void> refrescar() async {
    if (_consultando) return;
    _consultando = true;
    final version = _version;
    try {
      final nuevo = await _consultar(state.value?.viaje);
      if (!ref.mounted || version != _version) return;
      state = AsyncData(nuevo);
      _seguir(nuevo.viaje);
    } on SesionInvalida {
      // `ClienteApi` ya avisó la sesión inválida (una sola vez); acá no hay nada más que hacer y este
      // método corre sin await desde el timer y el listener, así que no puede propagar el error.
    } on ErrorApi {
      // Sin red o error pasajero: se conserva lo último que se sabía y se reintenta en el próximo ciclo.
    } finally {
      _consultando = false;
    }
  }

  /// Pedido inmediato (spec 5.2 y 5.3). Los errores (422, etc.) llegan a la pantalla.
  Future<Viaje> pedir(PedidoViaje pedido) async {
    final v = await ref.read(apiProvider).pedirViaje(pedido);
    _fijar(SeguimientoViaje(viaje: v));
    _seguir(v);
    return v;
  }

  Future<void> cancelar({String? motivo}) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).cancelarViaje(actual.id, motivo: motivo);
    if (ref.mounted) _aplicarViaje(v);
  }

  /// Paso siguiente del chofer (spec 5.5): `en_camino`, `llego`, `en_curso` o `finalizado`. Los errores
  /// (422 de una reserva que todavía no puede empezar, 403 si el viaje ya no es suyo) llegan a la pantalla.
  Future<void> avanzar(EstadoViaje hacia) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).avanzarViaje(actual.id, hacia);
    if (ref.mounted) _aplicarViaje(v);
  }

  /// El usuario ya vio el estado final (finalizado, cancelado, sin chofer): se vuelve al mapa.
  void descartar() {
    _fijar(const SeguimientoViaje());
    _seguir(null);
  }

  Future<SeguimientoViaje> _consultar(Viaje? previo) async {
    final api = ref.read(apiProvider);
    final actual = await api.viajeActual();
    var viaje = actual.viaje;

    // `viajes/actual` no devuelve viajes terminados: si el que se seguía desapareció mientras no había
    // socket, se busca en el historial para mostrar cómo terminó (p. ej. sin_chofer).
    if (viaje == null && previo != null && !previo.estado.terminado && !_usuario.esChofer) {
      final historial = (await api.misViajes()).historial;
      viaje = historial.where((v) => v.id == previo.id).firstOrNull;
    }
    // Un viaje ya terminado se queda en pantalla hasta que el usuario lo descarte.
    if (viaje == null && previo != null && previo.estado.terminado) viaje = previo;

    UbicacionChofer? ubicacion = viaje?.chofer?.id == state.value?.viaje?.chofer?.id
        ? state.value?.ubicacionChofer
        : null;
    final choferId = viaje?.chofer?.id;
    if (choferId != null && !_usuario.esChofer && _tr.estado != EstadoConexion.conectado) {
      // Sin socket tampoco llegan las posiciones: se toman de GET /choferes. Es un extra: si falla, se
      // conserva la última posición conocida y el viaje igual se actualiza.
      try {
        final c = (await api.choferes()).where((c) => c.id == choferId).firstOrNull;
        if (c?.posicion != null) {
          ubicacion = UbicacionChofer(
            choferId: c!.id,
            posicion: c.posicion!,
            rumbo: c.rumbo,
            actualizadoEn: c.actualizadoEn ?? DateTime.now().toUtc(),
          );
        }
      } on ErrorApi {
        // Incluye un 401: `ClienteApi` ya avisó la sesión inválida.
      }
    }
    return SeguimientoViaje(viaje: viaje, oferta: actual.oferta, ubicacionChofer: ubicacion);
  }

  /// Un evento que no se puede leer (p. ej. un estado nuevo del backend) se ignora: el respaldo o el
  /// próximo evento traen el estado.
  void _alEvento(EventoTiempoReal e) {
    try {
      _aplicarEvento(e);
    } catch (error) {
      if (!esErrorDeLectura(error)) rethrow;
      debugPrint('vehiculos_oficiales: evento ${e.nombre} ignorado, no se pudo leer: $error');
    }
  }

  void _aplicarEvento(EventoTiempoReal e) {
    final actual = state.value;
    if (actual == null) return;

    switch (e.nombre) {
      case Eventos.viajeActualizado:
        _aplicarViaje(Viaje.fromJson(e.datos));
      case Eventos.ofertaCreada:
        final oferta = Oferta.fromJson(e.datos);
        // Las solicitudes de reserva van a la agenda, no a la pantalla de oferta (AvisosViaje / ViajeController::actual).
        if (oferta.viaje.tipo == TipoViaje.inmediato) _fijar(actual.conOferta(oferta));
      case Eventos.choferUbicacion:
        final u = UbicacionChofer.fromJson(e.datos);
        if (actual.viaje?.chofer?.id == u.choferId) state = AsyncData(actual.conUbicacion(u));
    }
  }

  void _aplicarViaje(Viaje v) {
    final actual = state.value ?? const SeguimientoViaje();
    if (_atrasado(actual.viaje, v)) return;
    var nuevo = actual;

    if (_usuario.esChofer) {
      // La oferta ya se respondió, venció o se la llevó otro.
      if (actual.oferta?.viaje.id == v.id && v.estado != EstadoViaje.ofrecido) nuevo = nuevo.conOferta(null);
      // Mismo viaje (incluso si se lo reasignaron a otro o lo cancelaron: el chofer lo ve y lo descarta)
      // o uno nuevo que lo ocupa ahora (obligatorio asignado, reserva que arrancó).
      final ocupaAhora =
          v.chofer?.id == _usuario.id &&
          !v.estado.terminado &&
          (v.tipo == TipoViaje.inmediato || v.estado != EstadoViaje.aceptado);
      if (actual.viaje?.id == v.id || ocupaAhora) nuevo = nuevo.conViaje(v);
    } else if (actual.viaje == null || actual.viaje!.id == v.id) {
      nuevo = nuevo.conViaje(v);
    }

    _fijar(nuevo);
    _seguir(nuevo.viaje);
  }

  void _fijar(SeguimientoViaje s) {
    _version++;
    state = AsyncData(s);
  }

  /// Cuánto avanzó un viaje con chofer (0 = todavía sin chofer).
  static int _avance(EstadoViaje e) => switch (e) {
    EstadoViaje.buscando || EstadoViaje.ofrecido => 0,
    EstadoViaje.aceptado => 1,
    EstadoViaje.enCamino => 2,
    EstadoViaje.llego => 3,
    EstadoViaje.enCurso => 4,
    EstadoViaje.finalizado || EstadoViaje.cancelado || EstadoViaje.sinChofer => 5,
  };

  /// Una respuesta o un evento que llega tarde (p. ej. la respuesta de "Iniciar viaje" después del evento
  /// "cancelado") no hace retroceder el mismo viaje con el mismo chofer. Con otro chofer (reasignado,
  /// cancelado por el chofer) sí se aplica.
  static bool _atrasado(Viaje? actual, Viaje v) =>
      actual != null &&
      actual.id == v.id &&
      actual.chofer?.id == v.chofer?.id &&
      _avance(v.estado) > 0 &&
      _avance(v.estado) < _avance(actual.estado);

  /// El solicitante escucha `viaje.{id}` (estado y posición del chofer) mientras el viaje no terminó.
  /// El chofer ya recibe `viaje.actualizado` por `chofer.{id}`.
  void _seguir(Viaje? viaje) {
    final id = viaje != null && !viaje.estado.terminado && !_usuario.esChofer ? viaje.id : null;
    if (id == _canalViajeId) return;
    unawaited(_canalViaje?.cancel());
    _canalViaje = null;
    _canalViajeId = id;
    if (id != null) _canalViaje = _tr.canal(Canales.viaje(id)).listen(_alEvento);
  }
}
```

- [ ] **Step 6: `telefonoMarcable` a comunes**

En `paquete/vehiculos_oficiales/lib/src/ui/solicitante/pantalla_viaje.dart`, borrar la función `telefonoMarcable` (con su comentario). En `lib/src/ui/comunes/comunes.dart`, agregarla después de `lanzadorUrlProvider` (`pantalla_viaje.dart` ya importa `comunes.dart`):

```dart
/// Teléfono para `tel:`: solo dígitos y un `+` inicial (llega como texto libre, p. ej. "+54 (381) 555-0000").
/// Nulo si no queda ningún dígito.
String? telefonoMarcable(String? telefono) {
  if (telefono == null) return null;
  final digitos = telefono.replaceAll(RegExp(r'\D'), '');
  if (digitos.isEmpty) return null;
  return telefono.trimLeft().startsWith('+') ? '+$digitos' : digitos;
}
```

- [ ] **Step 7: Pantalla del viaje**

`paquete/vehiculos_oficiales/lib/src/ui/chofer/viaje_chofer.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../chofer/pasos_viaje.dart';
import '../../chofer/turno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../sesion/sesion.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Spec 7, chofer 4: viaje en curso paso a paso, navegación externa, llamar y cancelar (spec 5.5 y 5.6).
class ViajeChofer extends ConsumerWidget {
  const ViajeChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final seguimiento = ref.watch(viajeActualProvider);
    final viaje = seguimiento.value?.viaje;
    final usuario = ref.watch(usuarioProvider);

    if (seguimiento.hasValue && viaje == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (context.mounted) context.go(Rutas.chofer);
      });
    }

    final Widget contenido;
    if (viaje == null) {
      contenido = const Center(child: CircularProgressIndicator());
    } else if (viaje.estado == EstadoViaje.cancelado) {
      contenido = const _Fin(icono: Icons.cancel, texto: 'El viaje fue cancelado');
    } else if (viaje.chofer?.id != usuario.id) {
      // Lo reasignó un administrador (o volvió a buscar chofer): ya no es suyo.
      contenido = const _Fin(icono: Icons.swap_horiz, texto: 'El viaje se reasignó a otro chofer');
    } else if (viaje.estado == EstadoViaje.finalizado) {
      contenido = const _Fin(icono: Icons.check_circle, texto: 'Viaje finalizado');
    } else {
      contenido = _EnCurso(viaje: viaje);
    }

    return Scaffold(
      appBar: AppBar(title: Text(viaje == null ? 'Viaje' : estadoParaChofer(viaje.estado))),
      body: Column(
        children: [
          const BannerConexion(),
          Expanded(child: contenido),
        ],
      ),
    );
  }
}

class _EnCurso extends ConsumerStatefulWidget {
  const _EnCurso({required this.viaje});

  final Viaje viaje;

  @override
  ConsumerState<_EnCurso> createState() => _EnCursoState();
}

class _EnCursoState extends ConsumerState<_EnCurso> {
  bool _enviando = false;

  Future<void> _accion(Future<void> Function() accion) async {
    setState(() => _enviando = true);
    try {
      await accion();
    } on ErrorApi catch (e) {
      // P. ej. "Podés salir hacia esta reserva a partir de las 11:15." o "Este viaje no es tuyo.".
      if (!mounted) return;
      mostrarError(context, e);
      if (e is AccesoDenegado) await ref.read(viajeActualProvider.notifier).refrescar();
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  Future<void> _navegar() async {
    final c = widget.viaje.haciaDonde.coordenada;
    final urls = await showModalBottomSheet<List<Uri>>(
      context: context,
      builder: (hoja) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.map),
              title: const Text('Google Maps'),
              onTap: () => Navigator.pop(hoja, urlsGoogleMaps(c)),
            ),
            ListTile(
              leading: const Icon(Icons.navigation),
              title: const Text('Waze'),
              onTap: () => Navigator.pop(hoja, [urlWaze(c)]),
            ),
          ],
        ),
      ),
    );
    if (urls != null && mounted) await _abrir(urls, 'No se pudo abrir la navegación.');
  }

  /// Prueba las URLs en orden hasta que una abra. Un error de la plataforma (sin la app, sin manejador
  /// del esquema) cuenta como "no abrió" y nunca se escapa.
  Future<void> _abrir(List<Uri> urls, String siFalla) async {
    final lanzar = ref.read(lanzadorUrlProvider);
    for (final u in urls) {
      try {
        if (await lanzar(u)) return;
      } catch (_) {
        // Se prueba la siguiente.
      }
    }
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(siFalla)));
  }

  Future<void> _cancelar() async {
    final motivo = await showDialog<String>(context: context, builder: (_) => const _DialogoCancelar());
    if (motivo == null || !mounted) return;
    final notifier = ref.read(viajeActualProvider.notifier);
    final mensajero = ScaffoldMessenger.of(context);
    await _accion(() async {
      await notifier.cancelar(motivo: motivo);
      // Ya no es suyo (vuelve a buscar chofer o queda sin chofer): se vuelve al mapa.
      notifier.descartar();
      mensajero.showSnackBar(const SnackBar(content: Text('Cancelaste el viaje.')));
    });
  }

  @override
  Widget build(BuildContext context) {
    final viaje = widget.viaje;
    final paso = viaje.siguientePaso;
    final telefono = telefonoMarcable(viaje.solicitante.telefono);
    final aqui = ref.watch(posicionPropiaProvider).punto?.posicion;
    final mapa = ref.watch(constructorMapaProvider);
    final texto = Theme.of(context).textTheme;
    final notifier = ref.read(viajeActualProvider.notifier);

    return Column(
      children: [
        Expanded(
          child: mapa(
            context,
            DatosMapa(
              centro: aqui ?? viaje.haciaDonde.coordenada,
              marcadores: [
                MarcadorMapa(
                  id: 'origen',
                  posicion: viaje.origen.coordenada,
                  tipo: TipoMarcador.origen,
                  titulo: 'Origen',
                ),
                MarcadorMapa(
                  id: 'destino',
                  posicion: viaje.destino.coordenada,
                  tipo: TipoMarcador.destino,
                  titulo: 'Destino',
                ),
                if (aqui != null)
                  MarcadorMapa(id: 'yo', posicion: aqui, tipo: TipoMarcador.choferAsignado, titulo: 'Vos'),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(viaje.solicitante.nombre, style: texto.titleLarge),
              if (viaje.obligatorio) const Text('Viaje obligatorio'),
              if (viaje.programadoPara case final cuando?) Text('Reserva para ${formatearFechaHora(cuando)}'),
              Text('Origen: ${viaje.origen.descripcion}'),
              Text('Destino: ${viaje.destino.descripcion}'),
              if (viaje.motivo case final motivo?) Text('Motivo: $motivo'),
              const SizedBox(height: 16),
              if (paso != null)
                FilledButton(
                  onPressed: _enviando ? null : () => _accion(() => notifier.avanzar(paso)),
                  child: Text(textoPaso(paso)),
                ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.navigation),
                      label: const Text('Navegar'),
                      onPressed: _navegar,
                    ),
                  ),
                  if (telefono != null) ...[
                    const SizedBox(width: 12),
                    Expanded(
                      child: OutlinedButton.icon(
                        icon: const Icon(Icons.phone),
                        label: const Text('Llamar'),
                        onPressed: () => _abrir([Uri(scheme: 'tel', path: telefono)], 'No se pudo abrir el teléfono.'),
                      ),
                    ),
                  ],
                ],
              ),
              if (viaje.cancelablePorChofer)
                TextButton(onPressed: _enviando ? null : _cancelar, child: const Text('Cancelar viaje')),
            ],
          ),
        ),
      ],
    );
  }
}

/// Pide el motivo (obligatorio para el chofer, `ViajeController::cancelar`).
class _DialogoCancelar extends StatefulWidget {
  const _DialogoCancelar();

  @override
  State<_DialogoCancelar> createState() => _DialogoCancelarState();
}

class _DialogoCancelarState extends State<_DialogoCancelar> {
  final _motivo = TextEditingController();

  @override
  void dispose() {
    _motivo.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final motivo = _motivo.text.trim();
    return AlertDialog(
      title: const Text('¿Cancelar el viaje?'),
      content: TextField(
        controller: _motivo,
        autofocus: true,
        maxLength: 255,
        decoration: const InputDecoration(labelText: 'Motivo'),
        onChanged: (_) => setState(() {}),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Volver')),
        FilledButton(
          onPressed: motivo.isEmpty ? null : () => Navigator.pop(context, motivo),
          child: const Text('Cancelar viaje'),
        ),
      ],
    );
  }
}

class _Fin extends ConsumerWidget {
  const _Fin({required this.icono, required this.texto});

  final IconData icono;
  final String texto;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(icono, size: 48),
          const SizedBox(height: 16),
          Text(texto, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 32),
          FilledButton(
            onPressed: () {
              ref.read(viajeActualProvider.notifier).descartar();
              context.go(Rutas.chofer);
            },
            child: const Text('Volver al mapa'),
          ),
        ],
      ),
    );
  }
}
```

- [ ] **Step 8: Ruta y paso automático**

`paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart` queda así (`Rutas.viajeChofer` y la ruta hija `viaje` de `/chofer`):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:go_router/go_router.dart';

import '../entorno.dart';
import '../push/push_modulo.dart';
import '../sesion/sesion.dart';
import 'chofer/inicio_chofer.dart';
import 'chofer/viaje_chofer.dart';
import 'sesion/pantalla_inicio.dart';
import 'solicitante/inicio_solicitante.dart';
import 'solicitante/mis_viajes.dart';
import 'solicitante/pantalla_reserva.dart';
import 'solicitante/pantalla_viaje.dart';

/// Cierra el módulo y vuelve a la app principal. Lo define [ModuloVehiculos].
final cerrarModuloProvider = Provider<VoidCallback>((ref) => () {});

abstract final class Rutas {
  static const inicio = '/';
  static const solicitante = '/solicitante';
  static const viaje = '/solicitante/viaje';
  static const reservar = '/solicitante/reservar';
  static const misViajes = '/solicitante/mis-viajes';
  static const chofer = '/chofer';
  static const viajeChofer = '/chofer/viaje';
}

/// Raíz del módulo: su propio `ProviderScope` y su propio router (no toca los de la app principal).
///
/// Se queda con el [entorno], [alCerrar] y [overrides] de la primera construcción: la app principal puede
/// reconstruir la ruta (cambio de tema, de idioma…) y un entorno nuevo reiniciaría todo el módulo
/// (cliente HTTP sin token, sesión otra vez "iniciando", otro socket). Del contexto solo toma el tema.
class ModuloVehiculos extends StatefulWidget {
  const ModuloVehiculos({super.key, required this.entorno, required this.alCerrar, this.overrides = const []});

  final EntornoModulo entorno;
  final VoidCallback alCerrar;

  /// Solo para tests (HTTP falso, Reverb falso, mapa de prueba…).
  @visibleForTesting
  final List<Override> overrides;

  @override
  State<ModuloVehiculos> createState() => _ModuloVehiculosState();
}

class _ModuloVehiculosState extends State<ModuloVehiculos> {
  late final List<Override> _overrides = [
    entornoProvider.overrideWithValue(widget.entorno),
    cerrarModuloProvider.overrideWithValue(widget.alCerrar),
    ...widget.overrides,
  ];

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      // Riverpod 3 reintenta por defecto los providers que fallan; acá los errores se muestran y se
      // reintentan a mano o por el respaldo de 10 s.
      retry: (_, _) => null,
      overrides: _overrides,
      child: _RaizModulo(tema: Theme.of(context)),
    );
  }
}

class _RaizModulo extends ConsumerStatefulWidget {
  const _RaizModulo({required this.tema});

  final ThemeData tema;

  @override
  ConsumerState<_RaizModulo> createState() => _RaizModuloState();
}

class _RaizModuloState extends ConsumerState<_RaizModulo> {
  final _cambiosDeSesion = ValueNotifier<int>(0);
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    ref.listenManual(sesionProvider, (_, _) => _cambiosDeSesion.value++);
    _router = GoRouter(
      refreshListenable: _cambiosDeSesion,
      redirect: (context, estado) {
        final sesion = ref.read(sesionProvider);
        final enInicio = estado.matchedLocation == Rutas.inicio;
        if (sesion is! SesionLista) return enInicio ? null : Rutas.inicio;
        if (enInicio) return sesion.usuario.esChofer ? Rutas.chofer : Rutas.solicitante;
        return null;
      },
      routes: [
        GoRoute(path: Rutas.inicio, builder: (_, _) => const PantallaInicio()),
        ShellRoute(
          builder: (_, _, child) => _ConSesion(child: child),
          routes: [
            GoRoute(
              path: Rutas.solicitante,
              builder: (_, _) => const InicioSolicitante(),
              routes: [
                GoRoute(path: 'viaje', builder: (_, _) => const PantallaViaje()),
                GoRoute(
                  path: 'reservar',
                  builder: (_, estado) => PantallaReserva(fechaInicial: estado.extra as DateTime?),
                ),
                GoRoute(path: 'mis-viajes', builder: (_, _) => const MisViajesPantalla()),
              ],
            ),
            GoRoute(
              path: Rutas.chofer,
              builder: (_, _) => const InicioChofer(),
              routes: [GoRoute(path: 'viaje', builder: (_, _) => const ViajeChofer())],
            ),
          ],
        ),
      ],
    );
  }

  @override
  void dispose() {
    _router.dispose();
    _cambiosDeSesion.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // El "atrás" del sistema lo recibe primero el Navigator de la app principal, que cerraría todo el
    // módulo. Se bloquea ese pop y se le pasa al router interno (diálogos, hojas y rutas del módulo);
    // solo si ya no hay nada que cerrar adentro, se cierra el módulo.
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (yaCerro, _) async {
        if (yaCerro) return;
        if (!await _router.routerDelegate.popRoute()) ref.read(cerrarModuloProvider)();
      },
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        title: 'Vehículos oficiales',
        theme: widget.tema,
        locale: const Locale('es', 'AR'),
        supportedLocales: const [Locale('es', 'AR'), Locale('es')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        routerConfig: _router,
      ),
    );
  }
}

/// Pantallas con sesión lista: activa el puente push (registro del token y avisos).
class _ConSesion extends ConsumerStatefulWidget {
  const _ConSesion({required this.child});

  final Widget child;

  @override
  ConsumerState<_ConSesion> createState() => _ConSesionState();
}

class _ConSesionState extends ConsumerState<_ConSesion> {
  @override
  void initState() {
    super.initState();
    ref.read(pushModuloProvider);
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
```

`paquete/vehiculos_oficiales/lib/src/ui/chofer/inicio_chofer.dart` queda así (el `ref.listen` que pasa al viaje):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../chofer/turno.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import 'iniciar_turno.dart';
import 'mapa_chofer.dart';

/// Entrada del chofer: sin turno, "Iniciar turno"; con turno, su mapa. Como `InicioSolicitante`, pasa a la
/// pantalla del viaje cuando aparece uno (el que había al abrir, uno aceptado o asignado).
class InicioChofer extends ConsumerWidget {
  const InicioChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Solo cuando aparece o cambia de viaje: las novedades del mismo no deshacen el "atrás".
    ref.listen(viajeActualProvider, (anterior, siguiente) {
      final id = siguiente.value?.viaje?.id;
      if (id != null && id != anterior?.value?.viaje?.id) context.go(Rutas.viajeChofer);
    });
    final turno = ref.watch(turnoProvider);

    if (turno.hasValue) {
      final t = turno.value;
      if (t == null) return const IniciarTurno();
      // "Atrás" en el mapa cerraría el módulo sin preguntar: pasa por la misma confirmación que la X.
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (cerro, _) {
          if (!cerro) cerrarModuloChofer(context, ref);
        },
        child: MapaChofer(turno: t),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Vehículos oficiales'), leading: const BotonCerrarModulo()),
      body: Center(
        child: turno.hasError
            ? Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(mensajeDeError(turno.error!)),
                  TextButton(onPressed: () => ref.invalidate(turnoProvider), child: const Text('Reintentar')),
                ],
              )
            : const CircularProgressIndicator(),
      ),
    );
  }
}

/// Cierra el módulo. Con el turno abierto pregunta antes: el GPS vive con el módulo y se corta al cerrarlo.
Future<void> cerrarModuloChofer(BuildContext context, WidgetRef ref) async {
  final cerrar = ref.read(cerrarModuloProvider);
  if (ref.read(turnoProvider).value == null) return cerrar();
  final confirma = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Tu turno sigue abierto'),
      content: const Text(
        'Si cerrás Vehículos oficiales dejás de compartir tu ubicación hasta que lo vuelvas a abrir. '
        'Para terminar el día usá "Finalizar turno".',
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Seguir acá')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Cerrar igual')),
      ],
    ),
  );
  if (confirma == true) cerrar();
}

class BotonCerrarModulo extends ConsumerWidget {
  const BotonCerrarModulo({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) =>
      IconButton(icon: const Icon(Icons.close), tooltip: 'Cerrar', onPressed: () => cerrarModuloChofer(context, ref));
}
```

`paquete/vehiculos_oficiales/lib/src/ui/chofer/mapa_chofer.dart` queda así (aviso "Tenés un viaje en curso." con "Ver"):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../chofer/turno.dart';
import '../../entorno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../sesion/sesion.dart';
import '../../solicitante/choferes_mapa.dart';
import '../../ubicacion/ubicador.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import 'inicio_chofer.dart';

/// Spec 7, chofer 2 y 6: su posición, su estado, el vehículo del turno y "Finalizar turno".
class MapaChofer extends ConsumerWidget {
  const MapaChofer({super.key, required this.turno});

  final Turno turno;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final posicion = ref.watch(posicionPropiaProvider);
    final usuario = ref.watch(usuarioProvider);
    // El estado lo calcula el backend (spec 4.1) y llega en el mismo listado que ve el solicitante.
    final yo = ref.watch(choferesMapaProvider).value?.where((c) => c.id == usuario.id).firstOrNull;
    final mapa = ref.watch(constructorMapaProvider);
    final config = ref.watch(entornoProvider).config;
    final aqui = posicion.punto?.posicion;
    final texto = Theme.of(context).textTheme;
    final hayViaje = ref.watch(viajeActualProvider).value?.viaje != null;

    return Scaffold(
      appBar: AppBar(title: const Text('Vehículos oficiales'), leading: const BotonCerrarModulo()),
      body: Column(
        children: [
          const BannerConexion(),
          if (hayViaje)
            MaterialBanner(
              content: const Text('Tenés un viaje en curso.'),
              actions: [TextButton(onPressed: () => context.go(Rutas.viajeChofer), child: const Text('Ver'))],
            ),
          if (posicion.sinGps)
            MaterialBanner(
              leading: const Icon(Icons.gps_off),
              content: const Text(
                'No podemos obtener tu ubicación. Revisá que la ubicación del teléfono esté activa y el permiso '
                'concedido.',
              ),
              actions: [
                TextButton(
                  onPressed: () => ref.read(ubicadorProvider).abrirAjustes(PermisoUbicacion.denegado),
                  child: const Text('Abrir ajustes'),
                ),
                TextButton(
                  onPressed: () => ref.read(turnoProvider.notifier).reintentarGps(),
                  child: const Text('Reintentar'),
                ),
              ],
            ),
          Expanded(
            child: mapa(
              context,
              DatosMapa(
                centro: aqui ?? Coordenada(config.centroMapaLat, config.centroMapaLng),
                marcadores: [
                  if (aqui != null)
                    MarcadorMapa(id: 'yo', posicion: aqui, tipo: TipoMarcador.choferAsignado, titulo: 'Vos'),
                ],
              ),
            ),
          ),
          Material(
            elevation: 8,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(yo?.estado.texto ?? 'En turno', style: texto.titleLarge),
                  if (turno.vehiculo case final v?) Text([v.descripcion, ?v.color].join(' · ')),
                  if (aqui == null && !posicion.sinGps) const Text('Buscando tu ubicación…'),
                  const SizedBox(height: 16),
                  OutlinedButton(onPressed: () => _finalizar(context, ref), child: const Text('Finalizar turno')),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _finalizar(BuildContext context, WidgetRef ref) async {
    final confirma = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('¿Finalizar el turno?'),
        content: const Text('Se deja de compartir tu ubicación y no vas a recibir viajes.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, finalizar')),
        ],
      ),
    );
    if (confirma != true || !context.mounted) return;
    try {
      await ref.read(turnoProvider.notifier).finalizar();
    } on ErrorApi catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }
}
```

- [ ] **Step 9: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **192 PASS**, sin problemas, `0 changed`. Sin la comparación de `_version` en `refrescar` falla "una consulta del respaldo que empezó antes de una novedad no la pisa"; sin `_atrasado`, "una respuesta que llega después de un "cancelado" no lo pisa".

- [ ] **Step 10: Commit**

```bash
git add paquete
git commit -m "feat: viaje en curso del chofer con pasos, navegación externa y cancelación" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Oferta entrante y "Viaje asignado"

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/chofer/cuenta_regresiva.dart`, `lib/src/ui/chofer/pantalla_oferta.dart`, `lib/src/ui/chofer/viaje_asignado.dart`
- Modify: `lib/src/viaje/viaje_actual.dart` (`asignadoSinOferta`, `aceptarOferta`, `rechazarOferta`, `ofertaVencida`, `verViajeAsignado`), `lib/src/ui/modulo_app.dart` (rutas `/chofer/oferta` y `/chofer/asignado`), `lib/src/ui/chofer/inicio_chofer.dart` (paso automático a la oferta y al aviso)
- Test: `test/ui/pantalla_oferta_test.dart`, `test/viaje_actual_chofer_test.dart` (casos nuevos)

**Interfaces:**
- Consumes: `viajeActualProvider`, `relojServidorProvider` (Task 2), `ApiVehiculos.aceptarOferta/rechazarOferta` (Task 1), `posicionPropiaProvider`, `distanciaMetros`, `formatearDistancia`.
- Produces:
  - En `SeguimientoViaje`: `bool asignadoSinOferta` (C10) y `conAsignado(bool)`. En `ViajeActualNotifier`: `Future<void> aceptarOferta()` (usa la oferta del estado; con la respuesta fija el viaje y borra la oferta; un 422 también la borra y se relanza), `Future<void> rechazarOferta()`, `void ofertaVencida(int ofertaId)`, `void verViajeAsignado()`.
  - `restanteProvider` (`NotifierProvider.autoDispose.family<RestanteNotifier, Duration, DateTime>`) y `segundosRestantes(Duration)` (C12).
  - `alertaOfertaProvider` (`Provider<void Function()>`, vibración + sonido, nunca lanza), `PantallaOferta` (`/chofer/oferta`, `Rutas.ofertaChofer`) y `ViajeAsignado` (`/chofer/asignado`, `Rutas.viajeAsignado`), decisiones 7 y 9, C11.

- [ ] **Step 1: Escribir los tests que fallan**

`paquete/vehiculos_oficiales/test/viaje_actual_chofer_test.dart` queda así (grupos nuevos `oferta` y `asignado sin oferta`):

```dart
import 'dart:async';

import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
import 'soporte/dobles_chofer.dart';
import 'soporte/entorno_prueba.dart';

void main() {
  late ApiChofer api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiChofer();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(chofer),
    ]);
    c.listen(viajeActualProvider, (_, _) {});
    return c;
  }

  SeguimientoViaje leer(ProviderContainer c) => c.read(viajeActualProvider).requireValue;

  group('avanzar', () {
    test('manda el paso y aplica la respuesta', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.enCamino);
        async.flushMicrotasks();

        expect(api.avances.single, (1, EstadoViaje.enCamino));
        expect(leer(c).viaje!.estado, EstadoViaje.enCamino);
      });
    });

    test('una respuesta que llega después de un "cancelado" no lo pisa', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'cancelado', conChofer: true)));
        c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.llego);
        async.flushMicrotasks();

        expect(leer(c).viaje!.estado, EstadoViaje.cancelado);
      });
    });

    test('cancelar como chofer: el viaje vuelve sin chofer (no cuenta como retroceso)', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        c.read(viajeActualProvider.notifier).cancelar(motivo: 'Se rompió el auto');
        async.flushMicrotasks();

        expect(api.cancelaciones.single, (1, 'Se rompió el auto'));
        expect(leer(c).viaje!.chofer, isNull);
        expect(leer(c).viaje!.estado, EstadoViaje.buscando);
      });
    });
  });

  test('una consulta del respaldo que empezó antes de una novedad no la pisa', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      final c = crear();
      async.flushMicrotasks();

      api.demoraActual = Completer<void>();
      async.elapse(const Duration(seconds: 10)); // empieza la consulta, que ve "aceptado"
      c.read(viajeActualProvider.notifier).avanzar(EstadoViaje.enCamino);
      async.flushMicrotasks();
      api.demoraActual!.complete();
      async.flushMicrotasks();

      expect(leer(c).viaje!.estado, EstadoViaje.enCamino);

      api
        ..demoraActual = null
        ..actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
      async.elapse(const Duration(seconds: 10)); // la siguiente sí se aplica
      expect(leer(c).viaje!.estado, EstadoViaje.llego);
    });
  });

  group('oferta', () {
    ProviderContainer conOferta(FakeAsync async) {
      api.actual = ViajeActual.fromJson(p.json(p.viajeActualChofer));
      final c = crear();
      async.flushMicrotasks();
      expect(leer(c).oferta!.id, 1);
      return c;
    }

    test('aceptar: queda el viaje, se va la oferta y no es "asignado sin oferta"', () {
      fakeAsync((async) {
        final c = conOferta(async);

        c.read(viajeActualProvider.notifier).aceptarOferta();
        async.flushMicrotasks();

        expect(api.llamadas, contains('aceptar:1'));
        expect(leer(c).oferta, isNull);
        expect(leer(c).viaje!.estado, EstadoViaje.aceptado);
        expect(leer(c).asignadoSinOferta, isFalse);
      });
    });

    test('si el evento "aceptado" llega antes que la respuesta, tampoco es "asignado sin oferta"', () {
      fakeAsync((async) {
        final c = conOferta(async);

        tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'aceptado', conChofer: true)));
        c.read(viajeActualProvider.notifier).aceptarOferta(); // la oferta ya no está: no hace nada
        async.flushMicrotasks();

        expect(leer(c).viaje!.estado, EstadoViaje.aceptado);
        expect(leer(c).asignadoSinOferta, isFalse);
      });
    });

    test('rechazar la quita; un 422 también la quita y llega a quien llamó', () {
      fakeAsync((async) {
        var c = conOferta(async);
        c.read(viajeActualProvider.notifier).rechazarOferta();
        async.flushMicrotasks();
        expect(api.llamadas, contains('rechazar:1'));
        expect(leer(c).oferta, isNull);

        c = conOferta(async);
        api.errorOferta = const ErrorNegocio('La oferta ya no está vigente.');
        Object? error;
        c.read(viajeActualProvider.notifier).aceptarOferta().catchError((Object e) => error = e);
        async.flushMicrotasks();
        expect(error, isA<ErrorNegocio>());
        expect(leer(c).oferta, isNull);
      });
    });

    test('sin red al rechazar la oferta se conserva', () {
      fakeAsync((async) {
        final c = conOferta(async);
        api.errorOferta = const SinConexion();

        c.read(viajeActualProvider.notifier).rechazarOferta().catchError((Object _) {});
        async.flushMicrotasks();

        expect(leer(c).oferta!.id, 1);
      });
    });

    test('al vencer se quita', () {
      fakeAsync((async) {
        final c = conOferta(async);

        c.read(viajeActualProvider.notifier).ofertaVencida(1);

        expect(leer(c).oferta, isNull);
      });
    });
  });

  group('asignado sin oferta', () {
    test('un obligatorio aceptado que llega por el canal queda marcado hasta verlo', () {
      fakeAsync((async) {
        final c = crear();
        async.flushMicrotasks();

        tr.emitir(
          'chofer.2',
          Eventos.viajeActualizado,
          jsonViaje(viaje(id: 3, estado: 'aceptado', obligatorio: true, conChofer: true)),
        );
        expect(leer(c).viaje!.id, 3);
        expect(leer(c).asignadoSinOferta, isTrue);

        tr.emitir('chofer.2', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
        expect(leer(c).asignadoSinOferta, isTrue);

        c.read(viajeActualProvider.notifier).verViajeAsignado();
        expect(leer(c).asignadoSinOferta, isFalse);
        expect(leer(c).viaje!.id, 3);
      });
    });

    test('sin socket, el viaje asignado que aparece en la consulta también queda marcado', () {
      fakeAsync((async) {
        tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
        final c = crear();
        async.flushMicrotasks();

        api.actual = ViajeActual(viaje: viaje(id: 4, estado: 'aceptado', conChofer: true));
        async.elapse(const Duration(seconds: 10));

        expect(leer(c).viaje!.id, 4);
        expect(leer(c).asignadoSinOferta, isTrue);
      });
    });

    test('el viaje que había al abrir no se marca', () {
      fakeAsync((async) {
        api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
        final c = crear();
        async.flushMicrotasks();

        expect(leer(c).asignadoSinOferta, isFalse);
      });
    });
  });
}
```

`paquete/vehiculos_oficiales/test/ui/pantalla_oferta_test.dart`:

```dart
import 'dart:convert';

import 'package:clock/clock.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/reloj_servidor.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/mapa_chofer.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/pantalla_oferta.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_asignado.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_chofer.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

/// Payload de `oferta.creada` (inmediato) que vence en [vence] según el reloj del teléfono.
Map<String, dynamic> ofertaCreada(Duration vence) => {
  'oferta_id': 1,
  'vence_en': escribirFecha(clock.now().add(vence)),
  'viaje': p.json(p.viajeOfrecido),
};

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;
  late int alertas;

  setUp(() {
    e = entornoChofer();
    tr = TiempoRealFalso();
    alertas = 0;
  });

  Future<void> abrir(WidgetTester tester, {RelojServidor? reloj}) => montarChofer(
    tester,
    e,
    tiempoReal: tr,
    extra: [
      alertaOfertaProvider.overrideWithValue(() => alertas++),
      if (reloj != null) relojServidorProvider.overrideWithValue(reloj),
    ],
  );

  /// Llega la oferta por el socket y se abre su pantalla, sin que pase el tiempo.
  Future<void> llegaOferta(WidgetTester tester, Duration vence) async {
    tr.emitir('chofer.2', Eventos.ofertaCreada, ofertaCreada(vence));
    await tester.pump();
    await tester.pump();
  }

  testWidgets('la cuenta regresiva sale de vence_en (12 s, no 30) y baja con el reloj', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 12));

    expect(find.byType(PantallaOferta), findsOneWidget);
    expect(find.text('12 s'), findsOneWidget);
    expect(find.text('Ana Pérez'), findsOneWidget);
    expect(find.text('Destino: Tribunales'), findsOneWidget);

    await tester.pump(const Duration(seconds: 1));
    expect(find.text('11 s'), findsOneWidget);
  });

  testWidgets('con el reloj del servidor 60 s adelantado, vence_en a 70 s del teléfono son 10 s', (tester) async {
    final cliente = ClienteApi(baseApi: configPrueba.apiUri, alRecibir401: () {})
      ..desfaseReloj = const Duration(seconds: 60);

    await abrir(tester, reloj: RelojServidor(cliente));
    await llegaOferta(tester, const Duration(seconds: 70));

    expect(find.text('10 s'), findsOneWidget);
  });

  testWidgets('vibra y suena al abrir y cada 5 s', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    expect(alertas, 1);

    await tester.pump(const Duration(seconds: 4));
    expect(alertas, 1);
    await tester.pump(const Duration(seconds: 1));
    expect(alertas, 2);
    await tester.pump(const Duration(seconds: 5));
    expect(alertas, 3);
  });

  testWidgets('aceptar: POST /ofertas/1/aceptar y pasa al viaje', (tester) async {
    e.http.responder('POST', 'ofertas/1/aceptar', 200, p.viajeAceptado);

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/1/aceptar'));
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('rechazar: POST /ofertas/1/rechazar y vuelve al mapa', (tester) async {
    e.http.responder('POST', 'ofertas/1/rechazar', 204);

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Rechazar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/1/rechazar'));
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('a 0 muestra "La oferta venció" y vuelve al mapa sin responder', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 3));

    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
    expect(find.text('La oferta venció'), findsOneWidget);
    expect(find.text('Aceptar'), findsNothing);

    await tester.tap(find.text('Volver al mapa'));
    await esperar(tester);
    expect(find.byType(MapaChofer), findsOneWidget);
    expect(pedidosHechos(e).where((r) => r.contains('ofertas')), isEmpty);
  });

  testWidgets('422 "La oferta ya no está vigente." se muestra y vuelve al mapa', (tester) async {
    e.http.responder('POST', 'ofertas/1/aceptar', 422, c.ofertaNoVigente);

    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(find.text('La oferta ya no está vigente.'), findsOneWidget);
    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('si el solicitante cancela mientras está la oferta, vuelve al mapa', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(p.viajeOfrecido)..['estado'] = 'cancelado');
    await esperar(tester);

    expect(find.byType(MapaChofer), findsOneWidget);
  });

  testWidgets('"atrás" no saca de la oferta', (tester) async {
    await abrir(tester);
    await llegaOferta(tester, const Duration(seconds: 30));

    await tester.binding.handlePopRoute();
    await tester.pump();
    expect(find.byType(PantallaOferta), findsOneWidget);
  });

  testWidgets('un obligatorio asignado sin oferta: "Viaje asignado" sin "Rechazar" y después sin "Cancelar"', (
    tester,
  ) async {
    await abrir(tester);

    final obligatorio = p.json(p.viajeAceptado)
      ..['id'] = 7
      ..['obligatorio'] = true;
    tr.emitir('chofer.2', Eventos.viajeActualizado, obligatorio);
    await esperar(tester);

    expect(find.byType(ViajeAsignado), findsOneWidget);
    expect(find.text('Es un viaje obligatorio: no se puede rechazar.'), findsOneWidget);
    expect(find.text('Rechazar'), findsNothing);
    expect(alertas, 1);

    await tester.tap(find.text('Ver viaje'));
    await esperar(tester);
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Viaje obligatorio'), findsOneWidget);
    expect(find.text('Cancelar viaje'), findsNothing);
  });

  testWidgets('una oferta pendiente al abrir el módulo se muestra enseguida', (tester) async {
    final conOferta = p.json(p.viajeActualChofer);
    (conOferta['oferta'] as Map<String, dynamic>)['vence_en'] = escribirFecha(
      clock.now().add(const Duration(seconds: 20)),
    );
    e = entornoChofer(viajeActual: jsonEncode(conOferta));

    await abrir(tester);

    expect(find.byType(PantallaOferta), findsOneWidget);
  });
}
```

- [ ] **Step 2: Correr y ver que fallan**

Run: `flutter test test/viaje_actual_chofer_test.dart test/ui/pantalla_oferta_test.dart`
Expected: FAIL de compilación (no existen `aceptarOferta`, `asignadoSinOferta`, `pantalla_oferta.dart`…).

- [ ] **Step 3: Ofertas y asignación en el notifier**

`paquete/vehiculos_oficiales/lib/src/viaje/viaje_actual.dart` queda así:

```dart
import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../sesion/sesion.dart';
import '../tiempo_real/respaldo.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';

/// Lo que se muestra del viaje en curso: el viaje (puede quedar en un estado final hasta que el usuario
/// lo descarte), la oferta pendiente del chofer y la última posición conocida del chofer asignado.
class SeguimientoViaje {
  const SeguimientoViaje({this.viaje, this.oferta, this.ubicacionChofer, this.asignadoSinOferta = false});

  final Viaje? viaje;
  final Oferta? oferta;
  final UbicacionChofer? ubicacionChofer;

  /// Chofer: [viaje] le llegó ya asignado, sin oferta (obligatorio, o asignado por un administrador). Se
  /// muestra el aviso "Viaje asignado" hasta que lo vea (`verViajeAsignado`).
  final bool asignadoSinOferta;

  SeguimientoViaje conViaje(Viaje? v) => SeguimientoViaje(
    viaje: v,
    oferta: oferta,
    ubicacionChofer: v?.chofer?.id == viaje?.chofer?.id ? ubicacionChofer : null,
    asignadoSinOferta: asignadoSinOferta && v?.id == viaje?.id,
  );

  SeguimientoViaje conOferta(Oferta? o) =>
      SeguimientoViaje(viaje: viaje, oferta: o, ubicacionChofer: ubicacionChofer, asignadoSinOferta: asignadoSinOferta);

  SeguimientoViaje conUbicacion(UbicacionChofer? u) =>
      SeguimientoViaje(viaje: viaje, oferta: oferta, ubicacionChofer: u, asignadoSinOferta: asignadoSinOferta);

  SeguimientoViaje conAsignado(bool a) =>
      SeguimientoViaje(viaje: viaje, oferta: oferta, ubicacionChofer: ubicacionChofer, asignadoSinOferta: a);
}

final viajeActualProvider = AsyncNotifierProvider<ViajeActualNotifier, SeguimientoViaje>(ViajeActualNotifier.new);

/// Viaje actual sincronizado (spec 6): eventos de Reverb mientras el socket está conectado; con el socket
/// caído, `GET /viajes/actual` cada 10 s; al reconectar, estado completo otra vez.
class ViajeActualNotifier extends AsyncNotifier<SeguimientoViaje> {
  StreamSubscription<EventoTiempoReal>? _canalViaje;
  int? _canalViajeId;
  bool _consultando = false;

  /// Cambia con cada novedad del viaje o de la oferta que no vino de [refrescar] (respuesta de una acción,
  /// evento del socket). Una consulta que empezó antes y termina después no pisa esa novedad.
  int _version = 0;

  late Usuario _usuario;
  late TiempoReal _tr;

  @override
  Future<SeguimientoViaje> build() async {
    _usuario = ref.watch(usuarioProvider);
    _tr = ref.watch(tiempoRealProvider);

    final canalChofer = _usuario.esChofer ? _tr.canal(Canales.chofer(_usuario.id)).listen(_alEvento) : null;
    final respaldo = Respaldo(tiempoReal: _tr, intervalo: ref.read(intervaloRespaldoProvider), refrescar: refrescar);
    ref.onDispose(() {
      respaldo.cerrar();
      unawaited(canalChofer?.cancel());
      unawaited(_canalViaje?.cancel());
    });

    final inicial = await _consultar(null);
    _seguir(inicial.viaje);
    return inicial;
  }

  /// Consulta la API y reemplaza el estado. La usan el respaldo, la reconexión y los avisos push.
  Future<void> refrescar() async {
    if (_consultando) return;
    _consultando = true;
    final version = _version;
    final antes = state.value;
    try {
      var nuevo = await _consultar(antes?.viaje);
      if (!ref.mounted || version != _version) return;
      // Sin socket, un viaje asignado sin oferta aparece recién acá.
      if (nuevo.viaje case final v? when _llegoSinOferta(antes, v)) nuevo = nuevo.conAsignado(true);
      state = AsyncData(nuevo);
      _seguir(nuevo.viaje);
    } on SesionInvalida {
      // `ClienteApi` ya avisó la sesión inválida (una sola vez); acá no hay nada más que hacer y este
      // método corre sin await desde el timer y el listener, así que no puede propagar el error.
    } on ErrorApi {
      // Sin red o error pasajero: se conserva lo último que se sabía y se reintenta en el próximo ciclo.
    } finally {
      _consultando = false;
    }
  }

  /// Pedido inmediato (spec 5.2 y 5.3). Los errores (422, etc.) llegan a la pantalla.
  Future<Viaje> pedir(PedidoViaje pedido) async {
    final v = await ref.read(apiProvider).pedirViaje(pedido);
    _fijar(SeguimientoViaje(viaje: v));
    _seguir(v);
    return v;
  }

  Future<void> cancelar({String? motivo}) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).cancelarViaje(actual.id, motivo: motivo);
    if (ref.mounted) _aplicarViaje(v);
  }

  /// Paso siguiente del chofer (spec 5.5): `en_camino`, `llego`, `en_curso` o `finalizado`. Los errores
  /// (422 de una reserva que todavía no puede empezar, 403 si el viaje ya no es suyo) llegan a la pantalla.
  Future<void> avanzar(EstadoViaje hacia) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).avanzarViaje(actual.id, hacia);
    if (ref.mounted) _aplicarViaje(v);
  }

  /// Chofer: acepta la oferta pendiente (spec 5.1). Con la respuesta queda el viaje y se va la oferta. Si
  /// ya no está vigente (422) también se va, y el error llega a la pantalla.
  Future<void> aceptarOferta() async {
    final oferta = state.value?.oferta;
    if (oferta == null) return;
    try {
      final v = await ref.read(apiProvider).aceptarOferta(oferta.id);
      if (ref.mounted) _aplicarViaje(v);
    } on ErrorNegocio {
      _quitarOferta(oferta.id);
      rethrow;
    }
  }

  /// Chofer: rechaza la oferta pendiente. Sin red, la oferta queda (se puede reintentar hasta que venza).
  Future<void> rechazarOferta() async {
    final oferta = state.value?.oferta;
    if (oferta == null) return;
    try {
      await ref.read(apiProvider).rechazarOferta(oferta.id);
    } on ErrorNegocio {
      _quitarOferta(oferta.id);
      rethrow;
    }
    _quitarOferta(oferta.id);
  }

  /// La cuenta regresiva de la oferta llegó a cero (según el reloj del servidor).
  void ofertaVencida(int ofertaId) => _quitarOferta(ofertaId);

  /// El chofer vio el aviso "Viaje asignado".
  void verViajeAsignado() {
    final actual = state.value;
    if (actual != null && actual.asignadoSinOferta) _fijar(actual.conAsignado(false));
  }

  void _quitarOferta(int ofertaId) {
    final actual = state.value;
    if (ref.mounted && actual?.oferta?.id == ofertaId) _fijar(actual!.conOferta(null));
  }

  /// El usuario ya vio el estado final (finalizado, cancelado, sin chofer): se vuelve al mapa.
  void descartar() {
    _fijar(const SeguimientoViaje());
    _seguir(null);
  }

  Future<SeguimientoViaje> _consultar(Viaje? previo) async {
    final api = ref.read(apiProvider);
    final actual = await api.viajeActual();
    var viaje = actual.viaje;

    // `viajes/actual` no devuelve viajes terminados: si el que se seguía desapareció mientras no había
    // socket, se busca en el historial para mostrar cómo terminó (p. ej. sin_chofer).
    if (viaje == null && previo != null && !previo.estado.terminado && !_usuario.esChofer) {
      final historial = (await api.misViajes()).historial;
      viaje = historial.where((v) => v.id == previo.id).firstOrNull;
    }
    // Un viaje ya terminado se queda en pantalla hasta que el usuario lo descarte.
    if (viaje == null && previo != null && previo.estado.terminado) viaje = previo;

    UbicacionChofer? ubicacion = viaje?.chofer?.id == state.value?.viaje?.chofer?.id
        ? state.value?.ubicacionChofer
        : null;
    final choferId = viaje?.chofer?.id;
    if (choferId != null && !_usuario.esChofer && _tr.estado != EstadoConexion.conectado) {
      // Sin socket tampoco llegan las posiciones: se toman de GET /choferes. Es un extra: si falla, se
      // conserva la última posición conocida y el viaje igual se actualiza.
      try {
        final c = (await api.choferes()).where((c) => c.id == choferId).firstOrNull;
        if (c?.posicion != null) {
          ubicacion = UbicacionChofer(
            choferId: c!.id,
            posicion: c.posicion!,
            rumbo: c.rumbo,
            actualizadoEn: c.actualizadoEn ?? DateTime.now().toUtc(),
          );
        }
      } on ErrorApi {
        // Incluye un 401: `ClienteApi` ya avisó la sesión inválida.
      }
    }
    return SeguimientoViaje(viaje: viaje, oferta: actual.oferta, ubicacionChofer: ubicacion);
  }

  /// Un evento que no se puede leer (p. ej. un estado nuevo del backend) se ignora: el respaldo o el
  /// próximo evento traen el estado.
  void _alEvento(EventoTiempoReal e) {
    try {
      _aplicarEvento(e);
    } catch (error) {
      if (!esErrorDeLectura(error)) rethrow;
      debugPrint('vehiculos_oficiales: evento ${e.nombre} ignorado, no se pudo leer: $error');
    }
  }

  void _aplicarEvento(EventoTiempoReal e) {
    final actual = state.value;
    if (actual == null) return;

    switch (e.nombre) {
      case Eventos.viajeActualizado:
        _aplicarViaje(Viaje.fromJson(e.datos));
      case Eventos.ofertaCreada:
        final oferta = Oferta.fromJson(e.datos);
        // Las solicitudes de reserva van a la agenda, no a la pantalla de oferta (AvisosViaje / ViajeController::actual).
        if (oferta.viaje.tipo == TipoViaje.inmediato) _fijar(actual.conOferta(oferta));
      case Eventos.choferUbicacion:
        final u = UbicacionChofer.fromJson(e.datos);
        if (actual.viaje?.chofer?.id == u.choferId) state = AsyncData(actual.conUbicacion(u));
    }
  }

  void _aplicarViaje(Viaje v) {
    final actual = state.value ?? const SeguimientoViaje();
    if (_atrasado(actual.viaje, v)) return;
    var nuevo = actual;

    if (_usuario.esChofer) {
      final sinOferta = _llegoSinOferta(actual, v);
      // La oferta ya se respondió, venció o se la llevó otro.
      if (actual.oferta?.viaje.id == v.id && v.estado != EstadoViaje.ofrecido) nuevo = nuevo.conOferta(null);
      // Mismo viaje (incluso si se lo reasignaron a otro o lo cancelaron: el chofer lo ve y lo descarta)
      // o uno nuevo que lo ocupa ahora (obligatorio asignado, reserva que arrancó).
      final ocupaAhora =
          v.chofer?.id == _usuario.id &&
          !v.estado.terminado &&
          (v.tipo == TipoViaje.inmediato || v.estado != EstadoViaje.aceptado);
      if (actual.viaje?.id == v.id || ocupaAhora) nuevo = nuevo.conViaje(v);
      if (sinOferta) nuevo = nuevo.conAsignado(true);
    } else if (actual.viaje == null || actual.viaje!.id == v.id) {
      nuevo = nuevo.conViaje(v);
    }

    _fijar(nuevo);
    _seguir(nuevo.viaje);
  }

  /// Chofer: un inmediato que ya le llega aceptado, sin haber tenido la oferta (spec 5.2 y 5.6). Uno que el
  /// chofer aceptó siempre tuvo su oferta en el estado (el evento o la respuesta de aceptar la encuentran).
  bool _llegoSinOferta(SeguimientoViaje? antes, Viaje v) =>
      _usuario.esChofer &&
      v.tipo == TipoViaje.inmediato &&
      v.estado == EstadoViaje.aceptado &&
      v.chofer?.id == _usuario.id &&
      antes?.viaje?.id != v.id &&
      antes?.oferta?.viaje.id != v.id;

  void _fijar(SeguimientoViaje s) {
    _version++;
    state = AsyncData(s);
  }

  /// Cuánto avanzó un viaje con chofer (0 = todavía sin chofer).
  static int _avance(EstadoViaje e) => switch (e) {
    EstadoViaje.buscando || EstadoViaje.ofrecido => 0,
    EstadoViaje.aceptado => 1,
    EstadoViaje.enCamino => 2,
    EstadoViaje.llego => 3,
    EstadoViaje.enCurso => 4,
    EstadoViaje.finalizado || EstadoViaje.cancelado || EstadoViaje.sinChofer => 5,
  };

  /// Una respuesta o un evento que llega tarde (p. ej. la respuesta de "Iniciar viaje" después del evento
  /// "cancelado") no hace retroceder el mismo viaje con el mismo chofer. Con otro chofer (reasignado,
  /// cancelado por el chofer) sí se aplica.
  static bool _atrasado(Viaje? actual, Viaje v) =>
      actual != null &&
      actual.id == v.id &&
      actual.chofer?.id == v.chofer?.id &&
      _avance(v.estado) > 0 &&
      _avance(v.estado) < _avance(actual.estado);

  /// El solicitante escucha `viaje.{id}` (estado y posición del chofer) mientras el viaje no terminó.
  /// El chofer ya recibe `viaje.actualizado` por `chofer.{id}`.
  void _seguir(Viaje? viaje) {
    final id = viaje != null && !viaje.estado.terminado && !_usuario.esChofer ? viaje.id : null;
    if (id == _canalViajeId) return;
    unawaited(_canalViaje?.cancel());
    _canalViaje = null;
    _canalViajeId = id;
    if (id != null) _canalViaje = _tr.canal(Canales.viaje(id)).listen(_alEvento);
  }
}
```

- [ ] **Step 4: Cuenta regresiva**

`paquete/vehiculos_oficiales/lib/src/chofer/cuenta_regresiva.dart`:

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/reloj_servidor.dart';

/// Lo que falta para un vencimiento del backend (`vence_en`), según el reloj del servidor.
/// Se recalcula cada segundo (no se descuenta un contador) y el timer vive acá, no en la pantalla.
final restanteProvider = NotifierProvider.autoDispose.family<RestanteNotifier, Duration, DateTime>(
  RestanteNotifier.new,
);

class RestanteNotifier extends Notifier<Duration> {
  RestanteNotifier(this.vence);

  final DateTime vence;

  @override
  Duration build() {
    final reloj = ref.watch(relojServidorProvider);
    final timer = Timer.periodic(const Duration(seconds: 1), (t) {
      state = reloj.restante(vence);
      if (state == Duration.zero) t.cancel();
    });
    ref.onDispose(timer.cancel);
    return reloj.restante(vence);
  }
}

/// Segundos para mostrar: 11,2 s se muestran como 12 (llega a 0 recién cuando venció).
int segundosRestantes(Duration d) => (d.inMilliseconds / 1000).ceil();
```

- [ ] **Step 5: Pantallas**

`paquete/vehiculos_oficiales/lib/src/ui/chofer/pantalla_oferta.dart`:

```dart
import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../chofer/cuenta_regresiva.dart';
import '../../chofer/turno.dart';
import '../../modelos/modelos.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Vibración y sonido de la oferta. Costura para los tests. Nunca lanza: sin vibrador o sin
/// sonido del sistema (web, tests) no pasa nada.
final alertaOfertaProvider = Provider<void Function()>(
  (ref) =>
      () => unawaited(_alertar()),
);

Future<void> _alertar() async {
  try {
    await HapticFeedback.vibrate();
    await SystemSound.play(SystemSoundType.alert);
  } catch (_) {
    // Plataforma sin vibración o sin sonidos del sistema.
  }
}

/// Spec 7, chofer 3: oferta entrante a pantalla completa con la cuenta regresiva del servidor.
/// No se sale con "atrás": se acepta, se rechaza o vence.
class PantallaOferta extends ConsumerStatefulWidget {
  const PantallaOferta({super.key});

  @override
  ConsumerState<PantallaOferta> createState() => _PantallaOfertaState();
}

class _PantallaOfertaState extends ConsumerState<PantallaOferta> {
  /// La oferta que se está mostrando (queda al vencer, cuando ya no está en el estado).
  Oferta? _oferta;
  bool _vencida = false;
  bool _respondiendo = false;
  int _segundos = 0;

  @override
  void initState() {
    super.initState();
    _oferta = ref.read(viajeActualProvider).value?.oferta;
    ref.read(alertaOfertaProvider)();
  }

  void _vencer() {
    if (_vencida) return;
    setState(() => _vencida = true);
    ref.read(viajeActualProvider.notifier).ofertaVencida(_oferta!.id);
  }

  Future<void> _responder({required bool aceptar}) async {
    setState(() => _respondiendo = true);
    final notifier = ref.read(viajeActualProvider.notifier);
    try {
      if (aceptar) {
        await notifier.aceptarOferta(); // con el viaje asignado, InicioChofer pasa a su pantalla
      } else {
        await notifier.rechazarOferta();
        if (mounted) context.go(Rutas.chofer);
      }
    } on ErrorApi catch (e) {
      if (!mounted) return;
      mostrarError(context, e);
      if (e is ErrorNegocio) context.go(Rutas.chofer); // "La oferta ya no está vigente."
    } finally {
      if (mounted) setState(() => _respondiendo = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final seguimiento = ref.watch(viajeActualProvider).value;
    final actual = seguimiento?.oferta;
    if (actual != null) _oferta = actual;
    final oferta = _oferta;

    // La oferta se fue sin que la respondiera acá (la canceló el solicitante, la tomó otro): se vuelve.
    if (oferta == null || (actual == null && !_vencida && !_respondiendo)) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        context.go(ref.read(viajeActualProvider).value?.viaje != null ? Rutas.viajeChofer : Rutas.chofer);
      });
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    final restante = ref.watch(restanteProvider(oferta.venceEn));
    ref.listen(restanteProvider(oferta.venceEn), (_, r) {
      if (r == Duration.zero) return _vencer();
      if (++_segundos % 5 == 0) ref.read(alertaOfertaProvider)(); // cada 5 s mientras está abierta
    });
    if (restante == Duration.zero && !_vencida) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _vencer();
      });
    }

    return PopScope(
      canPop: false,
      child: Scaffold(
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: _vencida ? const _Vencida() : _Detalle(oferta: oferta, restante: restante, alResponder: _botones),
          ),
        ),
      ),
    );
  }

  Widget _botones() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      FilledButton(
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
        onPressed: _respondiendo ? null : () => _responder(aceptar: true),
        child: const Text('Aceptar'),
      ),
      const SizedBox(height: 12),
      OutlinedButton(onPressed: _respondiendo ? null : () => _responder(aceptar: false), child: const Text('Rechazar')),
    ],
  );
}

class _Detalle extends ConsumerWidget {
  const _Detalle({required this.oferta, required this.restante, required this.alResponder});

  final Oferta oferta;
  final Duration restante;
  final Widget Function() alResponder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final viaje = oferta.viaje;
    final texto = Theme.of(context).textTheme;
    final aqui = ref.watch(posicionPropiaProvider).punto?.posicion;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Nuevo viaje', style: texto.headlineMedium, textAlign: TextAlign.center),
        const SizedBox(height: 8),
        Text('${segundosRestantes(restante)} s', style: texto.displayMedium, textAlign: TextAlign.center),
        const SizedBox(height: 24),
        Text(viaje.solicitante.nombre, style: texto.titleLarge),
        if (aqui != null) Text('A ${formatearDistancia(distanciaMetros(aqui, viaje.origen.coordenada))} del origen'),
        const SizedBox(height: 8),
        Text('Origen: ${viaje.origen.descripcion}'),
        Text('Destino: ${viaje.destino.descripcion}'),
        if (viaje.motivo case final motivo?) Text('Motivo: $motivo'),
        const Spacer(),
        alResponder(),
      ],
    );
  }
}

class _Vencida extends StatelessWidget {
  const _Vencida();

  @override
  Widget build(BuildContext context) => Column(
    mainAxisAlignment: MainAxisAlignment.center,
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const Icon(Icons.timer_off, size: 48),
      const SizedBox(height: 16),
      Text('La oferta venció', textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
      const SizedBox(height: 32),
      FilledButton(onPressed: () => context.go(Rutas.chofer), child: const Text('Volver al mapa')),
    ],
  );
}
```

`paquete/vehiculos_oficiales/lib/src/ui/chofer/viaje_asignado.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../viaje/viaje_actual.dart';
import '../modulo_app.dart';
import 'pantalla_oferta.dart';

/// Spec 5.2 y 5.6: un viaje que llega ya asignado (obligatorio, o asignado por un administrador) no se ofrece:
/// se avisa a pantalla completa, con un único botón.
class ViajeAsignado extends ConsumerStatefulWidget {
  const ViajeAsignado({super.key});

  @override
  ConsumerState<ViajeAsignado> createState() => _ViajeAsignadoState();
}

class _ViajeAsignadoState extends ConsumerState<ViajeAsignado> {
  @override
  void initState() {
    super.initState();
    ref.read(alertaOfertaProvider)();
  }

  void _verViaje() {
    ref.read(viajeActualProvider.notifier).verViajeAsignado();
    context.go(Rutas.viajeChofer);
  }

  @override
  Widget build(BuildContext context) {
    final seguimiento = ref.watch(viajeActualProvider).value;
    final viaje = seguimiento?.viaje;
    if (viaje == null || !seguimiento!.asignadoSinOferta) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) context.go(viaje != null ? Rutas.viajeChofer : Rutas.chofer);
      });
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    final texto = Theme.of(context).textTheme;

    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (yaCerro, _) {
        if (!yaCerro) _verViaje();
      },
      child: Scaffold(
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Icon(Icons.assignment_ind, size: 48),
                const SizedBox(height: 16),
                Text('Viaje asignado', style: texto.headlineMedium, textAlign: TextAlign.center),
                const SizedBox(height: 8),
                Text(
                  viaje.obligatorio
                      ? 'Es un viaje obligatorio: no se puede rechazar.'
                      : 'Te lo asignó un administrador.',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 24),
                Text(viaje.solicitante.nombre, style: texto.titleLarge),
                Text('Origen: ${viaje.origen.descripcion}'),
                Text('Destino: ${viaje.destino.descripcion}'),
                if (viaje.motivo case final motivo?) Text('Motivo: $motivo'),
                const Spacer(),
                FilledButton(
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                  onPressed: _verViaje,
                  child: const Text('Ver viaje'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
```

- [ ] **Step 6: Rutas y paso automático**

`paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart` queda así:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:go_router/go_router.dart';

import '../entorno.dart';
import '../push/push_modulo.dart';
import '../sesion/sesion.dart';
import 'chofer/inicio_chofer.dart';
import 'chofer/pantalla_oferta.dart';
import 'chofer/viaje_asignado.dart';
import 'chofer/viaje_chofer.dart';
import 'sesion/pantalla_inicio.dart';
import 'solicitante/inicio_solicitante.dart';
import 'solicitante/mis_viajes.dart';
import 'solicitante/pantalla_reserva.dart';
import 'solicitante/pantalla_viaje.dart';

/// Cierra el módulo y vuelve a la app principal. Lo define [ModuloVehiculos].
final cerrarModuloProvider = Provider<VoidCallback>((ref) => () {});

abstract final class Rutas {
  static const inicio = '/';
  static const solicitante = '/solicitante';
  static const viaje = '/solicitante/viaje';
  static const reservar = '/solicitante/reservar';
  static const misViajes = '/solicitante/mis-viajes';
  static const chofer = '/chofer';
  static const viajeChofer = '/chofer/viaje';
  static const ofertaChofer = '/chofer/oferta';
  static const viajeAsignado = '/chofer/asignado';
}

/// Raíz del módulo: su propio `ProviderScope` y su propio router (no toca los de la app principal).
///
/// Se queda con el [entorno], [alCerrar] y [overrides] de la primera construcción: la app principal puede
/// reconstruir la ruta (cambio de tema, de idioma…) y un entorno nuevo reiniciaría todo el módulo
/// (cliente HTTP sin token, sesión otra vez "iniciando", otro socket). Del contexto solo toma el tema.
class ModuloVehiculos extends StatefulWidget {
  const ModuloVehiculos({super.key, required this.entorno, required this.alCerrar, this.overrides = const []});

  final EntornoModulo entorno;
  final VoidCallback alCerrar;

  /// Solo para tests (HTTP falso, Reverb falso, mapa de prueba…).
  @visibleForTesting
  final List<Override> overrides;

  @override
  State<ModuloVehiculos> createState() => _ModuloVehiculosState();
}

class _ModuloVehiculosState extends State<ModuloVehiculos> {
  late final List<Override> _overrides = [
    entornoProvider.overrideWithValue(widget.entorno),
    cerrarModuloProvider.overrideWithValue(widget.alCerrar),
    ...widget.overrides,
  ];

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      // Riverpod 3 reintenta por defecto los providers que fallan; acá los errores se muestran y se
      // reintentan a mano o por el respaldo de 10 s.
      retry: (_, _) => null,
      overrides: _overrides,
      child: _RaizModulo(tema: Theme.of(context)),
    );
  }
}

class _RaizModulo extends ConsumerStatefulWidget {
  const _RaizModulo({required this.tema});

  final ThemeData tema;

  @override
  ConsumerState<_RaizModulo> createState() => _RaizModuloState();
}

class _RaizModuloState extends ConsumerState<_RaizModulo> {
  final _cambiosDeSesion = ValueNotifier<int>(0);
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    ref.listenManual(sesionProvider, (_, _) => _cambiosDeSesion.value++);
    _router = GoRouter(
      refreshListenable: _cambiosDeSesion,
      redirect: (context, estado) {
        final sesion = ref.read(sesionProvider);
        final enInicio = estado.matchedLocation == Rutas.inicio;
        if (sesion is! SesionLista) return enInicio ? null : Rutas.inicio;
        if (enInicio) return sesion.usuario.esChofer ? Rutas.chofer : Rutas.solicitante;
        return null;
      },
      routes: [
        GoRoute(path: Rutas.inicio, builder: (_, _) => const PantallaInicio()),
        ShellRoute(
          builder: (_, _, child) => _ConSesion(child: child),
          routes: [
            GoRoute(
              path: Rutas.solicitante,
              builder: (_, _) => const InicioSolicitante(),
              routes: [
                GoRoute(path: 'viaje', builder: (_, _) => const PantallaViaje()),
                GoRoute(
                  path: 'reservar',
                  builder: (_, estado) => PantallaReserva(fechaInicial: estado.extra as DateTime?),
                ),
                GoRoute(path: 'mis-viajes', builder: (_, _) => const MisViajesPantalla()),
              ],
            ),
            GoRoute(
              path: Rutas.chofer,
              builder: (_, _) => const InicioChofer(),
              routes: [
                GoRoute(path: 'viaje', builder: (_, _) => const ViajeChofer()),
                GoRoute(path: 'oferta', builder: (_, _) => const PantallaOferta()),
                GoRoute(path: 'asignado', builder: (_, _) => const ViajeAsignado()),
              ],
            ),
          ],
        ),
      ],
    );
  }

  @override
  void dispose() {
    _router.dispose();
    _cambiosDeSesion.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // El "atrás" del sistema lo recibe primero el Navigator de la app principal, que cerraría todo el
    // módulo. Se bloquea ese pop y se le pasa al router interno (diálogos, hojas y rutas del módulo);
    // solo si ya no hay nada que cerrar adentro, se cierra el módulo.
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (yaCerro, _) async {
        if (yaCerro) return;
        if (!await _router.routerDelegate.popRoute()) ref.read(cerrarModuloProvider)();
      },
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        title: 'Vehículos oficiales',
        theme: widget.tema,
        locale: const Locale('es', 'AR'),
        supportedLocales: const [Locale('es', 'AR'), Locale('es')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        routerConfig: _router,
      ),
    );
  }
}

/// Pantallas con sesión lista: activa el puente push (registro del token y avisos).
class _ConSesion extends ConsumerStatefulWidget {
  const _ConSesion({required this.child});

  final Widget child;

  @override
  ConsumerState<_ConSesion> createState() => _ConSesionState();
}

class _ConSesionState extends ConsumerState<_ConSesion> {
  @override
  void initState() {
    super.initState();
    ref.read(pushModuloProvider);
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
```

`paquete/vehiculos_oficiales/lib/src/ui/chofer/inicio_chofer.dart` queda así (el aviso "asignado" tiene prioridad; después, una oferta nueva; después, un viaje nuevo):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../chofer/turno.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import 'iniciar_turno.dart';
import 'mapa_chofer.dart';

/// Entrada del chofer: sin turno, "Iniciar turno"; con turno, su mapa. Como `InicioSolicitante`, pasa a la
/// pantalla que corresponde cuando aparece algo nuevo: una oferta, un viaje asignado sin oferta o un viaje
/// (el que había al abrir, uno aceptado, una reserva que arrancó).
class InicioChofer extends ConsumerWidget {
  const InicioChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Solo cuando aparece algo nuevo: las novedades de lo mismo no deshacen el "atrás".
    ref.listen(viajeActualProvider, (anterior, siguiente) {
      final antes = anterior?.value;
      final ahora = siguiente.value;
      if (ahora == null) return;
      if (ahora.asignadoSinOferta && antes?.asignadoSinOferta != true) return context.go(Rutas.viajeAsignado);
      final oferta = ahora.oferta;
      if (oferta != null && oferta.id != antes?.oferta?.id) return context.go(Rutas.ofertaChofer);
      final id = ahora.viaje?.id;
      if (id != null && id != antes?.viaje?.id) context.go(Rutas.viajeChofer);
    });
    final turno = ref.watch(turnoProvider);

    if (turno.hasValue) {
      final t = turno.value;
      if (t == null) return const IniciarTurno();
      // "Atrás" en el mapa cerraría el módulo sin preguntar: pasa por la misma confirmación que la X.
      return PopScope(
        canPop: false,
        onPopInvokedWithResult: (cerro, _) {
          if (!cerro) cerrarModuloChofer(context, ref);
        },
        child: MapaChofer(turno: t),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Vehículos oficiales'), leading: const BotonCerrarModulo()),
      body: Center(
        child: turno.hasError
            ? Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(mensajeDeError(turno.error!)),
                  TextButton(onPressed: () => ref.invalidate(turnoProvider), child: const Text('Reintentar')),
                ],
              )
            : const CircularProgressIndicator(),
      ),
    );
  }
}

/// Cierra el módulo. Con el turno abierto pregunta antes: el GPS vive con el módulo y se corta al cerrarlo.
Future<void> cerrarModuloChofer(BuildContext context, WidgetRef ref) async {
  final cerrar = ref.read(cerrarModuloProvider);
  if (ref.read(turnoProvider).value == null) return cerrar();
  final confirma = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('Tu turno sigue abierto'),
      content: const Text(
        'Si cerrás Vehículos oficiales dejás de compartir tu ubicación hasta que lo vuelvas a abrir. '
        'Para terminar el día usá "Finalizar turno".',
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Seguir acá')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Cerrar igual')),
      ],
    ),
  );
  if (confirma == true) cerrar();
}

class BotonCerrarModulo extends ConsumerWidget {
  const BotonCerrarModulo({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) =>
      IconButton(icon: const Icon(Icons.close), tooltip: 'Cerrar', onPressed: () => cerrarModuloChofer(context, ref));
}
```

- [ ] **Step 7: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **211 PASS**, sin problemas, `0 changed`. Los tests de la cuenta regresiva no dejan pasar el tiempo entre que llega la oferta y se mira el texto (`tester.pump()` sin duración): así "12 s" y "10 s" son exactos.

- [ ] **Step 8: Commit**

```bash
git add paquete
git commit -m "feat: oferta entrante a pantalla completa con cuenta regresiva del servidor" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Agenda

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/chofer/agenda.dart`, `lib/src/ui/chofer/agenda.dart`
- Modify: `lib/src/viaje/viaje_actual.dart` (`salirHaciaReserva`), `lib/src/ui/modulo_app.dart` (ruta `/chofer/agenda`), `lib/src/ui/chofer/mapa_chofer.dart` (acceso a la agenda y próxima reserva), `test/soporte/montar_chofer.dart` (`agenda`)
- Test: `test/ui/agenda_test.dart`

**Interfaces:**
- Consumes: `apiProvider.agenda/aceptarOferta/rechazarOferta`, `pushModuloProvider.avisos`, `tiempoRealProvider.canal(chofer.{id})`, `usuarioProvider`, `ViajeActualNotifier.salirHaciaReserva`.
- Produces:
  - En `ViajeActualNotifier`: `Future<void> salirHaciaReserva(Viaje reserva)` (`POST /viajes/{id}/estado` `en_camino`; la reserva pasa a ser el viaje actual).
  - `agendaProvider` (`AsyncNotifierProvider.autoDispose<AgendaNotifier, Agenda>`; se invalida con avisos `oferta_reserva`, `recordatorio_reserva`, `alerta_reserva`, `viaje` y con eventos de reservas en `chofer.{id}`), con `aceptar(Oferta)`, `rechazar(Oferta)` y `salir(Viaje)` (C14).
  - `AgendaPantalla` (`/chofer/agenda`, `Rutas.agenda`) y `TarjetaReserva` (también en el mapa, "Próxima reserva"), decisiones 10 y 11, C13.

- [ ] **Step 1: Montaje de prueba con agenda**

`paquete/vehiculos_oficiales/test/soporte/montar_chofer.dart` queda así (`agenda` como parámetro, vacía por defecto):

```dart
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import 'dobles.dart';
import 'entorno_prueba.dart';
import 'montar.dart';

/// `POST /auth/intercambio` de un chofer (id 2).
const intercambioChofer =
    '{"token":"2|x","usuario":{"id":2,"nombre":"Carlos G\\u00f3mez","cargo":"Chofer","rol":"chofer"}}';

/// Entorno HTTP de un chofer: sesión, configuración, [viajeActual] (por defecto sin viaje ni oferta), él
/// mismo libre en el mapa, [agenda] (por defecto vacía) y con o sin turno abierto. Cada test agrega lo suyo.
EntornoPrueba entornoChofer({
  bool conTurno = true,
  String viajeActual = p.viajeActualVacio,
  String agenda = '{"reservas":[],"solicitudes":[]}',
}) {
  final e = EntornoPrueba(tokenPJ: 'sim|200|Carlos Chofer|Chofer');
  e.http
    ..responder('POST', 'auth/intercambio', 200, intercambioChofer)
    ..responder('GET', 'configuracion', 200, p.configuracion)
    ..responder('GET', 'viajes/actual', 200, viajeActual)
    ..responder('GET', 'choferes', 200, p.choferes)
    ..responder('GET', 'turnos/actual', 200, conTurno ? c.turnoActual : c.sinTurno)
    ..responder('GET', 'agenda', 200, agenda)
    ..responder('POST', 'ubicacion', 204);
  return e;
}

/// Abre el módulo como chofer, con el GPS falso.
Future<void> montarChofer(
  WidgetTester tester,
  EntornoPrueba e, {
  TiempoRealFalso? tiempoReal,
  UbicadorFalso? ubicador,
  List<Override> extra = const [],
}) => montarModulo(
  tester,
  e,
  tiempoReal: tiempoReal,
  extra: [ubicadorProvider.overrideWithValue(ubicador ?? UbicadorFalso()), ...extra],
);

/// Rutas de los pedidos hechos, sin `/api/`, con su método (p. ej. `POST turnos`).
List<String> pedidosHechos(EntornoPrueba e) => [
  for (final r in e.http.pedidos) '${r.metodo} ${r.uri.path.replaceFirst('/api/', '')}',
];
```

- [ ] **Step 2: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/ui/agenda_test.dart`:

```dart
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/agenda.dart';
import 'package:vehiculos_oficiales/src/ui/chofer/viaje_chofer.dart';

import '../fixtures/payloads.dart' as p;
import '../fixtures/payloads_chofer.dart' as c;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';
import '../soporte/montar_chofer.dart';

/// Agenda con la reserva confirmada 2 y la solicitud 3 (del fixture).
String agendaCompleta() {
  final a = p.json(c.agenda)..['reservas'] = [p.json(c.reservaConfirmada)];
  return jsonEncode(a);
}

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;

  setUp(() {
    e = entornoChofer(agenda: agendaCompleta());
    tr = TiempoRealFalso();
  });

  int consultasAgenda() => pedidosHechos(e).where((r) => r == 'GET agenda').length;

  Future<void> abrirAgenda(WidgetTester tester) async {
    await montarChofer(tester, e, tiempoReal: tr);
    await tester.tap(find.byTooltip('Agenda'));
    await esperar(tester);
    expect(find.byType(AgendaPantalla), findsOneWidget);
  }

  testWidgets('muestra solicitudes con su vencimiento y reservas confirmadas', (tester) async {
    await abrirAgenda(tester);

    expect(find.textContaining('Casa de Gobierno'), findsNWidgets(2));
    expect(find.textContaining('Responder antes de'), findsOneWidget);
    expect(find.textContaining('~19 min'), findsOneWidget);
    expect(find.text('Aceptar'), findsOneWidget);
    expect(find.text('Rechazar'), findsOneWidget);
    expect(find.text('Voy en camino'), findsOneWidget);
  });

  testWidgets('aceptar una solicitud llama a la API y recarga', (tester) async {
    e.http.responder('POST', 'ofertas/3/aceptar', 200, c.reservaConfirmada);

    await abrirAgenda(tester);
    final antes = consultasAgenda();
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/3/aceptar'));
    expect(consultasAgenda(), antes + 1);
  });

  testWidgets('rechazar una solicitud llama a la API y recarga', (tester) async {
    e.http.responder('POST', 'ofertas/3/rechazar', 204);

    await abrirAgenda(tester);
    final antes = consultasAgenda();
    await tester.tap(find.text('Rechazar'));
    await esperar(tester);

    expect(pedidosHechos(e), contains('POST ofertas/3/rechazar'));
    expect(consultasAgenda(), antes + 1);
  });

  testWidgets('una solicitud que se superpone muestra el 422 y recarga', (tester) async {
    e.http.responder('POST', 'ofertas/3/aceptar', 422, c.reservaNoDisponible);

    await abrirAgenda(tester);
    final antes = consultasAgenda();
    await tester.tap(find.text('Aceptar'));
    await esperar(tester);

    expect(find.text('La reserva ya no está disponible o se superpone con otra de tu agenda.'), findsOneWidget);
    expect(consultasAgenda(), antes + 1);
  });

  testWidgets('un push oferta_reserva y un evento de una reserva recargan; uno de un inmediato no', (tester) async {
    await abrirAgenda(tester);
    final antes = consultasAgenda();

    e.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'oferta_reserva', 'oferta_id': '4'});
    await esperar(tester);
    expect(consultasAgenda(), antes + 1);

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(c.reservaConfirmada)..['estado'] = 'cancelado');
    await esperar(tester);
    expect(consultasAgenda(), antes + 2);

    tr.emitir('chofer.2', Eventos.viajeActualizado, p.json(p.viajeOfrecido)..['estado'] = 'cancelado');
    await esperar(tester);
    expect(consultasAgenda(), antes + 2);
  });

  testWidgets('el mapa destaca la próxima reserva; "Voy en camino" antes de tiempo muestra el 422', (tester) async {
    e.http.responder('POST', 'viajes/2/estado', 422, c.reservaAntesDeTiempo);

    await montarChofer(tester, e, tiempoReal: tr);
    expect(find.textContaining('Próxima reserva'), findsOneWidget);

    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(find.text('Podés salir hacia esta reserva a partir de las 11:15.'), findsOneWidget);
  });

  testWidgets('"Voy en camino" a tiempo: la reserva pasa a ser el viaje actual', (tester) async {
    e.http.responder('POST', 'viajes/2/estado', 200, jsonEncode(p.json(c.reservaConfirmada)..['estado'] = 'en_camino'));

    await abrirAgenda(tester);
    await tester.tap(find.text('Voy en camino'));
    await esperar(tester);

    expect(jsonDecode(e.http.pedidos.last.cuerpo), {'estado': 'en_camino'});
    expect(find.byType(ViajeChofer), findsOneWidget);
    expect(find.text('Llegué'), findsOneWidget);
  });
}
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test test/ui/agenda_test.dart`
Expected: FAIL de compilación (no existe `ui/chofer/agenda.dart`).

- [ ] **Step 4: Salir hacia una reserva**

En `paquete/vehiculos_oficiales/lib/src/viaje/viaje_actual.dart`, antes de `aceptarOferta`:

```dart
  /// Chofer: "Voy en camino" hacia una reserva confirmada de la agenda (spec 5.4, paso 7). Con la respuesta
  /// la reserva pasa a ser el viaje actual. El 422 ("Podés salir hacia esta reserva a partir de las 11:15.")
  /// llega a la pantalla.
  Future<void> salirHaciaReserva(Viaje reserva) async {
    final v = await ref.read(apiProvider).avanzarViaje(reserva.id, EstadoViaje.enCamino);
    if (ref.mounted) _aplicarViaje(v);
  }
```

- [ ] **Step 5: Agenda**

`paquete/vehiculos_oficiales/lib/src/chofer/agenda.dart`:

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../push/push_modulo.dart';
import '../sesion/sesion.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';
import '../viaje/viaje_actual.dart';

/// Avisos push que cambian la agenda (AvisosViaje, AvisosReserva y los jobs de reservas).
const _avisosDeAgenda = {'oferta_reserva', 'recordatorio_reserva', 'alerta_reserva', 'viaje'};

final agendaProvider = AsyncNotifierProvider.autoDispose<AgendaNotifier, Agenda>(AgendaNotifier.new);

/// Spec 7, chofer 5: `GET /agenda`, que se vuelve a pedir con los avisos push de reservas y
/// con cualquier evento de una reserva en `chofer.{id}` (solicitud nueva, reserva cancelada o reasignada).
class AgendaNotifier extends AsyncNotifier<Agenda> {
  @override
  Future<Agenda> build() async {
    final usuario = ref.watch(usuarioProvider);
    final avisos = ref.watch(pushModuloProvider).avisos.listen((a) {
      if (_avisosDeAgenda.contains(a.tipo)) ref.invalidateSelf();
    });
    final canal = ref.watch(tiempoRealProvider).canal(Canales.chofer(usuario.id)).listen((e) {
      if (_esDeReserva(e.datos)) ref.invalidateSelf();
    });
    ref.onDispose(() {
      unawaited(avisos.cancel());
      unawaited(canal.cancel());
    });
    return ref.read(apiProvider).agenda();
  }

  /// `viaje.actualizado` trae el viaje; `oferta.creada`, `{oferta_id, vence_en, viaje}`. Se mira solo el
  /// tipo, sin leer el resto: un evento raro no puede romper nada.
  static bool _esDeReserva(Json datos) {
    final viaje = datos['viaje'];
    final tipo = viaje is Map ? viaje['tipo'] : datos['tipo'];
    return tipo == TipoViaje.reserva.name;
  }

  /// Acepta una solicitud de reserva. 422 si ya no está disponible o se superpone con otra: la agenda se
  /// recarga igual y el error llega a la pantalla.
  Future<void> aceptar(Oferta solicitud) => _responder(() => ref.read(apiProvider).aceptarOferta(solicitud.id));

  Future<void> rechazar(Oferta solicitud) => _responder(() => ref.read(apiProvider).rechazarOferta(solicitud.id));

  /// "Voy en camino" hacia una reserva confirmada (spec 5.4, paso 7): la reserva pasa a ser el viaje actual.
  Future<void> salir(Viaje reserva) => ref.read(viajeActualProvider.notifier).salirHaciaReserva(reserva);

  Future<void> _responder(Future<Object?> Function() pedido) async {
    try {
      await pedido();
    } on ErrorNegocio {
      if (ref.mounted) ref.invalidateSelf();
      rethrow;
    }
    if (ref.mounted) ref.invalidateSelf();
  }
}
```

`paquete/vehiculos_oficiales/lib/src/ui/chofer/agenda.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../api/errores_api.dart';
import '../../chofer/agenda.dart';
import '../../modelos/modelos.dart';
import '../comunes/comunes.dart';

/// Spec 7, chofer 5: reservas confirmadas y solicitudes de reserva por responder.
class AgendaPantalla extends ConsumerWidget {
  const AgendaPantalla({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final agenda = ref.watch(agendaProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Agenda')),
      body: switch (agenda) {
        AsyncData(:final value) => RefreshIndicator(
          onRefresh: () => ref.refresh(agendaProvider.future),
          child: ListView(
            children: [
              const _Titulo('Solicitudes'),
              if (value.solicitudes.isEmpty) const ListTile(title: Text('No tenés solicitudes pendientes.')),
              for (final s in value.solicitudes) _Solicitud(solicitud: s),
              const _Titulo('Reservas confirmadas'),
              if (value.reservas.isEmpty) const ListTile(title: Text('No tenés reservas confirmadas.')),
              for (final r in value.reservas) TarjetaReserva(reserva: r),
            ],
          ),
        ),
        AsyncError(:final error) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(mensajeDeError(error)),
              TextButton(onPressed: () => ref.invalidate(agendaProvider), child: const Text('Reintentar')),
            ],
          ),
        ),
        _ => const Center(child: CircularProgressIndicator()),
      },
    );
  }
}

class _Titulo extends StatelessWidget {
  const _Titulo(this.texto);

  final String texto;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
    child: Text(texto, style: Theme.of(context).textTheme.titleMedium),
  );
}

/// Ejecuta una acción de la agenda mostrando el error del backend, si lo hay.
Future<void> _accionAgenda(BuildContext context, Future<void> Function() accion) async {
  try {
    await accion();
  } on ErrorApi catch (e) {
    if (context.mounted) mostrarError(context, e);
  }
}

class _Solicitud extends ConsumerWidget {
  const _Solicitud({required this.solicitud});

  final Oferta solicitud;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final viaje = solicitud.viaje;
    final agenda = ref.read(agendaProvider.notifier);
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            leading: const Icon(Icons.event_note),
            title: Text('${formatearFechaHora(viaje.programadoPara!)} · ${viaje.destino.descripcion}'),
            subtitle: Text(
              [
                viaje.solicitante.nombre,
                if (viaje.duracionEstimadaMin case final min?) '~$min min',
                'Responder antes de ${formatearFechaHora(solicitud.venceEn)}',
              ].join(' · '),
            ),
          ),
          OverflowBar(
            alignment: MainAxisAlignment.end,
            children: [
              TextButton(
                onPressed: () => _accionAgenda(context, () => agenda.rechazar(solicitud)),
                child: const Text('Rechazar'),
              ),
              FilledButton(
                onPressed: () => _accionAgenda(context, () => agenda.aceptar(solicitud)),
                child: const Text('Aceptar'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Una reserva confirmada con "Voy en camino" (el backend decide si ya se puede salir). También la usa el
/// mapa del chofer para destacar la próxima.
class TarjetaReserva extends ConsumerWidget {
  const TarjetaReserva({super.key, required this.reserva, this.titulo});

  final Viaje reserva;

  /// Encabezado opcional (p. ej. "Próxima reserva").
  final String? titulo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final agenda = ref.read(agendaProvider.notifier);
    return Card(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          ListTile(
            leading: const Icon(Icons.event_available),
            title: Text(
              [?titulo, formatearFechaHora(reserva.programadoPara!), reserva.destino.descripcion].join(' · '),
            ),
            subtitle: Text('${reserva.solicitante.nombre} · Desde ${reserva.origen.descripcion}'),
          ),
          OverflowBar(
            alignment: MainAxisAlignment.end,
            children: [
              FilledButton.tonal(
                onPressed: () => _accionAgenda(context, () => agenda.salir(reserva)),
                child: const Text('Voy en camino'),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
```

- [ ] **Step 6: Ruta y mapa**

`paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart` queda así:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:go_router/go_router.dart';

import '../entorno.dart';
import '../push/push_modulo.dart';
import '../sesion/sesion.dart';
import 'chofer/agenda.dart';
import 'chofer/inicio_chofer.dart';
import 'chofer/pantalla_oferta.dart';
import 'chofer/viaje_asignado.dart';
import 'chofer/viaje_chofer.dart';
import 'sesion/pantalla_inicio.dart';
import 'solicitante/inicio_solicitante.dart';
import 'solicitante/mis_viajes.dart';
import 'solicitante/pantalla_reserva.dart';
import 'solicitante/pantalla_viaje.dart';

/// Cierra el módulo y vuelve a la app principal. Lo define [ModuloVehiculos].
final cerrarModuloProvider = Provider<VoidCallback>((ref) => () {});

abstract final class Rutas {
  static const inicio = '/';
  static const solicitante = '/solicitante';
  static const viaje = '/solicitante/viaje';
  static const reservar = '/solicitante/reservar';
  static const misViajes = '/solicitante/mis-viajes';
  static const chofer = '/chofer';
  static const viajeChofer = '/chofer/viaje';
  static const ofertaChofer = '/chofer/oferta';
  static const viajeAsignado = '/chofer/asignado';
  static const agenda = '/chofer/agenda';
}

/// Raíz del módulo: su propio `ProviderScope` y su propio router (no toca los de la app principal).
///
/// Se queda con el [entorno], [alCerrar] y [overrides] de la primera construcción: la app principal puede
/// reconstruir la ruta (cambio de tema, de idioma…) y un entorno nuevo reiniciaría todo el módulo
/// (cliente HTTP sin token, sesión otra vez "iniciando", otro socket). Del contexto solo toma el tema.
class ModuloVehiculos extends StatefulWidget {
  const ModuloVehiculos({super.key, required this.entorno, required this.alCerrar, this.overrides = const []});

  final EntornoModulo entorno;
  final VoidCallback alCerrar;

  /// Solo para tests (HTTP falso, Reverb falso, mapa de prueba…).
  @visibleForTesting
  final List<Override> overrides;

  @override
  State<ModuloVehiculos> createState() => _ModuloVehiculosState();
}

class _ModuloVehiculosState extends State<ModuloVehiculos> {
  late final List<Override> _overrides = [
    entornoProvider.overrideWithValue(widget.entorno),
    cerrarModuloProvider.overrideWithValue(widget.alCerrar),
    ...widget.overrides,
  ];

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      // Riverpod 3 reintenta por defecto los providers que fallan; acá los errores se muestran y se
      // reintentan a mano o por el respaldo de 10 s.
      retry: (_, _) => null,
      overrides: _overrides,
      child: _RaizModulo(tema: Theme.of(context)),
    );
  }
}

class _RaizModulo extends ConsumerStatefulWidget {
  const _RaizModulo({required this.tema});

  final ThemeData tema;

  @override
  ConsumerState<_RaizModulo> createState() => _RaizModuloState();
}

class _RaizModuloState extends ConsumerState<_RaizModulo> {
  final _cambiosDeSesion = ValueNotifier<int>(0);
  late final GoRouter _router;

  @override
  void initState() {
    super.initState();
    ref.listenManual(sesionProvider, (_, _) => _cambiosDeSesion.value++);
    _router = GoRouter(
      refreshListenable: _cambiosDeSesion,
      redirect: (context, estado) {
        final sesion = ref.read(sesionProvider);
        final enInicio = estado.matchedLocation == Rutas.inicio;
        if (sesion is! SesionLista) return enInicio ? null : Rutas.inicio;
        if (enInicio) return sesion.usuario.esChofer ? Rutas.chofer : Rutas.solicitante;
        return null;
      },
      routes: [
        GoRoute(path: Rutas.inicio, builder: (_, _) => const PantallaInicio()),
        ShellRoute(
          builder: (_, _, child) => _ConSesion(child: child),
          routes: [
            GoRoute(
              path: Rutas.solicitante,
              builder: (_, _) => const InicioSolicitante(),
              routes: [
                GoRoute(path: 'viaje', builder: (_, _) => const PantallaViaje()),
                GoRoute(
                  path: 'reservar',
                  builder: (_, estado) => PantallaReserva(fechaInicial: estado.extra as DateTime?),
                ),
                GoRoute(path: 'mis-viajes', builder: (_, _) => const MisViajesPantalla()),
              ],
            ),
            GoRoute(
              path: Rutas.chofer,
              builder: (_, _) => const InicioChofer(),
              routes: [
                GoRoute(path: 'viaje', builder: (_, _) => const ViajeChofer()),
                GoRoute(path: 'oferta', builder: (_, _) => const PantallaOferta()),
                GoRoute(path: 'asignado', builder: (_, _) => const ViajeAsignado()),
                GoRoute(path: 'agenda', builder: (_, _) => const AgendaPantalla()),
              ],
            ),
          ],
        ),
      ],
    );
  }

  @override
  void dispose() {
    _router.dispose();
    _cambiosDeSesion.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // El "atrás" del sistema lo recibe primero el Navigator de la app principal, que cerraría todo el
    // módulo. Se bloquea ese pop y se le pasa al router interno (diálogos, hojas y rutas del módulo);
    // solo si ya no hay nada que cerrar adentro, se cierra el módulo.
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (yaCerro, _) async {
        if (yaCerro) return;
        if (!await _router.routerDelegate.popRoute()) ref.read(cerrarModuloProvider)();
      },
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        title: 'Vehículos oficiales',
        theme: widget.tema,
        locale: const Locale('es', 'AR'),
        supportedLocales: const [Locale('es', 'AR'), Locale('es')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        routerConfig: _router,
      ),
    );
  }
}

/// Pantallas con sesión lista: activa el puente push (registro del token y avisos).
class _ConSesion extends ConsumerStatefulWidget {
  const _ConSesion({required this.child});

  final Widget child;

  @override
  ConsumerState<_ConSesion> createState() => _ConSesionState();
}

class _ConSesionState extends ConsumerState<_ConSesion> {
  @override
  void initState() {
    super.initState();
    ref.read(pushModuloProvider);
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
```

`paquete/vehiculos_oficiales/lib/src/ui/chofer/mapa_chofer.dart` queda así (acción "Agenda" y "Próxima reserva" cuando no hay viaje):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../api/errores_api.dart';
import '../../chofer/agenda.dart';
import '../../chofer/turno.dart';
import '../../entorno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../sesion/sesion.dart';
import '../../solicitante/choferes_mapa.dart';
import '../../ubicacion/ubicador.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';
import 'agenda.dart';
import 'inicio_chofer.dart';

/// Spec 7, chofer 2 y 6: su posición, su estado, el vehículo del turno, la próxima reserva
/// confirmada, el acceso a la agenda y "Finalizar turno".
class MapaChofer extends ConsumerWidget {
  const MapaChofer({super.key, required this.turno});

  final Turno turno;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final posicion = ref.watch(posicionPropiaProvider);
    final usuario = ref.watch(usuarioProvider);
    // El estado lo calcula el backend (spec 4.1) y llega en el mismo listado que ve el solicitante.
    final yo = ref.watch(choferesMapaProvider).value?.where((c) => c.id == usuario.id).firstOrNull;
    final mapa = ref.watch(constructorMapaProvider);
    final config = ref.watch(entornoProvider).config;
    final aqui = posicion.punto?.posicion;
    final texto = Theme.of(context).textTheme;
    final hayViaje = ref.watch(viajeActualProvider).value?.viaje != null;
    // La agenda llega ordenada por fecha: la primera reserva confirmada es la próxima.
    final proxima = ref.watch(agendaProvider).value?.reservas.firstOrNull;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: const BotonCerrarModulo(),
        actions: [
          IconButton(icon: const Icon(Icons.event), tooltip: 'Agenda', onPressed: () => context.push(Rutas.agenda)),
        ],
      ),
      body: Column(
        children: [
          const BannerConexion(),
          if (hayViaje)
            MaterialBanner(
              content: const Text('Tenés un viaje en curso.'),
              actions: [TextButton(onPressed: () => context.go(Rutas.viajeChofer), child: const Text('Ver'))],
            ),
          if (posicion.sinGps)
            MaterialBanner(
              leading: const Icon(Icons.gps_off),
              content: const Text(
                'No podemos obtener tu ubicación. Revisá que la ubicación del teléfono esté activa y el permiso '
                'concedido.',
              ),
              actions: [
                TextButton(
                  onPressed: () => ref.read(ubicadorProvider).abrirAjustes(PermisoUbicacion.denegado),
                  child: const Text('Abrir ajustes'),
                ),
                TextButton(
                  onPressed: () => ref.read(turnoProvider.notifier).reintentarGps(),
                  child: const Text('Reintentar'),
                ),
              ],
            ),
          Expanded(
            child: mapa(
              context,
              DatosMapa(
                centro: aqui ?? Coordenada(config.centroMapaLat, config.centroMapaLng),
                marcadores: [
                  if (aqui != null)
                    MarcadorMapa(id: 'yo', posicion: aqui, tipo: TipoMarcador.choferAsignado, titulo: 'Vos'),
                ],
              ),
            ),
          ),
          if (proxima != null && !hayViaje) TarjetaReserva(reserva: proxima, titulo: 'Próxima reserva'),
          Material(
            elevation: 8,
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(yo?.estado.texto ?? 'En turno', style: texto.titleLarge),
                  if (turno.vehiculo case final v?) Text([v.descripcion, ?v.color].join(' · ')),
                  if (aqui == null && !posicion.sinGps) const Text('Buscando tu ubicación…'),
                  const SizedBox(height: 16),
                  OutlinedButton(onPressed: () => _finalizar(context, ref), child: const Text('Finalizar turno')),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _finalizar(BuildContext context, WidgetRef ref) async {
    final confirma = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('¿Finalizar el turno?'),
        content: const Text('Se deja de compartir tu ubicación y no vas a recibir viajes.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, finalizar')),
        ],
      ),
    );
    if (confirma != true || !context.mounted) return;
    try {
      await ref.read(turnoProvider.notifier).finalizar();
    } on ErrorApi catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }
}
```

- [ ] **Step 7: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **218 PASS**, sin problemas, `0 changed`.

- [ ] **Step 8: Commit**

```bash
git add paquete
git commit -m "feat: agenda del chofer con reservas y solicitudes" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Backend: recorrido sin puntos duplicados

**Files:**
- Create: `backend/database/migrations/2026_09_30_000001_indice_unico_recorrido_viaje.php`
- Modify: `backend/app/Servicios/ServicioUbicacion.php`
- Test: `backend/tests/Feature/UbicacionTest.php` (3 tests nuevos)

**Interfaces:**
- Consumes: tabla `recorrido_viaje` (`viaje_id`, `lat`, `lng`, `registrado_en` timestamp NOT NULL `useCurrent()`), `PuntoRecorrido`, `ServicioUbicacion::registrar`.
- Produces: índice único `(viaje_id, registrado_en)`; `registrar` inserta los puntos del recorrido con `insertOrIgnore` (un lote reenviado, o un punto repetido dentro del lote, no duplica ni responde 500). Decisión 5 y C21. Comandos desde `backend/`.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar al final de `backend/tests/Feature/UbicacionTest.php`:

```php
it('no duplica el recorrido si la app reenvía un lote', function () {
    $turno = Turno::factory()->create();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $turno->chofer_id, 'estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subMinutes(5),
    ]);
    // Como lo manda la app: UTC con milisegundos.
    $lote = ['puntos' => [
        ['lat' => -34.60, 'lng' => -58.30, 'registrado_en' => now()->subSeconds(20)->format('Y-m-d\TH:i:s.v\Z')],
        ['lat' => -34.61, 'lng' => -58.31, 'registrado_en' => now()->subSeconds(10)->format('Y-m-d\TH:i:s.v\Z')],
    ]];

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', $lote)->assertNoContent();
    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', $lote)->assertNoContent();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(2);

    // Un lote con lo viejo (sin confirmar) más lo nuevo, y un punto repetido dentro del mismo lote.
    $lote['puntos'][] = ['lat' => -34.62, 'lng' => -58.32, 'registrado_en' => now()->format('Y-m-d\TH:i:s.v\Z')];
    $lote['puntos'][] = end($lote['puntos']);
    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', $lote)->assertNoContent();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->orderBy('registrado_en')->pluck('lat')->all())
        ->toBe([-34.60, -34.61, -34.62]);
});

it('el mismo momento en dos viajes distintos no choca', function () {
    $viajes = Viaje::factory()->count(2)->create();
    $momento = now()->subMinute();

    foreach ($viajes as $v) {
        PuntoRecorrido::insertOrIgnore([['viaje_id' => $v->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => $momento]]);
    }

    expect(PuntoRecorrido::count())->toBe(2);
});

it('la migración del índice único deja un solo punto por viaje y momento', function () {
    $migracion = require database_path('migrations/2026_09_30_000001_indice_unico_recorrido_viaje.php');
    $migracion->down();

    $viaje = Viaje::factory()->create();
    $momento = now()->subMinute()->startOfSecond();
    Illuminate\Support\Facades\DB::table('recorrido_viaje')->insert([
        ['viaje_id' => $viaje->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => $momento],
        ['viaje_id' => $viaje->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => $momento],
        ['viaje_id' => $viaje->id, 'lat' => 2, 'lng' => 2, 'registrado_en' => $momento->copy()->addSeconds(5)],
    ]);

    $migracion->up();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(2);
    expect(fn () => PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => 3, 'lng' => 3, 'registrado_en' => $momento]))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
```

- [ ] **Step 2: Correr y ver que fallan**

Run: `./vendor/bin/pest tests/Feature/UbicacionTest.php`
Expected: 2 FAIL ("no duplica el recorrido…": hay 4 puntos en vez de 2; "la migración del índice único…": no existe el archivo). Solo con la migración (sin el Step 4), el reenvío responde 500 (`UniqueConstraintViolationException`).

- [ ] **Step 3: Migración**

`backend/database/migrations/2026_09_30_000001_indice_unico_recorrido_viaje.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un punto del recorrido por viaje y momento: si la app reenvía un lote porque no le llegó el 204,
 * los puntos repetidos se ignoran en vez de duplicarse.
 * `registrado_en` no se toca (sigue NOT NULL con useCurrent()).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los repetidos que ya existan (de reenvíos anteriores) impedirían crear el índice: se deja el primero.
        $repetidos = DB::table('recorrido_viaje')
            ->select('viaje_id', 'registrado_en', DB::raw('MIN(id) as conservar'))
            ->groupBy('viaje_id', 'registrado_en')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($repetidos as $r) {
            DB::table('recorrido_viaje')
                ->where('viaje_id', $r->viaje_id)
                ->where('registrado_en', $r->registrado_en)
                ->where('id', '!=', $r->conservar)
                ->delete();
        }

        Schema::table('recorrido_viaje', function (Blueprint $t) {
            $t->unique(['viaje_id', 'registrado_en']);
        });
    }

    public function down(): void
    {
        Schema::table('recorrido_viaje', function (Blueprint $t) {
            $t->dropUnique(['viaje_id', 'registrado_en']);
        });
    }
};
```

- [ ] **Step 4: Inserción idempotente**

`backend/app/Servicios/ServicioUbicacion.php` queda así (cambia el bloque del recorrido al final de `registrar`):

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Excepciones\ReglaNegocio;
use App\Models\PuntoRecorrido;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Support\HoraLocal;

class ServicioUbicacion
{
    /** @param array<int, array{lat: float, lng: float, rumbo?: ?float, velocidad?: ?float, registrado_en: string}> $puntos */
    public function registrar(Usuario $chofer, array $puntos): void
    {
        if (! $chofer->turnoAbierto()->exists()) {
            throw new ReglaNegocio('Iniciá un turno para compartir tu ubicación.');
        }

        $puntos = collect($puntos)
            ->map(fn (array $p) => [...$p, 'momento' => HoraLocal::interpretar($p['registrado_en'])->min(now())])
            ->sortBy('momento')
            ->values();
        $ultimo = $puntos->last();

        $actual = UbicacionChofer::find($chofer->id);
        if (! $actual || $actual->actualizado_en->lte($ultimo['momento'])) {
            UbicacionChofer::updateOrCreate(['chofer_id' => $chofer->id], [
                'lat' => $ultimo['lat'],
                'lng' => $ultimo['lng'],
                'rumbo' => $ultimo['rumbo'] ?? null,
                'velocidad' => $ultimo['velocidad'] ?? null,
                'actualizado_en' => $ultimo['momento'],
            ]);
            \App\Events\UbicacionChoferActualizada::dispatch(
                $chofer->id,
                (float) $ultimo['lat'],
                (float) $ultimo['lng'],
                isset($ultimo['rumbo']) ? (float) $ultimo['rumbo'] : null,
                $ultimo['momento']->toIso8601String(),
                Viaje::activosDeChofer($chofer->id)->value('id'),
            );
        }

        $enCurso = Viaje::where('chofer_id', $chofer->id)->where('estado', EstadoViaje::EnCurso)->first();
        if ($enCurso) {
            // Idempotente: un lote reenviado (la app no recibió el 204) no duplica puntos. El índice único
            // (viaje_id, registrado_en) descarta los que ya estaban, también dentro del mismo lote.
            $filas = $puntos->filter(fn ($p) => $p['momento']->gte($enCurso->iniciado_en))
                ->map(fn ($p) => [
                    'viaje_id' => $enCurso->id, 'lat' => $p['lat'], 'lng' => $p['lng'], 'registrado_en' => $p['momento'],
                ])
                ->values()
                ->all();
            if ($filas !== []) {
                PuntoRecorrido::insertOrIgnore($filas);
            }
        }
    }
}
```

- [ ] **Step 5: Correr la suite**

Run: `./vendor/bin/pest`
Expected: **321 passed** (318 + 3).

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: el recorrido del viaje no duplica puntos reenviados por la app" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Prueba punta a punta como chofer

**Files:**
- Ninguno, salvo que la prueba muestre algo que corregir (`host_prueba/android/app/src/main/AndroidManifest.xml` e `ios/Runner/Info.plist` ya tienen los permisos de ubicación, servicio en primer plano y notificaciones desde el plan anterior).

- [ ] **Step 1: Suites y compilación**

Run (desde `paquete/vehiculos_oficiales/`): `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **218 PASS**, sin problemas, `0 changed`.

Run (desde `host_prueba/`): `flutter pub get && flutter analyze && flutter test && flutter build web && flutter build apk --debug`
Expected: sin problemas, 3 PASS, `✓ Built build\web`, `✓ Built build\app\outputs\flutter-apk\app-debug.apk`.

Run (desde `backend/`): `./vendor/bin/pest`
Expected: 321 passed.

- [ ] **Step 2: Backend local**

En `backend/.env`: `IDENTIDAD_DRIVER=simulada`, `MAPAS_DRIVER=falso`, `BROADCAST_CONNECTION=reverb`, `QUEUE_CONNECTION=database` y las claves `REVERB_*` (`php artisan reverb:install` las genera). Después:

```bash
php artisan migrate
php artisan vehiculos:crear-admin admin@pj.local --nombre="Admin" --password=secreto
```

En tres consolas, desde `backend/`: `php artisan serve --host=0.0.0.0`, `php artisan reverb:start --host=0.0.0.0` y `php artisan queue:work`. El teléfono (o emulador: `10.0.2.2` es la máquina) y la PC tienen que estar en la misma red; en un teléfono físico usar la IP de la PC en `API_URL` y `REVERB_HOST`.

- [ ] **Step 3: El admin le da el rol de chofer a Carlos**

1. En el teléfono/emulador, `flutter run --dart-define=API_URL=http://10.0.2.2:8000 --dart-define=REVERB_HOST=10.0.2.2 --dart-define=REVERB_APP_KEY=<REVERB_APP_KEY del .env> --dart-define=MAPS_API_KEY=<clave>` desde `host_prueba/`. Entrar como **"Carlos Chofer"** (id externo 200) → Herramientas → Vehículos oficiales: entra como **solicitante** (el intercambio crea a todos así, A9 del plan anterior). Cerrar el módulo.
2. En el panel (`http://localhost:8000/admin`, `admin@pj.local` / `secreto`): **Usuarios** → Carlos Chofer → rol **chofer** → Guardar. **Vehículos** → Nuevo: patente `AB123CD`, Toyota Corolla, Blanco, activo. **Cargos prioritarios** → Nuevo: `Juez`.
3. Volver a abrir el módulo como Carlos: ahora aparece **"Iniciar turno"** con el vehículo.

- [ ] **Step 4: Turno, GPS en segundo plano y oferta**

1. Elegir `AB123CD` → "Iniciar turno". Primera vez: aceptar el permiso de ubicación ("mientras se usa la app") y, en Android 13+, el de notificaciones. Se ve el mapa con "Libre", el vehículo y el marcador "Vos". Probar antes "No permitir": tiene que aparecer el texto de spec 9 con "Abrir ajustes" y no iniciarse el turno.
2. Mandar la app a segundo plano: en la barra de notificaciones está fija **"Turno activo – compartiendo ubicación"**. En el panel (mapa en vivo) Carlos aparece y se mueve (en el emulador: *Extended controls → Location → Routes*, reproducir una ruta).
3. Desde Chrome (`flutter run -d chrome --dart-define=API_URL=http://localhost:8000 --dart-define=REVERB_HOST=localhost --dart-define=REVERB_APP_KEY=…`), entrar como **"Ana Pérez"** y "Pedir el más cercano" cerca de Carlos.
4. En el teléfono aparece la oferta a pantalla completa (vibra y suena) con la cuenta regresiva de `oferta_segundos` (30 s por defecto): comprobar que baja de a un segundo y que "atrás" no la cierra. Dejarla vencer una vez ("La oferta venció"; en el panel el viaje pasa a otro chofer o a `sin_chofer`). Pedir otra vez y **Aceptar**.
5. Recorrer "Voy en camino" → "Llegué" → "Iniciar viaje": en cada paso Ana ve el estado nuevo. "Navegar" → Google Maps abre la navegación al origen (antes de "Iniciar viaje") y al destino (después); probar también Waze si está instalado. "Llamar" solo aparece si Ana tiene teléfono en el PJ simulado (no lo tiene: no aparece).
6. Durante `en_curso`: activar el **modo avión 1 minuto** (el mapa del chofer sigue con su posición; aparece "Sin conexión en tiempo real. Actualizando cada 10 s."), volver a desactivarlo y esperar un ciclo. En el panel, **Viajes** → el viaje → sección **Recorrido**: "Puntos GPS" suma también los de ese minuto (~12 a 5 s). Sin repetidos: `php artisan tinker --execute="dump(DB::table('recorrido_viaje')->select('registrado_en')->groupBy('registrado_en')->havingRaw('count(*) > 1')->count())"` devuelve `0`. "Finalizar" → "Viaje finalizado" → "Volver al mapa".

- [ ] **Step 5: Obligatorio, cancelación, agenda y fin del turno**

1. Desde Chrome, entrar como **"Jorge Juez"** y pedir un viaje: en el teléfono aparece **"Viaje asignado"** ("Es un viaje obligatorio…") sin "Rechazar"; "Ver viaje" → no hay "Cancelar viaje". Terminarlo (o cancelarlo desde el panel: el chofer ve "El viaje fue cancelado").
2. Como Ana, pedir otro viaje; aceptarlo y "Cancelar viaje": el botón de confirmar está deshabilitado hasta escribir el motivo; al confirmar vuelve al mapa con "Cancelaste el viaje." y en el panel, el viaje → **Ofertas** muestra el rechazo de Carlos con ese motivo.
3. Como Ana, reservar para mañana eligiendo a Carlos: en el teléfono llega el push `oferta_reserva` (si la app del PJ tiene FCM; en `host_prueba`, "Simular push") y la **Agenda** muestra la solicitud con "Responder antes de …". Aceptarla: pasa a "Reservas confirmadas" y el mapa muestra "Próxima reserva". "Voy en camino" antes de tiempo muestra "Podés salir hacia esta reserva a partir de las …".
4. "Finalizar turno" con un viaje activo muestra "Finalizá el viaje en curso antes de cerrar el turno."; sin viaje, vuelve a "Iniciar turno" y la notificación fija **desaparece**. En el panel Carlos ya no está en el mapa.
5. Con el turno abierto, cerrar el módulo con "atrás" desde el mapa: aparece "Tu turno sigue abierto"; "Cerrar igual" corta el GPS (la notificación desaparece) y al volver a abrir el módulo el rastreo se retoma solo.

- [ ] **Step 6: Commit** (solo si la prueba obligó a corregir algo)

```bash
git add paquete host_prueba backend
git commit -m "fix: ajustes de la prueba punta a punta del chofer" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

## Cobertura del spec en este plan

| Spec | Task |
|---|---|
| 4.1 Estado del chofer (se muestra el calculado por el backend) | 6 |
| 5.1 / 5.2 Aceptar o rechazar una oferta; obligatorio asignado directo | 8 |
| 5.4 Reserva: aceptar/rechazar solicitudes; salir hacia la reserva (no antes de tiempo) | 7, 9 |
| 5.5 Pasos del viaje, navegación externa, recorrido en `en_curso` (sin duplicados) | 4, 5, 7, 10 |
| 5.6 Chofer cancela con motivo; obligatorio no; reasignación por el admin | 7, 8 |
| 6 Envío de ubicación por lotes; `chofer.{id}`; reconexión y sondeo | 4, 5, 7, 8 |
| 7 Chofer 1–6; GPS en segundo plano con notificación fija | 3, 5, 6, 7, 8, 9 |
| 9 Sin señal: guardar y enviar al reconectar; permiso denegado: no se inicia turno | 4, 5, 6 |
| 10 La ubicación solo se envía con turno abierto | 5, 6 |
| 11 Tests de providers y widgets de la oferta entrante; prueba manual | todas, 11 |

**Fuera de este plan:** `TurnoAsistencia` (spec 13), sonido propio de la oferta, persistencia de la cola en disco y GPS con el módulo cerrado (decisiones 5 y 9, C8: a confirmar con el equipo de la app del PJ), exponer `Date` por CORS para la web (C12).

