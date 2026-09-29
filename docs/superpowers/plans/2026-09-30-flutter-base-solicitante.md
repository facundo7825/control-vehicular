# App Flutter: base y solicitante — Vehículos Oficiales — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un solicitante abra "Vehículos oficiales" desde la app del Poder Judicial (simulada por `host_prueba`), entre con el token de sesión del PJ, vea en el mapa a los choferes en turno en tiempo real, pida el chofer más cercano o uno elegido, siga el viaje (buscando, chofer asignado, en camino, llegó, en curso, fin), lo cancele, reserve viajes a futuro y consulte sus viajes. Todo sobre la base que después usa el chofer (plan siguiente): sesión, cliente de la API, tiempo real con respaldo por sondeo, puente push, navegación propia del módulo y costura del mapa para los tests.

**Architecture:** Paquete Flutter `paquete/vehiculos_oficiales/` con un único punto de entrada, `VehiculosOficiales.abrir(context, sesion:, push:, onSesionInvalida:, config:)`, que empuja una ruta de pantalla completa en el `Navigator` de la app principal. Adentro vive un `ProviderScope` propio (Riverpod 3) y un `MaterialApp.router` propio (go_router): el módulo no toca el estado ni el router de la app principal. La sesión se obtiene con `POST /api/auth/intercambio` y el token Sanctum viaja en todos los pedidos (dio) y en la autorización de los canales privados de Reverb (`dart_pusher_channels`, `POST /api/broadcasting/auth`). Los datos vivos (viaje actual y choferes del mapa) se actualizan por eventos del socket; si el socket se cae se consulta la API cada 10 s y, al reconectar, se pide el estado completo (spec 6). Google Maps se dibuja detrás de un provider (`constructorMapaProvider`) que los tests reemplazan por una lista de botones. `host_prueba/` es una app Flutter que simula a la del PJ: login falso que arma el token `sim|<id>|<nombre>|<cargo>` y la sección "Herramientas".

**Tech Stack:** Flutter 3.41.6 / Dart 3.11.4, flutter_riverpod 3.3.2, go_router 17.5.0, dio 5.11.1, dart_pusher_channels 1.3.1, google_maps_flutter 2.18.1, geolocator 14.1.1, url_launcher 6.3.2, flutter_secure_storage 11.2.0, crypto 3.0.7, intl 0.20.2 (fijada por el SDK), clock 1.1.2, flutter_localizations (SDK); tests con flutter_test y fake_async 1.3.3. Backend: el Laravel de `backend/` (sin cambios en este plan).

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md` (secciones 2, 3, 5, 6, 7, 9, 10, 11 y 12)

**Planes anteriores (código sobre el que se apoya):** `docs/superpowers/plans/2026-09-28-backend-nucleo.md`, `2026-09-29-reservas.md` y `2026-09-29-panel-admin.md` (ya mergeados en `main`). **Plan siguiente:** `docs/superpowers/plans/2026-09-30-flutter-chofer.md` (turno, GPS, ofertas, viaje en curso y agenda del chofer).

**Verificación previa:** todo el código de este plan se escribió y se probó en un directorio aparte con la misma estructura (`paquete/vehiculos_oficiales` + `host_prueba`), con las versiones exactas de arriba: en el paquete `flutter analyze` sin problemas, `dart format` sin cambios y **84 tests en verde**; además se reconstruyeron los estados intermedios al final de las Tasks 8, 10 y 11 (58, 67 y 78 tests en verde). En `host_prueba`: `flutter analyze` sin problemas, 3 tests en verde, `flutter build web` correcto y ``flutter build apk --debug` correcto (la primera compilación instaló por su cuenta el NDK 28.2, las plataformas 35 y 36 del SDK y CMake 3.22.1, y tardó unos 9 minutos; las siguientes, unos 20 s)`. Los JSON de `test/fixtures/payloads.dart` no están escritos a mano: son respuestas reales del backend de `main`, obtenidas levantando la app Laravel sobre SQLite en memoria (`IDENTIDAD_DRIVER=simulada`, `MAPAS_DRIVER=falso`) y llamando a cada endpoint con un kernel HTTP.

## Decisiones

Tomadas antes de escribir el plan. El plan las implementa tal cual, salvo los ajustes que se detallan después. Las marcadas **(A confirmar con el equipo de la app del PJ)** dependen de ese equipo (spec 12): el plan sigue con la opción indicada y el cambio queda aislado en un solo lugar.

1. **Estructura (spec 3.1).** `paquete/vehiculos_oficiales/` (paquete Flutter, `publish_to: none`) y `host_prueba/` (app que depende del paquete por `path`). Único punto de entrada público: `VehiculosOficiales.abrir(context, {sesion, push, onSesionInvalida, config})`. Lo que exporta `lib/vehiculos_oficiales.dart` es solo `VehiculosOficiales`, `VehiculosOficialesConfig`, `SesionPJ` y `PuenteNotificaciones`; todo lo demás vive en `lib/src/`. `VehiculosOficialesConfig` lleva la URL del backend (sin `/api`), host, puerto, clave y esquema de Reverb, la clave de Google Maps (informativa: en Android e iOS se declara en el manifiesto / `Info.plist`) y el centro inicial del mapa (por defecto Buenos Aires; **A confirmar**: ciudad).
2. **Estado: `flutter_riverpod` 3.3.2** (la 3.4 exige Dart 3.12). Riverpod 3 es la versión estable actual; permite `ProviderContainer.test`, `ref.mounted` y `Notifier`/`AsyncNotifier` sin generación de código (no se usa `riverpod_generator` ni `build_runner`). Se desactiva el reintento automático de Riverpod 3 (`retry: (_, _) => null`): los errores se muestran y se reintentan a mano o por el sondeo de 10 s. El módulo crea su propio `ProviderScope`, así que no comparte estado con la app principal aunque ella también use Riverpod. **(A confirmar con el equipo de la app del PJ:** si usan Riverpod 2.x, las dos versiones no pueden convivir en el mismo `pubspec.lock`; la alternativa es bajar el paquete a `flutter_riverpod ^2.6` —cambios acotados: `ProviderContainer.test` → `ProviderContainer` con `addTearDown`, y sin `retry`/`ref.mounted`—.)
3. **Navegación: `go_router` 17.5.0** (la 18 exige Flutter 3.44) dentro de un `MaterialApp.router` propio del módulo, que hereda el `Theme` de la app principal y fija el idioma en español (`flutter_localizations`, para los selectores de fecha y hora). La app principal solo ve una ruta de pantalla completa.
4. **HTTP: `dio` 5.11.1** en lugar de `http`: timeouts, `HttpClientAdapter` reemplazable en los tests (se prueba el cliente real con un adaptador falso, sin mocks de métodos), `Options` por pedido y `DioException` con la respuesta para traducir 401/403/404/422/503. Todos los pedidos llevan `Accept: application/json` y, con sesión, `Authorization: Bearer <token Sanctum>`.
5. **Tiempo real: `dart_pusher_channels` 1.3.1.** Es Dart puro (Android, iOS y web, sobre `web_socket_channel`), acepta host/puerto/esquema propios (`PusherChannelsOptions.fromHost`) y permite un delegado de autorización propio: se implementa `AutorizacionCanalApi`, que firma los canales `private-…` con `POST /api/broadcasting/auth` **a través del mismo cliente dio**, así el token Sanctum es el de la sesión y un 401 ahí también dispara `onSesionInvalida`. Se descartó `pusher_channels_flutter` (SDK nativo de Pusher: no está pensado para un host propio ni funciona en web) y `laravel_echo` (sin mantenimiento para Dart 3).
6. **Mapas: `google_maps_flutter` 2.18.1** (la 2.18.2 exige Flutter 3.47) detrás de `constructorMapaProvider`: las pantallas arman un `DatosMapa` (centro, marcadores, toque en el mapa) y el provider decide cómo dibujarlo. En producción es `MapaGoogle`; en los tests, una lista de botones (un botón por marcador y uno que "toca" el mapa). Así ningún test instancia Google Maps. Colores: verde para los libres; para los demás (en viaje, sin señal, reservados pronto) un marcador amarillo semitransparente, porque los marcadores por defecto no tienen gris (**A confirmar:** íconos propios).
7. **Ubicación: `geolocator` 14.1.1.** En este plan solo para "Usar mi ubicación" como origen del pedido; si se niega el permiso, el solicitante marca el origen tocando el mapa (spec 9). El GPS en segundo plano del chofer, con la notificación "Turno activo – compartiendo ubicación" (`AndroidSettings.foregroundNotificationConfig`), es del plan siguiente.
8. **Sesión (spec 3.2 y 9).** La app principal pasa `SesionPJ(token)`. El módulo llama a `POST /api/auth/intercambio`, guarda el token Sanctum en memoria (en `ClienteApi.token`) y además en `flutter_secure_storage`, **asociado al SHA-256 del token del PJ**: si después el PJ responde 503 y el token Sanctum de *esa misma sesión* sigue vigente (`GET /api/yo`), el módulo sigue funcionando (spec 9); el token guardado de otra sesión u otro usuario del mismo dispositivo nunca se usa. Estados: iniciando, lista, identidad no disponible (503, con "Reintentar"), vencida (401), deshabilitada (403 "Usuario deshabilitado.") y error de conexión (con "Reintentar"). **Cualquier 401 de cualquier pedido** (API o autorización de canales) llama a `onSesionInvalida` **una sola vez** por apertura del módulo y borra el token guardado. **(A confirmar con el equipo de la app del PJ:** formato del token de sesión que se pasa —el backend ya tiene `EndpointPoderJudicial` configurable— y qué hace su app en `onSesionInvalida`.)
9. **Puente push (spec 12.3).** Interfaz `PuenteNotificaciones { Future<String?> token(); Stream<Map<String, dynamic>> get mensajes; }` que implementa la app principal. Con la sesión lista, el módulo registra el token con `POST /api/push/token` y escucha `mensajes`: solo toma los que traen `modulo = vehiculos_oficiales` (lo agrega `NotificadorFcm`; todos los valores llegan como string). `tipo = viaje` u `oferta` → vuelve a pedir el viaje actual; todos los avisos se publican tipados (`AvisoPush`) para que "Mis viajes" (y la agenda del chofer) se refresquen. `host_prueba` usa un puente falso sin Firebase (token nulo, con un botón para simular un push). **(A confirmar con el equipo de la app del PJ:** el cableado real de FCM, la renovación del token —la interfaz no la contempla; alcanza con volver a abrir el módulo— y que tocar una notificación del módulo abra `VehiculosOficiales.abrir`.)
10. **Tiempo real y respaldo (spec 6).** El viaje actual (`GET /api/viajes/actual`) escucha `viaje.{id}` (solicitante) o `chofer.{id}` (chofer) y el mapa escucha `mapa.choferes`. Una sola pieza, `Respaldo`, implementa la regla: con el socket en cualquier estado que no sea "conectado" consulta cada 10 s; al conectarse corta el sondeo y pide el estado completo una vez. Con el socket caído, la posición del chofer asignado se toma de `GET /api/choferes`. Si durante el sondeo el viaje desaparece de `viajes/actual` (que no devuelve viajes terminados), se busca en el historial (`GET /api/viajes`) para mostrar cómo terminó (por ejemplo `sin_chofer`). En pantalla se avisa "Sin conexión en tiempo real. Actualizando cada 10 s.".
11. **Pantallas del solicitante (spec 7).**
    - *Mapa:* choferes en turno (verde = libre; los demás, incluidos los "reservados pronto", se ven pero no se pueden elegir). Al tocar uno: nombre, vehículo, estado y "Pedir a este chofer" (deshabilitado si no está libre).
    - *Pedido (panel inferior fijo, no una hoja modal, para poder tocar el mapa mientras se arma):* origen y destino se marcan tocando el mapa (el primer toque es el origen y el segundo el destino; se puede elegir cuál marcar), "Usar mi ubicación" para el origen, direcciones escritas y motivo opcionales, "Pedir el más cercano" o "Pedir a <chofer>", y "Reservar para más tarde". **(A confirmar:** autocompletado de direcciones con Places, que necesita la API de Places habilitada y facturación; en v1 no se usa.)
    - *Buscando:* texto según el modo y "Cancelar pedido".
    - *Viaje activo:* mapa con origen, destino y chofer en vivo, estado, chofer, vehículo (marca, modelo, patente, color), "Llamar" (`tel:` con `url_launcher`, solo si el chofer tiene teléfono) y "Cancelar viaje" (antes de `en_curso`, spec 5.6). En vez de ETA se muestra la distancia en línea recta del chofer al origen (**A confirmar:** ETA real, que necesita un endpoint del backend con Directions/Distance Matrix).
    - *Sin chofer:* "Pedir el más cercano" (crea un viaje nuevo con el mismo origen, destino y motivo) y "Elegir otro" / "Volver al mapa" (vuelve con el pedido precargado), spec 5.3.
    - *Finalizado / cancelado:* "Volver al mapa".
    - *Reservar:* fecha y hora (selectores de Material en español), "Ver choferes disponibles" (`GET /api/reservas/disponibles`, con la duración estimada), elegir un chofer o "Cualquiera disponible" (por defecto) y "Confirmar reserva". Los errores del backend (anticipación mínima, franja ocupada) se muestran tal cual.
    - *Mis viajes:* próximas reservas (con "Cancelar reserva") e historial; una reserva rechazada (`sin_chofer`) ofrece "Elegir otro", que vuelve a la pantalla de reserva con los mismos datos. Se actualiza sola con cada aviso push y con "tirar para refrescar".
12. **`host_prueba`.** Login falso con perfiles de ejemplo (Ana Pérez/Secretaria, Jorge Juez/Juez, Carlos Chofer/Chofer) y campos editables, que arma `sim|<id>|<nombre>|<cargo>` (lo acepta el backend con `IDENTIDAD_DRIVER=simulada`); pantalla "Herramientas" con el ítem "Vehículos oficiales", "Simular push" y "Cerrar sesión"; `onSesionInvalida` vuelve al login. Configuración por `--dart-define` (`API_URL` por defecto `http://10.0.2.2:8000`, `REVERB_HOST` `10.0.2.2`, `REVERB_PORT` 8080, `REVERB_SCHEME` `http`, `REVERB_APP_KEY`, `MAPS_API_KEY`). Plataformas: Android, iOS y web (web sirve para desarrollar en Chrome sin emulador).
13. **Plataformas.** En `host_prueba`: permisos de Android (INTERNET, ubicación fina y aproximada, ubicación en segundo plano, servicio en primer plano y su tipo `location`, notificaciones), `queries` para `tel:`, Google Maps y Waze, tráfico sin cifrar **solo en el manifiesto de debug**, y la clave de Maps como *manifest placeholder* leído de `android/secretos.properties` (ignorado por git). En iOS: textos de permiso de ubicación, modo de fondo `location`, esquemas consultables, `GMSApiKey` desde `ios/Flutter/Secretos.xcconfig` (ignorado por git) y `NSAllowsLocalNetworking` (solo host de prueba). La lista de lo que tiene que agregar la app del PJ está al final (spec 12.4). **(A confirmar con el equipo de la app del PJ:** permisos, justificación ante las tiendas y clave de Maps propia o compartida, spec 12.4 y 12.6.)
14. **Tests (spec 11).** Unitarios del cliente de la API (adaptador HTTP falso, sin red), de los modelos (contra los JSON reales del backend), de los notifiers de Riverpod (sesión, viaje actual, choferes del mapa, push, borrador del pedido) con dobles de la API y de Reverb y `fake_async` para el sondeo, del cliente de Reverb contra un servidor Pusher en memoria, y de widgets de todas las pantallas del solicitante (incluidas la del viaje activo con el socket caído y el "atrás" del sistema). La oferta entrante y el viaje en curso del chofer se prueban en el plan siguiente.
15. **Versiones y compatibilidad (spec 12.5).** El `pubspec.yaml` del paquete usa rangos `^` (un paquete embebido no debe fijar versiones exactas: las resuelve el `pubspec.lock` de la app que lo usa); las versiones verificadas son las del Tech Stack. Mínimos: Flutter 3.41 / Dart 3.11, `minSdk` 24 en Android (lo exigen `google_maps_flutter_android` y `flutter_secure_storage`). **(A confirmar con el equipo de la app del PJ:** sus versiones de Flutter/Dart y de estas dependencias.)

### Ajustes que obligó el código real (revisar)

- **A1. Versiones.** Las últimas de varios paquetes exigen un SDK más nuevo que Flutter 3.41.6 / Dart 3.11.4: `flutter_riverpod` 3.4.x (Dart 3.12), `go_router` 18 (Flutter 3.44), `google_maps_flutter` 2.18.2 (Flutter 3.47). `pub` resolvió 3.3.2, 17.5.0 y 2.18.1. `intl` queda en **0.20.2** porque `flutter_localizations` la fija (con `intl: 0.20.3` la resolución falla).
- **A2. `Accept: application/json` es obligatorio.** Sin ese encabezado, un pedido sin sesión no devuelve `{"message":"Unauthenticated."}` sino una redirección a una ruta de login que no existe. `ClienteApi` lo agrega siempre.
- **A3. Formatos reales.** Las fechas de `ViajeResource` y de los eventos vienen como `2026-10-01T12:00:00+00:00` y las de los modelos Eloquent sin Resource (turnos, vehículos) como `2026-10-01T12:00:00.000000Z`; `DateTime.parse` acepta las dos y se guardan en UTC. Las coordenadas pueden llegar como entero. Las fechas que se envían van en UTC con `Z` (`escribirFecha`), que el backend respeta (`HoraLocal::interpretar`). Las validaciones de Laravel responden 422 con `{message, errors}` **en inglés** (el backend corre con `APP_LOCALE=en`); las reglas de negocio responden 422 con `{message}` en español. La app evita mandar datos inválidos y muestra `message` (**A confirmar con el backend:** `APP_LOCALE=es` y traducciones de validación). `POST /api/broadcasting/auth` responde 403 con `{"message": ""}`.
- **A4. `viajes/actual` no devuelve viajes terminados** (para el solicitante: inmediatos en progreso o reservas ya comenzadas). Por eso, si un viaje que se seguía desaparece mientras no había socket, `ViajeActualNotifier` lo busca en `GET /api/viajes` (historial) para mostrar `sin_chofer`, `cancelado` o `finalizado`. Mientras hay socket, el evento `viaje.actualizado` ya trae el estado final.
- **A5. El "atrás" del sistema.** Con un `MaterialApp.router` anidado, el "atrás" lo recibe primero el `Navigator` de la app principal, que cerraba el módulo entero aunque hubiera una pantalla interna abierta (lo detectó el test de la Task 11). La raíz del módulo usa `PopScope(canPop: false)`: le pasa el "atrás" al router interno (`routerDelegate.popRoute()`, que también cierra diálogos y hojas) y solo cierra el módulo si adentro no queda nada. Por eso `alCerrar` usa `Navigator.pop()` y no `maybePop()` (el `PopScope` bloquearía este último).
- **A6. `dart_pusher_channels`:** tras una caída el cliente pasa directo a `reconnecting` (no a `disconnected`); se toma como "desconectado" para activar el sondeo. Reintenta cada 3 s (`minimumReconnectDelayDuration`) y, en cada `onConnectionEstablished`, se vuelve a suscribir a los canales activos con una autorización nueva (el `socket_id` cambia). Probado contra un servidor Pusher en memoria (`ConexionFalsa`).
- **A7. `sin_chofer` de un pedido específico** llega con `chofer: null` (el backend limpia el chofer al rechazar); la pantalla usa `modo = especifico` para mostrar "El chofer no aceptó el viaje" y "Elegir otro".
- **A8. Distancia en vez de ETA.** El backend no expone un ETA (Distance Matrix solo se usa internamente para elegir chofer). Se muestra "A 2,3 km del origen" en línea recta mientras el chofer se acerca (decisión 11).
- **A9. Rol de chofer.** El intercambio crea a todos como `solicitante`; el rol `chofer` lo asigna un admin en el panel. En `host_prueba`, "Carlos Chofer" entra como solicitante hasta que se le cambie el rol (el admin se trata como solicitante: las rutas de pedido admiten `rol:solicitante,admin`).
- **A10. Web.** `flutter build web` compila, pero para ver el mapa en Chrome hay que agregar el script de la Maps JavaScript API en `host_prueba/web/index.html` con una clave propia (no se versiona; ver Task 13). El backend ya acepta CORS en `api/*` (configuración por defecto de Laravel 12).

## Contrato de la API usado (referencia)

| Método y ruta | Rol | Respuesta | Errores relevantes |
|---|---|---|---|
| `POST /api/auth/intercambio` `{token_externo}` | — | 200 `{token, usuario: {id, nombre, cargo, rol}}` | 401 `Sesión inválida.`, 403 `Usuario deshabilitado.`, 503 `Servicio de identidad no disponible.` |
| `GET /api/yo` | todos | `{id, nombre, cargo, rol}` | 401 `Unauthenticated.` |
| `GET /api/configuracion` | todos | `{gps_turno_seg, gps_viaje_seg, oferta_segundos}` | |
| `GET /api/choferes` | todos | `[{id, nombre, estado, lat, lng, rumbo, actualizado_en, vehiculo}]` | |
| `GET /api/viajes/actual` | todos | `{viaje: ViajeResource\|null, oferta: {id, vence_en, viaje}\|null}` (oferta solo chofer) | |
| `GET /api/viajes` | todos | `{proximas: [ViajeResource], historial: [ViajeResource]}` | |
| `POST /api/viajes` | solicitante, admin | 201 `ViajeResource` | 422 `El chofer elegido no está disponible.`, `Ya tenés un viaje en curso.`, validación |
| `POST /api/viajes/{id}/cancelar` `{motivo?}` | todos (chofer: `motivo` obligatorio) | 200 `ViajeResource` | 403, 422 transición inválida |
| `GET /api/reservas/disponibles?programado_para&origen_*&destino_*` | solicitante, admin | `{duracion_estimada_min, choferes: [{id, nombre, reservas_del_dia}]}` | 422 anticipación mínima |
| `POST /api/reservas` | solicitante, admin | 201 `ViajeResource` (`ofrecido` o `aceptado`) | 422 sin choferes / franja ocupada |
| `POST /api/push/token` `{token}` | todos | 204 | |
| `POST /api/broadcasting/auth` (form: `socket_id`, `channel_name`) | todos | `{auth}` | 403 `{"message":""}` |

Eventos (`broadcastAs`) en canales privados: `viaje.actualizado` (`ViajeResource`) en `viaje.{id}` y `chofer.{id}`; `oferta.creada` (`{oferta_id, vence_en, viaje}`) en `chofer.{id}`; `chofer.ubicacion` (`{chofer_id, lat, lng, rumbo, actualizado_en}`) en `mapa.choferes` y `viaje.{id}`; `chofer.estado` (`{chofer_id, estado}`) en `mapa.choferes`. Push (`data`, todo string, con `modulo = vehiculos_oficiales`): `tipo` = `oferta`, `oferta_reserva` (`oferta_id`, `viaje_id`), `viaje` (`viaje_id`, `estado`), `recordatorio_reserva`, `alerta_reserva` (`viaje_id`).

## Global Constraints

- Nombres de clases, archivos, providers, textos de pantalla y tests en **español**, como en los planes anteriores.
- Los comandos `flutter`/`dart` del paquete se corren desde `paquete/vehiculos_oficiales/` y los de la app de prueba desde `host_prueba/`; los `git`, desde la raíz del repo.
- Cada Task termina con `flutter analyze` sin problemas, `dart format --output=none --set-exit-if-changed lib test` sin cambios (el paquete usa `formatter: page_width: 120`) y todos los tests en verde.
- Nada en `lib/src/` importa `package:google_maps_flutter` salvo `lib/src/mapa/mapa_google.dart`; las pantallas dibujan el mapa con `ref.watch(constructorMapaProvider)`.
- Ningún pedido HTTP sale por fuera de `ClienteApi` (incluida la autorización de canales de Reverb), así el token Sanctum y el aviso de 401 están en un solo lugar.
- Las pantallas no abren canales de Reverb ni timers de sondeo: eso lo hacen los notifiers (`ViajeActualNotifier`, `ChoferesMapaNotifier`) con `Respaldo`.
- Los tests nunca usan red, Google Maps, Firebase ni plugins nativos: `AdaptadorFalso` (HTTP), `TiempoRealFalso` o `ConexionFalsa` (Reverb), `mapaDePrueba`, `UbicadorFalso`, `PuenteFalso`, `AlmacenTokenMemoria` y `lanzadorUrlProvider`/`elegirFechaHoraProvider` sobrescritos.
- Las fechas se guardan en UTC y se muestran con `formatearFechaHora` (zona del dispositivo).
- Cada commit termina con la línea `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Un 401 en cualquier lado llama a `onSesionInvalida` exactamente una vez:** en el intercambio, en tres pedidos simultáneos y en la autorización de un canal de Reverb; un reintento no vuelve a llamar; el 401 al probar un token guardado durante una caída del PJ **no** llama (y ese token no es de otra sesión). Tests en Tasks 3, 4, 5 y 8.
2. **Con el WebSocket caído la pantalla del viaje activo sigue al día:** consulta cada 10 s (y no antes), muestra el aviso, toma la posición del chofer de `GET /api/choferes`, deja de consultar al reconectar y en ese momento pide el estado completo; un viaje que termina sin socket muestra cómo terminó. Tests en Tasks 6, 9 y 10 (y la mutación "sin sondeo" hace fallar 4 tests).
3. **La autorización de canales privados usa el token Sanctum:** `POST /api/broadcasting/auth` con `Authorization: Bearer`, formulario `socket_id`/`channel_name`, y el `auth` devuelto va en el `pusher:subscribe`; tras una caída se reconecta y se vuelve a autorizar. Test en Task 5.
4. **El "atrás" del sistema no cierra el módulo si hay una pantalla interna:** desde el viaje o "Mis viajes" vuelve al mapa; desde el mapa cierra el módulo y vuelve a "Herramientas". Tests en Tasks 8, 11 y 12.
5. **Pedidos y reservas mandan exactamente lo que valida el backend:** campos de `ViajeController::store` y `ReservaController::store`, `chofer_id` solo en modo específico, direcciones y motivo vacíos omitidos, `programado_para` en UTC. Tests en Tasks 3, 11 y 12.

## Estructura de archivos

```
paquete/vehiculos_oficiales/
  pubspec.yaml, analysis_options.yaml, .gitignore, .metadata        # flutter create --template=package
  lib/vehiculos_oficiales.dart                                      # exports públicos
  lib/src/config.dart                                               # VehiculosOficialesConfig, SesionPJ
  lib/src/puente_notificaciones.dart                                # PuenteNotificaciones
  lib/src/vehiculos_oficiales.dart                                  # VehiculosOficiales.abrir
  lib/src/entorno.dart                                              # EntornoModulo y providers base (API, almacén, aviso 401)
  lib/src/modelos/{json,comunes,usuario,viaje,chofer_mapa,reservas,configuracion,pedidos,modelos}.dart
  lib/src/api/{errores_api,cliente_api,api_vehiculos}.dart
  lib/src/sesion/{almacen_token,aviso_sesion,sesion}.dart
  lib/src/tiempo_real/{tiempo_real,tiempo_real_pusher,respaldo,tiempo_real_provider}.dart
  lib/src/viaje/viaje_actual.dart                                   # ViajeActualNotifier (también lo usa el chofer)
  lib/src/push/push_modulo.dart
  lib/src/mapa/{mapa,mapa_google}.dart
  lib/src/ubicacion/ubicador.dart
  lib/src/solicitante/{choferes_mapa,borrador_pedido,mis_viajes}.dart
  lib/src/ui/modulo_app.dart                                        # ProviderScope + MaterialApp.router + rutas
  lib/src/ui/comunes/comunes.dart                                   # lanzador de URLs, formatos, banner de conexión
  lib/src/ui/sesion/pantalla_inicio.dart
  lib/src/ui/solicitante/{inicio_solicitante,pantalla_viaje,pantalla_reserva,mis_viajes}.dart
  lib/src/ui/chofer/inicio_chofer.dart                              # provisoria (plan siguiente)
  test/fixtures/payloads.dart                                       # JSON reales del backend
  test/soporte/{adaptador_falso,entorno_prueba,dobles,montar}.dart
  test/{config,modelos,api,sesion,tiempo_real_pusher,viaje_actual,push,choferes_mapa,borrador_pedido}_test.dart
  test/ui/{modulo,pantalla_viaje,inicio_solicitante,reservas}_test.dart
host_prueba/                                                        # flutter create (android, ios, web)
  pubspec.yaml, pubspec.lock, analysis_options.yaml
  lib/{main,login_falso,herramientas,config_host,puente_falso}.dart
  test/host_test.dart
  android/app/build.gradle.kts, android/app/src/main/AndroidManifest.xml, android/app/src/debug/AndroidManifest.xml, android/.gitignore
  ios/Runner/Info.plist, ios/Runner/AppDelegate.swift, ios/Flutter/{Debug,Release}.xcconfig, ios/.gitignore
```

---

### Task 1: Esqueleto del paquete y configuración

**Files:**
- Create (con `flutter create`): `paquete/vehiculos_oficiales/` (se conservan `.gitignore`, `.metadata`; se borran `README.md`, `CHANGELOG.md`, `LICENSE` y el test de ejemplo)
- Modify: `paquete/vehiculos_oficiales/pubspec.yaml`, `paquete/vehiculos_oficiales/analysis_options.yaml`
- Create: `paquete/vehiculos_oficiales/lib/vehiculos_oficiales.dart`, `lib/src/config.dart`, `lib/src/puente_notificaciones.dart`
- Test: `paquete/vehiculos_oficiales/test/config_test.dart`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `VehiculosOficialesConfig({required String apiBaseUrl, required String reverbHost, required String reverbKey, int reverbPort = 443, String reverbScheme = 'https', String googleMapsApiKey = '', double centroMapaLat = -34.6037, double centroMapaLng = -58.3816})` con `Uri apiUri` (`…/api/`), `Uri autorizacionCanalesUri` (`…/api/broadcasting/auth`) y `String reverbWsScheme` (`ws`/`wss`).
  - `SesionPJ(String token)`.
  - `abstract interface class PuenteNotificaciones { Future<String?> token(); Stream<Map<String, dynamic>> get mensajes; }`.

- [ ] **Step 1: Crear el paquete**

Desde la raíz del repo:

```bash
mkdir paquete
cd paquete
flutter create --template=package --project-name vehiculos_oficiales vehiculos_oficiales
cd vehiculos_oficiales
rm README.md CHANGELOG.md LICENSE test/vehiculos_oficiales_test.dart
```

Expected: `All done!`. El `.gitignore` generado ya ignora `pubspec.lock` (correcto para un paquete), `.dart_tool/`, `build/`, `.idea/` y `*.iml`.

- [ ] **Step 2: Dependencias y análisis**

`paquete/vehiculos_oficiales/pubspec.yaml` (reemplazar entero):

```yaml
name: vehiculos_oficiales
description: "Módulo Vehículos Oficiales para la app del Poder Judicial (solicitantes y choferes)."
version: 0.1.0
publish_to: none

