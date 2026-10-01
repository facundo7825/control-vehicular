# Mejoras del panel: tablero y reportes, alertas en vivo, gestión de viajes y detalle desde el mapa — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** El usuario pidió cuatro mejoras del panel de administración:
1. **Tablero y reportes:** números del día y la semana, gráficos, y reportes por chofer y por vehículo exportables a Excel.
2. **Alertas en vivo:** cuando pasa algo, el admin recibe un aviso con sonido sin recargar la página.
3. **Gestionar viajes:**
   - crear un viaje o una reserva para otra persona;
   - asignar un chofer a mano;
   - más filtros;
   - exportar a Excel.
4. **Detalle desde el mapa:**
   - al tocar un chofer, se ven su viaje actual con el tiempo estimado de llegada, lo que hizo en el día y un acceso al viaje;
   - al tocar un viaje, se resalta su recorrido.

**Architecture:** Solo backend: Laravel 12 + Filament 5.9 en `backend/`.
- Panel en `app/Providers/Filament/AdminPanelProvider.php`. No tiene grupos de navegación, notificaciones de base de datos ni Echo/Reverb en el navegador.
- Widget actual: `app/Filament/Widgets/ResumenOperativo.php` (con `App\Servicios\ResumenPanel`).
- Viajes:
  - `app/Filament/Resources/Viajes/{ViajeResource.php, Pages/ListViajes.php, Pages/ViewViaje.php}`;
  - "Reasignar" usa `ServicioViaje::reasignarPorAdmin` y "Cancelar" usa `cancelarPorAdmin`, los dos en `ViewViaje.php`.
- Alertas: `app/Models/Alerta.php` (tipos como constantes), `AlertaResource`, `AlertasSinSenal` y el job `AlertarReservaSinTurno`.
- Pedidos y despacho:
  - `ServicioViaje::pedir()`, `ServicioReservas::crear()`;
  - `Despachador::{despachar, pedirA}`;
  - `MaquinaEstadosViaje`.
- Mapa en vivo:
  - `app/Filament/Pages/MapaEnVivo.php`, `resources/views/filament/pages/mapa-en-vivo.blade.php` y `app/Servicios/DatosMapaPanel.php`;
  - los datos llegan con el evento `mapa-datos` cada 10 s;
  - los globos se arman con `globoChofer` y `globoViaje`.
