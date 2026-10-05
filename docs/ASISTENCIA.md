# Integración con el control de asistencia del Poder Judicial

Contrato para que el sistema de asistencia (fichadas) del PJ avise a **Vehículos Oficiales** cuando un chofer ficha la **entrada** o la **salida**. La entrada abre su turno con el vehículo habitual y la salida lo cierra, sin que el chofer tenga que hacerlo a mano en la app.

Es una comunicación **entre servidores**: no hay usuario ni sesión, solo una clave compartida.

## Resumen

| | |
|---|---|
| Endpoint | `POST /api/asistencia/eventos` |
| Autenticación | Encabezado `X-Clave-Asistencia` con la clave compartida |
| Formato | JSON (`Content-Type: application/json`, `Accept: application/json`) |
| Un fichaje o un lote | Cuerpo con un evento, o `{"eventos": [...]}` de 1 a 100 |
| Límite | 120 pedidos por minuto por IP de origen |

## Autenticación y clave compartida

Cada pedido debe llevar el encabezado `X-Clave-Asistencia` con la clave configurada en el servidor de Vehículos Oficiales (variable `ASISTENCIA_CLAVE` del archivo `.env` del backend).

- **Generar una clave fuerte** (larga y aleatoria, 64 caracteres hexadecimales):
  ```bash
  openssl rand -hex 32
  ```
- **Configurarla** en el `.env` del backend: `ASISTENCIA_CLAVE=<la clave>` y reiniciar/recargar el backend (`php artisan config:clear` si la configuración está cacheada). La clave se entrega al equipo de asistencia por un canal seguro; no se versiona ni se manda por mail o chat sin cifrar.
- **Integración apagada:** con `ASISTENCIA_CLAVE` vacía o ausente el endpoint responde `503`. Es el estado por defecto.
- **Rotación:** (1) generar una clave nueva; (2) cambiarla en el `.env` del backend y recargarlo; (3) cargarla en el sistema de asistencia. Hay una sola clave activa a la vez: entre los pasos 2 y 3 los pedidos reciben `401`, así que conviene hacerlo en una ventana corta y reenviar lo rechazado (los reintentos son seguros, ver más abajo). Rotarla también si se sospecha que se filtró.
- Usar siempre HTTPS entre los servidores.

## Qué manda el sistema de asistencia

### Evento

| Campo | Tipo | Obligatorio | Significado |
|---|---|---|---|
| `id_externo` | texto (máx. 255) | sí | Identificador de la persona en el sistema del PJ. Es **el mismo** `id_externo` con el que la persona inicia sesión en Vehículos Oficiales. |
| `tipo` | `"entrada"` o `"salida"` | sí | Qué fichó. |
| `momento` | texto de fecha y hora | no | Cuándo fichó (ver abajo). Sin `momento` se toma la hora de recepción. |
| `id_evento` | texto (máx. 100) | no, **recomendado** | Identificador único del fichaje en el sistema de asistencia. Permite reintentar sin duplicar (ver "Idempotencia y reintentos"). |

**Formato de `momento`:** ISO-8601, **con zona horaria** (recomendado), por ejemplo `2026-10-05T07:58:00-03:00` o `2026-10-05T10:58:00Z`. Si viene **sin zona** (`2026-10-05T07:58:00`) se interpreta como hora local de los usuarios: `America/Argentina/Buenos_Aires` por defecto (configurable con `VEHICULOS_ZONA_HORARIA`).

**Ventana aceptada:** el momento no puede estar más de **5 minutos en el futuro** ni tener más de **7 días de antigüedad**; fuera de esa ventana el pedido se rechaza con `422`.

### Un solo fichaje

```bash
curl -X POST https://vehiculos.ejemplo.gob.ar/api/asistencia/eventos \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Clave-Asistencia: $ASISTENCIA_CLAVE" \
  -d '{
    "id_evento": "FICH-2026-10-05-000123",
    "id_externo": "20123456",
    "tipo": "entrada",
    "momento": "2026-10-05T07:58:00-03:00"
  }'
```

Respuesta `200`:

```json
{
  "id_evento": "FICH-2026-10-05-000123",
  "id_externo": "20123456",
  "tipo": "entrada",
  "resultado": "abierto",
  "motivo": "Turno abierto con el vehículo habitual."
}
```

### Lote

Hasta **100** eventos en un pedido (por ejemplo, para ponerse al día tras un corte). El lote se procesa en **orden cronológico** por `momento` (los que no traen momento se toman como "ahora") y la respuesta trae un resultado por evento **en el mismo orden en que se enviaron**.

