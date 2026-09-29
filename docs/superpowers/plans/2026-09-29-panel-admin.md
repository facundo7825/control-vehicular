# Panel de administración (Filament) — Vehículos Oficiales — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que un administrador entre a `/admin` con email y contraseña y opere el sistema desde un panel Filament: ABM de vehículos y cargos prioritarios, roles y estado de choferes con sus turnos, listado y detalle de viajes y reservas con las acciones **cancelar** y **reasignar** (incluidos los obligatorios, spec 5.6), alertas (reservas sin turno y choferes sin señal durante un viaje) con un resumen en el tablero, edición de los parámetros de la spec 5.7 y un mapa en vivo de choferes y viajes activos.

**Architecture:** Filament 5.9 (Livewire 4) montado sobre el mismo Laravel (rama `feature/panel-admin`). El panel usa el guard `web` con el modelo `Usuario`; solo los admins activos pasan `canAccessPanel`. Las acciones que cambian viajes **no** tocan modelos desde Filament: llaman a métodos nuevos de `ServicioViaje` (`cancelarPorAdmin`, `reasignarPorAdmin`), que bloquean viaje y chofer como el resto de los servicios y pasan por `MaquinaEstadosViaje` (con transiciones exclusivas del admin separadas de las de la app). Los errores de negocio (`ReglaNegocio`) se muestran como notificaciones de Filament. La alerta "sin señal" es un comando programado cada minuto. El mapa se refresca con polling de Livewire (sin Echo en el panel).

**Tech Stack:** PHP 8.2+, Laravel 12, Filament **5.9.0** (verificado; trae Livewire 4.4.7), Pest 3 con los helpers de testing de Filament sobre `Livewire::test()`, Google Maps JavaScript API, MySQL 8 / MariaDB 10.6+ (producción), SQLite en memoria (tests).

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md` (secciones 4.1, 5.6, 5.7, 8 y 9)

**Planes anteriores (código sobre el que se apoya):** `docs/superpowers/plans/2026-09-28-backend-nucleo.md` y `docs/superpowers/plans/2026-09-29-reservas.md` (ya mergeados en `main`: 212 tests + suite de carreras `tests/Concurrencia`).

**Verificación previa:** todo el código de este plan se probó en una copia de `backend/` con Filament 5.9.0 instalado exactamente como indica la Task 1: 295 tests en verde (212 existentes + 83 nuevos) y la suite de carreras (7 carreras) contra MariaDB. La carrera nueva de la Task 11 falla si se saca el bloqueo del chofer en `reasignarPorAdmin`.

## Decisiones

Tomadas antes de escribir el plan. El plan las implementa tal cual, salvo los ajustes que se detallan después.

1. **Login del panel:** los usuarios no tienen contraseña (entran con el token del PJ). Se agregan `email` (nullable, único) y `password` (nullable, oculto, cast `hashed`) a `usuarios` en una migración nueva. El comando `vehiculos:crear-admin {email} {--nombre=} {--id-externo=} {--password=}` crea un admin nuevo o promueve a uno existente (buscado por `--id-externo` o por email), con rol admin, activo, y la contraseña pedida por consola (o `--password` para uso no interactivo y tests). Panel en `/admin`, login de Filament por email y contraseña. `Usuario implements FilamentUser`: `canAccessPanel` → rol admin y activo. Tests: solicitante, chofer y admin inactivo reciben 403; un admin activo, 200.
2. **Vehículos:** ABM completo (patente única, marca, modelo, color, activo). No se borra un vehículo con historial: se desactiva.
3. **Usuarios y choferes:** listado con filtros por rol y activo; se editan rol (solicitante, chofer, admin) y activo; nombre, cargo e id externo son de solo lectura (vienen del PJ). Columna con el estado calculado del chofer (`CalculadorEstadoChofer`). Tabla de turnos del chofer (relation manager). Un admin no puede quitarse el rol ni desactivarse.
4. **Cargos prioritarios:** ABM (cargo único, obligatorio).
5. **Viajes y reservas:** listado con filtros (tipo, estado, fecha, obligatorio, chofer); detalle con línea de tiempo, ofertas y recorrido (cantidad de puntos, primero y último). Acciones:
   - **Cancelar:** `ServicioViaje::cancelarPorAdmin(Viaje, Usuario $admin, string $motivo)`, en cualquier estado anterior a `finalizado` salvo `cancelado` (incluidos `en_curso` y los obligatorios) → `cancelado`, `cancelado_por = 'admin'`, vence las ofertas pendientes y avisa al solicitante y al chofer. El solicitante sigue sin poder cancelar un viaje `en_curso`.
   - **Reasignar:** `ServicioViaje::reasignarPorAdmin(Viaje, Usuario $chofer)`. Inmediato: desde `buscando`, `ofrecido`, `aceptado`, `en_camino`, `llego` o `sin_chofer`, con el chofer destino `Libre`, asignación forzada (sin oferta) aunque no sea obligatorio. Reserva: mientras no empezó (`buscando`, `ofrecido`, `aceptado`, `sin_chofer`), con la franja libre en la agenda del destino (`DisponibilidadReservas::estaDisponible` excluyendo este viaje); se programan recordatorios y alerta del chofer nuevo, y los del anterior no hacen nada gracias a `sigueReservadaPara`. Se avisa al chofer anterior y se vencen las ofertas pendientes. Todo en una transacción con los bloqueos de siempre y `attempts: 3`.
   - Ambas son acciones de Filament con confirmación, motivo obligatorio (cancelar) o select limitado a choferes elegibles (reasignar), y muestran las `ReglaNegocio` como notificaciones de error en lugar de un 500.
6. **Alertas:** recurso con las `alertas` (pendientes primero) y la acción "Marcar resuelta"; widget del tablero con: alertas sin resolver, viajes `sin_chofer` de las últimas 24 h y choferes `SinSenal` con un viaje activo. Además, filas de `alertas` por "chofer sin señal durante un viaje" (spec 9, `no_disponible_min` = 10 min) con un comando programado cada minuto, sin duplicados (una pendiente por viaje) y que se resuelven solas cuando vuelve la señal o el viaje termina.
7. **Parámetros:** página que lista cada clave de `config('vehiculos.parametros')` con su valor por defecto y el actual, editable como entero positivo (≥ 1) y guardado en `parametros`; "Restablecer" borra la fila.
8. **Mapa en vivo:** página con Google Maps JS (clave de `config('vehiculos.mapas.google_api_key')`; sin clave, un mensaje claro en lugar del mapa) con los choferes en turno (color por estado) y los viajes activos (origen y destino), refrescado con polling de Livewire cada 10 s. Los datos salen de un método PHP que devuelve un array y tiene su test; otro test verifica que la página se muestra a un admin. JS mínimo, en línea en la vista Blade.
9. **Tests:** helpers de testing de Filament sobre `Livewire::test()` para recursos, acciones y páginas; tests de servicio para `cancelarPorAdmin` y `reasignarPorAdmin`, incluidas carreras simuladas con copias desactualizadas como en `tests/Feature/ConcurrenciaViajeTest.php`. Una carrera nueva en `tests/Concurrencia`: el admin reasigna un viaje inmediato al chofer X mientras se le asigna un obligatorio → X termina con un solo viaje activo.

### Ajustes que obligó el código real o la API de Filament (revisar)

- **A1. Filament 5.9, no 3.** La última versión estable compatible con Laravel 12 y PHP 8.2 es la **5.9.0** (con Livewire 4.4.7). Su API no es la de Filament 3: formularios, infolists y páginas usan `Filament\Schemas\Schema` (`->components([...])`); todas las acciones están en `Filament\Actions\*` (también las de tabla: `->recordActions()`, `->toolbarActions()`); los filtros y las acciones con formulario usan `->schema([...])`; los íconos son el enum `Filament\Support\Icons\Heroicon`; `$view` de una página es `protected string` (no estático); los recursos se generan en `app/Filament/Resources/<Plural>/` con sus páginas en `Pages/`. Los tests usan `Livewire::test()` con las macros de Filament (`fillForm`, `callAction`, `TestAction::make(...)->table($registro)`, `filterTable`, `assertNotified`…), así que **no** hace falta `pestphp/pest-plugin-livewire` (su helper `livewire()` no se usa).
- **A2. `composer require` en Windows.** `composer` es un `.bat`: `cmd` se come el `^` y en la prueba quedó `"filament/filament": "5.9"`. Se usa `~5.9`, que equivale a `^5.9` para esta versión y no tiene caracteres especiales. Con `-W`, `laravel/framework` subió de 12.69.2 a 12.69.3 (parche).
- **A3. `en_curso → cancelado` no va en `PERMITIDAS`.** `MaquinaEstadosViajeTest` ya exige que esa transición esté prohibida (dataset y el test "rechaza una transición inválida"). En vez de romperlo, `MaquinaEstadosViaje` suma una tabla aparte, `SOLO_ADMIN`, que solo consulta `transicionarComoAdmin()`. `puede($desde, $hacia)` sigue igual y gana un tercer parámetro opcional `comoAdmin`. Así ningún flujo de la app (solicitante o chofer) puede llegar a esa transición: no depende de un `if` en `cancelarPorSolicitante`.
- **A4. La reasignación no agrega transiciones a `PERMITIDAS`.** Pasar de `aceptado` a `aceptado` con otro chofer es "mismo estado" y `aplicar()` lo trata como repetido (no hace nada). Hacerlo en dos pasos (`aceptado → buscando → aceptado`) emitiría dos `ViajeActualizado` y mandaría pushes falsos ("Tu chofer canceló, estamos buscando otro"). Se agrega `MaquinaEstadosViaje::reasignar()`: bloquea la fila, exige un estado de `REASIGNABLES`, guarda `aceptado` con el chofer y el vehículo nuevos (y `llego_en` en null) y emite **un** evento. `sin_chofer → aceptado` solo es posible por ahí.
- **A5. `ViajeActualizado` suma `porAdmin`.** Con ese dato `AvisosViaje` manda los textos del panel ("Un administrador canceló el viaje. Motivo: …", "Viaje reasignado", "Un administrador te asignó un viaje", …) en lugar de los de la app. Lo marcan `transicionarComoAdmin()` y `reasignar()`.
- **A6. La reserva reasignada no usa `Asignador::asignarReserva`.** Ese método solo acepta `buscando` u `ofrecido` (en `aceptado` devolvería `false`). `reasignarPorAdmin` repite sus verificaciones (bloqueo de viaje y chofer, `estaDisponible(..., bloquear: true)` excluyendo este viaje) y llama al mismo `AvisosReserva::programar()`. Se verificó que `RecordarReserva` y `AlertarReservaSinTurno` del chofer anterior no hacen nada (`sigueReservadaPara` compara `chofer_id`). Límite conocido: si una reserva vuelve a su chofer original (A → B → A), los jobs viejos de A vuelven a ser válidos y A recibe recordatorios duplicados (inofensivo).
- **A7. `sin_chofer` es un estado final.** "Cancelar" no se ofrece ahí (el servicio responde "El viaje ya terminó; no se puede cancelar."). Sí se puede **reasignar** un `sin_chofer` (decisión 5). Tampoco se puede reasignar al mismo chofer ni a un usuario que no sea chofer activo.
- **A8. `Parametros` no cachea.** Hace `Parametro::find()` en cada llamada, así que no hay nada que invalidar: el valor nuevo rige desde la operación siguiente (hay un test que despacha un viaje después de editar `oferta_segundos`). La página es una tabla de Filament con registros en array (`Table::records()`), con las acciones "Cambiar" y "Restablecer".
- **A9. Login con el modelo real.** Además de `email` y `password` hace falta `remember_token` (casilla "Recordarme" del login). El modelo usa `nombre`, no `name`: `Usuario` implementa `HasName` (si no, Filament muestra el nombre vacío). `id_externo` es obligatorio y único: un admin creado desde cero recibe `panel:{email}`.
- **A10. Guarda extra en usuarios.** `CalculadorEstadoChofer::libres()` no filtra por `activo`: un chofer desactivado con el turno abierto seguiría recibiendo viajes. Por eso el panel no deja quitar el rol de chofer ni desactivar a un chofer con turno abierto o con viajes asignados (`aceptado` a `en_curso`, incluidas reservas futuras). Lo muestra como notificación. Un admin editándose a sí mismo ve `rol` y `activo` deshabilitados, y además `EditUsuario` los descarta al guardar.
- **A11. Etiquetas y colores en los enums.** `RolUsuario`, `EstadoChofer`, `EstadoViaje`, `TipoViaje`, `ModoViaje` y `ResultadoOferta` implementan `HasLabel` (y `HasColor` donde hay badge) de Filament. Los valores no cambian, así que la API tampoco.
- **A12. Panel en español.** Filament trae traducciones `es`. El middleware persistente `PanelEnEspanol` fija el idioma solo en el panel (y en sus requests de Livewire); la API sigue con `APP_LOCALE`.
- **A13. Clave de Google para el navegador.** La clave del mapa queda a la vista en el navegador, y la del servidor tiene habilitadas Distance Matrix y Directions. Se agrega la opcional `GOOGLE_MAPS_JS_API_KEY` (`vehiculos.mapas.google_js_api_key`, conviene restringirla por HTTP referrer). Si falta, se usa `google_api_key`, como pide la decisión 8. El mapa usa `google.maps.Marker`, obsoleto pero soportado; `AdvancedMarkerElement` exige un Map ID. El refresco es `wire:poll.10s="refrescar"` → `$this->dispatch('mapa-datos', ...)` → `$wire.$on(...)` dentro de `@script` (Livewire 4), y el `div` del mapa lleva `wire:ignore` para no redibujarse. Los viajes que se dibujan son `Viaje::activos()` (con chofer): los que están `buscando` u `ofrecido` no.
- **A14. Alerta sin señal.** Solo cuenta a choferes con turno abierto (la spec 4.1 define "sin señal" con turno abierto; sin turno ya existe la alerta de reserva sin turno) y usa `no_disponible_min`. La clave de deduplicación es (viaje, chofer): si el viaje se reasigna, la alerta vieja se resuelve y, si el chofer nuevo tampoco tiene señal, se crea otra. Se agrega el scope `Viaje::activos()` y `activosDeChofer` pasa a usarlo, con el mismo criterio. En producción hace falta el cron de `schedule:run` o `php artisan schedule:work`.
- **A15. "Viajes sin chofer en 24 h"** se cuenta por `updated_at`, porque no hay una columna con el momento de la transición a `sin_chofer`.
- **A16. Carreras dentro del panel.** Si el viaje cambió con el modal abierto, Filament vuelve a evaluar `visible()` en el request siguiente y no ejecuta la acción. El select de choferes valida contra las opciones vigentes, y el servicio igual verifica todo con bloqueo. El camino "`ReglaNegocio` → notificación" se prueba con el servicio simulado (mock).
- **A17. Vehículos:** "Eliminar" solo aparece si el vehículo no tiene turnos ni viajes (si los tiene, la clave foránea igual lo impediría). El widget del tablero carga en diferido (lazy), así que el test del tablero verifica que el componente esté presente, y los números se prueban en el propio widget.

## Global Constraints

- Nombres de tablas, columnas, clases, rutas, mensajes y tests en **español**, como en los planes anteriores.
- Toda transición de estado pasa por `MaquinaEstadosViaje`. Las exclusivas del admin (`SOLO_ADMIN` y `reasignar()`) solo se usan desde `ServicioViaje::cancelarPorAdmin` y `reasignarPorAdmin`, nunca desde controladores de la API.
- Filament nunca modifica un `Viaje` directamente: las acciones llaman a `ServicioViaje`. Las `ReglaNegocio` y `AccionNoPermitida` se muestran como `Notification::make()->danger()`.
- Los servicios del admin bloquean con `lockForUpdate()` primero el viaje y después el chofer (el mismo orden que `Asignador`), validan contra la fila bloqueada y usan `DB::transaction(..., attempts: 3)`.
- El código de Filament es para la **5.9** (A1). No copiar ejemplos de Filament 3 (`Filament\Forms\Form`, `Filament\Tables\Actions\*`, `->form()` en filtros, `protected static string $view`).
- Tests del panel: `$this->actingAs(Usuario::factory()->admin()->create())` y `Livewire::test(Pagina::class, ['record' => $modelo->getRouteKey()])`. Para requests HTTP: `$this->get(Recurso::getUrl(...))`.
- En los tests donde importa la hora se congela con `$this->travelTo(Carbon::parse('2026-10-01 12:00:00'))` (UTC = 09:00 en Buenos Aires). `Queue::fake()` o `Bus::fake([...])` como en los tests existentes, y `NotificadorFalso` para los pushes.
- Migración nueva; las existentes no se editan.
- Cada commit termina con la línea `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Los comandos `composer`, `php artisan` y `./vendor/bin/pest` se corren desde `backend/`; los `git` desde la raíz del repo.

## Review Focus

1. **Solo un admin activo entra al panel:** solicitante, chofer y admin inactivo reciben 403 en `/admin`, y un chofer con contraseña no puede iniciar sesión. Tests en Task 1.
2. **Cancelar `en_curso` desde el panel no se lo habilita al solicitante:** `puede(EnCurso, Cancelado)` sigue en `false` sin `comoAdmin`, `cancelarPorSolicitante` sobre un `en_curso` sigue lanzando `TransicionInvalida`, y `MaquinaEstadosViajeTest` queda sin cambios. Tests en Task 4.
3. **Reasignar una reserva no deja recordatorios del chofer anterior:** sus `RecordarReserva` y `AlertarReservaSinTurno` no mandan nada ni crean alertas, y se encolan los del chofer nuevo. Test en Task 5.
4. **No se puede reasignar a un chofer ocupado, ni siquiera en una carrera:** un chofer no libre o con una reserva superpuesta se rechaza, también con copias desactualizadas (Task 5). La carrera real contra MySQL/MariaDB, reasignación contra asignación de un obligatorio al mismo chofer, deja un solo viaje activo (Task 11).
5. **Un parámetro editado rige enseguida:** después de cambiar `oferta_segundos` a 45 desde el panel, la oferta siguiente vence a los 45 s. Los valores < 1 o no enteros se rechazan. Tests en Task 9.

## Estructura de archivos

