// Respuestas reales del backend (rama main, `IDENTIDAD_DRIVER=simulada`, `MAPAS_DRIVER=falso`,
// reloj en 2026-10-01 12:00 UTC), copiadas tal cual. Si cambia un Resource o un controlador,
// regenerarlas y actualizar los modelos.
import 'dart:convert';

Map<String, dynamic> json(String s) => (jsonDecode(s) as Map).cast<String, dynamic>();

List<dynamic> jsonLista(String s) => jsonDecode(s) as List<dynamic>;

// POST /api/auth/intercambio  {"token_externo":"sim|100|Ana Pérez|Secretaria"} -> 200
const intercambio =
    r'''{"token":"1|MIrx0yp82jJCJvkdlCxuiYvIFl0kENIXWZeiV0t1eecca02e","usuario":{"id":1,"nombre":"Ana Pérez","cargo":"Secretaria","rol":"solicitante"}}''';

// POST /api/auth/intercambio  {"token_externo":"invalido"} -> 401
const intercambioInvalido = r'''{"message":"Sesión inválida."}''';

// GET /api/yo sin token -> 401
const noAutenticado = r'''{"message":"Unauthenticated."}''';

// GET /api/configuracion -> 200 (mapa de fondo por defecto: el OSM público)
const configuracion =
    r'''{"gps_turno_seg":10,"gps_viaje_seg":5,"oferta_segundos":30,"lugares_autocompletar":true,"teselas":{"url":"https:\/\/tile.openstreetmap.org\/{z}\/{x}\/{y}.png","atribucion":"© OpenStreetMap contributors","atribucion_url":"https:\/\/www.openstreetmap.org\/copyright","tms":false,"max_zoom":19}}''';

// GET /api/configuracion -> 200 con MAPAS_TESELAS_URL=https://mapas.ejemplo.gob.ar/tms/{z}/{x}/{y}.png,
// MAPAS_TESELAS_ATRIBUCION="IGN", MAPAS_TESELAS_ATRIBUCION_URL= (vacía), MAPAS_TESELAS_TMS=true y MAPAS_TESELAS_MAX_ZOOM=15.
const configuracionTeselasPropias =
    r'''{"gps_turno_seg":10,"gps_viaje_seg":5,"oferta_segundos":30,"lugares_autocompletar":true,"teselas":{"url":"https:\/\/mapas.ejemplo.gob.ar\/tms\/{z}\/{x}\/{y}.png","atribucion":"IGN","atribucion_url":null,"tms":true,"max_zoom":15}}''';

// GET /api/configuracion -> 200 con LUGARES_DRIVER=nominatim: la búsqueda de lugares es al confirmar.
const configuracionSinAutocompletar =
    r'''{"gps_turno_seg":10,"gps_viaje_seg":5,"oferta_segundos":30,"lugares_autocompletar":false}''';

// GET /api/choferes -> 200
const choferes =
    r'''[{"id":2,"nombre":"Carlos Gómez","estado":"libre","lat":-26.8301,"lng":-65.2001,"rumbo":91.5,"actualizado_en":"2026-10-01T12:00:00+00:00","vehiculo":{"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco"}}]''';

// POST /api/broadcasting/auth (form: socket_id, channel_name=private-mapa.choferes) -> 200
const autorizacionCanal = r'''{"auth":"clave:050a29a0ab1ade49991dd26c45b24f6ff1580b645893ac51f353385ad15e2ae8"}''';

// POST /api/viajes sin coordenadas -> 422 (validación de Laravel)
const validacion =
    r'''{"message":"The chofer id field is required when modo is especifico. (and 4 more errors)","errors":{"chofer_id":["The chofer id field is required when modo is especifico."],"origen_lat":["The origen lat field is required."],"origen_lng":["The origen lng field is required."],"destino_lat":["The destino lat field is required."],"destino_lng":["The destino lng field is required."]}}''';

