#!/usr/bin/env bash
# Prepara los datos del servidor de mapas propio (OSRM, Nominatim y teselas) para una región: la provincia de
# Catamarca (por defecto) o todo el país.
#
#   ./preparar-datos.sh                          # Catamarca; si ya está hecho, no repite nada (idempotente)
#   REGION=argentina ./preparar-datos.sh         # todo el país (viajes a otras provincias)
#   ./preparar-datos.sh --actualizar             # baja el extracto de Geofabrik si hay uno más nuevo y rehace lo que cambió
#
# Pasos (cada uno se salta si su resultado ya existe y es más nuevo que su entrada):
#   1. Descarga argentina-latest.osm.pbf de Geofabrik y verifica su MD5 (lo comparten las dos regiones).
#   2. Arma datos/<región>.osm.pbf: con catamarca recorta la provincia con osmium (bbox + margen); con argentina es
#      una copia del extracto completo, sin recorte.
#   3. Prepara OSRM: osrm-extract (perfil car), osrm-partition y osrm-customize (algoritmo MLD).
#   4. Genera <región>.mbtiles (teselas vectoriales OpenMapTiles) con Planetiler, acotado a los límites de la región.
# Nominatim importa <región>.osm.pbf solo, la primera vez que arranca `docker compose up -d` (con MAPAS_REGION en .env).
#
# Los archivos de cada región llevan su nombre (datos/catamarca.*, datos/argentina.*): preparar una no toca la otra,
# así que se puede preparar argentina mientras el servidor sigue sirviendo catamarca.
#
# Si se corta a mitad de camino (Ctrl+C, un corte de luz), basta con volver a correrlo: cada paso escribe en un
# archivo temporal y solo lo pone en su lugar cuando terminó bien.
#
# Corre en Linux, en WSL y en Git Bash de Windows (con Docker Desktop). Necesita docker, curl y md5sum.
set -euo pipefail

# --- Versiones fijas (cambiarlas acá y en docker-compose.yml) ---
IMAGEN_OSRM="ghcr.io/project-osrm/osrm-backend:v6.0.0"
IMAGEN_PLANETILER="ghcr.io/onthegomap/planetiler:0.10.2"
IMAGEN_OSMIUM="control-vehiculos/osmium:1.14"

# --- Región ---
REGION="${REGION:-catamarca}"
case "$REGION" in
    catamarca)
        # Límite de la provincia en OpenStreetMap (relación 153545): lon -69.095 … -64.781, lat -30.120 … -25.169.
        # Se agrega un margen de ~0,1° para que las rutas que entran y salen de la provincia sigan funcionando.
        BBOX="${MAPAS_BBOX:--69.2,-30.22,-64.68,-25.07}"
        RECORTAR=1
        RAM_PLANETILER="${PLANETILER_RAM:-2g}"
        ;;
    argentina)
        # Límite del país en OpenStreetMap (relación 286393): lon -73.56 … -53.64, lat -55.06 … -21.78, con margen.
        # Solo acota las teselas: OSRM y Nominatim usan el extracto completo de Geofabrik.
        BBOX="${MAPAS_BBOX:--73.6,-55.1,-53.6,-21.7}"
        RECORTAR=0
        RAM_PLANETILER="${PLANETILER_RAM:-3g}"
        ;;
    *) echo "REGION desconocida: $REGION (usar catamarca o argentina)" >&2; exit 2 ;;
esac
PBF_URL="${MAPAS_PBF_URL:-https://download.geofabrik.de/south-america/argentina-latest.osm.pbf}"
NOMBRE="$REGION"

ACTUALIZAR=0
for arg in "$@"; do
    case "$arg" in
        --actualizar) ACTUALIZAR=1 ;;
        -h|--help) sed -n '2,23p' "$0"; exit 0 ;;
        *) echo "Opción desconocida: $arg" >&2; exit 2 ;;
    esac
done

cd "$(dirname "$0")"
AQUI="$(pwd)"
DATOS="$AQUI/datos"
mkdir -p "$DATOS/fuentes" "$DATOS/osrm" "$DATOS/teselas" "$DATOS/tmp"

# En Git Bash (Windows) docker necesita rutas tipo C:/... y que MSYS no reescriba /data dentro del contenedor.
if [ -n "${MSYSTEM:-}" ] && pwd -W >/dev/null 2>&1; then
    # (MSYS_NO_PATHCONV se aplica solo a docker: curl y el resto sí necesitan la conversión de rutas.)
    DATOS_DOCKER="$(cd "$DATOS" && pwd -W)"
    AQUI_DOCKER="$(pwd -W)"
else
    DATOS_DOCKER="$DATOS"
    AQUI_DOCKER="$AQUI"
fi