```bash
curl -X POST https://vehiculos.ejemplo.gob.ar/api/asistencia/eventos \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "X-Clave-Asistencia: $ASISTENCIA_CLAVE" \
  -d '{
    "eventos": [
      {"id_evento": "FICH-000124", "id_externo": "20123456", "tipo": "salida",  "momento": "2026-10-05T16:02:00-03:00"},
      {"id_evento": "FICH-000125", "id_externo": "27999888", "tipo": "entrada", "momento": "2026-10-05T07:55:00-03:00"}
    ]
  }'
```

Respuesta `200`:

```json
{
  "resultados": [
    {
      "id_evento": "FICH-000124",
      "id_externo": "20123456",
      "tipo": "salida",
      "resultado": "cerrado",
      "motivo": "Turno cerrado."
    },
    {
      "id_evento": "FICH-000125",
      "id_externo": "27999888",
      "tipo": "entrada",
      "resultado": "ignorado",
      "motivo": "No hay ningún usuario con ese id_externo."
    }
  ]
}
```

La validación de un lote es **todo o nada**: si un solo evento tiene un campo inválido o un `momento` fuera de la ventana, todo el pedido responde `422` y no se procesa ninguno. Una vez validado, un fallo inesperado en un evento no corta a los demás: ese evento vuelve como `ignorado` con motivo `error interno al procesar el evento` y **no queda registrado**, así que reenviarlo lo vuelve a intentar.

## Resultados

Cada evento responde con `resultado` (para programar contra él) y `motivo` (texto para humanos; no depender de su redacción exacta).

| `resultado` | Cuándo | Qué ve el chofer | Qué ve el panel |
|---|---|---|---|
| `abierto` | Entrada de un chofer sin turno, con vehículo habitual disponible. Se abre el turno con ese vehículo (origen "asistencia"). | Push "Tu turno empezó: abrí la app para compartir tu ubicación". Al abrir la app ya está en turno y solo debe aceptar el permiso de ubicación. | Fichaje "Turno abierto"; el chofer aparece en el mapa en vivo cuando empieza a mandar ubicación. |
| `cerrado` | Salida de un chofer con turno abierto y sin viaje activo. Se cierra el turno (se borra su última ubicación). | Push "Tu turno terminó: se registró tu salida". | Fichaje "Turno cerrado"; sale del mapa. |
| `cierre_pendiente` | Salida de un chofer con un **viaje activo**. El turno sigue abierto hasta que termine el viaje. | Nada nuevo: termina el viaje en curso. Al finalizarlo (o cancelarse) el turno se cierra solo y recibe el push "Tu turno terminó". Mientras tanto no recibe viajes ni reservas nuevos. | Fichaje "Cierre pendiente"; luego el turno figura cerrado. |
| `sin_vehiculo` | Entrada de un chofer sin turno que **no tiene vehículo habitual**, o cuyo vehículo habitual está inactivo o en uso por otro chofer. No se abre el turno. | Push "Fichaste la entrada: abrí la app y elegí el vehículo para empezar el turno". | Alerta "fichó la entrada pero no tiene vehículo habitual disponible" y fichaje "Sin vehículo". La alerta se resuelve sola cuando el chofer inicia turno. |
| `ignorado` | El evento no produjo cambios (ver motivos abajo). **No es un error**: el pedido fue recibido y registrado. | Nada. | Fichaje "Ignorado" con el motivo. |

Motivos habituales de `ignorado`:

- `No hay ningún usuario con ese id_externo.`
- `El usuario está deshabilitado.`
- `El usuario no es chofer.`
- `Ya tenía un turno abierto.` (entrada con turno ya abierto, p. ej. abierto a mano por el chofer)
- `Ya tenía un turno abierto; se anuló el cierre pendiente.` (entrada posterior a una salida con cierre pendiente: el chofer sigue de turno)
- `No tenía un turno abierto.` (salida sin turno)
- `Evento fuera de orden: es anterior al último fichaje procesado.`
- `error interno al procesar el evento` (solo en lotes; no queda registrado)

Todos los fichajes recibidos (incluidos los ignorados) se pueden consultar en el panel, en **Fichajes**.

## Idempotencia y reintentos

- **Mandar siempre `id_evento`**, único por fichaje en el sistema de asistencia. Si llega un `id_evento` ya procesado, **no se vuelve a procesar nada** (ni turno, ni alerta, ni push) y se devuelve el mismo `resultado` y `motivo` de la primera vez.
- Por eso **reintentar es seguro**: ante un timeout, un `5xx`, un `429` o un corte de red, reenviar el mismo evento con el mismo `id_evento`. Reintentar con espera creciente (por ejemplo 1 s, 5 s, 30 s...) y, si sigue fallando, dejarlo en cola y seguir más tarde.
- Un `422` o un `401` **no** se arregla reintentando igual: corregir el dato o la clave. Un `422` no registra nada, así que se puede reenviar corregido.
- Sin `id_evento` no hay protección contra duplicados por reintento (aunque un duplicado suele resultar `ignorado`: entrada con turno abierto, salida sin turno).
- Los eventos de una misma persona se procesan de a uno, aunque lleguen en pedidos simultáneos.