```
backend/
  composer.json, composer.lock, .gitignore, bootstrap/providers.php   # Filament ~5.9 (filament:install)
  .env.example                                         # GOOGLE_MAPS_API_KEY y GOOGLE_MAPS_JS_API_KEY
  config/vehiculos.php                                 # + mapas.google_js_api_key
  database/migrations/2026_09_29_000002_agregar_acceso_panel_a_usuarios.php
  app/Providers/Filament/AdminPanelProvider.php        # panel /admin
  app/Http/Middleware/PanelEnEspanol.php
  app/Console/Commands/CrearAdmin.php                  # vehiculos:crear-admin
  app/Console/Commands/AlertarSinSenal.php             # vehiculos:alertar-sin-senal
  app/Models/Usuario.php                               # FilamentUser, HasName, email/password
  app/Models/Vehiculo.php                              # turnos(), viajes(), tieneHistorial()
  app/Models/Viaje.php                                 # scope activos()
  app/Models/Alerta.php                                # CHOFER_SIN_SENAL
  app/Enums/{RolUsuario,EstadoChofer,EstadoViaje,TipoViaje,ModoViaje,ResultadoOferta}.php  # etiquetas/colores
  app/Events/ViajeActualizado.php                      # + porAdmin
  app/Listeners/AvisosViaje.php                        # textos de cancelación y reasignación del admin
  app/Servicios/MaquinaEstadosViaje.php                # SOLO_ADMIN, transicionarComoAdmin(), reasignar()
  app/Servicios/ServicioViaje.php                      # cancelarPorAdmin(), reasignarPorAdmin()
  app/Servicios/AlertasSinSenal.php, ResumenPanel.php, DatosMapaPanel.php
  app/Filament/Resources/Vehiculos/...                 # VehiculoResource + List/Create/Edit
  app/Filament/Resources/CargosPrioritarios/...        # CargoPrioritarioResource + List/Create/Edit
  app/Filament/Resources/Usuarios/...                  # UsuarioResource + List/Edit + TurnosRelationManager
  app/Filament/Resources/Viajes/...                    # ViajeResource + List/View + OfertasRelationManager
  app/Filament/Resources/Alertas/...                   # AlertaResource + List
  app/Filament/Pages/ConfiguracionParametros.php, MapaEnVivo.php
  app/Filament/Widgets/ResumenOperativo.php
  resources/views/filament/pages/mapa-en-vivo.blade.php
  routes/console.php                                   # alertar-sin-senal cada minuto
  tests/Feature/Panel/{AccesoPanel,VehiculosYCargosPanel,UsuariosPanel,ViajesPanel,AlertasPanel,ParametrosPanel}Test.php
  tests/Feature/Panel/MapaEnVivoTest.php
  tests/Feature/{CancelacionAdmin,ReasignacionAdmin,AlertasSinSenal}Test.php
  tests/Concurrencia/CarrerasTest.php, proceso_carrera.php
```

`tests/Pest.php` no cambia: los helpers nuevos de cada test tienen nombres propios (`viajeDeChofer`, `inmediatoDe`, `viajeActivoDe`) para no chocar con los existentes (`viajeAsignado`, `sinJobsDiferidos`).

---

### Task 1: Instalar Filament y acceso al panel para admins

**Files:**
- Modify (con `composer` y `filament:install`): `backend/composer.json`, `backend/composer.lock`, `backend/.gitignore`, `backend/bootstrap/providers.php`
- Create (con `filament:install`, después se reemplaza): `backend/app/Providers/Filament/AdminPanelProvider.php`
- Create: `backend/database/migrations/2026_09_29_000002_agregar_acceso_panel_a_usuarios.php`
- Create: `backend/app/Http/Middleware/PanelEnEspanol.php`
- Create: `backend/app/Console/Commands/CrearAdmin.php`
- Modify: `backend/app/Models/Usuario.php`
- Test: `backend/tests/Feature/Panel/AccesoPanelTest.php`

**Interfaces:**
- Consumes: `Usuario`, `RolUsuario`, `UsuarioFactory::admin()` / `chofer()` (planes anteriores).
- Produces:
  - Panel Filament `admin` en `/admin` con login (`/admin/login`), en español, y descubrimiento automático de `app/Filament/{Resources,Pages,Widgets}`.
  - `usuarios.email` (nullable, único), `usuarios.password` (nullable, `hashed`, oculto), `usuarios.remember_token`.
  - `Usuario::esAdmin(): bool`, `Usuario::canAccessPanel(Panel): bool` (admin y activo), `Usuario::getFilamentName(): string`.
  - Comando `vehiculos:crear-admin {email} {--nombre=} {--id-externo=} {--password=}`.

- [ ] **Step 1: Instalar Filament**

Desde `backend/`:

```bash
composer require "filament/filament:~5.9" -W
php artisan filament:install --panels --no-interaction
composer show filament/filament livewire/livewire
```

Expected: `filament/filament` **v5.9.x** y `livewire/livewire` **v4.x**. `filament:install` crea `app/Providers/Filament/AdminPanelProvider.php` (id `admin`, path `admin`) y lo registra en `bootstrap/providers.php`, agrega `"@php artisan filament:upgrade"` al `post-autoload-dump` de `composer.json`, ignora `public/css/filament`, `public/js/filament` y `public/fonts/filament` en `.gitignore` y publica esos assets. En `composer.json` tiene que quedar `"filament/filament": "~5.9"` (ver A2: no usar `^` desde `cmd`/PowerShell).

- [ ] **Step 2: Verificar que no se rompió nada**

Run: `./vendor/bin/pest`
Expected: 212 PASS.

- [ ] **Step 3: Escribir el test que falla**

`backend/tests/Feature/Panel/AccesoPanelTest.php`:

```php
<?php

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('manda al login a quien no inició sesión', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('deja entrar a un admin activo y muestra el panel en español', function () {
    $this->actingAs(Usuario::factory()->admin()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Escritorio');

    expect(app()->getLocale())->toBe('es');
});

it('no cambia el idioma de la API', function () {
    $this->actingAs(Usuario::factory()->create())->getJson('/api/yo')->assertOk();

    expect(app()->getLocale())->toBe(config('app.locale'));
});

it('rechaza con 403 a solicitantes, choferes y admins inactivos', function (array $atributos) {
    $this->actingAs(Usuario::factory()->create($atributos))->get('/admin')->assertForbidden();
})->with([
    'solicitante' => [['rol' => RolUsuario::Solicitante]],
    'chofer' => [['rol' => RolUsuario::Chofer]],
    'admin inactivo' => [['rol' => RolUsuario::Admin, 'activo' => false]],
]);

it('inicia sesión con email y contraseña', function () {
    $admin = Usuario::factory()->admin()->create(['email' => 'admin@pj.gob.ar', 'password' => 'secreta123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'admin@pj.gob.ar', 'password' => 'secreta123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($admin);
});

it('no inicia sesión a un chofer aunque tenga contraseña', function () {
    Usuario::factory()->chofer()->create(['email' => 'chofer@pj.gob.ar', 'password' => 'secreta123']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'chofer@pj.gob.ar', 'password' => 'secreta123'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('no expone la contraseña al serializar el usuario', function () {
    $admin = Usuario::factory()->admin()->create(['email' => 'a@pj.gob.ar', 'password' => 'secreta123']);

    expect($admin->toArray())->not->toHaveKey('password')
        ->and(Hash::check('secreta123', $admin->fresh()->password))->toBeTrue();
});

it('crea un admin nuevo desde la consola', function () {
    $this->artisan('vehiculos:crear-admin', ['email' => 'Nuevo@PJ.gob.ar', '--nombre' => 'Ana Admin', '--password' => 'secreta123'])
        ->assertSuccessful();

    $admin = Usuario::where('email', 'nuevo@pj.gob.ar')->sole();
    expect($admin->rol)->toBe(RolUsuario::Admin)
        ->and($admin->nombre)->toBe('Ana Admin')
        ->and($admin->id_externo)->toBe('panel:nuevo@pj.gob.ar')
        ->and(Hash::check('secreta123', $admin->password))->toBeTrue();
});

it('promueve a admin a un usuario que ya entró por la app', function () {
    $usuario = Usuario::factory()->create(['id_externo' => '4242', 'activo' => false]);

    $this->artisan('vehiculos:crear-admin', ['email' => 'jefe@pj.gob.ar', '--id-externo' => '4242', '--password' => 'secreta123'])
        ->assertSuccessful();

    expect($usuario->fresh())
        ->rol->toBe(RolUsuario::Admin)
        ->activo->toBeTrue()
        ->email->toBe('jefe@pj.gob.ar')
        ->and(Usuario::count())->toBe(1);
});

it('pide la contraseña por consola si no se pasa como opción', function () {
    $this->artisan('vehiculos:crear-admin', ['email' => 'a@pj.gob.ar'])
        ->expectsQuestion('Contraseña', 'secreta123')
        ->expectsQuestion('Repetí la contraseña', 'secreta123')
        ->assertSuccessful();

    expect(Hash::check('secreta123', Usuario::where('email', 'a@pj.gob.ar')->value('password')))->toBeTrue();
});

it('rechaza contraseñas cortas, emails usados por otro y ids externos inexistentes', function (array $argumentos) {
    Usuario::factory()->create(['email' => 'ocupado@pj.gob.ar']);
    Usuario::factory()->create(['id_externo' => '777']);

    $this->artisan('vehiculos:crear-admin', $argumentos)->assertFailed();

    expect(Usuario::where('rol', RolUsuario::Admin)->exists())->toBeFalse();
})->with([
    'contraseña corta' => [['email' => 'a@pj.gob.ar', '--password' => 'corta']],
    'email de otro' => [['email' => 'ocupado@pj.gob.ar', '--id-externo' => '777', '--password' => 'secreta123']],
    'id externo inexistente' => [['email' => 'b@pj.gob.ar', '--id-externo' => 'no-existe', '--password' => 'secreta123']],
]);
```

- [ ] **Step 4: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/Panel/AccesoPanelTest.php`
Expected: FAIL. El admin activo recibe 403: `Usuario` no implementa `FilamentUser`, y en ese caso Filament solo deja pasar con `APP_ENV=local` (los 403 de los otros roles pasan por el mismo motivo, todavía no por la regla). Los tests de login y del comando fallan porque no existen las columnas `email`/`password` ni el comando `vehiculos:crear-admin`.

- [ ] **Step 5: Migración**

`backend/database/migrations/2026_09_29_000002_agregar_acceso_panel_a_usuarios.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Solo los administradores del panel tienen email y contraseña; el resto entra con el token del PJ.
        Schema::table('usuarios', function (Blueprint $t) {
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->rememberToken();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropUnique(['email']);
            $t->dropColumn(['email', 'password', 'remember_token']);
        });
    }
};
```

- [ ] **Step 6: Modelo `Usuario`**

Reemplazar `backend/app/Models/Usuario.php` completo por:

```php
<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['id_externo', 'nombre', 'cargo', 'rol', 'telefono', 'token_push', 'activo', 'email', 'password'];

    protected $hidden = ['token_push', 'password', 'remember_token'];

    protected function casts(): array
    {
        return ['rol' => RolUsuario::class, 'activo' => 'boolean', 'password' => 'hashed'];
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class, 'chofer_id');
    }

    public function turnoAbierto(): HasOne
    {
        // Invariante (ServicioTurnos): como máximo un turno abierto por chofer.
        return $this->hasOne(Turno::class, 'chofer_id')->whereNull('fin');
    }

    public function ubicacion(): HasOne
    {
        return $this->hasOne(UbicacionChofer::class, 'chofer_id');
    }

    public function esChofer(): bool
    {
        return $this->rol === RolUsuario::Chofer;
    }

    public function esAdmin(): bool
    {
        return $this->rol === RolUsuario::Admin;
    }

    /** Panel Filament (spec 8): solo administradores activos. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->esAdmin() && $this->activo;
    }

    public function getFilamentName(): string
    {
        return $this->nombre;
    }
}
```

- [ ] **Step 7: Panel en español y provider del panel**

`backend/app/Http/Middleware/PanelEnEspanol.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** El panel se muestra en español sin cambiar el idioma de la API (APP_LOCALE). */
class PanelEnEspanol
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('es');

        return $next($request);
    }
}
```

Reemplazar `backend/app/Providers/Filament/AdminPanelProvider.php` (el que generó `filament:install`) completo por:

```php
<?php

namespace App\Providers\Filament;

use App\Http\Middleware\PanelEnEspanol;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Vehículos Oficiales')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([PanelEnEspanol::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

Diferencias con el generado: marca "Vehículos Oficiales", color azul, sin `FilamentInfoWidget` y con `PanelEnEspanol` como middleware persistente (se reaplica en los requests de Livewire del panel).

- [ ] **Step 8: Comando `vehiculos:crear-admin`**

`backend/app/Console/Commands/CrearAdmin.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/** Da acceso al panel: los usuarios de la app no tienen contraseña (entran con el token del PJ). */
class CrearAdmin extends Command
{
    protected $signature = 'vehiculos:crear-admin
        {email : Email con el que entra al panel}
        {--nombre= : Nombre, si hay que crear el usuario}
        {--id-externo= : Id del PJ de un usuario existente a promover}
        {--password= : Contraseña (sin esta opción se pide por consola)}';

    protected $description = 'Crea un administrador del panel o promueve a un usuario existente';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        $idExterno = $this->option('id-externo');

        $usuario = $idExterno !== null
            ? Usuario::where('id_externo', $idExterno)->first()
            : Usuario::where('email', $email)->first();

        if ($idExterno !== null && ! $usuario) {
            $this->error("No existe un usuario con id externo $idExterno.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?? $this->pedirPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        $validacion = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email'], 'password' => ['required', 'string', 'min:8']],
        );
        if ($validacion->fails()) {
            foreach ($validacion->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (Usuario::where('email', $email)->when($usuario, fn ($q) => $q->whereKeyNot($usuario->id))->exists()) {
            $this->error("El email $email ya lo usa otro usuario.");

            return self::FAILURE;
        }

        $usuario ??= new Usuario([
            'id_externo' => "panel:$email",
            'nombre' => $this->option('nombre') ?? $email,
        ]);
        $usuario->fill([
            'email' => $email,
            'password' => $password,
            'rol' => RolUsuario::Admin,
            'activo' => true,
        ])->save();

        $this->info("{$usuario->nombre} ($email) ya puede entrar al panel en /admin.");

        return self::SUCCESS;
    }

    private function pedirPassword(): ?string
    {
        $password = $this->secret('Contraseña');
        if ($password !== $this->secret('Repetí la contraseña')) {
            $this->error('Las contraseñas no coinciden.');

            return null;
        }

        return $password;
    }
}
```

- [ ] **Step 9: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (227).

- [ ] **Step 10: Commit**

```bash
git add backend
git commit -m "feat: panel Filament en /admin solo para administradores" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Vehículos y cargos prioritarios

**Files:**
- Modify: `backend/app/Models/Vehiculo.php`
- Create: `backend/app/Filament/Resources/Vehiculos/VehiculoResource.php`
- Create: `backend/app/Filament/Resources/Vehiculos/Pages/{ListVehiculos,CreateVehiculo,EditVehiculo}.php`
- Create: `backend/app/Filament/Resources/CargosPrioritarios/CargoPrioritarioResource.php`
- Create: `backend/app/Filament/Resources/CargosPrioritarios/Pages/{ListCargosPrioritarios,CreateCargoPrioritario,EditCargoPrioritario}.php`
- Test: `backend/tests/Feature/Panel/VehiculosYCargosPanelTest.php`

**Interfaces:**
- Consumes: panel de la Task 1, `Vehiculo`, `CargoPrioritario::esObligatorio()`, `Turno`.
- Produces: `Vehiculo::turnos(): HasMany`, `Vehiculo::viajes(): HasMany`, `Vehiculo::tieneHistorial(): bool`; recursos `/admin/vehiculos` y `/admin/cargos-prioritarios`.

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/Panel/VehiculosYCargosPanelTest.php`:

```php
<?php

use App\Filament\Resources\CargosPrioritarios\Pages\CreateCargoPrioritario;
use App\Filament\Resources\CargosPrioritarios\Pages\ListCargosPrioritarios;
use App\Filament\Resources\Vehiculos\Pages\CreateVehiculo;
use App\Filament\Resources\Vehiculos\Pages\EditVehiculo;
use App\Filament\Resources\Vehiculos\Pages\ListVehiculos;
use App\Models\CargoPrioritario;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(Usuario::factory()->admin()->create()));

it('lista los vehículos y filtra los activos', function () {
    $activo = Vehiculo::factory()->create();
    $inactivo = Vehiculo::factory()->create(['activo' => false]);

    Livewire::test(ListVehiculos::class)
        ->assertCanSeeTableRecords([$activo, $inactivo])
        ->filterTable('activo', true)
        ->assertCanSeeTableRecords([$activo])
        ->assertCanNotSeeTableRecords([$inactivo]);
});

it('crea un vehículo', function () {
    Livewire::test(CreateVehiculo::class)
        ->fillForm(['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Etios', 'color' => 'Gris'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Vehiculo::sole())->patente->toBe('AB123CD')->activo->toBeTrue();
});

it('no acepta una patente repetida', function () {
    Vehiculo::factory()->create(['patente' => 'AB123CD']);

    Livewire::test(CreateVehiculo::class)
        ->fillForm(['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Etios'])
        ->call('create')
        ->assertHasFormErrors(['patente' => 'unique']);
});

it('desactiva un vehículo desde la edición', function () {
    $vehiculo = Vehiculo::factory()->create();

    Livewire::test(EditVehiculo::class, ['record' => $vehiculo->getRouteKey()])
        ->fillForm(['activo' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($vehiculo->fresh()->activo)->toBeFalse();
});

it('solo deja borrar vehículos sin historial', function () {
    $usado = Turno::factory()->create()->vehiculo;
    $nuevo = Vehiculo::factory()->create();

    Livewire::test(EditVehiculo::class, ['record' => $usado->getRouteKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditVehiculo::class, ['record' => $nuevo->getRouteKey()])
        ->callAction('delete');

    expect(Vehiculo::pluck('id')->all())->toBe([$usado->id]);
});

it('administra los cargos prioritarios', function () {
    Livewire::test(CreateCargoPrioritario::class)
        ->fillForm(['cargo' => 'Juez', 'obligatorio' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateCargoPrioritario::class)
        ->fillForm(['cargo' => 'Juez'])
        ->call('create')
        ->assertHasFormErrors(['cargo' => 'unique']);

    expect(CargoPrioritario::esObligatorio('Juez'))->toBeTrue();

    Livewire::test(ListCargosPrioritarios::class)
        ->callAction(TestAction::make('delete')->table(CargoPrioritario::sole()));

    expect(CargoPrioritario::count())->toBe(0);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/Panel/VehiculosYCargosPanelTest.php`
Expected: FAIL con `Class "App\Filament\Resources\Vehiculos\Pages\ListVehiculos" not found` (y lo mismo con las páginas de cargos).

- [ ] **Step 3: Modelo `Vehiculo`**

Reemplazar `backend/app/Models/Vehiculo.php` completo por:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehiculo extends Model
{
    use HasFactory;

    protected $table = 'vehiculos';

    protected $fillable = ['patente', 'marca', 'modelo', 'color', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(Turno::class);
    }

    public function viajes(): HasMany
    {
        return $this->hasMany(Viaje::class);
    }

    /** Un vehículo que ya se usó no se borra (lo referencian turnos y viajes): se desactiva. */
    public function tieneHistorial(): bool
    {
        return $this->turnos()->exists() || $this->viajes()->exists();
    }
}
```

- [ ] **Step 4: Recurso de vehículos**

`backend/app/Filament/Resources/Vehiculos/VehiculoResource.php`:

```php
<?php

namespace App\Filament\Resources\Vehiculos;

