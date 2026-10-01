# Mejoras surgidas de la demo: ubicación, búsqueda de destino y mapa en vivo del panel — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Lo que el usuario vio al probar la app en un dispositivo real:
1. El mapa abre en el centro por defecto (Buenos Aires) en vez de en la ubicación de la persona; hay que moverlo a mano.
2. El solicitante tiene que marcar el origen en el mapa: el origen debe ser **su ubicación actual** automáticamente.
3. El destino solo se puede marcar en el mapa: tiene que poder **escribirse** (con sugerencias).
4. El mapa en vivo del panel de administración no se ve (usa Google Maps y no hay clave): tiene que mostrar a **todos los choferes** en turno y los viajes activos.

**Architecture:** Backend Laravel (`backend/`), paquete Flutter `paquete/vehiculos_oficiales/` (Riverpod 3 sin generación de código; mapa detrás de `constructorMapaProvider` con `MapaGoogle` o `MapaOsm` según haya clave; `Ubicador` en lib/src/ubicacion/ubicador.dart; borrador del pedido en lib/src/solicitante/borrador_pedido.dart; pantalla del solicitante lib/src/ui/solicitante/inicio_solicitante.dart; mapa del chofer lib/src/ui/chofer/mapa_chofer.dart). Panel Filament 5.9 (`backend/app/Filament/Pages/MapaEnVivo.php` + `resources/views/filament/pages/mapa-en-vivo.blade.php`).

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md` (3.3 "ServicioMapas … geocodificación", 7 solicitante "origen, destino (Places)", 8.1 mapa en vivo, 9 permiso denegado → marcar a mano).

## Decisiones

1. **Búsqueda de lugares en el backend** (no en la app): `GET /api/lugares?q=…&lat=…&lng=…` (autenticado) → `[{nombre, direccion, lat, lng}]`, como mucho 5. Interfaz nueva `App\Mapas\BuscadorLugares` con tres implementaciones elegidas por `config('vehiculos.lugares.driver')` (env `LUGARES_DRIVER`):
   - `nominatim` (por defecto en desarrollo): API pública de OpenStreetMap (`https://nominatim.openstreetmap.org/search`, `format=jsonv2`, `addressdetails=1`, `limit=5`, `countrycodes=ar`, `accept-language=es`, sesgo con `viewbox` alrededor de lat/lng si vienen). Respeta su política: `User-Agent` propio identificable, como mucho 1 pedido por segundo (bloqueo simple en cache) y cache de 24 h por consulta normalizada. **Solo para desarrollo/demos** (igual que los tiles de OSM).
   - `google`: Places API (New) Text Search o Geocoding con `config('vehiculos.mapas.google_api_key')`.
   - `falso`: resultados deterministas para tests.
   - Nunca lanza: ante error/timeout devuelve `[]` (la app muestra "Sin resultados"). Texto de menos de 3 caracteres → 422 de validación.
2. **El mapa se mueve solo:** `DatosMapa` suma la noción de "a dónde mirar" (un centro que puede cambiar y una forma de pedir "centrar ahora", p. ej. un contador/versión de enfoque). `MapaGoogle` y `MapaOsm` mueven la cámara cuando cambia (sin recrear el mapa). Cada pantalla con mapa ofrece un botón **"Mi ubicación"** que vuelve a centrar.
3. **Solicitante:**
   - Al abrir, pide la ubicación (`Ubicador.actual()`) y **centra el mapa ahí**; si no hay permiso/ubicación, queda el centro por defecto y se pide marcar el origen a mano (comportamiento actual, spec 9).
   - El **origen es la ubicación actual** automáticamente (se muestra "Origen: tu ubicación actual"); hay un botón **"Cambiar origen"** que permite tocar el mapa o buscar una dirección.
   - El **destino** se elige con un **campo de búsqueda** con sugerencias (consulta a `/api/lugares` con 400 ms de espera tras dejar de escribir, desde 3 caracteres, sesgada a la ubicación actual); elegir una sugerencia fija destino y dirección y centra el mapa entre origen y destino. Tocar el mapa sigue funcionando para el destino.
   - Lo mismo en la pantalla de reserva (comparte el borrador).
