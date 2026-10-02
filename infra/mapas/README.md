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

```bash
cd infra/mapas
./preparar-datos.sh          # baja Argentina (~410 MB), recorta Catamarca, prepara OSRM y genera las teselas
docker compose up -d         # la primera vez Nominatim importa el recorte (unos minutos)
docker compose logs -f nominatim   # esperar "Nominatim is ready to accept requests"
```

Probar:

```bash
curl "http://localhost:5001/route/v1/driving/-65.779,-28.469;-65.77,-28.46?overview=false"
curl "http://localhost:8088/search?q=Catedral+Catamarca&format=json&limit=1"
curl -o tesela.png -w "%{http_code} %{content_type}\n" "http://localhost:8089/styles/basico/14/5198/9543.png"
```

Todo lo generado queda en `datos/` (ignorado por git; se puede borrar y regenerar) y en el volumen `mapas_nominatim-db`.

### Windows

- **Git Bash:** `./preparar-datos.sh` funciona tal cual con Docker Desktop iniciado (el script usa rutas `C:/...` y
  `MSYS_NO_PATHCONV=1` para los `docker run -v`).
- **WSL2:** también funciona; conviene clonar el repo dentro del sistema de archivos de Linux (`~/...`, no `/mnt/c`)
  porque es mucho más rápido.

## Actualizar los datos (mensual)

```bash
./preparar-datos.sh --actualizar
docker compose down
docker volume rm mapas_nominatim-db     # Nominatim reimporta el recorte nuevo al arrancar
docker compose up -d
```

## Apuntar el backend

En `backend/.env` (no hace falta recompilar la app: el mapa de fondo le llega por `/api/configuracion`):

```env
LUGARES_DRIVER=nominatim,georef
LUGARES_NOMINATIM_URL=http://localhost:8088
LUGARES_PROVINCIA=Catamarca
RUTAS_DRIVER=osrm
RUTAS_OSRM_URL=http://localhost:5001
MAPAS_DRIVER=osrm
MAPAS_TESELAS_URL=https://mapas.ejemplo.gob.ar/styles/basico/{z}/{x}/{y}.png
MAPAS_TESELAS_ATRIBUCION="© OpenMapTiles © OpenStreetMap contributors"
MAPAS_TESELAS_MAX_ZOOM=18
```

Las teselas las piden los celulares y el navegador, así que en producción van detrás de un proxy con HTTPS (ver la guía).