environment:
  sdk: ^3.11.0
  flutter: ">=3.41.0"

dependencies:
  flutter:
    sdk: flutter
  flutter_localizations:
    sdk: flutter
  clock: ^1.1.2
  crypto: ^3.0.7
  dart_pusher_channels: ^1.3.1
  dio: ^5.11.1
  flutter_riverpod: ^3.3.2
  flutter_secure_storage: ^11.2.0
  geolocator: ^14.1.1
  go_router: ^17.5.0
  google_maps_flutter: ^2.18.1
  intl: ^0.20.2
  url_launcher: ^6.3.2

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^6.0.0
  fake_async: ^1.3.3

flutter:
```

`paquete/vehiculos_oficiales/analysis_options.yaml` (reemplazar entero):

```yaml
include: package:flutter_lints/flutter.yaml

analyzer:
  language:
    strict-casts: true
    strict-raw-types: true

linter:
  rules:
    - prefer_single_quotes
    - unawaited_futures
    - avoid_dynamic_calls

formatter:
  page_width: 120
```

Run: `flutter pub get` y después `flutter pub deps --style=compact`.
Expected: `flutter_riverpod 3.3.2`, `go_router 17.5.0`, `dio 5.11.1`, `dart_pusher_channels 1.3.1`, `google_maps_flutter 2.18.1`, `geolocator 14.1.1`, `intl 0.20.2`. Si `pub` elige otras versiones (por ejemplo, con un Flutter más nuevo), anotar las que resuelva y seguir: el código no depende de parches.

- [ ] **Step 3: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/config_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

void main() {
  test('arma las URLs de la API y de autorización de canales', () {
    const config = VehiculosOficialesConfig(
      apiBaseUrl: 'http://10.0.2.2:8000/',
      reverbHost: '10.0.2.2',
      reverbPort: 8080,
      reverbScheme: 'http',
      reverbKey: 'clave',
    );

    expect(config.apiUri.toString(), 'http://10.0.2.2:8000/api/');
    expect(config.apiUri.resolve('viajes/actual').toString(), 'http://10.0.2.2:8000/api/viajes/actual');
    expect(config.autorizacionCanalesUri.toString(), 'http://10.0.2.2:8000/api/broadcasting/auth');
    expect(config.reverbWsScheme, 'ws');
  });

  test('https usa wss', () {
    const config = VehiculosOficialesConfig(
      apiBaseUrl: 'https://vehiculos.pj.gob.ar',
      reverbHost: 'vehiculos.pj.gob.ar',
      reverbKey: 'clave',
    );

    expect(config.reverbWsScheme, 'wss');
    expect(config.reverbPort, 443);
  });
}
```

- [ ] **Step 4: Correr y ver que falla**

Run: `flutter test`
Expected: FAIL (no existe `package:vehiculos_oficiales/vehiculos_oficiales.dart` con esos símbolos).

- [ ] **Step 5: Implementación**

`paquete/vehiculos_oficiales/lib/src/config.dart`:

```dart
/// Datos de conexión del módulo. Los define la app que lo embebe.
class VehiculosOficialesConfig {
  const VehiculosOficialesConfig({
    required this.apiBaseUrl,
    required this.reverbHost,
    required this.reverbKey,
    this.reverbPort = 443,
    this.reverbScheme = 'https',
    this.googleMapsApiKey = '',
    this.centroMapaLat = -34.6037,
    this.centroMapaLng = -58.3816,
  });

  /// Dónde se centra el mapa al abrir, si todavía no hay choferes ni ubicación propia.
  final double centroMapaLat;
  final double centroMapaLng;

  /// URL del backend sin `/api`, por ejemplo `https://vehiculos.pj.gob.ar`.
  final String apiBaseUrl;

  final String reverbHost;
  final int reverbPort;

  /// `http` o `https` (se traduce a `ws` o `wss`).
  final String reverbScheme;
  final String reverbKey;

  /// Solo informativa: en Android e iOS la clave se declara en el manifiesto / AppDelegate.
  final String googleMapsApiKey;

  Uri get apiUri => Uri.parse('${_sinBarraFinal(apiBaseUrl)}/api/');

  Uri get autorizacionCanalesUri => apiUri.resolve('broadcasting/auth');

  String get reverbWsScheme => reverbScheme == 'https' ? 'wss' : 'ws';

  static String _sinBarraFinal(String url) => url.endsWith('/') ? url.substring(0, url.length - 1) : url;
}

/// Sesión de la app del Poder Judicial: el único dato que el módulo toma de ella.
class SesionPJ {
  const SesionPJ(this.token);

  final String token;
}
```

`paquete/vehiculos_oficiales/lib/src/puente_notificaciones.dart`:

```dart
/// Puente de notificaciones push que implementa la app principal (spec 12.3).
///
/// La app principal es dueña de Firebase: le pasa al módulo el token FCM del dispositivo
/// y le reenvía los mensajes recibidos (el `data` del mensaje FCM, todo en strings).
abstract interface class PuenteNotificaciones {
  Future<String?> token();

  Stream<Map<String, dynamic>> get mensajes;
}
```

`paquete/vehiculos_oficiales/lib/vehiculos_oficiales.dart` (la Task 8 le agrega `VehiculosOficiales`):

```dart
/// Módulo Vehículos Oficiales. Único punto de entrada: [VehiculosOficiales.abrir].
library;

export 'src/config.dart' show SesionPJ, VehiculosOficialesConfig;
export 'src/puente_notificaciones.dart' show PuenteNotificaciones;
```

- [ ] **Step 6: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 2 PASS, `No issues found!`, `0 changed`.

- [ ] **Step 7: Commit**

```bash
git add paquete
git commit -m "feat: paquete Flutter vehiculos_oficiales con su configuración" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Modelos contra los JSON reales del backend

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/modelos/{json,comunes,usuario,viaje,chofer_mapa,reservas,configuracion,pedidos,modelos}.dart`
- Create: `paquete/vehiculos_oficiales/test/fixtures/payloads.dart`
- Test: `paquete/vehiculos_oficiales/test/modelos_test.dart`

**Interfaces:**
- Consumes: formatos de `ViajeResource`, `ViajeController::actual`/`index`, `MapaController`, `ReservaController::disponibles`, `ConfiguracionController`, `AuthController` y de los eventos `OfertaCreada`, `UbicacionChoferActualizada`, `EstadoChoferActualizado`.
- Produces:
  - `typedef Json = Map<String, dynamic>`; `leerDouble`, `leerDoubleOpcional`, `leerFecha` (UTC), `leerFechaOpcional`, `escribirFecha` (UTC con `Z`), `leerMapa`, `leerLista`.
  - `Coordenada(lat, lng)` (con `==`), `Lugar(coordenada, {direccion})` + `descripcion`, `Vehiculo({id?, patente, marca, modelo, color?})` + `descripcion`, `Persona({id, nombre, telefono?})`.
  - `enum Rol {solicitante, chofer, admin}`, `Usuario({id, nombre, cargo?, rol})` + `esChofer`.
  - `enum EstadoViaje` (`valor`, `texto`, `buscandoChofer`, `conChofer`, `terminado`, `cancelablePorSolicitante`), `enum TipoViaje`, `enum ModoViaje` (`valor`), `Viaje` (todos los campos de `ViajeResource`), `Oferta({id, venceEn, viaje})` (acepta `id` u `oferta_id`), `ViajeActual({viaje?, oferta?})` + `ViajeActual.vacio`, `MisViajes({proximas, historial})`.
  - `enum EstadoChofer` (`valor`, `texto`), `ChoferEnMapa` (+ `seleccionable`, `conEstado`, `conUbicacion`), `UbicacionChofer`.
  - `ChoferDisponible`, `DisponiblesReserva`, `Configuracion`.
  - `PedidoViaje({modo, choferId?, origen, destino, motivo?}).toJson()`, `FranjaReserva({programadoPara, origen, destino}).toQuery()`, `PedidoReserva({programadoPara, modo, choferId?, origen, destino, motivo?}).toJson()`.

- [ ] **Step 1: Copiar los JSON reales**

`paquete/vehiculos_oficiales/test/fixtures/payloads.dart`:

```dart
// Respuestas reales del backend (rama main, `IDENTIDAD_DRIVER=simulada`, `MAPAS_DRIVER=falso`,
// reloj en 2026-10-01 12:00 UTC), copiadas tal cual. Si cambia un Resource o un controlador,
// regenerarlas y actualizar los modelos.
import 'dart:convert';

Map<String, dynamic> json(String s) => (jsonDecode(s) as Map).cast<String, dynamic>();

List<dynamic> jsonLista(String s) => jsonDecode(s) as List<dynamic>;

// POST /api/auth/intercambio  {"token_externo":"sim|100|Ana Pérez|Secretaria"} -> 200
const intercambio =
    r'''{"token":"1|MIrx0yp82jJCJvkdlCxuiYvIFl0kENIXWZeiV0t1eecca02e","usuario":{"id":1,"nombre":"Ana Pérez","cargo":"Secretaria","rol":"solicitante"}}''';

// POST /api/auth/intercambio  {"token_externo":"invalido"} -> 401
const intercambioInvalido = r'''{"message":"Sesión inválida."}''';

// GET /api/yo sin token -> 401
const noAutenticado = r'''{"message":"Unauthenticated."}''';

// GET /api/configuracion -> 200
const configuracion = r'''{"gps_turno_seg":10,"gps_viaje_seg":5,"oferta_segundos":30}''';

// GET /api/choferes -> 200
const choferes =
    r'''[{"id":2,"nombre":"Carlos Gómez","estado":"libre","lat":-26.8301,"lng":-65.2001,"rumbo":91.5,"actualizado_en":"2026-10-01T12:00:00+00:00","vehiculo":{"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco"}}]''';

// POST /api/broadcasting/auth (form: socket_id, channel_name=private-mapa.choferes) -> 200
const autorizacionCanal = r'''{"auth":"clave:050a29a0ab1ade49991dd26c45b24f6ff1580b645893ac51f353385ad15e2ae8"}''';

// POST /api/viajes sin coordenadas -> 422 (validación de Laravel)
const validacion =
    r'''{"message":"The chofer id field is required when modo is especifico. (and 4 more errors)","errors":{"chofer_id":["The chofer id field is required when modo is especifico."],"origen_lat":["The origen lat field is required."],"origen_lng":["The origen lng field is required."],"destino_lat":["The destino lat field is required."],"destino_lng":["The destino lng field is required."]}}''';

