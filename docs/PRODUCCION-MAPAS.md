# Mapas en producción sin servicios pagos (Catamarca)

Todo lo de mapas del sistema funciona con software libre y datos de OpenStreetMap en un **servidor propio**: sin
Google, sin cuotas y sin mandar ubicaciones a empresas. El servidor se entrega con Docker en
[`infra/mapas/`](../infra/mapas/) (inicio rápido en su `README.md`).

> **No usar los servidores públicos en producción.** `router.project-osrm.org`, `nominatim.openstreetmap.org` y
> `tile.openstreetmap.org` son de la comunidad OSM, sin garantía, con límites estrictos (Nominatim: 1 pedido por segundo
> y nada de autocompletar) y pueden bloquear a quien los use para un servicio. El backend los trae por defecto **solo
> para desarrollo y demos**.

## 1. Qué hace cada servicio

| Servicio | Software | Puerto local | Lo usa | Para qué |
|---|---|---|---|---|
| `osrm` | OSRM 6 (algoritmo MLD, perfil auto) | 5001 | backend | Recorrido con indicaciones (`route`) y tiempos de viaje de varios choferes a un punto (`table`): elegir el más cercano, estimar la llegada, duración de reservas |
| `nominatim` | Nominatim 5.3 + PostgreSQL 16 | 8088 | backend | Búsqueda de lugares y direcciones del destino (con servidor propio la app autocompleta) |
| `teselas` | TileServer-GL 5.6 | 8089 | **celulares y navegadores** (vía proxy HTTPS) | Mapa de fondo en PNG de 256 px, dibujado desde teselas vectoriales OpenMapTiles con el estilo libre *Basic* |

Datos: el extracto de Argentina de Geofabrik, recortado a Catamarca (bbox de la relación OSM 153545 más ~0,1° de
margen: `-69.2,-30.22,-64.68,-25.07`). Fuera de ese recuadro no hay mapa, búsquedas ni rutas.

Además se puede sumar **Georef** (API del Estado, `apis.datos.gob.ar/georef`) como segundo buscador: resuelve
intersecciones ("Sarmiento y Rivadavia") aunque en Catamarca no tiene alturas. Es un servicio público gratuito;
`LUGARES_DRIVER=nominatim,georef` consulta los dos y une los resultados.

## 2. Recursos estimados

Medido el 2026-10-02 en la compu de desarrollo (Windows 11, Docker Desktop con WSL2: 16 hilos y 7,4 GB de RAM para
Docker), con el extracto de Geofabrik de ese día:

| | Medición | Para el servidor |
|---|---|---|
| **RAM en uso** (`docker stats`) | OSRM 110 MB · Nominatim 460–500 MB (pico de 1,1 GB al importar) · TileServer-GL 210–330 MB | **2 GB** libres para los tres; 4 GB de RAM total si el backend corre en la misma máquina |
| **RAM al preparar** | Planetiler: ~0,8 GB después de cada GC, tope `-Xmx2g` | 3 GB libres durante la preparación |
| **CPU** | En reposo ~0 %. Una tesela nueva tarda 0,3–1,3 s en dibujarse (después la cachea el proxy) | 2 vCPU alcanzan; 4 aceleran la preparación |
| **Disco: imágenes Docker** | osrm-backend 100 MB · nominatim 1,5 GB · tileserver-gl 1,7 GB · planetiler 0,9 GB · osmium 0,1 GB | ~4,3 GB |
| **Disco: datos** (`infra/mapas/datos`) | Argentina 412 MB · fuentes de Planetiler 1,4 GB (agua 931 MB, Natural Earth 434 MB, lagos 81 MB) · recorte de Catamarca 19 MB · OSRM 147 MB · `catamarca.mbtiles` 43 MB | ~2,1 GB |
| **Disco: base de Nominatim** (volumen `mapas_nominatim-db`) | 1,4 GB | |
| **Total de disco** | | **10 GB** (20 GB para tener margen en las actualizaciones) |
| **Tiempo de preparación** | Imágenes 1 min 40 s · descarga de Argentina 45 s (a ~8 MB/s) · recorte 30 s · OSRM 18 s · Planetiler 7 min 51 s (de eso, 2 min 21 s bajando sus fuentes y 4 min procesando Natural Earth) · importación de Nominatim 2 min 15 s | **~15 minutos** la primera vez; una segunda corrida sin cambios tarda 1 s |

Después de la preparación se pueden borrar `datos/fuentes/*.zip` para ahorrar 1,4 GB, pero se vuelven a bajar en la
próxima actualización.

## 3. Instalación en un servidor Linux con Docker

Requisitos: Linux x86-64 (Debian 12 / Ubuntu 22.04 o 24.04), Docker Engine con el plugin `compose`, `curl`, `git`.

```bash
git clone <repositorio> /opt/control-vehiculos      # o copiar solo la carpeta infra/mapas
cd /opt/control-vehiculos/infra/mapas
./preparar-datos.sh                                  # descarga, recorte, OSRM y teselas (idempotente)
docker compose up -d
docker compose logs -f nominatim                     # esperar "Nominatim is ready to accept requests"
```