use App\Filament\Resources\Vehiculos\Pages\CreateVehiculo;
use App\Filament\Resources\Vehiculos\Pages\EditVehiculo;
use App\Filament\Resources\Vehiculos\Pages\ListVehiculos;
use App\Models\Vehiculo;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** ABM de vehículos (spec 8.2). No se borran los que tienen historial: se desactivan. */
class VehiculoResource extends Resource
{
    protected static ?string $model = Vehiculo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $modelLabel = 'vehículo';

    protected static ?string $pluralModelLabel = 'vehículos';

    protected static ?string $recordTitleAttribute = 'patente';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('patente')
                    ->required()
                    ->maxLength(20)
                    ->unique(),
                TextInput::make('marca')
                    ->required()
                    ->maxLength(100),
                TextInput::make('modelo')
                    ->required()
                    ->maxLength(100),
                TextInput::make('color')
                    ->maxLength(50),
                Toggle::make('activo')
                    ->helperText('Un vehículo inactivo no se ofrece para iniciar turno.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patente')->searchable()->sortable(),
                TextColumn::make('marca')->searchable(),
                TextColumn::make('modelo')->searchable(),
                TextColumn::make('color'),
                IconColumn::make('activo')->boolean(),
            ])
            ->defaultSort('patente')
            ->filters([
                TernaryFilter::make('activo'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehiculos::route('/'),
            'create' => CreateVehiculo::route('/create'),
            'edit' => EditVehiculo::route('/{record}/edit'),
        ];
    }
}
```

`backend/app/Filament/Resources/Vehiculos/Pages/ListVehiculos.php`:

```php
<?php

namespace App\Filament\Resources\Vehiculos\Pages;

use App\Filament\Resources\Vehiculos\VehiculoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVehiculos extends ListRecords
{
    protected static string $resource = VehiculoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

`backend/app/Filament/Resources/Vehiculos/Pages/CreateVehiculo.php`:

```php
<?php

namespace App\Filament\Resources\Vehiculos\Pages;

use App\Filament\Resources\Vehiculos\VehiculoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVehiculo extends CreateRecord
{
    protected static string $resource = VehiculoResource::class;
}
```

`backend/app/Filament/Resources/Vehiculos/Pages/EditVehiculo.php`:

```php
<?php

namespace App\Filament\Resources\Vehiculos\Pages;

use App\Filament\Resources\Vehiculos\VehiculoResource;
use App\Models\Vehiculo;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVehiculo extends EditRecord
{
    protected static string $resource = VehiculoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Con turnos o viajes lo referencian claves foráneas: se desactiva en lugar de borrarse.
            DeleteAction::make()->hidden(fn (Vehiculo $record): bool => $record->tieneHistorial()),
        ];
    }
}
```

- [ ] **Step 5: Recurso de cargos prioritarios**

`backend/app/Filament/Resources/CargosPrioritarios/CargoPrioritarioResource.php`:

```php
<?php

namespace App\Filament\Resources\CargosPrioritarios;

use App\Filament\Resources\CargosPrioritarios\Pages\CreateCargoPrioritario;
use App\Filament\Resources\CargosPrioritarios\Pages\EditCargoPrioritario;
use App\Filament\Resources\CargosPrioritarios\Pages\ListCargosPrioritarios;
use App\Models\CargoPrioritario;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Cargos que generan viajes obligatorios (spec 8.4). El cargo se compara tal cual llega del PJ. */
class CargoPrioritarioResource extends Resource
{
    protected static ?string $model = CargoPrioritario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $modelLabel = 'cargo prioritario';

    protected static ?string $pluralModelLabel = 'cargos prioritarios';

    protected static ?string $slug = 'cargos-prioritarios';

    protected static ?string $recordTitleAttribute = 'cargo';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('cargo')
                    ->required()
                    ->maxLength(255)
                    ->unique()
                    ->helperText('Tal como lo informa el Poder Judicial (se distinguen mayúsculas y acentos).'),
                Toggle::make('obligatorio')
                    ->helperText('Los viajes de este cargo se asignan directo y el chofer no puede rechazarlos.')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cargo')->searchable()->sortable(),
                IconColumn::make('obligatorio')->boolean(),
            ])
            ->defaultSort('cargo')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCargosPrioritarios::route('/'),
            'create' => CreateCargoPrioritario::route('/create'),
            'edit' => EditCargoPrioritario::route('/{record}/edit'),
        ];
    }
}
```

`backend/app/Filament/Resources/CargosPrioritarios/Pages/ListCargosPrioritarios.php`:

```php
<?php

namespace App\Filament\Resources\CargosPrioritarios\Pages;

use App\Filament\Resources\CargosPrioritarios\CargoPrioritarioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCargosPrioritarios extends ListRecords
{
    protected static string $resource = CargoPrioritarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

`backend/app/Filament/Resources/CargosPrioritarios/Pages/CreateCargoPrioritario.php`:

```php
<?php

namespace App\Filament\Resources\CargosPrioritarios\Pages;

use App\Filament\Resources\CargosPrioritarios\CargoPrioritarioResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCargoPrioritario extends CreateRecord
{
    protected static string $resource = CargoPrioritarioResource::class;
}
```

`backend/app/Filament/Resources/CargosPrioritarios/Pages/EditCargoPrioritario.php`:

```php
<?php

namespace App\Filament\Resources\CargosPrioritarios\Pages;

use App\Filament\Resources\CargosPrioritarios\CargoPrioritarioResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCargoPrioritario extends EditRecord
{
    protected static string $resource = CargoPrioritarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
```

- [ ] **Step 6: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (233).

- [ ] **Step 7: Commit**

```bash
git add backend
git commit -m "feat: ABM de vehículos y cargos prioritarios en el panel" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Usuarios, choferes y sus turnos

**Files:**
- Modify: `backend/app/Enums/RolUsuario.php`, `backend/app/Enums/EstadoChofer.php`
- Create: `backend/app/Filament/Resources/Usuarios/UsuarioResource.php`
- Create: `backend/app/Filament/Resources/Usuarios/Pages/{ListUsuarios,EditUsuario}.php`
- Create: `backend/app/Filament/Resources/Usuarios/RelationManagers/TurnosRelationManager.php`
- Test: `backend/tests/Feature/Panel/UsuariosPanelTest.php`

**Interfaces:**
- Consumes: `CalculadorEstadoChofer::estado()`, `Usuario::turnos()`, `Usuario::turnoAbierto()`, `EstadoViaje::conChofer()`, helpers `choferEnTurno()` y `reservaAceptada()`.
- Produces: `RolUsuario` y `EstadoChofer` con `getLabel()` (y `EstadoChofer::getColor()`); recurso `/admin/usuarios` (listado y edición, sin alta); `TurnosRelationManager` (solo lectura, solo choferes).

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/Panel/UsuariosPanelTest.php`:

```php
<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\Pages\EditUsuario;
use App\Filament\Resources\Usuarios\Pages\ListUsuarios;
use App\Filament\Resources\Usuarios\RelationManagers\TurnosRelationManager;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs($this->admin = Usuario::factory()->admin()->create()));

it('filtra por rol y por activo', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $inactivo = Usuario::factory()->create(['activo' => false]);

    Livewire::test(ListUsuarios::class)
        ->assertCanSeeTableRecords([$this->admin, $chofer, $inactivo])
        ->filterTable('rol', RolUsuario::Chofer)
        ->assertCanSeeTableRecords([$chofer])
        ->assertCanNotSeeTableRecords([$this->admin, $inactivo])
        ->resetTableFilters()
        ->filterTable('activo', false)
        ->assertCanSeeTableRecords([$inactivo])
        ->assertCanNotSeeTableRecords([$this->admin, $chofer]);
});

it('muestra el estado calculado solo para los choferes', function () {
    $libre = choferEnTurno();
    $fuera = Usuario::factory()->chofer()->create();
    $solicitante = Usuario::factory()->create();

    Livewire::test(ListUsuarios::class)
        ->assertTableColumnStateSet('estado_chofer', EstadoChofer::Libre, $libre)
        ->assertTableColumnStateSet('estado_chofer', EstadoChofer::FueraDeTurno, $fuera)
        ->assertTableColumnStateSet('estado_chofer', null, $solicitante);
});

