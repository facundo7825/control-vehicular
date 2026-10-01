# Buscador de choferes en el mapa en vivo e íconos de auto y punto — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Pedido del usuario después de probar el mapa en vivo:
1. En el mapa en vivo del panel, un **buscador** para encontrar un chofer en particular.
2. En los mapas, los **usuarios** se ven como un **punto** y los **choferes** como un **auto**.

**Architecture:** Panel Filament 5.9: `backend/app/Filament/Pages/MapaEnVivo.php` + `backend/resources/views/filament/pages/mapa-en-vivo.blade.php`. Leaflet/OSM sin clave y Google con clave. Los datos llegan por el evento Livewire `mapa-datos` cada 10 s. Paquete Flutter `paquete/vehiculos_oficiales/`: `lib/src/mapa/{mapa,mapa_osm,mapa_google}.dart` (`TipoMarcador`, `MarcadorMapa`).

## Decisiones

1. **Buscador del panel** (en el navegador, sin ida y vuelta al servidor):
   - Campo "Buscar chofer (nombre o patente)" arriba del mapa. Filtra mientras se escribe, sin distinguir mayúsculas ni acentos, por nombre o patente.
   - Mientras hay texto, los choferes que no coinciden quedan atenuados en el mapa.
   - Debajo del campo, una lista de coincidencias (hasta 8), cada una con nombre, patente y estado con su color.
     - Al tocar una, el mapa va al chofer (zoom 16) y abre su globo.
     - Un chofer que coincide pero no tiene ubicación figura con "sin ubicación todavía" y no mueve el mapa.
   - Enter elige la primera coincidencia; Escape o vaciar el campo vuelve todo a la normalidad.
   - El filtro se mantiene en cada actualización de 10 s.
   - Los choferes sin ubicación de la lista inferior también se pueden encontrar.
   - Funciona con Leaflet y con Google.
2. **Íconos del panel:**
   - Los choferes son un **auto**: un SVG inline de auto (estilo Material "directions_car") blanco sobre un círculo del color de su estado, con borde blanco y sombra. Los sin señal llevan el círculo gris.
   - En los viajes activos, el **origen** (donde está el usuario) es un **punto** naranja con borde blanco y el **destino** un pin rojo.
   - La línea entre ambos se mantiene.
   - Con Google se usan íconos SVG equivalentes (`icon: {url: 'data:image/svg+xml,…', anchor}`).
3. **Íconos de la app** (los mismos en `MapaOsm` y `MapaGoogle`):
   - `choferLibre`, `choferNoDisponible` y `choferAsignado` se ven como un **auto** (`Icons.directions_car` blanco sobre un círculo de su color, con borde blanco). `choferNoDisponible` sigue desvaído y se centra en la posición.
   - `origen` (el usuario) es un **punto** del mismo color de hoy (naranja) con borde blanco, centrado en la posición.
   - `destino` sigue siendo el pin rojo con la punta sobre la posición.
   - Google: íconos generados una vez en tiempo de ejecución, dibujando a PNG con `dart:ui`, y cacheados. Se usan con `BitmapDescriptor.bytes` (o la API equivalente de la versión instalada) con anchor al centro para auto y punto. Mientras no están listos se usan los marcadores de color actuales. Ningún error sin capturar.

## Global Constraints

- Nombres y textos en español; patrones existentes.
- Backend: Pest en verde y Pint limpio. Los textos del servidor que van a HTML siguen pasando por `escapar()`.
- Paquete: `flutter analyze` sin problemas y `dart format --output=none --set-exit-if-changed lib test` sin cambios. Todos los tests en verde, y `host_prueba` pasa `flutter test`/`flutter analyze`. Solo `mapa_google.dart` importa google_maps_flutter y solo `mapa_osm.dart` importa flutter_map/latlong2.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Panel — buscador de choferes e íconos de auto y punto
Files: `resources/views/filament/pages/mapa-en-vivo.blade.php` (+ `MapaEnVivo.php` si hace falta), tests `tests/Feature/Panel/MapaEnVivoTest.php` (o el que exista).
Tests:
- la página renderiza el campo de búsqueda;
- el script incluye la lógica de filtro (normalización sin acentos) y los íconos de auto y punto;
- los datos incluyen lo necesario para buscar (nombre y patente de cada chofer, también los sin ubicación).
Además, verificar la lógica JS de filtrado con una prueba en node (sin commitearla si no hay infraestructura de JS).
Commit: `feat: buscador de choferes en el mapa en vivo y choferes como autos`

### Task 2: App — choferes como auto y usuario como punto
Files: `lib/src/mapa/mapa_osm.dart`, `lib/src/mapa/mapa_google.dart` (+ `mapa.dart` si conviene una función común de "forma" por tipo), tests de mapa.
Tests:
- OSM: un marcador de chofer muestra `Icons.directions_car` y uno de origen un punto (no `location_on`), centrados; el destino sigue siendo `location_on` con la punta abajo; el no disponible sigue desvaído.
- Google: probar la lógica pura (qué forma y qué anchor por tipo, y el fallback mientras no hay íconos) sin instanciar GoogleMap.
Commit: `feat: en los mapas los choferes se ven como autos y el usuario como un punto`