// POST /api/viajes (mas_cercano) -> 201
const viajeOfrecido =
    r'''{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// POST /api/ofertas/1/aceptar -> 200 (también es el payload de `viaje.actualizado`)
const viajeAceptado =
    r'''{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"aceptado","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":{"id":2,"nombre":"Carlos Gómez","telefono":"3815550000"},"vehiculo":{"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco"},"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// GET /api/viajes/actual (solicitante) -> 200
const viajeActualSolicitante =
    r'''{"viaje":{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null},"oferta":null}''';

// GET /api/viajes/actual (chofer con oferta pendiente) -> 200
const viajeActualChofer =
    r'''{"viaje":null,"oferta":{"id":1,"vence_en":"2026-10-01T12:00:30+00:00","viaje":{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}}''';

// GET /api/viajes/actual sin nada en curso -> 200
const viajeActualVacio = r'''{"viaje":null,"oferta":null}''';

// POST /api/viajes/1/cancelar (solicitante, viaje en sin_chofer) -> 422
const reglaNegocio = r'''{"message":"El viaje no puede pasar de sin_chofer a cancelado."}''';

// GET /api/agenda como solicitante -> 403
const sinPermiso = r'''{"message":"No tenés permiso para esta acción."}''';

// GET /api/reservas/disponibles?programado_para=2026-10-02T13:00:00Z&... -> 200
const reservasDisponibles =
    r'''{"duracion_estimada_min":19,"choferes":[{"id":2,"nombre":"Carlos Gómez","reservas_del_dia":0}]}''';

// POST /api/reservas (cualquiera_disponible) -> 201
const reservaCreada =
    r'''{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// GET /api/viajes -> 200
const misViajes =
    r'''{"proximas":[{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}],"historial":[{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"sin_chofer","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}]}''';

// Evento `oferta.creada` en `private-chofer.{id}` (OfertaCreada::broadcastWith)
const eventoOfertaCreada =
    r'''{"oferta_id":3,"vence_en":"2026-10-01T12:30:00+00:00","viaje":{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}''';

// Evento `chofer.ubicacion` en `private-mapa.choferes` y `private-viaje.{id}`
const eventoUbicacion =
    r'''{"chofer_id":2,"lat":-26.8301,"lng":-65.2001,"rumbo":91.5,"actualizado_en":"2026-10-01T12:00:00+00:00"}''';

// Evento `chofer.estado` en `private-mapa.choferes`
const eventoEstadoChofer = r'''{"chofer_id":2,"estado":"en_viaje"}''';
```

- [ ] **Step 2: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/modelos_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;

void main() {
  test('lee el usuario del intercambio', () {
    final u = Usuario.fromJson(leerMapa(p.json(p.intercambio)['usuario']));

    expect(u.id, 1);
    expect(u.nombre, 'Ana Pérez');
    expect(u.cargo, 'Secretaria');
    expect(u.rol, Rol.solicitante);
    expect(u.esChofer, isFalse);
  });

  test('lee un viaje ofrecido sin chofer', () {
    final v = Viaje.fromJson(p.json(p.viajeOfrecido));

    expect(v.id, 1);
    expect(v.tipo, TipoViaje.inmediato);
    expect(v.modo, ModoViaje.masCercano);
    expect(v.estado, EstadoViaje.ofrecido);
    expect(v.estado.buscandoChofer, isTrue);
    expect(v.obligatorio, isFalse);
    expect(v.origen.coordenada, const Coordenada(-26.8241, -65.2226));
    expect(v.origen.direccion, 'Plaza Independencia');
    expect(v.destino.descripcion, 'Tribunales');
    expect(v.chofer, isNull);
    expect(v.vehiculo, isNull);
    expect(v.programadoPara, isNull);
    expect(v.solicitante.nombre, 'Ana Pérez');
  });

  test('lee un viaje aceptado con chofer, vehículo y fechas en UTC', () {
    final v = Viaje.fromJson(p.json(p.viajeAceptado));

    expect(v.estado, EstadoViaje.aceptado);
    expect(v.estado.conChofer, isTrue);
    expect(v.chofer!.nombre, 'Carlos Gómez');
    expect(v.chofer!.telefono, '3815550000');
    expect(v.vehiculo!.descripcion, 'Toyota Corolla (AB123CD)');
    expect(v.aceptadoEn, DateTime.utc(2026, 10, 1, 12));
    expect(v.aceptadoEn!.isUtc, isTrue);
  });

  test('lee una reserva con programado_para y dirección de origen nula', () {
    final v = Viaje.fromJson(p.json(p.reservaCreada));

    expect(v.tipo, TipoViaje.reserva);
    expect(v.modo, ModoViaje.cualquieraDisponible);
    expect(v.programadoPara, DateTime.utc(2026, 10, 2, 13));
    expect(v.duracionEstimadaMin, 19);
    expect(v.origen.direccion, isNull);
    expect(v.origen.descripcion, '-26.82410, -65.22260');
  });

  test('lee viajes/actual del solicitante y del chofer', () {
    final sol = ViajeActual.fromJson(p.json(p.viajeActualSolicitante));
    expect(sol.viaje!.id, 1);
    expect(sol.oferta, isNull);

    final cho = ViajeActual.fromJson(p.json(p.viajeActualChofer));
    expect(cho.viaje, isNull);
    expect(cho.oferta!.id, 1);
    expect(cho.oferta!.venceEn, DateTime.utc(2026, 10, 1, 12, 0, 30));
    expect(cho.oferta!.viaje.estado, EstadoViaje.ofrecido);

    final vacio = ViajeActual.fromJson(p.json(p.viajeActualVacio));
    expect(vacio.viaje, isNull);
    expect(vacio.oferta, isNull);
  });

  test('lee la oferta del evento oferta.creada (clave oferta_id)', () {
    final o = Oferta.fromJson(p.json(p.eventoOfertaCreada));

    expect(o.id, 3);
    expect(o.venceEn, DateTime.utc(2026, 10, 1, 12, 30));
    expect(o.viaje.tipo, TipoViaje.reserva);
  });

  test('lee mis viajes', () {
    final m = MisViajes.fromJson(p.json(p.misViajes));

    expect(m.proximas.single.id, 2);
    expect(m.historial.single.estado, EstadoViaje.sinChofer);
    expect(m.historial.single.estado.terminado, isTrue);
  });

  test('lee los choferes del mapa y aplica eventos de ubicación y estado', () {
    final c = ChoferEnMapa.fromJson(leerMapa(p.jsonLista(p.choferes).single));

    expect(c.id, 2);
    expect(c.estado, EstadoChofer.libre);
    expect(c.seleccionable, isTrue);
    expect(c.posicion, const Coordenada(-26.8301, -65.2001));
    expect(c.rumbo, 91.5);
    expect(c.vehiculo.patente, 'AB123CD');

    final u = UbicacionChofer.fromJson(p.json(p.eventoUbicacion));
    expect(u.choferId, 2);
    expect(c.conUbicacion(u).actualizadoEn, DateTime.utc(2026, 10, 1, 12));

    final ocupado = c.conEstado(EstadoChofer.desde(p.json(p.eventoEstadoChofer)['estado'] as String));
    expect(ocupado.estado, EstadoChofer.enViaje);
    expect(ocupado.seleccionable, isFalse);
    expect(c.conEstado(EstadoChofer.reservadoPronto).seleccionable, isFalse);
  });

  test('un chofer sin ubicación todavía no tiene posición', () {
    final c = ChoferEnMapa.fromJson({
      'id': 5,
      'nombre': 'Sin GPS',
      'estado': 'sin_senal',
      'lat': null,
      'lng': null,
      'rumbo': null,
      'actualizado_en': null,
      'vehiculo': {'patente': 'X', 'marca': 'Fiat', 'modelo': 'Cronos', 'color': null},
    });

    expect(c.posicion, isNull);
    expect(c.estado, EstadoChofer.sinSenal);
  });

  test('lee configuración y disponibles de reserva', () {
    final c = Configuracion.fromJson(p.json(p.configuracion));
    expect([c.gpsTurnoSeg, c.gpsViajeSeg, c.ofertaSegundos], [10, 5, 30]);

    final d = DisponiblesReserva.fromJson(p.json(p.reservasDisponibles));
    expect(d.duracionEstimadaMin, 19);
    expect(d.choferes.single.reservasDelDia, 0);
  });

  test('acepta coordenadas enteras y escribe fechas en UTC con Z', () {
    expect(Lugar.fromJson({'lat': -26, 'lng': -65, 'direccion': null}).coordenada, const Coordenada(-26, -65));
    expect(escribirFecha(DateTime.utc(2026, 10, 2, 13)), '2026-10-02T13:00:00.000Z');
  });

  test('un estado desconocido es un error de formato', () {
    expect(() => EstadoViaje.desde('volando'), throwsFormatException);
  });
}
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test test/modelos_test.dart`
Expected: FAIL (no existe `lib/src/modelos/modelos.dart`).

- [ ] **Step 4: Implementación**

`paquete/vehiculos_oficiales/lib/src/modelos/json.dart`:

```dart
/// Lectura de los JSON del backend (Laravel): los números pueden llegar como int o double
/// y las fechas en ISO-8601 con offset (`2026-10-01T12:00:00+00:00`), que se guardan en UTC.
typedef Json = Map<String, dynamic>;

double leerDouble(Object? v) => (v as num).toDouble();

double? leerDoubleOpcional(Object? v) => v == null ? null : (v as num).toDouble();

DateTime leerFecha(Object? v) => DateTime.parse(v as String).toUtc();

DateTime? leerFechaOpcional(Object? v) => v == null ? null : leerFecha(v);

/// Formato de las fechas que se envían: ISO-8601 en UTC con `Z` (el backend respeta el offset).
String escribirFecha(DateTime d) => d.toUtc().toIso8601String();

Json leerMapa(Object? v) => (v as Map).cast<String, dynamic>();

List<Json> leerLista(Object? v) => (v as List).map(leerMapa).toList();
```

`paquete/vehiculos_oficiales/lib/src/modelos/comunes.dart`:

```dart
import 'json.dart';

class Coordenada {
  const Coordenada(this.lat, this.lng);

  final double lat;
  final double lng;

  @override
  bool operator ==(Object other) => other is Coordenada && other.lat == lat && other.lng == lng;

  @override
  int get hashCode => Object.hash(lat, lng);

  @override
  String toString() => 'Coordenada($lat, $lng)';
}

/// Origen o destino de un viaje: `{lat, lng, direccion}`.
class Lugar {
  const Lugar(this.coordenada, {this.direccion});

  factory Lugar.fromJson(Json j) =>
      Lugar(Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])), direccion: j['direccion'] as String?);

  final Coordenada coordenada;
  final String? direccion;

  /// Texto para mostrar: la dirección escrita o, si no hay, las coordenadas.
  String get descripcion => direccion ?? '${coordenada.lat.toStringAsFixed(5)}, ${coordenada.lng.toStringAsFixed(5)}';
}

/// `{patente, marca, modelo, color}`; `id` solo viene en `GET /vehiculos/disponibles`.
class Vehiculo {
  const Vehiculo({this.id, required this.patente, required this.marca, required this.modelo, this.color});

  factory Vehiculo.fromJson(Json j) => Vehiculo(
    id: j['id'] as int?,
    patente: j['patente'] as String,
    marca: j['marca'] as String,
    modelo: j['modelo'] as String,
    color: j['color'] as String?,
  );

  final int? id;
  final String patente;
  final String marca;
  final String modelo;
  final String? color;

  String get descripcion => '$marca $modelo ($patente)';
}

/// Chofer o solicitante dentro de un viaje: `{id, nombre, telefono}`.
class Persona {
  const Persona({required this.id, required this.nombre, this.telefono});

  factory Persona.fromJson(Json j) =>
      Persona(id: j['id'] as int, nombre: j['nombre'] as String, telefono: j['telefono'] as String?);

  final int id;
  final String nombre;
  final String? telefono;
}
```

`paquete/vehiculos_oficiales/lib/src/modelos/usuario.dart`:

```dart
import 'json.dart';

enum Rol {
  solicitante,
  chofer,
  admin;

  static Rol desde(String valor) => Rol.values.byName(valor);
}

/// `usuario` de `POST /auth/intercambio` y respuesta de `GET /yo`.
class Usuario {
  const Usuario({required this.id, required this.nombre, this.cargo, required this.rol});

  factory Usuario.fromJson(Json j) => Usuario(
    id: j['id'] as int,
    nombre: j['nombre'] as String,
    cargo: j['cargo'] as String?,
    rol: Rol.desde(j['rol'] as String),
  );

  final int id;
  final String nombre;
  final String? cargo;
  final Rol rol;

  /// El admin usa la app como solicitante (las rutas de pedido admiten `rol:solicitante,admin`).
  bool get esChofer => rol == Rol.chofer;
}
```

`paquete/vehiculos_oficiales/lib/src/modelos/viaje.dart`:

```dart
import 'comunes.dart';
import 'json.dart';

enum EstadoViaje {
  buscando('buscando', 'Buscando chofer'),
  ofrecido('ofrecido', 'Buscando chofer'),
  aceptado('aceptado', 'Chofer asignado'),
  enCamino('en_camino', 'El chofer va en camino'),
  llego('llego', 'El chofer llegó'),
  enCurso('en_curso', 'En viaje'),
  finalizado('finalizado', 'Viaje finalizado'),
  cancelado('cancelado', 'Viaje cancelado'),
  sinChofer('sin_chofer', 'No hay choferes disponibles');

  const EstadoViaje(this.valor, this.texto);

  final String valor;
  final String texto;

  static EstadoViaje desde(String valor) => values.firstWhere(
    (e) => e.valor == valor,
    orElse: () => throw FormatException('Estado de viaje desconocido: $valor'),
  );

  bool get buscandoChofer => this == buscando || this == ofrecido;

  bool get conChofer => this == aceptado || this == enCamino || this == llego || this == enCurso;

  bool get terminado => this == finalizado || this == cancelado || this == sinChofer;

  /// El solicitante puede cancelar en cualquier estado anterior a `en_curso` (spec 5.6).
  bool get cancelablePorSolicitante => !terminado && this != enCurso;
}

enum TipoViaje {
  inmediato,
  reserva;

  static TipoViaje desde(String valor) => values.byName(valor);
}

enum ModoViaje {
  masCercano('mas_cercano'),
  especifico('especifico'),
  cualquieraDisponible('cualquiera_disponible');

  const ModoViaje(this.valor);

  final String valor;

  static ModoViaje desde(String valor) => values.firstWhere((e) => e.valor == valor);
}

/// `ViajeResource` del backend (también es el payload del evento `viaje.actualizado`).
class Viaje {
  const Viaje({
    required this.id,
    required this.tipo,
    required this.modo,
    required this.estado,
    required this.obligatorio,
    required this.origen,
    required this.destino,
    this.motivo,
    this.programadoPara,
    this.duracionEstimadaMin,
    this.chofer,
    this.vehiculo,
    required this.solicitante,
    this.aceptadoEn,
    this.llegoEn,
    this.iniciadoEn,
    this.finalizadoEn,
    this.canceladoEn,
  });

  factory Viaje.fromJson(Json j) => Viaje(
    id: j['id'] as int,
    tipo: TipoViaje.desde(j['tipo'] as String),
    modo: ModoViaje.desde(j['modo'] as String),
    estado: EstadoViaje.desde(j['estado'] as String),
    obligatorio: j['obligatorio'] as bool,
    origen: Lugar.fromJson(leerMapa(j['origen'])),
    destino: Lugar.fromJson(leerMapa(j['destino'])),
    motivo: j['motivo'] as String?,
    programadoPara: leerFechaOpcional(j['programado_para']),
    duracionEstimadaMin: j['duracion_estimada_min'] as int?,
    chofer: j['chofer'] == null ? null : Persona.fromJson(leerMapa(j['chofer'])),
    vehiculo: j['vehiculo'] == null ? null : Vehiculo.fromJson(leerMapa(j['vehiculo'])),
    solicitante: Persona.fromJson(leerMapa(j['solicitante'])),
    aceptadoEn: leerFechaOpcional(j['aceptado_en']),
    llegoEn: leerFechaOpcional(j['llego_en']),
    iniciadoEn: leerFechaOpcional(j['iniciado_en']),
    finalizadoEn: leerFechaOpcional(j['finalizado_en']),
    canceladoEn: leerFechaOpcional(j['cancelado_en']),
  );

  final int id;
  final TipoViaje tipo;
  final ModoViaje modo;
  final EstadoViaje estado;
  final bool obligatorio;
  final Lugar origen;
  final Lugar destino;
  final String? motivo;
  final DateTime? programadoPara;
  final int? duracionEstimadaMin;
  final Persona? chofer;
  final Vehiculo? vehiculo;
  final Persona solicitante;
  final DateTime? aceptadoEn;
  final DateTime? llegoEn;
  final DateTime? iniciadoEn;
  final DateTime? finalizadoEn;
  final DateTime? canceladoEn;
}

/// Oferta de viaje para un chofer. Llega como `{id, vence_en, viaje}` (`GET /viajes/actual`,
/// `GET /agenda`) o como `{oferta_id, vence_en, viaje}` (evento `oferta.creada`).
class Oferta {
  const Oferta({required this.id, required this.venceEn, required this.viaje});

  factory Oferta.fromJson(Json j) => Oferta(
    id: (j['id'] ?? j['oferta_id']) as int,
    venceEn: leerFecha(j['vence_en']),
    viaje: Viaje.fromJson(leerMapa(j['viaje'])),
  );

  final int id;
  final DateTime venceEn;
  final Viaje viaje;
}

/// `GET /viajes/actual`: `{viaje, oferta}`. `oferta` solo aparece para choferes.
class ViajeActual {
  const ViajeActual({this.viaje, this.oferta});

  factory ViajeActual.fromJson(Json j) => ViajeActual(
    viaje: j['viaje'] == null ? null : Viaje.fromJson(leerMapa(j['viaje'])),
    oferta: j['oferta'] == null ? null : Oferta.fromJson(leerMapa(j['oferta'])),
  );

  static const vacio = ViajeActual();

  final Viaje? viaje;
  final Oferta? oferta;
}

/// `GET /viajes`: próximas reservas e historial del solicitante.
class MisViajes {
  const MisViajes({required this.proximas, required this.historial});

  factory MisViajes.fromJson(Json j) => MisViajes(
    proximas: leerLista(j['proximas']).map(Viaje.fromJson).toList(),
    historial: leerLista(j['historial']).map(Viaje.fromJson).toList(),
  );

  final List<Viaje> proximas;
  final List<Viaje> historial;
}
```

`paquete/vehiculos_oficiales/lib/src/modelos/chofer_mapa.dart`:

```dart
import 'comunes.dart';
import 'json.dart';

enum EstadoChofer {
  fueraDeTurno('fuera_de_turno', 'Fuera de turno'),
  sinSenal('sin_senal', 'Sin señal'),
  enViaje('en_viaje', 'En viaje'),
  reservadoPronto('reservado_pronto', 'Reservado pronto'),
  libre('libre', 'Libre');

  const EstadoChofer(this.valor, this.texto);

  final String valor;
  final String texto;

  static EstadoChofer desde(String valor) => values.firstWhere(
    (e) => e.valor == valor,
    orElse: () => throw FormatException('Estado de chofer desconocido: $valor'),
  );
}

/// Un chofer en turno, de `GET /choferes`.
class ChoferEnMapa {
  const ChoferEnMapa({
    required this.id,
    required this.nombre,
    required this.estado,
    this.posicion,
    this.rumbo,
    this.actualizadoEn,
    required this.vehiculo,
  });

  factory ChoferEnMapa.fromJson(Json j) => ChoferEnMapa(
    id: j['id'] as int,
    nombre: j['nombre'] as String,
    estado: EstadoChofer.desde(j['estado'] as String),
    posicion: j['lat'] == null ? null : Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])),
    rumbo: leerDoubleOpcional(j['rumbo']),
    actualizadoEn: leerFechaOpcional(j['actualizado_en']),
    vehiculo: Vehiculo.fromJson(leerMapa(j['vehiculo'])),
  );

  final int id;
  final String nombre;
  final EstadoChofer estado;
  final Coordenada? posicion;
  final double? rumbo;
  final DateTime? actualizadoEn;
  final Vehiculo vehiculo;

  /// Spec 5.3 y 7: solo se le puede pedir un viaje a un chofer libre (los "reservados pronto" se ven
  /// pero no se eligen).
  bool get seleccionable => estado == EstadoChofer.libre;

  ChoferEnMapa conEstado(EstadoChofer nuevo) => ChoferEnMapa(
    id: id,
    nombre: nombre,
    estado: nuevo,
    posicion: posicion,
    rumbo: rumbo,
    actualizadoEn: actualizadoEn,
    vehiculo: vehiculo,
  );

  ChoferEnMapa conUbicacion(UbicacionChofer u) => ChoferEnMapa(
    id: id,
    nombre: nombre,
    estado: estado,
    posicion: u.posicion,
    rumbo: u.rumbo,
    actualizadoEn: u.actualizadoEn,
    vehiculo: vehiculo,
  );
}

/// Payload de `chofer.ubicacion` (canales `mapa.choferes` y `viaje.{id}`).
class UbicacionChofer {
  const UbicacionChofer({required this.choferId, required this.posicion, this.rumbo, required this.actualizadoEn});

  factory UbicacionChofer.fromJson(Json j) => UbicacionChofer(
    choferId: j['chofer_id'] as int,
    posicion: Coordenada(leerDouble(j['lat']), leerDouble(j['lng'])),
    rumbo: leerDoubleOpcional(j['rumbo']),
    actualizadoEn: leerFecha(j['actualizado_en']),
  );

  final int choferId;
  final Coordenada posicion;
  final double? rumbo;
  final DateTime actualizadoEn;
}
```

`paquete/vehiculos_oficiales/lib/src/modelos/reservas.dart`:

```dart
import 'json.dart';

class ChoferDisponible {
  const ChoferDisponible({required this.id, required this.nombre, required this.reservasDelDia});

  factory ChoferDisponible.fromJson(Json j) =>
      ChoferDisponible(id: j['id'] as int, nombre: j['nombre'] as String, reservasDelDia: j['reservas_del_dia'] as int);

  final int id;
  final String nombre;
  final int reservasDelDia;
}

/// `GET /reservas/disponibles`.
class DisponiblesReserva {
  const DisponiblesReserva({required this.duracionEstimadaMin, required this.choferes});

  factory DisponiblesReserva.fromJson(Json j) => DisponiblesReserva(
    duracionEstimadaMin: j['duracion_estimada_min'] as int,
    choferes: leerLista(j['choferes']).map(ChoferDisponible.fromJson).toList(),
  );

  final int duracionEstimadaMin;
  final List<ChoferDisponible> choferes;
}
```

`paquete/vehiculos_oficiales/lib/src/modelos/configuracion.dart`:

```dart
import 'json.dart';

/// `GET /configuracion`: los parámetros de la spec 5.7 que usa la app.
class Configuracion {
  const Configuracion({required this.gpsTurnoSeg, required this.gpsViajeSeg, required this.ofertaSegundos});

  factory Configuracion.fromJson(Json j) => Configuracion(
    gpsTurnoSeg: j['gps_turno_seg'] as int,
    gpsViajeSeg: j['gps_viaje_seg'] as int,
    ofertaSegundos: j['oferta_segundos'] as int,
  );

  final int gpsTurnoSeg;
  final int gpsViajeSeg;
  final int ofertaSegundos;
}
```

`paquete/vehiculos_oficiales/lib/src/modelos/pedidos.dart` (se prueba en la Task 3, junto con el cliente):

```dart
import 'comunes.dart';
import 'json.dart';
import 'viaje.dart';

/// Cuerpo de `POST /viajes` (pedido inmediato).
class PedidoViaje {
  const PedidoViaje({required this.modo, this.choferId, required this.origen, required this.destino, this.motivo})
    : assert(modo != ModoViaje.cualquieraDisponible, 'Solo las reservas usan cualquiera_disponible'),
      assert(modo != ModoViaje.especifico || choferId != null, 'Falta chofer_id');

  final ModoViaje modo;
  final int? choferId;
  final Lugar origen;
  final Lugar destino;
  final String? motivo;

  Json toJson() => {
    'modo': modo.valor,
    if (choferId != null) 'chofer_id': choferId,
    ..._lugares(origen, destino),
    if (motivo != null && motivo!.isNotEmpty) 'motivo': motivo,
  };
}

/// Franja de una reserva: query de `GET /reservas/disponibles`.
class FranjaReserva {
  const FranjaReserva({required this.programadoPara, required this.origen, required this.destino});

  final DateTime programadoPara;
  final Coordenada origen;
  final Coordenada destino;

  Json toQuery() => {
    'programado_para': escribirFecha(programadoPara),
    'origen_lat': origen.lat,
    'origen_lng': origen.lng,
    'destino_lat': destino.lat,
    'destino_lng': destino.lng,
  };
}

/// Cuerpo de `POST /reservas`.
class PedidoReserva {
  const PedidoReserva({
    required this.programadoPara,
    required this.modo,
    this.choferId,
    required this.origen,
    required this.destino,
    this.motivo,
  }) : assert(modo != ModoViaje.masCercano, 'Las reservas no usan mas_cercano'),
       assert(modo != ModoViaje.especifico || choferId != null, 'Falta chofer_id');

  final DateTime programadoPara;
  final ModoViaje modo;
  final int? choferId;
  final Lugar origen;
  final Lugar destino;
  final String? motivo;

  Json toJson() => {
    'programado_para': escribirFecha(programadoPara),
    'modo': modo.valor,
    if (choferId != null) 'chofer_id': choferId,
    ..._lugares(origen, destino),
    if (motivo != null && motivo!.isNotEmpty) 'motivo': motivo,
  };
}

Json _lugares(Lugar origen, Lugar destino) => {
  'origen_lat': origen.coordenada.lat,
  'origen_lng': origen.coordenada.lng,
  if (origen.direccion != null && origen.direccion!.isNotEmpty) 'origen_direccion': origen.direccion,
  'destino_lat': destino.coordenada.lat,
  'destino_lng': destino.coordenada.lng,
  if (destino.direccion != null && destino.direccion!.isNotEmpty) 'destino_direccion': destino.direccion,
};
```

`paquete/vehiculos_oficiales/lib/src/modelos/modelos.dart`:

```dart
export 'chofer_mapa.dart';
export 'comunes.dart';
export 'configuracion.dart';
export 'json.dart';
export 'pedidos.dart';
export 'reservas.dart';
export 'usuario.dart';
export 'viaje.dart';
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 14 PASS, sin problemas, `0 changed`.

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: modelos del módulo contra los JSON reales del backend" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Cliente de la API

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/api/{errores_api,cliente_api,api_vehiculos}.dart`
- Create: `paquete/vehiculos_oficiales/test/soporte/adaptador_falso.dart`
- Test: `paquete/vehiculos_oficiales/test/api_test.dart`

**Interfaces:**
- Consumes: modelos (Task 2).
- Produces:
  - `sealed class ErrorApi implements Exception { String mensaje; }` con `SesionInvalida` (401), `AccesoDenegado` (403), `NoEncontrado` (404), `ErrorNegocio` (422, con `Map<String, List<String>> errores`), `ServicioNoDisponible` (503), `SinConexion` (sin respuesta), `ErrorServidor` (otro código o respuesta que no es JSON).
  - `ClienteApi({required Uri baseApi, required void Function() alRecibir401, HttpClientAdapter? adaptador})` con `String? token`, `get`, `post`, `postFormulario`, `getMapa`, `postMapa`. Llama a `alRecibir401` con cada 401.
  - `ApiVehiculos(ClienteApi cliente)`: `intercambiar(tokenExterno) → Intercambio(token, usuario)`, `yo()`, `configuracion()`, `choferes()`, `viajeActual()`, `misViajes()`, `pedirViaje(PedidoViaje)`, `cancelarViaje(id, {motivo})`, `disponiblesReserva(FranjaReserva)`, `crearReserva(PedidoReserva)`, `registrarTokenPush(token)`, `autorizarCanal({socketId, canal}) → String auth`. El plan del chofer le agrega sus endpoints.
  - Test: `AdaptadorFalso` (`responder(metodo, ruta, estado, [cuerpo])`, `sinRed(metodo, ruta)`, `pedidos`).

- [ ] **Step 1: Adaptador HTTP de prueba**

`paquete/vehiculos_oficiales/test/soporte/adaptador_falso.dart`:

```dart
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

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
  final List<PedidoRegistrado> pedidos = [];

  /// Encola una respuesta. Si queda una sola, se repite en los pedidos siguientes.
  void responder(String metodo, String ruta, int estado, [String? cuerpo]) =>
      (_respuestas['$metodo $ruta'] ??= []).add((estado, cuerpo));

  void sinRed(String metodo, String ruta) => (_respuestas['$metodo $ruta'] ??= []).add((-1, null));

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
    final cola = _respuestas['${options.method} $ruta'];
    if (cola == null || cola.isEmpty) {
      throw StateError('Sin respuesta preparada para ${options.method} $ruta');
    }
    final (estado, cuerpo) = cola.length > 1 ? cola.removeAt(0) : cola.first;
    if (estado == -1) {
      throw DioException.connectionError(requestOptions: options, reason: 'sin red');
    }
    return ResponseBody.fromString(
      cuerpo ?? '',
      estado,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
```

- [ ] **Step 2: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/api_test.dart`:

```dart
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/adaptador_falso.dart';

void main() {
  late AdaptadorFalso http;
  late int avisos401;
  late ApiVehiculos api;

  setUp(() {
    http = AdaptadorFalso();
    avisos401 = 0;
    api = ApiVehiculos(
      ClienteApi(baseApi: Uri.parse('http://10.0.2.2:8000/api/'), alRecibir401: () => avisos401++, adaptador: http),
    );
  });

  test('el intercambio manda el token del PJ en JSON y sin Authorization', () async {
    http.responder('POST', 'auth/intercambio', 200, p.intercambio);

    final r = await api.intercambiar('sim|100|Ana Pérez|Secretaria');

    expect(r.token, startsWith('1|'));
    expect(r.usuario.rol, Rol.solicitante);
    final pedido = http.pedidos.single;
    expect(pedido.uri.toString(), 'http://10.0.2.2:8000/api/auth/intercambio');
    expect(pedido.headers['Accept'], 'application/json');
    expect(pedido.headers.containsKey('Authorization'), isFalse);
    expect(jsonDecode(pedido.cuerpo), {'token_externo': 'sim|100|Ana Pérez|Secretaria'});
  });

  test('con token, cada pedido lleva Authorization: Bearer', () async {
    http.responder('GET', 'viajes/actual', 200, p.viajeActualSolicitante);
    api.cliente.token = '1|abc';

    final actual = await api.viajeActual();

    expect(actual.viaje!.estado, EstadoViaje.ofrecido);
    expect(http.pedidos.single.headers['Authorization'], 'Bearer 1|abc');
  });

  test('401 lanza SesionInvalida y avisa', () async {
    http.responder('GET', 'yo', 401, p.noAutenticado);

    await expectLater(api.yo(), throwsA(isA<SesionInvalida>().having((e) => e.mensaje, 'mensaje', 'Unauthenticated.')));
    expect(avisos401, 1);
  });

  test('403, 404, 422 y 503 se traducen con el message del backend', () async {
    http.responder('GET', 'viajes', 403, p.sinPermiso);
    http.responder('POST', 'viajes/1/cancelar', 422, p.reglaNegocio);
    http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    http.responder('GET', 'configuracion', 404, '{"message":"Not Found"}');

    await expectLater(
      api.misViajes(),
      throwsA(isA<AccesoDenegado>().having((e) => e.mensaje, 'mensaje', 'No tenés permiso para esta acción.')),
    );
    await expectLater(
      api.cancelarViaje(1),
      throwsA(
        isA<ErrorNegocio>().having((e) => e.mensaje, 'mensaje', 'El viaje no puede pasar de sin_chofer a cancelado.'),
      ),
    );
    await expectLater(api.intercambiar('x'), throwsA(isA<ServicioNoDisponible>()));
    await expectLater(api.configuracion(), throwsA(isA<NoEncontrado>()));
    expect(avisos401, 0);
  });

  test('una validación de Laravel conserva los errores por campo', () async {
    http.responder('POST', 'viajes', 422, p.validacion);

    final pedido = PedidoViaje(
      modo: ModoViaje.masCercano,
      origen: const Lugar(Coordenada(0, 0)),
      destino: const Lugar(Coordenada(0, 0)),
    );

    await expectLater(
      api.pedirViaje(pedido),
      throwsA(
        isA<ErrorNegocio>().having((e) => e.errores['origen_lat'], 'errores', ['The origen lat field is required.']),
      ),
    );
  });

  test('sin red es SinConexion; un 500 o una respuesta que no es JSON es ErrorServidor', () async {
    http.sinRed('GET', 'choferes');
    http.responder('GET', 'viajes/actual', 500, '{"message":"Server Error"}');
    http.responder('GET', 'viajes', 200, '<html>proxy</html>');

    await expectLater(api.choferes(), throwsA(isA<SinConexion>()));
    await expectLater(api.viajeActual(), throwsA(isA<ErrorServidor>()));
    await expectLater(api.misViajes(), throwsA(isA<ErrorServidor>()));
  });

  test('pedir un viaje manda los campos de ViajeController::store', () async {
    http.responder('POST', 'viajes', 201, p.viajeOfrecido);

    final v = await api.pedirViaje(
      const PedidoViaje(
        modo: ModoViaje.especifico,
        choferId: 2,
        origen: Lugar(Coordenada(-26.8241, -65.2226), direccion: 'Plaza Independencia'),
        destino: Lugar(Coordenada(-26.8083, -65.2176)),
        motivo: 'Audiencia',
      ),
    );

    expect(v.id, 1);
    expect(jsonDecode(http.pedidos.single.cuerpo), {
      'modo': 'especifico',
      'chofer_id': 2,
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'origen_direccion': 'Plaza Independencia',
      'destino_lat': -26.8083,
      'destino_lng': -65.2176,
      'motivo': 'Audiencia',
    });
  });

  test('disponibles de reserva manda la franja como query con fecha UTC', () async {
    http.responder('GET', 'reservas/disponibles', 200, p.reservasDisponibles);

    final d = await api.disponiblesReserva(
      FranjaReserva(
        programadoPara: DateTime.utc(2026, 10, 2, 13),
        origen: const Coordenada(-26.8241, -65.2226),
        destino: const Coordenada(-26.8083, -65.2176),
      ),
    );

    expect(d.choferes.single.nombre, 'Carlos Gómez');
    expect(http.pedidos.single.uri.queryParameters, {
      'programado_para': '2026-10-02T13:00:00.000Z',
      'origen_lat': '-26.8241',
      'origen_lng': '-65.2226',
      'destino_lat': '-26.8083',
      'destino_lng': '-65.2176',
    });
  });

  test('crear una reserva y registrar el token push (204)', () async {
    http.responder('POST', 'reservas', 201, p.reservaCreada);
    http.responder('POST', 'push/token', 204);

    final r = await api.crearReserva(
      PedidoReserva(
        programadoPara: DateTime.utc(2026, 10, 2, 13),
        modo: ModoViaje.cualquieraDisponible,
        origen: const Lugar(Coordenada(-26.8241, -65.2226)),
        destino: const Lugar(Coordenada(-26.8083, -65.2176), direccion: 'Casa de Gobierno'),
      ),
    );
    await api.registrarTokenPush('fcm-abc');

    expect(r.tipo, TipoViaje.reserva);
    expect(jsonDecode(http.pedidos.first.cuerpo), {
      'programado_para': '2026-10-02T13:00:00.000Z',
      'modo': 'cualquiera_disponible',
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'destino_lat': -26.8083,
      'destino_lng': -65.2176,
      'destino_direccion': 'Casa de Gobierno',
    });
    expect(jsonDecode(http.pedidos.last.cuerpo), {'token': 'fcm-abc'});
  });

  test('autorizar un canal privado es un POST de formulario con el token Sanctum', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    api.cliente.token = '1|abc';

    final auth = await api.autorizarCanal(socketId: '1234.5678', canal: 'private-mapa.choferes');

    expect(auth, startsWith('clave:'));
    final pedido = http.pedidos.single;
    expect(pedido.headers['Authorization'], 'Bearer 1|abc');
    expect(pedido.headers['content-type'], 'application/x-www-form-urlencoded');
    expect(Uri.splitQueryString(pedido.cuerpo), {'socket_id': '1234.5678', 'channel_name': 'private-mapa.choferes'});
  });
}
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test test/api_test.dart`
Expected: FAIL (no existen `lib/src/api/…`).

- [ ] **Step 4: Implementación**

`paquete/vehiculos_oficiales/lib/src/api/errores_api.dart`:

```dart
/// Errores de la API traducidos desde el código HTTP. El backend siempre responde `{message}`
/// (y `errors` en las validaciones de Laravel).
sealed class ErrorApi implements Exception {
  const ErrorApi(this.mensaje);

  final String mensaje;

  @override
  String toString() => '$runtimeType: $mensaje';
}

/// 401: token del PJ inválido o token Sanctum vencido/revocado. Dispara `onSesionInvalida`.
class SesionInvalida extends ErrorApi {
  const SesionInvalida([super.mensaje = 'Tu sesión venció.']);
}

/// 403: sin permiso para la acción, usuario deshabilitado o viaje ajeno.
class AccesoDenegado extends ErrorApi {
  const AccesoDenegado(super.mensaje);
}

/// 404: recurso inexistente (p. ej. un viaje o una oferta que no existe).
class NoEncontrado extends ErrorApi {
  const NoEncontrado([super.mensaje = 'No se encontró lo que buscabas.']);
}

/// 422: validación o regla de negocio (`ReglaNegocio`/`TransicionInvalida` del backend).
class ErrorNegocio extends ErrorApi {
  const ErrorNegocio(super.mensaje, {this.errores = const {}});

  /// `errors` de una validación de Laravel (campo → mensajes). Vacío en reglas de negocio.
  final Map<String, List<String>> errores;
}

/// 503: el endpoint de identidad del PJ no responde (solo en `POST /auth/intercambio`).
class ServicioNoDisponible extends ErrorApi {
  const ServicioNoDisponible([super.mensaje = 'Servicio de identidad no disponible.']);
}

/// Sin respuesta: sin red, DNS, timeout o conexión rechazada.
class SinConexion extends ErrorApi {
  const SinConexion([super.mensaje = 'No hay conexión con el servidor.']);
}

/// Cualquier otra respuesta inesperada (5xx, JSON inválido).
class ErrorServidor extends ErrorApi {
  const ErrorServidor([super.mensaje = 'Ocurrió un error en el servidor.']);
}
```

`paquete/vehiculos_oficiales/lib/src/api/cliente_api.dart`:

```dart
import 'package:dio/dio.dart';

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
      return r.statusCode == 204 ? null : r.data;
    } on DioException catch (e) {
      throw _traducir(e);
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

`paquete/vehiculos_oficiales/lib/src/api/api_vehiculos.dart`:

```dart
import '../modelos/modelos.dart';
import 'cliente_api.dart';

/// Resultado de `POST /auth/intercambio`.
class Intercambio {
  const Intercambio(this.token, this.usuario);

  final String token;
  final Usuario usuario;
}

/// Endpoints del backend (routes/api.php) con tipos. Los tests de providers la reemplazan por un doble.
class ApiVehiculos {
  ApiVehiculos(this.cliente);

  final ClienteApi cliente;

  Future<Intercambio> intercambiar(String tokenExterno) async {
    final j = await cliente.postMapa('auth/intercambio', datos: {'token_externo': tokenExterno});
    return Intercambio(j['token'] as String, Usuario.fromJson(leerMapa(j['usuario'])));
  }

  Future<Usuario> yo() async => Usuario.fromJson(await cliente.getMapa('yo'));

  Future<Configuracion> configuracion() async => Configuracion.fromJson(await cliente.getMapa('configuracion'));

  Future<List<ChoferEnMapa>> choferes() async =>
      leerLista(await cliente.get('choferes')).map(ChoferEnMapa.fromJson).toList();

  Future<ViajeActual> viajeActual() async => ViajeActual.fromJson(await cliente.getMapa('viajes/actual'));

  Future<MisViajes> misViajes() async => MisViajes.fromJson(await cliente.getMapa('viajes'));

  Future<Viaje> pedirViaje(PedidoViaje pedido) async =>
      Viaje.fromJson(await cliente.postMapa('viajes', datos: pedido.toJson()));

  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) async =>
      Viaje.fromJson(await cliente.postMapa('viajes/$viajeId/cancelar', datos: {'motivo': ?motivo}));

  Future<DisponiblesReserva> disponiblesReserva(FranjaReserva franja) async =>
      DisponiblesReserva.fromJson(await cliente.getMapa('reservas/disponibles', query: franja.toQuery()));

  Future<Viaje> crearReserva(PedidoReserva pedido) async =>
      Viaje.fromJson(await cliente.postMapa('reservas', datos: pedido.toJson()));

  Future<void> registrarTokenPush(String token) async {
    await cliente.post('push/token', datos: {'token': token});
  }

  /// Firma de un canal privado (`private-...`) para el socket [socketId]. Devuelve `auth`.
  Future<String> autorizarCanal({required String socketId, required String canal}) async {
    final j = leerMapa(
      await cliente.postFormulario('broadcasting/auth', {'socket_id': socketId, 'channel_name': canal}),
    );
    return j['auth'] as String;
  }
}
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 24 PASS, sin problemas, `0 changed`.

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: cliente de la API con errores tipados y token Sanctum" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Sesión: intercambio, token guardado y 401 una sola vez

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/entorno.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/sesion/{almacen_token,aviso_sesion,sesion}.dart`
- Create: `paquete/vehiculos_oficiales/test/soporte/entorno_prueba.dart`
- Test: `paquete/vehiculos_oficiales/test/sesion_test.dart`

**Interfaces:**
- Consumes: `ApiVehiculos`, `ClienteApi`, errores (Task 3); `VehiculosOficialesConfig`, `SesionPJ`, `PuenteNotificaciones` (Task 1).
- Produces:
  - `EntornoModulo({config, sesion, push, onSesionInvalida})` y los providers `entornoProvider` (se sobrescribe siempre), `adaptadorHttpProvider` (nulo = real), `almacenTokenProvider` (`AlmacenTokenSeguro`), `avisoSesionProvider`, `clienteApiProvider`, `apiProvider`.
  - `AlmacenToken { leer(tokenPJ), guardar(tokenPJ, tokenSanctum), borrar() }` con `AlmacenTokenSeguro` (flutter_secure_storage) y `AlmacenTokenMemoria`; `claveDeSesion(tokenPJ)` = SHA-256.
  - `AvisoSesionInvalida(callbackHost)` con `avisar()` (una sola vez), `escuchar(cb)`, `silenciado`, `avisado`.
  - `sealed class EstadoSesion`: `SesionIniciando`, `SesionLista(usuario)`, `SesionIdentidadNoDisponible`, `SesionVencida`, `SesionDeshabilitada(mensaje)`, `SesionConError(mensaje)`; `sesionProvider` (`SesionNotifier.iniciar()` también es "Reintentar") y `usuarioProvider`.
  - Test: `EntornoPrueba({tokenPJ, tokenPush})` con `http`, `almacen`, `puente` (`PuenteFalso`), `sesionesInvalidas`, `overrides()`, `overridesDeModulo()` (sin el entorno, para `ModuloVehiculos`) y `contenedor()`.

- [ ] **Step 1: Entorno de prueba**

`paquete/vehiculos_oficiales/test/soporte/entorno_prueba.dart`:

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/sesion/almacen_token.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'adaptador_falso.dart';

const configPrueba = VehiculosOficialesConfig(
  apiBaseUrl: 'http://10.0.2.2:8000',
  reverbHost: '10.0.2.2',
  reverbPort: 8080,
  reverbScheme: 'http',
  reverbKey: 'clave',
);

class PuenteFalso implements PuenteNotificaciones {
  PuenteFalso({this.tokenPush});

  String? tokenPush;
  final controlador = StreamController<Map<String, dynamic>>.broadcast();

  @override
  Future<String?> token() async => tokenPush;

  @override
  Stream<Map<String, dynamic>> get mensajes => controlador.stream;
}

/// Todo lo que un test necesita para armar el módulo sin red, sin Firebase y sin Google.
class EntornoPrueba {
  EntornoPrueba({this.tokenPJ = 'sim|100|Ana Pérez|Secretaria', String? tokenPush})
    : puente = PuenteFalso(tokenPush: tokenPush);

  final String tokenPJ;
  final PuenteFalso puente;
  final http = AdaptadorFalso();
  final almacen = AlmacenTokenMemoria();
  int sesionesInvalidas = 0;

  EntornoModulo get entorno => EntornoModulo(
    config: configPrueba,
    sesion: SesionPJ(tokenPJ),
    push: puente,
    onSesionInvalida: () => sesionesInvalidas++,
  );

  List<Override> overrides([List<Override> extra = const []]) => [
    entornoProvider.overrideWithValue(entorno),
    ...overridesDeModulo(extra),
  ];

  /// Sin el entorno, que lo agrega `ModuloVehiculos`.
  List<Override> overridesDeModulo([List<Override> extra = const []]) => [
    adaptadorHttpProvider.overrideWithValue(http),
    almacenTokenProvider.overrideWithValue(almacen),
    ...extra,
  ];

  ProviderContainer contenedor([List<Override> extra = const []]) =>
      ProviderContainer.test(overrides: overrides(extra), retry: (_, _) => null);
}
```

- [ ] **Step 2: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/sesion_test.dart`:

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/entorno_prueba.dart';

/// Espera a que la sesión salga de "iniciando".
Future<EstadoSesion> sesionResuelta(ProviderContainer c) async {
  c.read(sesionProvider);
  for (var i = 0; i < 50 && c.read(sesionProvider) is SesionIniciando; i++) {
    await Future<void>.delayed(Duration.zero);
  }
  return c.read(sesionProvider);
}

void main() {
  late EntornoPrueba e;

  setUp(() => e = EntornoPrueba());

  test('intercambia el token del PJ, guarda el Sanctum y queda lista', () async {
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    final c = e.contenedor();

    final s = await sesionResuelta(c);

    expect(s, isA<SesionLista>().having((s) => s.usuario.rol, 'rol', Rol.solicitante));
    expect(c.read(clienteApiProvider).token, startsWith('1|'));
    expect(await e.almacen.leer('sim|100|Ana Pérez|Secretaria'), startsWith('1|'));
    expect(c.read(usuarioProvider).nombre, 'Ana Pérez');
  });

  test('token del PJ inválido: 401, estado vencida y onSesionInvalida una vez', () async {
    e.http.responder('POST', 'auth/intercambio', 401, p.intercambioInvalido);
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionVencida>());
    expect(e.sesionesInvalidas, 1);

    await c.read(sesionProvider.notifier).iniciar(); // un reintento no vuelve a llamar al host
    expect(e.sesionesInvalidas, 1);
    expect(e.http.pedidos, hasLength(1));
  });

  test('un 401 en varios pedidos a la vez avisa una sola vez y borra el token guardado', () async {
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 401, p.noAutenticado);
    e.http.responder('GET', 'choferes', 401, p.noAutenticado);
    e.http.responder('GET', 'viajes', 401, p.noAutenticado);
    final c = e.contenedor();
    await sesionResuelta(c);
    final api = c.read(apiProvider);

    final resultados = await Future.wait([
      api.viajeActual().then<Object?>((v) => v, onError: (Object err) => err),
      api.choferes().then<Object?>((v) => v, onError: (Object err) => err),
      api.misViajes().then<Object?>((v) => v, onError: (Object err) => err),
    ]);

    expect(resultados, everyElement(isA<SesionInvalida>()));
    expect(e.sesionesInvalidas, 1);
    expect(c.read(sesionProvider), isA<SesionVencida>());
    expect(await e.almacen.leer('sim|100|Ana Pérez|Secretaria'), isNull);
  });

  test('503 sin token guardado: identidad no disponible, y reintentar funciona', () async {
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionIdentidadNoDisponible>());

    await c.read(sesionProvider.notifier).iniciar();
    expect(c.read(sesionProvider), isA<SesionLista>());
    expect(e.sesionesInvalidas, 0);
  });

  test('503 con el token Sanctum guardado de esta sesión: sigue funcionando', () async {
    await e.almacen.guardar('sim|100|Ana Pérez|Secretaria', '7|guardado');
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    e.http.responder('GET', 'yo', 200, '{"id":1,"nombre":"Ana P\\u00e9rez","cargo":"Secretaria","rol":"solicitante"}');
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionLista>());
    expect(c.read(clienteApiProvider).token, '7|guardado');
    expect(e.http.pedidos.last.headers['Authorization'], 'Bearer 7|guardado');
  });

  test('503 con un token guardado vencido no llama a onSesionInvalida', () async {
    await e.almacen.guardar('sim|100|Ana Pérez|Secretaria', '7|vencido');
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    e.http.responder('GET', 'yo', 401, p.noAutenticado);
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionIdentidadNoDisponible>());
    expect(e.sesionesInvalidas, 0);
    expect(c.read(clienteApiProvider).token, isNull);
  });

  test('el token guardado de otra sesión del PJ no se usa', () async {
    await e.almacen.guardar('sim|999|Otro|Juez', '9|de-otro');
    e.http.responder('POST', 'auth/intercambio', 503, '{"message":"Servicio de identidad no disponible."}');
    final c = e.contenedor();

    expect(await sesionResuelta(c), isA<SesionIdentidadNoDisponible>());
    expect(e.http.pedidos, hasLength(1));
  });

  test('usuario deshabilitado (403) y sin red', () async {
    e.http.responder('POST', 'auth/intercambio', 403, '{"message":"Usuario deshabilitado."}');
    e.http.sinRed('POST', 'auth/intercambio');
    final c = e.contenedor();

    expect(
      await sesionResuelta(c),
      isA<SesionDeshabilitada>().having((s) => s.mensaje, 'mensaje', 'Usuario deshabilitado.'),
    );

    await c.read(sesionProvider.notifier).iniciar();
    expect(c.read(sesionProvider), isA<SesionConError>());
  });
}
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test test/sesion_test.dart`
Expected: FAIL (no existen `entorno.dart` ni `sesion/…`).

- [ ] **Step 4: Implementación**

`paquete/vehiculos_oficiales/lib/src/sesion/almacen_token.dart`:

```dart
import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Guarda el token Sanctum asociado a una sesión del PJ, para seguir funcionando si el endpoint de
/// identidad del PJ se cae (spec 9). La clave es el SHA-256 del token del PJ: otro usuario (u otra
/// sesión) del mismo dispositivo no puede reutilizarlo.
abstract interface class AlmacenToken {
  Future<String?> leer(String tokenPJ);

  Future<void> guardar(String tokenPJ, String tokenSanctum);

  Future<void> borrar();
}

String claveDeSesion(String tokenPJ) => sha256.convert(utf8.encode(tokenPJ)).toString();