it('le da el rol de chofer a un solicitante sin tocar sus datos del PJ', function () {
    $usuario = Usuario::factory()->create(['nombre' => 'Juan Pérez', 'cargo' => 'Ujier']);

    Livewire::test(EditUsuario::class, ['record' => $usuario->getRouteKey()])
        ->assertFormFieldDisabled('nombre')
        ->assertFormFieldDisabled('cargo')
        ->assertFormFieldDisabled('id_externo')
        ->fillForm(['rol' => RolUsuario::Chofer->value, 'nombre' => 'Otro'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($usuario->fresh())
        ->rol->toBe(RolUsuario::Chofer)
        ->nombre->toBe('Juan Pérez');
});

it('un admin no puede quitarse el rol ni desactivarse', function () {
    Livewire::test(EditUsuario::class, ['record' => $this->admin->getRouteKey()])
        ->assertFormFieldDisabled('rol')
        ->assertFormFieldDisabled('activo')
        ->fillForm(['rol' => RolUsuario::Solicitante->value, 'activo' => false])
        ->call('save');

    expect($this->admin->fresh())
        ->rol->toBe(RolUsuario::Admin)
        ->activo->toBeTrue();
});

it('no deja quitarle el rol a un chofer con turno abierto o viajes asignados', function () {
    $enTurno = choferEnTurno();
    $conReserva = Usuario::factory()->chofer()->create();
    reservaAceptada($conReserva, now()->addDay());

    foreach ([$enTurno, $conReserva] as $chofer) {
        Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
            ->fillForm(['activo' => false])
            ->call('save')
            ->assertNotified('El chofer tiene un turno abierto o viajes asignados.');

        expect($chofer->fresh()->activo)->toBeTrue();
    }
});

it('desactiva a un chofer sin trabajo pendiente', function () {
    $chofer = Usuario::factory()->chofer()->create();
    Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado]);

    Livewire::test(EditUsuario::class, ['record' => $chofer->getRouteKey()])
        ->fillForm(['activo' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($chofer->fresh()->activo)->toBeFalse();
});

it('muestra los turnos del chofer', function () {
    $turno = Turno::factory()->create(['fin' => now()]);

    Livewire::test(TurnosRelationManager::class, [
        'ownerRecord' => $turno->chofer,
        'pageClass' => EditUsuario::class,
    ])->assertCanSeeTableRecords([$turno]);

    expect(TurnosRelationManager::canViewForRecord($turno->chofer, EditUsuario::class))->toBeTrue()
        ->and(TurnosRelationManager::canViewForRecord(Usuario::factory()->create(), EditUsuario::class))->toBeFalse();
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/Panel/UsuariosPanelTest.php`
Expected: FAIL con `Class "App\Filament\Resources\Usuarios\Pages\ListUsuarios" not found`.

- [ ] **Step 3: Etiquetas de rol y estado del chofer**

Reemplazar `backend/app/Enums/RolUsuario.php` completo por:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RolUsuario: string implements HasLabel
{
    case Solicitante = 'solicitante';
    case Chofer = 'chofer';
    case Admin = 'admin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Solicitante => 'Solicitante',
            self::Chofer => 'Chofer',
            self::Admin => 'Administrador',
        };
    }
}
```

Reemplazar `backend/app/Enums/EstadoChofer.php` completo por:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstadoChofer: string implements HasColor, HasLabel
{
    case FueraDeTurno = 'fuera_de_turno';
    case SinSenal = 'sin_senal';
    case EnViaje = 'en_viaje';
    case ReservadoPronto = 'reservado_pronto';
    case Libre = 'libre';

    public function getLabel(): string
    {
        return match ($this) {
            self::FueraDeTurno => 'Fuera de turno',
            self::SinSenal => 'Sin señal',
            self::EnViaje => 'En viaje',
            self::ReservadoPronto => 'Reservado pronto',
            self::Libre => 'Libre',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FueraDeTurno => 'gray',
            self::SinSenal => 'danger',
            self::EnViaje => 'info',
            self::ReservadoPronto => 'warning',
            self::Libre => 'success',
        };
    }
}
```

- [ ] **Step 4: Recurso de usuarios**

`backend/app/Filament/Resources/Usuarios/UsuarioResource.php`:

```php
<?php

namespace App\Filament\Resources\Usuarios;

use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\Pages\EditUsuario;
use App\Filament\Resources\Usuarios\Pages\ListUsuarios;
use App\Filament\Resources\Usuarios\RelationManagers\TurnosRelationManager;
use App\Models\Usuario;
use App\Servicios\CalculadorEstadoChofer;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Usuarios y choferes (spec 8.3). Nombre, cargo e id externo vienen del PJ y no se editan acá;
 * el admin solo cambia el rol y si está activo. Los usuarios se crean al entrar por la app.
 */
class UsuarioResource extends Resource
{
    protected static ?string $model = Usuario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'usuario';

    protected static ?string $pluralModelLabel = 'usuarios y choferes';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        // Un admin no puede quitarse el rol ni desactivarse (EditUsuario lo refuerza al guardar).
        $esUnoMismo = fn (?Usuario $record): bool => $record?->is(Filament::auth()->user()) ?? false;

        return $schema
            ->components([
                TextInput::make('nombre')->disabled(),
                TextInput::make('cargo')->disabled(),
                TextInput::make('id_externo')->label('Id externo (PJ)')->disabled(),
                TextInput::make('email')->label('Email del panel')->disabled(),
                Select::make('rol')
                    ->options(RolUsuario::class)
                    ->required()
                    ->disabled($esUnoMismo),
                Toggle::make('activo')
                    ->helperText('Un usuario inactivo no puede entrar a la app ni al panel.')
                    ->disabled($esUnoMismo),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->searchable()->sortable(),
                TextColumn::make('cargo')->searchable(),
                TextColumn::make('rol')->badge(),
                TextColumn::make('estado_chofer')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Usuario $record) => $record->esChofer()
                        ? app(CalculadorEstadoChofer::class)->estado($record)
                        : null),
                IconColumn::make('activo')->boolean(),
                TextColumn::make('id_externo')->label('Id externo')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('nombre')
            ->filters([
                SelectFilter::make('rol')->options(RolUsuario::class),
                TernaryFilter::make('activo'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TurnosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsuarios::route('/'),
            'edit' => EditUsuario::route('/{record}/edit'),
        ];
    }
}
```

`backend/app/Filament/Resources/Usuarios/Pages/ListUsuarios.php`:

```php
<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Filament\Resources\Usuarios\UsuarioResource;
use Filament\Resources\Pages\ListRecords;

class ListUsuarios extends ListRecords
{
    protected static string $resource = UsuarioResource::class;
}
```

`backend/app/Filament/Resources/Usuarios/Pages/EditUsuario.php`:

```php
<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\Usuario;
use App\Models\Viaje;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUsuario extends EditRecord
{
    protected static string $resource = UsuarioResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Usuario $usuario */
        $usuario = $this->getRecord();

        // Los campos deshabilitados no se guardan, pero se refuerza por si llegan igual.
        if ($usuario->is(Filament::auth()->user())) {
            unset($data['rol'], $data['activo']);

            return $data;
        }

        $rol = $data['rol'] instanceof RolUsuario ? $data['rol'] : RolUsuario::from($data['rol']);
        $dejaDeManejar = $usuario->esChofer() && ($rol !== RolUsuario::Chofer || ! $data['activo']);

        if ($dejaDeManejar && $this->tieneTrabajoPendiente($usuario)) {
            Notification::make()
                ->danger()
                ->title('El chofer tiene un turno abierto o viajes asignados.')
                ->body('Cerrá su turno y reasigná o cancelá sus viajes antes de quitarle el rol o desactivarlo.')
                ->send();

            $this->halt();
        }

        return $data;
    }

    private function tieneTrabajoPendiente(Usuario $chofer): bool
    {
        return $chofer->turnoAbierto()->exists()
            || Viaje::where('chofer_id', $chofer->id)->whereIn('estado', EstadoViaje::conChofer())->exists();
    }
}
```

`backend/app/Filament/Resources/Usuarios/RelationManagers/TurnosRelationManager.php`:

```php
<?php

namespace App\Filament\Resources\Usuarios\RelationManagers;

use App\Models\Usuario;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Turnos del chofer, solo lectura: los abre y cierra el chofer desde la app. */
class TurnosRelationManager extends RelationManager
{
    protected static string $relationship = 'turnos';

    protected static ?string $title = 'Turnos';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Usuario && $ownerRecord->esChofer();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('inicio')->dateTime('d/m/Y H:i', config('vehiculos.zona_horaria'))->sortable(),
                TextColumn::make('fin')
                    ->dateTime('d/m/Y H:i', config('vehiculos.zona_horaria'))
                    ->placeholder('Abierto'),
                TextColumn::make('vehiculo.patente')->label('Vehículo'),
                TextColumn::make('origen')->formatStateUsing(fn ($state): string => ucfirst($state->value)),
            ])
            ->defaultSort('inicio', 'desc');
    }
}
```

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (240). Los tests de la API no cambian: los enums conservan sus valores.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: usuarios y choferes en el panel con estado calculado y turnos" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: El admin cancela cualquier viaje no terminado

**Files:**
- Modify: `backend/app/Servicios/MaquinaEstadosViaje.php`
- Modify: `backend/app/Events/ViajeActualizado.php`
- Modify: `backend/app/Listeners/AvisosViaje.php`
- Modify: `backend/app/Servicios/ServicioViaje.php`
- Test: `backend/tests/Feature/CancelacionAdminTest.php`

**Interfaces:**
- Consumes: `MaquinaEstadosViaje::transicionar()`, `ServicioViaje::cancelarPorSolicitante()`, `Despachador`, `NotificadorFalso`, helpers `choferEnTurno()` y `reservaAceptada()`.
- Produces:
  - `MaquinaEstadosViaje::puede(E $desde, E $hacia, bool $comoAdmin = false): bool`, `transicionarComoAdmin(Viaje, E $hacia, array $atributos = []): bool` y `reasignar(Viaje, int $choferId, ?int $vehiculoId): void` (este último lo usa la Task 5).
  - `ViajeActualizado::$porAdmin` (4.º parámetro del constructor, por defecto `false`).
  - `ServicioViaje::cancelarPorAdmin(Viaje, Usuario $admin, string $motivo): Viaje`, `ServicioViaje::cancelablePorAdmin(Viaje): bool` (estático, lo usa el panel).
  - Pushes: "Viaje cancelado" / "Reserva cancelada" al solicitante (con el motivo) y al chofer.

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/CancelacionAdminTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Excepciones\TransicionInvalida;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    // Cola sync para que corra el listener de push; los jobs con retraso se falsean.
    Bus::fake([App\Jobs\VencerOferta::class, App\Jobs\RecordarReserva::class, App\Jobs\AlertarReservaSinTurno::class]);
    $this->push = new NotificadorFalso();
    $this->app->instance(Notificador::class, $this->push);
    $this->admin = Usuario::factory()->admin()->create();
});

function viajeDeChofer(Usuario $chofer, EstadoViaje $estado, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto?->vehiculo_id,
        'estado' => $estado,
        'aceptado_en' => now(),
        ...$attrs,
    ]);
}

it('solo el admin tiene la transición de en_curso a cancelado', function () {
    $maquina = app(MaquinaEstadosViaje::class);

    expect($maquina->puede(EstadoViaje::EnCurso, EstadoViaje::Cancelado))->toBeFalse()
        ->and($maquina->puede(EstadoViaje::EnCurso, EstadoViaje::Cancelado, comoAdmin: true))->toBeTrue()
        ->and($maquina->puede(EstadoViaje::Finalizado, EstadoViaje::Cancelado, comoAdmin: true))->toBeFalse();
});

it('el admin cancela un viaje obligatorio en curso y avisa a ambos', function () {
    $chofer = choferEnTurno();
    $viaje = viajeDeChofer($chofer, EstadoViaje::EnCurso, ['obligatorio' => true]);

    app(ServicioViaje::class)->cancelarPorAdmin($viaje, $this->admin, '  Vehículo averiado  ');

    expect($viaje->fresh())
        ->estado->toBe(EstadoViaje::Cancelado)
        ->cancelado_por->toBe('admin')
        ->motivo_cancelacion->toBe('Vehículo averiado')
        ->cancelado_en->not->toBeNull();
    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Viaje cancelado'])
        ->and($this->push->titulosPara($chofer))->toBe(['Viaje cancelado'])
        ->and(collect($this->push->enviados)->firstWhere('destino', $viaje->solicitante_id)['cuerpo'])
        ->toBe('Un administrador canceló el viaje. Motivo: Vehículo averiado');
});

it('el admin cancela una reserva aceptada con el texto de reservas', function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($chofer, Carbon::parse('2026-10-02 15:00'), attrs: ['obligatorio' => true]);

    app(ServicioViaje::class)->cancelarPorAdmin($viaje, $this->admin, 'Se suspendió la audiencia');

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado)
        ->and($this->push->titulosPara($chofer))->toBe(['Reserva cancelada'])
        ->and(collect($this->push->enviados)->firstWhere('destino', $chofer->id)['cuerpo'])
        ->toBe('Un administrador canceló la reserva del 02/10 12:00.');
});

it('cancelar un viaje ofrecido vence la oferta pendiente', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]);
    app(Despachador::class)->despachar($viaje);

    app(ServicioViaje::class)->cancelarPorAdmin($viaje->fresh(), $this->admin, 'Pedido duplicado');

    expect(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada)
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado);
    expect(fn () => app(Despachador::class)->responder(OfertaViaje::sole(), true))
        ->toThrow(ReglaNegocio::class, 'La oferta ya no está vigente.');
});

it('no cancela viajes terminados ni sin motivo', function (EstadoViaje $estado, string $motivo, string $mensaje) {
    $viaje = Viaje::factory()->create(['estado' => $estado]);

    expect(fn () => app(ServicioViaje::class)->cancelarPorAdmin($viaje, $this->admin, $motivo))
        ->toThrow(ReglaNegocio::class, $mensaje);
    expect($viaje->fresh()->estado)->toBe($estado);
})->with([
    [EstadoViaje::Finalizado, 'x', 'El viaje ya terminó; no se puede cancelar.'],
    [EstadoViaje::SinChofer, 'x', 'El viaje ya terminó; no se puede cancelar.'],
    [EstadoViaje::Cancelado, 'x', 'El viaje ya estaba cancelado.'],
    [EstadoViaje::Buscando, '   ', 'Indicá el motivo de la cancelación.'],
]);

it('solo un admin puede usar la cancelación del panel', function () {
    $viaje = Viaje::factory()->create();

    expect(fn () => app(ServicioViaje::class)->cancelarPorAdmin($viaje, $viaje->solicitante, 'x'))
        ->toThrow(AccionNoPermitida::class);
});

it('el solicitante sigue sin poder cancelar un viaje en curso', function () {
    $viaje = viajeDeChofer(choferEnTurno(), EstadoViaje::EnCurso);

    expect(fn () => app(ServicioViaje::class)->cancelarPorSolicitante($viaje, $viaje->solicitante, null))
        ->toThrow(TransicionInvalida::class);
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::EnCurso);
});

it('con datos viejos no cancela un viaje que el chofer ya finalizó', function () {
    $chofer = choferEnTurno();
    $viaje = viajeDeChofer($chofer, EstadoViaje::EnCurso);
    $copiaDelPanel = Viaje::find($viaje->id);

    app(ServicioViaje::class)->avanzar(Viaje::find($viaje->id), $chofer, EstadoViaje::Finalizado);

    expect(fn () => app(ServicioViaje::class)->cancelarPorAdmin($copiaDelPanel, $this->admin, 'x'))
        ->toThrow(ReglaNegocio::class, 'El viaje ya terminó; no se puede cancelar.');
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Finalizado);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/CancelacionAdminTest.php`
Expected: FAIL. `puede()` no acepta `comoAdmin` (`Unknown named parameter $comoAdmin`) y `cancelarPorAdmin` no existe (`Call to undefined method`). Los dos tests del solicitante y del chofer que finaliza siguen en PASS o fallan solo por el método faltante.

- [ ] **Step 3: Máquina de estados**

Reemplazar `backend/app/Servicios/MaquinaEstadosViaje.php` completo por:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoViaje as E;
use App\Enums\ResultadoOferta;
use App\Events\EstadoChoferActualizado;
use App\Events\ViajeActualizado;
use App\Excepciones\TransicionInvalida;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use Illuminate\Support\Facades\DB;

/** Única puerta para cambiar el estado de un viaje (spec 5.1). */
class MaquinaEstadosViaje
{
    public function __construct(private CalculadorEstadoChofer $estados) {}

    private const PERMITIDAS = [
        'buscando' => [E::Ofrecido, E::Aceptado, E::SinChofer, E::Cancelado],
        'ofrecido' => [E::Buscando, E::Aceptado, E::SinChofer, E::Cancelado],
        // aceptado → sin_chofer: el chofer cancela una reserva, que no se reasigna sola (spec 5.4 y 5.6).
        'aceptado' => [E::EnCamino, E::Buscando, E::Cancelado, E::SinChofer],
        'en_camino' => [E::Llego, E::Buscando, E::Cancelado],
        'llego' => [E::EnCurso, E::Buscando, E::Cancelado],
        'en_curso' => [E::Finalizado],
    ];

    /**
     * Transiciones que solo hace un administrador desde el panel (spec 5.6). Están aparte para que
     * ningún flujo de la app (solicitante o chofer) pueda usarlas: solo transicionarComoAdmin las mira.
     */
    private const SOLO_ADMIN = [
        'en_curso' => [E::Cancelado],
    ];

    /** Estados desde los que el admin puede reasignar el viaje a otro chofer (queda aceptado). */
    private const REASIGNABLES = [E::Buscando, E::Ofrecido, E::Aceptado, E::EnCamino, E::Llego, E::SinChofer];

    private const MARCAS = [
        'aceptado' => 'aceptado_en',
        'llego' => 'llego_en',
        'en_curso' => 'iniciado_en',
        'finalizado' => 'finalizado_en',
        'cancelado' => 'cancelado_en',
    ];

    public function puede(E $desde, E $hacia, bool $comoAdmin = false): bool
    {
        return in_array($hacia, self::PERMITIDAS[$desde->value] ?? [], true)
            || ($comoAdmin && in_array($hacia, self::SOLO_ADMIN[$desde->value] ?? [], true));
    }

    public function transicionar(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        return $this->aplicar($viaje, $hacia, $atributos, estricto: true);
    }

    /** Como transicionar, pero devuelve false (sin lanzar) si el estado actual ya no lo permite. */
    public function intentar(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        return $this->aplicar($viaje, $hacia, $atributos, estricto: false);
    }

    /** Transición pedida por un administrador: suma las de SOLO_ADMIN y avisa con los textos del panel. */
    public function transicionarComoAdmin(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        return $this->aplicar($viaje, $hacia, $atributos, estricto: true, comoAdmin: true);
    }

    /**
     * El admin asigna el viaje a otro chofer (spec 5.6). Queda aceptado aunque ya lo estuviera,
     * así que no pasa por aplicar(), que trata "mismo estado" como repetido.
     */
    public function reasignar(Viaje $viaje, int $choferId, ?int $vehiculoId): void
    {
        DB::transaction(function () use ($viaje, $choferId, $vehiculoId) {
            $this->sincronizarConFilaBloqueada($viaje);

            if (! in_array($viaje->estado, self::REASIGNABLES, true)) {
                throw new TransicionInvalida("El viaje no puede reasignarse en estado {$viaje->estado->value}.");
            }

            $this->guardar($viaje, E::Aceptado, [
                'chofer_id' => $choferId,
                'vehiculo_id' => $vehiculoId,
                'llego_en' => null,
            ], porAdmin: true);
        }, attempts: 3);
    }

    private function aplicar(Viaje $viaje, E $hacia, array $atributos, bool $estricto, bool $comoAdmin = false): bool
    {
        return DB::transaction(function () use ($viaje, $hacia, $atributos, $estricto, $comoAdmin) {
            $this->sincronizarConFilaBloqueada($viaje);

            if ($viaje->estado === $hacia) {
                return false;
            }

            if (! $this->puede($viaje->estado, $hacia, $comoAdmin)) {
                if (! $estricto) {
                    return false;
                }
                throw new TransicionInvalida("El viaje no puede pasar de {$viaje->estado->value} a {$hacia->value}.");
            }

            $this->guardar($viaje, $hacia, $atributos, porAdmin: $comoAdmin);

            return true;
        }, attempts: 3);
    }

    /**
     * Se valida contra la fila bloqueada, no contra la copia que trae el llamador,
     * para que dos transiciones concurrentes no se pisen.
     */
    private function sincronizarConFilaBloqueada(Viaje $viaje): void
    {
        $actual = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
        $viaje->setRawAttributes($actual->getAttributes(), true);
        $viaje->setRelations([]);
    }

    private function guardar(Viaje $viaje, E $hacia, array $atributos, bool $porAdmin = false): void
    {
        $desde = $viaje->estado;
        $choferAnterior = $viaje->chofer_id;
        // El chofer con una oferta pendiente no es chofer_id del viaje, pero tiene que enterarse del cambio.
        $conOferta = $desde === E::Ofrecido
            ? OfertaViaje::where('viaje_id', $viaje->id)->where('resultado', ResultadoOferta::Pendiente)
                ->pluck('chofer_id')->all()
            : [];

        $viaje->fill($atributos);
        $viaje->estado = $hacia;
        if ($marca = self::MARCAS[$hacia->value] ?? null) {
            $viaje->{$marca} = now();
        }
        $viaje->save();

        ViajeActualizado::dispatch($viaje, $choferAnterior !== $viaje->chofer_id ? $choferAnterior : null, $conOferta, $porAdmin);
        foreach (array_unique(array_filter([$choferAnterior, $viaje->chofer_id])) as $choferId) {
            $this->emitirEstadoChofer($choferId);
        }
    }

    private function emitirEstadoChofer(int $choferId): void
    {
        $chofer = \App\Models\Usuario::find($choferId);
        EstadoChoferActualizado::dispatch($choferId, $this->estados->estado($chofer)->value);
    }
}
```

`PERMITIDAS` no cambia (A3): `MaquinaEstadosViajeTest` sigue exigiendo que `en_curso → cancelado` esté prohibido para la app.

- [ ] **Step 4: Evento con la marca del admin**

Reemplazar `backend/app/Events/ViajeActualizado.php` completo por:

```php
<?php

namespace App\Events;

use App\Http\Resources\ViajeResource;
use App\Models\Viaje;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ViajeActualizado implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $choferesConOferta  choferes que tenían una oferta pendiente del viaje
     * @param  bool  $porAdmin  el cambio lo hizo un administrador desde el panel (cancelación o reasignación)
     */
    public function __construct(
        public Viaje $viaje,
        public ?int $choferAnteriorId = null,
        public array $choferesConOferta = [],
        public bool $porAdmin = false,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $choferes = array_unique(array_filter([
            $this->viaje->chofer_id, $this->choferAnteriorId, ...$this->choferesConOferta,
        ]));

        return [
            new PrivateChannel("viaje.{$this->viaje->id}"),
            ...array_map(fn (int $id) => new PrivateChannel("chofer.$id"), $choferes),
        ];
    }

    public function broadcastAs(): string
    {
        return 'viaje.actualizado';
    }

    public function broadcastWith(): array
    {
        return (new ViajeResource($this->viaje->loadMissing(['chofer', 'vehiculo', 'solicitante'])))->resolve();
    }
}
```

- [ ] **Step 5: Pushes de las acciones del admin**

En `backend/app/Listeners/AvisosViaje.php`, dentro de `handleViajeActualizado`, justo después de la línea

```php
        $datos = ['tipo' => 'viaje', 'viaje_id' => $v->id, 'estado' => $v->estado->value];
```

agregar:

```php

        if ($e->porAdmin) {
            $this->avisarAccionDelAdmin($v, $e->choferAnteriorId, $datos);

            return;
        }
```

y al final de la clase, después del método `avisarReserva`, agregar:

```php
    /** Cancelación o reasignación hecha desde el panel (spec 5.6). */
    private function avisarAccionDelAdmin(Viaje $v, ?int $choferAnteriorId, array $datos): void
    {
        $esReserva = $v->tipo === TipoViaje::Reserva;
        $cuando = $v->horaProgramadaLocal();
        $cual = $esReserva ? "la reserva del $cuando" : 'el viaje';

        if ($v->estado === EstadoViaje::Cancelado) {
            $titulo = $esReserva ? 'Reserva cancelada' : 'Viaje cancelado';
            $this->push->enviar($v->solicitante, $titulo, "Un administrador canceló $cual. Motivo: {$v->motivo_cancelacion}", $datos);
            if ($v->chofer) {
                $this->push->enviar($v->chofer, $titulo, "Un administrador canceló $cual.", $datos);
            }

            return;
        }

        // Reasignación: el viaje quedó aceptado con otro chofer.
        if ($choferAnteriorId && $anterior = Usuario::find($choferAnteriorId)) {
            $this->push->enviar($anterior, $esReserva ? 'Reserva reasignada' : 'Viaje reasignado',
                "Un administrador le asignó $cual a otro chofer.", $datos);
        }
        $this->push->enviar($v->chofer, $esReserva ? 'Reserva asignada' : 'Viaje asignado',
            $esReserva ? "Un administrador te asignó una reserva el $cuando." : 'Un administrador te asignó un viaje.', $datos);
        $this->push->enviar($v->solicitante, $esReserva ? "Reserva confirmada para $cuando" : 'Tu auto está confirmado',
            "Ahora te lleva {$v->chofer->nombre}.", $datos);
    }
```

(`Usuario`, `Viaje`, `EstadoViaje` y `TipoViaje` ya están importados en ese archivo.)

- [ ] **Step 6: `ServicioViaje::cancelarPorAdmin`**

En `backend/app/Servicios/ServicioViaje.php`, dentro de `cancelarPorSolicitante`, reemplazar:

```php
            OfertaViaje::where('viaje_id', $viaje->id)
                ->where('resultado', ResultadoOferta::Pendiente)
                ->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);
```

por:

```php
            $this->expirarOfertasPendientes($viaje->id);
```

y, a continuación del método `cancelarPorSolicitante` (antes de `cancelarPorChofer`), agregar:

```php
    /**
     * Spec 5.6: el admin cancela desde el panel cualquier viaje que no haya terminado, incluidos los
     * obligatorios y los que están en curso (transición exclusiva del admin en MaquinaEstadosViaje).
     */
    public function cancelarPorAdmin(Viaje $viaje, Usuario $admin, string $motivo): Viaje
    {
        if (! $admin->esAdmin()) {
            throw new AccionNoPermitida('Solo un administrador puede cancelar desde el panel.');
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new ReglaNegocio('Indicá el motivo de la cancelación.');
        }

        DB::transaction(function () use ($viaje, $motivo) {
            // Se valida la fila bloqueada: el chofer o el solicitante pueden haberlo cambiado recién.
            $viaje->setRawAttributes(Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);

            if (! self::cancelablePorAdmin($viaje)) {
                throw new ReglaNegocio($viaje->estado === EstadoViaje::Cancelado
                    ? 'El viaje ya estaba cancelado.'
                    : 'El viaje ya terminó; no se puede cancelar.');
            }

            $this->maquina->transicionarComoAdmin($viaje, EstadoViaje::Cancelado, [
                'cancelado_por' => 'admin',
                'motivo_cancelacion' => $motivo,
            ]);

            $this->expirarOfertasPendientes($viaje->id);
        }, attempts: 3);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** ¿El panel ofrece "Cancelar" para este viaje? Todo lo que no terminó (sin_chofer ya es final). */
    public static function cancelablePorAdmin(Viaje $viaje): bool
    {
        return ! in_array($viaje->estado, [EstadoViaje::Finalizado, EstadoViaje::Cancelado, EstadoViaje::SinChofer], true);
    }

    /** Vence las ofertas que seguían abiertas: si el chofer responde tarde, recibe "La oferta ya no está vigente". */
    private function expirarOfertasPendientes(int $viajeId): void
    {
        OfertaViaje::where('viaje_id', $viajeId)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);
    }
```

- [ ] **Step 7: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (251), incluidos `MaquinaEstadosViajeTest`, `ViajeEnCursoTest` ("el solicitante no puede cancelar un viaje en curso") y `AvisosViajeTest` sin cambios.

- [ ] **Step 8: Commit**

```bash
git add backend
git commit -m "feat: el admin cancela viajes no terminados, incluso obligatorios y en curso" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: El admin reasigna viajes y reservas

**Files:**
- Modify: `backend/app/Servicios/ServicioViaje.php`
- Test: `backend/tests/Feature/ReasignacionAdminTest.php`

**Interfaces:**
- Consumes: `MaquinaEstadosViaje::reasignar()` (Task 4), `CalculadorEstadoChofer::estado()`, `DisponibilidadReservas::estaDisponible(..., excluirViajeId:, bloquear: true)`, `AvisosReserva::programar()`, `Viaje::sigueReservadaPara()` (plan de reservas).
- Produces: `ServicioViaje::reasignarPorAdmin(Viaje, Usuario $chofer): Viaje`, `ServicioViaje::reasignable(Viaje): bool` (estático, lo usa el panel). `ServicioViaje` recibe además `DisponibilidadReservas` y `AvisosReserva` por constructor (se resuelve por el contenedor; nadie lo instancia a mano).

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/ReasignacionAdminTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Events\ViajeActualizado;
use App\Excepciones\ReglaNegocio;
use App\Excepciones\TransicionInvalida;
use App\Jobs\AlertarReservaSinTurno;
use App\Jobs\RecordarReserva;
use App\Jobs\VencerOferta;
use App\Models\Alerta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\Asignador;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    // Cola sync para que corra el listener de push; los jobs con retraso se falsean y se inspeccionan.
    Bus::fake([VencerOferta::class, RecordarReserva::class, AlertarReservaSinTurno::class]);
    $this->push = new NotificadorFalso();
    $this->app->instance(Notificador::class, $this->push);
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
});

