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
            {{-- z-index por encima del mapa (que tiene z-index 0). --}}
            <div id="buscador-choferes-lista" class="fi-dropdown-panel fi-dropdown-list" hidden
                 style="top: calc(100% + 0.25rem); left: 0; width: 100%; z-index: 20;"></div>
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
        const normalizar = (texto) => String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '')
            .toLowerCase().replace(/\s+/g, ' ').trim();
        // Coincide por nombre o por patente; la patente se compara sin espacios ("AB 123 CD" = "ab123cd").
        const coincide = (chofer, consulta) => {
            const buscado = normalizar(consulta);
            if (buscado === '') {
                return true;
            }
            const sinEspacios = (texto) => texto.replace(/ /g, '');

            return normalizar(chofer.nombre).includes(buscado)
                || (chofer.patente != null && sinEspacios(normalizar(chofer.patente)).includes(sinEspacios(buscado)));
        };
        const buscarChoferes = (choferes, consulta, limite = 8) => normalizar(consulta) === ''
            ? []
            : choferes.filter((c) => coincide(c, consulta)).slice(0, limite);
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

        const escapar = (texto) => String(texto ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        const globoChofer = (c) => `<strong>${escapar(c.nombre)}</strong><br>`
            + `Vehículo: ${escapar(c.patente ?? 'sin vehículo')}<br>`
            + `Estado: ${escapar(c.estado_etiqueta)}<br>`
            + `Última ubicación: ${escapar(c.actualizado_hace)} (${escapar(c.actualizado_en)})`;

        const globoViaje = (v, punto, letra) => `<strong>Viaje #${escapar(v.id)}</strong> · ${escapar(v.estado_etiqueta)}<br>`
            + `Chofer: ${escapar(v.chofer)}<br>`
            + `${letra === 'O' ? 'Origen' : 'Destino'}: ${escapar(punto.direccion ?? 'sin dirección')}`;

        // Íconos SVG (los mismos para Leaflet y Google): el chofer es un auto (Material "directions_car") blanco
        // sobre un círculo del color de su estado; el origen del viaje (el usuario) un punto naranja; el destino un pin rojo.
        const sombra = '<defs><filter id="sombra-mapa-en-vivo" x="-50%" y="-50%" width="200%" height="200%">'
            + '<feDropShadow dx="0" dy="1" stdDeviation="1.2" flood-opacity="0.45"/></filter></defs>';
        const TAMANO_AUTO = 36;
        const svgAuto = (color) => `<svg xmlns="http://www.w3.org/2000/svg" width="${TAMANO_AUTO}" height="${TAMANO_AUTO}" viewBox="0 0 36 36">${sombra}`
            + `<circle cx="18" cy="18" r="15" fill="${escapar(color)}" stroke="#ffffff" stroke-width="2" filter="url(#sombra-mapa-en-vivo)"/>`
            + '<path transform="translate(8.4 8.4) scale(0.8)" fill="#ffffff" d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>'
            + '</svg>';
        const TAMANO_PUNTO = 22;
        const svgPunto = () => `<svg xmlns="http://www.w3.org/2000/svg" width="${TAMANO_PUNTO}" height="${TAMANO_PUNTO}" viewBox="0 0 22 22">${sombra}`
            + '<circle cx="11" cy="11" r="7" fill="#f97316" stroke="#ffffff" stroke-width="3" filter="url(#sombra-mapa-en-vivo)"/></svg>';
        // Pin de 32 px: la punta (y = 22 de 24 en el viewBox) queda a 29 px, que es el anclaje.
        const TAMANO_PIN = 32;
        const PUNTA_PIN = 29;
        const svgPin = () => `<svg xmlns="http://www.w3.org/2000/svg" width="${TAMANO_PIN}" height="${TAMANO_PIN}" viewBox="0 0 24 24">${sombra}`
            + '<path fill="#dc2626" stroke="#ffffff" stroke-width="1.2" filter="url(#sombra-mapa-en-vivo)" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>'
            + '</svg>';
        const urlSvg = (svg) => `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`;
        const OPACIDAD_ATENUADO = 0.3;

        // Buscador: filtra en el navegador mientras se escribe; lista hasta 8 coincidencias y atenúa al resto en el mapa.
        const campo = document.getElementById('buscador-choferes-campo');
        const lista = document.getElementById('buscador-choferes-lista');
        let coincidencias = [];

        const elegir = (chofer) => {
            if (chofer.lat === null || chofer.lng === null) {
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
            lista.innerHTML = coincidencias.length === 0
                ? '<div class="fi-dropdown-list-item"><span class="fi-dropdown-list-item-label">Ningún chofer en turno coincide.</span></div>'
                : coincidencias.map((c, i) => {
                    const sinUbicacion = c.lat === null || c.lng === null;

                    return `<button type="button" class="fi-dropdown-list-item" data-indice="${i}"`
                        + `${sinUbicacion ? ' style="cursor: default;"' : ''}>`
                        + `<span style="width:10px;height:10px;border-radius:9999px;flex:none;background:${escapar(c.color)};"></span>`
                        + '<span class="fi-dropdown-list-item-label">'
                        + `<strong>${escapar(c.nombre)}</strong> · ${escapar(c.patente ?? 'sin vehículo')} · `
                        + `<span style="color:${escapar(c.color)};">${escapar(c.estado_etiqueta)}</span>`
                        + `${sinUbicacion ? ' · <em>sin ubicación todavía</em>' : ''}</span></button>`;
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
                if (coincidencias.length > 0) {
                    elegir(coincidencias[0]);
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
        document.addEventListener('click', (evento) => {
            if (! document.getElementById('buscador-choferes')?.contains(evento.target)) {
                lista.hidden = true;
            }
        });

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
                className: '',
                html: svg,
                iconSize: [tamano, tamano],
                iconAnchor: anclaje,
                popupAnchor: [0, -anclaje[1]],
            });
            const iconoPunto = icono(svgPunto(), TAMANO_PUNTO, [TAMANO_PUNTO / 2, TAMANO_PUNTO / 2]);
            const iconoPin = icono(svgPin(), TAMANO_PIN, [TAMANO_PIN / 2, PUNTA_PIN]);

            const actualizar = (datos) => {
                const limites = L.latLngBounds([]);

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

                // Viajes activos: origen (punto) y destino (pin) unidos por una línea.
                const viajesVistos = new Set();
                datos.viajes.forEach((v) => {
                    const origen = [v.origen.lat, v.origen.lng];
                    const destino = [v.destino.lat, v.destino.lng];
                    viajesVistos.add(v.id);
                    limites.extend(origen);
                    limites.extend(destino);
                    let capas = capasViaje.get(v.id);
                    if (! capas) {
                        capas = {
                            linea: L.polyline([origen, destino], { color: '#2563eb', opacity: 0.6, weight: 3 })
                                .bindTooltip('')
                                .addTo(mapa),
                            origen: L.marker(origen, { icon: iconoPunto }).bindPopup('').addTo(mapa),
                            destino: L.marker(destino, { icon: iconoPin }).bindPopup('').addTo(mapa),
                        };
                        capasViaje.set(v.id, capas);
                    }
                    capas.linea.setLatLngs([origen, destino]);
                    capas.origen.setLatLng(origen).setPopupContent(globoViaje(v, v.origen, 'O'));
                    capas.destino.setLatLng(destino).setPopupContent(globoViaje(v, v.destino, 'D'));
                    capas.linea.setTooltipContent(`Viaje #${escapar(v.id)} · ${escapar(v.estado_etiqueta)}`);
                });
                capasViaje.forEach((capas, id) => {
                    if (! viajesVistos.has(id)) {
                        Object.values(capas).forEach((capa) => capa.remove());
                        capasViaje.delete(id);
                    }
                });

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

            let idConGlobo = null;
            globo.addListener('closeclick', () => {
                idConGlobo = null;
            });
            const abrirGlobo = (id) => {
                const marcador = marcadoresChofer.get(id);
                if (marcador) {
                    globo.setContent(globoChofer(choferPorId.get(id)));
                    globo.open({ map: mapa, anchor: marcador });
                    idConGlobo = id;
                }
            };

            const dibujar = (datos) => {
                dibujados.forEach((d) => d.setMap(null));
                dibujados = [];
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
                    [['Origen', v.origen, iconoPunto], ['Destino', v.destino, iconoPin]].forEach(([nombre, punto, icon]) => {
                        limites.extend(punto);
                        dibujados.push(new google.maps.Marker({
                            map: mapa, position: punto, icon, title: `${titulo} · ${nombre}: ${punto.direccion ?? 'sin dirección'}`,
                        }));
                    });
                    dibujados.push(new google.maps.Polyline({
                        map: mapa, path: [v.origen, v.destino], strokeColor: '#2563eb', strokeOpacity: 0.6, strokeWeight: 3,
                    }));
                });

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
