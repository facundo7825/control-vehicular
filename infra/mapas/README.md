# Servidor de mapas propio (Catamarca)

Tres servicios libres, con datos de OpenStreetMap, para no depender de Google ni de los servidores públicos:

| Servicio | Imagen | Puerto (127.0.0.1) | Para qué |
|---|---|---|---|
| `osrm` | `ghcr.io/project-osrm/osrm-backend:v6.0.0` | 5001 | Recorridos con indicaciones y tiempos de viaje (chofer más cercano, llegada estimada) |
| `nominatim` | `mediagis/nominatim:5.3` | 8088 | Búsqueda de lugares y direcciones |
| `teselas` | `maptiler/tileserver-gl:v5.6.0` | 8089 | Mapa de fondo en PNG (`/styles/basico/{z}/{x}/{y}.png`) |

La guía completa (recursos, producción, HTTPS, actualización, licencias) está en [`docs/PRODUCCION-MAPAS.md`](../../docs/PRODUCCION-MAPAS.md).

## Inicio rápido

Necesita Docker (con `docker compose`), `curl` y unos 10 GB libres (imágenes 4,3 GB + datos 3,5 GB); la primera preparación tarda ~15 minutos.

> **Primero `./preparar-datos.sh`, después `docker compose up`.** Si se levanta antes, Docker crea carpetas vacías
> donde van los archivos de datos (por ejemplo `datos/catamarca.osm.pbf` como carpeta) y los servicios no arrancan.
> En ese caso: `docker compose down`, borrar esa carpeta vacía y correr el script.

```bash
cd infra/mapas
./preparar-datos.sh          # baja Argentina (~410 MB, verifica el MD5), recorta Catamarca, prepara OSRM y las teselas
cp .env.example .env         # y poner una clave larga en NOMINATIM_CLAVE_DB (obligatoria)
docker compose up -d         # la primera vez Nominatim importa el recorte (unos minutos)
docker compose ps            # esperar que los tres digan (healthy)
```

Si el script se corta (Ctrl+C, un corte de luz), se vuelve a correr: rehace solo lo que no terminó.

Probar:

```bash
curl "http://127.0.0.1:5001/route/v1/driving/-65.779,-28.469;-65.77,-28.46?overview=false"
curl "http://127.0.0.1:8088/search?q=Catedral+Catamarca&format=json&limit=1"
curl -o tesela.png -w "%{http_code} %{content_type}\n" "http://127.0.0.1:8089/styles/basico/14/5198/9543.png"
```

Todo lo generado queda en `datos/` (ignorado por git; se puede borrar y regenerar) y en el volumen `mapas_nominatim-db`.

### Windows

- **Git Bash:** `./preparar-datos.sh` funciona tal cual con Docker Desktop iniciado (el script usa rutas `C:/...` y
  `MSYS_NO_PATHCONV=1` para los `docker run -v`).
- **WSL2:** es bash estándar (no se probó en esta verificación); conviene clonar el repo dentro del sistema de archivos de Linux (`~/...`, no `/mnt/c`)
  porque es mucho más rápido.

## Actualizar los datos (mensual)

```bash
./preparar-datos.sh --actualizar
```

Si dice "Sin cambios en Geofabrik", no hay nada más que hacer. Si el recorte cambió, el script muestra los comandos
para aplicar los datos nuevos (reiniciar OSRM y teselas, y que Nominatim reimporte). Detalle en la guía.

## Apuntar el backend

En `backend/.env` (no hace falta recompilar la app: el mapa de fondo le llega por `/api/configuracion`):

```env
LUGARES_DRIVER=nominatim,georef
LUGARES_NOMINATIM_URL=http://127.0.0.1:8088
LUGARES_PROVINCIA=Catamarca
RUTAS_DRIVER=osrm
RUTAS_OSRM_URL=http://127.0.0.1:5001
MAPAS_DRIVER=osrm
MAPAS_TESELAS_URL=https://mapas.ejemplo.gob.ar/styles/basico/{z}/{x}/{y}.png
MAPAS_TESELAS_ATRIBUCION="© OpenMapTiles © OpenStreetMap contributors"
MAPAS_TESELAS_MAX_ZOOM=18
```

Las teselas las piden los celulares y el navegador, así que en producción van detrás de un proxy con HTTPS (ver la guía).
