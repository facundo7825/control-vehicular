{{-- Botón de la barra superior para silenciar o activar el sonido de las alertas en vivo (se guarda en este navegador). --}}
<div
    x-data="{
        clave: 'vehiculos.alertas.silencio',
        silencio: false,
        init() {
            try {
                this.silencio = localStorage.getItem(this.clave) === '1'
            } catch (e) {}
        },
        alternar() {
            this.silencio = ! this.silencio
            try {
                this.silencio ? localStorage.setItem(this.clave, '1') : localStorage.removeItem(this.clave)
            } catch (e) {}
        },
    }"
    class="fi-boton-silencio-alertas flex items-center"
>
    <x-filament::icon-button
        :icon="\Filament\Support\Icons\Heroicon::OutlinedBell"
        color="gray"
        label="Silenciar alertas"
        tooltip="Silenciar alertas"
        x-show="! silencio"
        x-on:click="alternar()"
    />
    <x-filament::icon-button
        :icon="\Filament\Support\Icons\Heroicon::OutlinedBellSlash"
        color="gray"
        label="Activar sonido de alertas"
        tooltip="Activar sonido de alertas"
        x-show="silencio"
        x-cloak
        x-on:click="alternar()"
    />
</div>
