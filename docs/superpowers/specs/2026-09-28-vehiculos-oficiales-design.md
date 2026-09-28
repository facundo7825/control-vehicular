# Vehículos Oficiales — Diseño

**Fecha:** 2026-09-28
**Estado:** Aprobado en conversación, pendiente de revisión del spec escrito

## 1. Objetivo

Módulo de la app móvil del Poder Judicial que conecta a **choferes** de autos oficiales con **solicitantes** de traslados, al estilo Uber:

- Los choferes en turno aparecen en un mapa con su ubicación y estado (libre / en viaje).
- Los solicitantes piden un viaje inmediato de dos maneras: **el chofer libre más cercano** o **un chofer específico**.
- Los solicitantes pueden **reservar viajes a futuro**.
- Según el **cargo** del solicitante, un viaje puede ser **obligatorio**: el chofer no puede rechazarlo ni cancelarlo.

**Criterio de éxito:** un solicitante pide un auto y en segundos un chofer lo recibe o se le asigna. Ambos ven el estado del viaje y la posición del auto en tiempo real.

**Fuera de alcance (v1):** pagos o tarifas, aprobaciones de supervisor, reportes y estadísticas, exportación a Excel.

## 2. Restricciones y decisiones

| Tema | Decisión |
|---|---|
| App principal | Flutter + backend PHP del Poder Judicial. Sin acceso a su repositorio. |
| Integración | **Paquete Flutter embebido** (`vehiculos_oficiales`). Se abre desde la sección "Herramientas" de la app principal. |
| Identidad | La app principal expone un endpoint con los datos de sesión (usuario, cargo). Es el único dato que se toma de ella. |
| Backend propio | **Laravel (PHP)** + **Laravel Reverb** (WebSockets) + **MySQL/MariaDB**, en servidores propios. |
| Tiempo real | Opción 1: el chofer envía GPS por HTTP y el backend lo retransmite por WebSocket. FCM para avisos con la app cerrada. |
| Push | **Firebase Cloud Messaging** (a través de la configuración Firebase de la app principal). |
| Mapas | **Google Maps** (Maps SDK, Places, Directions, Distance Matrix). |
| Rechazo de viajes | Depende del **cargo** del solicitante, que viene en los datos de sesión. Cargos configurables como "obligatorios". |
| Reservas | El chofer se asigna **al momento de reservar** y bloquea esa franja de su agenda. |
| Turno | Por ahora se inicia **manualmente**. Diseñado como pieza intercambiable porque luego lo reemplazará el control de asistencia. |
| Admin | Panel web con **Filament** sobre el mismo Laravel. |

## 3. Arquitectura

```
┌──────────────── App Poder Judicial (Flutter) ────────────────┐
│  Herramientas → "Vehículos oficiales"                        │
│        │  abre pantalla del paquete pasando:                 │
│        │   • token/datos de sesión (usuario, cargo)          │
│        │   • puente FCM (token push + mensajes recibidos)    │
│        ▼                                                     │
│  ┌── paquete: vehiculos_oficiales (Flutter) ──┐              │
│  │  Pantallas solicitante / chofer            │              │
│  └────────────────────────────────────────────┘              │
└──────────────────────┬───────────────────────────────────────┘
                       │ HTTPS (API REST)  +  WebSocket (Reverb)
                       ▼
┌────────── Backend propio: Laravel (PHP) ──────────┐
│  API REST  ·  Reverb (WebSockets)  ·  Colas/Jobs  │
│  Panel admin web (Filament)                       │
│  MySQL/MariaDB                                    │
└──────┬─────────────────────────────┬──────────────┘
       │ valida sesión               │ envía push
       ▼                             ▼
  Endpoint de la app PJ           Firebase FCM
```

### 3.1 Componentes del repositorio

- `backend/`: Laravel. API REST, Reverb, colas, panel Filament.
- `paquete/vehiculos_oficiales/`: paquete Flutter con un único punto de entrada:
  `VehiculosOficiales.abrir(context, sesion: ..., push: ..., onSesionInvalida: ...)`.
- `host_prueba/`: app Flutter que simula a la app del Poder Judicial (login falso con cargos elegibles). Sirve para desarrollar de forma independiente.

### 3.2 Autenticación

1. La app principal le pasa al paquete su token o datos de sesión.
2. El paquete llama a `POST /auth/intercambio` en el backend propio.
3. El backend valida contra el endpoint del Poder Judicial mediante un **ProveedorIdentidad** y obtiene id externo, nombre y cargo.
4. Crea o actualiza el `usuario` local y emite un token **Laravel Sanctum**.
5. Todas las llamadas posteriores (API y canales privados de Reverb) usan el token Sanctum.

### 3.3 Piezas intercambiables (interfaces)

