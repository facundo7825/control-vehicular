<x-filament-panels::page>
    @php
        $datos = $this->datos();
        $celda = 'padding: 0.75rem;';
        $numero = 'padding: 0.75rem; text-align: end; font-variant-numeric: tabular-nums;';
    @endphp

    {{ $this->form }}

    @if ($avisoRango = $this->avisoRango())
        <x-filament::callout color="warning" :icon="\Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle"
                             :description="$avisoRango" />
    @endif

    @if ($datos['aviso'])
        <x-filament::callout color="warning" :icon="\Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle"
                             :description="$datos['aviso']" />
    @endif

    <x-filament::section heading="Por chofer">
        @if ($datos['choferes'] === [])
            <p>No hay actividad de choferes en el rango.</p>
        @else
            <div class="fi-ta-ctn" style="overflow-x: auto;">
                <table class="fi-ta-table">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell">Chofer</th>
                            <th class="fi-ta-header-cell fi-align-end">Finalizados</th>
                            <th class="fi-ta-header-cell fi-align-end">Cancelados</th>
                            <th class="fi-ta-header-cell fi-align-end">Km recorridos</th>
                            <th class="fi-ta-header-cell fi-align-end">Horas de turno</th>
                            <th class="fi-ta-header-cell fi-align-end">Llegada promedio (min)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datos['choferes'] as $fila)
                            <tr class="fi-ta-row" wire:key="chofer-{{ $fila['chofer_id'] }}">
                                <td class="fi-ta-cell" style="{{ $celda }}">{{ $fila['chofer'] }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $fila['finalizados'] }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $fila['cancelados'] }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $this->numero($fila['km'], 2) }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $this->numero($fila['horas_turno']) }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $this->numero($fila['llegada_promedio_min']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Por vehículo">
        @if ($datos['vehiculos'] === [])
            <p>No hay actividad de vehículos en el rango.</p>
        @else
            <div class="fi-ta-ctn" style="overflow-x: auto;">
                <table class="fi-ta-table">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell">Patente</th>
                            <th class="fi-ta-header-cell">Vehículo</th>
                            <th class="fi-ta-header-cell fi-align-end">Finalizados</th>
                            <th class="fi-ta-header-cell fi-align-end">Km recorridos</th>
                            <th class="fi-ta-header-cell fi-align-end">Horas en turno</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($datos['vehiculos'] as $fila)
                            <tr class="fi-ta-row" wire:key="vehiculo-{{ $fila['vehiculo_id'] }}">
                                <td class="fi-ta-cell" style="{{ $celda }}">{{ $fila['patente'] }}</td>
                                <td class="fi-ta-cell" style="{{ $celda }}">{{ $fila['vehiculo'] }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $fila['finalizados'] }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $this->numero($fila['km'], 2) }}</td>
                                <td class="fi-ta-cell" style="{{ $numero }}">{{ $this->numero($fila['horas_turno']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