// POST /api/viajes (mas_cercano) -> 201
const viajeOfrecido =
    r'''{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// POST /api/ofertas/1/aceptar -> 200 (también es el payload de `viaje.actualizado`)
const viajeAceptado =
    r'''{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"aceptado","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":{"id":2,"nombre":"Carlos Gómez","telefono":"3815550000"},"vehiculo":{"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco"},"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// GET /api/viajes/actual (solicitante) -> 200
const viajeActualSolicitante =
    r'''{"viaje":{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null},"oferta":null}''';

// GET /api/viajes/actual (chofer con oferta pendiente) -> 200
const viajeActualChofer =
    r'''{"viaje":null,"oferta":{"id":1,"vence_en":"2026-10-01T12:00:30+00:00","viaje":{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}}''';

// GET /api/viajes/actual sin nada en curso -> 200
const viajeActualVacio = r'''{"viaje":null,"oferta":null}''';

// POST /api/viajes/1/cancelar (solicitante, viaje en sin_chofer) -> 422
const reglaNegocio = r'''{"message":"El viaje no puede pasar de sin_chofer a cancelado."}''';

// GET /api/agenda como solicitante -> 403
const sinPermiso = r'''{"message":"No tenés permiso para esta acción."}''';

// GET /api/reservas/disponibles?programado_para=2026-10-02T13:00:00Z&... -> 200
const reservasDisponibles =
    r'''{"duracion_estimada_min":19,"choferes":[{"id":2,"nombre":"Carlos Gómez","reservas_del_dia":0}]}''';

// POST /api/reservas (cualquiera_disponible) -> 201
const reservaCreada =
    r'''{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}''';

// GET /api/viajes -> 200
const misViajes =
    r'''{"proximas":[{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}],"historial":[{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"sin_chofer","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}]}''';

// Evento `oferta.creada` en `private-chofer.{id}` (OfertaCreada::broadcastWith)
const eventoOfertaCreada =
    r'''{"oferta_id":3,"vence_en":"2026-10-01T12:30:00+00:00","viaje":{"id":2,"tipo":"reserva","modo":"cualquiera_disponible","estado":"ofrecido","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":null},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Casa de Gobierno"},"motivo":null,"programado_para":"2026-10-02T13:00:00+00:00","duracion_estimada_min":19,"chofer":null,"vehiculo":null,"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":null,"llego_en":null,"iniciado_en":null,"finalizado_en":null,"cancelado_en":null}}''';

// Evento `chofer.ubicacion` en `private-mapa.choferes` y `private-viaje.{id}`
const eventoUbicacion =
    r'''{"chofer_id":2,"lat":-26.8301,"lng":-65.2001,"rumbo":91.5,"actualizado_en":"2026-10-01T12:00:00+00:00"}''';

// Evento `chofer.estado` en `private-mapa.choferes`
const eventoEstadoChofer = r'''{"chofer_id":2,"estado":"en_viaje"}''';

// GET /api/ruta?origen_lat=-26.8241&origen_lng=-65.2226&destino_lat=-26.8083&destino_lng=-65.2176 -> 200
// Forma de la Decisión 1 del plan de recorrido (el backend se escribe en paralelo): `indice` es la posición
// del punto de la maniobra dentro de `puntos`. Sin recorrido disponible responde 200 con `null`.
const ruta =
    r'''{"distancia_m":1830,"duracion_s":240.5,"puntos":[[-26.8241,-65.2226],[-26.8162,-65.2201],[-26.8083,-65.2176]],"pasos":[{"instruccion":"Seguí por 24 de Septiembre","distancia_m":1830,"indice":0,"lat":-26.8241,"lng":-65.2226,"tipo":"salida"},{"instruccion":"Llegaste a destino","distancia_m":0,"indice":2,"lat":-26.8083,"lng":-65.2176,"tipo":"llegada"}]}''';

// GET /api/viajes/1 de un viaje finalizado (con los campos del detalle del historial)
const viajeFinalizado =
    r'''{"id":1,"tipo":"inmediato","modo":"mas_cercano","estado":"finalizado","obligatorio":false,"origen":{"lat":-26.8241,"lng":-65.2226,"direccion":"Plaza Independencia"},"destino":{"lat":-26.8083,"lng":-65.2176,"direccion":"Tribunales"},"motivo":"Audiencia","programado_para":null,"duracion_estimada_min":null,"chofer":{"id":2,"nombre":"Carlos Gómez","telefono":"3815550000"},"vehiculo":{"patente":"AB123CD","marca":"Toyota","modelo":"Corolla","color":"Blanco"},"solicitante":{"id":1,"nombre":"Ana Pérez","telefono":null},"aceptado_en":"2026-10-01T12:00:00+00:00","llego_en":"2026-10-01T12:05:00+00:00","iniciado_en":"2026-10-01T12:06:00+00:00","finalizado_en":"2026-10-01T12:20:00+00:00","cancelado_en":null,"pedido_en":"2026-10-01T11:58:00+00:00","cancelado_por":null,"motivo_cancelacion":null,"metros_recorridos":5300}''';

// GET /api/viajes/1/recorrido -> 200
const recorrido = r'''{"puntos":[[-26.8241,-65.2226],[-26.8162,-65.2201],[-26.8083,-65.2176]],"disponible":true}''';

// GET /api/viajes/1/recorrido sin puntos o pasada la retención -> 200
const recorridoNoDisponible = r'''{"puntos":[],"disponible":false}''';