function inmediatoDe(Usuario $chofer, EstadoViaje $estado, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto?->vehiculo_id,
        'estado' => $estado,
        'aceptado_en' => now()->subMinutes(10),
        ...$attrs,
    ]);
}

it('reasigna un inmediato en camino a un chofer libre con el vehículo de su turno', function () {
    $anterior = choferEnTurno();
    $nuevo = choferEnTurno();
    $viaje = inmediatoDe($anterior, EstadoViaje::Llego, ['llego_en' => now()]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    expect($viaje->fresh())
        ->estado->toBe(EstadoViaje::Aceptado)
        ->chofer_id->toBe($nuevo->id)
        ->vehiculo_id->toBe($nuevo->turnoAbierto->vehiculo_id)
        ->llego_en->toBeNull();
    expect($this->push->titulosPara($anterior))->toBe(['Viaje reasignado'])
        ->and($this->push->titulosPara($nuevo))->toBe(['Viaje asignado'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Tu auto está confirmado']);
});

it('asigna directo un viaje no obligatorio sin chofer, sin pasar por una oferta', function () {
    $nuevo = choferEnTurno();
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and(OfertaViaje::count())->toBe(0);
});

it('reasignar un viaje ofrecido vence la oferta y avisa al chofer que la tenía', function () {
    $ofrecido = choferEnTurno(-34.60, -58.38);
    $viaje = Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]);
    app(Despachador::class)->despachar($viaje);
    $nuevo = choferEnTurno(-34.70, -58.50);
    Event::fake([ViajeActualizado::class]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje->fresh(), $nuevo);

    expect($viaje->fresh()->chofer_id)->toBe($nuevo->id)
        ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada);
    Event::assertDispatched(ViajeActualizado::class,
        fn ($e) => $e->porAdmin && in_array($ofrecido->id, $e->choferesConOferta, true));
});

it('no reasigna un inmediato a un chofer que no está libre', function () {
    $ocupado = choferEnTurno();
    inmediatoDe($ocupado, EstadoViaje::EnCurso);
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Buscando]);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($viaje, $ocupado))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no está libre.');
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Buscando);
});

it('no reasigna un viaje ya iniciado, al mismo chofer ni a quien no es chofer activo', function () {
    $chofer = choferEnTurno();
    $enCurso = inmediatoDe($chofer, EstadoViaje::EnCurso);
    $aceptado = inmediatoDe(choferEnTurno(), EstadoViaje::Aceptado);
    $inactivo = choferEnTurno();
    $inactivo->update(['activo' => false]);
    $servicio = app(ServicioViaje::class);

    expect(fn () => $servicio->reasignarPorAdmin($enCurso, choferEnTurno()))
        ->toThrow(ReglaNegocio::class, 'El viaje ya comenzó o terminó; no se puede reasignar.')
        ->and(fn () => $servicio->reasignarPorAdmin($aceptado, $aceptado->chofer))
        ->toThrow(ReglaNegocio::class, 'El viaje ya está asignado a ese chofer.')
        ->and(fn () => $servicio->reasignarPorAdmin($aceptado, $inactivo))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no existe o no está activo.')
        ->and(fn () => $servicio->reasignarPorAdmin($aceptado, Usuario::factory()->create()))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no existe o no está activo.');
});

it('reasigna una reserva aceptada a un chofer con la franja libre aunque esté fuera de turno', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $nuevo = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'), attrs: ['obligatorio' => true]);

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    expect($viaje->fresh())
        ->estado->toBe(EstadoViaje::Aceptado)
        ->chofer_id->toBe($nuevo->id)
        ->vehiculo_id->toBeNull();
    expect($this->push->titulosPara($anterior))->toBe(['Reserva reasignada'])
        ->and($this->push->titulosPara($nuevo))->toBe(['Reserva asignada'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Reserva confirmada para 02/10 12:00']);
});

it('al reasignar una reserva los recordatorios del chofer anterior no hacen nada y se programan los del nuevo', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $nuevo = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'));
    $marca = $viaje->programado_para->getTimestamp();

    app(ServicioViaje::class)->reasignarPorAdmin($viaje, $nuevo);

    Bus::assertDispatched(RecordarReserva::class, fn ($j) => $j->choferId === $nuevo->id);
    Bus::assertDispatched(AlertarReservaSinTurno::class, fn ($j) => $j->choferId === $nuevo->id);

    $this->push->enviados = [];
    (new RecordarReserva($viaje->id, $anterior->id, $marca))->handle($this->push);
    (new AlertarReservaSinTurno($viaje->id, $anterior->id, $marca))->handle($this->push);
    expect($this->push->enviados)->toBe([])
        ->and(Alerta::count())->toBe(0);
});

it('no reasigna una reserva a un chofer con otra reserva superpuesta', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $ocupado = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'));
    reservaAceptada($ocupado, Carbon::parse('2026-10-02 15:30'));

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($viaje, $ocupado))
        ->toThrow(ReglaNegocio::class, 'El chofer tiene otra reserva en ese horario.');
    expect($viaje->fresh()->chofer_id)->toBe($anterior->id);
});

it('no reasigna una reserva en la que el chofer ya salió', function () {
    $viaje = reservaAceptada(choferEnTurno(), now()->addMinutes(30), attrs: ['estado' => EstadoViaje::EnCamino]);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($viaje, Usuario::factory()->chofer()->create()))
        ->toThrow(ReglaNegocio::class, 'La reserva ya comenzó o terminó; no se puede reasignar.');
});

it('la máquina de estados rechaza reasignar un viaje terminado', function () {
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado]);

    expect(fn () => app(MaquinaEstadosViaje::class)->reasignar($viaje, choferEnTurno()->id, null))
        ->toThrow(TransicionInvalida::class);
});

// Carreras simuladas con copias leídas antes de que otro request cambiara los datos.

it('con datos viejos no reasigna un viaje que el solicitante ya canceló', function () {
    $viaje = inmediatoDe(choferEnTurno(), EstadoViaje::Aceptado);
    $copiaDelPanel = Viaje::find($viaje->id);
    app(ServicioViaje::class)->cancelarPorSolicitante(Viaje::find($viaje->id), $viaje->solicitante, null);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($copiaDelPanel, choferEnTurno()))
        ->toThrow(ReglaNegocio::class, 'El viaje ya comenzó o terminó; no se puede reasignar.');
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado);
});

it('con datos viejos del chofer no le asigna un segundo viaje activo', function () {
    $chofer = choferEnTurno();
    $copiaDelChofer = Usuario::find($chofer->id);
    $obligatorio = Viaje::factory()->create(['obligatorio' => true]);
    $aReasignar = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);

    app(Asignador::class)->asignar($obligatorio, $chofer);

    expect(fn () => app(ServicioViaje::class)->reasignarPorAdmin($aReasignar, $copiaDelChofer))
        ->toThrow(ReglaNegocio::class, 'El chofer elegido no está libre.');
    expect(Viaje::activosDeChofer($chofer->id)->pluck('id')->all())->toBe([$obligatorio->id]);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/ReasignacionAdminTest.php`
Expected: FAIL con `Call to undefined method App\Servicios\ServicioViaje::reasignarPorAdmin()`. El test "la máquina de estados rechaza reasignar un viaje terminado" ya pasa (Task 4).

- [ ] **Step 3: Dependencias y estados reasignables**

En `backend/app/Servicios/ServicioViaje.php`, reemplazar el constructor:

```php
    public function __construct(
        private Despachador $despachador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
    ) {}
```

por:

```php
    public function __construct(
        private Despachador $despachador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
        private DisponibilidadReservas $disponibilidad,
        private AvisosReserva $avisosReserva,
    ) {}

    /** Un inmediato se puede reasignar hasta que empieza el viaje con el pasajero (spec 5.6). */
    private const REASIGNABLES_INMEDIATO = [
        EstadoViaje::Buscando, EstadoViaje::Ofrecido, EstadoViaje::Aceptado,
        EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::SinChofer,
    ];

    /** Una reserva, mientras el chofer no haya salido. */
    private const REASIGNABLES_RESERVA = [
        EstadoViaje::Buscando, EstadoViaje::Ofrecido, EstadoViaje::Aceptado, EstadoViaje::SinChofer,
    ];
```

- [ ] **Step 4: `reasignarPorAdmin`**

En el mismo archivo, después del método estático `cancelablePorAdmin` (Task 4), agregar:

```php
    /**
     * Spec 5.6: el admin asigna el viaje a otro chofer, sin oferta, sea o no obligatorio.
     * Inmediato: el chofer tiene que estar libre ahora. Reserva: la franja tiene que estar libre en su agenda.
     */
    public function reasignarPorAdmin(Viaje $viaje, Usuario $chofer): Viaje
    {
        $esReserva = DB::transaction(function () use ($viaje, $chofer) {
            // Mismo orden de bloqueo que Asignador (viaje, luego chofer): compite en igualdad con
            // cualquier otra asignación a ese chofer.
            $viaje->setRawAttributes(Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $c = Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if (! $c?->esChofer() || ! $c->activo) {
                throw new ReglaNegocio('El chofer elegido no existe o no está activo.');
            }
            if ($viaje->chofer_id === $c->id) {
                throw new ReglaNegocio('El viaje ya está asignado a ese chofer.');
            }

            $esReserva = $viaje->tipo === TipoViaje::Reserva;
            if (! self::reasignable($viaje)) {
                throw new ReglaNegocio($esReserva
                    ? 'La reserva ya comenzó o terminó; no se puede reasignar.'
                    : 'El viaje ya comenzó o terminó; no se puede reasignar.');
            }

            if ($esReserva) {
                $libre = $this->disponibilidad->estaDisponible(
                    $c->id,
                    $viaje->programado_para,
                    $viaje->duracion_estimada_min ?? $this->parametros->entero('duracion_reserva_por_defecto_min'),
                    excluirViajeId: $viaje->id,
                    bloquear: true,
                );
                if (! $libre) {
                    throw new ReglaNegocio('El chofer tiene otra reserva en ese horario.');
                }
                $vehiculoId = null; // se toma del turno al salir (en_camino), como en toda reserva
            } else {
                if ($this->estados->estado($c) !== EstadoChofer::Libre) {
                    throw new ReglaNegocio('El chofer elegido no está libre.');
                }
                $vehiculoId = $c->turnoAbierto()->value('vehiculo_id');
            }

            // Primero la máquina (así avisa al chofer que tenía la oferta) y después se vence la oferta.
            $this->maquina->reasignar($viaje, $c->id, $vehiculoId);
            $this->expirarOfertasPendientes($viaje->id);

            return $esReserva;
        }, attempts: 3);

        $viaje->refresh();

        if ($esReserva) {
            // Los recordatorios y la alerta del chofer anterior quedan sin efecto por sigueReservadaPara().
            $this->avisosReserva->programar($viaje);
        }

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    /** ¿El panel ofrece "Reasignar" para este viaje? (se vuelve a verificar con la fila bloqueada) */
    public static function reasignable(Viaje $viaje): bool
    {
        return in_array($viaje->estado, $viaje->tipo === TipoViaje::Reserva
            ? self::REASIGNABLES_RESERVA
            : self::REASIGNABLES_INMEDIATO, true);
    }
```

`DisponibilidadReservas` y `AvisosReserva` están en el mismo namespace (`App\Servicios`), y `EstadoChofer`, `TipoViaje`, `ReglaNegocio` y `Usuario` ya están importados: no hacen falta imports nuevos.

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (263).

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: el admin reasigna viajes y reservas a otro chofer" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Viajes y reservas en el panel, con cancelar y reasignar

**Files:**
- Modify: `backend/app/Enums/EstadoViaje.php`, `backend/app/Enums/TipoViaje.php`, `backend/app/Enums/ModoViaje.php`, `backend/app/Enums/ResultadoOferta.php`
- Create: `backend/app/Filament/Resources/Viajes/ViajeResource.php`
- Create: `backend/app/Filament/Resources/Viajes/Pages/{ListViajes,ViewViaje}.php`
- Create: `backend/app/Filament/Resources/Viajes/RelationManagers/OfertasRelationManager.php`
- Test: `backend/tests/Feature/Panel/ViajesPanelTest.php`

**Interfaces:**
- Consumes: `ServicioViaje::cancelarPorAdmin()`, `cancelablePorAdmin()`, `reasignarPorAdmin()`, `reasignable()` (Tasks 4 y 5), `CalculadorEstadoChofer::libres()`, `DisponibilidadReservas::choferesDisponibles()`, `Viaje::ofertas()`, `Viaje::recorrido()`, `Parametros`.
- Produces:
  - Enums de viaje con `getLabel()` (y `getColor()` en `EstadoViaje` y `ResultadoOferta`).
  - Recurso `/admin/viajes` (listado y detalle, sin alta ni edición) con filtros `tipo`, `estado` (múltiple), `obligatorio`, `chofer` y `fecha` (`desde`/`hasta`, días locales).
  - `ViajeResource::filtrarPorFecha(Builder, ?string $desde, ?string $hasta): Builder` y `ViajeResource::choferesElegibles(Viaje): array<int, string>`.
  - Acciones de encabezado `reasignar` (select `chofer_id`) y `cancelar` (`motivo`, obligatorio) en `ViewViaje`.

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/Panel/ViajesPanelTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\Pages\ListViajes;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Filament\Resources\Viajes\RelationManagers\OfertasRelationManager;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\OfertaViaje;
use App\Models\PuntoRecorrido;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\ServicioViaje;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs($this->admin = Usuario::factory()->admin()->create());
});

it('filtra por tipo, estado, obligatorio y chofer', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $inmediato = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);
    $reserva = reservaBuscando(['obligatorio' => true]);
    $sinChofer = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);

    Livewire::test(ListViajes::class)
        ->assertCanSeeTableRecords([$inmediato, $reserva, $sinChofer])
        ->filterTable('tipo', TipoViaje::Reserva)
        ->assertCanSeeTableRecords([$reserva])
        ->assertCanNotSeeTableRecords([$inmediato, $sinChofer])
        ->resetTableFilters()
        ->filterTable('estado', [EstadoViaje::SinChofer, EstadoViaje::Aceptado])
        ->assertCanSeeTableRecords([$inmediato, $sinChofer])
        ->assertCanNotSeeTableRecords([$reserva])
        ->resetTableFilters()
        ->filterTable('obligatorio', true)
        ->assertCanSeeTableRecords([$reserva])
        ->assertCanNotSeeTableRecords([$inmediato, $sinChofer])
        ->resetTableFilters()
        ->filterTable('chofer', $chofer->id)
        ->assertCanSeeTableRecords([$inmediato])
        ->assertCanNotSeeTableRecords([$reserva, $sinChofer]);
});

it('filtra por fecha: programada en reservas y del pedido en inmediatos, en hora local', function () {
    // 2026-10-01 12:00 UTC = 09:00 en Buenos Aires.
    $hoy = Viaje::factory()->create();
    $ayer = Viaje::factory()->create(['created_at' => now()->subDay()]);
    $reservaManana = reservaBuscando(); // 2026-10-02 15:00 UTC
    // 2026-10-02 02:00 UTC es todavía 1/10 a las 23:00 en Buenos Aires.
    $reservaNocheLocal = reservaBuscando(['programado_para' => Carbon::parse('2026-10-02 02:00:00')]);

    Livewire::test(ListViajes::class)
        ->filterTable('fecha', ['desde' => '2026-10-01', 'hasta' => '2026-10-01'])
        ->assertCanSeeTableRecords([$hoy, $reservaNocheLocal])
        ->assertCanNotSeeTableRecords([$ayer, $reservaManana]);
});

it('muestra el detalle con línea de tiempo, ofertas y recorrido', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Finalizado,
        'aceptado_en' => now()->subMinutes(30), 'iniciado_en' => now()->subMinutes(20), 'finalizado_en' => now(),
    ]);
    $oferta = OfertaViaje::create([
        'viaje_id' => $viaje->id, 'chofer_id' => $chofer->id, 'resultado' => ResultadoOferta::Aceptada,
        'ofrecido_en' => now()->subMinutes(31), 'vence_en' => now()->subMinutes(30), 'respondido_en' => now()->subMinutes(30),
    ]);
    foreach ([10, 5] as $minutos) {
        PuntoRecorrido::create(['viaje_id' => $viaje->id, 'lat' => -34.6, 'lng' => -58.4, 'registrado_en' => now()->subMinutes($minutos)]);
    }

    $this->get(ViajeResource::getUrl('view', ['record' => $viaje]))
        ->assertOk()
        ->assertSee('Línea de tiempo')
        ->assertSee('01/10/2026 08:40') // iniciado_en en hora local
        ->assertSee('Puntos GPS');

    Livewire::test(OfertasRelationManager::class, ['ownerRecord' => $viaje, 'pageClass' => ViewViaje::class])
        ->assertCanSeeTableRecords([$oferta]);
});

it('cancela desde el panel con motivo obligatorio', function () {
    $viaje = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::EnCurso, 'obligatorio' => true]);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('cancelar', data: ['motivo' => ''])
        ->assertHasActionErrors(['motivo' => 'required']);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('cancelar', data: ['motivo' => 'Vehículo averiado'])
        ->assertHasNoActionErrors()
        ->assertNotified('Viaje cancelado');

    expect($viaje->fresh())->estado->toBe(EstadoViaje::Cancelado)->cancelado_por->toBe('admin');
});

it('muestra las reglas de negocio del servicio como notificación y no como error', function () {
    $viaje = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::EnCurso]);
    $this->mock(ServicioViaje::class)
        ->shouldReceive('cancelarPorAdmin')
        ->andThrow(new ReglaNegocio('El viaje ya terminó; no se puede cancelar.'));

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('cancelar', data: ['motivo' => 'x'])
        ->assertNotified('El viaje ya terminó; no se puede cancelar.')
        ->assertNotNotified('Viaje cancelado');
});

it('si el viaje terminó con el modal abierto, la cancelación no se ejecuta', function () {
    $viaje = Viaje::factory()->create(['chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::EnCurso]);
    $pagina = Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->mountAction('cancelar');

    Viaje::whereKey($viaje->id)->update(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()]);

    $pagina->setActionData(['motivo' => 'x'])
        ->callMountedAction()
        ->assertNotNotified('Viaje cancelado');
    expect($viaje->fresh())->estado->toBe(EstadoViaje::Finalizado)->cancelado_en->toBeNull();
});

it('no ofrece cancelar ni reasignar un viaje terminado', function () {
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado]);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->assertActionHidden('cancelar')
        ->assertActionHidden('reasignar');
});

it('reasigna un inmediato a un chofer libre elegido de la lista', function () {
    $anterior = choferEnTurno();
    $libre = choferEnTurno();
    $ocupado = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);
    $viaje = Viaje::factory()->create(['chofer_id' => $anterior->id, 'estado' => EstadoViaje::EnCamino]);

    expect(array_keys(ViajeResource::choferesElegibles($viaje)))->toBe([$libre->id]);

    Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->callAction('reasignar', data: ['chofer_id' => $libre->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Viaje reasignado');

    expect($viaje->fresh())->chofer_id->toBe($libre->id)->estado->toBe(EstadoViaje::Aceptado);
});

it('lista para una reserva solo los choferes con la franja libre', function () {
    $anterior = Usuario::factory()->chofer()->create();
    $libre = Usuario::factory()->chofer()->create();
    $ocupado = Usuario::factory()->chofer()->create();
    $viaje = reservaAceptada($anterior, Carbon::parse('2026-10-02 15:00'));
    reservaAceptada($ocupado, Carbon::parse('2026-10-02 15:30'));

    expect(array_keys(ViajeResource::choferesElegibles($viaje)))->toBe([$libre->id]);
});

it('rechaza al chofer elegido si dejó de estar libre antes de confirmar', function () {
    $elegido = choferEnTurno();
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);
    $pagina = Livewire::test(ViewViaje::class, ['record' => $viaje->getRouteKey()])
        ->mountAction('reasignar')
        ->setActionData(['chofer_id' => $elegido->id]);

    Viaje::factory()->create(['chofer_id' => $elegido->id, 'estado' => EstadoViaje::EnCurso]);

    // El select valida contra las opciones vigentes; si igual pasara, el servicio lo rechaza con bloqueo.
    $pagina->callMountedAction()->assertHasActionErrors(['chofer_id']);
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/Panel/ViajesPanelTest.php`
Expected: FAIL con `Class "App\Filament\Resources\Viajes\Pages\ListViajes" not found`.

- [ ] **Step 3: Etiquetas de los enums de viaje**

Reemplazar `backend/app/Enums/EstadoViaje.php` completo por:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EstadoViaje: string implements HasColor, HasLabel
{
    case Buscando = 'buscando';
    case Ofrecido = 'ofrecido';
    case Aceptado = 'aceptado';
    case EnCamino = 'en_camino';
    case Llego = 'llego';
    case EnCurso = 'en_curso';
    case Finalizado = 'finalizado';
    case Cancelado = 'cancelado';
    case SinChofer = 'sin_chofer';

    /** @return array<EstadoViaje> */
    public static function enProgreso(): array
    {
        return [self::Buscando, self::Ofrecido, ...self::conChofer()];
    }

    /** @return array<EstadoViaje> */
    public static function conChofer(): array
    {
        return [self::Aceptado, self::EnCamino, self::Llego, self::EnCurso];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Buscando => 'Buscando',
            self::Ofrecido => 'Ofrecido',
            self::Aceptado => 'Aceptado',
            self::EnCamino => 'En camino',
            self::Llego => 'Llegó',
            self::EnCurso => 'En curso',
            self::Finalizado => 'Finalizado',
            self::Cancelado => 'Cancelado',
            self::SinChofer => 'Sin chofer',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Buscando, self::Ofrecido => 'warning',
            self::Aceptado, self::EnCamino, self::Llego, self::EnCurso => 'info',
            self::Finalizado => 'success',
            self::Cancelado => 'gray',
            self::SinChofer => 'danger',
        };
    }
}
```

Reemplazar `backend/app/Enums/TipoViaje.php` completo por:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TipoViaje: string implements HasLabel
{
    case Inmediato = 'inmediato';
    case Reserva = 'reserva';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inmediato => 'Inmediato',
            self::Reserva => 'Reserva',
        };
    }
}
```

Reemplazar `backend/app/Enums/ModoViaje.php` completo por:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ModoViaje: string implements HasLabel
{
    case MasCercano = 'mas_cercano';
    case Especifico = 'especifico';
    case CualquieraDisponible = 'cualquiera_disponible';

    public function getLabel(): string
    {
        return match ($this) {
            self::MasCercano => 'Más cercano',
            self::Especifico => 'Chofer específico',
            self::CualquieraDisponible => 'Cualquiera disponible',
        };
    }
}
```

Reemplazar `backend/app/Enums/ResultadoOferta.php` completo por:

```php
<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ResultadoOferta: string implements HasColor, HasLabel
{
    case Pendiente = 'pendiente';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case Expirada = 'expirada';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Aceptada => 'Aceptada',
            self::Rechazada => 'Rechazada',
            self::Expirada => 'Expirada',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::Aceptada => 'success',
            self::Rechazada => 'danger',
            self::Expirada => 'gray',
        };
    }
}
```

- [ ] **Step 4: Recurso de viajes**

`backend/app/Filament/Resources/Viajes/ViajeResource.php`:

```php
<?php

