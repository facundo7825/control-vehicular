<x-filament-panels::page>
    @if (! $this->claveGoogle())
        <x-filament::section heading="Falta la API key de Google Maps">
            Configurá <code>GOOGLE_MAPS_API_KEY</code> en el archivo <code>.env</code> del backend para ver el mapa en vivo.
        </x-filament::section>
    @else
        <div wire:poll.10s="refrescar">
            <div id="mapa-en-vivo" wire:ignore style="height: 70vh; width: 100%; border-radius: 0.75rem;"></div>
            <p style="margin-top: 0.5rem; font-size: 0.875rem; opacity: 0.75;">
                Choferes: verde libre · azul en viaje · ámbar reservado pronto · rojo sin señal.
                Viajes: línea de origen (O) a destino (D). Se actualiza cada 10 segundos.
            </p>
        </div>

        @script
        <script>
            const datosIniciales = @js($this->datosMapa());
            const clave = @js($this->claveGoogle());

            const iniciar = () => {
                const mapa = new google.maps.Map(document.getElementById('mapa-en-vivo'), {
                    center: { lat: -34.6037, lng: -58.3816 },
                    zoom: 12,
                });
                let dibujados = [];
                let encuadrado = false;

                const dibujar = (datos) => {
                    dibujados.forEach((d) => d.setMap(null));
                    dibujados = [];
                    const limites = new google.maps.LatLngBounds();

                    datos.choferes.forEach((c) => {
                        const posicion = { lat: c.lat, lng: c.lng };
                        limites.extend(posicion);
                        dibujados.push(new google.maps.Marker({
                            map: mapa,
                            position: posicion,
                            title: `${c.nombre} (${c.patente ?? 'sin vehículo'}) · ${c.estado_etiqueta} · ${c.actualizado_en}`,
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

            if (window.google?.maps) {
                iniciar();
            } else {
                window.iniciarMapaEnVivo = iniciar;
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(clave)}&callback=iniciarMapaEnVivo`;
                script.async = true;
                document.head.appendChild(script);
            }
        </script>
        @endscript
    @endif
</x-filament-panels::page>
