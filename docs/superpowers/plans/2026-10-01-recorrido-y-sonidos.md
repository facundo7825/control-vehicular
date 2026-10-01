# Recorrido, indicaciones al chofer y avisos con sonido — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Pedido del usuario después de la demo:
1. Al pedir el viaje se ve el **recorrido** a hacer.
2. El chofer recibe **indicaciones dentro de la app**: el recorrido en el mapa y un cartel con la próxima indicación en español que avanza con el GPS, sin voz. El botón **Navegar** (Google Maps / Waze) se mantiene para guiar con voz.
3. Hay **sonido** en estos casos:
   - al chofer le llega una oferta: suena repetido hasta que acepta, rechaza o vence;
   - al solicitante le aceptan el viaje;
   - al solicitante le avisan que el chofer llegó;
   - al chofer o al solicitante les cancelan el viaje.

   Con la app en segundo plano, el aviso sale como una notificación con sonido.

**Architecture:**
- Backend Laravel (`backend/`).
- Paquete Flutter `paquete/vehiculos_oficiales/`, con Riverpod 3 sin generación de código.
  - El mapa está detrás de `constructorMapaProvider` (`MapaOsm`/`MapaGoogle`), con `DatosMapa`, `Enfoque` y `MarcadorMapa` en `lib/src/mapa/mapa.dart`.
  - El viaje compartido vive en `lib/src/viaje/viaje_actual.dart`, y `EstadoViaje` en `lib/src/modelos/viaje.dart`.
  - Pantallas del chofer: `lib/src/ui/chofer/{pantalla_oferta,viaje_chofer,mapa_chofer}.dart`. Pantallas del solicitante: `lib/src/ui/solicitante/{inicio_solicitante,pantalla_viaje}.dart`.
  - La navegación externa ya existe en `lib/src/chofer/pasos_viaje.dart`, y `alertaOfertaProvider` en `pantalla_oferta.dart`.
- Host de prueba: `host_prueba/`.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`, puntos 5.5 (navegación externa), 6 (push), 7 chofer 3 (oferta con sonido, vibración y cuenta regresiva) y 10 (privacidad).

## Decisiones

1. **Recorrido en el backend:** `GET /api/ruta?origen_lat&origen_lng&destino_lat&destino_lng`, autenticado con `auth:sanctum` + `activo` y limitado a 60 por minuto por usuario. Responde:
   `{distancia_m, duracion_s, puntos: [[lat,lng],…], pasos: [{instruccion, distancia_m, indice, lat, lng, tipo}]}`
   - `indice` es la posición del punto de la maniobra dentro de `puntos`.
   - `instruccion` va en español rioplatense: "Doblá a la derecha por San Martín", "Seguí por Belgrano", "En la rotonda, tomá la 2.ª salida", "Llegaste a destino".
   - Interfaz `App\Mapas\ServicioRutas` con drivers elegidos por `config('vehiculos.rutas.driver')` (env `RUTAS_DRIVER`):
     - `osrm`, por defecto en desarrollo y demos: el servidor público de OSRM, `https://router.project-osrm.org/route/v1/driving/…?overview=full&geometries=geojson&steps=true`. Las instrucciones en español se arman con `type`, `modifier` y `name` de cada maniobra, en una clase propia y testeada.
     - `google`: Directions API con `language=es`. Las instrucciones vienen de `html_instructions`, sin etiquetas.
     - `falso`: una línea recta con dos pasos, para tests.
   - Igual que la búsqueda de lugares:
     - nunca lanza; ante error responde 200 con `null` y la app sigue sin recorrido;
     - User-Agent propio;
     - timeout de 3 s;
     - corte de 60 s ante falla;
     - cache de 10 min por coordenadas redondeadas a 4 decimales (unos 11 m), que se usan también en el pedido;
     - en el log no van coordenadas ni URL.
   - Coordenadas inválidas dan 422.
