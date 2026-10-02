#!/usr/bin/env bash
# Prepara los datos del servidor de mapas propio (OSRM, Nominatim y teselas) para la provincia de Catamarca.
#
#   ./preparar-datos.sh               # deja todo listo; si ya está hecho, no repite nada (idempotente)
#   ./preparar-datos.sh --actualizar  # vuelve a bajar el extracto de Geofabrik y rehace lo que cambió
#
# Pasos (cada uno se salta si su resultado ya existe y es más nuevo que su entrada):
#   1. Descarga argentina-latest.osm.pbf de Geofabrik.
#   2. Recorta Catamarca con osmium (bbox de la provincia + margen).
#   3. Prepara OSRM: osrm-extract (perfil car), osrm-partition y osrm-customize (algoritmo MLD).
#   4. Genera catamarca.mbtiles (teselas vectoriales OpenMapTiles) con Planetiler, acotado al recorte.
# Nominatim importa catamarca.osm.pbf solo, la primera vez que arranca `docker compose up -d`.
#
# Corre en Linux, en WSL y en Git Bash de Windows (con Docker Desktop). Necesita docker y curl.
set -euo pipefail

# --- Versiones fijas (cambiarlas acá y en docker-compose.yml) ---
IMAGEN_OSRM="ghcr.io/project-osrm/osrm-backend:v6.0.0"
IMAGEN_PLANETILER="ghcr.io/onthegomap/planetiler:0.10.2"
IMAGEN_OSMIUM="control-vehiculos/osmium:1.14"

# --- Zona ---
# Límite de la provincia de Catamarca en OpenStreetMap (relación 153545): lon -69.095 … -64.781, lat -30.120 … -25.169.
# Se agrega un margen de ~0,1° para que las rutas que entran y salen de la provincia sigan funcionando.
BBOX="${MAPAS_BBOX:--69.2,-30.22,-64.68,-25.07}"
PBF_URL="${MAPAS_PBF_URL:-https://download.geofabrik.de/south-america/argentina-latest.osm.pbf}"
NOMBRE="catamarca"

ACTUALIZAR=0
for arg in "$@"; do
    case "$arg" in
        --actualizar) ACTUALIZAR=1 ;;
        -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
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

dk() { MSYS_NO_PATHCONV=1 docker "$@"; }
paso() { printf '\n==> [%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
# Verdadero si $1 existe y es más nuevo que $2.
al_dia() { [ -s "$1" ] && [ "$1" -nt "$2" ]; }

docker info >/dev/null 2>&1 || { echo "Docker no responde. ¿Está iniciado (Docker Desktop / dockerd)?" >&2; exit 1; }

# 1. Extracto de Argentina
ARGENTINA="$DATOS/fuentes/argentina-latest.osm.pbf"
if [ ! -s "$ARGENTINA" ] || [ "$ACTUALIZAR" = 1 ]; then
    paso "Descargando $PBF_URL"
    # Si la descarga se corta o se traba (menos de 10 kB/s durante 1 minuto), reintenta y sigue desde donde quedó.
    BAJAR=(curl -fLR --retry 10 --retry-delay 5 --retry-all-errors --speed-limit 10000 --speed-time 60 -C -)
    if [ -s "$ARGENTINA" ]; then
        # Solo baja si Geofabrik tiene uno más nuevo.
        rm -f "$ARGENTINA.part"
        "${BAJAR[@]}" -z "$ARGENTINA" -o "$ARGENTINA.part" "$PBF_URL"
        if [ -s "$ARGENTINA.part" ]; then mv "$ARGENTINA.part" "$ARGENTINA"; else rm -f "$ARGENTINA.part"; echo "Sin cambios en Geofabrik."; fi
    else
        "${BAJAR[@]}" -o "$ARGENTINA.part" "$PBF_URL"
        mv "$ARGENTINA.part" "$ARGENTINA"
    fi
else
    paso "Extracto de Argentina ya descargado (usar --actualizar para bajar uno nuevo)"
fi

# 2. Recorte de Catamarca
RECORTE="$DATOS/$NOMBRE.osm.pbf"
if al_dia "$RECORTE" "$ARGENTINA"; then
    paso "Recorte $NOMBRE.osm.pbf al día"
else
    paso "Construyendo la imagen de osmium"
    dk build -q -t "$IMAGEN_OSMIUM" "$AQUI_DOCKER/osmium" >/dev/null
    paso "Recortando $NOMBRE (bbox $BBOX)"
    dk run --rm -v "$DATOS_DOCKER:/data" "$IMAGEN_OSMIUM" \
        extract --strategy smart --bbox "$BBOX" --overwrite \
        -o "/data/$NOMBRE.osm.pbf" /data/fuentes/argentina-latest.osm.pbf
fi

# 3. OSRM (MLD, perfil car)
OSRM_LISTO="$DATOS/osrm/$NOMBRE.osrm.cell_metrics"
if al_dia "$OSRM_LISTO" "$RECORTE"; then
    paso "Datos de OSRM al día"
else
    paso "OSRM: extract (perfil car)"
    rm -f "$DATOS/osrm/$NOMBRE".osrm*
    cp "$RECORTE" "$DATOS/osrm/$NOMBRE.osm.pbf"
    dk run --rm -v "$DATOS_DOCKER/osrm:/data" "$IMAGEN_OSRM" \
        osrm-extract -p /opt/car.lua "/data/$NOMBRE.osm.pbf"
    paso "OSRM: partition"
    dk run --rm -v "$DATOS_DOCKER/osrm:/data" "$IMAGEN_OSRM" osrm-partition "/data/$NOMBRE.osrm"
    paso "OSRM: customize"
    dk run --rm -v "$DATOS_DOCKER/osrm:/data" "$IMAGEN_OSRM" osrm-customize "/data/$NOMBRE.osrm"
    rm -f "$DATOS/osrm/$NOMBRE.osm.pbf"
fi

# 4. Teselas vectoriales con Planetiler (esquema OpenMapTiles). La primera vez baja además Natural Earth, los
#    polígonos de agua y las líneas de lagos (~1,4 GB, quedan en datos/fuentes y se reutilizan).
TESELAS="$DATOS/teselas/$NOMBRE.mbtiles"
if al_dia "$TESELAS" "$RECORTE"; then
    paso "Teselas $NOMBRE.mbtiles al día"
else
    paso "Planetiler: generando $NOMBRE.mbtiles"
    dk run --rm -e JAVA_TOOL_OPTIONS="-Xmx${PLANETILER_RAM:-2g}" -v "$DATOS_DOCKER:/data" "$IMAGEN_PLANETILER" \
        --osm-path="/data/$NOMBRE.osm.pbf" \
        --output="/data/teselas/$NOMBRE.mbtiles" --force \
        --bounds="$BBOX" \
        --download --download-dir=/data/fuentes --tmpdir=/data/tmp \
        --languages=es,en \
        --nodemap-type=sparsearray --storage=mmap
    rm -rf "$DATOS/tmp"/*
fi

paso "Listo. Tamaños:"
du -sh "$ARGENTINA" "$RECORTE" "$DATOS/osrm" "$TESELAS" 2>/dev/null || true
cat <<FIN

Siguiente paso:  docker compose up -d
  (la primera vez Nominatim importa $NOMBRE.osm.pbf: varios minutos; ver con  docker compose logs -f nominatim)
Si se actualizaron los datos (--actualizar), Nominatim necesita reimportar:
  docker compose down && docker volume rm mapas_nominatim-db && docker compose up -d
FIN