# Los contenedores corren con el usuario actual, así lo que escriben en datos/ no queda a nombre de root
# (con Docker Desktop no cambia nada: los archivos quedan a nombre del usuario de Windows).
COMO_YO=(--user "$(id -u):$(id -g)" -e HOME=/tmp)
dk() { MSYS_NO_PATHCONV=1 docker "$@"; }
paso() { printf '\n==> [%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
falla() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }
# Verdadero si $1 existe y es más nuevo que $2.
al_dia() { [ -s "$1" ] && [ "$1" -nt "$2" ]; }

docker info >/dev/null 2>&1 || falla "Docker no responde. ¿Está iniciado (Docker Desktop / dockerd)?"

# Restos de una corrida que se cortó: nunca se usan, se rehacen.
#    (Cada región tiene su carpeta de temporales de Planetiler, así no se pisan.)
TMP="$DATOS/tmp/$NOMBRE"
mkdir -p "$TMP"
rm -f "$DATOS/$NOMBRE.tmp.osm.pbf" "$DATOS/teselas/$NOMBRE.tmp.mbtiles"*
rm -rf "${TMP:?}/"*

# 1. Extracto de Argentina, verificado con el MD5 que publica Geofabrik.
ARGENTINA="$DATOS/fuentes/argentina-latest.osm.pbf"
# Un .part que quedó de una descarga cortada solo sirve si todavía no hay extracto (se sigue desde ahí); si ya hay
# uno, ocupa cientos de MB sin uso (y --actualizar empieza de cero), así que se borra.
if [ -s "$ARGENTINA" ]; then rm -f "$ARGENTINA.part"; fi
# Si la descarga se corta o se traba (menos de 10 kB/s durante 1 minuto), reintenta. Sin -R: el archivo queda con la
# fecha de descarga, que es la que comparan -z (¿hay uno más nuevo en Geofabrik?) y los pasos siguientes.
BAJAR=(curl -fL --retry 10 --retry-delay 5 --retry-all-errors --speed-limit 10000 --speed-time 60)

md5_correcto() {
    local esperado real
    esperado="$(curl -fsSL --retry 5 "$PBF_URL.md5" | awk '{print $1}')" || falla "No se pudo bajar $PBF_URL.md5"
    real="$(md5sum "$1" | awk '{print $1}')"
    [ -n "$esperado" ] && [ "$esperado" = "$real" ]
}

# Deja en $ARGENTINA un extracto verificado; con "-z archivo" no deja nada si Geofabrik no tiene uno más nuevo.
bajar_argentina() {
    local parte="$ARGENTINA.part"
    # -C -: si quedó un .part de una corrida cortada, sigue desde donde quedó.
    "${BAJAR[@]}" -C - "$@" -o "$parte" "$PBF_URL"
    [ -s "$parte" ] || { rm -f "$parte"; return 0; }
    if ! md5_correcto "$parte"; then
        # Un .part de una versión anterior del extracto, o una descarga dañada: se baja de nuevo desde cero.
        echo "El MD5 no coincide: se descarga de nuevo desde cero."
        rm -f "$parte"
        "${BAJAR[@]}" "$@" -o "$parte" "$PBF_URL"
        if ! md5_correcto "$parte"; then
            rm -f "$parte"
            falla "El extracto descargado está dañado (no coincide con $PBF_URL.md5). Probá de nuevo más tarde."
        fi
    fi
    mv "$parte" "$ARGENTINA"
}

if [ ! -s "$ARGENTINA" ]; then
    paso "Descargando $PBF_URL"
    bajar_argentina
elif [ "$ACTUALIZAR" = 1 ]; then
    paso "Buscando un extracto más nuevo en $PBF_URL"
    antes="$(date -r "$ARGENTINA" +%s)"
    bajar_argentina -z "$ARGENTINA"
    if [ "$antes" = "$(date -r "$ARGENTINA" +%s)" ]; then echo "Sin cambios en Geofabrik."; fi
else
    paso "Extracto de Argentina ya descargado (usar --actualizar para bajar uno nuevo)"
fi

# 2. Recorte de Catamarca
RECORTE="$DATOS/$NOMBRE.osm.pbf"
RECORTE_NUEVO=0
if al_dia "$RECORTE" "$ARGENTINA"; then
    paso "Recorte $NOMBRE.osm.pbf al día"
elif [ "$RECORTAR" = 0 ]; then
    # Todo el país: sin recorte, una copia del extracto (no un enlace: tiene que quedar con fecha propia para que
    # el paso se salte en la próxima corrida y se rehaga cuando --actualizar baje uno nuevo).
    paso "Copiando el extracto completo como $NOMBRE.osm.pbf (sin recorte)"
    cp "$ARGENTINA" "$DATOS/$NOMBRE.tmp.osm.pbf"
    mv "$DATOS/$NOMBRE.tmp.osm.pbf" "$RECORTE"
    RECORTE_NUEVO=1
