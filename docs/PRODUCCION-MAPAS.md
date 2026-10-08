# Mapas en producción sin servicios pagos (Catamarca o todo el país)

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

Datos: el extracto de Argentina de Geofabrik, para una de dos **regiones** (ver la sección 2 bis):

- `catamarca` (por defecto): recortado a la provincia (bbox de la relación OSM 153545 más ~0,1° de margen:
  `-69.2,-30.22,-64.68,-25.07`). Fuera de ese recuadro no hay mapa, búsquedas ni rutas.
- `argentina`: el extracto completo, sin recorte. Sirve para los **viajes largos a otras provincias** (recorrido y
  duración Catamarca → Córdoba, búsqueda de destinos fuera de la provincia, mapa de todo el país).

Además se puede sumar **Georef** (API del Estado, `apis.datos.gob.ar/georef`) como segundo buscador: resuelve
intersecciones ("Sarmiento y Rivadavia") aunque en Catamarca no tiene alturas. Es un servicio público gratuito;
`LUGARES_DRIVER=nominatim,georef` consulta los dos y une los resultados.

## 2. Recursos estimados (región `catamarca`)

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

## 2 bis. Todo el país (región `argentina`)

Con `catamarca` no hay recorrido, duración ni búsqueda fuera de la provincia, y el mapa de fondo termina en el límite.
Para los **viajes largos a otras provincias** se prepara la región `argentina`: OSRM y Nominatim usan el extracto
completo de Geofabrik, sin recorte, y las teselas cubren el país (`-73.6,-55.1,-53.6,-21.7`).

```bash
cd infra/mapas
REGION=argentina ./preparar-datos.sh       # OSRM y teselas de todo el país (~32 minutos)
# en .env: MAPAS_REGION=argentina
docker compose up -d                       # la primera vez Nominatim importa el país (~64 minutos)
```

- **Recomendación para producción:** `argentina` si el organismo hace viajes a otras provincias (lo normal con viajes
  largos); `catamarca` solo si todos los viajes quedan dentro de la provincia y el servidor es chico.
- **El backend no cambia:** usa las mismas URLs y la misma configuración de la sección 4. Nominatim ya busca en todo el
  país (`countrycodes=ar`) y prioriza lo cercano al punto de partida, así que con `argentina` encuentra destinos de
  otras provincias sin tocar nada. `LUGARES_PROVINCIA` solo acota a Georef (el buscador complementario).
- Cada región deja sus archivos con su nombre (`datos/argentina.osm.pbf`, `datos/osrm/argentina.osrm.*`,
  `datos/teselas/argentina.mbtiles`) y comparten el extracto y las fuentes de Planetiler. Se puede preparar
  `argentina` con el servidor sirviendo `catamarca` y cambiar recién cuando esté lista.

### Recursos medidos

Medido el 2026-10-07 en la misma compu de desarrollo (16 hilos, 7,4 GB de RAM para Docker, con el servidor de
`catamarca` andando al lado), con el extracto de Geofabrik del 2026-10-02 y las fuentes de Planetiler ya bajadas:

| | `catamarca` | `argentina` | Para el servidor con `argentina` |
|---|---|---|---|
| **RAM en uso** (`docker stats`) | OSRM 110 MB · Nominatim ~0,5 GB · TileServer-GL 0,2–0,3 GB | OSRM 2,4 GB · Nominatim 0,9 GB recién arrancado (crece con la cache de PostgreSQL) · TileServer-GL 0,2 GB recién arrancado | **8 GB** de RAM total si el backend corre en la misma máquina |
| **RAM al preparar** (pico) | Planetiler ~0,8 GB · importación de Nominatim 1,1 GB | `osrm-extract` 5,0 GB · `osrm-partition` 1,8 GB · `osrm-customize` 3,5 GB · Planetiler 3,9 GB (`-Xmx3g`) · importación de Nominatim 4,4 GB | **6 GB libres** durante la preparación (los pasos van de a uno) |
| **Disco: datos** | ~2,1 GB | extracto 412 MB · copia `argentina.osm.pbf` 412 MB · OSRM 3,2 GB · `argentina.mbtiles` 838 MB · fuentes de Planetiler 1,4 GB (compartidas) | ~6,3 GB (más los temporales de Planetiler mientras corre) |
| **Disco: base de Nominatim** | 1,4 GB | 14,4 GB | |
| **Total de disco** (con las imágenes, 4,3 GB) | 10 GB | ~25 GB | **40 GB** para tener margen en las actualizaciones |
| **Tiempo de preparación** | ~15 min | Copia del extracto 2 s · OSRM 12 min 36 s (extract 7 min 52 s, partition 3 min 33 s, customize 1 min 11 s) · Planetiler 18 min 57 s (7 min de Natural Earth, 3 min 40 s de rutas y lugares) · total del script **31 min 37 s** · importación de Nominatim **63 min 48 s** | ~1 h 45 min la primera vez desde cero (más imágenes, descarga y fuentes de Planetiler); una segunda corrida sin cambios tarda 2 s |
| **Respuesta** | | Recorrido Catamarca → Córdoba en 0,13 s · tesela nueva 0,1–1,3 s · búsqueda 0,25–0,5 s | |