class AlmacenTokenSeguro implements AlmacenToken {
  AlmacenTokenSeguro([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  static const _claveSesion = 'vehiculos_oficiales.sesion';
  static const _claveToken = 'vehiculos_oficiales.token';

  final FlutterSecureStorage _storage;

  @override
  Future<String?> leer(String tokenPJ) async {
    if (await _storage.read(key: _claveSesion) != claveDeSesion(tokenPJ)) return null;
    return _storage.read(key: _claveToken);
  }

  @override
  Future<void> guardar(String tokenPJ, String tokenSanctum) async {
    await _storage.write(key: _claveSesion, value: claveDeSesion(tokenPJ));
    await _storage.write(key: _claveToken, value: tokenSanctum);
  }

  @override
  Future<void> borrar() async {
    await _storage.delete(key: _claveSesion);
    await _storage.delete(key: _claveToken);
  }
}

/// Para tests y para quien no quiera persistir nada.
class AlmacenTokenMemoria implements AlmacenToken {
  String? _clave;
  String? _token;

  @override
  Future<String?> leer(String tokenPJ) async => _clave == claveDeSesion(tokenPJ) ? _token : null;

  @override
  Future<void> guardar(String tokenPJ, String tokenSanctum) async {
    _clave = claveDeSesion(tokenPJ);
    _token = tokenSanctum;
  }

  @override
  Future<void> borrar() async => _clave = _token = null;
}
```

`paquete/vehiculos_oficiales/lib/src/sesion/aviso_sesion.dart`:

```dart
/// Traduce los 401 de la API en **una sola** llamada a `onSesionInvalida` de la app principal
/// (spec 9), aunque fallen varios pedidos a la vez.
class AvisoSesionInvalida {
  AvisoSesionInvalida(this._callbackHost);

  final void Function() _callbackHost;
  final List<void Function()> _escuchas = [];
  bool _avisado = false;

  /// Mientras es `true`, un 401 no avisa (se usa al probar un token guardado).
  bool silenciado = false;

  bool get avisado => _avisado;

  void escuchar(void Function() escucha) => _escuchas.add(escucha);

  void avisar() {
    if (_avisado || silenciado) return;
    _avisado = true;
    for (final e in _escuchas) {
      e();
    }
    _callbackHost();
  }
}
```

`paquete/vehiculos_oficiales/lib/src/entorno.dart`:

```dart
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api/api_vehiculos.dart';
import 'api/cliente_api.dart';
import 'config.dart';
import 'puente_notificaciones.dart';
import 'sesion/almacen_token.dart';
import 'sesion/aviso_sesion.dart';

/// Lo que la app principal le pasa a `VehiculosOficiales.abrir`.
class EntornoModulo {
  const EntornoModulo({required this.config, required this.sesion, required this.push, required this.onSesionInvalida});

  final VehiculosOficialesConfig config;
  final SesionPJ sesion;
  final PuenteNotificaciones push;
  final void Function() onSesionInvalida;
}

/// Se sobreescribe en el `ProviderScope` que crea `VehiculosOficiales.abrir` (y en los tests).
final entornoProvider = Provider<EntornoModulo>((ref) => throw UnimplementedError('Falta el entorno del módulo'));

/// Adaptador HTTP de dio. Nulo = el real; los tests lo reemplazan.
final adaptadorHttpProvider = Provider<HttpClientAdapter?>((ref) => null);

final almacenTokenProvider = Provider<AlmacenToken>((ref) => AlmacenTokenSeguro());

final avisoSesionProvider = Provider<AvisoSesionInvalida>(
  (ref) => AvisoSesionInvalida(ref.watch(entornoProvider).onSesionInvalida),
);

final clienteApiProvider = Provider<ClienteApi>(
  (ref) => ClienteApi(
    baseApi: ref.watch(entornoProvider).config.apiUri,
    alRecibir401: () => ref.read(avisoSesionProvider).avisar(),
    adaptador: ref.watch(adaptadorHttpProvider),
  ),
);

final apiProvider = Provider<ApiVehiculos>((ref) => ApiVehiculos(ref.watch(clienteApiProvider)));
```

`paquete/vehiculos_oficiales/lib/src/sesion/sesion.dart`:

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';

sealed class EstadoSesion {
  const EstadoSesion();
}

class SesionIniciando extends EstadoSesion {
  const SesionIniciando();
}

class SesionLista extends EstadoSesion {
  const SesionLista(this.usuario);

  final Usuario usuario;
}

/// 503 del intercambio y sin token guardado válido: "Servicio de identidad no disponible" + reintentar.
class SesionIdentidadNoDisponible extends EstadoSesion {
  const SesionIdentidadNoDisponible();
}

/// 401 en cualquier pedido. Ya se llamó a `onSesionInvalida`.
class SesionVencida extends EstadoSesion {
  const SesionVencida();
}

/// 403 del intercambio: el usuario está deshabilitado en el panel.
class SesionDeshabilitada extends EstadoSesion {
  const SesionDeshabilitada(this.mensaje);

  final String mensaje;
}

/// Sin red u otro error: se puede reintentar.
class SesionConError extends EstadoSesion {
  const SesionConError(this.mensaje);

  final String mensaje;
}

final sesionProvider = NotifierProvider<SesionNotifier, EstadoSesion>(SesionNotifier.new);

/// Usuario de la sesión lista. Solo se lee debajo de las pantallas que requieren sesión.
final usuarioProvider = Provider<Usuario>((ref) {
  final s = ref.watch(sesionProvider);
  if (s is SesionLista) return s.usuario;
  throw StateError('No hay sesión');
});

class SesionNotifier extends Notifier<EstadoSesion> {
  @override
  EstadoSesion build() {
    final aviso = ref.watch(avisoSesionProvider);
    aviso.escuchar(() {
      state = const SesionVencida();
      ref.read(almacenTokenProvider).borrar();
    });
    Future.microtask(iniciar);
    return const SesionIniciando();
  }

  /// Intercambia el token del PJ por uno Sanctum (spec 3.2). También es el "Reintentar".
  Future<void> iniciar() async {
    final aviso = ref.read(avisoSesionProvider);
    if (aviso.avisado) return;

    state = const SesionIniciando();
    final tokenPJ = ref.read(entornoProvider).sesion.token;
    final api = ref.read(apiProvider);
    final almacen = ref.read(almacenTokenProvider);

    try {
      final r = await api.intercambiar(tokenPJ);
      api.cliente.token = r.token;
      await almacen.guardar(tokenPJ, r.token);
      if (ref.mounted) state = SesionLista(r.usuario);
    } on SesionInvalida {
      // El aviso ya pasó el estado a SesionVencida y llamó a la app principal.
    } on AccesoDenegado catch (e) {
      if (ref.mounted) state = SesionDeshabilitada(e.mensaje);
    } on ServicioNoDisponible {
      final respaldo = await _conTokenGuardado(tokenPJ);
      if (ref.mounted) state = respaldo ?? const SesionIdentidadNoDisponible();
    } on ErrorApi catch (e) {
      if (ref.mounted) state = SesionConError(e.mensaje);
    }
  }

  /// Spec 9: si el PJ no responde pero el token Sanctum de esta misma sesión sigue vigente, se sigue.
  Future<EstadoSesion?> _conTokenGuardado(String tokenPJ) async {
    final guardado = await ref.read(almacenTokenProvider).leer(tokenPJ);
    if (guardado == null) return null;

    final api = ref.read(apiProvider);
    final aviso = ref.read(avisoSesionProvider);
    api.cliente.token = guardado;
    aviso.silenciado = true; // un 401 acá significa "token guardado vencido", no "sesión del PJ inválida"
    try {
      return SesionLista(await api.yo());
    } on ErrorApi {
      api.cliente.token = null;
      return null;
    } finally {
      aviso.silenciado = false;
    }
  }
}
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 32 PASS, sin problemas, `0 changed`.

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: sesión del módulo por intercambio de token con aviso único de sesión inválida" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Tiempo real con Reverb (protocolo Pusher)

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/tiempo_real/{tiempo_real,tiempo_real_pusher}.dart`
- Test: `paquete/vehiculos_oficiales/test/tiempo_real_pusher_test.dart`

**Interfaces:**
- Consumes: `ApiVehiculos.autorizarCanal` (Task 3), `VehiculosOficialesConfig` (Task 1).
- Produces:
  - `enum EstadoConexion {conectando, conectado, desconectado}`; `EventoTiempoReal(canal, nombre, datos)` (canal sin `private-`).
  - `Canales.mapaChoferes`, `Canales.viaje(id)`, `Canales.chofer(id)`; `Eventos.viajeActualizado`, `ofertaCreada`, `choferUbicacion`, `choferEstado`.
  - `abstract interface class TiempoReal { EstadoConexion estado; Stream<EstadoConexion> estados; Stream<EventoTiempoReal> canal(String nombre); void conectar(); void cerrar(); }`.
  - `TiempoRealPusher({config, api, PusherChannelsConnection Function()? conexion, Duration esperaReconexion = 3 s})` y `AutorizacionCanalApi(api)`.

- [ ] **Step 1: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/tiempo_real_pusher_test.dart` (incluye un servidor Pusher mínimo en memoria):

```dart
import 'dart:async';
import 'dart:convert';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_pusher.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/adaptador_falso.dart';
import 'soporte/entorno_prueba.dart';

/// Servidor Pusher mínimo en memoria: responde connection_established y confirma suscripciones.
class ConexionFalsa implements PusherChannelsConnection {
  ConexionFalsa(this.servidor);

  final ServidorFalso servidor;
  PusherChannelsConnectionOnEventCallback? _alEvento;
  PusherChannelsConnectionOnDoneCallback? _alCerrar;

  @override
  void connect({
    required PusherChannelsConnectionOnDoneCallback onDoneCallback,
    required PusherChannelsConnectionOnErrorCallback onErrorCallback,
    required PusherChannelsConnectionOnEventCallback onEventCallback,
  }) {
    _alEvento = onEventCallback;
    _alCerrar = onDoneCallback;
    servidor.conexiones.add(this);
    scheduleMicrotask(
      () => onEventCallback(
        jsonEncode({
          'event': 'pusher:connection_established',
          'data': jsonEncode({'socket_id': '1234.5678', 'activity_timeout': 120}),
        }),
      ),
    );
  }

  @override
  void sendEvent(String eventEncoded) {
    final e = jsonDecode(eventEncoded) as Map<String, dynamic>;
    servidor.recibidos.add(e);
    if (e['event'] == 'pusher:subscribe') {
      final canal = (e['data'] as Map)['channel'] as String;
      scheduleMicrotask(
        () => _alEvento?.call(
          jsonEncode({'event': 'pusher_internal:subscription_succeeded', 'channel': canal, 'data': '{}'}),
        ),
      );
    }
  }

  void emitir(String canal, String evento, String datos) =>
      _alEvento?.call(jsonEncode({'event': evento, 'channel': canal, 'data': datos}));

  void caer() => _alCerrar?.call();

  @override
  void ping() {}

  @override
  FutureOr<void> close() {}
}

class ServidorFalso {
  final conexiones = <ConexionFalsa>[];
  final recibidos = <Map<String, dynamic>>[];

  ConexionFalsa get actual => conexiones.last;
}

Future<void> vaciar() async {
  for (var i = 0; i < 20; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

void main() {
  late AdaptadorFalso http;
  late ServidorFalso servidor;
  late TiempoRealPusher tr;
  late int avisos401;

  setUp(() {
    http = AdaptadorFalso();
    servidor = ServidorFalso();
    avisos401 = 0;
    final cliente = ClienteApi(baseApi: configPrueba.apiUri, alRecibir401: () => avisos401++, adaptador: http)
      ..token = '1|sanctum';
    tr = TiempoRealPusher(
      config: configPrueba,
      api: ApiVehiculos(cliente),
      conexion: () => ConexionFalsa(servidor),
      esperaReconexion: const Duration(milliseconds: 10),
    );
  });

  tearDown(() => tr.cerrar());

  test('se conecta y autoriza el canal privado con el token Sanctum', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    final eventos = <EventoTiempoReal>[];

    tr.conectar();
    tr.canal(Canales.viaje(1)).listen(eventos.add);
    await vaciar();

    expect(tr.estado, EstadoConexion.conectado);
    final auth = http.pedidos.single;
    expect(auth.uri.toString(), 'http://10.0.2.2:8000/api/broadcasting/auth');
    expect(auth.headers['Authorization'], 'Bearer 1|sanctum');
    expect(Uri.splitQueryString(auth.cuerpo), {'socket_id': '1234.5678', 'channel_name': 'private-viaje.1'});
    expect(servidor.recibidos.single, {
      'event': 'pusher:subscribe',
      'data': {'channel': 'private-viaje.1', 'auth': p.json(p.autorizacionCanal)['auth']},
    });

    servidor.actual.emitir('private-viaje.1', Eventos.viajeActualizado, p.viajeAceptado);
    await vaciar();

    expect(eventos.single.canal, 'viaje.1');
    expect(eventos.single.nombre, 'viaje.actualizado');
    expect(eventos.single.datos['estado'], 'aceptado');
  });

  test('un 401 al autorizar el canal avisa la sesión inválida', () async {
    http.responder('POST', 'broadcasting/auth', 401, p.noAutenticado);

    tr.conectar();
    tr.canal(Canales.mapaChoferes).listen((_) {});
    await vaciar();

    expect(avisos401, 1);
    expect(servidor.recibidos.where((e) => e['event'] == 'pusher:subscribe'), isEmpty);
  });

  test('informa la caída de la conexión', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    final estados = <EstadoConexion>[];
    tr.estados.listen(estados.add);

    tr.conectar();
    tr.canal(Canales.mapaChoferes).listen((_) {});
    await vaciar();
    servidor.actual.caer();
    await vaciar();

    expect(
      estados,
      containsAllInOrder([EstadoConexion.conectando, EstadoConexion.conectado, EstadoConexion.desconectado]),
    );
  });

  test('tras una caída reconecta y vuelve a suscribirse con autorización nueva', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);
    final eventos = <EventoTiempoReal>[];

    tr.conectar();
    tr.canal(Canales.viaje(1)).listen(eventos.add);
    await vaciar();
    servidor.actual.caer();
    await Future<void>.delayed(const Duration(milliseconds: 100));
    await vaciar();

    expect(servidor.conexiones, hasLength(2));
    expect(tr.estado, EstadoConexion.conectado);
    expect(http.pedidos, hasLength(2));
    expect(servidor.recibidos.where((e) => e['event'] == 'pusher:subscribe'), hasLength(2));

    servidor.actual.emitir('private-viaje.1', Eventos.viajeActualizado, p.viajeAceptado);
    await vaciar();
    expect(eventos, hasLength(1));
  });

  test('al dejar de escuchar el canal se desuscribe', () async {
    http.responder('POST', 'broadcasting/auth', 200, p.autorizacionCanal);

    tr.conectar();
    final s = tr.canal(Canales.chofer(2)).listen((_) {});
    await vaciar();
    await s.cancel();
    await vaciar();

    expect(servidor.recibidos.last, {
      'event': 'pusher:unsubscribe',
      'data': {'channel': 'private-chofer.2'},
    });
  });
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `flutter test test/tiempo_real_pusher_test.dart`
Expected: FAIL (no existen `tiempo_real/…`).

- [ ] **Step 3: Implementación**

`paquete/vehiculos_oficiales/lib/src/tiempo_real/tiempo_real.dart`:

```dart
import '../modelos/json.dart';

enum EstadoConexion { conectando, conectado, desconectado }

/// Un evento de Reverb: `canal` sin el prefijo `private-`, `nombre` = `broadcastAs()` del backend.
class EventoTiempoReal {
  const EventoTiempoReal(this.canal, this.nombre, this.datos);

  final String canal;
  final String nombre;
  final Json datos;
}

/// Nombres de canal y de evento del backend (routes/channels.php y app/Events).
abstract final class Canales {
  static const mapaChoferes = 'mapa.choferes';
  static String viaje(int id) => 'viaje.$id';
  static String chofer(int id) => 'chofer.$id';
}

abstract final class Eventos {
  static const viajeActualizado = 'viaje.actualizado';
  static const ofertaCreada = 'oferta.creada';
  static const choferUbicacion = 'chofer.ubicacion';
  static const choferEstado = 'chofer.estado';
}

/// Conexión de tiempo real. La implementación real usa el protocolo Pusher contra Reverb; los tests usan
/// un doble que permite simular caídas.
abstract interface class TiempoReal {
  EstadoConexion get estado;

  /// Cambios de estado de la conexión (broadcast).
  Stream<EstadoConexion> get estados;

  /// Eventos de un canal privado (nombre sin `private-`). Suscribe al escuchar y desuscribe al cancelar
  /// la última escucha. Tras una reconexión se vuelve a suscribir solo.
  Stream<EventoTiempoReal> canal(String nombre);

  void conectar();

  void cerrar();
}
```

`paquete/vehiculos_oficiales/lib/src/tiempo_real/tiempo_real_pusher.dart`:

```dart
import 'dart:async';

import 'package:dart_pusher_channels/dart_pusher_channels.dart';

import '../api/api_vehiculos.dart';
import '../config.dart';
import 'tiempo_real.dart';

/// Autoriza los canales privados contra `POST /api/broadcasting/auth` con el token **Sanctum**
/// (vía [ApiVehiculos], así un 401 también dispara `onSesionInvalida`).
class AutorizacionCanalApi
    implements EndpointAuthorizableChannelAuthorizationDelegate<PrivateChannelAuthorizationData> {
  AutorizacionCanalApi(this._api);

  final ApiVehiculos _api;

  @override
  EndpointAuthFailedCallback? get onAuthFailed => null;

  @override
  Future<PrivateChannelAuthorizationData> authorizationData(String socketId, String channelName) async =>
      PrivateChannelAuthorizationData(
        authKey: await _api.autorizarCanal(socketId: socketId, canal: channelName),
      );
}

/// [TiempoReal] sobre `dart_pusher_channels` (Dart puro: Android, iOS y web) contra Laravel Reverb.
class TiempoRealPusher implements TiempoReal {
  TiempoRealPusher({
    required VehiculosOficialesConfig config,
    required ApiVehiculos api,
    PusherChannelsConnection Function()? conexion,
    Duration esperaReconexion = const Duration(seconds: 3),
  }) : _autorizacion = AutorizacionCanalApi(api) {
    final opciones = PusherChannelsOptions.fromHost(
      scheme: config.reverbWsScheme,
      host: config.reverbHost,
      port: config.reverbPort,
      key: config.reverbKey,
      shouldSupplyMetadataQueries: true,
      metadata: PusherChannelsOptionsMetadata.byDefault(),
    );
    void alFallar(Object? error, StackTrace traza, void Function() reintentar) {
      _cambiar(EstadoConexion.desconectado);
      reintentar(); // el cliente espera minimumReconnectDelayDuration entre intentos
    }

    _cliente = conexion == null
        ? PusherChannelsClient.websocket(
            options: opciones,
            connectionErrorHandler: alFallar,
            minimumReconnectDelayDuration: esperaReconexion,
          )
        : PusherChannelsClient.custom(
            connectionDelegate: conexion,
            connectionErrorHandler: alFallar,
            minimumReconnectDelayDuration: esperaReconexion,
          );

    _suscripciones.add(
      _cliente.lifecycleStream.listen((ciclo) {
        switch (ciclo) {
          case PusherChannelsClientLifeCycleState.establishedConnection:
            _cambiar(EstadoConexion.conectado);
          case PusherChannelsClientLifeCycleState.pendingConnection:
            _cambiar(EstadoConexion.conectando);
          // Tras una caída la librería pasa directo a reconnecting: el socket ya no está.
          case PusherChannelsClientLifeCycleState.reconnecting ||
              PusherChannelsClientLifeCycleState.connectionError ||
              PusherChannelsClientLifeCycleState.disconnected ||
              PusherChannelsClientLifeCycleState.gotPusherError ||
              PusherChannelsClientLifeCycleState.disposed:
            _cambiar(EstadoConexion.desconectado);
          case PusherChannelsClientLifeCycleState.inactive:
            break;
        }
      }),
    );
    // Recomendación de la librería: (re)suscribir en cada conexión establecida.
    _suscripciones.add(
      _cliente.onConnectionEstablished.listen((_) {
        for (final c in _canales.values) {
          c.canal.subscribeIfNotUnsubscribed();
        }
      }),
    );
  }

  late final PusherChannelsClient _cliente;
  final AutorizacionCanalApi _autorizacion;
  final _estados = StreamController<EstadoConexion>.broadcast();
  final _canales = <String, _CanalActivo>{};
  final _suscripciones = <StreamSubscription<Object?>>[];
  EstadoConexion _estado = EstadoConexion.desconectado;

  @override
  EstadoConexion get estado => _estado;

  @override
  Stream<EstadoConexion> get estados => _estados.stream;

  void _cambiar(EstadoConexion nuevo) {
    if (nuevo == _estado) return;
    _estado = nuevo;
    _estados.add(nuevo);
  }

  @override
  void conectar() {
    _cambiar(EstadoConexion.conectando);
    unawaited(_cliente.connect());
  }

  @override
  Stream<EventoTiempoReal> canal(String nombre) {
    final existente = _canales[nombre];
    if (existente != null) return existente.eventos.stream;

    final privado = _cliente.privateChannel('private-$nombre', authorizationDelegate: _autorizacion);
    late final _CanalActivo activo;
    StreamSubscription<ChannelReadEvent>? escucha;
    final controlador = StreamController<EventoTiempoReal>.broadcast(
      onListen: () {
        escucha = privado.bindToAll().listen((e) {
          if (e.name.startsWith('pusher:') || e.name.startsWith('pusher_internal:')) return;
          final datos = e.tryGetDataAsMap();
          if (datos != null) activo.eventos.add(EventoTiempoReal(nombre, e.name, datos));
        });
        privado.subscribe();
      },
      onCancel: () {
        unawaited(escucha?.cancel());
        privado.unsubscribe();
        _canales.remove(nombre);
      },
    );
    activo = _CanalActivo(privado, controlador);
    _canales[nombre] = activo;
    return controlador.stream;
  }

  @override
  void cerrar() {
    for (final s in _suscripciones) {
      unawaited(s.cancel());
    }
    for (final c in _canales.values) {
      unawaited(c.eventos.close());
    }
    _canales.clear();
    _cliente.dispose();
    _cambiar(EstadoConexion.desconectado);
    unawaited(_estados.close());
  }
}

class _CanalActivo {
  _CanalActivo(this.canal, this.eventos);

  final PrivateChannel canal;
  final StreamController<EventoTiempoReal> eventos;
}
```

- [ ] **Step 4: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 37 PASS, sin problemas, `0 changed`.

- [ ] **Step 5: Commit**

```bash
git add paquete
git commit -m "feat: cliente de Reverb con canales privados autorizados con el token Sanctum" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Viaje actual en vivo con respaldo por sondeo

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/tiempo_real/{respaldo,tiempo_real_provider}.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/viaje/viaje_actual.dart`
- Create: `paquete/vehiculos_oficiales/test/soporte/dobles.dart`
- Test: `paquete/vehiculos_oficiales/test/viaje_actual_test.dart`

**Interfaces:**
- Consumes: `TiempoReal`, `Canales`, `Eventos` (Task 5); `apiProvider`, `usuarioProvider` (Task 4); modelos.
- Produces:
  - `Respaldo({tiempoReal, intervalo, refrescar})` con `sondeando` y `cerrar()`.
  - `tiempoRealProvider` (crea y conecta `TiempoRealPusher`), `intervaloRespaldoProvider` (10 s), `estadoConexionProvider` (`EstadoConexion`).
  - `SeguimientoViaje({viaje?, oferta?, ubicacionChofer?})` y `viajeActualProvider` (`AsyncNotifier`): `refrescar()`, `pedir(PedidoViaje) → Viaje`, `cancelar({motivo})`, `descartar()`. Para el chofer escucha `chofer.{id}` (ofertas inmediatas, asignaciones y cambios de su viaje); el plan siguiente lo usa tal cual.
  - Test: `solicitante`, `chofer` (usuarios), `viaje({id, estado, conChofer, obligatorio, tipo})`, `jsonViaje(v, {conChofer})`, `ApiFalsa` (respuestas y contadores programables) y `TiempoRealFalso` (`cambiar(estado)`, `emitir(canal, evento, datos)`, `canalesActivos`).

- [ ] **Step 1: Dobles de la API y de Reverb**

`paquete/vehiculos_oficiales/test/soporte/dobles.dart` (la Task 11 le agrega `UbicadorFalso`):

```dart
import 'dart:async';

import 'package:vehiculos_oficiales/src/api/api_vehiculos.dart';
import 'package:vehiculos_oficiales/src/api/cliente_api.dart';
import 'package:vehiculos_oficiales/src/api/errores_api.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';

import '../fixtures/payloads.dart' as p;
import 'adaptador_falso.dart';
import 'entorno_prueba.dart';

const solicitante = Usuario(id: 1, nombre: 'Ana Pérez', cargo: 'Secretaria', rol: Rol.solicitante);
const chofer = Usuario(id: 2, nombre: 'Carlos Gómez', cargo: 'Chofer', rol: Rol.chofer);

/// Un viaje real (fixture) con el estado pedido. `conChofer` agrega chofer 2 y vehículo.
Viaje viaje({
  int id = 1,
  String estado = 'ofrecido',
  bool conChofer = false,
  bool obligatorio = false,
  String tipo = 'inmediato',
}) {
  final j = p.json(conChofer ? p.viajeAceptado : p.viajeOfrecido)
    ..['id'] = id
    ..['estado'] = estado
    ..['obligatorio'] = obligatorio
    ..['tipo'] = tipo;
  return Viaje.fromJson(j);
}

Json jsonViaje(Viaje v, {bool conChofer = true}) {
  final j = p.json(conChofer ? p.viajeAceptado : p.viajeOfrecido)
    ..['id'] = v.id
    ..['estado'] = v.estado.valor
    ..['obligatorio'] = v.obligatorio
    ..['tipo'] = v.tipo.name;
  return j;
}

/// API con respuestas programables. Lo que no se programa falla como "sin respuesta preparada".
class ApiFalsa extends ApiVehiculos {
  ApiFalsa() : super(ClienteApi(baseApi: configPrueba.apiUri, alRecibir401: () {}, adaptador: AdaptadorFalso()));

  ViajeActual actual = ViajeActual.vacio;
  MisViajes mis = const MisViajes(proximas: [], historial: []);
  List<ChoferEnMapa> listaChoferes = [];
  ErrorApi? fallarConsultas;
  int consultasActual = 0;
  int consultasChoferes = 0;
  final pedidos = <PedidoViaje>[];
  final cancelaciones = <(int, String?)>[];
  Viaje? respuestaPedido;
  ErrorApi? errorPedido;

  @override
  Future<ViajeActual> viajeActual() async {
    consultasActual++;
    if (fallarConsultas != null) throw fallarConsultas!;
    return actual;
  }

  @override
  Future<MisViajes> misViajes() async => mis;

  @override
  Future<List<ChoferEnMapa>> choferes() async {
    consultasChoferes++;
    if (fallarConsultas != null) throw fallarConsultas!;
    return listaChoferes;
  }

  @override
  Future<Viaje> pedirViaje(PedidoViaje pedido) async {
    pedidos.add(pedido);
    if (errorPedido != null) throw errorPedido!;
    return respuestaPedido ?? viaje();
  }

  @override
  Future<Viaje> cancelarViaje(int viajeId, {String? motivo}) async {
    cancelaciones.add((viajeId, motivo));
    return viaje(id: viajeId, estado: 'cancelado');
  }
}

/// Reverb en memoria: se controla el estado de la conexión y se emiten eventos a mano.
class TiempoRealFalso implements TiempoReal {
  TiempoRealFalso({EstadoConexion estado = EstadoConexion.conectado}) : _estado = estado;

  EstadoConexion _estado;
  final _estados = StreamController<EstadoConexion>.broadcast(sync: true);
  final _canales = <String, StreamController<EventoTiempoReal>>{};

  Set<String> get canalesActivos => _canales.keys.toSet();

  @override
  EstadoConexion get estado => _estado;

  @override
  Stream<EstadoConexion> get estados => _estados.stream;

  void cambiar(EstadoConexion e) {
    _estado = e;
    _estados.add(e);
  }

  void emitir(String canal, String evento, Json datos) => _canales[canal]?.add(EventoTiempoReal(canal, evento, datos));

  @override
  Stream<EventoTiempoReal> canal(String nombre) {
    final c = _canales[nombre] ??= StreamController<EventoTiempoReal>.broadcast(
      sync: true,
      onCancel: () => _canales.remove(nombre),
    );
    return c.stream;
  }

  @override
  void conectar() {}

  @override
  void cerrar() {}
}
```

- [ ] **Step 2: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/viaje_actual_test.dart`:

```dart
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
import 'soporte/entorno_prueba.dart';

void main() {
  late ApiFalsa api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiFalsa();
    tr = TiempoRealFalso();
  });

  ProviderContainer crear({Usuario usuario = solicitante}) {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
      usuarioProvider.overrideWithValue(usuario),
    ]);
    c.listen(viajeActualProvider, (_, _) {});
    return c;
  }

  SeguimientoViaje leer(ProviderContainer c) => c.read(viajeActualProvider).requireValue;

  test('carga el viaje actual y escucha su canal', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      expect(leer(c).viaje!.estado, EstadoViaje.ofrecido);
      expect(tr.canalesActivos, {'viaje.1'});
    });
  });

  test('con el socket conectado se actualiza por eventos y no consulta la API', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(p.viajeAceptado));
      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
      async.elapse(const Duration(seconds: 30));

      expect(leer(c).viaje!.estado, EstadoViaje.aceptado);
      expect(leer(c).viaje!.chofer!.nombre, 'Carlos Gómez');
      expect(leer(c).ubicacionChofer!.posicion, const Coordenada(-26.8301, -65.2001));
      expect(api.consultasActual, 1);
    });
  });

  test('con el socket caído consulta cada 10 s y mantiene el viaje al día', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      api.listaChoferes = [ChoferEnMapa.fromJson(leerMapa(p.jsonLista(p.choferes).single))];
      final c = crear();
      async.flushMicrotasks();

      tr.cambiar(EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'en_camino', conChofer: true));
      async.elapse(const Duration(seconds: 9));
      expect(leer(c).viaje!.estado, EstadoViaje.aceptado);

      async.elapse(const Duration(seconds: 1));
      expect(leer(c).viaje!.estado, EstadoViaje.enCamino);
      expect(leer(c).ubicacionChofer!.posicion, const Coordenada(-26.8301, -65.2001));

      api.actual = ViajeActual(viaje: viaje(estado: 'llego', conChofer: true));
      async.elapse(const Duration(seconds: 10));
      expect(leer(c).viaje!.estado, EstadoViaje.llego);
      expect(api.consultasActual, 3);
    });
  });

  test('al reconectar pide el estado completo y deja de consultar', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();
      async.elapse(const Duration(seconds: 10));
      expect(api.consultasActual, 2);

      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      tr.cambiar(EstadoConexion.conectado);
      async.flushMicrotasks();
      expect(api.consultasActual, 3);
      expect(leer(c).viaje!.estado, EstadoViaje.aceptado);

      async.elapse(const Duration(seconds: 60));
      expect(api.consultasActual, 3);
    });
  });

  test('si el viaje desaparece mientras no había socket, muestra cómo terminó', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      api.actual = ViajeActual.vacio;
      api.mis = MisViajes(
        proximas: const [],
        historial: [viaje(estado: 'sin_chofer')],
      );
      async.elapse(const Duration(seconds: 10));

      expect(leer(c).viaje!.estado, EstadoViaje.sinChofer);
      expect(tr.canalesActivos, isEmpty);
    });
  });

  test('un error de red durante el respaldo conserva el último estado', () {
    fakeAsync((async) {
      tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
      api.actual = ViajeActual(viaje: viaje(estado: 'ofrecido'));
      final c = crear();
      async.flushMicrotasks();

      api.fallarConsultas = const SinConexion();
      async.elapse(const Duration(seconds: 20));

      expect(leer(c).viaje!.estado, EstadoViaje.ofrecido);
      expect(c.read(viajeActualProvider).hasError, isFalse);
    });
  });

  test('pedir guarda el viaje y lo sigue; cancelar lo deja cancelado; descartar vuelve a cero', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();
      api.respuestaPedido = viaje(id: 7, estado: 'buscando');

      c
          .read(viajeActualProvider.notifier)
          .pedir(
            const PedidoViaje(
              modo: ModoViaje.masCercano,
              origen: Lugar(Coordenada(1, 1)),
              destino: Lugar(Coordenada(2, 2)),
            ),
          );
      async.flushMicrotasks();
      expect(leer(c).viaje!.id, 7);
      expect(tr.canalesActivos, {'viaje.7'});

      c.read(viajeActualProvider.notifier).cancelar();
      async.flushMicrotasks();
      expect(api.cancelaciones.single, (7, null));
      expect(leer(c).viaje!.estado, EstadoViaje.cancelado);
      expect(tr.canalesActivos, isEmpty);

      c.read(viajeActualProvider.notifier).descartar();
      expect(leer(c).viaje, isNull);
    });
  });

  test('ignora eventos de otros viajes y ubicaciones de otros choferes', () {
    fakeAsync((async) {
      api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('viaje.1', Eventos.viajeActualizado, jsonViaje(viaje(id: 99, estado: 'finalizado')));
      tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion)..['chofer_id'] = 55);

      expect(leer(c).viaje!.id, 1);
      expect(leer(c).ubicacionChofer, isNull);
    });
  });

  group('chofer', () {
    test('escucha su canal: una oferta inmediata aparece y una de reserva no', () {
      fakeAsync((async) {
        final c = crear(usuario: chofer);
        async.flushMicrotasks();
        expect(tr.canalesActivos, {'chofer.2'});

        tr.emitir('chofer.2', Eventos.ofertaCreada, p.json(p.eventoOfertaCreada)); // es de una reserva
        expect(leer(c).oferta, isNull);

        final inmediata = p.json(p.viajeActualChofer)['oferta'] as Map<String, dynamic>;
        tr.emitir('chofer.2', Eventos.ofertaCreada, {...inmediata, 'oferta_id': inmediata['id']});
        expect(leer(c).oferta!.id, 1);
        expect(leer(c).oferta!.venceEn, DateTime.utc(2026, 10, 1, 12, 0, 30));
      });
    });

    test(
      'la oferta se va cuando el viaje deja de estar ofrecido y un obligatorio asignado se vuelve el viaje actual',
      () {
        fakeAsync((async) {
          api.actual = ViajeActual.fromJson(p.json(p.viajeActualChofer));
          final c = crear(usuario: chofer);
          async.flushMicrotasks();
          expect(leer(c).oferta, isNotNull);

          tr.emitir('chofer.2', Eventos.viajeActualizado, jsonViaje(viaje(estado: 'buscando'), conChofer: false));
          expect(leer(c).oferta, isNull);
          expect(leer(c).viaje, isNull);

          tr.emitir(
            'chofer.2',
            Eventos.viajeActualizado,
            jsonViaje(viaje(id: 3, estado: 'aceptado', obligatorio: true)),
          );
          expect(leer(c).viaje!.id, 3);
          expect(leer(c).viaje!.obligatorio, isTrue);
        });
      },
    );
  });
}
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test test/viaje_actual_test.dart`
Expected: FAIL (no existen `respaldo.dart`, `tiempo_real_provider.dart` ni `viaje_actual.dart`).

- [ ] **Step 4: Implementación**

`paquete/vehiculos_oficiales/lib/src/tiempo_real/respaldo.dart`:

```dart
import 'dart:async';