2. **Mapa con líneas:** `DatosMapa.lineas` es una lista de `LineaMapa(id, puntos, color, ancho)`. `MapaOsm` la dibuja con `PolylineLayer` y `MapaGoogle` con `polylines`. El recorrido se dibuja en azul con 5 px de ancho.
   - En la app hay un modelo `Ruta` (con `PasoRuta`), `ApiVehiculos.obtenerRuta(origen, destino)` y un provider que cachea por origen y destino redondeados. Si la ruta falla, el valor es null y la app no muestra error, solo deja de mostrar el recorrido.
3. **Solicitante:**
   - Con origen y destino elegidos, el mapa muestra el recorrido y encuadra origen, destino y ruta. El panel muestra "≈ 12 min · 5,3 km" debajo del destino.
   - En la pantalla del viaje en curso:
     - antes de "En curso", el recorrido del pedido;
     - en curso, el recorrido desde la posición del chofer al destino, recalculado como mucho cada 30 s.
4. **Chofer — indicaciones en la app** (pantalla del viaje en curso):
   - Con el viaje aceptado, en camino o llegó, el recorrido va desde su posición al origen. En curso, desde su posición al destino.
   - Arriba del mapa hay un cartel con la próxima indicación:
     - "En 200 m, doblá a la derecha por San Martín", y a menos de 30 m solo la indicación;
     - debajo, lo que falta: "4,1 km · 9 min".
   - El avance se calcula con el GPS del turno: el punto más cercano del recorrido, buscando hacia adelante, define la próxima maniobra y la distancia por la línea.
   - Si se aleja más de 60 m del recorrido en 2 posiciones seguidas, se recalcula, como mucho una vez cada 30 s.
   - El botón **Navegar** (Google Maps / Waze) sigue y se ve bien.
5. **Sonidos y avisos:**
   - Los sonidos son WAV cortos generados por un script del repo, `scripts/generar-sonidos.*`, sin licencias de terceros, y van como assets del paquete:
     - `oferta`: un timbre de dos tonos que se repite;
     - `aceptado`: un acorde ascendente;
     - `llego`: dos campanadas;
     - `cancelado`: un tono descendente.
   - Se reproducen con `audioplayers` (Android, iOS y web) detrás de una costura `ReproductorSonidos` con un doble en los tests. Nunca lanza.
   - Los avisos los dispara un notifier `AvisosViaje`, no las pantallas, con cada **transición** del viaje actual:
     - anterior distinto de nuevo y mismo viaje;
     - nunca al cargar un viaje ya en ese estado ni por un evento viejo.
   - Para el **chofer**:
     - una oferta nueva suena en bucle, con vibración repetida cada 2 s, hasta aceptar, rechazar o vencer. Reemplaza `alertaOfertaProvider`;
     - un viaje asignado obligatorio suena con `oferta` una vez;
     - un viaje cancelado suena con `cancelado`.
   - Para el **solicitante**:
     - `aceptado` (también la reserva aceptada, si la app se entera por evento o al refrescar);
     - `llego`;
     - `cancelado`, tanto si cancela el chofer o el admin como si queda `sin_chofer`. Nunca cuando cancela él mismo.
   - **En segundo plano** (estado del ciclo de vida distinto de `resumed`): además del sonido, una notificación local con `flutter_local_notifications`, en un canal de alta prioridad con sonido. El título y el texto van en español ("Nuevo viaje ofrecido", "Tu viaje fue aceptado", "El chofer llegó", "Viaje cancelado"). Tocarla abre la app.
   - En Android 13+ el permiso de notificaciones se pide al iniciar el turno (chofer) o al pedir el primer viaje (solicitante). En web solo hay sonido.
   - Con la app cerrada no hay avisos: eso es FCM (spec 6), fuera de este plan.
