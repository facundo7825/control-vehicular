# Turno automático por fichaje de asistencia — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** El usuario quiere que el turno del chofer se abra al fichar la entrada y se cierre al fichar la salida.
- Hoy se ficha en un **reloj aparte** que vuelca los horarios al sistema del PJ.
- A futuro se fichará desde la app móvil.
- Al abrirse solo, el turno usa el **vehículo habitual** del chofer, que se carga en el panel. Si ese día usa otro, lo cambia con un toque.

**Architecture:**
- Backend Laravel (`backend/`):
  - `App\Servicios\ServicioTurnos::{iniciar(chofer, vehiculoId, OrigenTurno), finalizar(chofer)}` toma locks, aplica sus reglas y publica el estado con `AvisoEstadoChofer`. `finalizar` falla si hay un viaje activo y borra `ubicaciones_chofer`.
  - `OrigenTurno::{Manual, Asistencia}` ya existe.
  - `turnos.vehiculo_id` no admite null.
  - Rutas del chofer `/api/turnos*`.
  - Push con `App\Notificaciones\Notificador::enviar(destino, titulo, cuerpo, datos)`; `datos.tipo` hoy vale `oferta | oferta_reserva | viaje | alerta_reserva | recordatorio_reserva`.
  - Alertas en `App\Models\Alerta`, con tipos como constantes y `AlertaResource::TIPOS`.
  - `MaquinaEstadosViaje::guardar()` concentra los cambios de estado de un viaje.
  - No hay middleware de clave entre servidores. Los RateLimiters están en `AppServiceProvider`.
  - Panel:
    - `UsuarioResource`: los campos del PJ quedan deshabilitados; se editan `rol` y `activo`;
    - `TurnosRelationManager`: solo lectura, con la columna `origen`.
