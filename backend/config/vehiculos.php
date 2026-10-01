<?php

return [
    'identidad' => [
        // simulada | poder_judicial
        'driver' => env('IDENTIDAD_DRIVER', 'simulada'),
        'url' => env('IDENTIDAD_PJ_URL'),
        'timeout' => (int) env('IDENTIDAD_PJ_TIMEOUT', 5),
        // Rutas (notación data_get) dentro del JSON que devuelve el endpoint del PJ.
        'campos' => [
            'id_externo' => env('IDENTIDAD_CAMPO_ID', 'id'),
            'nombre' => env('IDENTIDAD_CAMPO_NOMBRE', 'nombre'),
            'cargo' => env('IDENTIDAD_CAMPO_CARGO', 'cargo'),
            'telefono' => env('IDENTIDAD_CAMPO_TELEFONO', 'telefono'),
        ],
    ],

    'mapas' => [
        // falso | google
        'driver' => env('MAPAS_DRIVER', 'falso'),
        'google_api_key' => env('GOOGLE_MAPS_API_KEY'),
        // Clave para el mapa del panel (Maps JavaScript API): queda visible en el navegador, conviene una
        // distinta, restringida por HTTP referrer. Si falta se usa google_api_key.
        'google_js_api_key' => env('GOOGLE_MAPS_JS_API_KEY'),
    ],

    // Búsqueda de lugares para el destino del pedido.
    'lugares' => [
        // nominatim (OpenStreetMap, solo desarrollo/demos) | google (Places, usa mapas.google_api_key) | falso
        'driver' => env('LUGARES_DRIVER', 'nominatim'),
        // La política de Nominatim exige un User-Agent identificable de la aplicación.
        'user_agent' => env('LUGARES_USER_AGENT', 'VehiculosOficiales/1.0 (+https://github.com/facundo7825/control-vehicular)'),
    ],

    // Recorrido con indicaciones (GET /api/ruta).
    'rutas' => [
        // osrm (servidor público de OSRM, solo desarrollo/demos) | google (Directions, usa mapas.google_api_key) | falso
        'driver' => env('RUTAS_DRIVER', 'osrm'),
        // Un OSRM propio en producción; el público pide User-Agent identificable y como mucho 1 pedido por segundo.
        'osrm_url' => env('RUTAS_OSRM_URL', 'https://router.project-osrm.org'),
        'user_agent' => env('RUTAS_USER_AGENT', env('LUGARES_USER_AGENT', 'VehiculosOficiales/1.0 (+https://github.com/facundo7825/control-vehicular)')),
    ],

    'notificaciones' => [
        // registro | fcm
        'driver' => env('NOTIFICACIONES_DRIVER', 'registro'),
    ],

    // Zona horaria de los usuarios, para textos de push y para "reservas del día".
    // La app (config/app.php) y la base trabajan en UTC.
    'zona_horaria' => env('VEHICULOS_ZONA_HORARIA', 'America/Argentina/Buenos_Aires'),

    'parametros' => [
        'oferta_segundos' => 30,
        'candidatos_distance_matrix' => 5,
        'bloqueo_antes_reserva_min' => 45,
        'colchon_reservas_min' => 30,
        'anticipacion_minima_reserva_min' => 60,
        'plazo_respuesta_reserva_min' => 30,
        'margen_duracion_reserva_min' => 15,
        'duracion_reserva_por_defecto_min' => 60,
        'recordatorio_reserva_1_min' => 1440,
        'recordatorio_reserva_2_min' => 30,
        'alerta_sin_turno_min' => 15,
        'sin_senal_min' => 2,
        'no_disponible_min' => 10,
        'gps_turno_seg' => 10,
        'gps_viaje_seg' => 5,
        'retencion_recorrido_dias' => 90,
    ],
];