import 'tiempo_real.dart';

/// Spec 6: mientras el WebSocket no está conectado, llama a [refrescar] cada [intervalo]; cuando se
/// (re)conecta, corta el sondeo y llama a [refrescar] una vez para traer el estado completo.
class Respaldo {
  Respaldo({required TiempoReal tiempoReal, required this.intervalo, required this.refrescar}) : _tr = tiempoReal {
    _escucha = _tr.estados.listen(_alCambiar);
    if (_tr.estado != EstadoConexion.conectado) _sondear();
  }

  final TiempoReal _tr;
  final Duration intervalo;
  final Future<void> Function() refrescar;
  late final StreamSubscription<EstadoConexion> _escucha;
  Timer? _timer;

  bool get sondeando => _timer?.isActive ?? false;

  void _alCambiar(EstadoConexion estado) {
    if (estado == EstadoConexion.conectado) {
      _timer?.cancel();
      _timer = null;
      unawaited(refrescar());
    } else {
      _sondear();
    }
  }

  void _sondear() {
    if (sondeando) return;
    _timer = Timer.periodic(intervalo, (_) => unawaited(refrescar()));
  }

  void cerrar() {
    _timer?.cancel();
    unawaited(_escucha.cancel());
  }
}
```

`paquete/vehiculos_oficiales/lib/src/tiempo_real/tiempo_real_provider.dart`:

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import 'tiempo_real.dart';
import 'tiempo_real_pusher.dart';

/// Conexión única a Reverb. Se crea (y conecta) la primera vez que la lee una pantalla con sesión lista.
final tiempoRealProvider = Provider<TiempoReal>((ref) {
  final tr = TiempoRealPusher(config: ref.watch(entornoProvider).config, api: ref.watch(apiProvider));
  ref.onDispose(tr.cerrar);
  tr.conectar();
  return tr;
});

/// Spec 6: con el WebSocket caído se consulta la API cada 10 s.
final intervaloRespaldoProvider = Provider<Duration>((ref) => const Duration(seconds: 10));

/// Estado del socket, para avisar en pantalla cuando se está actualizando por sondeo.
final estadoConexionProvider = NotifierProvider<EstadoConexionNotifier, EstadoConexion>(EstadoConexionNotifier.new);

class EstadoConexionNotifier extends Notifier<EstadoConexion> {
  @override
  EstadoConexion build() {
    final tr = ref.watch(tiempoRealProvider);
    final escucha = tr.estados.listen((e) => state = e);
    ref.onDispose(() => unawaited(escucha.cancel()));
    return tr.estado;
  }
}
```

`paquete/vehiculos_oficiales/lib/src/viaje/viaje_actual.dart`:

```dart
import 'dart:async';

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
    try {
      final nuevo = await _consultar(state.value?.viaje);
      if (!ref.mounted) return;
      state = AsyncData(nuevo);
      _seguir(nuevo.viaje);
    } on SesionInvalida {
      rethrow;
    } on ErrorApi {
      // Sin red o error pasajero: se conserva lo último que se sabía y se reintenta en el próximo ciclo.
    } finally {
      _consultando = false;
    }
  }

  /// Pedido inmediato (spec 5.2 y 5.3). Los errores (422, etc.) llegan a la pantalla.
  Future<Viaje> pedir(PedidoViaje pedido) async {
    final v = await ref.read(apiProvider).pedirViaje(pedido);
    state = AsyncData(SeguimientoViaje(viaje: v));
    _seguir(v);
    return v;
  }

  Future<void> cancelar({String? motivo}) async {
    final actual = state.value?.viaje;
    if (actual == null) return;
    final v = await ref.read(apiProvider).cancelarViaje(actual.id, motivo: motivo);
    _aplicarViaje(v);
  }

  /// El usuario ya vio el estado final (finalizado, cancelado, sin chofer): se vuelve al mapa.
  void descartar() {
    state = const AsyncData(SeguimientoViaje());
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

    UbicacionChofer? ubicacion = viaje?.chofer?.id == state.value?.viaje?.chofer?.id
        ? state.value?.ubicacionChofer
        : null;
    final choferId = viaje?.chofer?.id;
    if (choferId != null && !_usuario.esChofer && _tr.estado != EstadoConexion.conectado) {
      // Sin socket tampoco llegan las posiciones: se toman de GET /choferes.
      final c = (await api.choferes()).where((c) => c.id == choferId).firstOrNull;
      if (c?.posicion != null) {
        ubicacion = UbicacionChofer(
          choferId: c!.id,
          posicion: c.posicion!,
          rumbo: c.rumbo,
          actualizadoEn: c.actualizadoEn ?? DateTime.now().toUtc(),
        );
      }
    }
    return SeguimientoViaje(viaje: viaje, oferta: actual.oferta, ubicacionChofer: ubicacion);
  }

  void _alEvento(EventoTiempoReal e) {
    final actual = state.value;
    if (actual == null) return;

    switch (e.nombre) {
      case Eventos.viajeActualizado:
        _aplicarViaje(Viaje.fromJson(e.datos));
      case Eventos.ofertaCreada:
        final oferta = Oferta.fromJson(e.datos);
        // Las solicitudes de reserva van a la agenda, no a la pantalla de oferta (AvisosViaje / ViajeController::actual).
        if (oferta.viaje.tipo == TipoViaje.inmediato) state = AsyncData(actual.conOferta(oferta));
      case Eventos.choferUbicacion:
        final u = UbicacionChofer.fromJson(e.datos);
        if (actual.viaje?.chofer?.id == u.choferId) state = AsyncData(actual.conUbicacion(u));
    }
  }

  void _aplicarViaje(Viaje v) {
    final actual = state.value ?? const SeguimientoViaje();
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

    state = AsyncData(nuevo);
    _seguir(nuevo.viaje);
  }

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

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 47 PASS, sin problemas, `0 changed`.

Verificación de que el sondeo está cubierto: cambiar temporalmente `if (sondeando) return;` por `return;` en `Respaldo._sondear` y correr `flutter test test/viaje_actual_test.dart`. Expected: 3 FAIL ("con el socket caído…", "al reconectar…", "si el viaje desaparece…"). Deshacer el cambio.

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: viaje actual en vivo por Reverb con sondeo cada 10 s si se cae el socket" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Puente de notificaciones push

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/push/push_modulo.dart`
- Test: `paquete/vehiculos_oficiales/test/push_test.dart`

**Interfaces:**
- Consumes: `entornoProvider.push`, `apiProvider.registrarTokenPush` (Task 4), `viajeActualProvider` (Task 6).
- Produces:
  - `AvisoPush({tipo, viajeId?, ofertaId?, estado?})` y `AvisoPush.desde(data)` (nulo si no es del módulo).
  - `PushModulo` con `Stream<AvisoPush> avisos`; `pushModuloProvider` (al leerse registra el token y empieza a escuchar). `tipo` `viaje`/`oferta` → `viajeActualProvider.notifier.refrescar()`.

- [ ] **Step 1: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/push_test.dart`:

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/push/push_modulo.dart';
import 'package:vehiculos_oficiales/src/sesion/sesion.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/viaje/viaje_actual.dart';

import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

class ApiConPush extends ApiFalsa {
  final tokens = <String>[];

  @override
  Future<void> registrarTokenPush(String token) async => tokens.add(token);
}

Future<void> vaciar() async {
  for (var i = 0; i < 10; i++) {
    await Future<void>.delayed(Duration.zero);
  }
}

void main() {
  late ApiConPush api;

  ProviderContainer crear(EntornoPrueba e) => e.contenedor([
    apiProvider.overrideWithValue(api),
    tiempoRealProvider.overrideWithValue(TiempoRealFalso()),
    usuarioProvider.overrideWithValue(solicitante),
  ]);

  setUp(() => api = ApiConPush());

  test('registra el token push del dispositivo', () async {
    final c = crear(EntornoPrueba(tokenPush: 'fcm-abc'));

    c.read(pushModuloProvider);
    await vaciar();

    expect(api.tokens, ['fcm-abc']);
  });

  test('sin token push no registra nada', () async {
    final c = crear(EntornoPrueba());

    c.read(pushModuloProvider);
    await vaciar();

    expect(api.tokens, isEmpty);
  });

  test('un push de viaje refresca el viaje actual y se publica tipado', () async {
    final e = EntornoPrueba();
    final c = crear(e);
    c.listen(viajeActualProvider, (_, _) {});
    final avisos = <AvisoPush>[];
    c.read(pushModuloProvider).avisos.listen(avisos.add);
    await vaciar();
    final antes = api.consultasActual;

    api.actual = ViajeActual(viaje: viaje(estado: 'aceptado', conChofer: true));
    e.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'viaje', 'viaje_id': '1', 'estado': 'aceptado'});
    await vaciar();

    expect(api.consultasActual, antes + 1);
    expect(c.read(viajeActualProvider).requireValue.viaje!.estado, EstadoViaje.aceptado);
    expect(avisos.single.viajeId, 1);
    expect(avisos.single.estado, 'aceptado');
  });

  test('ignora los mensajes de otros módulos de la app principal', () async {
    final e = EntornoPrueba();
    final c = crear(e);
    final avisos = <AvisoPush>[];
    c.read(pushModuloProvider).avisos.listen(avisos.add);
    await vaciar();

    e.puente.controlador.add({'tipo': 'viaje', 'viaje_id': '1'});
    e.puente.controlador.add({'modulo': 'expedientes', 'tipo': 'viaje'});
    await vaciar();

    expect(avisos, isEmpty);
  });

  test('lee oferta_reserva con ids numéricos', () {
    final a = AvisoPush.desde({
      'modulo': 'vehiculos_oficiales',
      'tipo': 'oferta_reserva',
      'oferta_id': '3',
      'viaje_id': '2',
    })!;

    expect(a.tipo, 'oferta_reserva');
    expect(a.ofertaId, 3);
    expect(a.viajeId, 2);
  });
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `flutter test test/push_test.dart`
Expected: FAIL (no existe `push/push_modulo.dart`).

- [ ] **Step 3: Implementación**

`paquete/vehiculos_oficiales/lib/src/push/push_modulo.dart`:

```dart
import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../viaje/viaje_actual.dart';

/// `data` de un push del módulo (NotificadorFcm: todo string, con `modulo = vehiculos_oficiales`).
class AvisoPush {
  const AvisoPush({required this.tipo, this.viajeId, this.ofertaId, this.estado});

  static const modulo = 'vehiculos_oficiales';

  /// `oferta`, `oferta_reserva`, `viaje`, `recordatorio_reserva` o `alerta_reserva` (AvisosViaje y jobs).
  final String tipo;
  final int? viajeId;
  final int? ofertaId;
  final String? estado;

  /// Nulo si el mensaje no es de este módulo.
  static AvisoPush? desde(Map<String, dynamic> data) {
    if (data['modulo']?.toString() != modulo || data['tipo'] == null) return null;
    int? entero(String clave) => int.tryParse(data[clave]?.toString() ?? '');
    return AvisoPush(
      tipo: data['tipo'].toString(),
      viajeId: entero('viaje_id'),
      ofertaId: entero('oferta_id'),
      estado: data['estado']?.toString(),
    );
  }
}

/// Registra el token push y reacciona a los mensajes que reenvía la app principal (spec 12.3).
class PushModulo {
  PushModulo(this._ref);

  final Ref _ref;
  final _avisos = StreamController<AvisoPush>.broadcast();
  StreamSubscription<Map<String, dynamic>>? _escucha;

  /// Avisos del módulo, para que otras pantallas (mis viajes, agenda) se refresquen.
  Stream<AvisoPush> get avisos => _avisos.stream;

  Future<void> iniciar() async {
    final puente = _ref.read(entornoProvider).push;
    _escucha = puente.mensajes.listen(_alRecibir);
    try {
      final token = await puente.token();
      if (token != null && token.isNotEmpty) await _ref.read(apiProvider).registrarTokenPush(token);
    } on ErrorApi catch (e) {
      debugPrint('vehiculos_oficiales: no se pudo registrar el token push: $e');
    }
  }

  void _alRecibir(Map<String, dynamic> data) {
    final aviso = AvisoPush.desde(data);
    if (aviso == null) return;
    if (aviso.tipo == 'viaje' || aviso.tipo == 'oferta') {
      // El push puede llegar antes que el evento del socket (o sin socket): se pide el estado a la API.
      unawaited(_ref.read(viajeActualProvider.notifier).refrescar());
    }
    _avisos.add(aviso);
  }

  void cerrar() {
    unawaited(_escucha?.cancel());
    unawaited(_avisos.close());
  }
}

/// Se activa al leerlo desde la pantalla raíz con sesión lista.
final pushModuloProvider = Provider<PushModulo>((ref) {
  final push = PushModulo(ref);
  ref.onDispose(push.cerrar);
  unawaited(push.iniciar());
  return push;
});
```

- [ ] **Step 4: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 52 PASS, sin problemas, `0 changed`.

- [ ] **Step 5: Commit**

```bash
git add paquete
git commit -m "feat: puente push: registro del token y reacción a los avisos del módulo" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Punto de entrada, navegación propia y pantallas de sesión

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/mapa/{mapa,mapa_google}.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/ui/sesion/pantalla_inicio.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/ui/chofer/inicio_chofer.dart` (provisoria)
- Create: `paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart` (provisoria; la Task 11 la reemplaza)
- Create: `paquete/vehiculos_oficiales/lib/src/vehiculos_oficiales.dart`
- Modify: `paquete/vehiculos_oficiales/lib/vehiculos_oficiales.dart`
- Create: `paquete/vehiculos_oficiales/test/soporte/montar.dart`
- Test: `paquete/vehiculos_oficiales/test/ui/modulo_test.dart`

**Interfaces:**
- Consumes: `sesionProvider`, `usuarioProvider`, `entornoProvider` (Task 4); `pushModuloProvider` (Task 7).
- Produces:
  - `VehiculosOficiales.abrir(context, {required SesionPJ sesion, required PuenteNotificaciones push, required VoidCallback onSesionInvalida, required VehiculosOficialesConfig config}) → Future<void>`.
  - `ModuloVehiculos({entorno, alCerrar, @visibleForTesting overrides})`, `cerrarModuloProvider`, `Rutas` (`/`, `/solicitante`, `/solicitante/viaje`, `/solicitante/reservar`, `/solicitante/mis-viajes`, `/chofer`). La redirección manda a `/` sin sesión lista y, con sesión, a `/chofer` o `/solicitante` según el rol.
  - `TipoMarcador`, `MarcadorMapa({id, posicion, tipo, titulo, alTocar})`, `DatosMapa({centro, marcadores, alTocarMapa})`, `typedef ConstructorMapa`, `constructorMapaProvider`, `MapaGoogle`.
  - `PantallaInicio`, `InicioChofer` (provisoria), `InicioSolicitante` (provisoria).
  - Test: `mapaDePrueba`, `puntoTocado`, `montarModulo(tester, entornoPrueba, {tiempoReal, extra})` y `esperar(tester)`.

- [ ] **Step 1: Montaje de prueba y mapa de prueba**

`paquete/vehiculos_oficiales/test/soporte/montar.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/mapa/mapa.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';
import 'package:vehiculos_oficiales/src/ui/modulo_app.dart';

import 'dobles.dart';
import 'entorno_prueba.dart';

/// Mapa de prueba: una lista de botones, uno por marcador, y un botón que "toca" el mapa en
/// [puntoTocado]. Así las pantallas se prueban sin Google Maps.
const puntoTocado = Coordenada(-26.8083, -65.2176);

Widget mapaDePrueba(BuildContext context, DatosMapa datos) {
  return ListView(
    key: const Key('mapa'),
    children: [
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

/// App principal de prueba con un botón que abre el módulo.
Future<void> montarModulo(
  WidgetTester tester,
  EntornoPrueba e, {
  TiempoRealFalso? tiempoReal,
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
```

- [ ] **Step 2: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/ui/modulo_test.dart`:

```dart
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
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test test/ui/modulo_test.dart`
Expected: FAIL (no existen `ui/…`, `mapa/…` ni `VehiculosOficiales`).

- [ ] **Step 4: Costura del mapa**

`paquete/vehiculos_oficiales/lib/src/mapa/mapa.dart`:

```dart
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../modelos/comunes.dart';
import 'mapa_google.dart';

enum TipoMarcador { choferLibre, choferNoDisponible, choferAsignado, origen, destino }

class MarcadorMapa {
  const MarcadorMapa({
    required this.id,
    required this.posicion,
    required this.tipo,
    required this.titulo,
    this.alTocar,
  });

  final String id;
  final Coordenada posicion;
  final TipoMarcador tipo;
  final String titulo;
  final VoidCallback? alTocar;
}

class DatosMapa {
  const DatosMapa({required this.centro, this.marcadores = const [], this.alTocarMapa});

  final Coordenada centro;
  final List<MarcadorMapa> marcadores;

  /// Si no es nulo, tocar el mapa elige un punto (origen o destino del pedido).
  final ValueChanged<Coordenada>? alTocarMapa;
}

/// Costura para no instanciar Google Maps en los tests: las pantallas dibujan el mapa con lo que
/// devuelva este provider (en producción, [MapaGoogle]).
typedef ConstructorMapa = Widget Function(BuildContext context, DatosMapa datos);

final constructorMapaProvider = Provider<ConstructorMapa>(
  (ref) =>
      (context, datos) => MapaGoogle(datos: datos),
);
```

`paquete/vehiculos_oficiales/lib/src/mapa/mapa_google.dart`:

```dart
import 'package:flutter/widgets.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import '../modelos/comunes.dart';
import 'mapa.dart';

/// Implementación real del mapa (google_maps_flutter). No se usa en los tests de widgets.
class MapaGoogle extends StatelessWidget {
  const MapaGoogle({super.key, required this.datos});

  final DatosMapa datos;

  static double _tono(TipoMarcador t) => switch (t) {
    TipoMarcador.choferLibre => BitmapDescriptor.hueGreen,
    TipoMarcador.choferNoDisponible => BitmapDescriptor.hueYellow,
    TipoMarcador.choferAsignado => BitmapDescriptor.hueAzure,
    TipoMarcador.origen => BitmapDescriptor.hueOrange,
    TipoMarcador.destino => BitmapDescriptor.hueRed,
  };

  @override
  Widget build(BuildContext context) {
    final alTocar = datos.alTocarMapa;
    return GoogleMap(
      initialCameraPosition: CameraPosition(target: LatLng(datos.centro.lat, datos.centro.lng), zoom: 14),
      myLocationButtonEnabled: false,
      mapToolbarEnabled: false,
      onTap: alTocar == null ? null : (p) => alTocar(Coordenada(p.latitude, p.longitude)),
      markers: {
        for (final m in datos.marcadores)
          Marker(
            markerId: MarkerId(m.id),
            position: LatLng(m.posicion.lat, m.posicion.lng),
            infoWindow: InfoWindow(title: m.titulo),
            // Spec 7 pide gris para los no disponibles: los marcadores por defecto no tienen gris,
            // se usan desvaídos hasta tener íconos propios.
            alpha: m.tipo == TipoMarcador.choferNoDisponible ? 0.45 : 1,
            icon: BitmapDescriptor.defaultMarkerWithHue(_tono(m.tipo)),
            onTap: m.alTocar,
          ),
      },
    );
  }
}
```

- [ ] **Step 5: Raíz del módulo, rutas y "atrás" del sistema**

`paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart` (las Tasks 10 y 12 le agregan rutas):

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
import 'sesion/pantalla_inicio.dart';
import 'solicitante/inicio_solicitante.dart';

/// Cierra el módulo y vuelve a la app principal. Lo define [ModuloVehiculos].
final cerrarModuloProvider = Provider<VoidCallback>((ref) => () {});

abstract final class Rutas {
  static const inicio = '/';
  static const solicitante = '/solicitante';
  static const viaje = '/solicitante/viaje';
  static const reservar = '/solicitante/reservar';
  static const misViajes = '/solicitante/mis-viajes';
  static const chofer = '/chofer';
}

/// Raíz del módulo: su propio `ProviderScope` y su propio router (no toca los de la app principal).
class ModuloVehiculos extends StatelessWidget {
  const ModuloVehiculos({super.key, required this.entorno, required this.alCerrar, this.overrides = const []});

  final EntornoModulo entorno;
  final VoidCallback alCerrar;

  /// Solo para tests (HTTP falso, Reverb falso, mapa de prueba…).
  @visibleForTesting
  final List<Override> overrides;

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      // Riverpod 3 reintenta por defecto los providers que fallan; acá los errores se muestran y se
      // reintentan a mano o por el respaldo de 10 s.
      retry: (_, _) => null,
      overrides: [
        entornoProvider.overrideWithValue(entorno),
        cerrarModuloProvider.overrideWithValue(alCerrar),
        ...overrides,
      ],
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
            ),
            GoRoute(path: Rutas.chofer, builder: (_, _) => const InicioChofer()),
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

- [ ] **Step 6: Pantallas de sesión y provisorias**

`paquete/vehiculos_oficiales/lib/src/ui/sesion/pantalla_inicio.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../sesion/sesion.dart';
import '../modulo_app.dart';

/// Primera pantalla: intercambio de sesión y sus errores (spec 3.2 y 9).
class PantallaInicio extends ConsumerWidget {
  const PantallaInicio({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sesion = ref.watch(sesionProvider);
    final reintentar = ref.read(sesionProvider.notifier).iniciar;
    final cerrar = ref.read(cerrarModuloProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(icon: const Icon(Icons.close), tooltip: 'Cerrar', onPressed: cerrar),
      ),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: switch (sesion) {
            SesionIniciando() || SesionLista() => const Column(
              mainAxisSize: MainAxisSize.min,
              children: [CircularProgressIndicator(), SizedBox(height: 16), Text('Conectando…')],
            ),
            SesionIdentidadNoDisponible() => _Aviso(
              icono: Icons.cloud_off,
              titulo: 'Servicio de identidad no disponible',
              detalle: 'No pudimos validar tu sesión con el Poder Judicial. Probá de nuevo en unos minutos.',
              accion: 'Reintentar',
              alAccionar: reintentar,
            ),
            SesionVencida() => _Aviso(
              icono: Icons.lock_clock,
              titulo: 'Tu sesión venció',
              detalle: 'Volvé a iniciar sesión en la app.',
              accion: 'Cerrar',
              alAccionar: cerrar,
            ),
            SesionDeshabilitada(:final mensaje) => _Aviso(
              icono: Icons.block,
              titulo: mensaje,
              detalle: 'Consultá con el administrador del sistema.',
              accion: 'Cerrar',
              alAccionar: cerrar,
            ),
            SesionConError(:final mensaje) => _Aviso(
              icono: Icons.wifi_off,
              titulo: mensaje,
              detalle: 'Revisá tu conexión.',
              accion: 'Reintentar',
              alAccionar: reintentar,
            ),
          },
        ),
      ),
    );
  }
}

class _Aviso extends StatelessWidget {
  const _Aviso({
    required this.icono,
    required this.titulo,
    required this.detalle,
    required this.accion,
    required this.alAccionar,
  });

  final IconData icono;
  final String titulo;
  final String detalle;
  final String accion;
  final VoidCallback alAccionar;

  @override
  Widget build(BuildContext context) {
    final texto = Theme.of(context).textTheme;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icono, size: 48),
        const SizedBox(height: 16),
        Text(titulo, style: texto.titleLarge, textAlign: TextAlign.center),
        const SizedBox(height: 8),
        Text(detalle, textAlign: TextAlign.center),
        const SizedBox(height: 24),
        FilledButton(onPressed: alAccionar, child: Text(accion)),
      ],
    );
  }
}
```

`paquete/vehiculos_oficiales/lib/src/ui/chofer/inicio_chofer.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../sesion/sesion.dart';
import '../modulo_app.dart';

/// Lugar reservado para las pantallas del chofer (plan `2026-09-30-flutter-chofer.md`).
class InicioChofer extends ConsumerWidget {
  const InicioChofer({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final usuario = ref.watch(usuarioProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
      ),
      body: Center(child: Text('Hola, ${usuario.nombre}. Las pantallas del chofer llegan en el próximo plan.')),
    );
  }
}
```

`paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../sesion/sesion.dart';
import '../modulo_app.dart';

/// Provisoria: la Task 11 la reemplaza por el mapa del solicitante.
class InicioSolicitante extends ConsumerWidget {
  const InicioSolicitante({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final usuario = ref.watch(usuarioProvider);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
      ),
      body: Center(child: Text('Hola, ${usuario.nombre}')),
    );
  }
}
```

- [ ] **Step 7: Punto de entrada público**

`paquete/vehiculos_oficiales/lib/src/vehiculos_oficiales.dart`:

```dart
import 'package:flutter/material.dart';

import 'config.dart';
import 'entorno.dart';
import 'puente_notificaciones.dart';
import 'ui/modulo_app.dart';

/// Punto de entrada del módulo (spec 3.1).
abstract final class VehiculosOficiales {
  /// Abre el módulo encima de la navegación de la app principal. El `Future` termina cuando se cierra.
  ///
  /// - [sesion]: token de sesión de la app del PJ, que el backend valida (spec 3.2).
  /// - [push]: puente FCM de la app principal (spec 12.3).
  /// - [onSesionInvalida]: se llama una sola vez si el backend responde 401 (token del PJ inválido o
  ///   vencido). La app principal decide qué hacer (normalmente, volver a su login).
  static Future<void> abrir(
    BuildContext context, {
    required SesionPJ sesion,
    required PuenteNotificaciones push,
    required VoidCallback onSesionInvalida,
    required VehiculosOficialesConfig config,
  }) {
    final navegador = Navigator.of(context);
    return navegador.push<void>(
      MaterialPageRoute(
        builder: (_) => ModuloVehiculos(
          entorno: EntornoModulo(config: config, sesion: sesion, push: push, onSesionInvalida: onSesionInvalida),
          alCerrar: () => navegador.pop(), // pop (no maybePop): el PopScope del módulo bloquea maybePop
        ),
      ),
    );
  }
}
```

`paquete/vehiculos_oficiales/lib/vehiculos_oficiales.dart` (reemplazar entero):

```dart
/// Módulo Vehículos Oficiales. Único punto de entrada: [VehiculosOficiales.abrir].
library;

export 'src/config.dart' show SesionPJ, VehiculosOficialesConfig;
export 'src/puente_notificaciones.dart' show PuenteNotificaciones;
export 'src/vehiculos_oficiales.dart' show VehiculosOficiales;
```

- [ ] **Step 8: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 58 PASS, sin problemas, `0 changed`.

- [ ] **Step 9: Commit**

```bash
git add paquete
git commit -m "feat: punto de entrada VehiculosOficiales.abrir con navegación propia y pantallas de sesión" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Choferes del mapa en vivo

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/solicitante/choferes_mapa.dart`
- Test: `paquete/vehiculos_oficiales/test/choferes_mapa_test.dart`

**Interfaces:**
- Consumes: `tiempoRealProvider`, `intervaloRespaldoProvider`, `Respaldo` (Task 6); `apiProvider.choferes()`.
- Produces: `choferesMapaProvider` (`AsyncNotifier<List<ChoferEnMapa>>`) con `refrescar()`. `chofer.ubicacion` mueve al chofer, `chofer.estado` cambia su estado (`fuera_de_turno` lo saca) y un chofer desconocido provoca una recarga completa.

- [ ] **Step 1: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/choferes_mapa_test.dart`:

```dart
import 'package:fake_async/fake_async.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/entorno.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/solicitante/choferes_mapa.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real_provider.dart';

import 'fixtures/payloads.dart' as p;
import 'soporte/dobles.dart';
import 'soporte/entorno_prueba.dart';

ChoferEnMapa carlos({String estado = 'libre'}) =>
    ChoferEnMapa.fromJson(leerMapa(p.jsonLista(p.choferes).single)..['estado'] = estado);

ChoferEnMapa otro(int id) => ChoferEnMapa.fromJson(
  leerMapa(p.jsonLista(p.choferes).single)
    ..['id'] = id
    ..['nombre'] = 'Chofer $id',
);

void main() {
  late ApiFalsa api;
  late TiempoRealFalso tr;

  setUp(() {
    api = ApiFalsa()..listaChoferes = [carlos()];
    tr = TiempoRealFalso();
  });

  ProviderContainer crear() {
    final c = EntornoPrueba().contenedor([
      apiProvider.overrideWithValue(api),
      tiempoRealProvider.overrideWithValue(tr),
    ]);
    c.listen(choferesMapaProvider, (_, _) {});
    return c;
  }

  List<ChoferEnMapa> leer(ProviderContainer c) => c.read(choferesMapaProvider).requireValue;

  test('carga los choferes y escucha mapa.choferes', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      expect(leer(c).single.nombre, 'Carlos Gómez');
      expect(tr.canalesActivos, {'mapa.choferes'});
    });
  });

  test('mueve al chofer y cambia su estado con los eventos', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('mapa.choferes', Eventos.choferUbicacion, {...p.json(p.eventoUbicacion), 'lat': -26.9, 'lng': -65.3});
      tr.emitir('mapa.choferes', Eventos.choferEstado, p.json(p.eventoEstadoChofer));

      expect(leer(c).single.posicion, const Coordenada(-26.9, -65.3));
      expect(leer(c).single.estado, EstadoChofer.enViaje);
      expect(api.consultasChoferes, 1);
    });
  });

  test('saca al chofer que termina el turno y recarga si aparece uno desconocido', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.emitir('mapa.choferes', Eventos.choferEstado, {'chofer_id': 2, 'estado': 'fuera_de_turno'});
      expect(leer(c), isEmpty);

      api.listaChoferes = [carlos(), otro(9)];
      tr.emitir('mapa.choferes', Eventos.choferEstado, {'chofer_id': 9, 'estado': 'libre'});
      async.flushMicrotasks();
      expect(leer(c).map((c) => c.id), [2, 9]);
      expect(api.consultasChoferes, 2);
    });
  });

  test('con el socket caído consulta cada 10 s y al reconectar deja de hacerlo', () {
    fakeAsync((async) {
      final c = crear();
      async.flushMicrotasks();

      tr.cambiar(EstadoConexion.desconectado);
      api.listaChoferes = [carlos(estado: 'reservado_pronto')];
      async.elapse(const Duration(seconds: 10));
      expect(leer(c).single.estado, EstadoChofer.reservadoPronto);
      expect(leer(c).single.seleccionable, isFalse);

      tr.cambiar(EstadoConexion.conectado);
      async.flushMicrotasks();
      final consultas = api.consultasChoferes;
      async.elapse(const Duration(seconds: 60));
      expect(api.consultasChoferes, consultas);
    });
  });
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `flutter test test/choferes_mapa_test.dart`
Expected: FAIL (no existe `solicitante/choferes_mapa.dart`).

