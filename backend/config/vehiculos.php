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
    ],

    'notificaciones' => [
        // registro | fcm
        'driver' => env('NOTIFICACIONES_DRIVER', 'registro'),
    ],

    'parametros' => [
        'oferta_segundos' => 30,
        'candidatos_distance_matrix' => 5,
        'bloqueo_antes_reserva_min' => 45,
        'colchon_reservas_min' => 30,
        'anticipacion_minima_reserva_min' => 60,
        'plazo_respuesta_reserva_min' => 30,
        'sin_senal_min' => 2,
        'no_disponible_min' => 10,
        'gps_turno_seg' => 10,
        'gps_viaje_seg' => 5,
        'retencion_recorrido_dias' => 90,
    ],
];