4. **Chofer:** el mapa del chofer se centra en su posición cuando llega el primer punto del GPS del turno, y tiene el botón "Mi ubicación". No lo sigue continuamente (para que pueda mover el mapa).
5. **Mapa en vivo del panel:** Leaflet + tiles de OpenStreetMap cuando no hay clave de Google para el navegador (`GOOGLE_MAPS_JS_API_KEY`/`google_api_key`); con clave, el comportamiento actual. Muestra **todos los choferes con turno abierto** (aunque estén sin señal; color por estado: libre verde, en viaje azul, reservado pronto ámbar, sin señal gris), con nombre, vehículo y hora de la última ubicación en el globo; los **viajes activos** (origen y destino unidos por una línea, con el estado); ajusta el encuadre a todos los puntos la primera vez y se actualiza cada 10 s sin perder el zoom que eligió el admin. Leaflet desde unpkg/cdnjs con SRI.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend: Pest en verde; los tests nunca llaman a servicios reales (`Http::fake`).
- Paquete: `flutter analyze` sin problemas, `dart format --output=none --set-exit-if-changed lib test` sin cambios, todos los tests en verde; `host_prueba` pasa `flutter test`/`flutter analyze`. Toda llamada HTTP por `ClienteApi`/`ApiVehiculos`; sin errores asíncronos sin capturar; `ref.mounted` tras cada `await`; timers/streams en notifiers; solo `mapa_google.dart` importa google_maps_flutter y solo `mapa_osm.dart` importa flutter_map/latlong2.
- Cada commit con un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — búsqueda de lugares
Files: `app/Mapas/BuscadorLugares.php`, `app/Mapas/{BuscadorNominatim,BuscadorGoogle,BuscadorFalso}.php`, binding en `AppServiceProvider`, `config/vehiculos.php` (`lugares.driver`, user agent), `.env.example` (`LUGARES_DRIVER`), `app/Http/Controllers/LugaresController.php`, ruta en `routes/api.php` (grupo `auth:sanctum` + `activo`), `phpunit.xml` (`LUGARES_DRIVER=falso`), tests `tests/Feature/LugaresTest.php`.
Tests: endpoint devuelve la forma pedida (con el falso); Nominatim con `Http::fake` (parámetros, User-Agent, mapeo, cache 24 h, error → `[]`, respeto de 1/s sin dormir en tests: la segunda consulta distinta dentro del mismo segundo espera o devuelve de cache — elegir y documentar); Google con `Http::fake`; validación `q` < 3 → 422; sin sesión → 401.
Commit: `feat: búsqueda de lugares para el destino (OpenStreetMap o Google)`

### Task 2: Flutter — el mapa se mueve y "Mi ubicación"
Files: `lib/src/mapa/mapa.dart` (DatosMapa: centro/enfoque), `mapa_google.dart`, `mapa_osm.dart`, `test/soporte` (mapaDePrueba si hace falta), tests de mapa.
Tests: cambiar el enfoque mueve la cámara (OSM con el MapController; Google: probar la lógica que decide mover, sin instanciar GoogleMap); no se recrea el mapa.
Commit: `feat: el mapa se centra donde se le pide`

### Task 3: Flutter — solicitante: origen = mi ubicación, destino escrito
Files: `lib/src/api/api_vehiculos.dart` (`buscarLugares`), modelo `Lugar`, `lib/src/solicitante/borrador_pedido.dart`, nuevo notifier/provider de búsqueda con debounce (timer en el notifier), `lib/src/ui/solicitante/inicio_solicitante.dart` (y la pantalla de reserva si usa los mismos campos), tests de borrador, búsqueda y widgets.
Tests: al abrir con permiso → origen = ubicación y mapa centrado ahí; sin permiso → origen a marcar; "Cambiar origen"; escribir ≥3 letras → una sola consulta tras 400 ms con lat/lng; elegir sugerencia → destino fijado; pedir manda origen/destino correctos; errores de búsqueda → "Sin resultados" sin excepción.
Commit: `feat: el origen es la ubicación actual y el destino se puede escribir`

### Task 4: Flutter — mapa del chofer centrado en su posición
Files: `lib/src/ui/chofer/mapa_chofer.dart` (+ lo que necesite), tests.
Commit: `feat: el mapa del chofer se centra en su posición`

### Task 5: Panel — mapa en vivo con OpenStreetMap
Files: `app/Filament/Pages/MapaEnVivo.php`, `resources/views/filament/pages/mapa-en-vivo.blade.php`, tests existentes del panel/mapa (`tests/Feature/Panel/...`).
Tests: el método de datos incluye todos los choferes con turno abierto (también sin señal) con estado, vehículo y última ubicación, y los viajes activos con origen/destino; la página renderiza con Leaflet cuando no hay clave y con Google cuando la hay.
Commit: `feat: mapa en vivo del panel con OpenStreetMap y todos los choferes`