- [ ] **Step 3: Implementación**

`paquete/vehiculos_oficiales/lib/src/solicitante/choferes_mapa.dart`:

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/errores_api.dart';
import '../entorno.dart';
import '../modelos/modelos.dart';
import '../tiempo_real/respaldo.dart';
import '../tiempo_real/tiempo_real.dart';
import '../tiempo_real/tiempo_real_provider.dart';

final choferesMapaProvider = AsyncNotifierProvider<ChoferesMapaNotifier, List<ChoferEnMapa>>(ChoferesMapaNotifier.new);

/// Choferes en turno para el mapa del solicitante (spec 7, solicitante 1): `GET /choferes` y después
/// `chofer.ubicacion` / `chofer.estado` por `mapa.choferes`; con el socket caído, `GET /choferes` cada 10 s.
class ChoferesMapaNotifier extends AsyncNotifier<List<ChoferEnMapa>> {
  bool _consultando = false;

  @override
  Future<List<ChoferEnMapa>> build() async {
    final tr = ref.watch(tiempoRealProvider);
    final canal = tr.canal(Canales.mapaChoferes).listen(_alEvento);
    final respaldo = Respaldo(tiempoReal: tr, intervalo: ref.read(intervaloRespaldoProvider), refrescar: refrescar);
    ref.onDispose(() {
      respaldo.cerrar();
      unawaited(canal.cancel());
    });
    return ref.read(apiProvider).choferes();
  }

  Future<void> refrescar() async {
    if (_consultando) return;
    _consultando = true;
    try {
      final lista = await ref.read(apiProvider).choferes();
      if (ref.mounted) state = AsyncData(lista);
    } on SesionInvalida {
      rethrow;
    } on ErrorApi {
      // Se conserva la última lista; el respaldo reintenta.
    } finally {
      _consultando = false;
    }
  }

  void _alEvento(EventoTiempoReal e) {
    final lista = state.value;
    if (lista == null) return;

    switch (e.nombre) {
      case Eventos.choferUbicacion:
        final u = UbicacionChofer.fromJson(e.datos);
        if (!lista.any((c) => c.id == u.choferId)) {
          unawaited(refrescar()); // chofer que recién inició turno: faltan nombre y vehículo
          return;
        }
        state = AsyncData([for (final c in lista) c.id == u.choferId ? c.conUbicacion(u) : c]);
      case Eventos.choferEstado:
        final id = e.datos['chofer_id'] as int;
        final estado = EstadoChofer.desde(e.datos['estado'] as String);
        if (estado == EstadoChofer.fueraDeTurno) {
          state = AsyncData(lista.where((c) => c.id != id).toList());
        } else if (lista.any((c) => c.id == id)) {
          state = AsyncData([for (final c in lista) c.id == id ? c.conEstado(estado) : c]);
        } else {
          unawaited(refrescar());
        }
    }
  }
}
```

- [ ] **Step 4: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 62 PASS, sin problemas, `0 changed`.

- [ ] **Step 5: Commit**

```bash
git add paquete
git commit -m "feat: choferes en turno en vivo para el mapa del solicitante" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Pantalla del viaje: buscando, activo y cómo terminó

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/ui/comunes/comunes.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/ui/solicitante/pantalla_viaje.dart`
- Modify: `paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart` (ruta `viaje`)
- Modify: `paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart` (provisoria: ir al viaje cuando aparece)
- Test: `paquete/vehiculos_oficiales/test/ui/pantalla_viaje_test.dart`

**Interfaces:**
- Consumes: `viajeActualProvider`, `estadoConexionProvider` (Task 6); `constructorMapaProvider`, `Rutas` (Task 8).
- Produces:
  - `typedef LanzadorUrl`, `lanzadorUrlProvider` (`url_launcher` en modo externo), `distanciaMetros(a, b)`, `formatearDistancia(m)`, `formatearFechaHora(d)`, `mensajeDeError(e)`, `mostrarError(context, e)`, `BannerConexion`.
  - `PantallaViaje` en `/solicitante/viaje`.

- [ ] **Step 1: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/ui/pantalla_viaje_test.dart`:

```dart
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/tiempo_real/tiempo_real.dart';
import 'package:vehiculos_oficiales/src/ui/comunes/comunes.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

String actualCon(String viajeJson) => '{"viaje":$viajeJson,"oferta":null}';

String viajeJson({String estado = 'aceptado', String modo = 'mas_cercano', bool conChofer = true}) => jsonEncode(
  p.json(conChofer ? p.viajeAceptado : p.viajeOfrecido)
    ..['estado'] = estado
    ..['modo'] = modo,
);

void main() {
  late EntornoPrueba e;
  late TiempoRealFalso tr;
  late List<Uri> lanzadas;

  setUp(() {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    tr = TiempoRealFalso();
    lanzadas = [];
  });

  Future<void> abrir(WidgetTester tester) => montarModulo(
    tester,
    e,
    tiempoReal: tr,
    extra: [
      lanzadorUrlProvider.overrideWithValue((uri) async {
        lanzadas.add(uri);
        return true;
      }),
    ],
  );

  testWidgets('buscando chofer: se puede cancelar el pedido', (tester) async {
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualSolicitante);
    e.http.responder('POST', 'viajes/1/cancelar', 200, viajeJson(estado: 'cancelado', conChofer: false));

    await abrir(tester);
    expect(find.text('Buscando el chofer más cercano…'), findsOneWidget);

    await tester.tap(find.text('Cancelar pedido'));
    await esperar(tester);
    await tester.tap(find.text('Sí, cancelar'));
    await esperar(tester);

    expect(find.text('Viaje cancelado'), findsWidgets);
    expect(e.http.pedidos.last.uri.path, '/api/viajes/1/cancelar');

    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  testWidgets('viaje activo: chofer, vehículo, llamar y cambios de estado en vivo', (tester) async {
    e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));

    await abrir(tester);
    expect(find.text('Chofer asignado'), findsWidgets);
    expect(find.text('Carlos Gómez'), findsOneWidget);
    expect(find.text('Toyota Corolla (AB123CD) · Blanco'), findsOneWidget);

    await tester.tap(find.text('Llamar'));
    expect(lanzadas.single.toString(), 'tel:3815550000');

    tr.emitir('viaje.1', Eventos.choferUbicacion, p.json(p.eventoUbicacion));
    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_camino')));
    await tester.pumpAndSettle();
    expect(find.text('El chofer va en camino'), findsWidgets);
    expect(find.textContaining('km del origen'), findsOneWidget);
    expect(find.byKey(const Key('marcador-chofer')), findsOneWidget);

    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'en_curso')));
    await tester.pumpAndSettle();
    expect(find.text('En viaje'), findsWidgets);
    expect(find.text('Cancelar viaje'), findsNothing); // spec 5.6: no se cancela en curso
  });

  testWidgets('con el socket caído avisa y se mantiene al día consultando cada 10 s', (tester) async {
    tr = TiempoRealFalso(estado: EstadoConexion.desconectado);
    e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));
    e.http.responder('GET', 'viajes/actual', 200, actualCon(viajeJson(estado: 'llego')));
    e.http.responder('GET', 'choferes', 200, p.choferes);

    await abrir(tester);
    expect(find.text('Sin conexión en tiempo real. Actualizando cada 10 s.'), findsOneWidget);
    expect(find.text('Chofer asignado'), findsWidgets);

    await tester.pump(const Duration(seconds: 10));
    await tester.pumpAndSettle();
    expect(find.text('El chofer llegó'), findsWidgets);
    expect(find.byKey(const Key('marcador-chofer')), findsOneWidget); // posición tomada de GET /choferes

    tr.cambiar(EstadoConexion.conectado);
    await tester.pumpAndSettle();
    expect(find.text('Sin conexión en tiempo real. Actualizando cada 10 s.'), findsNothing);
  });

  testWidgets('el chofer elegido no aceptó: pedir el más cercano crea otro viaje', (tester) async {
    e.http.responder(
      'GET',
      'viajes/actual',
      200,
      actualCon(viajeJson(estado: 'sin_chofer', modo: 'especifico', conChofer: false)),
    );
    e.http.responder('POST', 'viajes', 201, p.viajeOfrecido);

    await abrir(tester);
    expect(find.text('El chofer no aceptó el viaje'), findsOneWidget);
    expect(find.text('Elegir otro'), findsOneWidget);

    await tester.tap(find.text('Pedir el más cercano'));
    await esperar(tester);

    final cuerpo = jsonDecode(e.http.pedidos.last.cuerpo) as Map<String, dynamic>;
    expect(cuerpo['modo'], 'mas_cercano');
    expect(cuerpo['destino_direccion'], 'Tribunales');
    expect(find.text('Buscando el chofer más cercano…'), findsOneWidget);
  });

  testWidgets('viaje finalizado: volver al mapa', (tester) async {
    e.http.responder('GET', 'viajes/actual', 200, actualCon(p.viajeAceptado));

    await abrir(tester);
    tr.emitir('viaje.1', Eventos.viajeActualizado, p.json(viajeJson(estado: 'finalizado')));
    await tester.pumpAndSettle();

    expect(find.text('Viaje finalizado'), findsWidgets);
    await tester.tap(find.text('Volver al mapa'));
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `flutter test test/ui/pantalla_viaje_test.dart`
Expected: FAIL (no existen `comunes.dart` ni `pantalla_viaje.dart`).

- [ ] **Step 3: Utilidades de pantalla**

`paquete/vehiculos_oficiales/lib/src/ui/comunes/comunes.dart`:

```dart
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../api/errores_api.dart';
import '../../modelos/comunes.dart';
import '../../tiempo_real/tiempo_real.dart';
import '../../tiempo_real/tiempo_real_provider.dart';

/// Abre una URL externa (teléfono, Google Maps, Waze). Costura para los tests.
typedef LanzadorUrl = Future<bool> Function(Uri uri);

final lanzadorUrlProvider = Provider<LanzadorUrl>(
  (ref) =>
      (uri) => launchUrl(uri, mode: LaunchMode.externalApplication),
);

/// Distancia en línea recta (haversine), en metros.
double distanciaMetros(Coordenada a, Coordenada b) {
  const radio = 6371000.0;
  double rad(double g) => g * math.pi / 180;
  final dLat = rad(b.lat - a.lat);
  final dLng = rad(b.lng - a.lng);
  final h =
      math.pow(math.sin(dLat / 2), 2) + math.cos(rad(a.lat)) * math.cos(rad(b.lat)) * math.pow(math.sin(dLng / 2), 2);
  return 2 * radio * math.asin(math.sqrt(h));
}

String formatearDistancia(double metros) =>
    metros < 1000 ? '${(metros / 10).round() * 10} m' : '${NumberFormat('0.0', 'es').format(metros / 1000)} km';

/// Fecha y hora en la zona del dispositivo, p. ej. "vie 2/10 10:00".
String formatearFechaHora(DateTime d) => DateFormat('EEE d/M HH:mm', 'es').format(d.toLocal());

String mensajeDeError(Object error) => error is ErrorApi ? error.mensaje : 'Ocurrió un error inesperado.';

void mostrarError(BuildContext context, Object error) =>
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(mensajeDeError(error))));

/// Aviso visible mientras el WebSocket no está conectado (spec 6: se actualiza por sondeo).
class BannerConexion extends ConsumerWidget {
  const BannerConexion({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (ref.watch(estadoConexionProvider) == EstadoConexion.conectado) return const SizedBox.shrink();
    return MaterialBanner(
      leading: const Icon(Icons.sync_problem),
      content: const Text('Sin conexión en tiempo real. Actualizando cada 10 s.'),
      actions: const [SizedBox.shrink()],
    );
  }
}
```

- [ ] **Step 4: Pantalla del viaje**

`paquete/vehiculos_oficiales/lib/src/ui/solicitante/pantalla_viaje.dart` (la Task 11 le agrega una línea a `_volver`):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Buscando chofer, viaje activo y cómo terminó (spec 7, solicitante 3 y 4; spec 5.3 y 5.5).
class PantallaViaje extends ConsumerWidget {
  const PantallaViaje({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final seguimiento = ref.watch(viajeActualProvider);
    final viaje = seguimiento.value?.viaje;

    if (seguimiento.hasValue && viaje == null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (context.mounted) context.go(Rutas.solicitante);
      });
    }

    return Scaffold(
      appBar: AppBar(title: Text(viaje?.estado.texto ?? 'Tu viaje')),
      body: Column(
        children: [
          const BannerConexion(),
          Expanded(
            child: viaje == null
                ? const Center(child: CircularProgressIndicator())
                : switch (viaje.estado) {
                    EstadoViaje.buscando || EstadoViaje.ofrecido => _Buscando(viaje: viaje),
                    EstadoViaje.aceptado ||
                    EstadoViaje.enCamino ||
                    EstadoViaje.llego ||
                    EstadoViaje.enCurso => _Activo(viaje: viaje, ubicacion: seguimiento.value?.ubicacionChofer),
                    EstadoViaje.sinChofer => _SinChofer(viaje: viaje),
                    EstadoViaje.finalizado || EstadoViaje.cancelado => _Terminado(viaje: viaje),
                  },
          ),
        ],
      ),
    );
  }
}

Future<void> _cancelar(BuildContext context, WidgetRef ref) async {
  final confirma = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: const Text('¿Cancelar el viaje?'),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, cancelar')),
      ],
    ),
  );
  if (confirma != true) return;
  try {
    await ref.read(viajeActualProvider.notifier).cancelar();
  } on Exception catch (e) {
    if (context.mounted) mostrarError(context, e);
  }
}

class _Buscando extends ConsumerWidget {
  const _Buscando({required this.viaje});

  final Viaje viaje;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const LinearProgressIndicator(),
          const SizedBox(height: 24),
          Text(
            viaje.modo == ModoViaje.especifico ? 'Esperando que el chofer acepte…' : 'Buscando el chofer más cercano…',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          Text('Hacia ${viaje.destino.descripcion}', textAlign: TextAlign.center),
          const SizedBox(height: 32),
          OutlinedButton(onPressed: () => _cancelar(context, ref), child: const Text('Cancelar pedido')),
        ],
      ),
    );
  }
}

class _Activo extends ConsumerWidget {
  const _Activo({required this.viaje, this.ubicacion});

  final Viaje viaje;
  final UbicacionChofer? ubicacion;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final chofer = viaje.chofer;
    final vehiculo = viaje.vehiculo;
    final mapa = ref.watch(constructorMapaProvider);
    final texto = Theme.of(context).textTheme;
    final aproximandose = viaje.estado == EstadoViaje.aceptado || viaje.estado == EstadoViaje.enCamino;

    return Column(
      children: [
        Expanded(
          child: mapa(
            context,
            DatosMapa(
              centro: ubicacion?.posicion ?? viaje.origen.coordenada,
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
                if (ubicacion != null)
                  MarcadorMapa(
                    id: 'chofer',
                    posicion: ubicacion!.posicion,
                    tipo: TipoMarcador.choferAsignado,
                    titulo: chofer?.nombre ?? 'Chofer',
                  ),
              ],
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(viaje.estado.texto, style: texto.titleLarge),
              if (aproximandose && ubicacion != null)
                // Sin ETA real en v1: distancia en línea recta hasta el origen (ver Decisiones).
                Text(
                  'A ${formatearDistancia(distanciaMetros(ubicacion!.posicion, viaje.origen.coordenada))} del origen',
                ),
              const SizedBox(height: 8),
              if (chofer != null) Text(chofer.nombre, style: texto.titleMedium),
              if (vehiculo != null) Text([vehiculo.descripcion, ?vehiculo.color].join(' · ')),
              const SizedBox(height: 16),
              Row(
                children: [
                  if (chofer?.telefono != null)
                    Expanded(
                      child: FilledButton.icon(
                        icon: const Icon(Icons.phone),
                        label: const Text('Llamar'),
                        onPressed: () => ref.read(lanzadorUrlProvider)(Uri(scheme: 'tel', path: chofer!.telefono)),
                      ),
                    ),
                  if (chofer?.telefono != null && viaje.estado.cancelablePorSolicitante) const SizedBox(width: 12),
                  if (viaje.estado.cancelablePorSolicitante)
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () => _cancelar(context, ref),
                        child: const Text('Cancelar viaje'),
                      ),
                    ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _SinChofer extends ConsumerWidget {
  const _SinChofer({required this.viaje});

  final Viaje viaje;

  Future<void> _pedirMasCercano(BuildContext context, WidgetRef ref) async {
    try {
      await ref
          .read(viajeActualProvider.notifier)
          .pedir(
            PedidoViaje(modo: ModoViaje.masCercano, origen: viaje.origen, destino: viaje.destino, motivo: viaje.motivo),
          );
    } on Exception catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }

  void _volver(BuildContext context, WidgetRef ref) {
    ref.read(viajeActualProvider.notifier).descartar();
    context.go(Rutas.solicitante);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final especifico = viaje.modo == ModoViaje.especifico;
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Icon(Icons.no_crash, size: 48),
          const SizedBox(height: 16),
          Text(
            especifico ? 'El chofer no aceptó el viaje' : 'No hay choferes disponibles',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 32),
          FilledButton(onPressed: () => _pedirMasCercano(context, ref), child: const Text('Pedir el más cercano')),
          const SizedBox(height: 12),
          OutlinedButton(
            onPressed: () => _volver(context, ref),
            child: Text(especifico ? 'Elegir otro' : 'Volver al mapa'),
          ),
        ],
      ),
    );
  }
}

class _Terminado extends ConsumerWidget {
  const _Terminado({required this.viaje});

  final Viaje viaje;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Icon(viaje.estado == EstadoViaje.finalizado ? Icons.check_circle : Icons.cancel, size: 48),
          const SizedBox(height: 16),
          Text(viaje.estado.texto, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 32),
          FilledButton(
            onPressed: () {
              ref.read(viajeActualProvider.notifier).descartar();
              context.go(Rutas.solicitante);
            },
            child: const Text('Volver al mapa'),
          ),
        ],
      ),
    );
  }
}
```

- [ ] **Step 5: Ruta y paso automático a la pantalla del viaje**

En `paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart`, agregar el import y la ruta hija de `/solicitante`. El archivo queda así:

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
import 'sesion/pantalla_inicio.dart';
import 'solicitante/inicio_solicitante.dart';
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
}

/// Raíz del módulo: su propio `ProviderScope` y su propio router (no toca los de la app principal).
class ModuloVehiculos extends StatelessWidget {
  const ModuloVehiculos({super.key, required this.entorno, required this.alCerrar, this.overrides = const []});

  final EntornoModulo entorno;
  final VoidCallback alCerrar;

  /// Solo para tests (HTTP falso, Reverb falso, mapa de prueba…).
  @visibleForTesting
  final List<Override> overrides;

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      // Riverpod 3 reintenta por defecto los providers que fallan; acá los errores se muestran y se
      // reintentan a mano o por el respaldo de 10 s.
      retry: (_, _) => null,
      overrides: [
        entornoProvider.overrideWithValue(entorno),
        cerrarModuloProvider.overrideWithValue(alCerrar),
        ...overrides,
      ],
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
              routes: [GoRoute(path: 'viaje', builder: (_, _) => const PantallaViaje())],
            ),
            GoRoute(path: Rutas.chofer, builder: (_, _) => const InicioChofer()),
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

`paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart` (sigue siendo provisoria):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../sesion/sesion.dart';
import '../../viaje/viaje_actual.dart';
import '../modulo_app.dart';

/// Provisoria: la Task 11 la reemplaza por el mapa del solicitante.
class InicioSolicitante extends ConsumerWidget {
  const InicioSolicitante({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final usuario = ref.watch(usuarioProvider);
    // Al aparecer un viaje (el que había al abrir o uno recién pedido) se va a su pantalla.
    ref.listen(viajeActualProvider, (_, siguiente) {
      if (siguiente.value?.viaje != null) context.go(Rutas.viaje);
    });
    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
      ),
      body: Center(child: Text('Hola, ${usuario.nombre}')),
    );
  }
}
```

- [ ] **Step 6: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 67 PASS, sin problemas, `0 changed`.

- [ ] **Step 7: Commit**

```bash
git add paquete
git commit -m "feat: pantalla del viaje del solicitante con estado en vivo, llamar y cancelar" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Mapa del solicitante y nuevo pedido

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/ubicacion/ubicador.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/solicitante/borrador_pedido.dart`
- Modify (reemplazar): `paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart`
- Modify: `paquete/vehiculos_oficiales/lib/src/ui/solicitante/pantalla_viaje.dart` ("Elegir otro" precarga el pedido)
- Modify: `paquete/vehiculos_oficiales/test/soporte/dobles.dart` (`UbicadorFalso`)
- Test: `paquete/vehiculos_oficiales/test/borrador_pedido_test.dart`, `paquete/vehiculos_oficiales/test/ui/inicio_solicitante_test.dart`

**Interfaces:**
- Consumes: `choferesMapaProvider` (Task 9), `viajeActualProvider` (Task 6), `constructorMapaProvider`, `cerrarModuloProvider`, `Rutas` (Task 8), `BannerConexion`, `mostrarError` (Task 10).
- Produces:
  - `abstract interface class Ubicador { Future<Coordenada?> actual(); }`, `UbicadorGeolocator`, `ubicadorProvider`. El plan del chofer le agrega el seguimiento continuo.
  - `enum PuntoPedido {origen, destino}`, `BorradorPedido` (+ `completo`, `lugarOrigen`, `lugarDestino`, `pedido()`), `borradorPedidoProvider` con `marcar`, `fijar`, `marcarAhora`, `direccion`, `motivo`, `elegirChofer`, `desdeViaje`, `limpiar`.
  - `InicioSolicitante` definitiva (sin los accesos a reservas, que agrega la Task 12).

- [ ] **Step 1: Doble del ubicador**

Agregar al final de `paquete/vehiculos_oficiales/test/soporte/dobles.dart`, y el import `package:vehiculos_oficiales/src/ubicacion/ubicador.dart` junto a los demás:

```dart
class UbicadorFalso implements Ubicador {
  UbicadorFalso([this.posicion]);

  /// Nula = permiso denegado o GPS apagado.
  Coordenada? posicion;

  @override
  Future<Coordenada?> actual() async => posicion;
}
```

- [ ] **Step 2: Escribir los tests que fallan**

`paquete/vehiculos_oficiales/test/borrador_pedido_test.dart`:

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/solicitante/borrador_pedido.dart';

import 'soporte/dobles.dart';

void main() {
  late ProviderContainer c;
  BorradorPedidoNotifier notifier() => c.read(borradorPedidoProvider.notifier);
  BorradorPedido borrador() => c.read(borradorPedidoProvider);

  setUp(() => c = ProviderContainer.test());

  test('el primer toque marca el origen y el segundo el destino', () {
    notifier().marcar(const Coordenada(1, 1));
    expect(borrador().marcando, PuntoPedido.destino);
    notifier().marcar(const Coordenada(2, 2));

    expect(borrador().origen, const Coordenada(1, 1));
    expect(borrador().destino, const Coordenada(2, 2));
    expect(borrador().completo, isTrue);
  });

  test('sin chofer pide el más cercano; con chofer, a ese chofer; los textos vacíos no se mandan', () {
    notifier()
      ..fijar(PuntoPedido.origen, const Coordenada(1, 1))
      ..fijar(PuntoPedido.destino, const Coordenada(2, 2))
      ..direccion(PuntoPedido.destino, '  Tribunales ')
      ..motivo('   ');

    expect(borrador().pedido().toJson(), {
      'modo': 'mas_cercano',
      'origen_lat': 1.0,
      'origen_lng': 1.0,
      'destino_lat': 2.0,
      'destino_lng': 2.0,
      'destino_direccion': 'Tribunales',
    });

    final carlos = ChoferEnMapa(
      id: 2,
      nombre: 'Carlos',
      estado: EstadoChofer.libre,
      vehiculo: const Vehiculo(patente: 'A', marca: 'B', modelo: 'C'),
    );
    notifier().elegirChofer(carlos);
    expect(borrador().pedido().toJson()['modo'], 'especifico');
    expect(borrador().pedido().toJson()['chofer_id'], 2);

    notifier().elegirChofer(null);
    expect(borrador().chofer, isNull);
  });

  test('desde un viaje sin chofer copia origen, destino y motivo', () {
    notifier().desdeViaje(viaje(estado: 'sin_chofer'));

    expect(borrador().lugarOrigen!.descripcion, 'Plaza Independencia');
    expect(borrador().lugarDestino!.descripcion, 'Tribunales');
    expect(borrador().motivo, 'Audiencia');
    expect(borrador().chofer, isNull);
  });
}
```

`paquete/vehiculos_oficiales/test/ui/inicio_solicitante_test.dart`:

