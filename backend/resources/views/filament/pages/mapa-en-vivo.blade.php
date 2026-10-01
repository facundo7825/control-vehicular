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
        {{-- position/z-index: los paneles de Leaflet no tapan la barra superior ni los modales de Filament. --}}
        <div id="mapa-en-vivo" wire:ignore data-proveedor="{{ $proveedor }}"
             @if ($proveedor === 'leaflet')
                 data-leaflet-js="{{ \App\Filament\Pages\MapaEnVivo::LEAFLET_JS }}"
                 data-leaflet-sri="{{ \App\Filament\Pages\MapaEnVivo::LEAFLET_JS_SRI }}"
             @endif
             style="height: 70vh; width: 100%; border-radius: 0.75rem; position: relative; z-index: 0;"></div>
        <p style="margin-top: 0.5rem; font-size: 0.875rem; opacity: 0.75;">
            Choferes en turno: verde libre · azul en viaje · ámbar reservado pronto · gris sin señal.
            Viajes: línea de origen (O) a destino (D). Se actualiza cada 10 segundos.
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

        const iniciarLeaflet = () => {
            const mapa = L.map(contenedor).setView([centroPorDefecto.lat, centroPorDefecto.lng], 12);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
            }).addTo(mapa);

            const marcadoresChofer = new Map();
            const capasViaje = new Map();
            let encuadrado = false;

            const letra = (texto) => L.divIcon({
                className: '',
                html: `<div style="background:#1f2937;color:#fff;border:2px solid #fff;border-radius:9999px;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;">${texto}</div>`,
                iconSize: [24, 24],
                iconAnchor: [12, 12],
            });

            const actualizar = (datos) => {
                const limites = L.latLngBounds([]);

                // Choferes: se mueven los marcadores existentes en lugar de recrearlos.
                const choferesVistos = new Set();
                ubicados(datos.choferes).forEach((c) => {
                    const posicion = [c.lat, c.lng];
                    choferesVistos.add(c.id);
                    limites.extend(posicion);
                    let marcador = marcadoresChofer.get(c.id);
                    if (! marcador) {
                        marcador = L.circleMarker(posicion, { radius: 9, color: '#ffffff', weight: 2, fillOpacity: 1 })
                            .bindPopup('')
                            .addTo(mapa);
                        marcadoresChofer.set(c.id, marcador);
                    }
                    marcador.setLatLng(posicion);
                    marcador.setStyle({ fillColor: c.color });
                    marcador.setPopupContent(globoChofer(c));
                });
                marcadoresChofer.forEach((marcador, id) => {
                    if (! choferesVistos.has(id)) {
                        marcador.remove();
                        marcadoresChofer.delete(id);
                    }
                });

                // Viajes activos: origen y destino unidos por una línea.
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
                            linea: L.polyline([origen, destino], { color: '#2563eb', opacity: 0.6, weight: 3 }).addTo(mapa),
                            origen: L.marker(origen, { icon: letra('O') }).bindPopup('').addTo(mapa),
                            destino: L.marker(destino, { icon: letra('D') }).bindPopup('').addTo(mapa),
                        };
                        capasViaje.set(v.id, capas);
                    }
                    capas.linea.setLatLngs([origen, destino]);
                    capas.origen.setLatLng(origen).setPopupContent(globoViaje(v, v.origen, 'O'));
                    capas.destino.setLatLng(destino).setPopupContent(globoViaje(v, v.destino, 'D'));
                    capas.linea.bindTooltip(`Viaje #${escapar(v.id)} · ${escapar(v.estado_etiqueta)}`);
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

            actualizar(datosIniciales);
            $wire.$on('mapa-datos', ({ datos }) => actualizar(datos));
        };

        const iniciarGoogle = () => {
            const mapa = new google.maps.Map(contenedor, { center: centroPorDefecto, zoom: 12 });
            let dibujados = [];
            let encuadrado = false;

            const dibujar = (datos) => {
                dibujados.forEach((d) => d.setMap(null));
                dibujados = [];
                const limites = new google.maps.LatLngBounds();

                ubicados(datos.choferes).forEach((c) => {
                    const posicion = { lat: c.lat, lng: c.lng };
                    limites.extend(posicion);
                    dibujados.push(new google.maps.Marker({
                        map: mapa,
                        position: posicion,
                        title: `${c.nombre} (${c.patente ?? 'sin vehículo'}) · ${c.estado_etiqueta} · ${c.actualizado_hace} (${c.actualizado_en})`,
                        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 9, fillColor: c.color, fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 2 },
                    }));
                });

                datos.viajes.forEach((v) => {
                    const titulo = `Viaje #${v.id} · ${v.estado_etiqueta} · ${v.chofer}`;
                    [['O', v.origen], ['D', v.destino]].forEach(([letra, punto]) => {
                        limites.extend(punto);
                        dibujados.push(new google.maps.Marker({
                            map: mapa, position: punto, label: letra, title: `${titulo} · ${punto.direccion ?? ''}`,
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

            dibujar(datosIniciales);
            $wire.$on('mapa-datos', ({ datos }) => dibujar(datos));
        };

        if (contenedor.dataset.proveedor === 'google') {
            if (window.google?.maps) {
                iniciarGoogle();
            } else {
                window.iniciarMapaEnVivo = iniciarGoogle;
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(clave)}&callback=iniciarMapaEnVivo`;
                script.async = true;
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
        }
    </script>
    @endscript
</x-filament-panels::page>
