# Etapa 2: viajes largos (interior y otras provincias) y rotación — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Los viajes al interior de la provincia o a otra provincia los **carga el encargado desde el panel**.
- Pueden durar muchas horas y pasar el horario laboral.
- El panel **sugiere al chofer al que le toca** por rotación: el que hace más tiempo no hace un viaje largo. El encargado confirma o elige otro, y queda registrado.
- Los mapas propios tienen que cubrir más que Catamarca.

**Architecture:**
- Backend Laravel + Filament 5.9.
- `TipoViaje::{Inmediato, Reserva}`.
- Las reservas usan `programado_para` + `duracion_estimada_min`.
- `App\Servicios\DisponibilidadReservas`:
  - `estaDisponible(choferId, inicio, duracionMin, excluir?, bloquear)` revisa los solapamientos con un colchón, usando el parámetro `duracion_reserva_por_defecto_min`;
  - `choferesDisponibles(inicio, duracion)`.
- `ServicioReservas::crear`, `Asignador::asignarReserva` y la agenda del chofer: la app muestra las reservas aceptadas y el chofer arranca con "Voy en camino" (`salirHaciaReserva`).
- Panel:
  - `ViajeResource` con su `CreateViaje`, que ya crea inmediatos y reservas para otro solicitante usando los servicios, con el buscador de lugares y coordenadas manuales;
  - `ServicioViaje::asignarPorAdmin`.
