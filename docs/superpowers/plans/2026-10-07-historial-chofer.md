# Historial de viajes del chofer con resumen del día — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** El chofer ve en su app **"Mis viajes"**:
- arriba, un **resumen del día**: viajes finalizados hoy, km recorridos hoy y "En turno desde HH:MM" si tiene un turno abierto;
- debajo, la lista de sus viajes pasados (finalizados y cancelados), los más recientes primero.

Al tocar un viaje se abre el **mismo detalle** que ve el solicitante (recorrido real, horarios, duración y km), con los textos adaptados al chofer.

**Architecture:**
- Backend Laravel:
  - `GET /api/viajes/{id}` y `GET /api/viajes/{id}/recorrido` ya autorizan al chofer del viaje.
  - `viajes.metros_recorridos` se guarda al finalizar.
  - Las rutas del chofer están en el grupo `rol:chofer`: `/turnos*` y `/vehiculos/disponibles`.
  - Hora local: `config('vehiculos.zona_horaria')` y `App\Support\HoraLocal`.
  - `ViajeResource` incluye solicitante, chofer, vehículo, horarios, `pedido_en`, `cancelado_por`, `motivo_cancelacion` y `metros_recorridos`.
- Paquete Flutter:
  - pantalla de detalle `lib/src/ui/solicitante/detalle_viaje.dart`, con los providers `detalleViajeProvider` y `recorridoRealProvider` en `lib/src/solicitante/mis_viajes.dart` y la ruta `/solicitante/mis-viajes/viaje/:id`;
  - del chofer: `lib/src/ui/chofer/{inicio_chofer,mapa_chofer,...}.dart` y la Agenda (buscar cómo se llega a ella desde `MapaChofer` o `InicioChofer`);
  - formatos en `lib/src/comunes/formato.dart`.

## Decisiones

1. **`GET /api/chofer/viajes`** (grupo `rol:chofer`) devuelve `{"hoy": {"viajes": int, "metros": int, "en_turno_desde": ISO|null}, "viajes": [ViajeResource…]}`.
   - **`hoy`:**
     - cuenta los viajes del chofer con `estado = finalizado` y `finalizado_en` dentro del **día local** de hoy (límites del día local pasados a UTC);
     - `metros` es la suma de `metros_recorridos`, con null como 0;
     - `en_turno_desde` es el `inicio` del turno abierto, o null.
   - **`viajes`:** los del chofer (`chofer_id` = el usuario) en `finalizado` o `cancelado`, ordenados por `coalesce(finalizado_en, cancelado_en)` de más reciente a más antiguo y limitados a 50.
   - Las consultas son acotadas y sin N+1, con eager load de solicitante y vehículo.
2. **App del chofer:**
   - Pantalla **"Mis viajes"**, accesible desde el mismo lugar donde está la Agenda (un botón o ítem "Mis viajes" junto a ella), con y sin turno abierto.
   - **Encabezado:** una tarjeta con "Hoy: N viajes · X km", usando `formatearDistancia`, y "En turno desde HH:MM" si corresponde.
   - **Lista:** fecha (`finalizado_en` o `cancelado_en`), destino, nombre del solicitante y estado.
   - **Estados vacíos y de error** en español, con "Reintentar".
   - Se puede refrescar tirando hacia abajo.
   - Al tocar un viaje se abre el **detalle reutilizado** con una ruta propia para el chofer (por ejemplo `/chofer/mis-viajes/viaje/:id`, con `int.tryParse`). El detalle recibe quién lo mira (solicitante o chofer):
     - para el chofer muestra **"Solicitante"** con su nombre en lugar del bloque "Chofer";
     - los textos de cancelación pasan a "Lo canceló el solicitante" / "Lo canceló la administración".
   - El detalle del solicitante queda igual.
   - Los providers del detalle se comparten; si hace falta, se mueven a un lugar común como `lib/src/viaje/`.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend: Pest en verde y Pint limpio en los archivos tocados.
- Paquete:
  - `flutter analyze` y `dart format --output=none --set-exit-if-changed lib test` limpios;
  - todos los tests en verde;
  - `host_prueba` pasa `flutter test`/`flutter analyze`;
  - toda llamada HTTP pasa por `ApiVehiculos`;
  - se usa `ref.mounted` después de cada `await`.
- Git: dos agentes en paralelo. Nunca `git add -A`; los archivos nuevos se agregan con su ruta explícita y se commitea con pathspec (`-- backend/` o `-- paquete/ host_prueba/`).
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — viajes del chofer y resumen del día
Files: el controlador (método nuevo, por ejemplo `ChoferViajesController` o en `TurnoController`), `routes/api.php`, tests (`tests/Feature/HistorialChoferTest.php`).
Tests:
- solo viajes del chofer;
- solo finalizados y cancelados;
- orden y límite;
- `hoy` con el borde del día local (un viaje a las 02:00 UTC cuenta como el día anterior);
- metros null cuentan como 0;
- `en_turno_desde` con y sin turno;
- 403 para un solicitante.

Commit: `feat: viajes del chofer con resumen del día`

### Task 2: App — "Mis viajes" del chofer con el detalle reutilizado
Files: `ApiVehiculos.viajesChofer` + modelo; la pantalla `lib/src/ui/chofer/mis_viajes_chofer.dart` (nueva); la ruta; el acceso junto a la Agenda; `detalle_viaje.dart` parametrizado por quién mira; tests.
Tests:
- el resumen y la lista se muestran;
- tocar un viaje abre el detalle con "Solicitante" y los textos del chofer;
- el detalle del solicitante no cambió;
- estados vacío y de error;
- id inválido.

Commit: `feat: el chofer ve sus viajes y el resumen del día`
