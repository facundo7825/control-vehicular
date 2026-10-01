{{--
    Aviso en vivo de alertas (AvisoAlertas). Las notificaciones las muestra Filament; acá solo se consulta
    cada 10 s (también con la pestaña en segundo plano) y se reproduce el sonido, salvo que el admin lo haya
    silenciado con el botón de la barra superior (localStorage "vehiculos.alertas.silencio").
    El navegador no deja reproducir sonido hasta que el admin interactúa con la página: ese error se ignora.
--}}
<div
    wire:poll.10s.keep-alive="revisar"
    x-data="{
        sonar() {
            let silencio = false
            try {
                silencio = localStorage.getItem('vehiculos.alertas.silencio') === '1'
            } catch (e) {}
            if (silencio) return
            new Audio(@js(asset('sonidos/alerta.wav'))).play().catch(() => {})
        },
    }"
    x-on:alertas-nuevas.window="sonar()"
    class="hidden"
></div>