```dart
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

const _reservado =
    '[{"id":3,"nombre":"Luis Díaz","estado":"reservado_pronto","lat":-26.83,"lng":-65.21,"rumbo":null,"actualizado_en":"2026-10-01T12:00:00+00:00","vehiculo":{"patente":"AC456EF","marca":"Fiat","modelo":"Cronos","color":null}}]';

void main() {
  late EntornoPrueba e;
  late UbicadorFalso ubicador;

  setUp(() {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);
    e.http.responder('GET', 'choferes', 200, p.choferes);
    ubicador = UbicadorFalso(const Coordenada(-26.8241, -65.2226));
  });

  Future<void> abrir(WidgetTester tester) =>
      montarModulo(tester, e, extra: [ubicadorProvider.overrideWithValue(ubicador)]);

  Map<String, dynamic> ultimoCuerpo() => jsonDecode(e.http.pedidos.last.cuerpo) as Map<String, dynamic>;

  testWidgets('muestra los choferes en turno; el libre en verde', (tester) async {
    await abrir(tester);

    expect(find.text('choferLibre: Carlos Gómez · Libre'), findsOneWidget);
  });

  testWidgets('un chofer reservado pronto se ve pero no se puede elegir', (tester) async {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);
    e.http.responder('GET', 'choferes', 200, _reservado);

    await abrir(tester);
    await tester.tap(find.text('choferNoDisponible: Luis Díaz · Reservado pronto'));
    await tester.pumpAndSettle();

    expect(find.textContaining('no se le pueden pedir viajes ahora'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Pedir a este chofer')).onPressed, isNull);
  });

  testWidgets('pedir el más cercano con mi ubicación y un destino marcado en el mapa', (tester) async {
    e.http.responder('POST', 'viajes', 201, p.viajeOfrecido);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);

    await abrir(tester);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Pedir el más cercano')).onPressed, isNull);

    await tester.tap(find.byTooltip('Usar mi ubicación'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tocar-mapa'))); // después del origen se marca el destino
    await tester.pumpAndSettle();
    await tester.tap(find.text('Direcciones y motivo (opcional)'));
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Dirección de destino'), 'Tribunales');
    await tester.enterText(find.widgetWithText(TextField, 'Motivo'), 'Audiencia');
    await tester.tap(find.text('Pedir el más cercano'));
    await esperar(tester);

    expect(ultimoCuerpo(), {
      'modo': 'mas_cercano',
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'destino_lat': puntoTocado.lat,
      'destino_lng': puntoTocado.lng,
      'destino_direccion': 'Tribunales',
      'motivo': 'Audiencia',
    });
    expect(find.text('Buscando el chofer más cercano…'), findsOneWidget);
  });

  testWidgets('pedir a un chofer elegido en el mapa', (tester) async {
    e.http.responder('POST', 'viajes', 201, jsonEncode(p.json(p.viajeOfrecido)..['modo'] = 'especifico'));

    await abrir(tester);
    await tester.tap(find.text('choferLibre: Carlos Gómez · Libre'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pedir a este chofer'));
    await tester.pumpAndSettle();
    expect(find.text('Chofer: Carlos Gómez'), findsOneWidget);

    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.tap(find.text('Destino'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pedir a Carlos Gómez'));
    await esperar(tester);

    expect(ultimoCuerpo()['modo'], 'especifico');
    expect(ultimoCuerpo()['chofer_id'], 2);
    expect(find.text('Esperando que el chofer acepte…'), findsOneWidget);
  });

  testWidgets('sin permiso de ubicación se explica cómo marcar el origen', (tester) async {
    ubicador.posicion = null;

    await abrir(tester);
    await tester.tap(find.byTooltip('Usar mi ubicación'));
    await tester.pump();

    expect(find.text('No pudimos obtener tu ubicación. Marcá el origen tocando el mapa.'), findsOneWidget);
  });

  testWidgets('si el backend rechaza el pedido se muestra su mensaje', (tester) async {
    e.http.responder('POST', 'viajes', 422, '{"message":"Ya ten\\u00e9s un viaje en curso."}');

    await abrir(tester);
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Pedir el más cercano'));
    await tester.pumpAndSettle();

    expect(find.text('Ya tenés un viaje en curso.'), findsOneWidget);
  });

  testWidgets('con un viaje en curso al abrir va a su pantalla; al volver ofrece verlo', (tester) async {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, '{"viaje":${p.viajeAceptado},"oferta":null}');
    e.http.responder('GET', 'choferes', 200, p.choferes);

    await abrir(tester);
    expect(find.text('Carlos Gómez'), findsOneWidget);

    await tester.binding.handlePopRoute(); // "atrás" del sistema: vuelve al mapa, no cierra el módulo
    await tester.pumpAndSettle();
    expect(find.text('Tenés un viaje en curso.'), findsOneWidget);

    await tester.tap(find.text('Ver'));
    await tester.pumpAndSettle();
    expect(find.text('Chofer asignado'), findsWidgets);
  });

  testWidgets('"Elegir otro" vuelve al mapa con el mismo origen y destino', (tester) async {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    final sinChofer = p.json(p.viajeOfrecido)
      ..['estado'] = 'sin_chofer'
      ..['modo'] = 'especifico';
    e.http.responder('GET', 'viajes/actual', 200, jsonEncode({'viaje': sinChofer, 'oferta': null}));
    e.http.responder('GET', 'choferes', 200, p.choferes);

    await abrir(tester);
    await tester.tap(find.text('Elegir otro'));
    await tester.pumpAndSettle();

    expect(find.text('Plaza Independencia'), findsOneWidget);
    expect(find.text('Tribunales'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Pedir el más cercano')).onPressed, isNotNull);
  });
}
```

- [ ] **Step 3: Correr y ver que fallan**

Run: `flutter test test/borrador_pedido_test.dart test/ui/inicio_solicitante_test.dart`
Expected: FAIL (no existen `ubicador.dart` ni `borrador_pedido.dart`).

- [ ] **Step 4: Ubicador y borrador del pedido**

`paquete/vehiculos_oficiales/lib/src/ubicacion/ubicador.dart`:

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';

import '../modelos/comunes.dart';

/// Ubicación del dispositivo. El plan del chofer le agrega el seguimiento continuo (GPS del turno).
abstract interface class Ubicador {
  /// Posición actual, pidiendo permiso si hace falta. Nula si se negó el permiso o el GPS está apagado
  /// (spec 9: el solicitante puede marcar el origen a mano).
  Future<Coordenada?> actual();
}

class UbicadorGeolocator implements Ubicador {
  @override
  Future<Coordenada?> actual() async {
    if (!await Geolocator.isLocationServiceEnabled()) return null;
    var permiso = await Geolocator.checkPermission();
    if (permiso == LocationPermission.denied) permiso = await Geolocator.requestPermission();
    if (permiso == LocationPermission.denied || permiso == LocationPermission.deniedForever) return null;
    try {
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)),
      );
      return Coordenada(p.latitude, p.longitude);
    } on Exception {
      return null;
    }
  }
}

final ubicadorProvider = Provider<Ubicador>((ref) => UbicadorGeolocator());
```

`paquete/vehiculos_oficiales/lib/src/solicitante/borrador_pedido.dart`:

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../modelos/modelos.dart';

enum PuntoPedido { origen, destino }

/// Pedido que el solicitante está armando en el mapa (spec 7, solicitante 2).
class BorradorPedido {
  const BorradorPedido({
    this.origen,
    this.destino,
    this.direccionOrigen = '',
    this.direccionDestino = '',
    this.motivo = '',
    this.chofer,
    this.marcando = PuntoPedido.origen,
  });

  final Coordenada? origen;
  final Coordenada? destino;
  final String direccionOrigen;
  final String direccionDestino;
  final String motivo;

  /// Chofer elegido en el mapa ("Pedir a este chofer"); nulo = el más cercano.
  final ChoferEnMapa? chofer;

  /// Qué punto fija el próximo toque en el mapa.
  final PuntoPedido marcando;

  bool get completo => origen != null && destino != null;

  Lugar? get lugarOrigen => origen == null ? null : Lugar(origen!, direccion: _texto(direccionOrigen));

  Lugar? get lugarDestino => destino == null ? null : Lugar(destino!, direccion: _texto(direccionDestino));

  static String? _texto(String s) => s.trim().isEmpty ? null : s.trim();

  PedidoViaje pedido() => PedidoViaje(
    modo: chofer == null ? ModoViaje.masCercano : ModoViaje.especifico,
    choferId: chofer?.id,
    origen: lugarOrigen!,
    destino: lugarDestino!,
    motivo: _texto(motivo),
  );

  BorradorPedido copiar({
    Coordenada? origen,
    Coordenada? destino,
    String? direccionOrigen,
    String? direccionDestino,
    String? motivo,
    ChoferEnMapa? Function()? chofer,
    PuntoPedido? marcando,
  }) => BorradorPedido(
    origen: origen ?? this.origen,
    destino: destino ?? this.destino,
    direccionOrigen: direccionOrigen ?? this.direccionOrigen,
    direccionDestino: direccionDestino ?? this.direccionDestino,
    motivo: motivo ?? this.motivo,
    chofer: chofer == null ? this.chofer : chofer(),
    marcando: marcando ?? this.marcando,
  );
}

final borradorPedidoProvider = NotifierProvider<BorradorPedidoNotifier, BorradorPedido>(BorradorPedidoNotifier.new);

class BorradorPedidoNotifier extends Notifier<BorradorPedido> {
  @override
  BorradorPedido build() => const BorradorPedido();

  /// Toque en el mapa: fija el punto que se está marcando. Después del origen se pasa al destino.
  void marcar(Coordenada c) => fijar(state.marcando, c);

  void fijar(PuntoPedido punto, Coordenada c) => state = punto == PuntoPedido.origen
      ? state.copiar(origen: c, marcando: PuntoPedido.destino)
      : state.copiar(destino: c);

  void marcarAhora(PuntoPedido punto) => state = state.copiar(marcando: punto);

  void direccion(PuntoPedido punto, String texto) => state = punto == PuntoPedido.origen
      ? state.copiar(direccionOrigen: texto)
      : state.copiar(direccionDestino: texto);

  void motivo(String texto) => state = state.copiar(motivo: texto);

  void elegirChofer(ChoferEnMapa? chofer) => state = state.copiar(chofer: () => chofer);

  /// "Elegir otro" después de un `sin_chofer`: mismo origen, destino y motivo, sin chofer.
  void desdeViaje(Viaje v) => state = BorradorPedido(
    origen: v.origen.coordenada,
    destino: v.destino.coordenada,
    direccionOrigen: v.origen.direccion ?? '',
    direccionDestino: v.destino.direccion ?? '',
    motivo: v.motivo ?? '',
    marcando: PuntoPedido.destino,
  );

  void limpiar() => state = const BorradorPedido();
}
```

- [ ] **Step 5: Mapa del solicitante**

`paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart` (reemplazar entero):

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../entorno.dart';
import '../../mapa/mapa.dart';
import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../../solicitante/choferes_mapa.dart';
import '../../ubicacion/ubicador.dart';
import '../../viaje/viaje_actual.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Mapa principal del solicitante con los choferes en turno y el pedido (spec 7, solicitante 1 y 2).
class InicioSolicitante extends ConsumerWidget {
  const InicioSolicitante({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Al aparecer un viaje (el que había al abrir o uno recién pedido) se va a su pantalla.
    ref.listen(viajeActualProvider, (_, siguiente) {
      if (siguiente.value?.viaje != null) context.go(Rutas.viaje);
    });
    final hayViaje = ref.watch(viajeActualProvider).value?.viaje != null;
    final choferes = ref.watch(choferesMapaProvider).value ?? const <ChoferEnMapa>[];
    final borrador = ref.watch(borradorPedidoProvider);
    final mapa = ref.watch(constructorMapaProvider);
    final config = ref.watch(entornoProvider).config;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehículos oficiales'),
        leading: IconButton(
          icon: const Icon(Icons.close),
          tooltip: 'Cerrar',
          onPressed: ref.read(cerrarModuloProvider),
        ),
      ),
      body: Column(
        children: [
          const BannerConexion(),
          if (hayViaje)
            MaterialBanner(
              content: const Text('Tenés un viaje en curso.'),
              actions: [TextButton(onPressed: () => context.go(Rutas.viaje), child: const Text('Ver'))],
            ),
          Expanded(
            child: mapa(
              context,
              DatosMapa(
                centro: borrador.origen ?? Coordenada(config.centroMapaLat, config.centroMapaLng),
                alTocarMapa: ref.read(borradorPedidoProvider.notifier).marcar,
                marcadores: [
                  for (final c in choferes)
                    if (c.posicion != null)
                      MarcadorMapa(
                        id: 'chofer-${c.id}',
                        posicion: c.posicion!,
                        tipo: c.seleccionable ? TipoMarcador.choferLibre : TipoMarcador.choferNoDisponible,
                        titulo: '${c.nombre} · ${c.estado.texto}',
                        alTocar: () => _mostrarChofer(context, ref, c),
                      ),
                  if (borrador.origen != null)
                    MarcadorMapa(id: 'origen', posicion: borrador.origen!, tipo: TipoMarcador.origen, titulo: 'Origen'),
                  if (borrador.destino != null)
                    MarcadorMapa(
                      id: 'destino',
                      posicion: borrador.destino!,
                      tipo: TipoMarcador.destino,
                      titulo: 'Destino',
                    ),
                ],
              ),
            ),
          ),
          if (!hayViaje) const _PanelPedido(),
        ],
      ),
    );
  }

  Future<void> _mostrarChofer(BuildContext context, WidgetRef ref, ChoferEnMapa c) => showModalBottomSheet<void>(
    context: context,
    builder: (hoja) => Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(c.nombre, style: Theme.of(hoja).textTheme.titleLarge),
          Text([c.vehiculo.descripcion, ?c.vehiculo.color].join(' · ')),
          const SizedBox(height: 8),
          Text(c.estado.texto),
          if (c.estado == EstadoChofer.reservadoPronto)
            const Text('Tiene una reserva en los próximos minutos: no se le pueden pedir viajes ahora.'),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: c.seleccionable
                ? () {
                    ref.read(borradorPedidoProvider.notifier).elegirChofer(c);
                    Navigator.pop(hoja);
                  }
                : null,
            child: const Text('Pedir a este chofer'),
          ),
        ],
      ),
    ),
  );
}

class _PanelPedido extends ConsumerStatefulWidget {
  const _PanelPedido();

  @override
  ConsumerState<_PanelPedido> createState() => _PanelPedidoState();
}

class _PanelPedidoState extends ConsumerState<_PanelPedido> {
  final _dirOrigen = TextEditingController();
  final _dirDestino = TextEditingController();
  final _motivo = TextEditingController();
  bool _enviando = false;

  @override
  void initState() {
    super.initState();
    _sincronizar(ref.read(borradorPedidoProvider));
  }

  @override
  void dispose() {
    _dirOrigen.dispose();
    _dirDestino.dispose();
    _motivo.dispose();
    super.dispose();
  }

  /// Los textos pueden cambiar desde afuera ("Elegir otro" los copia del viaje sin chofer).
  void _sincronizar(BorradorPedido b) {
    if (_dirOrigen.text != b.direccionOrigen) _dirOrigen.text = b.direccionOrigen;
    if (_dirDestino.text != b.direccionDestino) _dirDestino.text = b.direccionDestino;
    if (_motivo.text != b.motivo) _motivo.text = b.motivo;
  }

