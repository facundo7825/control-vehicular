// Respuestas reales del backend para el chofer (rama main, reloj en 2026-10-01 12:00 UTC), copiadas tal cual.
// Los turnos y vehículos son modelos Eloquent (sin Resource): fechas con microsegundos y campos extra.

// GET /api/vehiculos/disponibles -> 200
const vehiculosDisponibles =
    r'''[{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}]''';

// GET /api/turnos/actual (sin turno) -> 200
const sinTurno = r'''{"turno":null}''';

// POST /api/turnos {"vehiculo_id":1} -> 201
const turnoIniciado =
    r'''{"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","origen":"manual","updated_at":"2026-10-01T12:00:00.000000Z","created_at":"2026-10-01T12:00:00.000000Z","id":1,"vehiculo":{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}}''';

// GET /api/turnos/actual (con turno) -> 200
const turnoActual =
    r'''{"turno":{"id":1,"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","fin":null,"origen":"manual","created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z","vehiculo":{"id":1,"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco","activo":true,"created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}}}''';

// POST /api/turnos/actual/finalizar -> 200 (sin `vehiculo`)
const turnoFinalizado =
    r'''{"id":1,"chofer_id":2,"vehiculo_id":1,"inicio":"2026-10-01T12:00:00.000000Z","fin":"2026-10-01T12:00:00.000000Z","origen":"manual","created_at":"2026-10-01T12:00:00.000000Z","updated_at":"2026-10-01T12:00:00.000000Z"}''';

// POST /api/turnos con el vehículo tomado -> 422
const vehiculoEnUso = r'''{"message":"El vehículo está en uso por otro chofer."}''';

// POST /api/turnos/actual/finalizar con un viaje activo -> 422
const finalizarConViaje = r'''{"message":"Finalizá el viaje en curso antes de cerrar el turno."}''';

// POST /api/ubicacion sin turno abierto -> 422
const ubicacionSinTurno = r'''{"message":"Iniciá un turno para compartir tu ubicación."}''';

// POST /api/ofertas/1/aceptar (vencida o ya respondida) -> 422
const ofertaNoVigente = r'''{"message":"La oferta ya no está vigente."}''';

// POST /api/viajes/1/estado (de otro chofer) -> 403
const viajeAjeno = r'''{"message":"Este viaje no es tuyo."}''';

// POST /api/viajes/2/estado {"estado":"en_camino"} (reserva, antes de tiempo) -> 422
const reservaAntesDeTiempo = r'''{"message":"Podés salir hacia esta reserva a partir de las 11:15."}''';

// POST /api/viajes/1/cancelar {"motivo":"x"} (chofer, inmediato con otro chofer libre: vuelve a buscar) -> 200.
// Armado sobre ViajeResource: sin chofer ni vehículo; si no hay otro chofer libre llega `sin_chofer` (B3).
const viajeCanceladoPorChofer =
    r'''{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"buscando","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// GET /api/agenda -> 200
const agenda =
    r'''{"reservas":[],"solicitudes":[{"id":3,"vence_en":"2026-10-01T12:30:00+00:00","viaje":{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}]}''';

// Armado sobre ViajeResource: reserva `aceptado` del chofer 2, como viene en `reservas` de la agenda.
const reservaConfirmada =
    r'''{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"aceptado","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":{"id":2,"nombre":"Carlos Gómez","telefono":"3815550000"},"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":"3815551111"},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// POST /api/ofertas/3/aceptar (reserva superpuesta) -> 422
const reservaNoDisponible = r'''{"message":"La reserva ya no está disponible o se superpone con otra de tu agenda."}''';
