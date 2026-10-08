# Etapa 3: el chofer sigue trabajando sin señal en la ruta — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** En los viajes al interior, el chofer pasa por zonas sin señal. Hoy:
- **La ubicación** ya se guarda en el celular y se envía al recuperar la señal (`cola_ubicaciones`).
- **"Llegué", "Iniciar viaje" y "Finalizar"** necesitan conexión. Sin señal fallan, y el chofer no puede seguir.
- **Los puntos de recorrido** que llegan tarde se pierden si el viaje ya no está "en curso" o si el turno ya se cerró.
- **El mapa** no muestra nada sin señal, porque las teselas no se guardan.

**Objetivo:**
- Los botones funcionan sin señal: se guardan con su **hora real**, la app avanza al instante y se envían en orden al recuperar la señal.
- El servidor acepta esas horas.
- Los puntos de GPS atrasados se asignan al viaje que corresponda según su hora.
- El mapa ya visto se ve sin señal.

**Architecture:**
- Backend Laravel:
  - `POST /api/viajes/{viaje}/estado {estado}` → `ViajeController::avanzar` → `ServicioViaje::avanzar(viaje, chofer, EstadoViaje)` → `MaquinaEstadosViaje`, que guarda `llego_en`, `iniciado_en` y `finalizado_en` con `now()`, todo en `guardar()` y bajo locks;
  - salida hacia una reserva o viaje largo: `salirHaciaReserva`;
  - `POST /api/ubicacion` → `ServicioUbicacion::registrar(chofer, puntos[])`, que exige turno abierto, guarda los puntos solo para el viaje **en curso ahora** y desde `iniciado_en` (con índice único `(viaje_id, registrado_en)`), y llama a `recalcularSiYaFinalizo`;
  - `CompletadorDirecciones` y `metros_recorridos` se guardan al finalizar;
  - `CerrarTurnoPendiente`.
