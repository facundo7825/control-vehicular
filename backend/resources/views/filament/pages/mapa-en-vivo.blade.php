<x-filament-panels::page>
    @php
        $proveedor = $this->claveGoogle() ? 'google' : 'leaflet';
        $sinUbicacion = $this->choferesSinUbicacion();
    @endphp

    @if ($proveedor === 'leaflet')
        <link rel="stylesheet" href="{{ \App\Filament\Pages\MapaEnVivo::LEAFLET_CSS }}"
              integrity="{{ \App\Filament\Pages\MapaEnVivo::LEAFLET_CSS_SRI }}" crossorigin="anonymous">
    @endif

    <div wire:poll.10s="refrescar">
        {{-- Buscador: wire:ignore para que el polling no le borre el texto ni la lista; lo maneja el script. --}}
        <div id="buscador-choferes" wire:ignore style="position: relative; margin-bottom: 0.75rem;">
            <x-filament::input.wrapper>
                <x-filament::input type="search" id="buscador-choferes-campo" autocomplete="off"
                                   placeholder="Buscar chofer (nombre o patente)"
                                   aria-label="Buscar chofer (nombre o patente)" />
            </x-filament::input.wrapper>
            {{-- Estilos propios (no los del dropdown de Filament, que limita el ancho y corta el texto). --}}
            <style>
                .mapa-en-vivo-lista { position: absolute; top: calc(100% + 0.25rem); left: 0; right: 0; z-index: 20; /* encima del mapa (z-index 0) */
                    max-height: 20rem; overflow-y: auto; padding: 0.25rem; border-radius: 0.5rem; background: #ffffff; color: #111827;
                    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 0 0 1px rgb(0 0 0 / 0.05); }
                .dark .mapa-en-vivo-lista { background: #18181b; color: #f4f4f5; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.4), 0 0 0 1px rgb(255 255 255 / 0.1); }
                .mapa-en-vivo-fila { display: flex; gap: 0.5rem; align-items: flex-start; width: 100%; padding: 0.5rem; border-radius: 0.375rem;
                    text-align: start; font-size: 0.875rem; line-height: 1.25rem; white-space: normal; overflow-wrap: anywhere; }
                button.mapa-en-vivo-fila:hover, button.mapa-en-vivo-fila:focus-visible { background: rgb(0 0 0 / 0.05); outline: none; }
                .dark button.mapa-en-vivo-fila:hover, .dark button.mapa-en-vivo-fila:focus-visible { background: rgb(255 255 255 / 0.08); }
                .mapa-en-vivo-fila-sin-ubicacion { cursor: default; }
                .mapa-en-vivo-detalle { opacity: 0.85; }
                .mapa-en-vivo-icono { filter: drop-shadow(0 1px 1.5px rgb(0 0 0 / 0.45)); }
            </style>
            <div id="buscador-choferes-lista" class="mapa-en-vivo-lista" hidden></div>
        </div>

        {{-- position/z-index: los paneles de Leaflet no tapan la barra superior ni los modales de Filament. --}}
        <div id="mapa-en-vivo" wire:ignore data-proveedor="{{ $proveedor }}"
             @if ($proveedor === 'leaflet')
                 data-leaflet-js="{{ \App\Filament\Pages\MapaEnVivo::LEAFLET_JS }}"
                 data-leaflet-sri="{{ \App\Filament\Pages\MapaEnVivo::LEAFLET_JS_SRI }}"
             @endif
             style="height: 70vh; width: 100%; border-radius: 0.75rem; position: relative; z-index: 0;"></div>
        <p style="margin-top: 0.5rem; font-size: 0.875rem; opacity: 0.75;">
            Choferes en turno (autos): verde libre · azul en viaje · ámbar reservado pronto · gris sin señal.
            Viajes: línea del origen (punto naranja, donde está el usuario) al destino (pin rojo). Se actualiza cada 10 segundos.
            Tocá un viaje para resaltar su recorrido; un clic en el mapa vacío o Escape lo quita.
        </p>

        @if (count($sinUbicacion) > 0)
            <x-filament::section heading="Sin ubicación todavía" style="margin-top: 1rem;">
                <p style="font-size: 0.875rem; opacity: 0.75; margin-bottom: 0.5rem;">
                    Tienen el turno abierto pero la app todavía no mandó ninguna ubicación, así que no se pueden mostrar en el mapa.
                </p>
                <ul>
                    @foreach ($sinUbicacion as $chofer)
                        <li wire:key="sin-ubicacion-{{ $chofer['id'] }}">
                            {{ $chofer['nombre'] }} · {{ $chofer['patente'] ?? 'sin vehículo' }} · {{ $chofer['estado_etiqueta'] }}
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    </div>

    @script
    <script>
        const datosIniciales = @js($this->datosMapa());
        const clave = @js($this->claveGoogle());
        const contenedor = document.getElementById('mapa-en-vivo');
        const centroPorDefecto = { lat: -34.6037, lng: -58.3816 };
        const ubicados = (choferes) => choferes.filter((c) => c.lat !== null && c.lng !== null);

        // buscador:logica-pura (inicio) — sin DOM ni mapa; se prueba aparte con node.
        // Minúsculas, sin acentos y con los espacios colapsados: "José  PÉREZ" → "jose perez".
        const normalizar = (texto) => String(texto ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/\s+/g, ' ').trim();
        // Coincide por nombre o por patente; la patente se compara sin espacios ni guiones ("AB 123-CD" = "ab123cd").
        const coincide = (chofer, consulta) => {
            const buscado = normalizar(consulta);
            if (buscado === '') {
                return true;
            }
            const compacta = (texto) => texto.replace(/[\s-]/g, '');

            return normalizar(chofer.nombre).includes(buscado)
                || (chofer.patente != null && compacta(buscado) !== ''
                    && compacta(normalizar(chofer.patente)).includes(compacta(buscado)));
        };
        const tieneUbicacion = (chofer) => chofer.lat !== null && chofer.lng !== null;
        const buscarChoferes = (choferes, consulta, limite = 8) => normalizar(consulta) === ''
            ? []
            : choferes.filter((c) => coincide(c, consulta)).slice(0, limite);
        // Lo que elige Enter: la primera coincidencia que se puede mostrar en el mapa (o null).
        const primeraConUbicacion = (choferes, consulta) => normalizar(consulta) === ''
            ? null
            : choferes.find((c) => tieneUbicacion(c) && coincide(c, consulta)) ?? null;
        // buscador:logica-pura (fin)

        // Se escucha desde ya (la librería puede tardar en cargar): se guarda el último dato y, cuando el mapa
        // está listo, se aplica ese y los que vengan. Después se vuelve a aplicar el filtro del buscador.
        let ultimosDatos = datosIniciales;
        let aplicar = null;
        // Los pone el mapa cuando está listo: atenuar a los que no coinciden e ir a un chofer.
        let filtrarMarcadores = null;
        let irAChofer = null;
        let refrescarBuscador = () => {};
        $wire.$on('mapa-datos', ({ datos }) => {
            ultimosDatos = datos;
            aplicar?.(datos);
            refrescarBuscador();
        });

        const mostrarError = () => {
            contenedor.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;'
                + 'text-align:center;padding:1rem;font-size:0.875rem;opacity:0.75;">'
                + 'No se pudo cargar el mapa. Revisá la conexión a internet y recargá la página.</div>';
        };

        // globos:logica-pura (inicio) — sin DOM ni mapa; se prueba aparte con node.
        const escapar = (texto) => String(texto ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        // La llegada estimada del viaje actual del chofer (los minutos vienen del servidor, redondeados hacia arriba).
        const textoLlegada = (viaje) => {
            if (viaje.estado === 'llego') {
                return 'ya está en el origen';
            }
            if (viaje.llega_en_min == null) {
                return 'sin estimación';
            }

            return viaje.llega_en_min < 1 ? 'llega en menos de 1 min' : `llega en ≈ ${viaje.llega_en_min} min`;
        };
        const SEPARADOR_GLOBO = '<hr style="margin: 0.4rem 0; opacity: 0.3;">';

        // Nombre, vehículo, estado y última ubicación; el viaje actual (con enlace y "Ver recorrido") y lo que hizo hoy.
        const globoChofer = (c) => {
            let html = `<strong>${escapar(c.nombre)}</strong><br>`
                + `Vehículo: ${escapar(c.patente ?? 'sin vehículo')}<br>`
                + `Estado: ${escapar(c.estado_etiqueta)}<br>`
                + `Última ubicación: ${escapar(c.actualizado_hace)} (${escapar(c.actualizado_en)})`;
            if (c.viaje) {
                html += SEPARADOR_GLOBO
                    + `<strong>Viaje #${escapar(c.viaje.id)}</strong> · ${escapar(c.viaje.estado_etiqueta)}`
                    + ` · <a href="${escapar(c.viaje.url)}">Ver viaje</a><br>`
                    + `Solicitante: ${escapar(c.viaje.solicitante ?? '—')}<br>`
                    + `Hacia ${c.viaje.hacia === 'destino' ? 'el destino' : 'el origen'}: ${escapar(c.viaje.hacia_direccion ?? 'sin dirección')}<br>`
                    + `${escapar(textoLlegada(c.viaje))}<br>`
                    + `<button type="button" data-resaltar-viaje="${escapar(c.viaje.id)}" style="text-decoration: underline;">Ver recorrido</button>`;
            }
            if (c.hoy) {
                html += SEPARADOR_GLOBO
                    + `<strong>Hoy:</strong> ${escapar(c.hoy.viajes)} ${c.hoy.viajes === 1 ? 'viaje finalizado' : 'viajes finalizados'}`
                    + ` · ${escapar(String(c.hoy.km).replace('.', ','))} km<br>`
                    + (c.hoy.turno_desde ? `En turno desde ${escapar(c.hoy.turno_desde)}<br>` : '');
            }

            return html + (c.url ? `<a href="${escapar(c.url)}">Ver chofer</a>` : '');
        };

        const globoViaje = (v, punto, letra) => `<strong>Viaje #${escapar(v.id)}</strong> · ${escapar(v.estado_etiqueta)}`
            + `${v.url ? ` · <a href="${escapar(v.url)}">Ver viaje</a>` : ''}<br>`
            + `Chofer: ${escapar(v.chofer)}<br>`
            + `${letra === 'O' ? 'Origen' : 'Destino'}: ${escapar(punto.direccion ?? 'sin dirección')}`;

        // Resaltado de un viaje: ese más grueso y opaco, los demás atenuados; sin resaltado, todos normales.
        const OPACIDAD_VIAJE_ATENUADO = 0.25;
        const nivelResaltado = (id, resaltadoId) => {
            if (resaltadoId === null) {
                return 'normal';
            }

            return id === resaltadoId ? 'resaltado' : 'atenuado';
        };
        // Grosor y opacidad de la línea según el nivel (sirve para Leaflet y para Google).
        const ajustarEstilo = (grosor, opacidad, nivel) => {
            if (nivel === 'resaltado') {
                return { grosor: grosor + 3, opacidad: 1 };
            }

            return { grosor, opacidad: nivel === 'atenuado' ? Math.min(opacidad, OPACIDAD_VIAJE_ATENUADO) : opacidad };
        };
        // En cada actualización el resaltado sigue mientras el viaje siga activo; si ya no está, se quita.
        const resaltadoVigente = (viajes, resaltadoId) => resaltadoId !== null && viajes.some((v) => v.id === resaltadoId)
            ? resaltadoId
            : null;
        // globos:logica-pura (fin)

        // El viaje resaltado (id o null) y las funciones que pone el mapa cuando está listo.
        let viajeResaltado = null;
        let resaltarViaje = null;
        let quitarResaltado = null;
        // "Ver recorrido" del globo del chofer. En captura: los globos de Leaflet cortan la propagación del clic.
        contenedor.addEventListener('click', (evento) => {
            const boton = evento.target.closest?.('[data-resaltar-viaje]');
            if (boton) {
                evento.preventDefault();
                resaltarViaje?.(Number(boton.dataset.resaltarViaje));
            }
        }, true);
        // Escape quita el resaltado. El listener se quita solo cuando la página ya no está, así no se acumulan.
        const quitarConEscape = (evento) => {
            if (! contenedor.isConnected) {
                document.removeEventListener('keydown', quitarConEscape);

                return;
            }
            if (evento.key === 'Escape') {
                quitarResaltado?.();
            }
        };
        document.addEventListener('keydown', quitarConEscape);

        // Íconos SVG (los mismos para Leaflet y Google): el chofer es un auto (Material "directions_car") blanco
        // sobre un círculo del color de su estado; el origen del viaje (el usuario) un punto naranja; el destino un pin rojo.
        // La sombra: en Leaflet con CSS (clase mapa-en-vivo-icono); en Google, dentro de cada imagen data: (documento aparte).
        const TAMANO_AUTO = 36;
        const svgAuto = (color) => `<svg xmlns="http://www.w3.org/2000/svg" width="${TAMANO_AUTO}" height="${TAMANO_AUTO}" viewBox="0 0 36 36">`
            + `<circle cx="18" cy="18" r="15" fill="${escapar(color)}" stroke="#ffffff" stroke-width="2"/>`
            + '<path transform="translate(8.4 8.4) scale(0.8)" fill="#ffffff" d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>'
            + '</svg>';
        const TAMANO_PUNTO = 22;
        const svgPunto = () => `<svg xmlns="http://www.w3.org/2000/svg" width="${TAMANO_PUNTO}" height="${TAMANO_PUNTO}" viewBox="0 0 22 22">`
            + '<circle cx="11" cy="11" r="7" fill="#f97316" stroke="#ffffff" stroke-width="3"/></svg>';
        // Pin de 32 px: la punta (y = 22 de 24 en el viewBox) queda a 29 px, que es el anclaje.
        const TAMANO_PIN = 32;
        const PUNTA_PIN = 29;
        const svgPin = () => `<svg xmlns="http://www.w3.org/2000/svg" width="${TAMANO_PIN}" height="${TAMANO_PIN}" viewBox="0 0 24 24">`
            + '<path fill="#dc2626" stroke="#ffffff" stroke-width="1.2" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>'
            + '</svg>';
        const urlSvg = (svg) => {
            const conSombra = svg.replace(/^(<svg[^>]*>)([\s\S]*)<\/svg>$/, '$1<defs><filter id="sombra" x="-50%" y="-50%" width="200%" height="200%">'
                + '<feDropShadow dx="0" dy="1" stdDeviation="1.2" flood-opacity="0.45"/></filter></defs><g filter="url(#sombra)">$2</g></svg>');

            return `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(conSombra)}`;
        };
        const OPACIDAD_ATENUADO = 0.3;
        // Al encuadrar un viaje resaltado: margen en píxeles y zoom máximo (igual en Leaflet y en Google).
        const MARGEN_ENCUADRE = 40;
        const ZOOM_MAXIMO_ENCUADRE = 16;

        // Buscador: filtra en el navegador mientras se escribe; lista hasta 8 coincidencias y atenúa al resto en el mapa.
        const campo = document.getElementById('buscador-choferes-campo');
        const lista = document.getElementById('buscador-choferes-lista');
        let coincidencias = [];

        const elegir = (chofer) => {
            if (! tieneUbicacion(chofer)) {
                return; // sin ubicación: figura en la lista pero no mueve el mapa
            }
            irAChofer?.(chofer);
            lista.hidden = true;
        };

        const mostrarLista = () => {
            const consulta = campo.value;
            coincidencias = buscarChoferes(ultimosDatos.choferes, consulta);
            if (normalizar(consulta) === '') {
                lista.hidden = true;
                lista.innerHTML = '';

                return;
            }
            // Dos renglones por chofer: el nombre y, abajo, patente · estado (con su color) · si no tiene ubicación.
            lista.innerHTML = coincidencias.length === 0
                ? '<div class="mapa-en-vivo-fila">Ningún chofer en turno coincide.</div>'
                : coincidencias.map((c, i) => {
                    const sinUbicacion = ! tieneUbicacion(c);

                    return `<button type="button" class="mapa-en-vivo-fila${sinUbicacion ? ' mapa-en-vivo-fila-sin-ubicacion' : ''}" data-indice="${i}">`
                        + `<span style="width:10px;height:10px;margin-top:0.3rem;border-radius:9999px;flex:none;background:${escapar(c.color)};"></span>`
                        + `<span><strong>${escapar(c.nombre)}</strong><br>`
                        + `<span class="mapa-en-vivo-detalle">${escapar(c.patente ?? 'sin vehículo')} · </span>`
                        + `<span style="color:${escapar(c.color)};font-weight:600;">${escapar(c.estado_etiqueta)}</span>`
                        + `${sinUbicacion ? '<span class="mapa-en-vivo-detalle"> · <em>sin ubicación todavía</em></span>' : ''}</span></button>`;
                }).join('');
            lista.hidden = false;
        };

        refrescarBuscador = () => {
            filtrarMarcadores?.(campo.value);
            if (! lista.hidden) {
                mostrarLista();
            }
        };

        const limpiar = () => {
            campo.value = '';
            mostrarLista();
            filtrarMarcadores?.('');
        };

        campo.addEventListener('input', () => {
            mostrarLista();
            filtrarMarcadores?.(campo.value);
        });
        campo.addEventListener('focus', () => {
            if (normalizar(campo.value) !== '') {
                mostrarLista();
            }
        });
        campo.addEventListener('keydown', (evento) => {
            if (evento.key === 'Enter') {
                evento.preventDefault();
                // Con los datos de ahora (no con la lista que quedó dibujada): el primero que está en el mapa.
                const elegido = primeraConUbicacion(ultimosDatos.choferes, campo.value);
                if (elegido) {
                    elegir(elegido);
                } else {
                    mostrarLista(); // ninguno tiene ubicación: la lista queda a la vista
                }
            } else if (evento.key === 'Escape') {
                limpiar();
            }
        });
        // El navegador vacía el campo type="search" con la cruz o con Escape: también vuelve todo a la normalidad.
        campo.addEventListener('search', () => {
            if (campo.value === '') {
                limpiar();
            }
        });
        lista.addEventListener('click', (evento) => {
            const boton = evento.target.closest('[data-indice]');
            if (boton) {
                elegir(coincidencias[Number(boton.dataset.indice)]);
            }
        });
        // Un clic fuera del buscador cierra la lista. El listener se quita solo cuando la página ya no está
        // (al volver a entrar se registra otro), así no se acumulan.
        const buscador = document.getElementById('buscador-choferes');
        const cerrarAlClicFuera = (evento) => {
            if (! buscador.isConnected) {
                document.removeEventListener('click', cerrarAlClicFuera);

                return;
            }
            if (! buscador.contains(evento.target)) {
                lista.hidden = true;
            }
        };
        document.addEventListener('click', cerrarAlClicFuera);

        const iniciarLeaflet = () => {
            const mapa = L.map(contenedor).setView([centroPorDefecto.lat, centroPorDefecto.lng], 12);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
            }).addTo(mapa);

            const marcadoresChofer = new Map();
            const choferPorId = new Map();
            const capasViaje = new Map();
            let encuadrado = false;

            const icono = (svg, tamano, anclaje) => L.divIcon({
                className: 'mapa-en-vivo-icono',
                html: svg,
                iconSize: [tamano, tamano],
                iconAnchor: anclaje,
                popupAnchor: [0, -anclaje[1]],
            });
            const iconoPunto = icono(svgPunto(), TAMANO_PUNTO, [TAMANO_PUNTO / 2, TAMANO_PUNTO / 2]);
            const iconoPin = icono(svgPin(), TAMANO_PIN, [TAMANO_PIN / 2, PUNTA_PIN]);
            const ESTILO_RECORRIDO = { color: '#2563eb', opacity: 0.8, weight: 4, dashArray: null };
            const ESTILO_RECTA = { color: '#2563eb', opacity: 0.6, weight: 3, dashArray: '6 8' };

            // Aplica el resaltado a todos los viajes dibujados: grosor y opacidad de la línea, y opacidad de sus marcadores.
            const aplicarResaltado = () => {
                capasViaje.forEach((capas, id) => {
                    const nivel = nivelResaltado(id, viajeResaltado);
                    const { grosor, opacidad } = ajustarEstilo(capas.base.weight, capas.base.opacity, nivel);
                    capas.linea.setStyle({ ...capas.base, weight: grosor, opacity: opacidad });
                    if (nivel === 'resaltado') {
                        capas.linea.bringToFront();
                    }
                    [capas.origen, capas.destino].forEach((m) => m.setOpacity(nivel === 'atenuado' ? OPACIDAD_ATENUADO : 1));
                });
            };
            resaltarViaje = (id) => {
                const capas = capasViaje.get(id);
                if (! capas) {
                    return;
                }
                viajeResaltado = id;
                aplicarResaltado();
                const limites = capas.linea.getBounds().extend(capas.origen.getLatLng()).extend(capas.destino.getLatLng());
                mapa.fitBounds(limites, { padding: [MARGEN_ENCUADRE, MARGEN_ENCUADRE], maxZoom: ZOOM_MAXIMO_ENCUADRE });
            };
            quitarResaltado = () => {
                if (viajeResaltado !== null) {
                    viajeResaltado = null;
                    aplicarResaltado();
                }
            };
            // Tocar el mapa vacío quita el resaltado (las líneas y los marcadores no pasan su clic al mapa).
            mapa.on('click', () => quitarResaltado());

            const actualizar = (datos) => {
                const limites = L.latLngBounds([]);
                viajeResaltado = resaltadoVigente(datos.viajes, viajeResaltado);

                // Choferes: se mueven los marcadores existentes en lugar de recrearlos; el ícono cambia solo si cambió el color.
                const choferesVistos = new Set();
                ubicados(datos.choferes).forEach((c) => {
                    const posicion = [c.lat, c.lng];
                    choferesVistos.add(c.id);
                    limites.extend(posicion);
                    const iconoAuto = () => icono(svgAuto(c.color), TAMANO_AUTO, [TAMANO_AUTO / 2, TAMANO_AUTO / 2]);
                    let marcador = marcadoresChofer.get(c.id);
                    if (! marcador) {
                        marcador = L.marker(posicion, { icon: iconoAuto() }).bindPopup('').addTo(mapa);
                        marcadoresChofer.set(c.id, marcador);
                    } else if (choferPorId.get(c.id).color !== c.color) {
                        marcador.setIcon(iconoAuto());
                    }
                    choferPorId.set(c.id, c);
                    marcador.setLatLng(posicion);
                    marcador.setPopupContent(globoChofer(c));
                });
                marcadoresChofer.forEach((marcador, id) => {
                    if (! choferesVistos.has(id)) {
                        marcador.remove();
                        marcadoresChofer.delete(id);
                        choferPorId.delete(id);
                    }
                });

                // Viajes activos: origen (punto) y destino (pin) unidos por el recorrido por calles; sin recorrido,
                // por una línea recta punteada.
                const viajesVistos = new Set();
                datos.viajes.forEach((v) => {
                    const origen = [v.origen.lat, v.origen.lng];
                    const destino = [v.destino.lat, v.destino.lng];
                    const camino = v.recorrido ?? [origen, destino];
                    const estilo = v.recorrido ? ESTILO_RECORRIDO : ESTILO_RECTA;
                    viajesVistos.add(v.id);
                    limites.extend(origen);
                    limites.extend(destino);
                    let capas = capasViaje.get(v.id);
                    if (! capas) {
                        const resaltarEste = () => resaltarViaje(v.id);
                        capas = {
                            linea: L.polyline(camino, { ...estilo, bubblingMouseEvents: false })
                                .bindTooltip('')
                                .on('click', resaltarEste)
                                .addTo(mapa),
                            origen: L.marker(origen, { icon: iconoPunto }).bindPopup('').on('click', resaltarEste).addTo(mapa),
                            destino: L.marker(destino, { icon: iconoPin }).bindPopup('').on('click', resaltarEste).addTo(mapa),
                        };
                        capasViaje.set(v.id, capas);
                    }
                    capas.base = estilo; // el estilo se aplica abajo, junto con el resaltado
                    capas.linea.setLatLngs(camino);
                    capas.origen.setLatLng(origen).setPopupContent(globoViaje(v, v.origen, 'O'));
                    capas.destino.setLatLng(destino).setPopupContent(globoViaje(v, v.destino, 'D'));
                    capas.linea.setTooltipContent(`Viaje #${escapar(v.id)} · ${escapar(v.estado_etiqueta)}`);
                });
                capasViaje.forEach((capas, id) => {
                    if (! viajesVistos.has(id)) {
                        [capas.linea, capas.origen, capas.destino].forEach((capa) => capa.remove());
                        capasViaje.delete(id);
                    }
                });
                aplicarResaltado();

                // Encuadra solo la primera vez, para no mover el mapa mientras el admin lo mira.
                if (! encuadrado && limites.isValid()) {
                    mapa.fitBounds(limites, { padding: [40, 40], maxZoom: 16 });
                    encuadrado = true;
                }
            };

            filtrarMarcadores = (consulta) => {
                marcadoresChofer.forEach((marcador, id) => {
                    const visible = coincide(choferPorId.get(id), consulta);
                    marcador.setOpacity(visible ? 1 : OPACIDAD_ATENUADO);
                    marcador.setZIndexOffset(visible && normalizar(consulta) !== '' ? 1000 : 0);
                });
            };
            irAChofer = (chofer) => {
                const marcador = marcadoresChofer.get(chofer.id);
                if (marcador) {
                    mapa.setView(marcador.getLatLng(), 16);
                    marcador.openPopup();
                }
            };

            aplicar = actualizar;
            actualizar(ultimosDatos);
            refrescarBuscador();
        };

        const iniciarGoogle = () => {
            const mapa = new google.maps.Map(contenedor, { center: centroPorDefecto, zoom: 12 });
            const globo = new google.maps.InfoWindow();
            // Los choferes se actualizan en el lugar (así el globo abierto sigue anclado); los viajes se redibujan.
            const marcadoresChofer = new Map();
            const choferPorId = new Map();
            let dibujados = [];
            let encuadrado = false;

            const iconoAuto = (color) => ({
                url: urlSvg(svgAuto(color)),
                anchor: new google.maps.Point(TAMANO_AUTO / 2, TAMANO_AUTO / 2),
            });
            const iconoPunto = { url: urlSvg(svgPunto()), anchor: new google.maps.Point(TAMANO_PUNTO / 2, TAMANO_PUNTO / 2) };
            const iconoPin = { url: urlSvg(svgPin()), anchor: new google.maps.Point(TAMANO_PIN / 2, PUNTA_PIN) };

            // El globo compartido muestra un chofer (idConGlobo) o un punto de un viaje (viajeConGlobo: {id, letra}).
            let idConGlobo = null;
            let viajeConGlobo = null;
            globo.addListener('closeclick', () => {
                idConGlobo = null;
                viajeConGlobo = null;
            });
            const abrirGlobo = (id) => {
                const marcador = marcadoresChofer.get(id);
                if (marcador) {
                    globo.setContent(globoChofer(choferPorId.get(id)));
                    globo.open({ map: mapa, anchor: marcador });
                    idConGlobo = id;
                    viajeConGlobo = null;
                }
            };
            // Ubicado en el punto y no anclado al marcador, porque los marcadores de viaje se rearman en cada actualización.
            const ponerGloboViaje = (v, letra) => {
                const punto = letra === 'O' ? v.origen : v.destino;
                globo.setContent(globoViaje(v, punto, letra));
                globo.setPosition({ lat: punto.lat, lng: punto.lng });
            };

            // Viajes dibujados por id (se rearman en cada actualización): línea, marcadores, estilo base y su encuadre.
            let viajesDibujados = new Map();
            // Espera pendiente para acotar el zoom tras encuadrar un viaje resaltado (ver resaltarViaje).
            let cancelarAcotarZoom = null;
            const PLAZO_ACOTAR_ZOOM_MS = 1000;
            const LINEA_PUNTEADA = 'M 0,-1 0,1';
            const aplicarResaltado = () => {
                viajesDibujados.forEach((d, id) => {
                    const nivel = nivelResaltado(id, viajeResaltado);
                    const { grosor, opacidad } = ajustarEstilo(d.grosor, d.opacidad, nivel);
                    const zIndex = nivel === 'resaltado' ? 10 : 1;
                    d.linea.setOptions(d.recta ? {
                        zIndex,
                        icons: [{ icon: { path: LINEA_PUNTEADA, strokeColor: '#2563eb', strokeOpacity: opacidad, scale: grosor }, offset: '0', repeat: '12px' }],
                    } : { zIndex, strokeWeight: grosor, strokeOpacity: opacidad });
                    d.marcadores.forEach((m) => m.setOpacity(nivel === 'atenuado' ? OPACIDAD_ATENUADO : 1));
                });
            };
            resaltarViaje = (id) => {
                const d = viajesDibujados.get(id);
                if (! d) {
                    return;
                }
                viajeResaltado = id;
                aplicarResaltado();
                // Google no tiene maxZoom en fitBounds: se acota cuando termina de encuadrar. Solo para este
                // encuadre: se descarta la espera de un resaltado anterior y, si el mapa no queda quieto en
                // PLAZO_ACOTAR_ZOOM_MS (p. ej. no hubo que moverlo), se deja de esperar, así un "idle"
                // posterior (el admin moviendo el mapa) no le cambia el zoom.
                cancelarAcotarZoom?.();
                mapa.fitBounds(d.limites, MARGEN_ENCUADRE);
                const escucha = google.maps.event.addListenerOnce(mapa, 'idle', () => {
                    cancelarAcotarZoom?.();
                    if (mapa.getZoom() > ZOOM_MAXIMO_ENCUADRE) {
                        mapa.setZoom(ZOOM_MAXIMO_ENCUADRE);
                    }
                });
                const plazo = setTimeout(() => cancelarAcotarZoom?.(), PLAZO_ACOTAR_ZOOM_MS);
                cancelarAcotarZoom = () => {
                    escucha.remove();
                    clearTimeout(plazo);
                    cancelarAcotarZoom = null;
                };
            };
            quitarResaltado = () => {
                if (viajeResaltado !== null) {
                    viajeResaltado = null;
                    aplicarResaltado();
                }
            };
            // Tocar el mapa vacío quita el resaltado (el clic en una línea o un marcador no llega al mapa).
            mapa.addListener('click', () => quitarResaltado());

            const dibujar = (datos) => {
                dibujados.forEach((d) => d.setMap(null));
                dibujados = [];
                viajesDibujados = new Map();
                viajeResaltado = resaltadoVigente(datos.viajes, viajeResaltado);
                const limites = new google.maps.LatLngBounds();

                const choferesVistos = new Set();
                ubicados(datos.choferes).forEach((c) => {
                    const posicion = { lat: c.lat, lng: c.lng };
                    choferesVistos.add(c.id);
                    limites.extend(posicion);
                    let marcador = marcadoresChofer.get(c.id);
                    if (! marcador) {
                        marcador = new google.maps.Marker({ map: mapa, position: posicion, icon: iconoAuto(c.color) });
                        marcador.addListener('click', () => abrirGlobo(c.id));
                        marcadoresChofer.set(c.id, marcador);
                    } else if (choferPorId.get(c.id).color !== c.color) {
                        marcador.setIcon(iconoAuto(c.color));
                    }
                    choferPorId.set(c.id, c);
                    marcador.setPosition(posicion);
                    marcador.setTitle(`${c.nombre} (${c.patente ?? 'sin vehículo'}) · ${c.estado_etiqueta} · ${c.actualizado_hace} (${c.actualizado_en})`);
                    if (idConGlobo === c.id) {
                        globo.setContent(globoChofer(c));
                    }
                });
                marcadoresChofer.forEach((marcador, id) => {
                    if (! choferesVistos.has(id)) {
                        if (idConGlobo === id) {
                            globo.close();
                            idConGlobo = null;
                        }
                        marcador.setMap(null);
                        marcadoresChofer.delete(id);
                        choferPorId.delete(id);
                    }
                });

                datos.viajes.forEach((v) => {
                    const titulo = `Viaje #${v.id} · ${v.estado_etiqueta} · ${v.chofer}`;
                    const delViaje = new google.maps.LatLngBounds();
                    const marcadores = [['Origen', v.origen, iconoPunto, 'O'], ['Destino', v.destino, iconoPin, 'D']].map(([nombre, punto, icon, letra]) => {
                        limites.extend(punto);
                        delViaje.extend(punto);
                        const marcador = new google.maps.Marker({
                            map: mapa, position: punto, icon, title: `${titulo} · ${nombre}: ${punto.direccion ?? 'sin dirección'}`,
                        });
                        // Tocar el origen o el destino resalta el viaje y muestra su globo.
                        marcador.addListener('click', () => {
                            resaltarViaje(v.id);
                            ponerGloboViaje(v, letra);
                            globo.open({ map: mapa });
                            idConGlobo = null;
                            viajeConGlobo = { id: v.id, letra };
                        });
                        dibujados.push(marcador);

                        return marcador;
                    });
                    // El recorrido por calles; sin recorrido, una línea recta punteada (el estilo lo pone aplicarResaltado).
                    const camino = v.recorrido ? v.recorrido.map(([lat, lng]) => ({ lat, lng })) : [v.origen, v.destino];
                    camino.forEach((punto) => delViaje.extend(punto));
                    const linea = new google.maps.Polyline(v.recorrido
                        ? { map: mapa, path: camino, strokeColor: '#2563eb' }
                        : { map: mapa, path: camino, strokeOpacity: 0 });
                    linea.addListener('click', () => resaltarViaje(v.id));
                    dibujados.push(linea);
                    viajesDibujados.set(v.id, v.recorrido
                        ? { linea, marcadores, limites: delViaje, recta: false, grosor: 4, opacidad: 0.8 }
                        : { linea, marcadores, limites: delViaje, recta: true, grosor: 3, opacidad: 0.6 });
                });
                aplicarResaltado();
                // El globo abierto de un viaje se actualiza con los datos nuevos; si el viaje terminó, se cierra.
                if (viajeConGlobo) {
                    const v = datos.viajes.find((x) => x.id === viajeConGlobo.id);
                    if (v) {
                        ponerGloboViaje(v, viajeConGlobo.letra);
                    } else {
                        globo.close();
                        viajeConGlobo = null;
                    }
                }

                // Encuadra solo la primera vez, para no mover el mapa mientras el admin lo mira.
                if (! encuadrado && ! limites.isEmpty()) {
                    mapa.fitBounds(limites);
                    encuadrado = true;
                }
            };

            filtrarMarcadores = (consulta) => {
                marcadoresChofer.forEach((marcador, id) => {
                    const visible = coincide(choferPorId.get(id), consulta);
                    marcador.setOpacity(visible ? 1 : OPACIDAD_ATENUADO);
                    marcador.setZIndex(visible && normalizar(consulta) !== '' ? 1000 : undefined);
                });
            };
            irAChofer = (chofer) => {
                const marcador = marcadoresChofer.get(chofer.id);
                if (marcador) {
                    mapa.setCenter(marcador.getPosition());
                    mapa.setZoom(16);
                    abrirGlobo(chofer.id);
                }
            };

            aplicar = dibujar;
            dibujar(ultimosDatos);
            refrescarBuscador();
        };

        if (contenedor.dataset.proveedor === 'google') {
            if (window.google?.maps) {
                iniciarGoogle();
            } else {
                window.iniciarMapaEnVivo = iniciarGoogle;
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(clave)}&callback=iniciarMapaEnVivo`;
                script.async = true;
                script.addEventListener('error', mostrarError, { once: true });
                document.head.appendChild(script);
            }
        } else if (window.L) {
            iniciarLeaflet();
        } else {
            let script = document.getElementById('leaflet-js');
            if (! script) {
                script = document.createElement('script');
                script.id = 'leaflet-js';
                script.src = contenedor.dataset.leafletJs;
                script.integrity = contenedor.dataset.leafletSri;
                script.crossOrigin = 'anonymous';
                document.head.appendChild(script);
            }
            script.addEventListener('load', iniciarLeaflet, { once: true });
            script.addEventListener('error', mostrarError, { once: true });
        }
    </script>
    @endscript
</x-filament-panels::page>