- `preparar-datos.sh` se puede volver a correr cuando se quiera: salta lo que ya está hecho.
- Los servicios tienen `restart: unless-stopped`: vuelven solos después de reiniciar el servidor.
- Variables opcionales de `docker compose` (en el entorno o en un archivo `infra/mapas/.env`):
  `MAPAS_ESCUCHA` (IP donde escuchan los puertos; por defecto `127.0.0.1`), `OSRM_PUERTO` (5001),
  `NOMINATIM_PUERTO` (8088), `TESELAS_PUERTO` (8089), `NOMINATIM_WORKERS` (2) y `NOMINATIM_CLAVE_DB`
  (clave interna de PostgreSQL: **cambiarla**).
- Variables opcionales de `preparar-datos.sh`: `MAPAS_BBOX` (otro recuadro), `MAPAS_PBF_URL` (otro extracto) y
  `PLANETILER_RAM` (memoria de Java para Planetiler; por defecto `2g`).

**Windows (para probar):** con Docker Desktop iniciado, el script corre igual desde **Git Bash** (usa rutas `C:/...` y
`MSYS_NO_PATHCONV=1` para los montajes) o desde **WSL2** (más rápido si el repo está en `~/`, no en `/mnt/c`). En
Docker Desktop la memoria de la máquina virtual de WSL limita a todos los contenedores (ver `.wslconfig`).

## 4. Apuntar el backend

En `backend/.env` del servidor (bloque "Producción sin Google" de `.env.example`):

```env
LUGARES_DRIVER=nominatim,georef
LUGARES_NOMINATIM_URL=http://127.0.0.1:8088
LUGARES_GEOREF_URL=https://apis.datos.gob.ar/georef/api
LUGARES_PROVINCIA=Catamarca
# LUGARES_AUTOCOMPLETAR=     # sin valor: con Nominatim propio y Georef la app autocompleta
RUTAS_DRIVER=osrm
RUTAS_OSRM_URL=http://127.0.0.1:5001
MAPAS_DRIVER=osrm             # tiempos de viaje con el mismo OSRM de RUTAS_OSRM_URL
MAPAS_TESELAS_URL=https://mapas.ejemplo.gob.ar/styles/basico/{z}/{x}/{y}.png
MAPAS_TESELAS_ATRIBUCION="© OpenMapTiles © OpenStreetMap contributors"
MAPAS_TESELAS_ATRIBUCION_URL=https://www.openstreetmap.org/copyright
MAPAS_TESELAS_TMS=false
MAPAS_TESELAS_MAX_ZOOM=18
```

Luego `php artisan config:cache` (si se usa) y reiniciar PHP y las colas. **No hace falta recompilar la app ni el
panel:** la app recibe el mapa de fondo (`teselas`: `url`, `atribucion`, `atribucion_url`, `tms`, `max_zoom`) y el
permiso de autocompletar (`lugares_autocompletar`) en `GET /api/configuracion`, y el mapa en vivo del panel usa la
misma configuración.

- Con un OSRM o Nominatim propio el backend **no** aplica la espera de 1 pedido por segundo (solo la aplica contra los
  servidores públicos).
- `MAPAS_TESELAS_MAX_ZOOM=18`: las teselas vectoriales llegan a z14 y TileServer-GL dibuja los niveles mayores a partir
  de ellas (calles y nombres se ven bien hasta z18).
- Si OSRM o Nominatim se caen, el backend deja de consultarlos un minuto y sigue funcionando (búsqueda vacía, sin
  recorrido, tiempos "sin dato"): nunca rompe un pedido.

## 5. Seguridad y HTTPS

- Los puertos escuchan solo en `127.0.0.1`. **OSRM y Nominatim no se exponen a internet**: los consulta solo el backend.
  Si el backend está en otra máquina, usar `MAPAS_ESCUCHA=<IP de la red interna>` y un firewall que solo deje entrar
  al backend.