## Orden de los eventos

Para cada persona, un evento con `momento` **anterior al último fichaje ya registrado** de esa persona se ignora (`Evento fuera de orden...`), para que un fichaje atrasado no pise uno más nuevo. Un momento igual al último sí se procesa.

Consecuencias para el sistema de asistencia:

- Mandar los eventos de una persona **en orden cronológico**. Dentro de un lote el orden se corrige solo; entre pedidos distintos, no.
- Si un evento queda pendiente de reenvío, **no mandar los posteriores de esa persona antes** que él: serían aceptados y el viejo, al llegar, se ignoraría.
- Mandar el `momento` real del fichaje (no la hora de envío) cuando se reenvía algo atrasado, respetando la ventana de 7 días.

## Comportamiento con un viaje activo

Si el chofer ficha la salida con un viaje activo, el turno **no se corta**: queda con *cierre pendiente* y el viaje sigue. Cuando el viaje termina o se cancela, el turno se cierra automáticamente. Si el chofer ficha la entrada antes de que eso ocurra, se anula el cierre pendiente (`ignorado`, "se anuló el cierre pendiente") y sigue de turno.

## Vehículo habitual

Para que la entrada abra el turno sola, el chofer debe tener **vehículo habitual**, que se carga en el panel: **Usuarios → (el chofer) → Vehículo habitual**. Si no lo tiene, o ese vehículo está inactivo o en uso por otro chofer, la entrada responde `sin_vehiculo`: no se abre el turno, se crea una alerta en el panel y el chofer recibe un push para que abra la app y elija el vehículo a mano. El chofer también puede cambiar de vehículo desde la app.

## Límite de pedidos

120 pedidos por minuto por IP de origen; al superarlo se responde `429` (con el encabezado `Retry-After`). Preferir lotes a muchos pedidos sueltos.

## Errores

Los errores vienen como JSON con `message` (y `errors` en los `422`).

| HTTP | Cuándo | Qué hacer |
|---|---|---|
| `200` | Pedido procesado; cada evento trae su `resultado` (que puede ser `ignorado`). | Marcar como enviado. |
| `401` | Falta el encabezado `X-Clave-Asistencia` o la clave no coincide (`Clave de asistencia inválida.`). | Revisar la clave; no reintentar igual. |
| `422` | Campo faltante o inválido (`id_externo`, `tipo` distinto de `entrada`/`salida`, `momento` no interpretable), `momento` fuera de la ventana (más de 5 minutos en el futuro o más de 7 días atrás), lote vacío o de más de 100. En un lote se rechaza **todo** el pedido. | Corregir y reenviar. |
| `429` | Más de 120 pedidos por minuto (`Demasiados eventos de asistencia...`). | Esperar (`Retry-After`) y reintentar. |
| `503` | La integración está apagada: `ASISTENCIA_CLAVE` sin configurar (`La integración con asistencia no está habilitada.`). | Avisar al equipo de Vehículos Oficiales. |
| `5xx` | Falla del servidor. | Reintentar con espera creciente. |

Ejemplo de `422`:

```json
{
  "message": "El momento no puede estar más de 5 minutos en el futuro.",
  "errors": {
    "momento": ["El momento no puede estar más de 5 minutos en el futuro."]
  }
}
```

## Lista de prueba (para la puesta en marcha)

1. Sin clave configurada: el pedido responde `503`.
2. Con la clave configurada, sin encabezado o con otra clave: `401`.
3. Con un `id_externo` que no existe: `200` con `ignorado` ("No hay ningún usuario...").
4. Chofer **sin** vehículo habitual, entrada: `sin_vehiculo`; aparece la alerta en el panel y le llega el push.
5. Cargar su vehículo habitual en el panel; entrada: `abierto`; el chofer ve el turno al abrir la app.
6. Repetir exactamente el mismo pedido (mismo `id_evento`): misma respuesta, sin push ni cambios nuevos.
7. Entrada otra vez con otro `id_evento`: `ignorado` ("Ya tenía un turno abierto").
8. Salida sin viaje activo: `cerrado`.
9. Entrada, el chofer toma un viaje, salida: `cierre_pendiente`; al finalizar el viaje el turno se cierra.
10. Evento con `momento` de hace 8 días o de dentro de 10 minutos: `422`. Evento con `momento` anterior al último fichaje de esa persona: `ignorado` (fuera de orden).
11. Lote de 2 o 3 eventos de personas distintas: un resultado por evento, en el orden enviado. Lote de 101: `422`.
12. Verificar los fichajes registrados en el panel, sección **Fichajes**.

Para probar sin el sistema real, desde el panel se puede simular un fichaje (ver [`DEMO.md`](DEMO.md), "Simular fichaje").