- `ProveedorIdentidad`: `EndpointPoderJudicial` (real) / `IdentidadSimulada` (desarrollo).
- `FuenteTurno`: `TurnoManual` (v1) / `TurnoAsistencia` (futuro).
- `Notificador`: `NotificadorFcm` / doble de prueba.
- `ServicioMapas`: `GoogleMaps` (distancias, duraciones, geocodificación) / doble de prueba.

## 4. Modelo de datos

**`usuarios`**: `id`, `id_externo`, `nombre`, `cargo`, `rol` (chofer | solicitante | admin), `telefono` (nullable), `token_push` (nullable), `activo`.

**`cargos_prioritarios`**: `cargo` (único), `obligatorio` (bool).

**`vehiculos`**: `id`, `patente` (único), `marca`, `modelo`, `color`, `activo`.

**`turnos`**: `id`, `chofer_id`, `vehiculo_id`, `inicio`, `fin` (nullable), `origen` (manual | asistencia).
- Como máximo un turno abierto por chofer y un turno abierto por vehículo.

**`ubicaciones_chofer`**: una fila por chofer con la última posición: `chofer_id` (PK), `lat`, `lng`, `rumbo`, `velocidad`, `actualizado_en`.

**`viajes`**
- `id`, `solicitante_id`, `chofer_id` (nullable), `vehiculo_id` (nullable)
- `tipo` (inmediato | reserva), `modo` (mas_cercano | especifico | cualquiera_disponible)
- `obligatorio` (bool, se copia del cargo al crear el viaje)
- `origen_lat`, `origen_lng`, `origen_direccion`, `destino_lat`, `destino_lng`, `destino_direccion`, `motivo`
- `programado_para` (nullable, solo reservas), `duracion_estimada_min`
- `estado`: `buscando | ofrecido | aceptado | en_camino | llego | en_curso | finalizado | cancelado | sin_chofer`
- Marcas de tiempo por transición (`aceptado_en`, `llego_en`, `iniciado_en`, `finalizado_en`, `cancelado_en`), más `cancelado_por` y `motivo_cancelacion`.

**`ofertas_viaje`**: `id`, `viaje_id`, `chofer_id`, `resultado` (pendiente | aceptada | rechazada | expirada), `ofrecido_en`, `vence_en`, `respondido_en`.

**`recorrido_viaje`**: `viaje_id`, `lat`, `lng`, `registrado_en`. Se purga pasados N días (configurable, por defecto 90).

**`configuracion`**: clave/valor con los parámetros de la sección 5.7.

### 4.1 Estado del chofer (calculado, no almacenado)

| Estado | Condición |
|---|---|
| Fuera de turno | Sin turno abierto |
| Sin señal | Turno abierto y `ubicaciones_chofer.actualizado_en` con más de 2 min de antigüedad |
| En viaje | Tiene un viaje en `aceptado`, `en_camino`, `llego` o `en_curso` |
| Reservado pronto | Libre, pero con una reserva que empieza dentro de los próximos 45 min |
| Libre | Turno abierto, con señal, sin viaje activo y sin reserva próxima |

### 4.2 Disponibilidad para reservas

Un chofer está disponible para la franja `[inicio, inicio + duracion_estimada]` si ninguna otra reserva aceptada suya se superpone con esa franja, extendida con un colchón de 30 min antes y después.

## 5. Flujos

### 5.1 Máquina de estados del viaje

```
buscando ──► ofrecido ──► aceptado ──► en_camino ──► llego ──► en_curso ──► finalizado
   │            │  ▲         │            │            │
   │            └──┘ (rechazo/expira → siguiente chofer)
   ▼            ▼            ▼            ▼            ▼
sin_chofer   sin_chofer   cancelado    cancelado    cancelado
```

- Todas las transiciones pasan por una sola clase, `MaquinaEstadosViaje`, que valida si son legales.
- Las transiciones repetidas (por ejemplo un "finalizar" duplicado) son idempotentes: no hacen nada y no dan error.
- En los viajes obligatorios se pasa directo de `buscando` a `aceptado`.

### 5.2 Pedido inmediato: más cercano

1. Se crea el viaje en `buscando`. Si el cargo del solicitante es obligatorio, se marca `obligatorio = true`.
2. Candidatos: choferes **libres** (según 4.1), excluyendo a los que ya rechazaron este viaje.
3. Se ordenan por distancia en línea recta y se toman los 5 primeros. Se calcula su tiempo real de llegada con Distance Matrix y se elige el menor.
4. Si es obligatorio, se asigna directamente (`aceptado`) y se notifica "viaje asignado". Si no, se crea una oferta (`ofrecido`) que vence a los 30 s.
5. Si la oferta es rechazada o expira, se vuelve al paso 2 con el siguiente chofer.
6. Si no quedan candidatos, el viaje pasa a `sin_chofer` y se notifica al solicitante, que puede reintentar.

