# Tiempo estimado de llegada y mensajes de validación en español — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** (1) Un endpoint que devuelva el tiempo estimado de llegada del chofer (al origen mientras va a buscar al solicitante, al destino durante el viaje). (2) Que todos los mensajes de validación de la API salgan en español, con nombres de campo legibles.

**Architecture:** Laravel 12 en `backend/`. El cálculo usa la interfaz existente `App\Mapas\ServicioMapas::duracionesHacia()` (Google Distance Matrix en producción, `ServicioMapasFalso` en desarrollo/tests) y `App\Mapas\Distancia::metros()`. Controlador delgado + servicio en `app/Servicios`.

**Tech Stack:** PHP 8.2, Laravel 12, Pest.

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md` (5.5: "el solicitante ve … el tiempo estimado de llegada").

**Decidido por el usuario (2026-09-30):** el tiempo estimado de llegada es necesario (no alcanza la distancia en línea recta) y los mensajes de validación pasan a español.

## Global Constraints

- Nombres de clases, rutas, claves JSON y mensajes en español, como el resto del backend.
- Los tests nunca llaman a Google: usan `ServicioMapasFalso` (30 km/h en línea recta) o un doble propio.
- Rutas nuevas dentro del grupo `Route::middleware(['auth:sanctum', 'activo'])` de `routes/api.php`.
- Errores de negocio con `App\Excepciones\ReglaNegocio` (→ 422 `{message}`) y `App\Excepciones\AccionNoPermitida` (→ 403 `{message}`), como el resto.
- Fechas en ISO-8601 (`toIso8601String()`).
- La suite completa (`./vendor/bin/pest` desde `backend/`) pasa al final de cada task.
- Cada commit termina con `-m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`.

---

### Task 1: Endpoint de tiempo estimado de llegada

**Files:**
- Create: `backend/app/Servicios/EstimadorLlegada.php`
- Create: `backend/app/Http/Controllers/EtaController.php` (invocable)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/EtaTest.php`

**Interfaces:**
- Produces: `GET /api/viajes/{viaje}/eta` → 200:
  ```json
  {"hacia": "origen", "segundos": 240, "metros": 1850, "calculado_en": "2026-10-01T12:00:00+00:00"}
  ```
  - `hacia`: `"origen"` si el viaje está en `aceptado`, `en_camino` o `llego`; `"destino"` si está en `en_curso`.
  - En `llego`: `segundos` = 0 y `metros` = 0 (el chofer ya está en el origen), sin llamar a mapas.
  - `segundos`: duración de manejo que devuelve `ServicioMapas::duracionesHacia([$choferId => [$lat, $lng]], $destLat, $destLng)[$choferId]`; puede ser `null` si Google no tiene dato.
  - `metros`: `Distancia::metros(...)` en línea recta, entero (redondeado), siempre presente si hay ubicación.
  - Si el chofer no tiene ubicación registrada: 200 con `segundos: null`, `metros: null`.
  - Si el viaje no está en ninguno de esos estados (buscando, ofrecido, finalizado, cancelado, sin_chofer) o no tiene chofer: 422 `{"message": "El viaje no tiene un chofer en camino."}`.
  - Acceso: el solicitante del viaje, el chofer asignado o un admin. Cualquier otro usuario: 403 `{"message": "Este viaje no es tuyo."}`.
- `EstimadorLlegada::estimar(Viaje $viaje): array` con esa forma (el controlador solo autoriza y devuelve JSON).
- **Cache:** para no pagar una llamada a Google por cada consulta, el resultado se cachea 30 segundos por viaje y por `hacia` (`Cache::remember("eta:{$viaje->id}:{$hacia}", 30, ...)`). `calculado_en` es el momento real del cálculo (así la app sabe cuán fresco es).

**Tests mínimos (TDD — escribirlos primero y verlos fallar con 404):**
1. Solicitante con viaje `en_camino` → 200, `hacia=origen`, `segundos` y `metros` coherentes con `ServicioMapasFalso` (p. ej. chofer a ~1 km → metros entre 900 y 1100, segundos = round(metros / 8.33)).
2. Viaje `en_curso` → `hacia=destino`, calculado contra `destino_lat/lng`.
3. Viaje `llego` → segundos 0, metros 0.
4. Chofer sin fila en `ubicaciones_chofer` → segundos y metros null.
5. Viaje `buscando` y viaje `finalizado` → 422 con el mensaje exacto.
6. Otro usuario → 403; el chofer asignado y un admin → 200.
7. Cache: dos llamadas seguidas devuelven el mismo `calculado_en`; tras `$this->travel(31)->seconds()` se recalcula (usar `travelTo` para fijar el reloj).

Usar los helpers existentes de `tests/Pest.php` (`choferEnTurno($lat, $lng, $minutos)`), factories y `Viaje::factory()`.

- [ ] Commit: `feat: endpoint de tiempo estimado de llegada del chofer`

---

### Task 2: Mensajes de validación en español

**Files:**
- Modify: `backend/config/app.php` (defaults `locale` → `es`, `fallback_locale` → `es`, `faker_locale` puede quedar)
- Modify: `backend/.env.example` (`APP_LOCALE=es`, `APP_FALLBACK_LOCALE=es`)
- Create: `backend/lang/es/validation.php` — traducción completa al español de **todas** las reglas del archivo de validación por defecto de Laravel 12 (publicarlo con `php artisan lang:publish` para tener la lista exacta de claves en `lang/en/validation.php` y traducir cada una, incluidos los sub-arrays `between`, `gt`, `size`, etc.), más `'attributes'` con nombres legibles para los campos que usa la API: `token_externo`, `modo`, `chofer_id`, `origen_lat`, `origen_lng`, `origen_direccion`, `destino_lat`, `destino_lng`, `destino_direccion`, `motivo`, `programado_para`, `estado`, `vehiculo_id`, `puntos`, `puntos.*.lat`, `puntos.*.lng`, `puntos.*.registrado_en`, `puntos.*.rumbo`, `puntos.*.velocidad`, `token` (buscar en `app/Http/Controllers` todas las claves que se validan y cubrirlas todas).
- Create (si `lang:publish` no los crea en español): `backend/lang/es/auth.php`, `passwords.php`, `pagination.php` traducidos.
- Mantener `lang/en/*` publicado (fallback) o borrarlo: decidir y justificar en el reporte.
- Test: `backend/tests/Feature/ValidacionEspanolTest.php`

**Tests mínimos:**
1. `POST /api/viajes` sin cuerpo (como solicitante) → 422 cuyo `message` está en español y `errors.origen_lat[0]` es exactamente `"El campo latitud de origen es obligatorio."` (o el texto que corresponda a la traducción elegida para `required` + el atributo — el test fija el texto exacto que produce el archivo).
2. `POST /api/reservas` con `programado_para` no fecha → error en español con el atributo legible.
3. `POST /api/ubicacion` con `puntos.0.lat` fuera de rango → error en español con atributo legible.
4. `POST /api/auth/intercambio` sin `token_externo` → error en español.
5. Revisar que ningún test existente dependa de textos en inglés (buscar `The ` / `field` en `tests/`) y ajustar sólo si alguno lo hace.
6. El test existente `tests/Feature/Panel/AccesoPanelTest.php` "no cambia el idioma de la API" debe seguir pasando (ahora `config('app.locale')` es `es`).

- [ ] Commit: `feat: mensajes de validación de la API en español`
