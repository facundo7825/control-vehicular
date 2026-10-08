# Etapa 1: chofer asignado a una persona y choferes por dependencia — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** En el Poder Judicial hay choferes asignados a **ciertas personas** (por ejemplo, el chofer de un juez) y choferes que atienden **ciertas dependencias** (fueros u oficinas). Los datos los carga el **encargado desde el panel**. Al pedir un viaje, el orden de ofrecimiento pasa a ser:
1. el **chofer asignado** a esa persona;
2. si no está libre o no acepta, los choferes de **su dependencia**, del más cercano al más lejano;
3. después, el resto, por cercanía, como hasta ahora.

**Architecture:**
- Backend Laravel + Filament 5.9.
- Despacho:
  - `App\Servicios\Despachador::despachar()` recorre `Asignador::ordenarPorCercania($viaje, $this->candidatos($viaje))`. Si es obligatorio asigna con `Asignador::asignar`; si no, ofrece con `ofrecer`, que crea `OfertaViaje` con vencimiento y la tabla `ofertas_viaje` ya tiene la columna `motivo`.
  - `seguirBuscando()` vuelve a despachar los inmediatos que no son de modo específico.
  - Los candidatos salen de `CalculadorEstadoChofer::libres()`, sin los ya ofrecidos ni los que tienen una oferta inmediata pendiente.
  - `Asignador::ordenarPorCercania` usa `ServicioMapas::duracionesHacia`, con desempate por distancia recta.
  - Reservas: `ServicioReservas::{disponibles, crear, candidatos(modo, choferId, inicio, duracion)}` con los modos `especifico` y `cualquiera_disponible`, y `Despachador::ofrecerReserva`.
- Identidad: `App\Identidad\EndpointPoderJudicial` lee los campos configurables `IDENTIDAD_CAMPO_*` y `AuthController::intercambio` hace `updateOrCreate` del usuario.
- Panel:
  - `UsuarioResource`: los campos del PJ quedan deshabilitados; se editan `rol`, `activo` y el vehículo habitual si es chofer;
  - `ViajeResource` + `OfertasRelationManager`.
- Concurrencia: la suite `tests/Concurrencia` en MariaDB la corre el controlador.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`, puntos 5 (asignación) y 8 (panel).

## Decisiones

1. **Datos:**
   - Tabla `dependencias`: id, `nombre` único, `activa` (bool), timestamps.
   - `usuarios.dependencia_id`: nullable, FK con `nullOnDelete`; es la dependencia del solicitante.
   - Tabla pivote `chofer_dependencia` (`chofer_id`, `dependencia_id`, única): las dependencias que atiende cada chofer, que pueden ser varias.
   - `usuarios.chofer_asignado_id`: nullable, FK a `usuarios` con `nullOnDelete`; es el chofer asignado a esa persona. Debe tener rol chofer: se valida en el panel y se ignora al despachar si dejó de ser chofer o está inactivo.
2. **Desde el PJ (opcional):**
   - Si se configura `IDENTIDAD_CAMPO_DEPENDENCIA` (ruta `data_get`, como los otros campos), al iniciar sesión se busca la dependencia por nombre, sin distinguir mayúsculas ni espacios de más, o se crea, y se fija en el usuario.
   - En ese caso, en el panel el campo "Dependencia" del usuario queda deshabilitado con la ayuda "Viene del sistema del PJ". Sin esa configuración, lo edita el encargado.
3. **Panel:**
   - Recurso **Dependencias** (CRUD): nombre, activa y un multi-select "Choferes que la atienden" (usuarios activos con rol chofer). La lista muestra la cantidad de choferes y de personas.
   - **Usuarios:**
     - solicitantes: "Dependencia" (select de dependencias activas) y "Chofer asignado" (select de choferes activos, opcional);
     - choferes: "Dependencias que atiende" (multi-select);
     - columnas opcionales en la tabla: dependencia y chofer asignado.
   - **Ofertas del viaje:** el `OfertasRelationManager` muestra el **criterio** con que se ofreció: "Chofer asignado", "Su dependencia" o "Cercanía". Se guarda en `ofertas_viaje.motivo` o en una columna nueva `criterio`, lo que encaje mejor con el uso actual de `motivo` (leerlo antes).
4. **Despacho (inmediatos, incluidos los obligatorios):** `Asignador::ordenar($viaje, $candidatos)` reemplaza a `ordenarPorCercania` en `despachar()` y arma tres grupos, cada uno ordenado por cercanía con la misma lógica de hoy, que no se duplica:
   1. el chofer asignado del solicitante, si está entre los candidatos;
   2. los choferes que atienden la dependencia del solicitante, si tiene una y está activa;
   3. el resto.

   Se conserva el resto de las reglas: libres, sin oferta pendiente, reofrecimiento con `seguirBuscando`, y obligatorio que asigna directo al primero que se pueda. El modo `especifico` ("Pedir a este chofer") no cambia. Cada oferta registra su criterio.
5. **Reservas `cualquiera_disponible`:** los candidatos de `ServicioReservas` siguen el mismo orden de grupos. Dentro de cada grupo se mantiene el orden actual, es decir lo que hoy decide entre disponibles. La primera oferta va al primer disponible del grupo más prioritario.
6. **App del solicitante:** sin cambios obligatorios en esta etapa.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend:
  - Pest en verde y Pint limpio en los archivos tocados;
  - las migraciones funcionan en SQLite y MariaDB;
  - sin N+1: se precargan dependencias y chofer asignado;
  - el orden de locks no cambia.
- La suite `tests/Concurrencia` debe seguir verde; la corre el controlador.
- Git: nunca `git add -A`; los archivos nuevos se agregan con su ruta explícita y se commitea con pathspec `-- backend/`.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Dependencias, chofer asignado y panel
Files: la migración, el modelo `Dependencia` y las relaciones en `Usuario`, `EndpointPoderJudicial`/`DatosIdentidad`/`AuthController` (dependencia opcional), config + `.env.example`, `DependenciaResource` (+ páginas), `UsuarioResource`, tests.

Commit: `feat: dependencias y chofer asignado cargados desde el panel`

### Task 2: El despacho respeta chofer asignado y dependencia
Files: `Asignador` (`ordenar`), `Despachador` (usarlo y registrar el criterio), `ServicioReservas` (candidatos), `OfertasRelationManager` (criterio), tests (unitarios del orden y del flujo completo: asignado libre, asignado ocupado → dependencia → resto, obligatorio, reserva, asignado inactivo).

Commit: `feat: los viajes se ofrecen primero al chofer asignado y a los de la dependencia`