### 5.3 Pedido inmediato: chofer específico

- Solo se pueden elegir choferes en estado **libre**.
- Si es obligatorio, se asigna directo. Si no, se crea una oferta de 30 s.
- Si el chofer rechaza o la oferta expira, el viaje pasa a `sin_chofer` y el solicitante ve dos opciones: **Elegir otro** o **Pedir el más cercano**. Cualquiera de las dos crea un viaje nuevo.

### 5.4 Reserva a futuro

1. El solicitante elige fecha y hora (con una anticipación mínima configurable, por defecto 1 h), origen, destino y motivo.
2. El backend estima la duración con Directions y le suma un margen.
3. Se listan los choferes disponibles en esa franja (4.2). El solicitante elige uno, o **Cualquiera disponible**, en cuyo caso el sistema toma al que tenga menos reservas ese día.
4. Si es obligatoria, queda en `aceptado` al instante. Si no, se crea una oferta que vence a los 30 min o 1 h antes del viaje, lo que ocurra primero. Si el chofer rechaza o la oferta expira, se notifica al solicitante para que elija otro.
5. Recordatorios a ambos 24 h antes y 30 min antes.
6. Si el chofer no tiene turno abierto 15 min antes, se alerta al chofer y al panel admin.
7. A partir de la hora programada el viaje sigue el flujo normal desde `aceptado` (el chofer marca "Voy en camino").

### 5.5 Durante el viaje

- El solicitante ve al chofer en vivo, el tiempo estimado de llegada, los datos del chofer y el vehículo, un botón para llamar y el estado actual.
- El chofer avanza el viaje con **Voy en camino → Llegué → Iniciar viaje → Finalizar**, y puede abrir la navegación externa (Google Maps o Waze).
- En `en_curso`, los puntos GPS se registran en `recorrido_viaje`.
- Al finalizar, el chofer vuelve a estar libre.

### 5.6 Cancelaciones

- **Solicitante:** puede cancelar en cualquier estado anterior a `en_curso`.
- **Chofer, viaje no obligatorio:** puede cancelar un viaje ya aceptado indicando un motivo. El sistema intenta reasignarlo con el flujo de 5.2 (inmediato) o notifica al solicitante (reserva).
- **Chofer, viaje obligatorio:** no puede cancelarlo desde la app. Solo el admin puede reasignar o cancelar desde el panel.
- Todas las cancelaciones guardan quién canceló y el motivo.

### 5.7 Parámetros configurables (valores por defecto)

| Parámetro | Valor |
|---|---|
| Tiempo de oferta de un pedido inmediato | 30 s |
| Candidatos evaluados con Distance Matrix | 5 |
| Bloqueo de pedidos inmediatos antes de una reserva | 45 min |
| Colchón entre reservas | 30 min |
| Anticipación mínima de una reserva | 1 h |
| Plazo de respuesta de una reserva | 30 min (o hasta 1 h antes del viaje) |
| Umbral "sin señal" | 2 min |
| Umbral "no disponible" por falta de señal | 10 min |
| Intervalo de GPS en turno / en viaje | 10 s / 5 s |
| Retención del recorrido | 90 días |

### 5.8 Concurrencia

- La asignación de un chofer a un viaje se hace en una transacción con `SELECT ... FOR UPDATE` sobre el chofer y el viaje. Nunca pueden quedar dos viajes activos para un chofer ni dos choferes en un viaje.
- El vencimiento de ofertas, los recordatorios y las alertas son jobs en la cola de Laravel (con retraso), y cada uno verifica el estado actual antes de actuar.

## 6. Tiempo real

- **Canales Reverb:**
  - `mapa.choferes` (privado, para usuarios autenticados): posiciones y estados de los choferes en turno.
  - `viaje.{id}` (privado, solo solicitante, chofer y admin): cambios de estado y posición del chofer asignado.
  - `chofer.{id}` (privado): ofertas y asignaciones.
- **Chofer:** envía su ubicación con `POST /ubicacion`, en lotes si estuvo sin señal. El backend actualiza `ubicaciones_chofer` y retransmite.
- **Push (FCM):** ofertas, asignaciones, cambios de estado relevantes y recordatorios, para cuando la app está cerrada o en segundo plano.
- **Reconexión:** si se cae el WebSocket, la app consulta la API cada 10 s mientras reintenta. Al reconectar, pide el estado completo del viaje activo.

## 7. App Flutter (paquete)

**Tecnología:** Riverpod (estado), `go_router` interno, `google_maps_flutter`, cliente de Pusher/Reverb, geolocalización en segundo plano.