- **Las teselas sí salen a internet**, porque las piden los celulares y los navegadores. La app en producción exige
  HTTPS, así que van detrás de un proxy inverso con certificado (por ejemplo Let's Encrypt) y con cache.

Ejemplo de nginx (`/etc/nginx/sites-available/mapas`):

```nginx
proxy_cache_path /var/cache/nginx/teselas levels=1:2 keys_zone=teselas:50m max_size=2g inactive=30d use_temp_path=off;

server {
    listen 443 ssl http2;
    server_name mapas.ejemplo.gob.ar;
    ssl_certificate     /etc/letsencrypt/live/mapas.ejemplo.gob.ar/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mapas.ejemplo.gob.ar/privkey.pem;

    # Solo las teselas PNG del estilo; nada más de TileServer-GL queda público.
    location ~ ^/styles/basico/\d+/\d+/\d+(@2x)?\.png$ {
        proxy_pass http://127.0.0.1:8089;
        proxy_cache teselas;
        proxy_cache_valid 200 7d;
        proxy_cache_use_stale error timeout updating;
        add_header Cache-Control "public, max-age=86400";
        add_header Access-Control-Allow-Origin "*";
        limit_except GET { deny all; }
    }
    location / { return 404; }
}
```

TileServer-GL avisa al arrancar que no tiene `allowedHosts`/`--public_url` (protección contra encabezados `Host`
falsos). Solo importa para las respuestas JSON con URLs (`/styles.json`, TileJSON); con el proxy de arriba, que deja
pasar únicamente PNG, no aplica.

## 6. Probar cada servicio (salud)

Dos puntos de San Fernando del Valle de Catamarca:

```bash
# OSRM: recorrido (debe responder "code":"Ok" con distance/duration)
curl -s "http://127.0.0.1:5001/route/v1/driving/-65.779,-28.469;-65.77,-28.46?overview=false"
# OSRM: tabla de duraciones (segundos)
curl -s "http://127.0.0.1:5001/table/v1/driving/-65.779,-28.469;-65.77,-28.46?annotations=duration"
# Nominatim: estado y búsqueda
curl -s "http://127.0.0.1:8088/status"
curl -s "http://127.0.0.1:8088/search?q=Catedral+Catamarca&format=json&limit=1"
# Teselas: debe dar "200 image/png"
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" "http://127.0.0.1:8089/styles/basico/14/5198/9543.png"
# Desde afuera, a través del proxy
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" "https://mapas.ejemplo.gob.ar/styles/basico/14/5198/9543.png"
```

Y desde el backend, con la configuración ya cargada:

```bash
php artisan tinker --execute='dump(app(App\Mapas\ServicioMapas::class)->duracionRuta(-28.469,-65.779,-28.46,-65.77));'
```

## 7. Actualizar los datos (mensual)

OpenStreetMap cambia todos los días y Geofabrik publica el extracto de Argentina a diario. Con una vez por mes alcanza:

```bash
cd /opt/control-vehiculos/infra/mapas
./preparar-datos.sh --actualizar          # baja el extracto solo si es más nuevo y rehace recorte, OSRM y teselas
docker compose restart osrm teselas       # toman los archivos nuevos
docker compose stop nominatim && docker compose rm -f nominatim
docker volume rm mapas_nominatim-db       # Nominatim reimporta el recorte nuevo al arrancar
docker compose up -d
```

Mientras Nominatim reimporta (unos minutos) la búsqueda no responde; el backend lo tolera y, si está configurado, sigue
contestando Georef. Conviene hacerlo de noche (por ejemplo con `cron` el primer domingo del mes).

## 8. Copias de seguridad

**No hacen falta.** Todo se regenera desde cero con `preparar-datos.sh` y `docker compose up -d`; no hay datos propios
del sistema en estos servicios. Basta con conservar este repositorio (o la carpeta `infra/mapas`).

## 9. Licencias y atribución

- **Datos:** © colaboradores de OpenStreetMap, licencia **ODbL 1.0**. Hay que mostrar la atribución en todo mapa y
  enlazar a <https://www.openstreetmap.org/copyright>: la app y el panel muestran `MAPAS_TESELAS_ATRIBUCION` con el
  enlace `MAPAS_TESELAS_ATRIBUCION_URL`. Los resultados de búsqueda y rutas también son datos derivados de OSM.
- **Esquema de teselas:** OpenMapTiles (generado con Planetiler), por eso se agrega "© OpenMapTiles" a la atribución.
- **Estilo Basic** (incluido en TileServer-GL, derivado de los Mapbox Open Styles): código BSD y diseño CC BY 3.0.
  Fuente Noto Sans (licencia OFL).
- **Software:** OSRM (BSD-2), Nominatim (GPL-2), TileServer-GL (BSD-2), Planetiler (Apache-2.0), osmium-tool (GPL-3).
  Se usan sin modificar, como servicios.
- Natural Earth (dominio público) y los polígonos de agua de osmdata.openstreetmap.de (ODbL) que baja Planetiler.

## 10. Alternativas

- **Teselas del IGN** (Instituto Geográfico Nacional, mapa oficial argentino). Se configuran sin tocar el código; el
  servidor del IGN usa la Y invertida (TMS):

  ```env
  MAPAS_TESELAS_URL=https://wms.ign.gob.ar/geoserver/gwc/service/tms/1.0.0/capabaseargenmap@EPSG%3A3857@png/{z}/{x}/{y}.png
  MAPAS_TESELAS_ATRIBUCION="Instituto Geográfico Nacional"
  MAPAS_TESELAS_ATRIBUCION_URL=https://www.ign.gob.ar
  MAPAS_TESELAS_TMS=true
  MAPAS_TESELAS_MAX_ZOOM=18
  ```

  Es gratis, pero es un servicio de terceros sin garantía (en las pruebas del 2026-10-02 no respondió, así que esta URL
  no se pudo comprobar: confirmar la vigente en <https://www.ign.gob.ar> antes de usarla). El servidor propio es la
  opción recomendada.
- **Georef** solo (`LUGARES_DRIVER=georef`): no requiere servidor, pero no encuentra alturas en Catamarca; mejor
  combinado con Nominatim.
- **Google** (`*_DRIVER=google`): sigue disponible, pero es pago.