Los límites de memoria por defecto de `docker-compose.yml` ya alcanzan para `argentina` (`OSRM_MEMORIA` 4g,
`NOMINATIM_MEMORIA` 5g, `TESELAS_MEMORIA` 1g); con `catamarca` son solo topes y no ocupan más. Planetiler usa
`-Xmx3g` con `argentina` (`PLANETILER_RAM` para cambiarlo). Mientras Nominatim importa por primera vez
(`docker compose logs -f nominatim`) la búsqueda no responde, pero OSRM y las teselas ya funcionan.

### Cambiar de región

Con los datos de la región nueva ya preparados:

```bash
cd infra/mapas
# 1. En .env: MAPAS_REGION=argentina (o catamarca)
# 2. Nominatim tiene que reimportar: su base es de la región anterior.
docker compose rm -sf nominatim
docker volume rm mapas_nominatim-db
docker compose up -d                       # OSRM y teselas toman la región nueva en segundos
sudo find /var/cache/nginx/teselas -type f -delete     # vaciar la cache de teselas del proxy
```

Sin borrar el volumen, Nominatim sigue con la base de la región anterior (no reimporta solo). El backend no se toca.
Después se pueden borrar los archivos de la región que ya no se usa (`datos/catamarca.*`, `datos/osrm/catamarca.*`,
`datos/teselas/catamarca.mbtiles`).

## 3. Instalación en un servidor Linux con Docker

Requisitos: Linux x86-64 (Debian 12 / Ubuntu 22.04 o 24.04), Docker Engine con el plugin `compose`, `curl`, `git`.

```bash
git clone <repositorio> /opt/control-vehiculos      # o copiar solo la carpeta infra/mapas
cd /opt/control-vehiculos/infra/mapas
./preparar-datos.sh                                  # descarga, recorte, OSRM y teselas (idempotente)
cp .env.example .env && nano .env                    # NOMINATIM_CLAVE_DB: obligatoria, una clave larga
docker compose up -d
docker compose ps                                    # esperar (healthy) en los tres servicios
```

- **Correr `preparar-datos.sh` antes del primer `docker compose up`.** Si no, Docker crea carpetas vacías donde van los
  archivos de datos y los servicios no arrancan (arreglo: `docker compose down`, borrar esas carpetas vacías de
  `datos/` y correr el script).
- `preparar-datos.sh` se puede volver a correr cuando se quiera: salta lo que ya está hecho. Si se corta (Ctrl+C, un
  corte de luz), al volver a correrlo rehace solo lo que no terminó: cada paso escribe en un temporal y lo pone en su
  lugar al terminar bien, y la descarga se verifica con el MD5 que publica Geofabrik. En Linux los contenedores del
  script corren con el usuario actual, así `datos/` no queda a nombre de root.
- Los servicios tienen `restart: unless-stopped` (vuelven solos después de reiniciar el servidor) y `healthcheck`:
  OSRM responde un `nearest`, Nominatim su `/status` y TileServer-GL dibuja una tesela de Catamarca.
  `docker compose ps` muestra `(healthy)` o `(unhealthy)`.
- Variables de `docker compose`, en `infra/mapas/.env` (ignorado por git; plantilla en `.env.example`):
  `NOMINATIM_CLAVE_DB` (**obligatoria**: clave interna de PostgreSQL; sin ella `docker compose` no arranca),
  `MAPAS_REGION` (`catamarca` por defecto, o `argentina`: la región preparada, ver la sección 2 bis),
  `MAPAS_ESCUCHA` (IP donde escuchan los puertos; por defecto `127.0.0.1`), `OSRM_PUERTO` (5001),
  `NOMINATIM_PUERTO` (8088), `TESELAS_PUERTO` (8089), `NOMINATIM_WORKERS` (2) y los límites de memoria
  `OSRM_MEMORIA` (4g), `NOMINATIM_MEMORIA` (5g) y `TESELAS_MEMORIA` (1g). Los límites tienen margen sobre lo medido
  con `argentina` (sección 2 bis); si se usa otro extracto más grande (`MAPAS_PBF_URL`), subirlos, sobre todo el de
  Nominatim durante la importación.