  Future<void> _usarMiUbicacion() async {
    final aqui = await ref.read(ubicadorProvider).actual();
    if (!mounted) return;
    if (aqui == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No pudimos obtener tu ubicación. Marcá el origen tocando el mapa.')),
      );
      return;
    }
    ref.read(borradorPedidoProvider.notifier).fijar(PuntoPedido.origen, aqui);
  }

  Future<void> _pedir() async {
    setState(() => _enviando = true);
    final borrador = ref.read(borradorPedidoProvider.notifier); // la pantalla se va apenas hay viaje
    try {
      await ref.read(viajeActualProvider.notifier).pedir(ref.read(borradorPedidoProvider).pedido());
      borrador.limpiar();
    } on Exception catch (e) {
      if (mounted) mostrarError(context, e);
    } finally {
      if (mounted) setState(() => _enviando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(borradorPedidoProvider, (_, b) => _sincronizar(b));
    final b = ref.watch(borradorPedidoProvider);
    final notifier = ref.read(borradorPedidoProvider.notifier);

    String descripcion(Coordenada? c, String direccion) => c == null
        ? 'Tocá el mapa para marcarlo'
        : direccion.trim().isNotEmpty
        ? direccion
        : Lugar(c).descripcion;

    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.5),
      child: Material(
        elevation: 8,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    ListTile(
                      leading: const Icon(Icons.trip_origin),
                      title: const Text('Origen'),
                      subtitle: Text(descripcion(b.origen, b.direccionOrigen)),
                      selected: b.marcando == PuntoPedido.origen,
                      onTap: () => notifier.marcarAhora(PuntoPedido.origen),
                      trailing: IconButton(
                        icon: const Icon(Icons.my_location),
                        tooltip: 'Usar mi ubicación',
                        onPressed: _usarMiUbicacion,
                      ),
                    ),
                    ListTile(
                      leading: const Icon(Icons.place),
                      title: const Text('Destino'),
                      subtitle: Text(descripcion(b.destino, b.direccionDestino)),
                      selected: b.marcando == PuntoPedido.destino,
                      onTap: () => notifier.marcarAhora(PuntoPedido.destino),
                    ),
                    ExpansionTile(
                      title: const Text('Direcciones y motivo (opcional)'),
                      children: [
                        TextField(
                          controller: _dirOrigen,
                          decoration: const InputDecoration(labelText: 'Dirección de origen'),
                          onChanged: (t) => notifier.direccion(PuntoPedido.origen, t),
                        ),
                        TextField(
                          controller: _dirDestino,
                          decoration: const InputDecoration(labelText: 'Dirección de destino'),
                          onChanged: (t) => notifier.direccion(PuntoPedido.destino, t),
                        ),
                        TextField(
                          controller: _motivo,
                          decoration: const InputDecoration(labelText: 'Motivo'),
                          onChanged: notifier.motivo,
                        ),
                      ],
                    ),
                    if (b.chofer != null)
                      Align(
                        alignment: Alignment.centerLeft,
                        child: InputChip(
                          label: Text('Chofer: ${b.chofer!.nombre}'),
                          onDeleted: () => notifier.elegirChofer(null),
                        ),
                      )
                    else
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 4),
                        child: Text('O tocá un chofer verde en el mapa para pedírselo a él.'),
                      ),
                  ],
                ),
              ),
            ),
            // Fuera del desplazamiento: el botón principal siempre queda a la vista.
            Padding(
              padding: const EdgeInsets.all(12),
              child: FilledButton(
                onPressed: b.completo && !_enviando ? _pedir : null,
                child: Text(b.chofer == null ? 'Pedir el más cercano' : 'Pedir a ${b.chofer!.nombre}'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
```

- [ ] **Step 6: "Elegir otro" precarga el pedido**

En `paquete/vehiculos_oficiales/lib/src/ui/solicitante/pantalla_viaje.dart`, agregar `import '../../solicitante/borrador_pedido.dart';` y la primera línea de `_SinChofer._volver`:

```dart
  void _volver(BuildContext context, WidgetRef ref) {
    ref.read(borradorPedidoProvider.notifier).desdeViaje(viaje); // mismo origen y destino para elegir otro
    ref.read(viajeActualProvider.notifier).descartar();
    context.go(Rutas.solicitante);
  }
```

- [ ] **Step 7: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 78 PASS, sin problemas, `0 changed`. El test "con un viaje en curso al abrir va a su pantalla; al volver ofrece verlo" es el que cubre A5: sin el `PopScope` de la Task 8, el "atrás" cierra el módulo entero.

- [ ] **Step 8: Commit**

```bash
git add paquete
git commit -m "feat: mapa del solicitante con choferes en vivo y armado del pedido" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Reservar y Mis viajes

**Files:**
- Create: `paquete/vehiculos_oficiales/lib/src/solicitante/mis_viajes.dart`
- Create: `paquete/vehiculos_oficiales/lib/src/ui/solicitante/{pantalla_reserva,mis_viajes}.dart`
- Modify: `paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart` (rutas `reservar` y `mis-viajes`)
- Modify: `paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart` (botón "Mis viajes" y "Reservar para más tarde")
- Test: `paquete/vehiculos_oficiales/test/ui/reservas_test.dart`

**Interfaces:**
- Consumes: `apiProvider.disponiblesReserva/crearReserva/misViajes/cancelarViaje`, `pushModuloProvider.avisos` (Task 7), `borradorPedidoProvider` (Task 11), `formatearFechaHora`, `mostrarError` (Task 10).
- Produces:
  - `misViajesProvider` (`FutureProvider.autoDispose<MisViajes>`, se invalida con cada aviso push).
  - `typedef ElegirFechaHora`, `elegirFechaHoraProvider` (selectores de Material), `PantallaReserva({fechaInicial})` en `/solicitante/reservar` (`extra`: `DateTime?`), `MisViajesPantalla` en `/solicitante/mis-viajes`.

- [ ] **Step 1: Escribir el test que falla**

`paquete/vehiculos_oficiales/test/ui/reservas_test.dart`:

```dart
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:vehiculos_oficiales/src/modelos/modelos.dart';
import 'package:vehiculos_oficiales/src/ubicacion/ubicador.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/inicio_solicitante.dart';
import 'package:vehiculos_oficiales/src/ui/solicitante/pantalla_reserva.dart';

import '../fixtures/payloads.dart' as p;
import '../soporte/dobles.dart';
import '../soporte/entorno_prueba.dart';
import '../soporte/montar.dart';

final _cuando = DateTime(2026, 10, 2, 10); // hora local del dispositivo

String _misViajes({List<Map<String, dynamic>> proximas = const [], List<Map<String, dynamic>> historial = const []}) =>
    jsonEncode({'proximas': proximas, 'historial': historial});

Map<String, dynamic> _reserva({String estado = 'ofrecido', int id = 2}) => p.json(p.reservaCreada)
  ..['estado'] = estado
  ..['id'] = id;

void main() {
  late EntornoPrueba e;

  setUp(() {
    e = EntornoPrueba();
    e.http.responder('POST', 'auth/intercambio', 200, p.intercambio);
    e.http.responder('GET', 'viajes/actual', 200, p.viajeActualVacio);
    e.http.responder('GET', 'choferes', 200, p.choferes);
  });

  Future<void> abrir(WidgetTester tester) => montarModulo(
    tester,
    e,
    extra: [
      ubicadorProvider.overrideWithValue(UbicadorFalso(const Coordenada(-26.8241, -65.2226))),
      elegirFechaHoraProvider.overrideWithValue((_, _) async => _cuando),
    ],
  );

  Future<void> marcarOrigenYDestino(WidgetTester tester) async {
    await tester.tap(find.byTooltip('Usar mi ubicación'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('tocar-mapa')));
    await tester.pumpAndSettle();
  }

  Map<String, dynamic> cuerpo(String metodo, String ruta) =>
      jsonDecode(e.http.pedidos.lastWhere((x) => x.metodo == metodo && x.uri.path == '/api/$ruta').cuerpo)
          as Map<String, dynamic>;

  testWidgets('reservar con un chofer elegido de los disponibles', (tester) async {
    e.http.responder('GET', 'reservas/disponibles', 200, p.reservasDisponibles);
    e.http.responder('POST', 'reservas', 201, jsonEncode(_reserva()..['modo'] = 'especifico'));
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [_reserva()]));

    await abrir(tester);
    await marcarOrigenYDestino(tester);
    await tester.tap(find.text('Reservar para más tarde'));
    await tester.pumpAndSettle();
    expect(find.byType(PantallaReserva), findsOneWidget);

    await tester.tap(find.text('Fecha y hora'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ver choferes disponibles'));
    await tester.pumpAndSettle();

    final consulta = e.http.pedidos.lastWhere((x) => x.uri.path == '/api/reservas/disponibles').uri.queryParameters;
    expect(consulta['programado_para'], escribirFecha(_cuando));
    expect(consulta['origen_lat'], '-26.8241');
    expect(find.text('Duración estimada: 19 min'), findsOneWidget);
    expect(find.text('Cualquiera disponible'), findsOneWidget);
    expect(find.text('0 reservas ese día'), findsOneWidget);

    await tester.tap(find.text('Carlos Gómez'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirmar reserva'));
    await tester.pumpAndSettle();

    expect(cuerpo('POST', 'reservas'), {
      'programado_para': escribirFecha(_cuando),
      'modo': 'especifico',
      'chofer_id': 2,
      'origen_lat': -26.8241,
      'origen_lng': -65.2226,
      'destino_lat': puntoTocado.lat,
      'destino_lng': puntoTocado.lng,
    });
    expect(find.text('Solicitud enviada. Te avisamos cuando el chofer responda.'), findsOneWidget);
    expect(find.text('Mis viajes'), findsOneWidget);
    expect(find.text('Esperando confirmación del chofer'), findsOneWidget);
  });

  testWidgets('"Cualquiera disponible" es la opción por defecto', (tester) async {
    e.http.responder('GET', 'reservas/disponibles', 200, p.reservasDisponibles);
    e.http.responder('POST', 'reservas', 201, p.reservaCreada);
    e.http.responder('GET', 'viajes', 200, _misViajes());

    await abrir(tester);
    await marcarOrigenYDestino(tester);
    await tester.tap(find.text('Reservar para más tarde'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ver choferes disponibles'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Confirmar reserva'));
    await tester.pumpAndSettle();

    expect(cuerpo('POST', 'reservas')['modo'], 'cualquiera_disponible');
    expect(cuerpo('POST', 'reservas').containsKey('chofer_id'), isFalse);
  });

  testWidgets('sin la anticipación mínima se muestra el mensaje del backend', (tester) async {
    e.http.responder(
      'GET',
      'reservas/disponibles',
      422,
      '{"message":"La reserva debe hacerse con al menos 60 minutos de anticipaci\\u00f3n."}',
    );

    await abrir(tester);
    await marcarOrigenYDestino(tester);
    await tester.tap(find.text('Reservar para más tarde'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Ver choferes disponibles'));
    await tester.pumpAndSettle();

    expect(find.text('La reserva debe hacerse con al menos 60 minutos de anticipación.'), findsOneWidget);
  });

  testWidgets('mis viajes: cancelar una reserva y "atrás" vuelve al mapa', (tester) async {
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [_reserva(estado: 'aceptado')]));
    e.http.responder('GET', 'viajes', 200, _misViajes(historial: [_reserva(estado: 'cancelado')]));
    e.http.responder('POST', 'viajes/2/cancelar', 200, jsonEncode(_reserva(estado: 'cancelado')));

    await abrir(tester);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();
    expect(find.text('Confirmada · '), findsOneWidget); // la reserva del fixture todavía no tiene chofer

    await tester.tap(find.byTooltip('Cancelar reserva'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sí, cancelar'));
    await tester.pumpAndSettle();

    expect(find.text('No tenés reservas próximas.'), findsOneWidget);
    expect(find.text('Viaje cancelado'), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byType(InicioSolicitante), findsOneWidget);
  });

  testWidgets('mis viajes se actualiza con un aviso push', (tester) async {
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [_reserva()]));
    e.http.responder('GET', 'viajes', 200, _misViajes(proximas: [_reserva(estado: 'aceptado')]));

    await abrir(tester);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();
    expect(find.text('Esperando confirmación del chofer'), findsOneWidget);

    e.puente.controlador.add({'modulo': 'vehiculos_oficiales', 'tipo': 'viaje', 'viaje_id': '2', 'estado': 'aceptado'});
    await tester.pumpAndSettle();

    expect(find.textContaining('Confirmada'), findsOneWidget);
  });

  testWidgets('una reserva rechazada ofrece elegir otro chofer con los mismos datos', (tester) async {
    e.http.responder('GET', 'viajes', 200, _misViajes(historial: [_reserva(estado: 'sin_chofer')]));

    await abrir(tester);
    await tester.tap(find.byTooltip('Mis viajes'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Elegir otro'));
    await tester.pumpAndSettle();

    expect(find.byType(PantallaReserva), findsOneWidget);
    expect(find.text('Casa de Gobierno'), findsOneWidget);
  });
}
```

- [ ] **Step 2: Correr y ver que falla**

Run: `flutter test test/ui/reservas_test.dart`
Expected: FAIL (no existen `pantalla_reserva.dart` ni "Reservar para más tarde").

- [ ] **Step 3: Implementación**

`paquete/vehiculos_oficiales/lib/src/solicitante/mis_viajes.dart`:

```dart
import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../entorno.dart';
import '../modelos/modelos.dart';
import '../push/push_modulo.dart';

/// `GET /viajes` (próximas reservas e historial). Se vuelve a pedir con cada aviso push del módulo
/// (reserva aceptada o rechazada, recordatorios…).
final misViajesProvider = FutureProvider.autoDispose<MisViajes>((ref) {
  final escucha = ref.watch(pushModuloProvider).avisos.listen((_) => ref.invalidateSelf());
  ref.onDispose(() => unawaited(escucha.cancel()));
  return ref.watch(apiProvider).misViajes();
});
```

`paquete/vehiculos_oficiales/lib/src/ui/solicitante/pantalla_reserva.dart`:

```dart
import 'package:clock/clock.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../entorno.dart';
import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Elige fecha y hora (en la zona del dispositivo). Costura para los tests.
typedef ElegirFechaHora = Future<DateTime?> Function(BuildContext context, DateTime inicial);

final elegirFechaHoraProvider = Provider<ElegirFechaHora>((ref) => _elegirConPickers);

Future<DateTime?> _elegirConPickers(BuildContext context, DateTime inicial) async {
  final ahora = clock.now();
  final dia = await showDatePicker(
    context: context,
    initialDate: inicial,
    firstDate: DateTime(ahora.year, ahora.month, ahora.day),
    lastDate: ahora.add(const Duration(days: 90)),
  );
  if (dia == null || !context.mounted) return null;
  final hora = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(inicial));
  if (hora == null) return null;
  return DateTime(dia.year, dia.month, dia.day, hora.hour, hora.minute);
}

/// Reserva a futuro (spec 5.4 y 7, solicitante 5): fecha y hora, choferes disponibles o "Cualquiera
/// disponible", confirmación. Origen, destino y motivo vienen del pedido armado en el mapa.
class PantallaReserva extends ConsumerStatefulWidget {
  const PantallaReserva({super.key, this.fechaInicial});

  final DateTime? fechaInicial;

  @override
  ConsumerState<PantallaReserva> createState() => _PantallaReservaState();
}

class _PantallaReservaState extends ConsumerState<PantallaReserva> {
  late DateTime _cuando;
  DisponiblesReserva? _disponibles;

  /// Nulo = "Cualquiera disponible".
  int? _choferId;
  bool _cargando = false;

  @override
  void initState() {
    super.initState();
    final sugerida = clock.now().add(const Duration(hours: 2));
    final inicial = widget.fechaInicial?.toLocal();
    _cuando = inicial != null && inicial.isAfter(clock.now())
        ? inicial
        : DateTime(sugerida.year, sugerida.month, sugerida.day, sugerida.hour);
  }

  Future<void> _elegirFecha() async {
    final elegida = await ref.read(elegirFechaHoraProvider)(context, _cuando);
    if (elegida == null) return;
    setState(() {
      _cuando = elegida;
      _disponibles = null; // la franja cambió: hay que volver a consultar
      _choferId = null;
    });
  }

  Future<void> _consultar(BorradorPedido b) async {
    setState(() => _cargando = true);
    try {
      final d = await ref
          .read(apiProvider)
          .disponiblesReserva(FranjaReserva(programadoPara: _cuando, origen: b.origen!, destino: b.destino!));
      if (mounted) setState(() => _disponibles = d);
    } on Exception catch (e) {
      if (mounted) mostrarError(context, e);
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  Future<void> _confirmar(BorradorPedido b) async {
    setState(() => _cargando = true);
    final borrador = ref.read(borradorPedidoProvider.notifier);
    try {
      final reserva = await ref
          .read(apiProvider)
          .crearReserva(
            PedidoReserva(
              programadoPara: _cuando,
              modo: _choferId == null ? ModoViaje.cualquieraDisponible : ModoViaje.especifico,
              choferId: _choferId,
              origen: b.lugarOrigen!,
              destino: b.lugarDestino!,
              motivo: b.motivo.trim().isEmpty ? null : b.motivo.trim(),
            ),
          );
      borrador.limpiar();
      if (!mounted) return;
      final texto = reserva.estado == EstadoViaje.aceptado
          ? 'Reserva confirmada para ${formatearFechaHora(reserva.programadoPara!)}.'
          : 'Solicitud enviada. Te avisamos cuando el chofer responda.';
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(texto)));
      context.go(Rutas.misViajes);
    } on Exception catch (e) {
      if (mounted) mostrarError(context, e);
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final b = ref.watch(borradorPedidoProvider);
    final disponibles = _disponibles;

    return Scaffold(
      appBar: AppBar(title: const Text('Reservar un viaje')),
      body: !b.completo
          ? const Center(child: Text('Marcá el origen y el destino en el mapa antes de reservar.'))
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                ListTile(
                  leading: const Icon(Icons.trip_origin),
                  title: const Text('Origen'),
                  subtitle: Text(b.lugarOrigen!.descripcion),
                ),
                ListTile(
                  leading: const Icon(Icons.place),
                  title: const Text('Destino'),
                  subtitle: Text(b.lugarDestino!.descripcion),
                ),
                ListTile(
                  leading: const Icon(Icons.event),
                  title: const Text('Fecha y hora'),
                  subtitle: Text(formatearFechaHora(_cuando)),
                  trailing: const Icon(Icons.edit),
                  onTap: _cargando ? null : _elegirFecha,
                ),
                const SizedBox(height: 8),
                if (disponibles == null)
                  FilledButton(
                    onPressed: _cargando ? null : () => _consultar(b),
                    child: const Text('Ver choferes disponibles'),
                  )
                else ...[
                  Text('Duración estimada: ${disponibles.duracionEstimadaMin} min'),
                  const SizedBox(height: 8),
                  if (disponibles.choferes.isEmpty)
                    const Text('No hay choferes disponibles en ese horario. Probá con otra hora.')
                  else
                    RadioGroup<int?>(
                      groupValue: _choferId,
                      onChanged: (v) => setState(() => _choferId = v),
                      child: Column(
                        children: [
                          const RadioListTile<int?>(
                            value: null,
                            title: Text('Cualquiera disponible'),
                            subtitle: Text('El sistema elige al que tenga menos reservas ese día.'),
                          ),
                          for (final c in disponibles.choferes)
                            RadioListTile<int?>(
                              value: c.id,
                              title: Text(c.nombre),
                              subtitle: Text('${c.reservasDelDia} reservas ese día'),
                            ),
                        ],
                      ),
                    ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _cargando || disponibles.choferes.isEmpty ? null : () => _confirmar(b),
                    child: const Text('Confirmar reserva'),
                  ),
                ],
              ],
            ),
    );
  }
}
```

`paquete/vehiculos_oficiales/lib/src/ui/solicitante/mis_viajes.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../entorno.dart';
import '../../modelos/modelos.dart';
import '../../solicitante/borrador_pedido.dart';
import '../../solicitante/mis_viajes.dart';
import '../comunes/comunes.dart';
import '../modulo_app.dart';

/// Próximas reservas e historial (spec 7, solicitante 6).
class MisViajesPantalla extends ConsumerWidget {
  const MisViajesPantalla({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final mis = ref.watch(misViajesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Mis viajes')),
      body: switch (mis) {
        AsyncData(:final value) => RefreshIndicator(
          onRefresh: () => ref.refresh(misViajesProvider.future),
          child: ListView(
            children: [
              const _Titulo('Próximas reservas'),
              if (value.proximas.isEmpty) const ListTile(title: Text('No tenés reservas próximas.')),
              for (final v in value.proximas) _Proxima(viaje: v),
              const _Titulo('Historial'),
              if (value.historial.isEmpty) const ListTile(title: Text('Todavía no hiciste viajes.')),
              for (final v in value.historial) _Pasado(viaje: v),
            ],
          ),
        ),
        AsyncError(:final error) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(mensajeDeError(error)),
              TextButton(onPressed: () => ref.invalidate(misViajesProvider), child: const Text('Reintentar')),
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

String _estadoReserva(Viaje v) => switch (v.estado) {
  EstadoViaje.buscando || EstadoViaje.ofrecido => 'Esperando confirmación del chofer',
  EstadoViaje.aceptado => 'Confirmada · ${v.chofer?.nombre ?? ''}',
  _ => v.estado.texto,
};

class _Proxima extends ConsumerWidget {
  const _Proxima({required this.viaje});

  final Viaje viaje;

  Future<void> _cancelar(BuildContext context, WidgetRef ref) async {
    final confirma = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('¿Cancelar la reserva?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('No')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Sí, cancelar')),
        ],
      ),
    );
    if (confirma != true) return;
    try {
      await ref.read(apiProvider).cancelarViaje(viaje.id);
      ref.invalidate(misViajesProvider);
    } on Exception catch (e) {
      if (context.mounted) mostrarError(context, e);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ListTile(
      leading: const Icon(Icons.event),
      title: Text('${formatearFechaHora(viaje.programadoPara!)} · ${viaje.destino.descripcion}'),
      subtitle: Text(_estadoReserva(viaje)),
      trailing: viaje.estado.cancelablePorSolicitante
          ? IconButton(
              icon: const Icon(Icons.cancel),
              tooltip: 'Cancelar reserva',
              onPressed: () => _cancelar(context, ref),
            )
          : null,
    );
  }
}

class _Pasado extends ConsumerWidget {
  const _Pasado({required this.viaje});

  final Viaje viaje;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cuando = viaje.programadoPara ?? viaje.finalizadoEn ?? viaje.canceladoEn ?? viaje.aceptadoEn;
    final reservaSinChofer = viaje.tipo == TipoViaje.reserva && viaje.estado == EstadoViaje.sinChofer;
    return ListTile(
      leading: Icon(viaje.tipo == TipoViaje.reserva ? Icons.event_available : Icons.local_taxi),
      title: Text([if (cuando != null) formatearFechaHora(cuando), viaje.destino.descripcion].join(' · ')),
      subtitle: Text(viaje.estado.texto),
      // Spec 5.4: si el chofer rechazó la reserva o no respondió, el solicitante elige otro.
      trailing: reservaSinChofer
          ? TextButton(
              onPressed: () {
                ref.read(borradorPedidoProvider.notifier).desdeViaje(viaje);
                context.push(Rutas.reservar, extra: viaje.programadoPara);
              },
              child: const Text('Elegir otro'),
            )
          : null,
    );
  }
}
```

- [ ] **Step 4: Rutas y accesos**

`paquete/vehiculos_oficiales/lib/src/ui/modulo_app.dart` (reemplazar entero; es la versión final):

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
}

/// Raíz del módulo: su propio `ProviderScope` y su propio router (no toca los de la app principal).
class ModuloVehiculos extends StatelessWidget {
  const ModuloVehiculos({super.key, required this.entorno, required this.alCerrar, this.overrides = const []});

  final EntornoModulo entorno;
  final VoidCallback alCerrar;

  /// Solo para tests (HTTP falso, Reverb falso, mapa de prueba…).
  @visibleForTesting
  final List<Override> overrides;

  @override
  Widget build(BuildContext context) {
    return ProviderScope(
      // Riverpod 3 reintenta por defecto los providers que fallan; acá los errores se muestran y se
      // reintentan a mano o por el respaldo de 10 s.
      retry: (_, _) => null,
      overrides: [
        entornoProvider.overrideWithValue(entorno),
        cerrarModuloProvider.overrideWithValue(alCerrar),
        ...overrides,
      ],
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
            GoRoute(path: Rutas.chofer, builder: (_, _) => const InicioChofer()),
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

En `paquete/vehiculos_oficiales/lib/src/ui/solicitante/inicio_solicitante.dart`, agregar al `AppBar` (después de `leading`):

```dart
        actions: [
          IconButton(
            icon: const Icon(Icons.history),
            tooltip: 'Mis viajes',
            onPressed: () => context.push(Rutas.misViajes),
          ),
        ],
```

y reemplazar el bloque del botón fijo del panel (desde el comentario `// Fuera del desplazamiento…` hasta el final de la lista de hijos) por:

```dart
            // Fuera del desplazamiento: los botones siempre quedan a la vista.
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
              child: FilledButton(
                onPressed: b.completo && !_enviando ? _pedir : null,
                child: Text(b.chofer == null ? 'Pedir el más cercano' : 'Pedir a ${b.chofer!.nombre}'),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: TextButton(
                onPressed: b.completo && !_enviando ? () => context.push(Rutas.reservar) : null,
                child: const Text('Reservar para más tarde'),
              ),
            ),
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: **84 PASS**, sin problemas, `0 changed`.

- [ ] **Step 6: Commit**

```bash
git add paquete
git commit -m "feat: reservas a futuro y mis viajes del solicitante" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: host_prueba (la app del PJ simulada) y configuración de plataformas

**Files:**
- Create (con `flutter create`): `host_prueba/` (Android, iOS y web; se borran `README.md` y `test/widget_test.dart`)
- Modify: `host_prueba/pubspec.yaml`, `host_prueba/analysis_options.yaml`
- Create: `host_prueba/lib/{config_host,puente_falso,login_falso,herramientas}.dart`; Modify: `host_prueba/lib/main.dart`
- Modify: `host_prueba/android/app/build.gradle.kts`, `host_prueba/android/app/src/main/AndroidManifest.xml`, `host_prueba/android/app/src/debug/AndroidManifest.xml`, `host_prueba/android/.gitignore`
- Modify: `host_prueba/ios/Runner/Info.plist`, `host_prueba/ios/Runner/AppDelegate.swift`, `host_prueba/ios/Flutter/{Debug,Release}.xcconfig`, `host_prueba/ios/.gitignore`
- Test: `host_prueba/test/host_test.dart`

**Interfaces:**
- Consumes: `VehiculosOficiales.abrir`, `VehiculosOficialesConfig`, `SesionPJ`, `PuenteNotificaciones` (API pública del paquete).
- Produces: `tokenSimulado({id, nombre, cargo})`, `HostPrueba`, `LoginFalso`, `Herramientas({tokenSesion, config})`, `PuenteNotificacionesFalso` (con `simular(data)`), `configHost`.

- [ ] **Step 1: Crear la app**

Desde la raíz del repo:

```bash
flutter create --org ar.gob.pj.vehiculos --platforms android,ios,web --project-name host_prueba host_prueba
cd host_prueba
rm README.md test/widget_test.dart
```

`host_prueba/pubspec.yaml` (reemplazar entero):

```yaml
name: host_prueba
description: "App de prueba que simula a la app del Poder Judicial para desarrollar el módulo Vehículos Oficiales."
publish_to: none
version: 0.1.0+1

environment:
  sdk: ^3.11.0

dependencies:
  flutter:
    sdk: flutter
  cupertino_icons: ^1.0.8
  vehiculos_oficiales:
    path: ../paquete/vehiculos_oficiales

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^6.0.0

flutter:
  uses-material-design: true
```

Copiar `paquete/vehiculos_oficiales/analysis_options.yaml` a `host_prueba/analysis_options.yaml` y correr `flutter pub get`. Esta app **sí** versiona su `pubspec.lock` (fija las versiones verificadas).

- [ ] **Step 2: Escribir el test que falla**

`host_prueba/test/host_test.dart`:

```dart
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
```

- [ ] **Step 3: Correr y ver que falla**

Run: `flutter test`
Expected: FAIL (no existe `login_falso.dart`).

- [ ] **Step 4: Implementación**

`host_prueba/lib/config_host.dart`:

```dart
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

/// Configuración por `--dart-define`. Los valores por defecto sirven para el emulador de Android contra el
/// backend local (`php artisan serve` en el puerto 8000 y `php artisan reverb:start` en el 8080):
/// `10.0.2.2` es el "localhost" de la PC vista desde el emulador.
const configHost = VehiculosOficialesConfig(
  apiBaseUrl: String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000'),
  reverbHost: String.fromEnvironment('REVERB_HOST', defaultValue: '10.0.2.2'),
  reverbPort: int.fromEnvironment('REVERB_PORT', defaultValue: 8080),
  reverbScheme: String.fromEnvironment('REVERB_SCHEME', defaultValue: 'http'),
  reverbKey: String.fromEnvironment('REVERB_APP_KEY'),
  googleMapsApiKey: String.fromEnvironment('MAPS_API_KEY'),
);
```

`host_prueba/lib/puente_falso.dart`:

```dart
import 'dart:async';

import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

/// host_prueba no usa Firebase: no hay token push y los "mensajes" se inyectan a mano (útil para probar
/// la reacción del módulo a un push). En la app del PJ esto lo implementa su integración con FCM.
class PuenteNotificacionesFalso implements PuenteNotificaciones {
  final _mensajes = StreamController<Map<String, dynamic>>.broadcast();

  @override
  Future<String?> token() async => null;

  @override
  Stream<Map<String, dynamic>> get mensajes => _mensajes.stream;

  void simular(Map<String, dynamic> data) => _mensajes.add(data);
}
```

`host_prueba/lib/main.dart` (reemplazar entero):

```dart
import 'package:flutter/material.dart';

import 'login_falso.dart';

void main() => runApp(const HostPrueba());

/// Simula a la app del Poder Judicial: login falso y la sección "Herramientas".
class HostPrueba extends StatelessWidget {
  const HostPrueba({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'App PJ (prueba)',
      theme: ThemeData(colorSchemeSeed: const Color(0xFF1B4F72)),
      home: const LoginFalso(),
    );
  }
}
```

`host_prueba/lib/login_falso.dart`:

```dart
import 'package:flutter/material.dart';

import 'herramientas.dart';

/// Token que acepta el backend con `IDENTIDAD_DRIVER=simulada` (IdentidadSimulada): `sim|<id>|<nombre>|<cargo>`.
String tokenSimulado({required String id, required String nombre, required String cargo}) =>
    'sim|${id.trim()}|${nombre.trim()}|${cargo.trim()}';

class _Perfil {
  const _Perfil(this.id, this.nombre, this.cargo);

  final String id;
  final String nombre;
  final String cargo;
}

/// Perfiles de ejemplo. El rol de chofer lo asigna un admin en el panel (spec 8): la primera vez que
/// "Carlos Chofer" entra queda como solicitante hasta que se lo cambien. "Juez" genera viajes obligatorios
/// si el cargo está marcado en Cargos prioritarios.
const _perfiles = [
  _Perfil('100', 'Ana Pérez', 'Secretaria'),
  _Perfil('101', 'Jorge Juez', 'Juez'),
  _Perfil('200', 'Carlos Chofer', 'Chofer'),
];

class LoginFalso extends StatefulWidget {
  const LoginFalso({super.key});

  @override
  State<LoginFalso> createState() => _LoginFalsoState();
}

class _LoginFalsoState extends State<LoginFalso> {
  final _form = GlobalKey<FormState>();
  final _id = TextEditingController(text: _perfiles.first.id);
  final _nombre = TextEditingController(text: _perfiles.first.nombre);
  final _cargo = TextEditingController(text: _perfiles.first.cargo);

  @override
  void dispose() {
    _id.dispose();
    _nombre.dispose();
    _cargo.dispose();
    super.dispose();
  }

  String? _validar(String? v) {
    if (v == null || v.trim().isEmpty) return 'Obligatorio';
    if (v.contains('|')) return 'No puede tener "|"';
    return null;
  }

  void _entrar() {
    if (!_form.currentState!.validate()) return;
    final token = tokenSimulado(id: _id.text, nombre: _nombre.text, cargo: _cargo.text);
    Navigator.of(context).pushReplacement(MaterialPageRoute<void>(builder: (_) => Herramientas(tokenSesion: token)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('App del Poder Judicial (prueba)')),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            Wrap(
              spacing: 8,
              children: [
                for (final p in _perfiles)
                  ActionChip(
                    label: Text(p.nombre),
                    onPressed: () => setState(() {
                      _id.text = p.id;
                      _nombre.text = p.nombre;
                      _cargo.text = p.cargo;
                    }),
                  ),
              ],
            ),
            TextFormField(
              controller: _id,
              decoration: const InputDecoration(labelText: 'Id externo'),
              validator: _validar,
            ),
            TextFormField(
              controller: _nombre,
              decoration: const InputDecoration(labelText: 'Nombre'),
              validator: _validar,
            ),
            TextFormField(
              controller: _cargo,
              decoration: const InputDecoration(labelText: 'Cargo'),
              validator: _validar,
            ),
            const SizedBox(height: 24),
            FilledButton(onPressed: _entrar, child: const Text('Ingresar')),
          ],
        ),
      ),
    );
  }
}
```

`host_prueba/lib/herramientas.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:vehiculos_oficiales/vehiculos_oficiales.dart';

import 'config_host.dart';
import 'login_falso.dart';
import 'puente_falso.dart';

/// Sección "Herramientas" de la app principal, con el ítem que abre el módulo (spec 12.2).
class Herramientas extends StatefulWidget {
  const Herramientas({super.key, required this.tokenSesion, this.config = configHost});

  final String tokenSesion;
  final VehiculosOficialesConfig config;

  @override
  State<Herramientas> createState() => _HerramientasState();
}

class _HerramientasState extends State<Herramientas> {
  final _push = PuenteNotificacionesFalso();

  void _cerrarSesion({String? aviso}) {
    final navegador = Navigator.of(context);
    final mensajero = ScaffoldMessenger.of(context);
    navegador.popUntil((r) => r.isFirst);
    navegador.pushReplacement(MaterialPageRoute<void>(builder: (_) => const LoginFalso()));
    if (aviso != null) mensajero.showSnackBar(SnackBar(content: Text(aviso)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Herramientas'),
        actions: [IconButton(icon: const Icon(Icons.logout), tooltip: 'Cerrar sesión', onPressed: _cerrarSesion)],
      ),
      body: ListView(
        children: [
          ListTile(
            leading: const Icon(Icons.directions_car),
            title: const Text('Vehículos oficiales'),
            subtitle: Text(widget.tokenSesion),
            onTap: () => VehiculosOficiales.abrir(
              context,
              sesion: SesionPJ(widget.tokenSesion),
              push: _push,
              onSesionInvalida: () => _cerrarSesion(aviso: 'Tu sesión venció. Volvé a ingresar.'),
              config: widget.config,
            ),
          ),
          ListTile(
            leading: const Icon(Icons.notifications),
            title: const Text('Simular push "viaje"'),
            subtitle: const Text('Como si FCM trajera un cambio de estado'),
            onTap: () => _push.simular({'modulo': 'vehiculos_oficiales', 'tipo': 'viaje'}),
          ),
        ],
      ),
    );
  }
}
```

- [ ] **Step 5: Correr tests y análisis**

Run: `flutter test && flutter analyze && dart format --output=none --set-exit-if-changed lib test`
Expected: 3 PASS, sin problemas, `0 changed`.

- [ ] **Step 6: Android**

Los archivos generados en Windows pueden tener fin de línea CRLF; conviene normalizarlos a LF antes de editar.

`host_prueba/android/app/src/main/AndroidManifest.xml` (reemplazar entero):

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <!-- Módulo Vehículos Oficiales (spec 12.4): API y Reverb, GPS del chofer en segundo plano y avisos. -->
    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
    <uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
    <uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
    <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
    <application
        android:label="Host prueba PJ"
        android:name="${applicationName}"
        android:icon="@mipmap/ic_launcher">
        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:launchMode="singleTop"
            android:taskAffinity=""
            android:theme="@style/LaunchTheme"
            android:configChanges="orientation|keyboardHidden|keyboard|screenSize|smallestScreenSize|locale|layoutDirection|fontScale|screenLayout|density|uiMode"
            android:hardwareAccelerated="true"
            android:windowSoftInputMode="adjustResize">
            <!-- Specifies an Android theme to apply to this Activity as soon as
                 the Android process has started. This theme is visible to the user
                 while the Flutter UI initializes. After that, this theme continues
                 to determine the Window background behind the Flutter UI. -->
            <meta-data
              android:name="io.flutter.embedding.android.NormalTheme"
              android:resource="@style/NormalTheme"
              />
            <intent-filter>
                <action android:name="android.intent.action.MAIN"/>
                <category android:name="android.intent.category.LAUNCHER"/>
            </intent-filter>
        </activity>
        <!-- Don't delete the meta-data below.
             This is used by the Flutter tool to generate GeneratedPluginRegistrant.java -->
        <meta-data
            android:name="flutterEmbedding"
            android:value="2" />
        <!-- Clave de Google Maps: MAPS_API_KEY en android/secretos.properties (ignorado por git). -->
        <meta-data
            android:name="com.google.android.geo.API_KEY"
            android:value="${MAPS_API_KEY}" />
    </application>
    <!-- Required to query activities that can process text, see:
         https://developer.android.com/training/package-visibility and
         https://developer.android.com/reference/android/content/Intent#ACTION_PROCESS_TEXT.

         In particular, this is used by the Flutter engine in io.flutter.plugin.text.ProcessTextPlugin. -->
    <queries>
        <intent>
            <action android:name="android.intent.action.PROCESS_TEXT"/>
            <data android:mimeType="text/plain"/>
        </intent>
        <!-- url_launcher: llamar al chofer / solicitante y abrir Google Maps o Waze (Android 11+). -->
        <intent>
            <action android:name="android.intent.action.DIAL" />
            <data android:scheme="tel" />
        </intent>
        <intent>
            <action android:name="android.intent.action.VIEW" />
            <data android:scheme="https" />
        </intent>
        <intent>
            <action android:name="android.intent.action.VIEW" />
            <data android:scheme="google.navigation" />
        </intent>
        <intent>
            <action android:name="android.intent.action.VIEW" />
            <data android:scheme="waze" />
        </intent>
    </queries>
</manifest>
```

`host_prueba/android/app/src/debug/AndroidManifest.xml` (reemplazar entero; el tráfico sin cifrar queda solo en debug):

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <!-- The INTERNET permission is required for development. Specifically,
         the Flutter tool needs it to communicate with the running application
         to allow setting breakpoints, to provide hot reload, etc.
    -->
    <uses-permission android:name="android.permission.INTERNET"/>
    <!-- Solo en debug: el backend local se sirve por http (php artisan serve, reverb:start). -->
    <application android:usesCleartextTraffic="true" />
</manifest>
```

`host_prueba/android/app/build.gradle.kts` (reemplazar entero):

```kotlin
import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Secretos locales (no se versionan): MAPS_API_KEY=...
val secretos = Properties().apply {
    val archivo = rootProject.file("secretos.properties")
    if (archivo.exists()) archivo.inputStream().use { load(it) }
}

android {
    namespace = "ar.gob.pj.vehiculos.host_prueba"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_17.toString()
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "ar.gob.pj.vehiculos.host_prueba"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        manifestPlaceholders["MAPS_API_KEY"] = secretos.getProperty("MAPS_API_KEY", "")
    }

    buildTypes {
        release {
            // TODO: Add your own signing config for the release build.
            // Signing with the debug keys for now, so `flutter run --release` works.
            signingConfig = signingConfigs.getByName("debug")
        }
    }
}

flutter {
    source = "../.."
}
```

Agregar al final de `host_prueba/android/.gitignore`:

```
# Clave de Google Maps y otros secretos locales
secretos.properties
```

Para usar el mapa, crear `host_prueba/android/secretos.properties` (no se versiona) con `MAPS_API_KEY=<clave con Maps SDK for Android habilitado>`. Sin ese archivo la app compila y el mapa queda en gris.

- [ ] **Step 7: iOS (no se puede compilar desde Windows: verificar en una Mac)**

Agregar al final del `<dict>` principal de `host_prueba/ios/Runner/Info.plist`, antes de `</dict></plist>`:

```xml
	<!-- Módulo Vehículos Oficiales (spec 12.4) -->
	<key>NSLocationWhenInUseUsageDescription</key>
	<string>Para marcar tu ubicación como origen del viaje y, si sos chofer, compartirla durante el turno.</string>
	<key>NSLocationAlwaysAndWhenInUseUsageDescription</key>
	<string>Mientras tu turno de chofer está abierto, la app comparte tu ubicación aunque esté en segundo plano.</string>
	<key>UIBackgroundModes</key>
	<array>
		<string>location</string>
	</array>
	<key>LSApplicationQueriesSchemes</key>
	<array>
		<string>tel</string>
		<string>comgooglemaps</string>
		<string>waze</string>
	</array>
	<!-- Solo host_prueba: backend local por http. -->
	<key>NSAppTransportSecurity</key>
	<dict>
		<key>NSAllowsLocalNetworking</key>
		<true/>
	</dict>
	<key>GMSApiKey</key>
	<string>$(MAPS_API_KEY)</string>
```

`host_prueba/ios/Runner/AppDelegate.swift` (reemplazar entero):

```swift
import Flutter
import GoogleMaps
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    if let clave = Bundle.main.object(forInfoDictionaryKey: "GMSApiKey") as? String, !clave.isEmpty {
      GMSServices.provideAPIKey(clave)
    }
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
  }
}
```

Agregar al final de `host_prueba/ios/Flutter/Debug.xcconfig` y de `host_prueba/ios/Flutter/Release.xcconfig` la línea `#include? "Secretos.xcconfig"`, y al final de `host_prueba/ios/.gitignore`:

```
# Clave de Google Maps (MAPS_API_KEY = ...)
Flutter/Secretos.xcconfig
```

La clave va en `host_prueba/ios/Flutter/Secretos.xcconfig` (`MAPS_API_KEY = <clave>`), que no se versiona.

- [ ] **Step 8: Compilar**

Run (desde `host_prueba/`): `flutter build web`
Expected: `✓ Built build\web`. Para ver el mapa en Chrome (`flutter run -d chrome --dart-define=API_URL=http://localhost:8000 --dart-define=REVERB_HOST=localhost --dart-define=REVERB_APP_KEY=<REVERB_APP_KEY del .env>`), agregar localmente en `web/index.html`, dentro de `<head>`, `<script src="https://maps.googleapis.com/maps/api/js?key=<clave JS>"></script>` y no commitearlo.

Run: `flutter build apk --debug`
Expected: `✓ Built build/app/outputs/flutter-apk/app-debug.apk`. La primera vez Gradle instala solo el NDK, las plataformas 35/36 del SDK y CMake (acepta las licencias) y puede tardar varios minutos. Si falla por licencias, correr `flutter doctor --android-licenses`; si no hay SDK de Android, anotarlo y seguir (no bloquea el plan).

- [ ] **Step 9: Prueba manual punta a punta (spec 11)**

Con el backend local (`backend/.env`: `IDENTIDAD_DRIVER=simulada`, `BROADCAST_CONNECTION=reverb`, claves `REVERB_*`), en tres consolas desde `backend/`: `php artisan serve --host=0.0.0.0`, `php artisan reverb:start`, `php artisan queue:work`; y `php artisan simular:choferes 5` para tener choferes moviéndose. Desde `host_prueba/`:

```bash
flutter run --dart-define=REVERB_APP_KEY=<REVERB_APP_KEY del .env> --dart-define=MAPS_API_KEY=<clave>
```

Verificar: ingresar como "Ana Pérez" → Herramientas → Vehículos oficiales; se ven los choferes simulados moviéndose; pedir el más cercano; con otra sesión (o el panel) ver el viaje; cortar Reverb (`Ctrl+C` en `reverb:start`) y comprobar el aviso "Sin conexión en tiempo real" y que el estado se sigue actualizando; volver a levantarlo y ver que el aviso desaparece.

- [ ] **Step 10: Commit**

```bash
git add host_prueba
git commit -m "feat: host_prueba simula la app del PJ con login falso y Herramientas" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Qué tiene que agregar la app del PJ (spec 12)

- **Dependencia:** `vehiculos_oficiales` (por `path` o `git`), con Flutter ≥ 3.41 y Dart ≥ 3.11, y las dependencias del Tech Stack compatibles con las suyas (decisiones 2 y 15).
- **Ítem en "Herramientas"** que llame a `VehiculosOficiales.abrir(context, sesion: SesionPJ(<token de sesión>), push: <su PuenteNotificaciones>, onSesionInvalida: <volver a su login>, config: VehiculosOficialesConfig(apiBaseUrl: …, reverbHost: …, reverbPort: 443, reverbScheme: 'https', reverbKey: …))`.
- **Puente FCM:** implementar `PuenteNotificaciones` con su `FirebaseMessaging` (token del dispositivo y reenvío de `onMessage`/`onMessageOpenedApp` cuyo `data['modulo'] == 'vehiculos_oficiales'`), y abrir el módulo al tocar una de esas notificaciones.
- **AndroidManifest.xml:** `INTERNET`, `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION`, `ACCESS_BACKGROUND_LOCATION` (solo si se pide "permitir siempre"; ver plan del chofer), `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION` (el servicio `GeolocatorLocationService` con `foregroundServiceType="location"` lo aporta `geolocator_android`), `POST_NOTIFICATIONS`; `queries` para `tel`, `https`, `google.navigation` y `waze`; `meta-data com.google.android.geo.API_KEY`; `minSdk` 24. Sin tráfico sin cifrar en producción. Justificar ante Google Play la ubicación en segundo plano / servicio en primer plano de tipo `location` (spec 12.4).
- **Info.plist:** `NSLocationWhenInUseUsageDescription`, `NSLocationAlwaysAndWhenInUseUsageDescription`, `UIBackgroundModes` → `location` (y `remote-notification` si su FCM lo usa), `LSApplicationQueriesSchemes` (`tel`, `comgooglemaps`, `waze`), y `GMSServices.provideAPIKey` en su `AppDelegate`.
- **Clave de Google Maps** con Maps SDK for Android/iOS (y, si se decide usar Places, Places API), spec 12.6.

## Cobertura del spec en este plan

| Spec | Task |
|---|---|
| 3.1 Paquete con un único punto de entrada; `host_prueba` con login falso | 1, 8, 13 |
| 3.2 Intercambio de sesión y token Sanctum en API y canales privados | 3, 4, 5 |
| 5.2 / 5.3 Pedido inmediato: más cercano y chofer específico (solo libres), `sin_chofer` con "Elegir otro" / "Pedir el más cercano" | 10, 11 |
| 5.4 Reserva: fecha y hora, choferes disponibles o cualquiera, confirmación, rechazo → elegir otro | 12 |
| 5.5 Solicitante: chofer en vivo, datos del chofer y del vehículo, llamar, estado | 6, 10 |
| 5.6 El solicitante cancela antes de `en_curso` | 10, 12 |
| 6 Canales `mapa.choferes`, `viaje.{id}`, `chofer.{id}`; sondeo cada 10 s y estado completo al reconectar | 5, 6, 9 |
| 6 / 12.3 Push por el puente de la app principal | 7 |
| 7 Solicitante 1–6 | 10, 11, 12 |
| 9 Token del PJ inválido → `onSesionInvalida`; PJ caído → token Sanctum vigente o "servicio de identidad no disponible"; permiso de ubicación denegado → origen a mano | 4, 8, 11 |
| 11 Tests de providers y widgets; prueba manual con `host_prueba` | todas, 13 |
| 12 Dependencias con el equipo de la app principal | Decisiones, "Qué tiene que agregar la app del PJ" |

**Fuera de este plan:**
- Todo lo del chofer (spec 7, chofer 1–6; GPS en segundo plano, spec 7 y 9): plan `2026-09-30-flutter-chofer.md`. La base (`viajeActualProvider` con `chofer.{id}` y ofertas, `Ubicador`, `ApiVehiculos`) ya queda lista.
- Autocompletado de direcciones con Places y ETA real (decisiones 11 y A8).
- Cableado real de FCM (lo hace la app del PJ).
