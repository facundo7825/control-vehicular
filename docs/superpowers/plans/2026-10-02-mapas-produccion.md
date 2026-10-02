# Mapas para producción sin servicios pagos — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** El usuario no quiere usar Google Maps porque es pago. Para producción, en la provincia de **Catamarca**, todo lo de mapas debe funcionar con software libre en un **servidor propio**, sin cuotas ni datos de ubicación hacia empresas:
1. Mapa de fondo.
2. Búsqueda de lugares y direcciones.
3. Recorridos con indicaciones.
4. Tiempos de viaje para elegir el chofer más cercano y estimar la llegada.

El tipo de servidor todavía no está definido, así que se entrega con Docker y con una guía.

**Pruebas reales (2026-10-02):**
- El servidor de teselas del IGN (`wms.ign.gob.ar`) no respondió: falló el DNS y el pedido directo a la IP quedó colgado.
- Georef (`apis.datos.gob.ar/georef`) responde. En Catamarca no tiene alturas: "Sarmiento 600" da 0 resultados. Sí resuelve **intersecciones** ("Sarmiento y Rivadavia"), aunque devuelve varias localidades.
- El OSRM público responde a `route` y `table`.

**Architecture:**
- Backend Laravel (`backend/`):
  - interfaces `App\Mapas\{BuscadorLugares, ServicioRutas, ServicioMapas}`;
  - implementaciones actuales: `BuscadorNominatim`/`BuscadorGoogle`/`BuscadorFalso`, `RutasOsrm`/`RutasGoogle`/`RutasFalso` (con la base común `RutasRemotas`) y `GoogleMaps`/`ServicioMapasFalso`;
  - bindings en `AppServiceProvider`, configuración en `config/vehiculos.php`, `GET /api/configuracion` con `lugares_autocompletar`.