namespace App\Filament\Resources\Viajes;

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Filament\Resources\Viajes\Pages\ListViajes;
use App\Filament\Resources\Viajes\Pages\ViewViaje;
use App\Filament\Resources\Viajes\RelationManagers\OfertasRelationManager;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;
use App\Servicios\DisponibilidadReservas;
use App\Servicios\Parametros;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Viajes y reservas (spec 8.5): listado con filtros y detalle con línea de tiempo, ofertas y recorrido. */
class ViajeResource extends Resource
{
    protected static ?string $model = Viaje::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $modelLabel = 'viaje';

    protected static ?string $pluralModelLabel = 'viajes y reservas';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        $fecha = fn (string $campo, string $etiqueta) => TextEntry::make($campo)
            ->label($etiqueta)
            ->dateTime('d/m/Y H:i', config('vehiculos.zona_horaria'))
            ->placeholder('—');

        return $schema
            ->components([
                Section::make('Viaje')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('estado')->badge(),
                        TextEntry::make('tipo')->badge(),
                        TextEntry::make('modo'),
                        IconEntry::make('obligatorio')->boolean(),
                        TextEntry::make('solicitante.nombre')->label('Solicitante'),
                        TextEntry::make('solicitante.cargo')->label('Cargo')->placeholder('—'),
                        TextEntry::make('chofer.nombre')->label('Chofer')->placeholder('Sin asignar'),
                        TextEntry::make('vehiculo.patente')->label('Vehículo')->placeholder('—'),
                        TextEntry::make('motivo')->placeholder('—'),
                        TextEntry::make('origen_direccion')->label('Origen')
                            ->state(fn (Viaje $record): string => $record->origen_direccion ?? "{$record->origen_lat}, {$record->origen_lng}"),
                        TextEntry::make('destino_direccion')->label('Destino')
                            ->state(fn (Viaje $record): string => $record->destino_direccion ?? "{$record->destino_lat}, {$record->destino_lng}"),
                        TextEntry::make('duracion_estimada_min')->label('Duración estimada')->suffix(' min')->placeholder('—'),
                    ]),
                Section::make('Línea de tiempo')
                    ->columns(4)
                    ->schema([
                        $fecha('created_at', 'Pedido'),
                        $fecha('programado_para', 'Programado para'),
                        $fecha('aceptado_en', 'Aceptado'),
                        $fecha('llego_en', 'Llegó'),
                        $fecha('iniciado_en', 'Inició'),
                        $fecha('finalizado_en', 'Finalizó'),
                        $fecha('cancelado_en', 'Cancelado'),
                        TextEntry::make('cancelado_por')->label('Canceló')->placeholder('—'),
                        TextEntry::make('motivo_cancelacion')->label('Motivo de cancelación')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Recorrido')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('puntos_recorrido')->label('Puntos GPS')
                            ->state(fn (Viaje $record): int => $record->recorrido()->count()),
                        TextEntry::make('primer_punto')->label('Primer punto')->placeholder('—')
                            ->state(fn (Viaje $record): ?string => self::describirPunto($record, 'asc')),
                        TextEntry::make('ultimo_punto')->label('Último punto')->placeholder('—')
                            ->state(fn (Viaje $record): ?string => self::describirPunto($record, 'desc')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $zona = config('vehiculos.zona_horaria');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['solicitante', 'chofer']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('tipo')->badge(),
                TextColumn::make('estado')->badge(),
                IconColumn::make('obligatorio')->boolean(),
                TextColumn::make('solicitante.nombre')->label('Solicitante')->searchable(),
                TextColumn::make('chofer.nombre')->label('Chofer')->placeholder('—')->searchable(),
                TextColumn::make('programado_para')->label('Programado')->dateTime('d/m H:i', $zona)->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Pedido')->dateTime('d/m H:i', $zona)->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('tipo')->options(TipoViaje::class),
                SelectFilter::make('estado')->options(EstadoViaje::class)->multiple(),
                TernaryFilter::make('obligatorio'),
                SelectFilter::make('chofer')
                    ->relationship('chofer', 'nombre', fn (Builder $query) => $query->where('rol', RolUsuario::Chofer))
                    ->searchable()
                    ->preload(),
                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filtrarPorFecha($query, $data['desde'] ?? null, $data['hasta'] ?? null)),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /**
     * Días en la zona de los usuarios. La fecha de un viaje es la programada (reservas) o la del pedido (inmediatos).
     */
    public static function filtrarPorFecha(Builder $query, ?string $desde, ?string $hasta): Builder
    {
        $zona = config('vehiculos.zona_horaria');
        $limite = fn (string $dia, bool $fin) => Carbon::parse($dia, $zona)
            ->{$fin ? 'endOfDay' : 'startOfDay'}()
            ->setTimezone(config('app.timezone'));

        foreach (array_filter(['>=' => $desde, '<=' => $hasta]) as $operador => $dia) {
            $momento = $limite($dia, $operador === '<=');
            $query->where(fn (Builder $q) => $q
                ->where('programado_para', $operador, $momento)
                ->orWhere(fn (Builder $i) => $i->whereNull('programado_para')->where('created_at', $operador, $momento)));
        }

        return $query;
    }

    /**
     * Choferes a los que el admin puede reasignar el viaje: libres ahora (inmediato) o con la franja libre (reserva).
     *
     * @return array<int, string>
     */
    public static function choferesElegibles(Viaje $viaje): array
    {
        if ($viaje->tipo === TipoViaje::Reserva) {
            return app(DisponibilidadReservas::class)
                ->choferesDisponibles(
                    $viaje->programado_para,
                    $viaje->duracion_estimada_min ?? app(Parametros::class)->entero('duracion_reserva_por_defecto_min'),
                )
                ->reject(fn (array $f) => $f['chofer']->id === $viaje->chofer_id)
                ->mapWithKeys(fn (array $f) => [$f['chofer']->id => "{$f['chofer']->nombre} ({$f['reservas_del_dia']} reservas ese día)"])
                ->all();
        }

        return app(CalculadorEstadoChofer::class)->libres()
            ->filter(fn (Usuario $c) => $c->activo && $c->id !== $viaje->chofer_id)
            ->mapWithKeys(fn (Usuario $c) => [$c->id => "{$c->nombre} ({$c->turnoAbierto?->vehiculo?->patente})"])
            ->all();
    }

    private static function describirPunto(Viaje $viaje, string $orden): ?string
    {
        $punto = $viaje->recorrido()->orderBy('registrado_en', $orden)->first();

        return $punto
            ? $punto->registrado_en->setTimezone(config('vehiculos.zona_horaria'))->format('H:i:s')." ({$punto->lat}, {$punto->lng})"
            : null;
    }

    public static function getRelations(): array
    {
        return [
            OfertasRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListViajes::route('/'),
            'view' => ViewViaje::route('/{record}'),
        ];
    }
}
```

`backend/app/Filament/Resources/Viajes/Pages/ListViajes.php`:

```php
<?php

namespace App\Filament\Resources\Viajes\Pages;

use App\Filament\Resources\Viajes\ViajeResource;
use Filament\Resources\Pages\ListRecords;

class ListViajes extends ListRecords
{
    protected static string $resource = ViajeResource::class;
}
```

`backend/app/Filament/Resources/Viajes/RelationManagers/OfertasRelationManager.php`:

```php
<?php

namespace App\Filament\Resources\Viajes\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Ofertas del viaje (a quién se ofreció y qué respondió), solo lectura. */
class OfertasRelationManager extends RelationManager
{
    protected static string $relationship = 'ofertas';

    protected static ?string $title = 'Ofertas';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $zona = config('vehiculos.zona_horaria');

        return $table
            ->columns([
                TextColumn::make('chofer.nombre')->label('Chofer'),
                TextColumn::make('resultado')->badge(),
                TextColumn::make('ofrecido_en')->label('Ofrecida')->dateTime('d/m H:i:s', $zona),
                TextColumn::make('vence_en')->label('Vence')->dateTime('d/m H:i:s', $zona),
                TextColumn::make('respondido_en')->label('Respondida')->dateTime('d/m H:i:s', $zona)->placeholder('—'),
                TextColumn::make('motivo')->placeholder('—'),
            ])
            ->defaultSort('ofrecido_en');
    }
}
```

- [ ] **Step 5: Detalle con las acciones del admin**

`backend/app/Filament/Resources/Viajes/Pages/ViewViaje.php`:

```php
<?php

namespace App\Filament\Resources\Viajes\Pages;

use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\ServicioViaje;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewViaje extends ViewRecord
{
    protected static string $resource = ViajeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reasignar')
                ->label('Reasignar')
                ->icon(Heroicon::OutlinedArrowPath)
                ->modalHeading('Reasignar a otro chofer')
                ->modalDescription('Se asigna directo, sin oferta, y se avisa al chofer anterior, al nuevo y al solicitante.')
                ->visible(fn (Viaje $record): bool => ServicioViaje::reasignable($record))
                ->schema([
                    Select::make('chofer_id')
                        ->label('Chofer')
                        ->options(fn (Viaje $record): array => ViajeResource::choferesElegibles($record))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (Viaje $record, array $data): void {
                    $this->ejecutar(
                        fn () => app(ServicioViaje::class)->reasignarPorAdmin($record, Usuario::findOrFail($data['chofer_id'])),
                        'Viaje reasignado',
                    );
                }),
            Action::make('cancelar')
                ->label('Cancelar')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Cancelar el viaje')
                ->modalDescription('Se avisa al solicitante y al chofer. No se puede deshacer.')
                ->visible(fn (Viaje $record): bool => ServicioViaje::cancelablePorAdmin($record))
                ->schema([
                    Textarea::make('motivo')
                        ->label('Motivo')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (Viaje $record, array $data): void {
                    $this->ejecutar(
                        fn () => app(ServicioViaje::class)->cancelarPorAdmin($record, Filament::auth()->user(), $data['motivo']),
                        'Viaje cancelado',
                    );
                }),
        ];
    }

    /** Las reglas de negocio llegan como notificación, no como error 500. */
    private function ejecutar(callable $operacion, string $exito): void
    {
        try {
            $operacion();
        } catch (ReglaNegocio|AccionNoPermitida $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } finally {
            // Se muestre lo que se muestre, la página refleja el estado actual del viaje.
            $this->getRecord()->refresh();
        }

        Notification::make()->success()->title($exito)->send();
    }
}
```

`visible()` usa los mismos métodos estáticos que el servicio, así que el botón aparece exactamente cuando el servicio aceptaría la acción. Igual el servicio vuelve a verificar con la fila bloqueada (A16).

- [ ] **Step 6: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (273).

- [ ] **Step 7: Commit**

```bash
git add backend
git commit -m "feat: viajes y reservas en el panel con cancelar y reasignar" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Alerta por chofer sin señal durante un viaje

**Files:**
- Modify: `backend/app/Models/Viaje.php`
- Modify: `backend/app/Models/Alerta.php`
- Create: `backend/app/Servicios/AlertasSinSenal.php`
- Create: `backend/app/Console/Commands/AlertarSinSenal.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/AlertasSinSenalTest.php`

**Interfaces:**
- Consumes: `Parametros::entero('no_disponible_min')`, `Alerta::pendientes()`, `HoraLocal::formatear()`, `Usuario::turnoAbierto()` / `ubicacion()`.
- Produces: scope `Viaje::activos()` (viajes que ocupan a algún chofer ahora; `activosDeChofer` lo reutiliza), `Alerta::CHOFER_SIN_SENAL`, `AlertasSinSenal::revisar(): array{creadas: int, resueltas: int}`, comando `vehiculos:alertar-sin-senal`, programado cada minuto sin superponerse.

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/AlertasSinSenalTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Models\Alerta;
use App\Models\Parametro;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-01 12:00:00')));

function viajeActivoDe(Usuario $chofer, EstadoViaje $estado = EstadoViaje::EnCurso): Viaje
{
    return Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => $estado, 'aceptado_en' => now()]);
}

it('alerta al panel si un chofer con viaje activo lleva más de 10 minutos sin señal', function () {
    $chofer = choferEnTurno(minutos: 11);
    $viaje = viajeActivoDe($chofer);
    viajeActivoDe(choferEnTurno(minutos: 9));          // todavía no llega al umbral
    choferEnTurno(minutos: 30);                         // sin viaje activo: no es alerta

    $this->artisan('vehiculos:alertar-sin-senal')->assertSuccessful();

    $alerta = Alerta::sole();
    expect($alerta)
        ->tipo->toBe(Alerta::CHOFER_SIN_SENAL)
        ->viaje_id->toBe($viaje->id)
        ->chofer_id->toBe($chofer->id)
        ->resuelta_en->toBeNull()
        ->and($alerta->mensaje)->toBe("{$chofer->nombre} no envía su ubicación desde las 08:49 y tiene el viaje #{$viaje->id} activo.");
});

it('no duplica la alerta mientras siga sin señal', function () {
    viajeActivoDe(choferEnTurno(minutos: 15));

    $this->artisan('vehiculos:alertar-sin-senal');
    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::count())->toBe(1);
});

it('resuelve la alerta cuando vuelve la señal', function () {
    $chofer = choferEnTurno(minutos: 15);
    viajeActivoDe($chofer);
    $this->artisan('vehiculos:alertar-sin-senal');

    UbicacionChofer::whereKey($chofer->id)->update(['actualizado_en' => now()]);
    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::sole()->resuelta_en)->not->toBeNull()
        ->and(Alerta::pendientes()->count())->toBe(0);
});

it('resuelve la alerta cuando el viaje termina', function () {
    $viaje = viajeActivoDe(choferEnTurno(minutos: 15));
    $this->artisan('vehiculos:alertar-sin-senal');

    $viaje->update(['estado' => EstadoViaje::Finalizado]);
    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::pendientes()->count())->toBe(0);
});

it('no alerta por una reserva aceptada que todavía no empezó', function () {
    reservaAceptada(choferEnTurno(minutos: 15), now()->addHours(3));

    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::count())->toBe(0);
});

it('usa el umbral configurable no_disponible_min', function () {
    Parametro::create(['clave' => 'no_disponible_min', 'valor' => '20']);
    viajeActivoDe(choferEnTurno(minutos: 15));

    $this->artisan('vehiculos:alertar-sin-senal');

    expect(Alerta::count())->toBe(0);
});