**Solicitante:**
1. Mapa principal con los choferes en turno (verde = libre, gris = en viaje o sin señal). Los choferes *reservados pronto* se ven pero no se pueden elegir. Al tocar uno se ve su detalle y el botón **Pedir a este chofer**.
2. Nuevo pedido (hoja inferior): origen, destino (Places), motivo, **Pedir el más cercano** / **Elegir en el mapa**, y el interruptor **Reservar para más tarde**.
3. Buscando chofer (con opción de cancelar).
4. Viaje activo.
5. Reservar: fecha y hora, choferes disponibles o *Cualquiera disponible*, confirmación.
6. Mis viajes: próximas reservas e historial.

**Chofer:**
1. Iniciar turno (elegir vehículo). Esta pantalla es la que después reemplazará la asistencia.
2. Mapa del chofer, con su estado y la próxima reserva destacada.
3. Oferta entrante a pantalla completa (sonido, vibración, cuenta regresiva, Aceptar/Rechazar) o aviso "Viaje asignado" si es obligatorio.
4. Viaje en curso: botones paso a paso, navegación externa, llamar y cancelar (solo si no es obligatorio).
5. Agenda: reservas confirmadas y solicitudes pendientes.
6. Finalizar turno.

**GPS en segundo plano:** mientras el turno está abierto, con una notificación fija en Android ("Turno activo – compartiendo ubicación") y permiso de ubicación "todo el tiempo". El envío se corta al finalizar el turno.

## 8. Panel de administración (Filament)

1. Mapa en vivo de los choferes y los viajes activos.
2. ABM de vehículos.
3. Choferes: asignar o quitar el rol, ver estado y turnos.
4. Cargos prioritarios: marcar qué cargos generan viajes obligatorios.
5. Viajes y reservas: listado con filtros, detalle con línea de tiempo, ofertas y recorrido. Acciones: **reasignar** y **cancelar**.
6. Alertas: reservas sin chofer en turno, viajes en `sin_chofer`, choferes sin señal durante un viaje.
7. Configuración de los parámetros de 5.7.

El acceso requiere el rol `admin`. El rol de chofer lo asigna el admin (si el endpoint del Poder Judicial trae un cargo "Chofer", se podrá automatizar).

## 9. Errores y casos borde

- **Chofer sin señal:** la app guarda los puntos y los envía al reconectar. A los 2 min pasa a "sin señal" (queda excluido de "más cercano"). A los 10 min deja de estar disponible y, si tiene un viaje activo, se genera una alerta en el panel. El turno **no** se cierra automáticamente.
- **Permiso de ubicación denegado:** el chofer no puede iniciar turno (la app lo explica y ofrece ir a los ajustes). El solicitante puede marcar el origen a mano.
- **Token del Poder Judicial inválido o vencido:** la API responde 401 y el paquete invoca el callback `onSesionInvalida` de la app principal.
- **Endpoint del Poder Judicial caído:** si el token Sanctum sigue vigente, la app sigue funcionando. Si no, muestra un mensaje de "servicio de identidad no disponible".
- **Acciones duplicadas:** las transiciones son idempotentes (5.1).

## 10. Privacidad

- La ubicación solo se envía y guarda durante un turno abierto.
- `ubicaciones_chofer` guarda solo la última posición. El recorrido se guarda solo durante los viajes y se purga a los 90 días (a confirmar con el área legal).

## 11. Pruebas

- **Backend (Pest):**
  - Unitarias: `MaquinaEstadosViaje`, cálculo del estado del chofer, disponibilidad y superposición de reservas, regla de "obligatorio".
  - Integración: más cercano con rechazos en cadena, chofer específico, reserva completa, cancelaciones (incluida la del obligatorio), pedidos simultáneos al mismo chofer.
  - Google y FCM se reemplazan por dobles de prueba.
- **Flutter:** unitarias de los providers de Riverpod y pruebas de widgets de la oferta entrante y el viaje activo.
- **Punta a punta manual:** `host_prueba` contra el backend local.
- **Simulador:** `php artisan simular:choferes {n}` mueve choferes falsos por la ciudad.

## 12. Dependencias con el equipo de la app principal

1. Formato y URL del endpoint de datos de sesión (incluye el **cargo**).
2. Agregar el paquete `vehiculos_oficiales` y el ítem en "Herramientas".
3. Puente FCM: pasar el token push y reenviar los mensajes del módulo al paquete.
4. Declarar los permisos de ubicación en segundo plano y el servicio en primer plano en AndroidManifest e Info.plist, y justificarlos ante las tiendas.
5. Informar qué librería de manejo de estado y qué versiones de Flutter/Dart usan, para evitar conflictos de dependencias.
6. API key de Google Maps con los permisos correspondientes (propia del módulo o de la app principal).

## 13. Evolución prevista (fuera de v1)

- `TurnoAsistencia`: el turno se abre y cierra con el control de asistencia de la app principal.
- Reportes (km por vehículo, viajes por dependencia) y exportación.
- Asignación automática del rol de chofer según el cargo.