- Tiempo estimado de llegada: `App\Servicios\EstimadorLlegada::estimar(Viaje)`, cacheado 30 s; lanza `ReglaNegocio` fuera de aceptado, en camino, llegó y en curso.
- Recorrido real: `recorrido_viaje` (`PuntoRecorrido`, `registrado_en`), conservado 90 días.
- Turnos: `turnos` (`inicio`, `fin`, `vehiculo_id`).
- Hora local: `App\Support\HoraLocal`, `config('vehiculos.zona_horaria')`. La base está en UTC.
- `openspout/openspout` ya está instalado como dependencia de Filament.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`, punto 8 (panel de administración) y punto 10 (privacidad).

## Decisiones

1. **Tablero** (escritorio):
   - `ResumenOperativo` suma "Viajes hoy" (pedidos del día local, con finalizados y cancelados en la descripción) y "Espera promedio hoy". La espera promedio va del pedido a "llegó" en los viajes inmediatos que llegaron hoy, en minutos; sin datos muestra "—".
   - También suma "Choferes en turno" (con libres y en viaje en la descripción). Se mantienen las tres actuales.
   - Dos gráficos `ChartWidget`, sin librerías nuevas:
     - **Viajes por día**, de los últimos 14 días locales: finalizados, cancelados y sin chofer, apilados.
     - **Pedidos por hora del día**, de los últimos 30 días, en hora local.
   - Los cálculos van en un servicio testeado (`EstadisticasPanel`), no en los widgets.
2. **Reportes:** una página nueva **Reportes** con un rango de fechas (por defecto el mes actual, en días locales) y dos pestañas o tablas.
   - **Por chofer:**
     - viajes finalizados y cancelados por el chofer;
     - km recorridos: la suma de `viajes.metros_recorridos` de sus viajes finalizados en el rango. Se calcula una vez al finalizar el viaje (haversine sobre `recorrido_viaje`) y se completó para los viajes previos con una migración; si en el rango hay viajes finalizados sin ese dato, se avisa;
     - horas de turno: la intersección de cada turno con el rango, con los turnos abiertos hasta ahora;
     - llegada promedio: de aceptado a llegó.
   - **Por vehículo:** viajes finalizados, km y horas en turno.
   - El botón **"Exportar a Excel"** descarga un `.xlsx` generado con OpenSpout, en el momento y sin colas ni tablas nuevas. Lleva encabezados en español y fechas locales.
   - `openspout/openspout` pasa a ser una dependencia explícita en `composer.json`, con la misma versión ya instalada.
   - Hay un exportador común (`App\Servicios\ExportadorExcel`) que también usa la lista de viajes.
3. **Alertas en vivo:**
   - Hay un tipo nuevo de alerta, `viaje_sin_chofer`: se crea cuando un viaje (inmediato o reserva) pasa a `sin_chofer`, una sola vez por viaje, y se resuelve sola si el viaje se asigna o se cancela.
   - Un componente Livewire `AvisoAlertas`, montado en todo el panel con un render hook (`PanelsRenderHook::BODY_END`), consulta cada 10 s si hay alertas pendientes nuevas, con un id mayor al último visto.
     - Al montar, toma como "visto" el id máximo actual, así no repite las viejas.
     - Por cada alerta nueva, hasta 3 (si hay más, un resumen "y N más"), muestra una notificación de Filament persistente con el mensaje y un botón **Ver** (al viaje, o a la lista de alertas).
     - Suena un aviso: un WAV corto generado por un script del repo en `public/sonidos/alerta.wav`, reproducido con `new Audio()` y errores ignorados.
     - En la barra superior hay un botón para **silenciar o activar** el sonido de alertas, que se guarda en `localStorage` del navegador.
     - El navegador no reproduce sonido hasta que el admin interactúa con la página; ese caso está documentado.
   - El badge de Alertas en la navegación ya existe y se mantiene.
4. **Gestionar viajes:**
   - **Nuevo viaje** (página Create del recurso Viajes):
     - **Solicitante**: un select con búsqueda de usuarios activos con rol solicitante.
     - **Tipo**: inmediato o reserva; con reserva aparece **Programado para**.
     - **Origen y destino**: un campo de texto con un botón **Buscar** que consulta `BuscadorLugares`, sin autocompletar, por la política de Nominatim, y llena un select de resultados. Al elegir uno se fijan la dirección y las coordenadas. Hay un desplegable "Coordenadas" para cargarlas a mano.
     - **Modo**: inmediato es "Más cercano" o "Chofer específico"; reserva es "Cualquiera disponible" o "Chofer específico". El select de choferes muestra solo los elegibles.
     - **Motivo**.
     - Usa `ServicioViaje::pedir()` o `ServicioReservas::crear()` con el **solicitante elegido**, así `obligatorio` sale de su cargo. Los errores de negocio se muestran como notificación, sin crear nada.
   - **Asignar chofer** (en la vista y en cada fila de la lista): para viajes en buscando, ofrecido o sin chofer.
     - Asignación directa como "Reasignar": `ServicioViaje::asignarPorAdmin` → `MaquinaEstadosViaje`.
     - Vencen las ofertas pendientes y se resuelve la alerta `viaje_sin_chofer`.
     - Mismas reglas de elegibilidad que "Reasignar": libre si es inmediato, agenda disponible si es reserva.
     - El chofer recibe el aviso por los mismos eventos que una reasignación.
   - **Lista de viajes:**
     - filtro por **solicitante**;
     - búsqueda por número, solicitante, chofer y direcciones;
     - columnas de origen y destino (dirección acortada);
     - acción **Exportar a Excel** que respeta los filtros y la búsqueda activos, con columnas en español y horas locales.
5. **Detalle desde el mapa:**
   - **Globo del chofer:**
     - nombre, vehículo, estado y última ubicación, como ahora;
     - si tiene viaje activo: número con un enlace **Ver viaje** (a `ViewViaje`), estado, solicitante, hacia dónde va (origen o destino, con dirección) y **llega en ≈ N min**. El tiempo sale de `EstimadorLlegada`; sin dato muestra "sin estimación", y sus errores se ignoran;
     - **Hoy**: viajes finalizados, km del día y "en turno desde HH:MM", con un enlace al usuario.
   - Todo sale de `DatosMapaPanel` con consultas agrupadas, sin N+1 por chofer.
   - **Al tocar un viaje** (su línea, su origen o su destino) o "Ver recorrido" en el globo del chofer, se resalta su recorrido: la línea más gruesa y opaca, las demás atenuadas, y el mapa encuadra ese viaje.
     - Tocar el mapa vacío o Escape quita el resaltado.
     - El resaltado se mantiene en las actualizaciones de 10 s mientras el viaje siga activo.
   - Funciona con Leaflet y con Google, y todo el texto pasa por `escapar()`.

## Global Constraints

- Nombres y textos en español; patrones existentes del panel (Filament 5.9, notificaciones con `Filament\Notifications\Notification`).
- Pest en verde y Pint limpio en los archivos tocados. El repo arrastra deuda de Pint en unos 28 archivos que no se tocan; no corregirlos acá.
- Los tests nunca llaman a servicios reales.
- Tiempos en hora local (`HoraLocal`) para mostrar y agrupar; la base está en UTC.
- Sin N+1 evidentes en las consultas del tablero, los reportes y el mapa.
- Privacidad (spec 10): los reportes no exponen ubicaciones, solo agregados.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Tablero con números del día y gráficos
Files: `app/Servicios/EstadisticasPanel.php` (nuevo), `app/Filament/Widgets/ResumenOperativo.php`, `app/Filament/Widgets/{ViajesPorDia,PedidosPorHora}.php` (nuevos), tests `tests/Feature/Panel/TableroTest.php`.
Tests: los números con datos sembrados (bordes de día local vs UTC, espera promedio, sin datos "—") y la serie de cada gráfico; los widgets renderizan.
Commit: `feat: tablero del panel con números del día y gráficos de viajes`

### Task 2: Reportes por chofer y vehículo con exportación a Excel
Files: `app/Servicios/{ReportesPanel,ExportadorExcel}.php` (nuevos), `app/Filament/Pages/Reportes.php` + vista, `composer.json` (openspout explícito), tests `tests/Feature/Panel/ReportesTest.php`.
Tests:
- km por haversine;
- horas de turno recortadas al rango y turno abierto;
- llegada promedio;
- rango por defecto;
- aviso de viajes sin km calculados;
- la descarga devuelve un xlsx válido: leerlo con OpenSpout en el test y verificar encabezados y filas.
Commit: `feat: reportes por chofer y vehículo exportables a Excel`

### Task 3: Alertas en vivo con sonido
Files: `app/Models/Alerta.php` (`VIAJE_SIN_CHOFER`), la creación y resolución en el punto donde un viaje pasa a/sale de `sin_chofer` (máquina de estados o servicio), `AlertaResource` (etiqueta del tipo), `app/Livewire/AvisoAlertas.php` + vista, render hooks en `AdminPanelProvider` (componente + botón de silencio), `scripts/generar-sonido-alerta.*` + `public/sonidos/alerta.wav`, tests.
Tests:
- la alerta se crea una vez y se resuelve sola;
- el componente no avisa las alertas previas al montar, avisa las nuevas, hasta 3 más el resumen, y el botón Ver apunta bien;
- el render hook está presente en las páginas del panel.
Commit: `feat: alertas en vivo con sonido en el panel`

### Task 4: Gestionar viajes desde el panel
Files: `ViajeResource.php` (form, filtros, búsqueda, columnas), `Pages/CreateViaje.php` (nuevo), `Pages/ListViajes.php` (exportar), `Pages/ViewViaje.php` (asignar), `ServicioViaje::asignarPorAdmin` (+ máquina de estados), tests.
Tests:
- crear un inmediato y una reserva para otro solicitante, con obligatorio según su cargo;
- un error de negocio no crea nada;
- el buscador de lugares con el driver falso;
- asignar en buscando, ofrecido y sin chofer: vencen las ofertas, se resuelve la alerta y la elegibilidad se respeta;
- el filtro por solicitante y la búsqueda;
- el Excel respeta los filtros.
Commit: `feat: crear viajes y asignar choferes desde el panel, con filtros y exportación`

### Task 5: Detalle del chofer y del viaje desde el mapa en vivo
Files: `app/Servicios/DatosMapaPanel.php`, `resources/views/filament/pages/mapa-en-vivo.blade.php`, tests `tests/Feature/Panel/MapaEnVivoTest.php`.
Tests:
- datos del chofer con viaje (solicitante, hacia, tiempo estimado, url) y sin viaje;
- "hoy" (viajes, km, turno desde);
- error del estimador → sin estimación;
- consultas acotadas (contar consultas con varios choferes);
- el script incluye el resaltado y el enlace.
Además, verificar la lógica JS con node (sin commitear tooling).
Commit: `feat: detalle del chofer y resaltado del viaje en el mapa en vivo`
