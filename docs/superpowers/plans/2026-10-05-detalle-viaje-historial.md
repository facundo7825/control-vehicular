# Detalle de un viaje del historial del solicitante — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** En "Mis viajes", el historial muestra los últimos 50 viajes terminados, cancelados o sin chofer, pero no tienen detalle. Al tocar uno se abre su **detalle**:
- chofer y vehículo;
- horarios (pedido, chofer asignado, llegada, inicio y fin), duración y km;
- el **recorrido real** dibujado en el mapa;
- si se canceló, quién lo canceló y el motivo.

**Architecture:**
- Backend Laravel (`backend/`):
  - `GET /api/viajes/{viaje}` (`ViajeController::show`) ya autoriza al solicitante, al chofer y al admin, y devuelve `ViajeResource`: id, tipo, modo, estado, origen y destino, motivo, `programado_para`, chofer, vehículo, solicitante, `aceptado_en`, `llego_en`, `iniciado_en`, `finalizado_en` y `cancelado_en`.
  - `GET /api/viajes` devuelve `{proximas, historial}`.
  - El recorrido real está en `recorrido_viaje` (`PuntoRecorrido`: viaje_id, lat, lng, `registrado_en`), se guarda solo mientras el viaje está en curso y se purga a los 90 días (`retencion_recorrido_dias`).
  - `viajes.metros_recorridos` se calcula al finalizar.
  - `viajes.cancelado_por` vale `solicitante` o `admin`, junto con `motivo_cancelacion`.
- Paquete Flutter (`paquete/vehiculos_oficiales/`):
  - pantalla `lib/src/ui/solicitante/mis_viajes.dart`, donde `_Pasado` es un `ListTile` sin acción;
  - modelo `Viaje` en `lib/src/modelos/viaje.dart`;
  - mapa: `DatosMapa`, `MarcadorMapa` (`TipoMarcador.origen` es un punto y `destino` un pin), `LineaMapa.recorrido` y `Enfoque.entre`;
  - formatos en `lib/src/comunes/formato.dart` (`formatearDistancia`, `formatearDuracion`) y `formatearFechaHora` en `ui/comunes`;
  - rutas internas con go_router en `Rutas`.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`, punto 7 (solicitante) y punto 10 (privacidad: el recorrido se conserva 90 días).

## Decisiones

1. **`ViajeResource` suma campos, sin quitar ninguno:**
   - `pedido_en` (`created_at`);
   - `cancelado_por` (`solicitante` | `admin` | null);
   - `motivo_cancelacion`;
   - `metros_recorridos` (entero o null).

   Una app vieja los ignora.
2. **`GET /api/viajes/{viaje}/recorrido`:**
   - **Autorización:** la misma que `show`: solicitante, chofer o admin; otros reciben 403.
   - **Respuesta:** `{"puntos": [[lat,lng],…], "disponible": bool}`.
     - Los puntos van ordenados por `registrado_en`.
     - Si hay más de 500, se reducen tomando uno cada N, conservando siempre el primero y el último.
     - `disponible: false` cuando el viaje terminó hace más de `retencion_recorrido_dias` días o no tiene puntos.
   - **Viaje todavía activo:** devuelve sus puntos hasta ahora, sin cambiar las reglas de la pantalla del viaje en curso.
   - Usa consultas acotadas, solo `lat` y `lng`.
3. **Pantalla "Detalle del viaje"** (app, solicitante): se abre al tocar un viaje del historial.
   - **Encabezado:** estado y fecha.
   - **Mapa:**
     - el recorrido real (`LineaMapa.recorrido`), un punto en el origen y un pin en el destino, con el encuadre sobre todo lo que se dibuja;
     - sin recorrido disponible, solo origen y destino, con la nota "El recorrido ya no está disponible (se conserva 90 días)" o "Este viaje no tiene recorrido registrado".
   - **Datos:** chofer (nombre) y vehículo (patente, marca, modelo, color).
   - **Horarios**, con los que existan: pedido, chofer asignado, llegó, inicio y fin.
   - **Duración** de inicio a fin y **km** de `metros_recorridos`, formateados con los helpers existentes.
   - **Cancelado:** "Lo cancelaste vos" o "Lo canceló la administración", más el motivo si hay.
   - **Sin chofer:** "No hubo choferes disponibles".
   - **Carga:** pide `GET /viajes/{id}` para tener los datos frescos y `GET /viajes/{id}/recorrido` aparte. Si el recorrido falla, la pantalla se muestra igual, sin línea. Los errores se muestran en español con "Reintentar".
   - Se mantiene el botón "Elegir otro" de las reservas sin chofer.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend: Pest en verde y Pint limpio en los archivos tocados.
- Paquete:
  - `flutter analyze` sin problemas y `dart format --output=none --set-exit-if-changed lib test` sin cambios;
  - todos los tests en verde;
  - `host_prueba` pasa `flutter test`/`flutter analyze`;
  - toda llamada HTTP pasa por `ApiVehiculos`;
  - se usa `ref.mounted` después de cada `await`;
  - solo `mapa_google.dart` importa google_maps_flutter y solo `mapa_osm.dart` importa flutter_map/latlong2.
- Git: los agentes pueden trabajar en paralelo. Nunca `git add -A`; los archivos nuevos se agregan con su ruta explícita y se commitea con pathspec.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — datos del detalle y recorrido real
Files: `app/Http/Resources/ViajeResource.php`, `ViajeController` (`recorrido`) o un controller nuevo, `routes/api.php`, tests (`tests/Feature/DetalleViajeTest.php` o el existente).
Tests:
- campos nuevos en `show`;
- recorrido ordenado;
- reducción a 500 conservando el primero y el último;
- 403 a terceros;
- el chofer y el admin pueden verlo;
- `disponible` false por retención o sin puntos.

Commit: `feat: el detalle del viaje trae km, cancelación y su recorrido real`

### Task 2: App — pantalla de detalle del viaje del historial
Files: `lib/src/modelos/viaje.dart` (campos nuevos, tolerante a su ausencia), `ApiVehiculos.recorridoViaje`, `lib/src/ui/solicitante/detalle_viaje.dart` (nuevo), la ruta en `Rutas`, `mis_viajes.dart` (`onTap`), tests.
Tests:
- tocar un viaje abre el detalle;
- horarios, duración y km con su formato;
- cancelado por el solicitante y por el admin;
- sin chofer;
- con recorrido, una línea en el mapa;
- sin recorrido, la nota;
- un error del recorrido no rompe la pantalla;
- un JSON viejo sin los campos nuevos.

Commit: `feat: detalle de cada viaje del historial con su recorrido`
