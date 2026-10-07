<x-filament-panels::page>
    @php
        $filas = $this->filas();
        $celda = 'padding: 0.75rem;';
        $numero = 'padding: 0.75rem; text-align: end; font-variant-numeric: tabular-nums;';
    @endphp

    <x-filament::section
        heading="A quién le toca"
        description="Primero el que hace más tiempo que no hace un viaje largo (los que nunca hicieron uno, antes). Al cargar un viaje largo se sugiere al primero que tenga la franja libre.">
        @if ($filas->isEmpty())
            <p>No hay choferes activos.</p>
        @else
            <div class="fi-ta-ctn" style="overflow-x: auto;">
                <table class="fi-ta-table">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell fi-align-end">#</th>
                            <th class="fi-ta-header-cell">Chofer</th>
                            <th class="fi-ta-header-cell">Último viaje largo</th>
                            <th class="fi-ta-header-cell fi-align-end">Últimos {{ $this->diasRecientes() }} días</th>
                            <th class="fi-ta-header-cell">Próximo programado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filas as $i => $fila)
                            <tr class="fi-ta-row" wire:key="rotacion-{{ $fila['chofer']->id }}">
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $i + 1 }}</td>
                                <td class="fi-ta-cell" style="{{ $celda }}">{{ $fila['chofer']->nombre }}</td>
                                <td class="fi-ta-cell" style="{{ $celda }}">
                                    @if ($fila['ultimo'])
                                        <x-filament::link :href="$this->urlViaje($fila['ultimo']['viaje_id'])">
                                            {{ $this->fecha($fila['ultimo']['fecha']) }}{{ $fila['ultimo']['destino'] ? ' — '.$fila['ultimo']['destino'] : '' }}
                                        </x-filament::link>
                                    @else
                                        Nunca
                                    @endif
                                </td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $fila['recientes'] }}</td>
                                <td class="fi-ta-cell" style="{{ $celda }}">
                                    @if ($fila['proximo'])
                                        <x-filament::link :href="$this->urlViaje($fila['proximo'])">
                                            {{ $this->fecha($fila['proximo']->programado_para, 'd/m/Y H:i') }}{{ $fila['proximo']->destino_direccion ? ' — '.$fila['proximo']->destino_direccion : '' }}
                                        </x-filament::link>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