- Turno por asistencia: una salida con viaje activo deja el cierre pendiente (`turnos.cierre_pendiente_en`).
- `viajes.metros_recorridos` se guarda al finalizar.
- Infra de mapas propia: `infra/mapas` (`preparar-datos.sh` recorta Catamarca por bbox, más OSRM, Nominatim y Planetiler) y la guía `docs/PRODUCCION-MAPAS.md`.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`, puntos 5.4 (reservas), 8 (panel) y 10 (privacidad).

## Decisiones

1. **Tipo nuevo `TipoViaje::Largo`** (etiqueta "Viaje largo"). Un viaje largo es como una reserva asignada a mano:
   - `programado_para` es la salida;
   - `duracion_estimada_min` se calcula como regreso estimado menos salida;
   - columna nueva `regreso_estimado` (datetime, nullable, solo para viajes largos);
   - `pasajeros` (texto opcional: otras personas además del solicitante);
   - se reutilizan `motivo`, `chofer_id` y `vehiculo_id`.

   No se ofrece a nadie: se **asigna directo**, con estado `aceptado`, al chofer y vehículo elegidos. El chofer lo ve en su **Agenda** como "Viaje largo" (destino, salida, regreso, pasajeros) y lo arranca con "Voy en camino", igual que una reserva, con el mismo flujo de estados.
2. **Disponibilidad:**
   - **Chofer:** un viaje largo bloquea su agenda durante toda la franja. `estaDisponible` considera los viajes largos aceptados como las reservas, con su duración real. Mientras dura, el chofer no recibe ofertas inmediatas ni reservas.
   - **Vehículo:** no puede estar en dos viajes largos que se solapen. Se valida al crear y al reasignar, con los mismos locks que las reservas.
3. **Panel — crear un viaje largo:** en "Nuevo viaje", el tipo suma "Viaje largo", con estos campos:
   - solicitante;
   - origen y destino: el destino se busca con el buscador de lugares, que con los mapas ampliados encuentra otras provincias, o se cargan las coordenadas a mano;
   - salida y regreso estimado (posterior a la salida, como mucho 7 días);
   - pasajeros y motivo;
   - **chofer**: se muestra el sugerido por rotación;
   - **vehículo**: se muestra el habitual del chofer o cualquiera libre en la franja.

   Las validaciones dan errores en español sin crear nada.
4. **Rotación:**
   - **Cálculo:** `App\Servicios\RotacionViajesLargos::ordenados(salida, regreso)` ordena los choferes **activos y disponibles** en la franja por la fecha de su último viaje largo **finalizado**, del más antiguo al más reciente. Los que nunca hicieron uno van primero, desempatados por nombre.
   - **En el formulario:** el primero aparece preseleccionado y cada opción del select muestra "Último viaje largo: 12/09 (Tinogasta)" o "Nunca".
   - **Página "Rotación de viajes largos":** lista cada chofer con su último viaje largo (fecha y destino), la cantidad en los últimos 90 días y el próximo programado, ordenada por a quién le toca. Es solo lectura, con enlace al viaje.
5. **Fuera de horario:** si el chofer ficha la salida con el viaje largo en curso, el turno queda con cierre pendiente (ya existe) y se cierra al finalizar.
   - En el detalle del viaje del panel se muestran la duración real y el aviso **"Fuera del horario laboral"** si el viaje terminó después de las 18:00 o empezó antes de las 7:00. Los dos horarios salen de los parámetros nuevos `horario_laboral_inicio` y `horario_laboral_fin`.
   - En Reportes, por chofer, se suma la columna "Horas en viajes largos".
6. **App:**
   - Agenda del chofer y detalle: el tipo largo se muestra con su etiqueta, regreso y pasajeros, sin opción de rechazar porque lo asignó el encargado. El chofer lo arranca desde la Agenda.
   - Solicitante: lo ve en "Mis viajes" en las próximas, como una reserva confirmada (chofer y vehículo) y sin poder cancelarlo. Los cambios los hace el encargado.
7. **Mapas fuera de Catamarca** (infra):
   - `preparar-datos.sh` acepta `REGION=catamarca|argentina`; por defecto queda `catamarca`. Con `argentina` usa el PBF completo sin recorte para OSRM, las teselas (Planetiler con los límites del país) y Nominatim.
   - La guía documenta recursos, tiempos y la recomendación para producción (`argentina` si hay viajes a otras provincias) y que el cambio de región no requiere tocar el backend.
   - Verificación real: preparar `argentina` en la compu de desarrollo **al menos OSRM y teselas** y probar un recorrido Catamarca → Córdoba y una tesela de Córdoba. Nominatim de Argentina puede tardar mucho; si no se completa, se documenta como pendiente.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend:
  - Pest en verde y Pint limpio en los archivos tocados;
  - las migraciones funcionan en SQLite y MariaDB;
  - los locks de reservas se reutilizan;
  - la suite `tests/Concurrencia` en MariaDB debe seguir verde; la corre el controlador.
- Paquete:
  - `flutter analyze` y `dart format --output=none --set-exit-if-changed lib test` limpios;
  - todos los tests en verde;
  - `host_prueba` pasa `flutter test`/`flutter analyze`;
  - un JSON viejo, sin `regreso_estimado` y sin el tipo `largo`, se tolera.
- Git: los agentes pueden trabajar en paralelo. Nunca `git add -A`; los archivos nuevos se agregan con su ruta explícita y se commitea con pathspec.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — viaje largo, disponibilidad y rotación
Files:
- `TipoViaje::Largo`;
- la migración (`regreso_estimado`, `pasajeros`, parámetros de horario laboral);
- `ServicioViajesLargos` (crear y reasignar con validaciones y locks) y `RotacionViajesLargos`;
- `DisponibilidadReservas` (viajes largos y vehículo);
- `ViajeResource` (JSON: `regreso_estimado`, `pasajeros`);
- `/api/viajes` y la agenda, para que incluyan los largos;
- tests, incluidos los solapamientos de chofer y vehículo y la exclusión de ofertas durante un viaje largo.

Commit: `feat: viajes largos asignados por el encargado con rotación de choferes`

### Task 2: Panel — crear viajes largos, página de rotación y reportes
Files: `ViajeResource` + `CreateViaje` (tipo largo con sugerencia), la página `RotacionViajesLargos`, el detalle con el aviso de fuera de horario, `ReportesPanel` (horas en viajes largos), tests.

Commit: `feat: el panel crea viajes largos con el chofer al que le toca y muestra la rotación`

### Task 3: App — viajes largos en la agenda y en "Mis viajes"
Files: el modelo (`TipoViaje.largo`, `regresoEstimado`, `pasajeros`), la agenda y el detalle del chofer, "Mis viajes" del solicitante, tests.

Commit: `feat: la app muestra los viajes largos en la agenda y en mis viajes`

### Task 4: Mapas de todo el país
Files: `infra/mapas/preparar-datos.sh` (REGION), `docker-compose.yml` si hace falta, `docs/PRODUCCION-MAPAS.md`, `infra/mapas/README.md`. Incluye la verificación real que indica la Decisión 7.

Commit: `feat: el servidor de mapas puede cubrir todo el país`
