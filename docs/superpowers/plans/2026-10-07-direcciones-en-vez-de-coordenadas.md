# Direcciones en lugar de coordenadas — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** El usuario no quiere ver coordenadas en ningún lado: todo punto se muestra con su **dirección**. Hoy, cuando el origen es "mi ubicación" o se marca tocando el mapa, el pedido viaja sin `*_direccion`, y la app (`Lugar.descripcion` en `lib/src/modelos/comunes.dart`) y el panel (por ejemplo `ViajeResource.php:289`) muestran "lat, lng".

**Architecture:**
- Backend Laravel:
  - `App\Mapas\BuscadorLugares` y sus implementaciones (`BuscadorNominatim` con URL configurable, servidor propio o el público con espera de 1 s, corte de 60 s y log sin coordenadas; `BuscadorGeoref`; `BuscadorGoogle`; `BuscadorFalso`; `BuscadorCombinado`);
  - `LugaresController` y `GET /api/lugares` (limiter `lugares`);
  - creación de viajes: `ServicioViaje::pedir()`, `ServicioReservas::crear()` y `CreateViaje` del panel;
  - `ViajeResource`;
  - panel: `ViajeResource` (columnas origen/destino), mapa en vivo (`DatosMapaPanel`, globos), exportaciones (`ExportadorExcel`), Fichajes no aplica.
- Paquete Flutter:
  - `Lugar` (`direccion?` + `coordenada`, `descripcion`);
  - borrador del pedido (`lib/src/solicitante/borrador_pedido.dart`, con origen "mi ubicación" y destino por toque o sugerencia);
  - todas las pantallas que usan `.descripcion` (`grep -rln "\.descripcion" lib`);
  - `ApiVehiculos`.

## Decisiones

1. **Geocodificación inversa en el backend:**
   - Interfaz nueva `App\Mapas\GeocodificadorInverso::direccion(float $lat, float $lng): ?string`, que nunca lanza.
   - Implementaciones:
     - **Nominatim:** `/reverse?format=jsonv2&lat&lng&zoom=18&addressdetails=1&accept-language=es`, con el mismo cliente, URL, User-Agent, corte y cache que la búsqueda.
       - Cache de 24 h por coordenadas redondeadas a 4 decimales.
       - Contra el servidor **público**, la espera de 1 por segundo como en la búsqueda.
       - Arma una dirección legible: `calle altura, barrio o localidad` (por ejemplo "Sarmiento 520, San Fernando del Valle de Catamarca"). Sin altura, `calle, localidad`. Sin calle, el nombre del lugar o la localidad.
     - **Google:** Geocoding API reverse con `language=es`.
     - **Falso:** un texto determinista para tests.
   - Se elige como `LUGARES_DRIVER`: `nominatim` y `georef` usan Nominatim (Georef no hace inversa por calle); `google` usa Google; `falso` usa el falso; el combinado usa Nominatim.
2. **Endpoint `GET /api/lugares/inverso?lat=&lng=`** (autenticado y activo, con el mismo limiter `lugares`). Responde `{"direccion": string|null}`; lat/lng inválidos dan 422.
3. **Al crear un viaje o una reserva** (API y panel): si llega sin `origen_direccion` o sin `destino_direccion`, el servidor la completa con la geocodificación inversa **antes de guardar**.
   - Timeout total de 2 s por punto. Si falla, se guarda sin dirección y se encola un job `CompletarDirecciones` que reintenta (3 intentos, con espera) y, si lo logra, actualiza el viaje y emite `ViajeActualizado`.
   - No bloquea el despacho más de esos 2 s; con el servidor propio responde en milisegundos.
4. **Comando `vehiculos:completar-direcciones`** (manual, acotado): completa las direcciones faltantes de viajes existentes, en lotes y respetando el límite del Nominatim público. Sirve para la base de la demo.
5. **Nunca coordenadas a la vista:**
   - App: `Lugar.descripcion` sin dirección muestra **"Ubicación marcada en el mapa"**.
   - Panel: columnas, globos, exportaciones y el detalle del viaje muestran "Ubicación marcada en el mapa" en lugar de "lat, lng".
6. **App del solicitante:**
   - **Origen "mi ubicación":** al obtener la ubicación se pide `GET /lugares/inverso` y el panel muestra "Tu ubicación actual" con la dirección debajo (por ejemplo "Sarmiento 520"). Esa dirección **se envía** como `origen_direccion`.
   - **Destino marcado tocando el mapa:** se pide la inversa y se fija esa dirección, que también se envía.
   - Sin conexión o si falla, se sigue sin dirección; el servidor la completa al crear.
   - Mientras se busca se muestra "Buscando la dirección…".
   - Las respuestas viejas no pisan un punto más nuevo (se descarta si el punto cambió).
   - Todo pasa por `ApiVehiculos` y se usa `ref.mounted` después de cada `await`.

## Global Constraints

- Nombres y textos en español; patrones existentes (`ClienteOsrm`/`RutasRemotas`/`BuscadorNominatim`: timeout, corte, cache, log sin coordenadas).
- Backend: Pest en verde y Pint limpio en los archivos tocados; los tests usan `Http::fake`; las variables nuevas se fijan en `phpunit.xml`.
- Paquete:
  - `flutter analyze` y `dart format --output=none --set-exit-if-changed lib test` limpios;
  - todos los tests en verde;
  - `host_prueba` pasa `flutter test`/`flutter analyze`.
- Git: dos agentes en paralelo. Nunca `git add -A`; los archivos nuevos se agregan con su ruta explícita y se commitea con pathspec.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — geocodificación inversa, completar al crear y sin coordenadas en el panel
Files:
- `app/Mapas/GeocodificadorInverso.php` + implementaciones;
- binding;
- `LugaresController` (`inverso`) + ruta;
- `ServicioViaje`/`ServicioReservas`/`CreateViaje` (completar);
- `app/Jobs/CompletarDirecciones.php`;
- el comando;
- panel (columnas, mapa en vivo, exportación) con el texto de reemplazo;
- tests.

Commit: `feat: los viajes guardan la dirección de cada punto y el panel no muestra coordenadas`

### Task 2: App — dirección del origen y del destino marcado, sin coordenadas
Files:
- `ApiVehiculos.direccionDe`;
- borrador del pedido;
- `inicio_solicitante.dart` (y la reserva si comparte);
- `Lugar.descripcion`;
- tests.

Commit: `feat: la app muestra y envía la dirección del origen y del destino marcado`
