# osmium-tool para recortar el extracto de la provincia. No hay imagen oficial: se arma desde Debian estable
# (bookworm trae osmium-tool 1.14). La construye preparar-datos.sh con la etiqueta control-vehiculos/osmium:1.14.
FROM debian:bookworm-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends osmium-tool \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /data
ENTRYPOINT ["osmium"]