it('corre cada minuto', function () {
    $evento = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'vehiculos:alertar-sin-senal'));

    expect($evento?->expression)->toBe('* * * * *');
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/AlertasSinSenalTest.php`
Expected: FAIL. `The command "vehiculos:alertar-sin-senal" does not exist.`, `Undefined constant App\Models\Alerta::CHOFER_SIN_SENAL` y el evento programado no existe.

- [ ] **Step 3: Scope `activos` y tipo de alerta**

En `backend/app/Models/Viaje.php`, reemplazar el método `scopeActivosDeChofer` completo (se conserva su comentario) por:

```php
    public function scopeActivosDeChofer(Builder $q, int $choferId): Builder
    {
        return $q->where('chofer_id', $choferId)->activos();
    }

    /** Los viajes que ocupan a algún chofer ahora (mismo criterio que activosDeChofer). */
    public function scopeActivos(Builder $q): Builder
    {
        return $q->whereNotNull('chofer_id')
            ->where(fn (Builder $w) => $w
                ->whereIn('estado', [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso])
                ->orWhere(fn (Builder $a) => $a
                    ->where('estado', EstadoViaje::Aceptado)
                    ->where(fn (Builder $p) => $p->whereNull('programado_para')->orWhere('programado_para', '<=', now()))));
    }
```

Reemplazar `backend/app/Models/Alerta.php` completo por:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso para el panel de administración (spec 8.6). */
class Alerta extends Model
{
    public const RESERVA_SIN_TURNO = 'reserva_sin_turno';

    /** Spec 9: chofer sin señal durante un viaje. Se resuelve sola (AlertasSinSenal). */
    public const CHOFER_SIN_SENAL = 'chofer_sin_senal';

    protected $table = 'alertas';

    protected $fillable = ['tipo', 'viaje_id', 'chofer_id', 'mensaje', 'resuelta_en'];

    protected function casts(): array
    {
        return ['resuelta_en' => 'datetime'];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->whereNull('resuelta_en');
    }
}
```

- [ ] **Step 4: Servicio y comando**

`backend/app/Servicios/AlertasSinSenal.php`:

```php
<?php

namespace App\Servicios;

use App\Models\Alerta;
use App\Models\Viaje;
use App\Support\HoraLocal;

/**
 * Spec 9: si un chofer con un viaje activo lleva no_disponible_min sin enviar su ubicación, se alerta al panel.
 * Una sola alerta pendiente por viaje y chofer; se resuelve sola cuando vuelve la señal o el viaje deja de estar activo.
 */
class AlertasSinSenal
{
    public function __construct(private Parametros $parametros) {}

    /** @return array{creadas: int, resueltas: int} */
    public function revisar(): array
    {
        $limite = now()->subMinutes($this->parametros->entero('no_disponible_min'));

        $sinSenal = Viaje::activos()
            ->whereHas('chofer.turnoAbierto')
            ->with('chofer.ubicacion')
            ->get()
            ->filter(fn (Viaje $v) => ! $v->chofer->ubicacion || $v->chofer->ubicacion->actualizado_en->lt($limite))
            ->keyBy(fn (Viaje $v) => "{$v->id}:{$v->chofer_id}");

        $pendientes = Alerta::pendientes()
            ->where('tipo', Alerta::CHOFER_SIN_SENAL)
            ->get()
            ->keyBy(fn (Alerta $a) => "{$a->viaje_id}:{$a->chofer_id}");

        $resueltas = $pendientes->diffKeys($sinSenal);
        Alerta::whereKey($resueltas->pluck('id'))->update(['resuelta_en' => now()]);

        $nuevas = $sinSenal->diffKeys($pendientes);
        foreach ($nuevas as $viaje) {
            $ubicacion = $viaje->chofer->ubicacion;
            $desde = $ubicacion
                ? 'desde las '.HoraLocal::formatear($ubicacion->actualizado_en, 'H:i')
                : 'desde que inició el turno';

            Alerta::create([
                'tipo' => Alerta::CHOFER_SIN_SENAL,
                'viaje_id' => $viaje->id,
                'chofer_id' => $viaje->chofer_id,
                'mensaje' => "{$viaje->chofer->nombre} no envía su ubicación $desde y tiene el viaje #{$viaje->id} activo.",
            ]);
        }

        return ['creadas' => $nuevas->count(), 'resueltas' => $resueltas->count()];
    }
}
```

`backend/app/Console/Commands/AlertarSinSenal.php`:

```php
<?php

namespace App\Console\Commands;

use App\Servicios\AlertasSinSenal;
use Illuminate\Console\Command;

class AlertarSinSenal extends Command
{
    protected $signature = 'vehiculos:alertar-sin-senal';

    protected $description = 'Alerta al panel por choferes sin señal durante un viaje y resuelve las que ya no aplican';

    public function handle(AlertasSinSenal $alertas): int
    {
        $r = $alertas->revisar();
        $this->info("Alertas creadas: {$r['creadas']}. Resueltas: {$r['resueltas']}.");

        return self::SUCCESS;
    }
}
```

Al final de `backend/routes/console.php` agregar:

```php
\Illuminate\Support\Facades\Schedule::command('vehiculos:alertar-sin-senal')->everyMinute()->withoutOverlapping();
```

En producción tiene que correr el scheduler (`* * * * * php artisan schedule:run` o `php artisan schedule:work`), igual que la purga de recorridos que ya existe.

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (280), incluidos `ModelosTest`, `EstadoChoferTest` y `TiempoRealTest`, que usan `activosDeChofer`.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: alerta al panel por chofer sin señal durante un viaje" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Alertas en el panel y resumen del tablero

**Files:**
- Create: `backend/app/Servicios/ResumenPanel.php`
- Create: `backend/app/Filament/Widgets/ResumenOperativo.php`
- Create: `backend/app/Filament/Resources/Alertas/AlertaResource.php`
- Create: `backend/app/Filament/Resources/Alertas/Pages/ListAlertas.php`
- Test: `backend/tests/Feature/Panel/AlertasPanelTest.php`

**Interfaces:**
- Consumes: `Alerta` (Task 7), `CalculadorEstadoChofer::choferesEnTurno()`, `Viaje::activosDeChofer()`, `ViajeResource::getUrl('view')` (Task 6).
- Produces: `ResumenPanel::alertasPendientes(): int`, `viajesSinChoferRecientes(): int`, `choferesSinSenalEnViaje(): Collection<Usuario>`; widget `ResumenOperativo` en el tablero (se descubre solo); recurso `/admin/alertas` con la acción de fila `resolver` y el badge de pendientes en el menú.

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/Panel/AlertasPanelTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Filament\Resources\Alertas\AlertaResource;
use App\Filament\Resources\Alertas\Pages\ListAlertas;
use App\Filament\Widgets\ResumenOperativo;
use App\Models\Alerta;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\ResumenPanel;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

it('lista primero las pendientes y las marca como resueltas', function () {
    $pendienteVieja = Alerta::create(['tipo' => Alerta::CHOFER_SIN_SENAL, 'mensaje' => 'A']);
    $this->travel(10)->minutes();
    $resuelta = Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'Resuelta', 'resuelta_en' => now()]);
    $pendienteNueva = Alerta::create(['tipo' => Alerta::CHOFER_SIN_SENAL, 'mensaje' => 'B']);

    Livewire::test(ListAlertas::class)
        ->assertCanSeeTableRecords([$pendienteNueva, $pendienteVieja, $resuelta], inOrder: true)
        ->assertActionHidden(TestAction::make('resolver')->table($resuelta))
        ->callAction(TestAction::make('resolver')->table($pendienteVieja));

    expect($pendienteVieja->fresh()->resuelta_en)->not->toBeNull()
        ->and(AlertaResource::getNavigationBadge())->toBe('1');
});

it('filtra las pendientes', function () {
    $resuelta = Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'x', 'resuelta_en' => now()]);
    $pendiente = Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'y']);

    Livewire::test(ListAlertas::class)
        ->filterTable('resuelta_en', false)
        ->assertCanSeeTableRecords([$pendiente])
        ->assertCanNotSeeTableRecords([$resuelta]);
});

it('cuenta alertas pendientes, viajes sin chofer de las últimas 24 h y choferes sin señal en viaje', function () {
    Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'x']);
    Alerta::create(['tipo' => Alerta::RESERVA_SIN_TURNO, 'mensaje' => 'y', 'resuelta_en' => now()]);
    Viaje::factory()->create(['estado' => EstadoViaje::SinChofer]);
    Viaje::factory()->create(['estado' => EstadoViaje::SinChofer, 'updated_at' => now()->subDays(2)]);
    $sinSenal = choferEnTurno(minutos: 5);
    Viaje::factory()->create(['chofer_id' => $sinSenal->id, 'estado' => EstadoViaje::EnCurso]);
    choferEnTurno(minutos: 5); // sin señal pero sin viaje: no cuenta

    $resumen = app(ResumenPanel::class);

    expect($resumen->alertasPendientes())->toBe(1)
        ->and($resumen->viajesSinChoferRecientes())->toBe(1)
        ->and($resumen->choferesSinSenalEnViaje()->pluck('id')->all())->toBe([$sinSenal->id]);

    Livewire::test(ResumenOperativo::class)
        ->assertSee('Alertas sin resolver')
        ->assertSee('Choferes sin señal en viaje')
        ->assertSee($sinSenal->nombre);
});

it('muestra el resumen en el tablero', function () {
    // Los widgets cargan en diferido: la página trae el componente y este dibuja los números.
    $this->get('/admin')->assertOk()->assertSeeLivewire(ResumenOperativo::class);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/Panel/AlertasPanelTest.php`
Expected: FAIL con `Class "App\Filament\Resources\Alertas\Pages\ListAlertas" not found` y `Class "App\Servicios\ResumenPanel" not found`.

- [ ] **Step 3: Números del tablero**

`backend/app/Servicios/ResumenPanel.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Models\Alerta;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;

/** Números del tablero del panel (spec 8.6). */
class ResumenPanel
{
    public function __construct(private CalculadorEstadoChofer $estados) {}

    public function alertasPendientes(): int
    {
        return Alerta::pendientes()->count();
    }

    /** Viajes que quedaron sin chofer en las últimas 24 h (updated_at es el momento de esa transición). */
    public function viajesSinChoferRecientes(): int
    {
        return Viaje::where('estado', EstadoViaje::SinChofer)
            ->where('updated_at', '>=', now()->subDay())
            ->count();
    }

    /** @return Collection<int, Usuario> choferes "sin señal" (spec 4.1) que tienen un viaje activo */
    public function choferesSinSenalEnViaje(): Collection
    {
        return $this->estados->choferesEnTurno()
            ->filter(fn (array $f) => $f['estado'] === EstadoChofer::SinSenal
                && Viaje::activosDeChofer($f['chofer']->id)->exists())
            ->map(fn (array $f) => $f['chofer'])
            ->values();
    }
}
```

`backend/app/Filament/Widgets/ResumenOperativo.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Alertas\AlertaResource;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Servicios\ResumenPanel;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ResumenOperativo extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $resumen = app(ResumenPanel::class);
        $alertas = $resumen->alertasPendientes();
        $sinChofer = $resumen->viajesSinChoferRecientes();
        $sinSenal = $resumen->choferesSinSenalEnViaje();

        return [
            Stat::make('Alertas sin resolver', $alertas)
                ->color($alertas > 0 ? 'danger' : 'success')
                ->url(AlertaResource::getUrl('index')),
            Stat::make('Viajes sin chofer (24 h)', $sinChofer)
                ->color($sinChofer > 0 ? 'warning' : 'success')
                ->url(ViajeResource::getUrl('index')),
            Stat::make('Choferes sin señal en viaje', $sinSenal->count())
                ->color($sinSenal->isNotEmpty() ? 'danger' : 'success')
                ->description($sinSenal->pluck('nombre')->join(', ') ?: 'Ninguno'),
        ];
    }
}
```

- [ ] **Step 4: Recurso de alertas**

`backend/app/Filament/Resources/Alertas/AlertaResource.php`:

```php
<?php

namespace App\Filament\Resources\Alertas;

use App\Filament\Resources\Alertas\Pages\ListAlertas;
use App\Filament\Resources\Viajes\ViajeResource;
use App\Models\Alerta;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Alertas del panel (spec 8.6): reservas sin turno y choferes sin señal durante un viaje. */
class AlertaResource extends Resource
{
    protected static ?string $model = Alerta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $modelLabel = 'alerta';

    protected static ?string $pluralModelLabel = 'alertas';

    protected static ?int $navigationSort = 15;

    private const TIPOS = [
        Alerta::RESERVA_SIN_TURNO => 'Reserva sin turno',
        Alerta::CHOFER_SIN_SENAL => 'Chofer sin señal',
    ];