- Paquete Flutter (`paquete/vehiculos_oficiales/`): `MapaOsm`, con una URL de teselas constante y un agente de usuario, y el modelo `Configuracion`.
- Panel: Leaflet en `resources/views/filament/pages/mapa-en-vivo.blade.php`.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`, puntos 3.3 (ServicioMapas) y 10 (privacidad).

## Decisiones

1. **Buscar lugares:**
   - Driver nuevo **`georef`**, que usa `GET {url}/direcciones` y `/calles` de la API Georef:
     - la provincia sale de `config('vehiculos.lugares.provincia')`, que en producción es `Catamarca`;
     - los resultados con ubicación se ordenan por cercanía a lat/lng si vienen;
     - `nombre` va en formato legible (por ejemplo "Sarmiento y Rivadavia, Belén"), `direccion` es la nomenclatura y como mucho son 5;
     - mismas garantías que los otros drivers: nunca lanza, timeout de 3 s, corte de 60 s ante falla, cache y nada sensible en el log.
   - Driver **combinado**: `LUGARES_DRIVER=nominatim,georef` consulta en orden, une los resultados, quita duplicados de menos de 30 m y devuelve como mucho 5.
   - Nominatim con **URL configurable** (`LUGARES_NOMINATIM_URL`):
     - la espera de 1 pedido por segundo y la política de "sin autocompletar" se aplican **solo si la URL es la pública** (`nominatim.openstreetmap.org`);
     - con servidor propio no se limita y `lugares_autocompletar` es `true`.
   - `LUGARES_AUTOCOMPLETAR` (opcional) fuerza el valor del flag.
2. **Tiempos de viaje:** driver nuevo de `ServicioMapas`, **`osrm`** (`MAPAS_DRIVER=osrm`), con la URL de OSRM compartida con rutas (`RUTAS_OSRM_URL`):
   - `duracionesHacia` usa el servicio `table` con `annotations=duration` y `duracionRuta` usa `route`;
   - garantías de los drivers remotos: timeout, corte ante falla, cache corto donde corresponda y log sin coordenadas;
   - si falla, devuelve lo que la interfaz define para "sin dato". Hay que leer `GoogleMaps` y los consumidores, como `Asignador`/`EstimadorLlegada`, para respetar el contrato.
   - En `RutasOsrm`, el bloqueo de 1 pedido por segundo se aplica **solo** contra el servidor público `router.project-osrm.org`.
3. **Mapa de fondo configurable desde el backend**, sin recompilar la app:
   - `config('vehiculos.mapas.teselas')` lleva `url`, `atribucion`, `tms` (bool, para servidores con la Y invertida como el IGN) y `max_zoom`. Se configura con `MAPAS_TESELAS_URL`, `MAPAS_TESELAS_ATRIBUCION`, `MAPAS_TESELAS_TMS` y `MAPAS_TESELAS_MAX_ZOOM`.
   - Por defecto es el OSM público actual, solo para desarrollo.
   - `GET /api/configuracion` lo expone como `teselas` (`url`, `atribucion`, `tms`, `max_zoom`).
   - `MapaOsm` usa esa configuración; sin ella, el valor actual. El texto de atribución y el enlace de créditos salen de ahí.
   - El panel (Leaflet) usa la misma configuración, con `tms` aplicado.
4. **Servidor de mapas propio** (`infra/mapas/`):
   - `docker-compose.yml` con:
     - **OSRM** (`osrm/osrm-backend`, algoritmo MLD, perfil car);
     - **Nominatim** (por ejemplo `mediagis/nominatim`, importando el extracto de Catamarca);
     - **TileServer-GL** (`maptiler/tileserver-gl`), que sirve teselas **raster PNG** desde un `.mbtiles` vectorial con un estilo incluido, así la app y el panel no cambian.
   - Script `preparar-datos.sh`:
     - descarga `argentina-latest.osm.pbf` de Geofabrik;
     - recorta Catamarca con osmium por bbox o polígono, con un margen;
     - prepara OSRM (extract, partition, customize);
     - genera el `.mbtiles` con **Planetiler**, acotado al recorte.

     Es idempotente, con versiones de imagen fijas y pensado para correr en Linux o WSL.
   - Guía `docs/PRODUCCION-MAPAS.md`:
     - recursos estimados (RAM, disco, tiempo de preparación);
     - puertos y variables de entorno del backend;
     - cómo actualizar los datos (mensual) y cómo probar cada servicio con curl;
     - el IGN y Georef como alternativas;
     - las licencias y atribuciones (ODbL de OpenStreetMap).
   - Se verifica levantándolo en la compu de desarrollo (Docker Desktop) y apuntando el backend local a esos servicios.
   - `.env.example` lleva una sección "Producción sin Google" con todas las variables.

## Global Constraints

- Nombres y textos en español; patrones existentes de los drivers de mapas: `RutasRemotas` y `BuscadorNominatim` (corte, cache, log sin coordenadas, redondeo).
- Backend: Pest en verde y Pint limpio en los archivos tocados; los tests usan `Http::fake`.
- Paquete:
  - `flutter analyze` y `dart format --output=none --set-exit-if-changed lib test` limpios;
  - todos los tests en verde;
  - `host_prueba` pasa `flutter test` y `flutter analyze`;
  - un `GET /api/configuracion` sin `teselas` sigue funcionando.
- No modificar `backend/.env` ni `backend/database/database.sqlite`.
- Cada commit lleva un solo trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

---

### Task 1: Búsqueda de lugares con Georef y Nominatim propio
Files: `app/Mapas/{BuscadorGeoref,BuscadorCombinado}.php` (nuevos), `BuscadorNominatim.php`, `AppServiceProvider`, `config/vehiculos.php`, `ConfiguracionController` (flag), `.env.example`, tests `tests/Feature/LugaresTest.php` (+ uno nuevo para Georef y el combinado).
Commit: `feat: búsqueda de lugares con Georef y con Nominatim propio`

### Task 2: Tiempos de viaje con OSRM
Files: `app/Mapas/ServicioMapasOsrm.php` (nuevo), `RutasOsrm.php` (bloqueo solo con el servidor público), `AppServiceProvider`, `config/vehiculos.php`, `.env.example`, tests.
Commit: `feat: tiempos de viaje con OSRM para elegir el chofer más cercano`

### Task 3: Mapa de fondo configurable desde el backend
Files: `config/vehiculos.php`, `ConfiguracionController`, la vista del mapa en vivo, en el paquete `lib/src/modelos/configuracion.dart` (+ fixtures) y `lib/src/mapa/mapa_osm.dart`, tests de backend y paquete.
Commit: `feat: el mapa de fondo se configura desde el backend`

### Task 4: Servidor de mapas propio con Docker y guía de producción
Files: `infra/mapas/{docker-compose.yml, preparar-datos.sh, README.md, estilos o config de tileserver}`, `docs/PRODUCCION-MAPAS.md`, `.env.example`, `docs/DEMO.md` si corresponde.
Verificación real en Docker Desktop: preparar los datos, levantar los servicios y probar con curl `route`, `table`, `search` y una tesela PNG. Después, el backend local apuntando a ellos con Pest sin cambios.
Commit: `feat: servidor de mapas propio (OSRM, Nominatim y teselas) para producción`