- Paquete Flutter:
  - `lib/src/chofer/{cola_ubicaciones, emisor_ubicacion, almacen_cola, rastreador_turno, turno}.dart`: cola persistente de GPS en el directorio de caché, con envío por lotes;
  - `lib/src/viaje/viaje_actual.dart`: `avanzar(EstadoViaje)`, `salirHaciaReserva`, con guardas de versión y vigencia;
  - `lib/src/chofer/guia_ruta.dart`: la indicación de giros usa la ruta ya obtenida y, si falla el recálculo, conserva la anterior;
  - `MapaOsm` con `TileLayer` y `tileProvider` configurable.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`, puntos 6 (tiempo real y reconexión), 7 (chofer) y 10 (privacidad).

## Decisiones

1. **Backend — avance con la hora real:**
   - **Campos opcionales** en `POST /viajes/{id}/estado`:
     - `momento` (ISO-8601): cuándo lo tocó el chofer;
     - `id_accion` (uuid de la app): para la idempotencia.
   - **Validación de `momento`:**
     - no más de 2 minutos en el futuro;
     - no anterior al estado previo, por ejemplo `llego_en ≥ en_camino` (aceptado), `iniciado_en ≥ llego_en`, `finalizado_en ≥ iniciado_en`;
     - no más de 24 h atrás.
     - Fuera de eso responde 422, con un mensaje en español. Sin `momento`, se usa `now()` como hoy.
   - **Los timestamps del viaje** usan ese momento. `metros_recorridos` y los avisos funcionan igual.
   - **Idempotencia:** una acción con el mismo `id_accion` ya aplicada devuelve el viaje actual con 200, sin volver a aplicarla. Se guarda en una tabla `acciones_viaje` (`id_accion` único, `viaje_id`, `estado`, `momento`, `aplicada_en`) o en una columna equivalente.
   - **Conflictos:** si el viaje fue cancelado o reasignado mientras tanto, responde con 409 o el 422 que corresponda y un mensaje claro ("El viaje fue cancelado mientras estabas sin señal."), sin cambiar nada.
   - Lo mismo vale para salir hacia una reserva o viaje largo ("Voy en camino").
2. **Backend — puntos de GPS atrasados:**
   - Cada punto se asigna al viaje **del chofer** cuyo intervalo `[iniciado_en, finalizado_en o ahora]` contiene su hora, aunque el viaje ya esté finalizado o cancelado.
   - Si se agregan puntos a un viaje finalizado, se recalcula `metros_recorridos` (ya existe `recalcularSiYaFinalizo`).
   - **Sin turno abierto,** los puntos que caen dentro del intervalo de un viaje del chofer **se aceptan igual**, porque son puntos del recorrido. Lo que no se actualiza es la ubicación actual ni el estado del chofer. Solo se responde "Iniciá un turno…" si ningún punto corresponde a un viaje y no hay turno.
   - La ubicación actual se actualiza solo con turno abierto, como hoy.
   - **Puntos de más de 24 h:** se descartan.
3. **App — acciones sin señal:**
   - **Cola persistente** de acciones del viaje (`cola_acciones.dart`, en el mismo almacenamiento que la cola de GPS). Cada acción guarda `id_accion`, `viaje_id`, `estado` y `momento`.
   - **Al tocar un botón,** la app aplica el cambio **al instante** (estado local optimista) y guarda la acción. Si hay conexión la envía; si falla por red, queda en cola.
   - **Envío al recuperar la señal** (reconexión del socket, cada 30 s mientras haya pendientes, al volver a primer plano y al iniciar):
     1. primero las acciones, en orden;
     2. después la cola de GPS, así el servidor ya conoce el intervalo del viaje.
   - **Indicador:** "Sin señal: 2 acciones se enviarán al reconectar". Se ve en la pantalla del viaje y en el mapa del chofer mientras haya pendientes.
   - **Conflictos:** si el servidor rechaza una acción (409/422, por ejemplo un viaje cancelado), se descartan las acciones pendientes de ese viaje, se refresca el estado real y se muestra el mensaje.
   - **"Finalizar turno"** queda deshabilitado mientras haya acciones o puntos pendientes, con el motivo "Esperando señal para enviar el viaje".
   - Mientras haya acciones pendientes, los eventos del servidor más viejos que el estado local no lo pisan (las guardas existentes de versión).
4. **App — mapa sin señal:**
   - Las teselas que se vieron quedan en **caché en disco**, con un tamaño máximo de unos 200 MB y descarte de las más viejas, usando un `TileProvider` con caché (por ejemplo `flutter_map_cache` con un almacén en archivos, o la opción mantenida que resulte más simple para flutter_map 8). Solo `mapa_osm.dart` importa los paquetes de mapa.
   - **Viajes largos:** al tocar "Voy en camino" se **descargan por adelantado** las teselas a lo largo del recorrido (zoom 10 a 14, un corredor de unos 2 km), en segundo plano y con un límite de unas 3000 teselas. Se cancela si cambia el viaje.
   - La indicación de giros sigue funcionando sin señal con la ruta ya obtenida; sin señal no se recalcula y muestra "Sin señal: recorrido sin actualizar".
5. **Panel:** en la línea de tiempo del viaje, una marca "(registrado sin señal, enviado HH:MM)" cuando la acción llegó más de 2 minutos después de su `momento`.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend:
  - Pest en verde y Pint limpio en los archivos tocados;
  - las migraciones funcionan en SQLite y MariaDB;
  - el orden de locks no cambia;
  - la suite `tests/Concurrencia` en MariaDB debe seguir verde; la corre el controlador.
- Paquete:
  - `flutter analyze` y `dart format --output=none --set-exit-if-changed lib test` limpios;
  - todos los tests en verde;
  - `host_prueba` pasa `flutter test`, `flutter analyze` y `flutter build apk --debug`;
  - toda llamada HTTP pasa por `ApiVehiculos`;
  - timers y streams viven en notifiers, y se usa `ref.mounted` después de cada `await`;
  - sin errores asíncronos sin capturar.
- Git: los agentes pueden trabajar en paralelo. Nunca `git add -A`; los archivos nuevos se agregan con su ruta explícita y se commitea con pathspec.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — avance con la hora real e idempotente, y puntos atrasados
Files:
- `ViajeController::avanzar` (y el de salir hacia una reserva);
- `ServicioViaje::avanzar` + `MaquinaEstadosViaje` (momento);
- la migración (`acciones_viaje`);
- `ServicioUbicacion::registrar`;
- `ViewViaje` (la marca "registrado sin señal");
- tests: momento válido e inválido (futuro, antes del estado previo, más de 24 h), `id_accion` repetido, viaje cancelado mientras tanto, puntos atrasados con el viaje finalizado y sin turno (con recálculo de metros), y la marca en el panel.

Commit: `feat: el servidor acepta las acciones del chofer con su hora real y los puntos atrasados`

### Task 2: App — mapa sin señal
Files: `mapa_osm.dart` (`TileProvider` con caché), `pubspec.yaml`, la descarga anticipada del corredor en los viajes largos (un servicio en `lib/src/mapa/` usado por la agenda o la guía), `INTEGRACION.md` (dependencias), tests (el provider con caché usado; el cálculo del corredor y su límite; se cancela al cambiar de viaje).

Commit: `feat: el mapa del chofer se ve sin señal y los viajes largos descargan el recorrido`

### Task 3: App — acciones del viaje sin señal
Files: `lib/src/chofer/cola_acciones.dart` (nuevo), `viaje_actual.dart` (optimista + cola + conflictos), el orden de envío con la cola de GPS (emisor y rastreador), el indicador en `viaje_chofer`/`mapa_chofer`, `turno.dart` (bloqueo de "Finalizar turno"), `ApiVehiculos.avanzarViaje` (`momento`, `id_accion`), tests (sin red → avanza local y encola; reconecta → envía en orden y después el GPS; conflicto → descarta y avisa; reintento idempotente; persistencia al reiniciar la app; finalizar turno bloqueado).

Commit: `feat: el chofer puede avanzar el viaje sin señal y se envía al reconectar`