- Paquete Flutter (`paquete/vehiculos_oficiales/`):
  - `turnoProvider` (en `lib/src/chofer/turno.dart`) es el único dueño del rastreo. Al construirse pide `turnoActual()` y, si hay turno, arranca el GPS solo.
  - `InicioChofer` muestra `IniciarTurno` (elegir vehículo) o `MapaChofer`.
  - Push en `lib/src/push/push_modulo.dart`: `AvisoPush{tipo, viajeId, ofertaId, estado}`; hoy solo refresca el viaje para `viaje` y `oferta`.
  - `host_prueba` simula los push con `PuenteNotificacionesFalso.simular`.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`:
- tabla de decisiones, fila "Turno": se diseñó intercambiable porque lo reemplazará el control de asistencia;
- punto 7, chofer 1: la pantalla de inicio de turno es la que reemplazará la asistencia;
- `TurnoAsistencia`.

## Decisiones

1. **Eventos de asistencia (entre servidores):** `POST /api/asistencia/eventos`.
   - **Autenticación:** encabezado `X-Clave-Asistencia`, comparado con `hash_equals` contra `config('vehiculos.asistencia.clave')` (env `ASISTENCIA_CLAVE`). Sin clave configurada responde **503** (integración apagada); con una clave incorrecta, **401**. Tiene un RateLimiter propio, `asistencia`, de 120 por minuto por IP.
   - **Cuerpo:** `{"id_evento"?: string, "id_externo": string, "tipo": "entrada"|"salida", "momento"?: ISO-8601}`. `momento` vale ahora por defecto, interpretado en hora local si no trae zona. También acepta un lote, `{"eventos":[…]}`, de hasta 100.
   - **Respuesta:** 200 con un resultado por evento: `abierto | cerrado | cierre_pendiente | ignorado | sin_vehiculo` y un `motivo` legible en español. La validación devuelve 422. **Nunca 500 por datos del PJ.**
   - **Registro en la tabla `eventos_asistencia`:** id, `id_evento` (único y nullable), `usuario_id` (nullable), `id_externo`, `tipo`, `momento`, `resultado`, `motivo`, timestamps.
     - **Idempotencia:** un `id_evento` repetido devuelve el resultado guardado y no hace nada.
     - **Orden:** un evento con `momento` anterior al último evento procesado de esa persona se ignora con el motivo "evento fuera de orden".
2. **Entrada (`App\Servicios\ServicioAsistencia`):**
   - Usuario inexistente, inactivo o que no es chofer → `ignorado`.
   - Ya tiene turno abierto → `ignorado`.
   - Tiene vehículo habitual activo y libre → `ServicioTurnos::iniciar(..., OrigenTurno::Asistencia)` → `abierto`. Además le llega un push `tipo: "turno"`, `estado: "abierto"`: "Tu turno empezó" / "Abrí la app para compartir tu ubicación".
   - Si no tiene vehículo habitual, o está inactivo o en uso → `sin_vehiculo`. Se crea una alerta nueva, `Alerta::ASISTENCIA_SIN_VEHICULO`, con el mensaje "Carlos Chofer fichó la entrada pero no tiene vehículo habitual disponible", y le llega un push "Fichaste la entrada" / "Abrí la app y elegí el vehículo para empezar el turno".
   - El alta del turno usa los locks de `iniciar`, así que no hay carreras con un inicio manual simultáneo.
3. **Salida:**
   - Sin turno abierto → `ignorado`.
   - Con un viaje activo → el turno queda con **cierre pendiente** (columna nueva `turnos.cierre_pendiente_en`) → `cierre_pendiente`. Cuando ese viaje pasa a `finalizado` o `cancelado`, dentro de `MaquinaEstadosViaje::guardar()` o en un listener después del commit, el turno se cierra solo y le llega un push "Tu turno terminó".
   - Si no tiene viaje activo → `ServicioTurnos::finalizar` → `cerrado`, con el push "Tu turno terminó".
   - Se cierra cualquier turno abierto, también los manuales.
   - Una entrada posterior anula un cierre pendiente.
4. **Vehículo habitual:**
   - Columna `usuarios.vehiculo_habitual_id`, nullable, FK con `nullOnDelete`.
   - En el panel, en Usuarios, se agrega un select de vehículos activos, visible si el rol es chofer.
   - **Cambiar el vehículo** del turno abierto: `POST /api/turnos/actual/vehiculo {vehiculo_id}`, solo para el chofer. Se permite sin viaje activo, si el vehículo está activo y libre, con los mismos locks que `iniciar`; los errores son en español.
5. **Panel:**
   - `TurnosRelationManager` muestra el origen con su etiqueta ("Manual" / "Asistencia") y el cierre pendiente.
   - Lista de **eventos de asistencia** (un recurso de solo lectura, o una pestaña en Alertas; lo más simple que sea navegable): fecha, persona, tipo, resultado y motivo, con filtros por resultado y fecha.
   - Acción **"Simular fichaje"** (entrada o salida) en la vista o fila de un chofer, para la demo. Usa `ServicioAsistencia` con el momento actual y avisa el resultado con una notificación.
6. **App del chofer:**
   - El push `tipo: "turno"` (abierto o cerrado) invalida `turnoProvider`. Al abrirse el turno, el GPS arranca solo con el flujo actual; al cerrarse, se corta.
   - Mientras está en la pantalla `IniciarTurno` (sin turno), sondea `turnoActual()` **cada 30 s**, con un timer en un notifier, para enterarse de un turno abierto por fichaje aunque no llegue el push.
   - En `MapaChofer`, la patente se puede tocar para **"Cambiar vehículo"**: abre una hoja con los vehículos disponibles y llama al endpoint. Si hay un viaje activo, queda deshabilitado con el motivo.
   - Con la app en segundo plano, el push de turno muestra una notificación local con el mecanismo de avisos existente.
7. **A futuro (no se implementa):** fichar desde el celular sería una función pública del módulo que llame a un endpoint de fichaje del chofer. Queda documentado en `INTEGRACION.md` como extensión prevista.
8. **Documentación:**
   - `.env.example` con `ASISTENCIA_CLAVE`.
   - En `docs/`, `ASISTENCIA.md` con el contrato del endpoint para el área de sistemas del PJ: ejemplos con curl, resultados e idempotencia.
   - El documento compartido del PJ se actualiza aparte; lo hace el controlador.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend: Pest en verde y Pint limpio en los archivos tocados.
  - Las migraciones funcionan en SQLite y MariaDB.
  - Concurrencia: los locks de `ServicioTurnos`.
  - Si se toca `MaquinaEstadosViaje`/`ServicioTurnos`, la suite `tests/Concurrencia` en MariaDB debe seguir verde; la corre el controlador.
- Paquete:
  - `flutter analyze` sin problemas y `dart format --output=none --set-exit-if-changed lib test` sin cambios;
  - todos los tests en verde;
  - `host_prueba` pasa `flutter test`/`flutter analyze`;
  - toda llamada HTTP pasa por `ApiVehiculos`;
  - los timers viven en notifiers y se usa `ref.mounted` después de cada `await`.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — eventos de asistencia, entrada y salida
Files:
- migración: `usuarios.vehiculo_habitual_id`, `turnos.cierre_pendiente_en` y la tabla `eventos_asistencia`;
- modelos: `EventoAsistencia` y la relación `vehiculoHabitual`;
- `app/Servicios/ServicioAsistencia.php`, el middleware de clave, `AsistenciaController`, la ruta y el limiter;
- `Alerta::ASISTENCIA_SIN_VEHICULO` + `AlertaResource::TIPOS`;
- el cierre pendiente al terminar un viaje;
- config + `.env.example`;
- tests.

Tests:
- autenticación (503, 401, 200);
- validación;
- entrada → abierto, con origen asistencia y el push;
- `sin_vehiculo`, con la alerta y el push;
- entrada repetida o con turno abierto → ignorado;
- salida → cerrado, con el push;
- salida con viaje → `cierre_pendiente` → finalizar el viaje cierra el turno;
- `id_evento` repetido → mismo resultado sin efectos;
- fuera de orden → ignorado;
- lote;
- usuario desconocido, inactivo o solicitante.

Commit: `feat: el turno se abre y se cierra con los fichajes de asistencia`

### Task 2: Backend — cambiar el vehículo del turno y panel
Files:
- la ruta y el método `POST /api/turnos/actual/vehiculo`, en `ServicioTurnos` y `TurnoController`;
- `UsuarioResource` (vehículo habitual);
- `TurnosRelationManager` (origen y cierre pendiente);
- el recurso o lista de eventos de asistencia;
- la acción "Simular fichaje";
- tests.

Commit: `feat: vehículo habitual, cambio de vehículo y fichajes en el panel`

### Task 3: App — el turno sigue al fichaje y el chofer cambia de vehículo
Files:
- `lib/src/push/push_modulo.dart` (tipo `turno`);
- `lib/src/chofer/turno.dart` (invalidación, sondeo cada 30 s sin turno y `cambiarVehiculo`);
- `ApiVehiculos.cambiarVehiculo`;
- `lib/src/ui/chofer/{iniciar_turno,mapa_chofer}.dart`;
- los avisos (notificación local del push de turno);
- `host_prueba` (botón para simular el push de turno, si es útil);
- `INTEGRACION.md` (fichaje desde el celular a futuro);
- tests.

Commit: `feat: la app del chofer sigue el turno abierto por fichaje y permite cambiar de vehículo`

### Task 4: Documentación del contrato de asistencia
Files: `docs/ASISTENCIA.md`, `docs/DEMO.md` (paso "Simular fichaje"), `.env.example` si falta algo.
Commit: `docs: contrato de eventos de asistencia para el Poder Judicial`