- Variables opcionales de `preparar-datos.sh`: `REGION` (`catamarca` por defecto, o `argentina`), `MAPAS_BBOX` (otro
  recuadro: con `catamarca` es el recorte, con `argentina` solo acota las teselas), `MAPAS_PBF_URL` (otro extracto;
  tiene que tener su `.md5` al lado, como en Geofabrik) y `PLANETILER_RAM` (memoria de Java para Planetiler; por
  defecto `2g` con `catamarca` y `3g` con `argentina`).

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
LUGARES_LIMITE_POR_MINUTO=120 # búsquedas por minuto y por usuario (30 por defecto)
RUTAS_DRIVER=osrm
RUTAS_OSRM_URL=http://127.0.0.1:5001
MAPAS_DRIVER=osrm             # tiempos de viaje con el mismo OSRM de RUTAS_OSRM_URL
MAPAS_TESELAS_URL=https://mapas.ejemplo.gob.ar/styles/basico/{z}/{x}/{y}.png
MAPAS_TESELAS_ATRIBUCION="© OpenMapTiles © OpenStreetMap contributors"
MAPAS_TESELAS_ATRIBUCION_URL=https://www.openstreetmap.org/copyright
MAPAS_TESELAS_TMS=false
MAPAS_TESELAS_MAX_ZOOM=18
```

En `backend/.env.example` el bloque está escrito sin acentos ni `©` (ese archivo se mantiene en ASCII, como el resto de
sus comentarios) y dice `"(c) OpenMapTiles (c) OpenStreetMap contributors"`. En el `.env` real conviene el texto de
arriba, con `©`: Laravel lo lee en UTF-8 sin problema.

Luego `php artisan config:cache` (si se usa) y reiniciar PHP y las colas. **No hace falta recompilar la app ni el
panel:** la app recibe el mapa de fondo (`teselas`: `url`, `atribucion`, `atribucion_url`, `tms`, `max_zoom`) y el
permiso de autocompletar (`lugares_autocompletar`) en `GET /api/configuracion`, y el mapa en vivo del panel usa la
misma configuración.

- Con un OSRM o Nominatim propio el backend **no** aplica la espera de 1 pedido por segundo (solo la aplica contra los
  servidores públicos).
- `LUGARES_LIMITE_POR_MINUTO` (30 por defecto) limita `/api/lugares` por usuario. Con autocompletar contra servidores
  propios cada pausa al escribir es una búsqueda, así que conviene ~120; con el Nominatim público, dejar 30.
- `MAPAS_TESELAS_MAX_ZOOM=18`: las teselas vectoriales llegan a z14 y TileServer-GL dibuja los niveles mayores a partir
  de ellas (calles y nombres se ven bien hasta z18).
- Si OSRM o Nominatim se caen, el backend deja de consultarlos un minuto y sigue funcionando (búsqueda vacía, sin
  recorrido, tiempos "sin dato"): nunca rompe un pedido.

**Comprobación de punta a punta:** lo que recibe la app tiene que mostrar el servidor propio. Con un token de prueba
(se crea con tinker y se borra al terminar):

```bash
cd backend
TOKEN="$(php artisan tinker --execute='echo App\Models\Usuario::first()->createToken("verificacion")->plainTextToken;')"
curl -s -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" https://api.ejemplo.gob.ar/api/configuracion
# Debe decir "lugares_autocompletar":true y "teselas":{"url":"https://mapas.ejemplo.gob.ar/styles/basico/{z}/{x}/{y}.png",...}
# (si dice tile.openstreetmap.org, el .env no se leyó: revisar las variables y repetir php artisan config:cache)
php artisan tinker --execute='Laravel\Sanctum\PersonalAccessToken::where("name", "verificacion")->delete();'
```

## 5. Seguridad y HTTPS

- Los puertos escuchan solo en `127.0.0.1`. **OSRM y Nominatim no se exponen a internet**: los consulta solo el backend.
  Si el backend está en otra máquina, usar `MAPAS_ESCUCHA=<IP de la red interna>` (una interfaz que no dé a internet).
- **Ojo con el firewall: Docker se saltea UFW**. Los puertos publicados por Docker se abren con sus
  propias reglas de iptables, antes que las de UFW, así que un `ufw deny 8088` **no** los cierra. Por eso los puertos
  escuchan en `127.0.0.1` o en una IP interna, nunca en `0.0.0.0`. Si hace falta filtrar por origen (por ejemplo,
  dejar entrar solo al backend `10.0.0.10` desde la red interna), las reglas van en la cadena `DOCKER-USER`:

  ```bash
  sudo iptables -I DOCKER-USER -i eth1 -p tcp -m multiport --dports 5000,8080 ! -s 10.0.0.10 -j DROP
  ```

  (`eth1` es la interfaz de la red interna. Los puertos son los de **dentro** del contenedor, porque `DOCKER-USER` ve
  el tráfico ya traducido; lo que llega desde el mismo servidor, como nginx a 127.0.0.1, no pasa por esa cadena. Hacerlas persistentes
  con `iptables-persistent` o el mecanismo de la distribución.)
- **Las teselas sí salen a internet**, porque las piden los celulares y los navegadores. La app en producción exige
  HTTPS, así que van detrás de un proxy inverso con certificado (por ejemplo Let's Encrypt) y con cache.
  `MAPAS_TESELAS_URL` tiene que ser `https://`: con `http://` el panel (servido por HTTPS) las bloquea como contenido
  mixto y Android no permite tráfico sin cifrar; en producción el backend lo avisa en el log.