else
    paso "Construyendo la imagen de osmium"
    dk build -q -t "$IMAGEN_OSMIUM" "$AQUI_DOCKER/osmium" >/dev/null
    paso "Recortando $NOMBRE (bbox $BBOX)"
    dk run --rm "${COMO_YO[@]}" -v "$DATOS_DOCKER:/data" "$IMAGEN_OSMIUM" \
        extract --strategy smart --bbox "$BBOX" --overwrite \
        -o "/data/$NOMBRE.tmp.osm.pbf" /data/fuentes/argentina-latest.osm.pbf
    mv "$DATOS/$NOMBRE.tmp.osm.pbf" "$RECORTE"
    RECORTE_NUEVO=1
fi

# 3. OSRM (MLD, perfil car). Son varios archivos: la marca .listo se crea solo cuando terminaron los tres pasos.
OSRM_LISTO="$DATOS/osrm/$NOMBRE.listo"
if al_dia "$OSRM_LISTO" "$RECORTE"; then
    paso "Datos de OSRM al día"
else
    paso "OSRM: extract (perfil car)"
    rm -f "$OSRM_LISTO" "$DATOS/osrm/$NOMBRE".osrm*
    cp "$RECORTE" "$DATOS/osrm/$NOMBRE.osm.pbf"
    dk run --rm "${COMO_YO[@]}" -v "$DATOS_DOCKER/osrm:/data" "$IMAGEN_OSRM" \
        osrm-extract -p /opt/car.lua "/data/$NOMBRE.osm.pbf"
    paso "OSRM: partition"
    dk run --rm "${COMO_YO[@]}" -v "$DATOS_DOCKER/osrm:/data" "$IMAGEN_OSRM" osrm-partition "/data/$NOMBRE.osrm"
    paso "OSRM: customize"
    dk run --rm "${COMO_YO[@]}" -v "$DATOS_DOCKER/osrm:/data" "$IMAGEN_OSRM" osrm-customize "/data/$NOMBRE.osrm"
    rm -f "$DATOS/osrm/$NOMBRE.osm.pbf"
    date > "$OSRM_LISTO"
fi

# 4. Teselas vectoriales con Planetiler (esquema OpenMapTiles). La primera vez baja además Natural Earth, los
#    polígonos de agua y las líneas de lagos (~1,4 GB, quedan en datos/fuentes y se reutilizan).
#    El temporal termina en .mbtiles porque Planetiler elige el formato de salida por la extensión.
TESELAS="$DATOS/teselas/$NOMBRE.mbtiles"
if al_dia "$TESELAS" "$RECORTE"; then
    paso "Teselas $NOMBRE.mbtiles al día"
else
    paso "Planetiler: generando $NOMBRE.mbtiles"
    dk run --rm "${COMO_YO[@]}" -e JAVA_TOOL_OPTIONS="-Xmx$RAM_PLANETILER" -v "$DATOS_DOCKER:/data" \
        "$IMAGEN_PLANETILER" \
        --osm-path="/data/$NOMBRE.osm.pbf" \
        --output="/data/teselas/$NOMBRE.tmp.mbtiles" --force \
        --bounds="$BBOX" \
        --download --download-dir=/data/fuentes --tmpdir="/data/tmp/$NOMBRE" \
        --languages=es,en \
        --nodemap-type=sparsearray --storage=mmap
    mv "$DATOS/teselas/$NOMBRE.tmp.mbtiles" "$TESELAS"
    rm -rf "${TMP:?}/"*
fi

paso "Listo ($NOMBRE). Tamaños:"
du -sh "$ARGENTINA" "$RECORTE" "$TESELAS" 2>/dev/null || true
printf '%s\t%s\n' "$(du -ch "$DATOS/osrm/$NOMBRE".* 2>/dev/null | tail -n 1 | cut -f 1)" "$DATOS/osrm/$NOMBRE.osrm.*"
echo
echo "Siguiente paso: docker compose up -d  (antes, copiar .env.example a .env y poner NOMINATIM_CLAVE_DB$( \
    [ "$NOMBRE" = catamarca ] || echo " y MAPAS_REGION=$NOMBRE"))."
echo "La primera vez Nominatim importa $NOMBRE.osm.pbf: docker compose logs -f nominatim"
if [ "$RECORTE_NUEVO" = 1 ] && [ "$ACTUALIZAR" = 1 ]; then
    echo
    echo "El recorte cambió. Si el servidor ya estaba andando, aplicar los datos nuevos con:"
    echo "  docker compose restart osrm teselas"
    echo "  docker compose rm -sf nominatim && docker volume rm mapas_nominatim-db && docker compose up -d"
fi