    public static function getNavigationBadge(): ?string
    {
        $pendientes = Alerta::pendientes()->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $zona = config('vehiculos.zona_horaria');

        return $table
            ->columns([
                TextColumn::make('created_at')->label('Fecha')->dateTime('d/m H:i', $zona),
                TextColumn::make('tipo')->badge()->color('warning')
                    ->formatStateUsing(fn (string $state): string => self::TIPOS[$state] ?? $state),
                TextColumn::make('mensaje')->wrap(),
                TextColumn::make('viaje_id')->label('Viaje')->prefix('#')->placeholder('—')
                    ->url(fn (Alerta $record): ?string => $record->viaje_id
                        ? ViajeResource::getUrl('view', ['record' => $record->viaje_id])
                        : null),
                TextColumn::make('resuelta_en')->label('Resuelta')->dateTime('d/m H:i', $zona)->placeholder('Pendiente'),
            ])
            // Pendientes primero y, dentro de cada grupo, las más nuevas arriba.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('resuelta_en IS NOT NULL')
                ->orderByDesc('created_at'))
            ->filters([
                TernaryFilter::make('resuelta_en')
                    ->label('Resuelta')
                    ->nullable(),
            ])
            ->recordActions([
                Action::make('resolver')
                    ->label('Marcar resuelta')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->requiresConfirmation()
                    ->visible(fn (Alerta $record): bool => $record->resuelta_en === null)
                    ->action(fn (Alerta $record) => $record->update(['resuelta_en' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertas::route('/'),
        ];
    }
}
```

`backend/app/Filament/Resources/Alertas/Pages/ListAlertas.php`:

```php
<?php

namespace App\Filament\Resources\Alertas\Pages;

use App\Filament\Resources\Alertas\AlertaResource;
use Filament\Resources\Pages\ListRecords;

class ListAlertas extends ListRecords
{
    protected static string $resource = AlertaResource::class;
}
```

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (284).

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: alertas del panel y resumen operativo en el tablero" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Parámetros editables desde el panel

**Files:**
- Create: `backend/app/Filament/Pages/ConfiguracionParametros.php`
- Test: `backend/tests/Feature/Panel/ParametrosPanelTest.php`

**Interfaces:**
- Consumes: `config('vehiculos.parametros')`, `Parametro` (tabla `parametros`), `Parametros::entero()` (sin caché, A8), `Despachador::despachar()`.
- Produces: página `/admin/parametros` con una tabla de registros en array (`ConfiguracionParametros::filas(): array<string, array{clave, descripcion, por_defecto, actual, personalizado}>`) y las acciones de fila `editar` (`valor`: entero ≥ 1) y `restablecer` (borra la fila).

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/Panel/ParametrosPanelTest.php`:

```php
<?php

use App\Filament\Pages\ConfiguracionParametros;
use App\Models\OfertaViaje;
use App\Models\Parametro;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\Despachador;
use App\Servicios\Parametros;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => $this->actingAs(Usuario::factory()->admin()->create()));

it('lista todos los parámetros con su valor por defecto y el actual', function () {
    Parametro::create(['clave' => 'oferta_segundos', 'valor' => '45']);

    $filas = Livewire::test(ConfiguracionParametros::class)
        ->assertOk()
        ->assertSee('Segundos para responder un pedido inmediato')
        ->instance()
        ->filas();

    expect(array_keys($filas))->toBe(array_keys(config('vehiculos.parametros')))
        ->and($filas['oferta_segundos'])->toMatchArray(['por_defecto' => 30, 'actual' => 45, 'personalizado' => true])
        ->and($filas['colchon_reservas_min'])->toMatchArray(['por_defecto' => 30, 'actual' => 30, 'personalizado' => false]);
});

it('guarda un valor nuevo que rige en la operación siguiente', function () {
    Queue::fake();

    Livewire::test(ConfiguracionParametros::class)
        ->callAction(TestAction::make('editar')->table('oferta_segundos'), data: ['valor' => 45])
        ->assertHasNoActionErrors()
        ->assertNotified('Parámetro guardado');

    expect(Parametro::find('oferta_segundos')->valor)->toBe('45')
        ->and(app(Parametros::class)->entero('oferta_segundos'))->toBe(45);

    choferEnTurno();
    app(Despachador::class)->despachar(Viaje::factory()->create(['origen_lat' => -34.60, 'origen_lng' => -58.38]));
    $oferta = OfertaViaje::sole();
    expect((int) $oferta->ofrecido_en->diffInSeconds($oferta->vence_en))->toBe(45);
});

it('rechaza valores que no son enteros positivos', function (mixed $valor) {
    Livewire::test(ConfiguracionParametros::class)
        ->callAction(TestAction::make('editar')->table('colchon_reservas_min'), data: ['valor' => $valor])
        ->assertHasActionErrors(['valor']);

    expect(Parametro::count())->toBe(0);
})->with([0, -5, 'diez', '']);

it('restablece el valor por defecto', function () {
    Parametro::create(['clave' => 'colchon_reservas_min', 'valor' => '10']);

    Livewire::test(ConfiguracionParametros::class)
        ->assertActionHidden(TestAction::make('restablecer')->table('oferta_segundos'))
        ->callAction(TestAction::make('restablecer')->table('colchon_reservas_min'));

    expect(Parametro::count())->toBe(0)
        ->and(app(Parametros::class)->entero('colchon_reservas_min'))->toBe(30);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/Panel/ParametrosPanelTest.php`
Expected: FAIL con `Class "App\Filament\Pages\ConfiguracionParametros" not found`.

- [ ] **Step 3: Página de parámetros**

`backend/app/Filament/Pages/ConfiguracionParametros.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Models\Parametro;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Parámetros de la spec 5.7. Los valores por defecto están en config('vehiculos.parametros'); un valor
 * cambiado se guarda en la tabla `parametros` y Parametros::entero() lo lee en cada llamada (sin caché),
 * así que rige desde la operación siguiente. "Restablecer" borra la fila y vuelve al valor por defecto.
 */
class ConfiguracionParametros extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Parámetros';

    protected static ?string $title = 'Parámetros';

    protected static ?string $slug = 'parametros';

    protected static ?int $navigationSort = 50;

    private const DESCRIPCIONES = [
        'oferta_segundos' => 'Segundos para responder un pedido inmediato',
        'candidatos_distance_matrix' => 'Choferes más cercanos evaluados con Distance Matrix',
        'bloqueo_antes_reserva_min' => 'Minutos antes de una reserva en que el chofer deja de recibir inmediatos',
        'colchon_reservas_min' => 'Minutos de colchón entre reservas del mismo chofer',
        'anticipacion_minima_reserva_min' => 'Anticipación mínima para reservar (minutos)',
        'plazo_respuesta_reserva_min' => 'Minutos para responder una solicitud de reserva',
        'margen_duracion_reserva_min' => 'Minutos que se suman a la duración estimada de una reserva',
        'duracion_reserva_por_defecto_min' => 'Duración de una reserva si Google no la estima (minutos)',
        'recordatorio_reserva_1_min' => 'Primer recordatorio de reserva (minutos antes)',
        'recordatorio_reserva_2_min' => 'Segundo recordatorio de reserva (minutos antes)',
        'alerta_sin_turno_min' => 'Alerta si el chofer no inició turno (minutos antes de la reserva)',
        'sin_senal_min' => 'Minutos sin ubicación para pasar a "sin señal"',
        'no_disponible_min' => 'Minutos sin ubicación para alertar al panel durante un viaje',
        'gps_turno_seg' => 'Intervalo de GPS en turno (segundos)',
        'gps_viaje_seg' => 'Intervalo de GPS en viaje (segundos)',
        'retencion_recorrido_dias' => 'Días que se guarda el recorrido de un viaje',
    ];

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->filas())
            ->paginated(false)
            ->columns([
                TextColumn::make('descripcion')->label('Parámetro')->description(fn (array $record): string => $record['clave']),
                TextColumn::make('por_defecto')->label('Por defecto'),
                TextColumn::make('actual')->label('Valor actual')->weight('bold'),
                IconColumn::make('personalizado')->label('Cambiado')->boolean(),
            ])
            ->recordActions([
                Action::make('editar')
                    ->label('Cambiar')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (array $record): string => $record['descripcion'])
                    ->fillForm(fn (array $record): array => ['valor' => $record['actual']])
                    ->schema([
                        TextInput::make('valor')
                            ->label('Valor')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(100000),
                    ])
                    ->action(function (array $record, array $data): void {
                        Parametro::updateOrCreate(['clave' => $record['clave']], ['valor' => (string) (int) $data['valor']]);
                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title('Parámetro guardado')->send();
                    }),
                Action::make('restablecer')
                    ->label('Restablecer')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (array $record): bool => $record['personalizado'])
                    ->action(function (array $record): void {
                        Parametro::whereKey($record['clave'])->delete();
                        $this->flushCachedTableRecords();
                        Notification::make()->success()->title('Se restableció el valor por defecto')->send();
                    }),
            ]);
    }

    /** @return array<string, array{clave: string, descripcion: string, por_defecto: int, actual: int, personalizado: bool}> */
    public function filas(): array
    {
        $guardados = Parametro::pluck('valor', 'clave');

        return collect(config('vehiculos.parametros'))
            ->mapWithKeys(fn (int $defecto, string $clave): array => [$clave => [
                'clave' => $clave,
                'descripcion' => self::DESCRIPCIONES[$clave] ?? $clave,
                'por_defecto' => $defecto,
                'actual' => (int) ($guardados[$clave] ?? $defecto),
                'personalizado' => $guardados->has($clave),
            ]])
            ->all();
    }
}
```

La página no necesita vista propia: la vista por defecto de `Page` dibuja `content()`, que embebe la tabla (`EmbeddedTable`). Si se agrega un parámetro nuevo a `config/vehiculos.php`, aparece solo; conviene sumarle su descripción a `DESCRIPCIONES` (si falta, se muestra la clave).

- [ ] **Step 4: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (291).

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "feat: parámetros de la spec 5.7 editables desde el panel" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Mapa en vivo

**Files:**
- Modify: `backend/config/vehiculos.php`, `backend/.env.example`
- Create: `backend/app/Servicios/DatosMapaPanel.php`
- Create: `backend/app/Filament/Pages/MapaEnVivo.php`
- Create: `backend/resources/views/filament/pages/mapa-en-vivo.blade.php`
- Test: `backend/tests/Feature/Panel/MapaEnVivoTest.php`

**Interfaces:**
- Consumes: `CalculadorEstadoChofer::choferesEnTurno()` (carga `ubicacion` y `turnoAbierto.vehiculo`), `Viaje::activos()` (Task 7), `EstadoChofer::getLabel()` y `EstadoViaje::getLabel()` (Tasks 3 y 6), `HoraLocal`.
- Produces: `config('vehiculos.mapas.google_js_api_key')`; `DatosMapaPanel::obtener(): array{choferes: list<array>, viajes: list<array>}` y `DatosMapaPanel::COLORES`; página `/admin/mapa` con `datosMapa()`, `claveGoogle()` y `refrescar()` (evento de navegador `mapa-datos`).

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/Panel/MapaEnVivoTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Filament\Pages\MapaEnVivo;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\DatosMapaPanel;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-01 12:00:00'));
    $this->actingAs(Usuario::factory()->admin()->create());
});

it('arma los choferes en turno con su color y los viajes activos con origen y destino', function () {
    $libre = choferEnTurno(-34.60, -58.38);
    $enViaje = choferEnTurno(-34.61, -58.39);
    $sinSenal = choferEnTurno(-34.62, -58.40, minutos: 5);
    Turno::factory()->create(); // en turno pero nunca mandó ubicación: no se dibuja
    $viaje = Viaje::factory()->create([
        'chofer_id' => $enViaje->id, 'estado' => EstadoViaje::EnCamino,
        'origen_direccion' => 'Tribunales', 'destino_direccion' => 'Casa de Gobierno',
    ]);
    Viaje::factory()->create(['estado' => EstadoViaje::Buscando]); // sin chofer: no es activo

    $datos = app(DatosMapaPanel::class)->obtener();

    expect(collect($datos['choferes'])->pluck('color', 'id')->all())->toBe([
        $libre->id => '#16a34a',
        $enViaje->id => '#2563eb',
        $sinSenal->id => '#dc2626',
    ]);
    expect($datos['choferes'][0])->toMatchArray([
        'nombre' => $libre->nombre,
        'estado' => 'libre',
        'estado_etiqueta' => 'Libre',
        'lat' => -34.60,
        'lng' => -58.38,
        'patente' => $libre->turnoAbierto->vehiculo->patente,
        'actualizado_en' => '09:00:00',
    ]);
    expect($datos['viajes'])->toBe([[
        'id' => $viaje->id,
        'estado' => 'en_camino',
        'estado_etiqueta' => 'En camino',
        'chofer_id' => $enViaje->id,
        'chofer' => $enViaje->nombre,
        'origen' => ['lat' => -34.6037, 'lng' => -58.3816, 'direccion' => 'Tribunales'],
        'destino' => ['lat' => -34.609, 'lng' => -58.392, 'direccion' => 'Casa de Gobierno'],
    ]]);
});

it('muestra el mapa con la API key y refresca por polling', function () {
    config(['vehiculos.mapas.google_api_key' => 'clave-de-prueba']);
    choferEnTurno();

    Livewire::test(MapaEnVivo::class)
        ->assertOk()
        ->assertSeeHtml('wire:poll.10s="refrescar"')
        ->assertSeeHtml('id="mapa-en-vivo"')
        ->call('refrescar')
        ->assertDispatched('mapa-datos');

    $this->get(MapaEnVivo::getUrl())->assertOk();
});

it('prefiere la clave propia del mapa a la del servidor', function () {
    config(['vehiculos.mapas.google_api_key' => 'clave-servidor', 'vehiculos.mapas.google_js_api_key' => 'clave-navegador']);

    expect(Livewire::test(MapaEnVivo::class)->instance()->claveGoogle())->toBe('clave-navegador');
});

it('explica que falta la API key en lugar de mostrar el mapa', function () {
    config(['vehiculos.mapas.google_api_key' => null, 'vehiculos.mapas.google_js_api_key' => null]);

    Livewire::test(MapaEnVivo::class)
        ->assertSee('Falta la API key de Google Maps')
        ->assertDontSeeHtml('id="mapa-en-vivo"');
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/Panel/MapaEnVivoTest.php`
Expected: FAIL con `Class "App\Servicios\DatosMapaPanel" not found` y `Class "App\Filament\Pages\MapaEnVivo" not found`.

- [ ] **Step 3: Clave de Google para el navegador**

En `backend/config/vehiculos.php`, dentro de `'mapas' => [...]`, después de `'google_api_key' => env('GOOGLE_MAPS_API_KEY'),`, agregar:

```php
        // Clave para el mapa del panel (Maps JavaScript API): queda visible en el navegador, conviene una
        // distinta, restringida por HTTP referrer. Si falta se usa google_api_key.
        'google_js_api_key' => env('GOOGLE_MAPS_JS_API_KEY'),
```

Al final de `backend/.env.example` agregar:

```
# Google Maps: la clave del servidor (Distance Matrix, Directions) y la del mapa del panel (Maps JavaScript API,
# restringida por HTTP referrer). Sin clave JS el panel usa la del servidor; sin ninguna, no muestra el mapa.
GOOGLE_MAPS_API_KEY=
GOOGLE_MAPS_JS_API_KEY=
```

- [ ] **Step 4: Datos del mapa**

`backend/app/Servicios/DatosMapaPanel.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Models\Viaje;
use App\Support\HoraLocal;

/** Lo que dibuja el mapa en vivo del panel (spec 8.1): choferes en turno y viajes activos. */
class DatosMapaPanel
{
    /** Colores del marcador de cada chofer según su estado (spec 4.1). */
    public const COLORES = [
        'libre' => '#16a34a',
        'en_viaje' => '#2563eb',
        'reservado_pronto' => '#d97706',
        'sin_senal' => '#dc2626',
    ];

    public function __construct(private CalculadorEstadoChofer $estados) {}

    /**
     * @return array{
     *     choferes: list<array{id: int, nombre: string, estado: string, estado_etiqueta: string, color: string, lat: float, lng: float, patente: ?string, actualizado_en: string}>,
     *     viajes: list<array{id: int, estado: string, estado_etiqueta: string, chofer_id: int, chofer: string, origen: array{lat: float, lng: float, direccion: ?string}, destino: array{lat: float, lng: float, direccion: ?string}}>
     * }
     */
    public function obtener(): array
    {
        $choferes = $this->estados->choferesEnTurno()
            // Sin ninguna ubicación todavía no hay dónde dibujarlo.
            ->filter(fn (array $f) => $f['chofer']->ubicacion !== null)
            ->map(function (array $f): array {
                /** @var EstadoChofer $estado */
                $estado = $f['estado'];
                $chofer = $f['chofer'];

                return [
                    'id' => $chofer->id,
                    'nombre' => $chofer->nombre,
                    'estado' => $estado->value,
                    'estado_etiqueta' => $estado->getLabel(),
                    'color' => self::COLORES[$estado->value] ?? '#6b7280',
                    'lat' => $chofer->ubicacion->lat,
                    'lng' => $chofer->ubicacion->lng,
                    'patente' => $chofer->turnoAbierto?->vehiculo?->patente,
                    'actualizado_en' => HoraLocal::formatear($chofer->ubicacion->actualizado_en, 'H:i:s'),
                ];
            })
            ->values()
            ->all();

        $viajes = Viaje::activos()
            ->with('chofer')
            ->orderBy('id')
            ->get()
            ->map(fn (Viaje $v): array => [
                'id' => $v->id,
                'estado' => $v->estado->value,
                'estado_etiqueta' => $v->estado->getLabel(),
                'chofer_id' => $v->chofer_id,
                'chofer' => $v->chofer->nombre,
                'origen' => ['lat' => $v->origen_lat, 'lng' => $v->origen_lng, 'direccion' => $v->origen_direccion],
                'destino' => ['lat' => $v->destino_lat, 'lng' => $v->destino_lng, 'direccion' => $v->destino_direccion],
            ])
            ->all();

        return ['choferes' => $choferes, 'viajes' => $viajes];
    }
}
```

- [ ] **Step 5: Página y vista**

`backend/app/Filament/Pages/MapaEnVivo.php`:

```php
<?php

namespace App\Filament\Pages;

use App\Servicios\DatosMapaPanel;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Mapa en vivo (spec 8.1). Sin Echo/Reverb en el panel (v1): Livewire consulta cada 10 s y le pasa
 * los datos nuevos al mapa con un evento del navegador; el mapa no se vuelve a dibujar (wire:ignore).
 */
class MapaEnVivo extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $navigationLabel = 'Mapa en vivo';

    protected static ?string $title = 'Mapa en vivo';

    protected static ?string $slug = 'mapa';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.mapa-en-vivo';

    /** @return array{choferes: array, viajes: array} */
    public function datosMapa(): array
    {
        return app(DatosMapaPanel::class)->obtener();
    }

    public function claveGoogle(): ?string
    {
        return config('vehiculos.mapas.google_js_api_key') ?: config('vehiculos.mapas.google_api_key') ?: null;
    }

    /** Lo llama wire:poll; el script del mapa escucha el evento y redibuja los marcadores. */
    public function refrescar(): void
    {
        $this->dispatch('mapa-datos', datos: $this->datosMapa());
    }
}
```

`backend/resources/views/filament/pages/mapa-en-vivo.blade.php`:

```blade
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
```

Notas: los estilos van en línea porque el tema de Filament no compila clases de Tailwind de vistas propias. `@script` ejecuta el bloque una sola vez, con `$wire` disponible. `wire:ignore` evita que el polling vuelva a crear el mapa: cada 10 s `refrescar()` despacha `mapa-datos` y el script redibuja los marcadores sin mover el encuadre.

- [ ] **Step 6: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS (295).

- [ ] **Step 7: Prueba manual (opcional, con una clave real)**

Con `GOOGLE_MAPS_API_KEY` (o `GOOGLE_MAPS_JS_API_KEY`) en `.env`, `php artisan serve` y `php artisan simular:choferes 5` en otra terminal, entrar a `http://localhost:8000/admin/mapa` con un admin creado con `php artisan vehiculos:crear-admin`. Los puntos se mueven cada 10 s sin que el mapa se recargue.

- [ ] **Step 8: Commit**

```bash
git add backend
git commit -m "feat: mapa en vivo de choferes y viajes activos en el panel" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Carrera real: reasignación del admin contra asignación de un obligatorio

**Files:**
- Modify: `backend/tests/Concurrencia/proceso_carrera.php`
- Modify: `backend/tests/Concurrencia/CarrerasTest.php`

**Interfaces:**
- Consumes: `ServicioViaje::reasignarPorAdmin()` (Task 5), `Asignador::asignar()`, `carrera()` (suite existente).
- Produces: acción `reasignar_admin` (`{viaje, chofer}`) en el proceso de carrera y un test contra MySQL/MariaDB.

- [ ] **Step 1: Acción nueva en el proceso de carrera**

En `backend/tests/Concurrencia/proceso_carrera.php`, dentro del `match ($accion)`, antes de la acción `'aceptar_oferta'`, agregar:

```php
        'reasignar_admin' => app(ServicioViaje::class)
            ->reasignarPorAdmin(Viaje::find($a['viaje']), Usuario::find($a['chofer']))->estado->value,
```

(`ServicioViaje`, `Viaje` y `Usuario` ya están importados.)

- [ ] **Step 2: Escribir el test**

Al final de `backend/tests/Concurrencia/CarrerasTest.php` agregar:

```php
it('el admin reasigna un viaje a un chofer mientras se le asigna un obligatorio: queda con un solo viaje activo', function () {
    foreach (range(1, 10) as $_) {
        $chofer = choferEnTurno();
        $aReasignar = Viaje::factory()->create([
            'chofer_id' => choferEnTurno()->id, 'estado' => EstadoViaje::Aceptado, 'aceptado_en' => now(),
        ]);
        $obligatorio = Viaje::factory()->create(['obligatorio' => true]);

        [$reasignacion, $asignacion] = carrera([
            ['reasignar_admin', ['viaje' => $aReasignar->id, 'chofer' => $chofer->id]],
            ['asignar', ['viaje' => $obligatorio->id, 'chofer' => $chofer->id]],
        ]);

        $ganoReasignacion = $reasignacion['ok'];
        $ganoAsignacion = $asignacion['ok'] && $asignacion['resultado'] === true;
        expect($ganoReasignacion xor $ganoAsignacion)->toBeTrue(json_encode([$reasignacion, $asignacion]))
            ->and(Viaje::activosDeChofer($chofer->id)->count())->toBe(1)
            // El perdedor quedó como estaba.
            ->and($aReasignar->fresh()->chofer_id === $chofer->id)->toBe($ganoReasignacion)
            ->and($obligatorio->fresh()->estado)->toBe($ganoAsignacion ? EstadoViaje::Aceptado : EstadoViaje::Buscando);
    }
});
```

(`EstadoViaje` y `Viaje` ya están importados en ese archivo.)

- [ ] **Step 3: Correr la suite de carreras**

Run: `./vendor/bin/pest tests/Concurrencia`
Expected: los 7 tests PASS contra MySQL/MariaDB (base vacía `vehiculos_concurrencia`; ver el encabezado de `CarrerasTest.php`). Si no hay servidor, se marcan *skipped*; en ese caso hay que correrlos contra MariaDB antes de mergear. La migración nueva de la Task 1 corre con `migrate:fresh` en la preparación de la suite.

Verificación opcional de que el test detecta el problema: en `ServicioViaje::reasignarPorAdmin()`, cambiar temporalmente `Usuario::whereKey($chofer->id)->lockForUpdate()->first()` por `Usuario::whereKey($chofer->id)->first()`. La carrera tiene que fallar (en la verificación previa falló en la primera vuelta). Después, restaurar el código.

- [ ] **Step 4: Correr la suite normal**

Run: `./vendor/bin/pest`
Expected: todos PASS (295; la suite normal no incluye `tests/Concurrencia`).

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "test: carrera real de reasignación del admin contra asignación obligatoria" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Cobertura del spec en este plan

| Spec | Task |
|---|---|
| 2 / 3.1 Panel admin web con Filament sobre el mismo Laravel | 1 |
| 4 `usuarios` (acceso al panel: email, contraseña) | 1 |
| 4.1 Estado del chofer calculado (columna del listado, colores del mapa, "sin señal" del tablero) | 3, 8, 10 |
| 5.6 Admin cancela viajes (incluidos obligatorios y en curso) | 4, 6 |
| 5.6 Admin reasigna viajes y reservas | 5, 6, 11 |
| 5.7 Parámetros configurables | 9 |
| 5.8 Concurrencia (bloqueo viaje → chofer, copias desactualizadas, carrera real) | 4, 5, 11 |
| 8.1 Mapa en vivo de choferes y viajes activos | 10 |
| 8.2 ABM de vehículos | 2 |
| 8.3 Choferes: asignar o quitar el rol, ver estado y turnos | 3 |
| 8.4 Cargos prioritarios | 2 |
| 8.5 Viajes y reservas: listado con filtros, detalle con línea de tiempo, ofertas y recorrido; reasignar y cancelar | 6 |
| 8.6 Alertas: reservas sin turno, viajes `sin_chofer`, choferes sin señal durante un viaje | 7, 8 |
| 8 Acceso solo con rol `admin` | 1 |
| 9 Chofer sin señal: a los 10 min con viaje activo, alerta en el panel | 7 |

**Fuera de este plan:**
- Tiempo real en el panel con Echo/Reverb (v1 usa polling cada 10 s).
- Dibujar el recorrido GPS completo en el detalle del viaje (se muestran cantidad de puntos, primero y último).
- Reportes y exportación (spec 13), asignación automática del rol de chofer según el cargo (spec 8, nota final).
- Pantallas Flutter (plan siguiente).