Ejemplo de nginx (`/etc/nginx/sites-available/mapas`):

```nginx
proxy_cache_path /var/cache/nginx/teselas levels=1:2 keys_zone=teselas:50m max_size=2g inactive=30d use_temp_path=off;

server {
    listen 80;
    server_name mapas.ejemplo.gob.ar;
    location /.well-known/acme-challenge/ { root /var/www/html; }   # renovación de Let's Encrypt
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    http2 on;                      # nginx 1.25.1 o más nuevo; en uno anterior: "listen 443 ssl http2;"
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

Certificado: con el bloque del puerto 80 ya activo, `sudo certbot certonly --webroot -w /var/www/html -d
mapas.ejemplo.gob.ar` (paquete `certbot`). La renovación queda automática con el temporizador que instala el paquete;
agregar `--deploy-hook "systemctl reload nginx"` para que nginx tome el certificado nuevo.

TileServer-GL avisa al arrancar que no tiene `allowedHosts`/`--public_url` (protección contra encabezados `Host`
falsos). Solo importa para las respuestas JSON con URLs (`/styles.json`, TileJSON); con el proxy de arriba, que deja
pasar únicamente PNG, no aplica.

## 6. Probar cada servicio (salud)

Dos puntos de San Fernando del Valle de Catamarca:

```bash
# Estado de los healthchecks: los tres tienen que decir (healthy)
docker compose ps
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

Con la región `argentina`, además, algo fuera de la provincia (Catamarca → Córdoba capital):

```bash
# OSRM: medido el 2026-10-07, "distance":440616.6 (441 km) y "duration":19524.9 (5 h 25 min)
curl -s "http://127.0.0.1:5001/route/v1/driving/-65.779,-28.469;-64.183,-31.417?overview=false"
# Nominatim: un lugar de Córdoba (Patio Olmos, Av. Vélez Sarsfield 361)
curl -s "http://127.0.0.1:8088/search?q=Patio+Olmos&format=json&limit=1&countrycodes=ar"
# Teselas: el centro de Córdoba, "200 image/png"
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" "http://127.0.0.1:8089/styles/basico/14/5270/9699.png"
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
# con todo el país: REGION=argentina ./preparar-datos.sh --actualizar   (~32 minutos si hay extracto nuevo)
```

**Si dice "Sin cambios en Geofabrik", termina ahí**: no hay que reiniciar nada ni reimportar Nominatim. Si el recorte
cambió (el script lo avisa al final), aplicar los datos nuevos:

```bash
docker compose restart osrm teselas                    # toman los archivos nuevos (cortan unos segundos)
sudo find /var/cache/nginx/teselas -type f -delete     # vaciar la cache de teselas del proxy
docker compose rm -sf nominatim                        # para y borra el contenedor de Nominatim
docker volume rm mapas_nominatim-db                    # Nominatim reimporta el recorte nuevo al arrancar
docker compose up -d
```

- Mientras OSRM y TileServer-GL reinician (unos segundos) no hay recorridos ni teselas nuevas: el backend lo tolera
  (corta un minuto y sigue sin dato) y las teselas ya cacheadas en la app siguen viéndose.
- Mientras Nominatim reimporta (2–3 minutos con `catamarca`, ~1 hora con `argentina`) la búsqueda no responde; el
  backend lo tolera y, si está configurado, sigue contestando Georef.
- Sin vaciar la cache de nginx, las teselas viejas se siguen sirviendo hasta 7 días (`proxy_cache_valid`).
- Conviene hacerlo de noche (por ejemplo con `cron` el primer domingo del mes).

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