6. **Host:** `INTEGRACION.md` documenta las dependencias nuevas y la configuración de Android (permiso `POST_NOTIFICATIONS`, desugaring si lo pide `flutter_local_notifications`, ícono de la notificación). `host_prueba` queda configurado y compila el APK.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend: Pest en verde y Pint limpio en los archivos tocados. Los tests nunca llaman a servicios reales (`Http::fake`).
- Paquete:
  - `flutter analyze` sin problemas, `dart format --output=none --set-exit-if-changed lib test` sin cambios y todos los tests en verde;
  - `host_prueba` pasa `flutter test`/`flutter analyze` y `flutter build apk --debug`;
  - toda llamada HTTP pasa por `ClienteApi`/`ApiVehiculos`;
  - sin errores asíncronos sin capturar, y `ref.mounted` tras cada `await`;
  - timers y streams viven en notifiers;
  - solo `mapa_google.dart` importa google_maps_flutter y solo `mapa_osm.dart` importa flutter_map/latlong2;
  - los plugins nuevos quedan detrás de costuras con dobles en los tests.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Backend — recorrido con indicaciones en español
Files: `app/Mapas/{ServicioRutas,RutasOsrm,RutasGoogle,RutasFalso,InstruccionesOsrm}.php` (o nombres equivalentes), binding en `AppServiceProvider`, `config/vehiculos.php` (`rutas.driver`), `.env.example`, `phpunit.xml` (`RUTAS_DRIVER=falso`), `app/Http/Controllers/RutaController.php`, ruta y rate limiter, tests `tests/Feature/RutaTest.php` y `tests/Unit/InstruccionesOsrmTest.php`.
Tests:
- forma de la respuesta con el falso;
- OSRM con `Http::fake`: URL, User-Agent, mapeo de puntos, pasos e `indice`, redondeo, cache, corte ante falla, timeout;
- traducción de maniobras: giros con todos los modificadores, seguir, rotonda con salida, llegada, calle sin nombre;
- Google con `Http::fake`, sin HTML;
- validación 422, sin sesión 401, throttle 429, y el log sin coordenadas.
Commit: `feat: recorrido con indicaciones en español (OSRM o Google)`

### Task 2: App — líneas en el mapa y ruta desde la API
Files: `lib/src/mapa/{mapa,mapa_osm,mapa_google}.dart` (`LineaMapa`, `DatosMapa.lineas`), modelo `Ruta`/`PasoRuta`, `ApiVehiculos.obtenerRuta`, provider de ruta cacheado, `test/soporte` (el mapa de prueba expone las líneas), tests.
Commit: `feat: el mapa dibuja recorridos y la app obtiene la ruta`

### Task 3: Solicitante — ver el recorrido al pedir y durante el viaje
Files: `lib/src/ui/solicitante/{inicio_solicitante,pantalla_viaje}.dart` (+ reserva si comparte el panel), tests.
Commit: `feat: el solicitante ve el recorrido del viaje`

### Task 4: Chofer — recorrido e indicaciones paso a paso
Files: nuevo `lib/src/chofer/guia_ruta.dart` (notifier: ruta activa, avance, próxima indicación, recálculo), `lib/src/ui/chofer/viaje_chofer.dart` (cartel y recorrido), tests (lógica de avance con puntos sintéticos; widget).
Commit: `feat: indicaciones paso a paso para el chofer`

### Task 5: Avisos con sonido y notificaciones
Files: `scripts/generar-sonidos.*` + `assets/sonidos/*.wav` en el paquete (`pubspec.yaml`), `lib/src/avisos/{reproductor_sonidos,notificaciones_locales,avisos_viaje}.dart`, cambios en `pantalla_oferta.dart` (reemplaza `alertaOfertaProvider`), arranque del notifier en el módulo, pedido de permiso, `INTEGRACION.md`, `host_prueba/android/...`, tests (transiciones → sonido correcto una sola vez, bucle de la oferta que se corta, segundo plano → notificación, sin aviso al cancelar uno mismo).
Commit: `feat: avisos con sonido para ofertas, aceptación, llegada y cancelación`
