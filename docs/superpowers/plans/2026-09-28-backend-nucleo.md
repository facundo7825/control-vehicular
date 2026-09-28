# Backend núcleo — Vehículos Oficiales — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Backend Laravel que autentica usuarios del Poder Judicial, gestiona turnos y ubicación de choferes, despacha pedidos inmediatos (más cercano / chofer específico, con viajes obligatorios por cargo), y emite eventos en tiempo real (Reverb) y push (FCM).

**Architecture:** Laravel 12 monolítico en `backend/`. La lógica de negocio vive en servicios (`app/Servicios`) invocados por controladores delgados; los integradores externos (identidad PJ, Google Maps, FCM) están detrás de interfaces con implementaciones falsas para tests y desarrollo. El estado del chofer se calcula, nunca se almacena. Todas las transiciones de viaje pasan por `MaquinaEstadosViaje`.

**Tech Stack:** PHP 8.3, Laravel 12, Pest 3, Laravel Sanctum, Laravel Reverb, kreait/laravel-firebase, MySQL 8 / MariaDB 10.6+ (producción), SQLite en memoria (tests).

**Spec:** `docs/superpowers/specs/2026-09-28-vehiculos-oficiales-design.md`

**Planes hermanos (se escriben después, dependen de este):** 2) Reservas a futuro, 3) Panel admin Filament, 4) Paquete Flutter + host de prueba.

## Global Constraints

- Nombres de tablas, columnas, clases de dominio y rutas en **español**, tal como en la sección 4 del spec.
- Parámetros configurables con estos valores por defecto (spec 5.7): `oferta_segundos=30`, `candidatos_distance_matrix=5`, `bloqueo_antes_reserva_min=45`, `colchon_reservas_min=30`, `anticipacion_minima_reserva_min=60`, `plazo_respuesta_reserva_min=30`, `sin_senal_min=2`, `no_disponible_min=10`, `gps_turno_seg=10`, `gps_viaje_seg=5`, `retencion_recorrido_dias=90`.
- La ubicación solo se acepta y guarda con un turno abierto (spec 10).
- Toda transición de estado de un viaje pasa por `MaquinaEstadosViaje`; transiciones repetidas son no-op sin error (spec 5.1).
- Viaje obligatorio: se asigna sin oferta y el chofer no puede cancelarlo (spec 5.2, 5.6).
- Asignación chofer↔viaje dentro de transacción con `lockForUpdate()` (spec 5.8).
- Integraciones externas (identidad PJ, Google Maps, FCM) siempre detrás de interfaz; los tests nunca llaman a servicios reales.
- Cada commit termina con la línea `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Dos pedidos compiten por el mismo chofer** → solo uno lo obtiene; el otro sigue buscando. Test en Task 8.
2. **El job de vencimiento de oferta corre después de que el chofer aceptó** → no hace nada. Test en Task 9.
3. **Un chofer que rechazó un viaje "más cercano"** → no vuelve a recibir la oferta de ese mismo viaje. Test en Task 9.
4. **Chofer con última ubicación de hace más de 2 min** → no aparece como libre ni recibe pedidos. Test en Task 6.
5. **Chofer intenta cancelar un viaje obligatorio** → 403 y el viaje sigue igual. Test en Task 11.

## Estructura de archivos

```
backend/
  config/vehiculos.php                      # identidad, google, parámetros por defecto
  app/Enums/                                # RolUsuario, EstadoViaje, TipoViaje, ModoViaje, ResultadoOferta, EstadoChofer, OrigenTurno
  app/Models/                               # Usuario, CargoPrioritario, Vehiculo, Turno, UbicacionChofer, Viaje, OfertaViaje, PuntoRecorrido, Parametro
  app/Identidad/                            # ProveedorIdentidad, DatosIdentidad, IdentidadSimulada, EndpointPoderJudicial
  app/Mapas/                                # ServicioMapas, GoogleMaps, ServicioMapasFalso, Distancia
  app/Notificaciones/                       # Notificador, NotificadorFcm, NotificadorRegistro, AvisosViaje
  app/Servicios/                            # Parametros, ServicioTurnos, ServicioUbicacion, CalculadorEstadoChofer,
                                            # MaquinaEstadosViaje, Asignador, Despachador, ServicioViaje
  app/Excepciones/                          # ReglaNegocio (422), TransicionInvalida
  app/Events/                               # UbicacionChoferActualizada, ViajeActualizado, OfertaCreada
  app/Jobs/VencerOferta.php
  app/Http/Controllers/                     # AuthController, TurnoController, UbicacionController, ViajeController, OfertaController, MapaController, PushController
  app/Console/Commands/SimularChoferes.php
  routes/api.php, routes/channels.php
  database/migrations/2026_09_28_000001_crear_tablas_vehiculos.php
  database/factories/
  tests/Feature/..., tests/Unit/...
```

---

### Task 1: Esqueleto Laravel + Pest

**Files:**
- Create: `backend/` (proyecto Laravel)
- Modify: `backend/phpunit.xml`, `backend/tests/Pest.php`
- Test: `backend/tests/Feature/SaludTest.php`

**Interfaces:**
- Produces: proyecto Laravel con Sanctum, `routes/api.php`, Pest con `RefreshDatabase` en `tests/Feature`.

- [ ] **Step 1: Verificar herramientas**

Run: `php -v && composer -V`
Expected: PHP 8.3 o superior y Composer 2.x. Si PHP es menor a 8.2, detenerse y avisar (Laravel 12 lo requiere).

- [ ] **Step 2: Crear el proyecto e instalar API + Pest**

```bash
composer create-project laravel/laravel backend "^12.0"
cd backend
php artisan install:api --no-interaction
composer remove phpunit/phpunit --dev
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
./vendor/bin/pest --init
```

- [ ] **Step 3: Configurar entorno de tests**

En `backend/phpunit.xml`, dentro de `<php>`, dejar exactamente estas entradas (descomentar/agregar):

```xml
<env name="APP_ENV" value="testing"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="BROADCAST_CONNECTION" value="null"/>
<env name="CACHE_STORE" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
```

Reemplazar el contenido de `backend/tests/Pest.php` por:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 4: Escribir test de humo**

`backend/tests/Feature/SaludTest.php`:

```php
<?php

it('responde el chequeo de salud', function () {
    $this->get('/up')->assertOk();
});
```

Borrar `tests/Feature/ExampleTest.php` y `tests/Unit/ExampleTest.php`.

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: 1 passed.

- [ ] **Step 6: Commit** (desde la raíz del repo)

```bash
git add backend
git commit -m "chore: esqueleto Laravel 12 con Sanctum y Pest" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Enums, migración y modelos

**Files:**
- Create: `backend/app/Enums/{RolUsuario,EstadoViaje,TipoViaje,ModoViaje,ResultadoOferta,OrigenTurno}.php`
- Create: `backend/database/migrations/2026_09_28_000001_crear_tablas_vehiculos.php`
- Create: `backend/app/Models/{Usuario,CargoPrioritario,Vehiculo,Turno,UbicacionChofer,Viaje,OfertaViaje,PuntoRecorrido,Parametro}.php`
- Create: `backend/database/factories/{UsuarioFactory,VehiculoFactory,TurnoFactory,ViajeFactory}.php`
- Modify: `backend/config/auth.php` (modelo del provider `users`)
- Test: `backend/tests/Feature/ModelosTest.php`

**Interfaces:**
- Produces:
  - `EstadoViaje::enProgreso(): array<EstadoViaje>` (buscando…en_curso), `EstadoViaje::conChofer(): array<EstadoViaje>` (aceptado…en_curso).
  - `Usuario::turnoAbierto(): HasOne<Turno>`, `Usuario::ubicacion(): HasOne<UbicacionChofer>`, `Usuario::esChofer(): bool`.
  - `Viaje::scopeActivosDeChofer(Builder $q, int $choferId)`: viajes en `conChofer()` cuyo `programado_para` es null o ya pasó.
  - Factories: `Usuario::factory()->chofer()`, `->admin()`; `Vehiculo::factory()`; `Turno::factory()`; `Viaje::factory()`.

- [ ] **Step 1: Escribir test que falla**

`backend/tests/Feature/ModelosTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;

it('persiste un viaje con enums casteados', function () {
    $viaje = Viaje::factory()->create();

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Buscando)
        ->and($viaje->fresh()->tipo)->toBe(TipoViaje::Inmediato)
        ->and($viaje->solicitante->rol)->toBe(RolUsuario::Solicitante);
});

it('encuentra el turno abierto del chofer', function () {
    $chofer = Usuario::factory()->chofer()->create();
    Turno::factory()->for($chofer, 'chofer')->create(['fin' => now()->subHour()]);
    $abierto = Turno::factory()->for($chofer, 'chofer')->create(['fin' => null]);

    expect($chofer->turnoAbierto->id)->toBe($abierto->id);
});

it('no considera activa una reserva aceptada que es a futuro', function () {
    $chofer = Usuario::factory()->chofer()->create();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'estado' => EstadoViaje::Aceptado,
        'tipo' => TipoViaje::Reserva,
        'programado_para' => now()->addDay(),
    ]);
    $inmediato = Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'estado' => EstadoViaje::EnCamino,
    ]);

    expect(Viaje::activosDeChofer($chofer->id)->pluck('id')->all())->toBe([$inmediato->id]);
});
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `./vendor/bin/pest tests/Feature/ModelosTest.php`
Expected: FAIL con `Class "App\Enums\EstadoViaje" not found`.

- [ ] **Step 3: Crear enums**

`backend/app/Enums/RolUsuario.php`:

```php
<?php

namespace App\Enums;

enum RolUsuario: string
{
    case Solicitante = 'solicitante';
    case Chofer = 'chofer';
    case Admin = 'admin';
}
```

`backend/app/Enums/EstadoViaje.php`:

```php
<?php

namespace App\Enums;

enum EstadoViaje: string
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
}
```

`backend/app/Enums/TipoViaje.php`:

```php
<?php

namespace App\Enums;

enum TipoViaje: string
{
    case Inmediato = 'inmediato';
    case Reserva = 'reserva';
}
```

`backend/app/Enums/ModoViaje.php`:

```php
<?php

namespace App\Enums;

enum ModoViaje: string
{
    case MasCercano = 'mas_cercano';
    case Especifico = 'especifico';
    case CualquieraDisponible = 'cualquiera_disponible';
}
```

`backend/app/Enums/ResultadoOferta.php`:

```php
<?php

namespace App\Enums;

enum ResultadoOferta: string
{
    case Pendiente = 'pendiente';
    case Aceptada = 'aceptada';
    case Rechazada = 'rechazada';
    case Expirada = 'expirada';
}
```

`backend/app/Enums/OrigenTurno.php`:

```php
<?php

namespace App\Enums;

enum OrigenTurno: string
{
    case Manual = 'manual';
    case Asistencia = 'asistencia';
}
```

- [ ] **Step 4: Crear la migración**

`backend/database/migrations/2026_09_28_000001_crear_tablas_vehiculos.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $t) {
            $t->id();
            $t->string('id_externo')->unique();
            $t->string('nombre');
            $t->string('cargo')->nullable();
            $t->string('rol')->default('solicitante');
            $t->string('telefono')->nullable();
            $t->string('token_push')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('cargos_prioritarios', function (Blueprint $t) {
            $t->id();
            $t->string('cargo')->unique();
            $t->boolean('obligatorio')->default(false);
            $t->timestamps();
        });

        Schema::create('vehiculos', function (Blueprint $t) {
            $t->id();
            $t->string('patente')->unique();
            $t->string('marca');
            $t->string('modelo');
            $t->string('color')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });

        Schema::create('turnos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chofer_id')->constrained('usuarios');
            $t->foreignId('vehiculo_id')->constrained('vehiculos');
            $t->timestamp('inicio');
            $t->timestamp('fin')->nullable();
            $t->string('origen')->default('manual');
            $t->timestamps();
            $t->index(['chofer_id', 'fin']);
            $t->index(['vehiculo_id', 'fin']);
        });

        Schema::create('ubicaciones_chofer', function (Blueprint $t) {
            $t->foreignId('chofer_id')->primary()->constrained('usuarios');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->float('rumbo')->nullable();
            $t->float('velocidad')->nullable();
            $t->timestamp('actualizado_en');
        });

        Schema::create('viajes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('solicitante_id')->constrained('usuarios');
            $t->foreignId('chofer_id')->nullable()->constrained('usuarios');
            $t->foreignId('vehiculo_id')->nullable()->constrained('vehiculos');
            $t->string('tipo');
            $t->string('modo');
            $t->boolean('obligatorio')->default(false);
            $t->decimal('origen_lat', 10, 7);
            $t->decimal('origen_lng', 10, 7);
            $t->string('origen_direccion')->nullable();
            $t->decimal('destino_lat', 10, 7);
            $t->decimal('destino_lng', 10, 7);
            $t->string('destino_direccion')->nullable();
            $t->string('motivo')->nullable();
            $t->timestamp('programado_para')->nullable();
            $t->unsignedInteger('duracion_estimada_min')->nullable();
            $t->string('estado');
            $t->timestamp('aceptado_en')->nullable();
            $t->timestamp('llego_en')->nullable();
            $t->timestamp('iniciado_en')->nullable();
            $t->timestamp('finalizado_en')->nullable();
            $t->timestamp('cancelado_en')->nullable();
            $t->string('cancelado_por')->nullable();
            $t->string('motivo_cancelacion')->nullable();
            $t->timestamps();
            $t->index(['chofer_id', 'estado']);
            $t->index(['solicitante_id', 'estado']);
        });

        Schema::create('ofertas_viaje', function (Blueprint $t) {
            $t->id();
            $t->foreignId('viaje_id')->constrained('viajes');
            $t->foreignId('chofer_id')->constrained('usuarios');
            $t->string('resultado')->default('pendiente');
            $t->timestamp('ofrecido_en');
            $t->timestamp('vence_en');
            $t->timestamp('respondido_en')->nullable();
            $t->string('motivo')->nullable(); // motivo si el chofer canceló tras aceptar
            $t->timestamps();
            $t->index(['chofer_id', 'resultado']);
        });

        Schema::create('recorrido_viaje', function (Blueprint $t) {
            $t->id();
            $t->foreignId('viaje_id')->constrained('viajes');
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->timestamp('registrado_en');
        });

        Schema::create('parametros', function (Blueprint $t) {
            $t->string('clave')->primary();
            $t->string('valor');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['parametros', 'recorrido_viaje', 'ofertas_viaje', 'viajes', 'ubicaciones_chofer',
                  'turnos', 'vehiculos', 'cargos_prioritarios', 'usuarios'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
```

- [ ] **Step 5: Crear modelos**

`backend/app/Models/Usuario.php`:

```php
<?php

namespace App\Models;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['id_externo', 'nombre', 'cargo', 'rol', 'telefono', 'token_push', 'activo'];

    protected $hidden = ['token_push'];

    protected function casts(): array
    {
        return ['rol' => RolUsuario::class, 'activo' => 'boolean'];
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
}
```

`backend/app/Models/CargoPrioritario.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargoPrioritario extends Model
{
    protected $table = 'cargos_prioritarios';

    protected $fillable = ['cargo', 'obligatorio'];

    protected function casts(): array
    {
        return ['obligatorio' => 'boolean'];
    }

    public static function esObligatorio(?string $cargo): bool
    {
        return $cargo !== null
            && static::where('cargo', $cargo)->where('obligatorio', true)->exists();
    }
}
```

`backend/app/Models/Vehiculo.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehiculo extends Model
{
    use HasFactory;

    protected $table = 'vehiculos';

    protected $fillable = ['patente', 'marca', 'modelo', 'color', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
```

`backend/app/Models/Turno.php`:

```php
<?php

namespace App\Models;

use App\Enums\OrigenTurno;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Turno extends Model
{
    use HasFactory;

    protected $table = 'turnos';

    protected $fillable = ['chofer_id', 'vehiculo_id', 'inicio', 'fin', 'origen'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fin' => 'datetime', 'origen' => OrigenTurno::class];
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }
}
```

`backend/app/Models/UbicacionChofer.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UbicacionChofer extends Model
{
    protected $table = 'ubicaciones_chofer';

    protected $primaryKey = 'chofer_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['chofer_id', 'lat', 'lng', 'rumbo', 'velocidad', 'actualizado_en'];

    protected function casts(): array
    {
        return [
            'lat' => 'float', 'lng' => 'float', 'rumbo' => 'float',
            'velocidad' => 'float', 'actualizado_en' => 'datetime',
        ];
    }
}
```

`backend/app/Models/Viaje.php`:

```php
<?php

namespace App\Models;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\TipoViaje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Viaje extends Model
{
    use HasFactory;

    protected $table = 'viajes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoViaje::class,
            'modo' => ModoViaje::class,
            'estado' => EstadoViaje::class,
            'obligatorio' => 'boolean',
            'origen_lat' => 'float', 'origen_lng' => 'float',
            'destino_lat' => 'float', 'destino_lng' => 'float',
            'programado_para' => 'datetime',
            'aceptado_en' => 'datetime', 'llego_en' => 'datetime', 'iniciado_en' => 'datetime',
            'finalizado_en' => 'datetime', 'cancelado_en' => 'datetime',
        ];
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }

    public function vehiculo(): BelongsTo
    {
        return $this->belongsTo(Vehiculo::class);
    }

    public function ofertas(): HasMany
    {
        return $this->hasMany(OfertaViaje::class);
    }

    public function recorrido(): HasMany
    {
        return $this->hasMany(PuntoRecorrido::class);
    }

    /** Viajes que ocupan al chofer ahora (excluye reservas aceptadas todavía a futuro). */
    public function scopeActivosDeChofer(Builder $q, int $choferId): Builder
    {
        return $q->where('chofer_id', $choferId)
            ->whereIn('estado', EstadoViaje::conChofer())
            ->where(fn (Builder $w) => $w->whereNull('programado_para')->orWhere('programado_para', '<=', now()));
    }
}
```

`backend/app/Models/OfertaViaje.php`:

```php
<?php

namespace App\Models;

use App\Enums\ResultadoOferta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfertaViaje extends Model
{
    protected $table = 'ofertas_viaje';

    protected $fillable = ['viaje_id', 'chofer_id', 'resultado', 'ofrecido_en', 'vence_en', 'respondido_en', 'motivo'];

    protected function casts(): array
    {
        return [
            'resultado' => ResultadoOferta::class,
            'ofrecido_en' => 'datetime', 'vence_en' => 'datetime', 'respondido_en' => 'datetime',
        ];
    }

    public function viaje(): BelongsTo
    {
        return $this->belongsTo(Viaje::class);
    }

    public function chofer(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'chofer_id');
    }
}
```

`backend/app/Models/PuntoRecorrido.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PuntoRecorrido extends Model
{
    protected $table = 'recorrido_viaje';

    public $timestamps = false;

    protected $fillable = ['viaje_id', 'lat', 'lng', 'registrado_en'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'registrado_en' => 'datetime'];
    }
}
```

`backend/app/Models/Parametro.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Parametro extends Model
{
    protected $table = 'parametros';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor'];
}
```

Borrar `backend/app/Models/User.php` y `backend/database/factories/UserFactory.php`. En `backend/config/auth.php` cambiar:

```php
'model' => env('AUTH_MODEL', App\Models\Usuario::class),
```

- [ ] **Step 6: Crear factories**

`backend/database/factories/UsuarioFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\RolUsuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'id_externo' => (string) fake()->unique()->numberBetween(1000, 999999),
            'nombre' => fake()->name(),
            'cargo' => 'Empleado',
            'rol' => RolUsuario::Solicitante,
            'telefono' => fake()->phoneNumber(),
            'activo' => true,
        ];
    }

    public function chofer(): static
    {
        return $this->state(['rol' => RolUsuario::Chofer, 'cargo' => 'Chofer']);
    }

    public function admin(): static
    {
        return $this->state(['rol' => RolUsuario::Admin]);
    }
}
```

`backend/database/factories/VehiculoFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class VehiculoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patente' => strtoupper(fake()->unique()->bothify('??###??')),
            'marca' => 'Toyota',
            'modelo' => 'Corolla',
            'color' => 'Blanco',
            'activo' => true,
        ];
    }
}
```

`backend/database/factories/TurnoFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\OrigenTurno;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Database\Eloquent\Factories\Factory;

class TurnoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chofer_id' => Usuario::factory()->chofer(),
            'vehiculo_id' => Vehiculo::factory(),
            'inicio' => now()->subHours(2),
            'fin' => null,
            'origen' => OrigenTurno::Manual,
        ];
    }
}
```

`backend/database/factories/ViajeFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\TipoViaje;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class ViajeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'solicitante_id' => Usuario::factory(),
            'tipo' => TipoViaje::Inmediato,
            'modo' => ModoViaje::MasCercano,
            'obligatorio' => false,
            'origen_lat' => -34.6037, 'origen_lng' => -58.3816,
            'destino_lat' => -34.6090, 'destino_lng' => -58.3920,
            'motivo' => 'Traslado',
            'estado' => EstadoViaje::Buscando,
        ];
    }
}
```

- [ ] **Step 7: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 8: Commit**

```bash
git add backend
git commit -m "feat: modelo de datos (enums, migración, modelos, factories)" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Configuración y autenticación por intercambio de token

**Files:**
- Create: `backend/config/vehiculos.php`
- Create: `backend/app/Identidad/{ProveedorIdentidad,DatosIdentidad,IdentidadNoDisponible,IdentidadSimulada,EndpointPoderJudicial}.php`
- Create: `backend/app/Http/Controllers/AuthController.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php`
- Test: `backend/tests/Feature/AuthTest.php`, `backend/tests/Feature/EndpointPoderJudicialTest.php`

**Interfaces:**
- Consumes: `Usuario` (Task 2).
- Produces:
  - `ProveedorIdentidad::validar(string $tokenExterno): ?DatosIdentidad` (null = sesión inválida; lanza `IdentidadNoDisponible` si el servicio no responde).
  - `DatosIdentidad(string $idExterno, string $nombre, ?string $cargo, ?string $telefono)`.
  - Token simulado: `sim|<id_externo>|<nombre>|<cargo>`.
  - `POST /api/auth/intercambio {token_externo}` → `200 {token, usuario:{id,nombre,cargo,rol}}` | 401 | 403 | 503.
  - `GET /api/yo` (auth) → `{id,nombre,cargo,rol}`.
  - `config('vehiculos.*')`: `identidad`, `mapas`, `notificaciones`, `parametros`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/AuthTest.php`:

```php
<?php

use App\Identidad\DatosIdentidad;
use App\Identidad\IdentidadNoDisponible;
use App\Identidad\ProveedorIdentidad;
use App\Models\Usuario;

it('intercambia un token válido por un token propio y crea el usuario', function () {
    $r = $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|123|Ana Pérez|Juez']);

    $r->assertOk()
        ->assertJsonPath('usuario.nombre', 'Ana Pérez')
        ->assertJsonPath('usuario.cargo', 'Juez')
        ->assertJsonPath('usuario.rol', 'solicitante');
    expect($r->json('token'))->toBeString()->not->toBeEmpty()
        ->and(Usuario::where('id_externo', '123')->count())->toBe(1);
});

it('actualiza el cargo en logins posteriores sin duplicar el usuario', function () {
    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|123|Ana Pérez|Secretaria']);
    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|123|Ana Pérez|Juez'])->assertOk();

    expect(Usuario::where('id_externo', '123')->count())->toBe(1)
        ->and(Usuario::firstWhere('id_externo', '123')->cargo)->toBe('Juez');
});

it('conserva el rol asignado localmente', function () {
    Usuario::factory()->chofer()->create(['id_externo' => '77']);

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|77|Juan|Chofer'])
        ->assertJsonPath('usuario.rol', 'chofer');
});

it('rechaza un token inválido con 401', function () {
    $this->postJson('/api/auth/intercambio', ['token_externo' => 'cualquier-cosa'])->assertUnauthorized();
});

it('rechaza a un usuario desactivado con 403', function () {
    Usuario::factory()->create(['id_externo' => '9', 'activo' => false]);

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|9|X|Empleado'])->assertForbidden();
});

it('responde 503 si el servicio de identidad no está disponible', function () {
    $this->app->instance(ProveedorIdentidad::class, new class implements ProveedorIdentidad {
        public function validar(string $tokenExterno): ?DatosIdentidad
        {
            throw new IdentidadNoDisponible();
        }
    });

    $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|1|A|B'])->assertStatus(503);
});

it('devuelve el usuario autenticado en /yo', function () {
    $token = $this->postJson('/api/auth/intercambio', ['token_externo' => 'sim|5|Luis|Empleado'])->json('token');

    $this->withToken($token)->getJson('/api/yo')->assertOk()->assertJsonPath('nombre', 'Luis');
});
```

`backend/tests/Feature/EndpointPoderJudicialTest.php`:

```php
<?php

use App\Identidad\EndpointPoderJudicial;
use App\Identidad\IdentidadNoDisponible;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'vehiculos.identidad.url' => 'https://pj.test/api/sesion',
        'vehiculos.identidad.campos' => [
            'id_externo' => 'data.legajo', 'nombre' => 'data.nombre_completo',
            'cargo' => 'data.cargo', 'telefono' => 'data.telefono',
        ],
    ]);
});

it('mapea la respuesta del endpoint del PJ según los campos configurados', function () {
    Http::fake(['pj.test/*' => Http::response(['data' => [
        'legajo' => 4455, 'nombre_completo' => 'María Gómez', 'cargo' => 'Juez', 'telefono' => '381555',
    ]])]);

    $datos = (new EndpointPoderJudicial())->validar('tok');

    expect($datos->idExterno)->toBe('4455')
        ->and($datos->nombre)->toBe('María Gómez')
        ->and($datos->cargo)->toBe('Juez');
    Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer tok'));
});

it('devuelve null si el PJ responde 401', function () {
    Http::fake(['pj.test/*' => Http::response([], 401)]);

    expect((new EndpointPoderJudicial())->validar('tok'))->toBeNull();
});

it('lanza IdentidadNoDisponible si el PJ responde 500', function () {
    Http::fake(['pj.test/*' => Http::response([], 500)]);

    (new EndpointPoderJudicial())->validar('tok');
})->throws(IdentidadNoDisponible::class);
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/AuthTest.php tests/Feature/EndpointPoderJudicialTest.php`
Expected: FAIL (`404` en `/api/auth/intercambio` y `Class "App\Identidad\EndpointPoderJudicial" not found`).

- [ ] **Step 3: Crear la configuración**

`backend/config/vehiculos.php`:

```php
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
```

- [ ] **Step 4: Crear las clases de identidad**

`backend/app/Identidad/DatosIdentidad.php`:

```php
<?php

namespace App\Identidad;

final readonly class DatosIdentidad
{
    public function __construct(
        public string $idExterno,
        public string $nombre,
        public ?string $cargo,
        public ?string $telefono = null,
    ) {}
}
```

`backend/app/Identidad/ProveedorIdentidad.php`:

```php
<?php

namespace App\Identidad;

interface ProveedorIdentidad
{
    /**
     * Valida el token de sesión de la app del Poder Judicial.
     * Devuelve null si la sesión es inválida.
     *
     * @throws IdentidadNoDisponible si el servicio no responde.
     */
    public function validar(string $tokenExterno): ?DatosIdentidad;
}
```

`backend/app/Identidad/IdentidadNoDisponible.php`:

```php
<?php

namespace App\Identidad;

use RuntimeException;

class IdentidadNoDisponible extends RuntimeException {}
```

`backend/app/Identidad/IdentidadSimulada.php`:

```php
<?php

namespace App\Identidad;

/** Solo para desarrollo y tests. Token: "sim|<id_externo>|<nombre>|<cargo>". */
class IdentidadSimulada implements ProveedorIdentidad
{
    public function validar(string $tokenExterno): ?DatosIdentidad
    {
        $partes = explode('|', $tokenExterno);

        if (count($partes) !== 4 || $partes[0] !== 'sim' || $partes[1] === '') {
            return null;
        }

        return new DatosIdentidad($partes[1], $partes[2], $partes[3] !== '' ? $partes[3] : null);
    }
}
```

`backend/app/Identidad/EndpointPoderJudicial.php`:

```php
<?php

namespace App\Identidad;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EndpointPoderJudicial implements ProveedorIdentidad
{
    public function validar(string $tokenExterno): ?DatosIdentidad
    {
        try {
            $respuesta = Http::withToken($tokenExterno)
                ->acceptJson()
                ->timeout(config('vehiculos.identidad.timeout'))
                ->get(config('vehiculos.identidad.url'));
        } catch (ConnectionException $e) {
            throw new IdentidadNoDisponible('No se pudo contactar al servicio de identidad.', previous: $e);
        }

        if (in_array($respuesta->status(), [401, 403], true)) {
            return null;
        }

        if ($respuesta->failed()) {
            throw new IdentidadNoDisponible("El servicio de identidad respondió {$respuesta->status()}.");
        }

        $json = $respuesta->json();
        $campos = config('vehiculos.identidad.campos');
        $id = data_get($json, $campos['id_externo']);

        if ($id === null || $id === '') {
            return null;
        }

        return new DatosIdentidad(
            (string) $id,
            (string) data_get($json, $campos['nombre'], ''),
            data_get($json, $campos['cargo']),
            data_get($json, $campos['telefono']),
        );
    }
}
```

- [ ] **Step 5: Registrar el proveedor**

En `backend/app/Providers/AppServiceProvider.php`, método `register()`:

```php
$this->app->bind(\App\Identidad\ProveedorIdentidad::class, function ($app) {
    $driver = config('vehiculos.identidad.driver');

    if ($driver === 'simulada' && $app->isProduction()) {
        throw new \LogicException('IDENTIDAD_DRIVER=simulada no está permitido en producción.');
    }

    return match ($driver) {
        'poder_judicial' => new \App\Identidad\EndpointPoderJudicial(),
        default => new \App\Identidad\IdentidadSimulada(),
    };
});
```

- [ ] **Step 6: Crear el controlador y las rutas**

`backend/app/Http/Controllers/AuthController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Identidad\IdentidadNoDisponible;
use App\Identidad\ProveedorIdentidad;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function intercambio(Request $request, ProveedorIdentidad $identidad): JsonResponse
    {
        $datos = $request->validate(['token_externo' => ['required', 'string']]);

        try {
            $id = $identidad->validar($datos['token_externo']);
        } catch (IdentidadNoDisponible) {
            return response()->json(['message' => 'Servicio de identidad no disponible.'], 503);
        }

        if ($id === null) {
            return response()->json(['message' => 'Sesión inválida.'], 401);
        }

        $usuario = Usuario::updateOrCreate(
            ['id_externo' => $id->idExterno],
            array_filter(['nombre' => $id->nombre, 'cargo' => $id->cargo, 'telefono' => $id->telefono],
                fn ($v) => $v !== null),
        )->refresh();

        if (! $usuario->activo) {
            return response()->json(['message' => 'Usuario deshabilitado.'], 403);
        }

        return response()->json([
            'token' => $usuario->createToken('app')->plainTextToken,
            'usuario' => self::datosUsuario($usuario),
        ]);
    }

    public function yo(Request $request): JsonResponse
    {
        return response()->json(self::datosUsuario($request->user()));
    }

    public static function datosUsuario(Usuario $u): array
    {
        return ['id' => $u->id, 'nombre' => $u->nombre, 'cargo' => $u->cargo, 'rol' => $u->rol->value];
    }
}
```

Reemplazar el contenido de `backend/routes/api.php` por:

```php
<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/intercambio', [AuthController::class, 'intercambio']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/yo', [AuthController::class, 'yo']);
});
```

- [ ] **Step 7: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 8: Commit**

```bash
git add backend
git commit -m "feat: autenticación por intercambio de token del PJ" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Parámetros, excepciones de negocio y control por rol

**Files:**
- Create: `backend/app/Servicios/Parametros.php`
- Create: `backend/app/Excepciones/{ReglaNegocio,AccionNoPermitida}.php`
- Create: `backend/app/Http/Middleware/AsegurarRol.php`
- Create: `backend/app/Http/Controllers/ConfiguracionController.php`
- Modify: `backend/bootstrap/app.php`, `backend/routes/api.php`
- Test: `backend/tests/Feature/ParametrosTest.php`, `backend/tests/Feature/RolTest.php`

**Interfaces:**
- Consumes: `Parametro`, `config('vehiculos.parametros')` (Tasks 2–3).
- Produces:
  - `Parametros::entero(string $clave): int` (valor en tabla `parametros` o, si no hay, el default de config).
  - `ReglaNegocio` (→ HTTP 422 `{message}`), `AccionNoPermitida` (→ HTTP 403 `{message}`).
  - Middleware alias `rol`: `->middleware('rol:chofer')`, admite varios: `rol:chofer,admin`.
  - `GET /api/configuracion` (auth) → `{gps_turno_seg, gps_viaje_seg, oferta_segundos}`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/ParametrosTest.php`:

```php
<?php

use App\Models\Parametro;
use App\Models\Usuario;
use App\Servicios\Parametros;

it('usa el valor por defecto de config', function () {
    expect(app(Parametros::class)->entero('oferta_segundos'))->toBe(30);
});

it('prioriza el valor guardado en la tabla', function () {
    Parametro::create(['clave' => 'oferta_segundos', 'valor' => '45']);

    expect(app(Parametros::class)->entero('oferta_segundos'))->toBe(45);
});

it('falla ante un parámetro desconocido', function () {
    app(Parametros::class)->entero('no_existe');
})->throws(InvalidArgumentException::class);

it('expone la configuración que necesita la app', function () {
    $this->actingAs(Usuario::factory()->create())
        ->getJson('/api/configuracion')
        ->assertOk()
        ->assertExactJson(['gps_turno_seg' => 10, 'gps_viaje_seg' => 5, 'oferta_segundos' => 30]);
});
```

`backend/tests/Feature/RolTest.php`:

```php
<?php

use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\Usuario;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'rol:chofer'])->get('/api/_solo-chofer', fn () => 'ok');
    Route::middleware('api')->get('/api/_regla', fn () => throw new ReglaNegocio('No se puede.'));
    Route::middleware('api')->get('/api/_prohibido', fn () => throw new AccionNoPermitida('Prohibido.'));
});

it('deja pasar a un chofer', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())->getJson('/api/_solo-chofer')->assertOk();
});

it('rechaza a un solicitante con 403', function () {
    $this->actingAs(Usuario::factory()->create())->getJson('/api/_solo-chofer')->assertForbidden();
});

it('convierte ReglaNegocio en 422 con mensaje', function () {
    $this->getJson('/api/_regla')->assertStatus(422)->assertJsonPath('message', 'No se puede.');
});

it('convierte AccionNoPermitida en 403 con mensaje', function () {
    $this->getJson('/api/_prohibido')->assertForbidden()->assertJsonPath('message', 'Prohibido.');
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/ParametrosTest.php tests/Feature/RolTest.php`
Expected: FAIL (`Class "App\Servicios\Parametros" not found`).

- [ ] **Step 3: Implementar**

`backend/app/Servicios/Parametros.php`:

```php
<?php

namespace App\Servicios;

use App\Models\Parametro;
use InvalidArgumentException;

class Parametros
{
    public function entero(string $clave): int
    {
        $valor = Parametro::find($clave)?->valor ?? config("vehiculos.parametros.$clave");

        if ($valor === null) {
            throw new InvalidArgumentException("Parámetro desconocido: $clave");
        }

        return (int) $valor;
    }
}
```

`backend/app/Excepciones/ReglaNegocio.php`:

```php
<?php

namespace App\Excepciones;

use Exception;
use Illuminate\Http\JsonResponse;

/** Operación válida en forma pero no permitida por el estado actual (HTTP 422). */
class ReglaNegocio extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
```

`backend/app/Excepciones/AccionNoPermitida.php`:

```php
<?php

namespace App\Excepciones;

use Exception;
use Illuminate\Http\JsonResponse;

/** El usuario no tiene permitido realizar la acción (HTTP 403). */
class AccionNoPermitida extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}
```

`backend/app/Http/Middleware/AsegurarRol.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AsegurarRol
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->rol?->value, $roles, true)) {
            return response()->json(['message' => 'No tenés permiso para esta acción.'], 403);
        }

        return $next($request);
    }
}
```

En `backend/bootstrap/app.php`, dentro de `->withMiddleware(function (Middleware $middleware) { ... })`:

```php
$middleware->alias(['rol' => \App\Http\Middleware\AsegurarRol::class]);
```

`backend/app/Http/Controllers/ConfiguracionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Servicios\Parametros;
use Illuminate\Http\JsonResponse;

class ConfiguracionController extends Controller
{
    public function __invoke(Parametros $p): JsonResponse
    {
        return response()->json([
            'gps_turno_seg' => $p->entero('gps_turno_seg'),
            'gps_viaje_seg' => $p->entero('gps_viaje_seg'),
            'oferta_segundos' => $p->entero('oferta_segundos'),
        ]);
    }
}
```

En `backend/routes/api.php`, dentro del grupo `auth:sanctum`, agregar:

```php
Route::get('/configuracion', \App\Http\Controllers\ConfiguracionController::class);
```

- [ ] **Step 4: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "feat: parámetros configurables, excepciones de negocio y middleware de rol" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Turnos del chofer

El "origen" del turno (`OrigenTurno`) es la costura para la futura integración con asistencia: `ServicioTurnos` no sabe quién lo invoca; hoy lo llama el controlador (manual), mañana lo llamará la integración de asistencia con `OrigenTurno::Asistencia`.

**Files:**
- Create: `backend/app/Servicios/ServicioTurnos.php`
- Create: `backend/app/Http/Controllers/TurnoController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/TurnosTest.php`

**Interfaces:**
- Consumes: `Usuario`, `Vehiculo`, `Turno`, `UbicacionChofer`, `Viaje::activosDeChofer` (Task 2); `ReglaNegocio`, `AccionNoPermitida`, `rol` (Task 4).
- Produces:
  - `ServicioTurnos::iniciar(Usuario $chofer, int $vehiculoId, OrigenTurno $origen = OrigenTurno::Manual): Turno`
  - `ServicioTurnos::finalizar(Usuario $chofer): Turno` (borra la fila de `ubicaciones_chofer`)
  - `ServicioTurnos::vehiculosDisponibles(): Collection<Vehiculo>`
  - Rutas (auth + `rol:chofer`): `GET /api/vehiculos/disponibles`, `GET /api/turnos/actual`, `POST /api/turnos {vehiculo_id}` (201), `POST /api/turnos/actual/finalizar`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/TurnosTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;

it('inicia un turno con un vehículo libre', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $vehiculo = Vehiculo::factory()->create();

    $this->actingAs($chofer)->postJson('/api/turnos', ['vehiculo_id' => $vehiculo->id])
        ->assertCreated()
        ->assertJsonPath('vehiculo.patente', $vehiculo->patente);

    expect($chofer->turnoAbierto)->not->toBeNull();
});

it('no permite dos turnos abiertos para el mismo chofer', function () {
    $turno = Turno::factory()->create();

    $this->actingAs($turno->chofer)
        ->postJson('/api/turnos', ['vehiculo_id' => Vehiculo::factory()->create()->id])
        ->assertStatus(422);
});

it('no permite usar un vehículo que está en otro turno abierto', function () {
    $turno = Turno::factory()->create();

    $this->actingAs(Usuario::factory()->chofer()->create())
        ->postJson('/api/turnos', ['vehiculo_id' => $turno->vehiculo_id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'El vehículo está en uso por otro chofer.');
});

it('no permite usar un vehículo inactivo', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())
        ->postJson('/api/turnos', ['vehiculo_id' => Vehiculo::factory()->create(['activo' => false])->id])
        ->assertStatus(422);
});

it('rechaza a un solicitante', function () {
    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/turnos', ['vehiculo_id' => Vehiculo::factory()->create()->id])
        ->assertForbidden();
});

it('lista solo vehículos activos y libres', function () {
    $libre = Vehiculo::factory()->create(['patente' => 'AA111AA']);
    Vehiculo::factory()->create(['activo' => false]);
    Turno::factory()->create();

    $this->actingAs(Usuario::factory()->chofer()->create())
        ->getJson('/api/vehiculos/disponibles')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $libre->id);
});

it('finaliza el turno y borra la última ubicación', function () {
    $turno = Turno::factory()->create();
    UbicacionChofer::create(['chofer_id' => $turno->chofer_id, 'lat' => -34.6, 'lng' => -58.4, 'actualizado_en' => now()]);

    $this->actingAs($turno->chofer)->postJson('/api/turnos/actual/finalizar')->assertOk();

    expect($turno->fresh()->fin)->not->toBeNull()
        ->and(UbicacionChofer::find($turno->chofer_id))->toBeNull();
});

it('no finaliza el turno con un viaje activo', function () {
    $turno = Turno::factory()->create();
    Viaje::factory()->create(['chofer_id' => $turno->chofer_id, 'estado' => EstadoViaje::EnCurso]);

    $this->actingAs($turno->chofer)->postJson('/api/turnos/actual/finalizar')->assertStatus(422);
});

it('devuelve el turno actual o null', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $this->actingAs($chofer)->getJson('/api/turnos/actual')->assertOk()->assertExactJson(['turno' => null]);

    $turno = Turno::factory()->for($chofer, 'chofer')->create();
    $this->actingAs($chofer)->getJson('/api/turnos/actual')->assertJsonPath('turno.id', $turno->id);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/TurnosTest.php`
Expected: FAIL (404 en las rutas).

- [ ] **Step 3: Implementar el servicio**

`backend/app/Servicios/ServicioTurnos.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\OrigenTurno;
use App\Excepciones\AccionNoPermitida;
use App\Excepciones\ReglaNegocio;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Models\Viaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioTurnos
{
    public function iniciar(Usuario $chofer, int $vehiculoId, OrigenTurno $origen = OrigenTurno::Manual): Turno
    {
        if (! $chofer->esChofer()) {
            throw new AccionNoPermitida('Solo los choferes pueden iniciar turno.');
        }

        return DB::transaction(function () use ($chofer, $vehiculoId, $origen) {
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();
            $vehiculo = Vehiculo::whereKey($vehiculoId)->lockForUpdate()->first();

            if (! $vehiculo || ! $vehiculo->activo) {
                throw new ReglaNegocio('El vehículo no existe o no está activo.');
            }
            if (Turno::where('chofer_id', $chofer->id)->whereNull('fin')->exists()) {
                throw new ReglaNegocio('Ya tenés un turno abierto.');
            }
            if (Turno::where('vehiculo_id', $vehiculo->id)->whereNull('fin')->exists()) {
                throw new ReglaNegocio('El vehículo está en uso por otro chofer.');
            }

            return Turno::create([
                'chofer_id' => $chofer->id,
                'vehiculo_id' => $vehiculo->id,
                'inicio' => now(),
                'origen' => $origen,
            ]);
        });
    }

    public function finalizar(Usuario $chofer): Turno
    {
        $turno = $chofer->turnoAbierto()->first()
            ?? throw new ReglaNegocio('No tenés un turno abierto.');

        if (Viaje::activosDeChofer($chofer->id)->exists()) {
            throw new ReglaNegocio('Finalizá el viaje en curso antes de cerrar el turno.');
        }

        $turno->update(['fin' => now()]);
        // Privacidad: fuera de turno no se conserva la ubicación.
        UbicacionChofer::where('chofer_id', $chofer->id)->delete();

        return $turno;
    }

    /** @return Collection<int, Vehiculo> */
    public function vehiculosDisponibles(): Collection
    {
        return Vehiculo::where('activo', true)
            ->whereNotIn('id', Turno::whereNull('fin')->select('vehiculo_id'))
            ->orderBy('patente')
            ->get();
    }
}
```

- [ ] **Step 4: Implementar controlador y rutas**

`backend/app/Http/Controllers/TurnoController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Servicios\ServicioTurnos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TurnoController extends Controller
{
    public function __construct(private ServicioTurnos $turnos) {}

    public function vehiculosDisponibles(): JsonResponse
    {
        return response()->json($this->turnos->vehiculosDisponibles());
    }

    public function actual(Request $request): JsonResponse
    {
        return response()->json(['turno' => $request->user()->turnoAbierto()->with('vehiculo')->first()]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        $datos = $request->validate(['vehiculo_id' => ['required', 'integer']]);

        $turno = $this->turnos->iniciar($request->user(), $datos['vehiculo_id']);

        return response()->json($turno->load('vehiculo'), 201);
    }

    public function finalizar(Request $request): JsonResponse
    {
        return response()->json($this->turnos->finalizar($request->user()));
    }
}
```

En `backend/routes/api.php`, dentro del grupo `auth:sanctum`, agregar:

```php
Route::middleware('rol:chofer')->group(function () {
    Route::get('/vehiculos/disponibles', [\App\Http\Controllers\TurnoController::class, 'vehiculosDisponibles']);
    Route::get('/turnos/actual', [\App\Http\Controllers\TurnoController::class, 'actual']);
    Route::post('/turnos', [\App\Http\Controllers\TurnoController::class, 'iniciar']);
    Route::post('/turnos/actual/finalizar', [\App\Http\Controllers\TurnoController::class, 'finalizar']);
});
```

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: inicio y fin de turno del chofer" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Ubicación del chofer y estado calculado

**Files:**
- Create: `backend/app/Enums/EstadoChofer.php`
- Create: `backend/app/Servicios/CalculadorEstadoChofer.php`
- Create: `backend/app/Servicios/ServicioUbicacion.php`
- Create: `backend/app/Http/Controllers/UbicacionController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/EstadoChoferTest.php`, `backend/tests/Feature/UbicacionTest.php`

**Interfaces:**
- Consumes: modelos (Task 2), `Parametros`, `ReglaNegocio`, `rol` (Task 4).
- Produces:
  - `enum EstadoChofer: string { FueraDeTurno='fuera_de_turno', SinSenal='sin_senal', EnViaje='en_viaje', ReservadoPronto='reservado_pronto', Libre='libre' }`
  - `CalculadorEstadoChofer::estado(Usuario $chofer): EstadoChofer` (precedencia en el orden del spec 4.1: fuera de turno → sin señal → en viaje → reservado pronto → libre).
  - `CalculadorEstadoChofer::choferesEnTurno(): Collection<array{chofer: Usuario, estado: EstadoChofer}>` (con `ubicacion` y `turnoAbierto.vehiculo` cargados).
  - `CalculadorEstadoChofer::libres(): Collection<Usuario>`.
  - `ServicioUbicacion::registrar(Usuario $chofer, array $puntos): void`; cada punto `{lat, lng, rumbo?, velocidad?, registrado_en}`.
  - `POST /api/ubicacion {puntos: [...]}` (auth + `rol:chofer`) → 204.

- [ ] **Step 1: Agregar helper compartido de tests**

Al final de `backend/tests/Pest.php` (lo usan también Tasks 8–11 y 13):

```php
/** Crea un chofer con turno abierto y ubicación reportada hace $minutos minutos. */
function choferEnTurno(float $lat = -34.60, float $lng = -58.38, int $minutos = 0): App\Models\Usuario
{
    $turno = App\Models\Turno::factory()->create();
    App\Models\UbicacionChofer::create([
        'chofer_id' => $turno->chofer_id, 'lat' => $lat, 'lng' => $lng,
        'actualizado_en' => now()->subMinutes($minutos),
    ]);

    return $turno->chofer;
}
```

- [ ] **Step 2: Escribir tests que fallan**

`backend/tests/Feature/EstadoChoferTest.php`:

```php
<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\TipoViaje;
use App\Models\Turno;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;

it('está fuera de turno sin turno abierto', function () {
    $chofer = Usuario::factory()->chofer()->create();

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::FueraDeTurno);
});

it('está libre con turno, señal reciente y sin viajes', function () {
    expect(app(CalculadorEstadoChofer::class)->estado(choferEnTurno()))->toBe(EstadoChofer::Libre);
});

it('queda sin señal si la última ubicación tiene más de 2 minutos y no figura entre los libres', function () {
    $chofer = choferEnTurno(minutos: 3);
    $calc = app(CalculadorEstadoChofer::class);

    expect($calc->estado($chofer))->toBe(EstadoChofer::SinSenal)
        ->and($calc->libres()->pluck('id')->all())->not->toContain($chofer->id);
});

it('está sin señal si nunca envió ubicación', function () {
    $turno = Turno::factory()->create();

    expect(app(CalculadorEstadoChofer::class)->estado($turno->chofer))->toBe(EstadoChofer::SinSenal);
});

it('está en viaje con un viaje activo', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Llego]);

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::EnViaje);
});

it('está reservado pronto con una reserva en los próximos 45 minutos', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado,
        'tipo' => TipoViaje::Reserva, 'programado_para' => now()->addMinutes(30),
    ]);

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::ReservadoPronto);
});

it('sigue libre con una reserva dentro de 3 horas', function () {
    $chofer = choferEnTurno();
    Viaje::factory()->create([
        'chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado,
        'tipo' => TipoViaje::Reserva, 'programado_para' => now()->addHours(3),
    ]);

    expect(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::Libre);
});

it('lista choferes en turno con su estado', function () {
    $libre = choferEnTurno();
    $ocupado = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);
    Usuario::factory()->chofer()->create();

    $mapa = app(CalculadorEstadoChofer::class)->choferesEnTurno()
        ->mapWithKeys(fn ($f) => [$f['chofer']->id => $f['estado']]);

    expect($mapa->all())->toBe([$libre->id => EstadoChofer::Libre, $ocupado->id => EstadoChofer::EnViaje]);
});
```

`backend/tests/Feature/UbicacionTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;

it('guarda la última ubicación de un lote', function () {
    $turno = Turno::factory()->create();

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => -34.61, 'lng' => -58.39, 'registrado_en' => now()->subSeconds(20)->toIso8601String()],
        ['lat' => -34.60, 'lng' => -58.38, 'rumbo' => 90, 'registrado_en' => now()->subSeconds(5)->toIso8601String()],
    ]])->assertNoContent();

    $u = UbicacionChofer::find($turno->chofer_id);
    expect($u->lat)->toBe(-34.60)->and($u->rumbo)->toBe(90.0);
});

it('ignora un lote más viejo que la ubicación guardada', function () {
    $turno = Turno::factory()->create();
    UbicacionChofer::create(['chofer_id' => $turno->chofer_id, 'lat' => 1, 'lng' => 1, 'actualizado_en' => now()]);

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => 2, 'lng' => 2, 'registrado_en' => now()->subMinute()->toIso8601String()],
    ]])->assertNoContent();

    expect(UbicacionChofer::find($turno->chofer_id)->lat)->toBe(1.0);
});

it('no acepta ubicación sin turno abierto', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => 2, 'lng' => 2, 'registrado_en' => now()->toIso8601String()],
    ]])->assertStatus(422);

    expect(UbicacionChofer::count())->toBe(0);
});

it('registra el recorrido durante un viaje en curso', function () {
    $turno = Turno::factory()->create();
    $viaje = Viaje::factory()->create([
        'chofer_id' => $turno->chofer_id, 'estado' => EstadoViaje::EnCurso, 'iniciado_en' => now()->subMinutes(5),
    ]);

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => -34.6, 'lng' => -58.3, 'registrado_en' => now()->subMinutes(10)->toIso8601String()],
        ['lat' => -34.7, 'lng' => -58.4, 'registrado_en' => now()->subMinute()->toIso8601String()],
    ]])->assertNoContent();

    expect(PuntoRecorrido::where('viaje_id', $viaje->id)->count())->toBe(1);
});

it('valida coordenadas', function () {
    $turno = Turno::factory()->create();

    $this->actingAs($turno->chofer)->postJson('/api/ubicacion', ['puntos' => [
        ['lat' => 120, 'lng' => 2, 'registrado_en' => now()->toIso8601String()],
    ]])->assertStatus(422)->assertJsonValidationErrors('puntos.0.lat');
});
```

- [ ] **Step 3: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/EstadoChoferTest.php tests/Feature/UbicacionTest.php`
Expected: FAIL (`Class "App\Enums\EstadoChofer" not found`).

- [ ] **Step 4: Implementar enum y calculador**

`backend/app/Enums/EstadoChofer.php`:

```php
<?php

namespace App\Enums;

enum EstadoChofer: string
{
    case FueraDeTurno = 'fuera_de_turno';
    case SinSenal = 'sin_senal';
    case EnViaje = 'en_viaje';
    case ReservadoPronto = 'reservado_pronto';
    case Libre = 'libre';
}
```

`backend/app/Servicios/CalculadorEstadoChofer.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;

/** El estado del chofer se calcula siempre; nunca se guarda (spec 4.1). */
class CalculadorEstadoChofer
{
    public function __construct(private Parametros $parametros) {}

    public function estado(Usuario $chofer): EstadoChofer
    {
        if (! $chofer->turnoAbierto()->exists()) {
            return EstadoChofer::FueraDeTurno;
        }

        $ubicacion = $chofer->ubicacion()->first();
        $limiteSenal = now()->subMinutes($this->parametros->entero('sin_senal_min'));
        if (! $ubicacion || $ubicacion->actualizado_en->lt($limiteSenal)) {
            return EstadoChofer::SinSenal;
        }

        if (Viaje::activosDeChofer($chofer->id)->exists()) {
            return EstadoChofer::EnViaje;
        }

        if ($this->tieneReservaProxima($chofer->id)) {
            return EstadoChofer::ReservadoPronto;
        }

        return EstadoChofer::Libre;
    }

    /** @return Collection<int, array{chofer: Usuario, estado: EstadoChofer}> */
    public function choferesEnTurno(): Collection
    {
        return Usuario::where('rol', RolUsuario::Chofer)
            ->whereHas('turnoAbierto')
            ->with(['ubicacion', 'turnoAbierto.vehiculo'])
            ->orderBy('id')
            ->get()
            ->map(fn (Usuario $c) => ['chofer' => $c, 'estado' => $this->estado($c)]);
    }

    /** @return Collection<int, Usuario> */
    public function libres(): Collection
    {
        return $this->choferesEnTurno()
            ->filter(fn (array $f) => $f['estado'] === EstadoChofer::Libre)
            ->map(fn (array $f) => $f['chofer'])
            ->values();
    }

    private function tieneReservaProxima(int $choferId): bool
    {
        return Viaje::where('chofer_id', $choferId)
            ->where('tipo', TipoViaje::Reserva)
            ->where('estado', EstadoViaje::Aceptado)
            ->whereBetween('programado_para', [
                now(), now()->addMinutes($this->parametros->entero('bloqueo_antes_reserva_min')),
            ])
            ->exists();
    }
}
```

- [ ] **Step 5: Implementar servicio de ubicación, controlador y ruta**

`backend/app/Servicios/ServicioUbicacion.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoViaje;
use App\Excepciones\ReglaNegocio;
use App\Models\PuntoRecorrido;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Carbon;

class ServicioUbicacion
{
    /** @param array<int, array{lat: float, lng: float, rumbo?: ?float, velocidad?: ?float, registrado_en: string}> $puntos */
    public function registrar(Usuario $chofer, array $puntos): void
    {
        if (! $chofer->turnoAbierto()->exists()) {
            throw new ReglaNegocio('Iniciá un turno para compartir tu ubicación.');
        }

        $puntos = collect($puntos)
            ->map(fn (array $p) => [...$p, 'momento' => Carbon::parse($p['registrado_en'])->min(now())])
            ->sortBy('momento')
            ->values();
        $ultimo = $puntos->last();

        $actual = UbicacionChofer::find($chofer->id);
        if (! $actual || $actual->actualizado_en->lte($ultimo['momento'])) {
            UbicacionChofer::updateOrCreate(['chofer_id' => $chofer->id], [
                'lat' => $ultimo['lat'],
                'lng' => $ultimo['lng'],
                'rumbo' => $ultimo['rumbo'] ?? null,
                'velocidad' => $ultimo['velocidad'] ?? null,
                'actualizado_en' => $ultimo['momento'],
            ]);
        }

        $enCurso = Viaje::where('chofer_id', $chofer->id)->where('estado', EstadoViaje::EnCurso)->first();
        if ($enCurso) {
            $puntos->filter(fn ($p) => $p['momento']->gte($enCurso->iniciado_en))
                ->each(fn ($p) => PuntoRecorrido::create([
                    'viaje_id' => $enCurso->id, 'lat' => $p['lat'], 'lng' => $p['lng'], 'registrado_en' => $p['momento'],
                ]));
        }
    }
}
```

`backend/app/Http/Controllers/UbicacionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Servicios\ServicioUbicacion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UbicacionController extends Controller
{
    public function __invoke(Request $request, ServicioUbicacion $ubicacion): Response
    {
        $datos = $request->validate([
            'puntos' => ['required', 'array', 'min:1', 'max:500'],
            'puntos.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'puntos.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'puntos.*.rumbo' => ['nullable', 'numeric', 'between:0,360'],
            'puntos.*.velocidad' => ['nullable', 'numeric', 'min:0'],
            'puntos.*.registrado_en' => ['required', 'date'],
        ]);

        $ubicacion->registrar($request->user(), $datos['puntos']);

        return response()->noContent();
    }
}
```

En `backend/routes/api.php`, dentro del grupo `rol:chofer`, agregar:

```php
Route::post('/ubicacion', \App\Http\Controllers\UbicacionController::class);
```

- [ ] **Step 6: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 7: Commit**

```bash
git add backend
git commit -m "feat: ubicación del chofer y estado calculado" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Máquina de estados del viaje

**Files:**
- Create: `backend/app/Excepciones/TransicionInvalida.php`
- Create: `backend/app/Servicios/MaquinaEstadosViaje.php`
- Test: `backend/tests/Feature/MaquinaEstadosViajeTest.php`

**Interfaces:**
- Consumes: `Viaje`, `EstadoViaje` (Task 2), `ReglaNegocio` (Task 4).
- Produces:
  - `MaquinaEstadosViaje::puede(EstadoViaje $desde, EstadoViaje $hacia): bool`
  - `MaquinaEstadosViaje::transicionar(Viaje $viaje, EstadoViaje $hacia, array $atributos = []): bool`. Devuelve `false` (sin guardar) si el viaje ya está en `$hacia`; lanza `TransicionInvalida` (422) si no está permitida; si no, aplica `$atributos`, el estado y la marca de tiempo, guarda y devuelve `true`.
  - `TransicionInvalida extends ReglaNegocio`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/MaquinaEstadosViajeTest.php`:

```php
<?php

use App\Enums\EstadoViaje as E;
use App\Excepciones\TransicionInvalida;
use App\Models\Viaje;
use App\Servicios\MaquinaEstadosViaje;

it('permite las transiciones del flujo', function (E $desde, E $hacia) {
    expect(app(MaquinaEstadosViaje::class)->puede($desde, $hacia))->toBeTrue();
})->with([
    [E::Buscando, E::Ofrecido], [E::Buscando, E::Aceptado], [E::Buscando, E::SinChofer],
    [E::Ofrecido, E::Buscando], [E::Ofrecido, E::Aceptado], [E::Ofrecido, E::SinChofer],
    [E::Aceptado, E::EnCamino], [E::EnCamino, E::Llego], [E::Llego, E::EnCurso],
    [E::EnCurso, E::Finalizado], [E::Aceptado, E::Buscando], [E::Llego, E::Cancelado],
]);

it('prohíbe transiciones fuera del flujo', function (E $desde, E $hacia) {
    expect(app(MaquinaEstadosViaje::class)->puede($desde, $hacia))->toBeFalse();
})->with([
    [E::Buscando, E::EnCurso], [E::EnCurso, E::Cancelado], [E::Finalizado, E::Buscando],
    [E::Cancelado, E::Aceptado], [E::SinChofer, E::Aceptado], [E::Aceptado, E::Finalizado],
]);

it('aplica estado, atributos y marca de tiempo', function () {
    $viaje = Viaje::factory()->create();

    $cambio = app(MaquinaEstadosViaje::class)->transicionar($viaje, E::Aceptado, ['motivo' => 'Otro']);

    $fresco = $viaje->fresh();
    expect($cambio)->toBeTrue()
        ->and($fresco->estado)->toBe(E::Aceptado)
        ->and($fresco->motivo)->toBe('Otro')
        ->and($fresco->aceptado_en)->not->toBeNull();
});

it('es idempotente si el viaje ya está en el estado destino', function () {
    $viaje = Viaje::factory()->create(['estado' => E::Finalizado, 'finalizado_en' => now()->subHour()]);

    expect(app(MaquinaEstadosViaje::class)->transicionar($viaje, E::Finalizado))->toBeFalse()
        ->and($viaje->fresh()->finalizado_en->lt(now()->subMinutes(30)))->toBeTrue();
});

it('rechaza una transición inválida sin modificar el viaje', function () {
    $viaje = Viaje::factory()->create(['estado' => E::EnCurso]);

    expect(fn () => app(MaquinaEstadosViaje::class)->transicionar($viaje, E::Cancelado))
        ->toThrow(TransicionInvalida::class);
    expect($viaje->fresh()->estado)->toBe(E::EnCurso);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/MaquinaEstadosViajeTest.php`
Expected: FAIL (`Class "App\Servicios\MaquinaEstadosViaje" not found`).

- [ ] **Step 3: Implementar**

`backend/app/Excepciones/TransicionInvalida.php`:

```php
<?php

namespace App\Excepciones;

class TransicionInvalida extends ReglaNegocio {}
```

`backend/app/Servicios/MaquinaEstadosViaje.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoViaje as E;
use App\Excepciones\TransicionInvalida;
use App\Models\Viaje;

/** Única puerta para cambiar el estado de un viaje (spec 5.1). */
class MaquinaEstadosViaje
{
    private const PERMITIDAS = [
        'buscando' => [E::Ofrecido, E::Aceptado, E::SinChofer, E::Cancelado],
        'ofrecido' => [E::Buscando, E::Aceptado, E::SinChofer, E::Cancelado],
        'aceptado' => [E::EnCamino, E::Buscando, E::Cancelado],
        'en_camino' => [E::Llego, E::Buscando, E::Cancelado],
        'llego' => [E::EnCurso, E::Buscando, E::Cancelado],
        'en_curso' => [E::Finalizado],
    ];

    private const MARCAS = [
        'aceptado' => 'aceptado_en',
        'llego' => 'llego_en',
        'en_curso' => 'iniciado_en',
        'finalizado' => 'finalizado_en',
        'cancelado' => 'cancelado_en',
    ];

    public function puede(E $desde, E $hacia): bool
    {
        return in_array($hacia, self::PERMITIDAS[$desde->value] ?? [], true);
    }

    public function transicionar(Viaje $viaje, E $hacia, array $atributos = []): bool
    {
        if ($viaje->estado === $hacia) {
            return false;
        }

        if (! $this->puede($viaje->estado, $hacia)) {
            throw new TransicionInvalida("El viaje no puede pasar de {$viaje->estado->value} a {$hacia->value}.");
        }

        $viaje->fill($atributos);
        $viaje->estado = $hacia;
        if ($marca = self::MARCAS[$hacia->value] ?? null) {
            $viaje->{$marca} = now();
        }
        $viaje->save();

        return true;
    }
}
```

- [ ] **Step 4: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 5: Commit**

```bash
git add backend
git commit -m "feat: máquina de estados del viaje" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Servicio de mapas y asignador

**Files:**
- Create: `backend/app/Mapas/{Distancia,ServicioMapas,ServicioMapasFalso,GoogleMaps}.php`
- Create: `backend/app/Servicios/Asignador.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`
- Test: `backend/tests/Feature/GoogleMapsTest.php`, `backend/tests/Feature/AsignadorTest.php`

**Interfaces:**
- Consumes: `CalculadorEstadoChofer`, `EstadoChofer` (Task 6); `MaquinaEstadosViaje` (Task 7); `Parametros` (Task 4); helper `choferEnTurno()` (Task 6).
- Produces:
  - `Distancia::metros(float $lat1, float $lng1, float $lat2, float $lng2): float`
  - `ServicioMapas::duracionesHacia(array $origenes, float $lat, float $lng): array` — `$origenes` es `[clave => [lat, lng]]`; devuelve `[clave => ?int segundos]` (null si no hay dato; nunca lanza).
  - `ServicioMapas::duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int` (la usará el plan de Reservas).
  - `Asignador::ordenarPorCercania(Viaje $viaje, Collection $candidatos): Collection<Usuario>` — candidatos con `ubicacion` cargada; los N más cercanos en línea recta se reordenan por tiempo de llegada.
  - `Asignador::asignar(Viaje $viaje, Usuario $chofer): bool` — transacción con lock; `false` si el viaje ya no está en `buscando`/`ofrecido` o el chofer ya no está `Libre`. Al asignar: `chofer_id`, `vehiculo_id` (del turno) y estado `aceptado`. Refresca `$viaje`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/GoogleMapsTest.php`:

```php
<?php

use App\Mapas\GoogleMaps;
use Illuminate\Support\Facades\Http;

it('devuelve duraciones por clave usando el tráfico si está disponible', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK',
        'rows' => [
            ['elements' => [['status' => 'OK', 'duration' => ['value' => 300], 'duration_in_traffic' => ['value' => 420]]]],
            ['elements' => [['status' => 'ZERO_RESULTS']]],
        ],
    ])]);

    $r = (new GoogleMaps('clave'))->duracionesHacia([7 => [-34.6, -58.4], 9 => [-34.7, -58.5]], -34.65, -58.45);

    expect($r)->toBe([7 => 420, 9 => null]);
    Http::assertSent(fn ($req) => str_contains($req->url(), 'origins=-34.6%2C-58.4%7C-34.7%2C-58.5'));
});

it('devuelve nulos si Google falla', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED'])]);

    expect((new GoogleMaps('clave'))->duracionesHacia([1 => [0, 0]], 1, 1))->toBe([1 => null]);
});
```

`backend/tests/Feature/AsignadorTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Mapas\ServicioMapas;
use App\Models\Parametro;
use App\Models\Viaje;
use App\Servicios\Asignador;

function candidatos(array $choferes)
{
    return collect($choferes)->each->load('ubicacion');
}

it('ordena por cercanía al origen del viaje', function () {
    $viaje = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);
    $lejos = choferEnTurno(-34.700, -58.480);
    $cerca = choferEnTurno(-34.601, -58.381);
    $medio = choferEnTurno(-34.620, -58.400);

    $orden = app(Asignador::class)->ordenarPorCercania($viaje, candidatos([$lejos, $cerca, $medio]));

    expect($orden->pluck('id')->all())->toBe([$cerca->id, $medio->id, $lejos->id]);
});

it('prioriza el tiempo de llegada de Google sobre la distancia en línea recta', function () {
    $viaje = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);
    $cerca = choferEnTurno(-34.601, -58.381);
    $medio = choferEnTurno(-34.620, -58.400);
    $this->app->instance(ServicioMapas::class, new class implements ServicioMapas {
        public function duracionesHacia(array $origenes, float $lat, float $lng): array
        {
            // El más cercano en línea recta tarda más (p. ej., está del otro lado de una autopista).
            return array_map(fn ($o) => $o[0] === -34.601 ? 900 : 200, $origenes);
        }

        public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
        {
            return null;
        }
    });

    $orden = app(Asignador::class)->ordenarPorCercania($viaje, candidatos([$cerca, $medio]));

    expect($orden->pluck('id')->all())->toBe([$medio->id, $cerca->id]);
});

it('consulta a Google solo los N más cercanos y agrega el resto al final', function () {
    Parametro::create(['clave' => 'candidatos_distance_matrix', 'valor' => '2']);
    $viaje = Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380]);
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.610, -58.390);
    $c = choferEnTurno(-34.700, -58.480);
    $espia = new class implements ServicioMapas {
        public array $consultados = [];

        public function duracionesHacia(array $origenes, float $lat, float $lng): array
        {
            $this->consultados = array_keys($origenes);

            return array_map(fn () => null, $origenes);
        }

        public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
        {
            return null;
        }
    };
    $this->app->instance(ServicioMapas::class, $espia);

    $orden = app(Asignador::class)->ordenarPorCercania($viaje, candidatos([$c, $b, $a]));

    expect($espia->consultados)->toBe([$a->id, $b->id])
        ->and($orden->pluck('id')->all())->toBe([$a->id, $b->id, $c->id]);
});

it('asigna chofer y vehículo y pasa el viaje a aceptado', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create();

    expect(app(Asignador::class)->asignar($viaje, $chofer))->toBeTrue()
        ->and($viaje->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->chofer_id)->toBe($chofer->id)
        ->and($viaje->vehiculo_id)->toBe($chofer->turnoAbierto->vehiculo_id);
});

it('no asigna el mismo chofer a dos viajes', function () {
    $chofer = choferEnTurno();
    $primero = Viaje::factory()->create();
    $segundo = Viaje::factory()->create();

    expect(app(Asignador::class)->asignar($primero, $chofer))->toBeTrue()
        ->and(app(Asignador::class)->asignar($segundo, $chofer))->toBeFalse()
        ->and($segundo->fresh()->estado)->toBe(EstadoViaje::Buscando)
        ->and($segundo->fresh()->chofer_id)->toBeNull();
});

it('no asigna un viaje que ya fue cancelado', function () {
    $viaje = Viaje::factory()->create(['estado' => EstadoViaje::Cancelado]);

    expect(app(Asignador::class)->asignar($viaje, choferEnTurno()))->toBeFalse();
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/GoogleMapsTest.php tests/Feature/AsignadorTest.php`
Expected: FAIL (`Class "App\Mapas\GoogleMaps" not found`).

- [ ] **Step 3: Implementar mapas**

`backend/app/Mapas/Distancia.php`:

```php
<?php

namespace App\Mapas;

final class Distancia
{
    private const RADIO_TIERRA_M = 6371000;

    /** Distancia en línea recta (haversine) en metros. */
    public static function metros(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::RADIO_TIERRA_M * asin(min(1, sqrt($a)));
    }
}
```

`backend/app/Mapas/ServicioMapas.php`:

```php
<?php

namespace App\Mapas;

interface ServicioMapas
{
    /**
     * Tiempo de manejo en segundos desde cada origen hasta el destino.
     *
     * @param  array<int|string, array{0: float, 1: float}>  $origenes  [clave => [lat, lng]]
     * @return array<int|string, ?int>  [clave => segundos | null si no hay dato]. Nunca lanza.
     */
    public function duracionesHacia(array $origenes, float $lat, float $lng): array;

    /** Duración de manejo en segundos entre dos puntos, o null si no hay dato. */
    public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int;
}
```

`backend/app/Mapas/ServicioMapasFalso.php`:

```php
<?php

namespace App\Mapas;

/** Desarrollo y tests: estima 30 km/h en línea recta. */
class ServicioMapasFalso implements ServicioMapas
{
    private const METROS_POR_SEGUNDO = 8.33;

    public function duracionesHacia(array $origenes, float $lat, float $lng): array
    {
        return array_map(
            fn (array $o) => (int) round(Distancia::metros($o[0], $o[1], $lat, $lng) / self::METROS_POR_SEGUNDO),
            $origenes,
        );
    }

    public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
    {
        return $this->duracionesHacia(['r' => [$oLat, $oLng]], $dLat, $dLng)['r'];
    }
}
```

`backend/app/Mapas/GoogleMaps.php`:

```php
<?php

namespace App\Mapas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMaps implements ServicioMapas
{
    private const URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    public function __construct(private string $apiKey) {}

    public function duracionesHacia(array $origenes, float $lat, float $lng): array
    {
        if ($origenes === []) {
            return [];
        }

        $claves = array_keys($origenes);
        $nulos = array_fill_keys($claves, null);

        try {
            $r = Http::timeout(5)->get(self::URL, [
                'origins' => implode('|', array_map(fn (array $o) => "{$o[0]},{$o[1]}", $origenes)),
                'destinations' => "$lat,$lng",
                'mode' => 'driving',
                'departure_time' => 'now',
                'key' => $this->apiKey,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Distance Matrix sin conexión', ['error' => $e->getMessage()]);

            return $nulos;
        }

        if ($r->failed() || $r->json('status') !== 'OK') {
            Log::warning('Distance Matrix falló', ['http' => $r->status(), 'status' => $r->json('status')]);

            return $nulos;
        }

        $resultado = [];
        foreach ($claves as $i => $clave) {
            $el = $r->json("rows.$i.elements.0");
            $resultado[$clave] = ($el['status'] ?? null) === 'OK'
                ? (int) ($el['duration_in_traffic']['value'] ?? $el['duration']['value'])
                : null;
        }

        return $resultado;
    }

    public function duracionRuta(float $oLat, float $oLng, float $dLat, float $dLng): ?int
    {
        return $this->duracionesHacia(['r' => [$oLat, $oLng]], $dLat, $dLng)['r'];
    }
}
```

En `backend/app/Providers/AppServiceProvider.php`, método `register()`, agregar:

```php
$this->app->bind(\App\Mapas\ServicioMapas::class, fn () => match (config('vehiculos.mapas.driver')) {
    'google' => new \App\Mapas\GoogleMaps((string) config('vehiculos.mapas.google_api_key')),
    default => new \App\Mapas\ServicioMapasFalso(),
});
```

- [ ] **Step 4: Implementar el asignador**

`backend/app/Servicios/Asignador.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Mapas\Distancia;
use App\Mapas\ServicioMapas;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Asignador
{
    public function __construct(
        private ServicioMapas $mapas,
        private Parametros $parametros,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
    ) {}

    /**
     * @param  Collection<int, Usuario>  $candidatos  con la relación `ubicacion` cargada
     * @return Collection<int, Usuario>
     */
    public function ordenarPorCercania(Viaje $viaje, Collection $candidatos): Collection
    {
        $porDistancia = $candidatos
            ->filter(fn (Usuario $c) => $c->ubicacion !== null)
            ->sortBy(fn (Usuario $c) => Distancia::metros(
                $c->ubicacion->lat, $c->ubicacion->lng, $viaje->origen_lat, $viaje->origen_lng,
            ))
            ->values();

        $n = $this->parametros->entero('candidatos_distance_matrix');
        $primeros = $porDistancia->take($n);

        $duraciones = $this->mapas->duracionesHacia(
            $primeros->mapWithKeys(fn (Usuario $c) => [$c->id => [$c->ubicacion->lat, $c->ubicacion->lng]])->all(),
            $viaje->origen_lat,
            $viaje->origen_lng,
        );

        // sortBy es estable: sin datos de Google se conserva el orden por distancia.
        return $primeros
            ->sortBy(fn (Usuario $c) => $duraciones[$c->id] ?? PHP_INT_MAX)
            ->concat($porDistancia->slice($n))
            ->values();
    }

    public function asignar(Viaje $viaje, Usuario $chofer): bool
    {
        $asignado = DB::transaction(function () use ($viaje, $chofer) {
            $bloqueado = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if (! in_array($bloqueado->estado, [EstadoViaje::Buscando, EstadoViaje::Ofrecido], true)) {
                return false;
            }
            if ($this->estados->estado($chofer) !== EstadoChofer::Libre) {
                return false;
            }

            return $this->maquina->transicionar($bloqueado, EstadoViaje::Aceptado, [
                'chofer_id' => $chofer->id,
                'vehiculo_id' => $chofer->turnoAbierto()->value('vehiculo_id'),
            ]);
        });

        $viaje->refresh();

        return $asignado;
    }
}
```

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: servicio de mapas y asignador con bloqueo" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Despachador (ofertas, rechazos y vencimientos)

**Files:**
- Create: `backend/app/Servicios/Despachador.php`
- Create: `backend/app/Jobs/VencerOferta.php`
- Test: `backend/tests/Feature/DespachadorTest.php`

**Interfaces:**
- Consumes: `Asignador` (Task 8), `CalculadorEstadoChofer` (Task 6), `MaquinaEstadosViaje` (Task 7), `Parametros`, `ReglaNegocio` (Task 4), modelos (Task 2), `choferEnTurno()`.
- Produces:
  - `Despachador::despachar(Viaje $viaje): void` — modo "más cercano". Solo actúa si el viaje está en `buscando`. Obligatorio: asigna directo al primero que se pueda. No obligatorio: crea oferta al primero elegible. Sin candidatos: `sin_chofer`.
  - `Despachador::pedirA(Viaje $viaje, Usuario $chofer): void` — modo "chofer específico". Si no se puede asignar/ofrecer: `sin_chofer`.
  - `Despachador::responder(OfertaViaje $oferta, bool $acepta): void` — lanza `ReglaNegocio` si la oferta no está pendiente, si venció o si el viaje ya no está disponible.
  - `Despachador::vencer(OfertaViaje $oferta): void` — no-op si la oferta ya no está pendiente.
  - Candidatos de "más cercano": choferes `Libre`, sin oferta pendiente vigente (de cualquier viaje) y que nunca recibieron oferta de este viaje.
  - Job `VencerOferta(int $ofertaId)` despachado con delay hasta `vence_en`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/DespachadorTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Excepciones\ReglaNegocio;
use App\Jobs\VencerOferta;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use App\Servicios\Despachador;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function viajeEnOrigen(array $attrs = []): Viaje
{
    return Viaje::factory()->create(['origen_lat' => -34.600, 'origen_lng' => -58.380, ...$attrs]);
}

it('ofrece el viaje al chofer libre más cercano por 30 segundos', function () {
    $lejos = choferEnTurno(-34.700, -58.480);
    $cerca = choferEnTurno(-34.601, -58.381);
    $viaje = viajeEnOrigen();

    app(Despachador::class)->despachar($viaje);

    $oferta = OfertaViaje::sole();
    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Ofrecido)
        ->and($oferta->chofer_id)->toBe($cerca->id)
        ->and($oferta->resultado)->toBe(ResultadoOferta::Pendiente)
        ->and((int) $oferta->ofrecido_en->diffInSeconds($oferta->vence_en))->toBe(30);
    Queue::assertPushed(VencerOferta::class, fn ($job) => $job->ofertaId === $oferta->id);
});

it('asigna directo un viaje obligatorio sin crear oferta', function () {
    $cerca = choferEnTurno(-34.601, -58.381);
    $viaje = viajeEnOrigen(['obligatorio' => true]);

    app(Despachador::class)->despachar($viaje);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($cerca->id)
        ->and(OfertaViaje::count())->toBe(0);
});

it('marca sin_chofer si no hay choferes libres', function () {
    $viaje = viajeEnOrigen();

    app(Despachador::class)->despachar($viaje);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});

it('pasa al siguiente chofer al rechazar y nunca vuelve a ofrecerle al que rechazó', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);

    $d->despachar($viaje);
    $d->responder(OfertaViaje::where('chofer_id', $a->id)->sole(), false);
    $d->responder(OfertaViaje::where('chofer_id', $b->id)->sole(), false);

    expect(OfertaViaje::where('chofer_id', $a->id)->count())->toBe(1)
        ->and(OfertaViaje::where('chofer_id', $b->id)->count())->toBe(1)
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});

it('asigna al aceptar la oferta', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);

    $d->responder(OfertaViaje::sole(), true);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($a->id)
        ->and(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Aceptada);
});

it('al vencer la oferta pasa al siguiente chofer', function () {
    choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);

    (new VencerOferta(OfertaViaje::sole()->id))->handle($d);

    expect(OfertaViaje::where('resultado', ResultadoOferta::Expirada)->count())->toBe(1)
        ->and(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole()->chofer_id)->toBe($b->id);
});

it('el vencimiento no hace nada si el chofer ya aceptó', function () {
    choferEnTurno(-34.601, -58.381);
    choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);
    $oferta = OfertaViaje::sole();
    $d->responder($oferta, true);

    (new VencerOferta($oferta->id))->handle($d);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($oferta->fresh()->resultado)->toBe(ResultadoOferta::Aceptada)
        ->and(OfertaViaje::count())->toBe(1);
});

it('no acepta responder una oferta ya respondida', function () {
    choferEnTurno(-34.601, -58.381);
    $d = app(Despachador::class);
    $d->despachar(viajeEnOrigen());
    $oferta = OfertaViaje::sole();
    $d->responder($oferta, false);

    $d->responder($oferta->fresh(), true);
})->throws(ReglaNegocio::class, 'La oferta ya no está vigente.');

it('rechaza aceptar una oferta vencida y sigue buscando', function () {
    choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $d = app(Despachador::class);
    $d->despachar(viajeEnOrigen());
    $oferta = OfertaViaje::sole();
    $this->travel(31)->seconds();

    expect(fn () => $d->responder($oferta, true))->toThrow(ReglaNegocio::class, 'La oferta venció.');
    expect(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole()->chofer_id)->toBe($b->id);
});

it('no ofrece a un chofer que ya tiene otra oferta pendiente', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $d = app(Despachador::class);
    $d->despachar(viajeEnOrigen());
    $segundo = viajeEnOrigen();

    $d->despachar($segundo);

    expect(OfertaViaje::where('viaje_id', $segundo->id)->sole()->chofer_id)->toBe($b->id);
});

it('pide a un chofer específico y queda sin_chofer si rechaza', function () {
    choferEnTurno(-34.601, -58.381);
    $elegido = choferEnTurno(-34.700, -58.480);
    $viaje = viajeEnOrigen(['modo' => ModoViaje::Especifico]);
    $d = app(Despachador::class);

    $d->pedirA($viaje, $elegido);
    $d->responder(OfertaViaje::sole(), false);

    expect(OfertaViaje::sole()->chofer_id)->toBe($elegido->id)
        ->and($viaje->fresh()->estado)->toBe(EstadoViaje::SinChofer);
});

it('asigna directo a un chofer específico si el viaje es obligatorio', function () {
    $elegido = choferEnTurno();
    $viaje = viajeEnOrigen(['modo' => ModoViaje::Especifico, 'obligatorio' => true]);

    app(Despachador::class)->pedirA($viaje, $elegido);

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($elegido->id);
});

it('el vencimiento no hace nada si el viaje fue cancelado', function () {
    choferEnTurno(-34.601, -58.381);
    choferEnTurno(-34.620, -58.400);
    $viaje = viajeEnOrigen();
    $d = app(Despachador::class);
    $d->despachar($viaje);
    $viaje->fresh()->update(['estado' => EstadoViaje::Cancelado]);

    $d->vencer(OfertaViaje::sole());

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Cancelado)
        ->and(OfertaViaje::count())->toBe(1);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/DespachadorTest.php`
Expected: FAIL (`Class "App\Servicios\Despachador" not found`).

- [ ] **Step 3: Implementar el job**

`backend/app/Jobs/VencerOferta.php`:

```php
<?php

namespace App\Jobs;

use App\Models\OfertaViaje;
use App\Servicios\Despachador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class VencerOferta implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ofertaId) {}

    public function handle(Despachador $despachador): void
    {
        if ($oferta = OfertaViaje::find($this->ofertaId)) {
            $despachador->vencer($oferta);
        }
    }
}
```

- [ ] **Step 4: Implementar el despachador**

`backend/app/Servicios/Despachador.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\ResultadoOferta;
use App\Excepciones\ReglaNegocio;
use App\Jobs\VencerOferta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Busca chofer para viajes inmediatos (spec 5.2 y 5.3). */
class Despachador
{
    public function __construct(
        private Asignador $asignador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
        private Parametros $parametros,
    ) {}

    public function despachar(Viaje $viaje): void
    {
        $viaje->refresh();
        if ($viaje->estado !== EstadoViaje::Buscando) {
            return;
        }

        foreach ($this->asignador->ordenarPorCercania($viaje, $this->candidatos($viaje)) as $chofer) {
            $listo = $viaje->obligatorio
                ? $this->asignador->asignar($viaje, $chofer)
                : $this->ofrecer($viaje, $chofer);

            if ($listo) {
                return;
            }
        }

        $this->maquina->transicionar($viaje->refresh(), EstadoViaje::SinChofer);
    }

    public function pedirA(Viaje $viaje, Usuario $chofer): void
    {
        $listo = $viaje->obligatorio
            ? $this->asignador->asignar($viaje, $chofer)
            : $this->ofrecer($viaje, $chofer);

        if (! $listo) {
            $this->maquina->transicionar($viaje->refresh(), EstadoViaje::SinChofer);
        }
    }

    public function responder(OfertaViaje $oferta, bool $acepta): void
    {
        $resultado = DB::transaction(function () use ($oferta, $acepta) {
            $o = OfertaViaje::whereKey($oferta->id)->lockForUpdate()->firstOrFail();

            if ($o->resultado !== ResultadoOferta::Pendiente) {
                return null;
            }
            if ($o->vence_en->isPast()) {
                $o->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);

                return ResultadoOferta::Expirada;
            }

            $nuevo = $acepta ? ResultadoOferta::Aceptada : ResultadoOferta::Rechazada;
            $o->update(['resultado' => $nuevo, 'respondido_en' => now()]);

            return $nuevo;
        });

        if ($resultado === null) {
            throw new ReglaNegocio('La oferta ya no está vigente.');
        }

        $viaje = $oferta->viaje;

        if ($resultado === ResultadoOferta::Aceptada) {
            if ($this->asignador->asignar($viaje, $oferta->chofer)) {
                return;
            }
            $this->seguirBuscando($viaje);

            throw new ReglaNegocio('El viaje ya no está disponible.');
        }

        $this->seguirBuscando($viaje);

        if ($resultado === ResultadoOferta::Expirada) {
            throw new ReglaNegocio('La oferta venció.');
        }
    }

    public function vencer(OfertaViaje $oferta): void
    {
        $vencida = DB::transaction(function () use ($oferta) {
            $o = OfertaViaje::whereKey($oferta->id)->lockForUpdate()->firstOrFail();
            if ($o->resultado !== ResultadoOferta::Pendiente) {
                return false;
            }
            $o->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);

            return true;
        });

        if ($vencida) {
            $this->seguirBuscando($oferta->viaje);
        }
    }

    private function seguirBuscando(Viaje $viaje): void
    {
        $viaje->refresh();
        if ($viaje->estado !== EstadoViaje::Ofrecido) {
            return; // cancelado o ya resuelto mientras tanto
        }

        if ($viaje->modo === ModoViaje::Especifico) {
            $this->maquina->transicionar($viaje, EstadoViaje::SinChofer);

            return;
        }

        $this->maquina->transicionar($viaje, EstadoViaje::Buscando);
        $this->despachar($viaje);
    }

    private function ofrecer(Viaje $viaje, Usuario $chofer): bool
    {
        $oferta = DB::transaction(function () use ($viaje, $chofer) {
            $v = Viaje::whereKey($viaje->id)->lockForUpdate()->firstOrFail();
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();

            if ($v->estado !== EstadoViaje::Buscando
                || $this->estados->estado($chofer) !== EstadoChofer::Libre
                || $this->tieneOfertaPendiente($chofer->id)) {
                return null;
            }

            $this->maquina->transicionar($v, EstadoViaje::Ofrecido);

            return OfertaViaje::create([
                'viaje_id' => $v->id,
                'chofer_id' => $chofer->id,
                'resultado' => ResultadoOferta::Pendiente,
                'ofrecido_en' => now(),
                'vence_en' => now()->addSeconds($this->parametros->entero('oferta_segundos')),
            ]);
        });

        if (! $oferta) {
            return false;
        }

        VencerOferta::dispatch($oferta->id)->delay($oferta->vence_en);

        return true;
    }

    /** @return Collection<int, Usuario> */
    private function candidatos(Viaje $viaje): Collection
    {
        $yaOfrecidos = OfertaViaje::where('viaje_id', $viaje->id)->pluck('chofer_id');

        return $this->estados->libres()
            ->reject(fn (Usuario $c) => $yaOfrecidos->contains($c->id) || $this->tieneOfertaPendiente($c->id))
            ->values();
    }

    private function tieneOfertaPendiente(int $choferId): bool
    {
        return OfertaViaje::where('chofer_id', $choferId)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->where('vence_en', '>', now())
            ->exists();
    }
}
```

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: despachador de viajes inmediatos con ofertas y vencimientos" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: API de pedidos, ofertas y mapa

**Files:**
- Create: `backend/app/Http/Resources/ViajeResource.php`
- Create: `backend/app/Servicios/ServicioViaje.php`
- Create: `backend/app/Http/Controllers/{ViajeController,OfertaController,MapaController}.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (sin wrapping de resources), `backend/routes/api.php`
- Test: `backend/tests/Feature/PedidosHttpTest.php`

**Interfaces:**
- Consumes: `Despachador` (Task 9), `CalculadorEstadoChofer` (Task 6), `CargoPrioritario::esObligatorio()` (Task 2), `ReglaNegocio`, `AccionNoPermitida`, `rol` (Task 4).
- Produces:
  - `ServicioViaje::pedir(Usuario $solicitante, array $datos): Viaje` — `$datos`: `modo` (`mas_cercano`|`especifico`), `chofer_id?`, `origen_lat`, `origen_lng`, `origen_direccion?`, `destino_lat`, `destino_lng`, `destino_direccion?`, `motivo?`.
  - `ViajeResource` → `{id, tipo, modo, estado, obligatorio, origen:{lat,lng,direccion}, destino:{...}, motivo, programado_para, chofer:{id,nombre,telefono}|null, vehiculo:{patente,marca,modelo,color}|null, solicitante:{id,nombre,telefono}, aceptado_en, llego_en, iniciado_en, finalizado_en, cancelado_en}` (sin envoltorio `data`).
  - Rutas:
    - `POST /api/viajes` (`rol:solicitante,admin`) → 201 `ViajeResource`
    - `GET /api/viajes/actual` (auth) → `{viaje: ViajeResource|null, oferta: {id, vence_en, viaje: ViajeResource}|null}`
    - `POST /api/ofertas/{oferta}/aceptar` (`rol:chofer`) → 200 `ViajeResource`; `POST /api/ofertas/{oferta}/rechazar` → 204
    - `GET /api/choferes` (auth) → `[{id, nombre, estado, lat, lng, rumbo, actualizado_en, vehiculo:{patente,marca,modelo,color}}]`

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/PedidosHttpTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Models\CargoPrioritario;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function datosPedido(array $extra = []): array
{
    return [
        'modo' => 'mas_cercano',
        'origen_lat' => -34.600, 'origen_lng' => -58.380, 'origen_direccion' => 'Talcahuano 550',
        'destino_lat' => -34.609, 'destino_lng' => -58.392, 'destino_direccion' => 'Tribunales',
        'motivo' => 'Audiencia',
        ...$extra,
    ];
}

it('crea un pedido al más cercano y lo ofrece', function () {
    $chofer = choferEnTurno(-34.601, -58.381);

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/viajes', datosPedido())
        ->assertCreated()
        ->assertJsonPath('estado', 'ofrecido')
        ->assertJsonPath('obligatorio', false)
        ->assertJsonPath('destino.direccion', 'Tribunales');

    expect(OfertaViaje::sole()->chofer_id)->toBe($chofer->id);
});

it('marca obligatorio y asigna directo si el cargo es prioritario', function () {
    CargoPrioritario::create(['cargo' => 'Juez', 'obligatorio' => true]);
    $chofer = choferEnTurno();

    $this->actingAs(Usuario::factory()->create(['cargo' => 'Juez']))
        ->postJson('/api/viajes', datosPedido())
        ->assertCreated()
        ->assertJsonPath('estado', 'aceptado')
        ->assertJsonPath('obligatorio', true)
        ->assertJsonPath('chofer.id', $chofer->id)
        ->assertJsonPath('vehiculo.patente', $chofer->turnoAbierto->vehiculo->patente);
});

it('rechaza pedir un chofer específico que no está libre', function () {
    $ocupado = choferEnTurno();
    Viaje::factory()->create(['chofer_id' => $ocupado->id, 'estado' => EstadoViaje::EnCurso]);

    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/viajes', datosPedido(['modo' => 'especifico', 'chofer_id' => $ocupado->id]))
        ->assertStatus(422)
        ->assertJsonPath('message', 'El chofer elegido no está disponible.');

    expect(Viaje::count())->toBe(1);
});

it('exige chofer_id en modo específico', function () {
    $this->actingAs(Usuario::factory()->create())
        ->postJson('/api/viajes', datosPedido(['modo' => 'especifico']))
        ->assertJsonValidationErrors('chofer_id');
});

it('no permite un segundo pedido con uno en progreso', function () {
    $solicitante = Usuario::factory()->create();
    Viaje::factory()->for($solicitante, 'solicitante')->create(['estado' => EstadoViaje::Ofrecido]);

    $this->actingAs($solicitante)->postJson('/api/viajes', datosPedido())->assertStatus(422);
});

it('no permite pedir a un chofer', function () {
    $this->actingAs(Usuario::factory()->chofer()->create())
        ->postJson('/api/viajes', datosPedido())
        ->assertForbidden();
});

it('el chofer acepta su oferta', function () {
    $chofer = choferEnTurno();
    $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido());
    $oferta = OfertaViaje::sole();

    $this->actingAs($chofer)->postJson("/api/ofertas/{$oferta->id}/aceptar")
        ->assertOk()
        ->assertJsonPath('estado', 'aceptado')
        ->assertJsonPath('chofer.id', $chofer->id);
});

it('el chofer no puede responder una oferta ajena', function () {
    choferEnTurno();
    $otro = choferEnTurno(-34.9, -58.9);
    $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido());

    $this->actingAs($otro)->postJson('/api/ofertas/'.OfertaViaje::sole()->id.'/aceptar')->assertForbidden();
});

it('el chofer rechaza y el viaje queda sin chofer si no hay otro', function () {
    $chofer = choferEnTurno();
    $viajeId = $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido())->json('id');

    $this->actingAs($chofer)->postJson('/api/ofertas/'.OfertaViaje::sole()->id.'/rechazar')->assertNoContent();

    expect(Viaje::find($viajeId)->estado)->toBe(EstadoViaje::SinChofer);
});

it('muestra al chofer su oferta pendiente en viajes/actual', function () {
    $chofer = choferEnTurno();
    $this->actingAs(Usuario::factory()->create())->postJson('/api/viajes', datosPedido());

    $this->actingAs($chofer)->getJson('/api/viajes/actual')
        ->assertOk()
        ->assertJsonPath('viaje', null)
        ->assertJsonPath('oferta.id', OfertaViaje::sole()->id)
        ->assertJsonPath('oferta.viaje.motivo', 'Audiencia');
});

it('muestra al solicitante su viaje en progreso', function () {
    choferEnTurno();
    $solicitante = Usuario::factory()->create();
    $id = $this->actingAs($solicitante)->postJson('/api/viajes', datosPedido())->json('id');

    $this->actingAs($solicitante)->getJson('/api/viajes/actual')->assertJsonPath('viaje.id', $id);
});

it('lista los choferes en turno para el mapa', function () {
    $libre = choferEnTurno(-34.601, -58.381);
    Usuario::factory()->chofer()->create();

    $this->actingAs(Usuario::factory()->create())->getJson('/api/choferes')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $libre->id)
        ->assertJsonPath('0.estado', 'libre')
        ->assertJsonPath('0.lat', -34.601)
        ->assertJsonPath('0.vehiculo.patente', $libre->turnoAbierto->vehiculo->patente);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/PedidosHttpTest.php`
Expected: FAIL (404 en las rutas).

- [ ] **Step 3: Implementar el resource**

`backend/app/Http/Resources/ViajeResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Viaje */
class ViajeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'modo' => $this->modo->value,
            'estado' => $this->estado->value,
            'obligatorio' => $this->obligatorio,
            'origen' => ['lat' => $this->origen_lat, 'lng' => $this->origen_lng, 'direccion' => $this->origen_direccion],
            'destino' => ['lat' => $this->destino_lat, 'lng' => $this->destino_lng, 'direccion' => $this->destino_direccion],
            'motivo' => $this->motivo,
            'programado_para' => $this->programado_para?->toIso8601String(),
            'chofer' => $this->chofer
                ? ['id' => $this->chofer->id, 'nombre' => $this->chofer->nombre, 'telefono' => $this->chofer->telefono]
                : null,
            'vehiculo' => $this->vehiculo?->only(['patente', 'marca', 'modelo', 'color']),
            'solicitante' => [
                'id' => $this->solicitante->id,
                'nombre' => $this->solicitante->nombre,
                'telefono' => $this->solicitante->telefono,
            ],
            'aceptado_en' => $this->aceptado_en?->toIso8601String(),
            'llego_en' => $this->llego_en?->toIso8601String(),
            'iniciado_en' => $this->iniciado_en?->toIso8601String(),
            'finalizado_en' => $this->finalizado_en?->toIso8601String(),
            'cancelado_en' => $this->cancelado_en?->toIso8601String(),
        ];
    }
}
```

En `backend/app/Providers/AppServiceProvider.php`, método `boot()`:

```php
\Illuminate\Http\Resources\Json\JsonResource::withoutWrapping();
```

- [ ] **Step 4: Implementar el servicio de viajes**

`backend/app/Servicios/ServicioViaje.php`:

```php
<?php

namespace App\Servicios;

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ModoViaje;
use App\Enums\RolUsuario;
use App\Enums\TipoViaje;
use App\Excepciones\ReglaNegocio;
use App\Models\CargoPrioritario;
use App\Models\Usuario;
use App\Models\Viaje;

class ServicioViaje
{
    public function __construct(
        private Despachador $despachador,
        private CalculadorEstadoChofer $estados,
        private MaquinaEstadosViaje $maquina,
    ) {}

    public function pedir(Usuario $solicitante, array $datos): Viaje
    {
        $enProgreso = Viaje::where('solicitante_id', $solicitante->id)
            ->where('tipo', TipoViaje::Inmediato)
            ->whereIn('estado', EstadoViaje::enProgreso())
            ->exists();
        if ($enProgreso) {
            throw new ReglaNegocio('Ya tenés un viaje en curso.');
        }

        $modo = ModoViaje::from($datos['modo']);
        $chofer = null;
        if ($modo === ModoViaje::Especifico) {
            $chofer = Usuario::where('rol', RolUsuario::Chofer)->find($datos['chofer_id'])
                ?? throw new ReglaNegocio('El chofer elegido no existe.');
            if ($this->estados->estado($chofer) !== EstadoChofer::Libre) {
                throw new ReglaNegocio('El chofer elegido no está disponible.');
            }
        }

        $viaje = Viaje::create([
            'solicitante_id' => $solicitante->id,
            'tipo' => TipoViaje::Inmediato,
            'modo' => $modo,
            'obligatorio' => CargoPrioritario::esObligatorio($solicitante->cargo),
            'origen_lat' => $datos['origen_lat'],
            'origen_lng' => $datos['origen_lng'],
            'origen_direccion' => $datos['origen_direccion'] ?? null,
            'destino_lat' => $datos['destino_lat'],
            'destino_lng' => $datos['destino_lng'],
            'destino_direccion' => $datos['destino_direccion'] ?? null,
            'motivo' => $datos['motivo'] ?? null,
            'estado' => EstadoViaje::Buscando,
        ]);

        $chofer
            ? $this->despachador->pedirA($viaje, $chofer)
            : $this->despachador->despachar($viaje);

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }
}
```

- [ ] **Step 5: Implementar controladores y rutas**

`backend/app/Http/Controllers/ViajeController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Enums\TipoViaje;
use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use App\Models\Viaje;
use App\Servicios\ServicioViaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViajeController extends Controller
{
    private const RELACIONES = ['chofer', 'vehiculo', 'solicitante'];

    public function __construct(private ServicioViaje $viajes) {}

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'modo' => ['required', 'in:mas_cercano,especifico'],
            'chofer_id' => ['required_if:modo,especifico', 'nullable', 'integer'],
            'origen_lat' => ['required', 'numeric', 'between:-90,90'],
            'origen_lng' => ['required', 'numeric', 'between:-180,180'],
            'origen_direccion' => ['nullable', 'string', 'max:255'],
            'destino_lat' => ['required', 'numeric', 'between:-90,90'],
            'destino_lng' => ['required', 'numeric', 'between:-180,180'],
            'destino_direccion' => ['nullable', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        return (new ViajeResource($this->viajes->pedir($request->user(), $datos)))
            ->response()
            ->setStatusCode(201);
    }

    public function actual(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $oferta = null;

        if ($usuario->esChofer()) {
            $viaje = Viaje::activosDeChofer($usuario->id)->first();
            $oferta = OfertaViaje::where('chofer_id', $usuario->id)
                ->where('resultado', ResultadoOferta::Pendiente)
                ->where('vence_en', '>', now())
                ->first();
        } else {
            $viaje = Viaje::where('solicitante_id', $usuario->id)
                ->where('tipo', TipoViaje::Inmediato)
                ->whereIn('estado', EstadoViaje::enProgreso())
                ->latest('id')
                ->first();
        }

        return response()->json([
            'viaje' => $viaje ? new ViajeResource($viaje->load(self::RELACIONES)) : null,
            'oferta' => $oferta ? [
                'id' => $oferta->id,
                'vence_en' => $oferta->vence_en->toIso8601String(),
                'viaje' => new ViajeResource($oferta->viaje->load(self::RELACIONES)),
            ] : null,
        ]);
    }
}
```

`backend/app/Http/Controllers/OfertaController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Excepciones\AccionNoPermitida;
use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use App\Servicios\Despachador;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OfertaController extends Controller
{
    public function __construct(private Despachador $despachador) {}

    public function aceptar(Request $request, OfertaViaje $oferta): ViajeResource
    {
        $this->autorizar($request, $oferta);
        $this->despachador->responder($oferta, true);

        return new ViajeResource($oferta->viaje->fresh()->load(['chofer', 'vehiculo', 'solicitante']));
    }

    public function rechazar(Request $request, OfertaViaje $oferta): Response
    {
        $this->autorizar($request, $oferta);
        $this->despachador->responder($oferta, false);

        return response()->noContent();
    }

    private function autorizar(Request $request, OfertaViaje $oferta): void
    {
        if ($oferta->chofer_id !== $request->user()->id) {
            throw new AccionNoPermitida('Esta oferta no es tuya.');
        }
    }
}
```

`backend/app/Http/Controllers/MapaController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Servicios\CalculadorEstadoChofer;
use Illuminate\Http\JsonResponse;

class MapaController extends Controller
{
    public function __invoke(CalculadorEstadoChofer $estados): JsonResponse
    {
        return response()->json($estados->choferesEnTurno()->map(fn (array $f) => [
            'id' => $f['chofer']->id,
            'nombre' => $f['chofer']->nombre,
            'estado' => $f['estado']->value,
            'lat' => $f['chofer']->ubicacion?->lat,
            'lng' => $f['chofer']->ubicacion?->lng,
            'rumbo' => $f['chofer']->ubicacion?->rumbo,
            'actualizado_en' => $f['chofer']->ubicacion?->actualizado_en?->toIso8601String(),
            'vehiculo' => $f['chofer']->turnoAbierto->vehiculo->only(['patente', 'marca', 'modelo', 'color']),
        ])->values());
    }
}
```

En `backend/routes/api.php`, dentro del grupo `auth:sanctum`, agregar:

```php
Route::get('/choferes', \App\Http\Controllers\MapaController::class);
Route::get('/viajes/actual', [\App\Http\Controllers\ViajeController::class, 'actual']);

Route::middleware('rol:solicitante,admin')->group(function () {
    Route::post('/viajes', [\App\Http\Controllers\ViajeController::class, 'store']);
});
```

Y dentro del grupo `rol:chofer`:

```php
Route::post('/ofertas/{oferta}/aceptar', [\App\Http\Controllers\OfertaController::class, 'aceptar']);
Route::post('/ofertas/{oferta}/rechazar', [\App\Http\Controllers\OfertaController::class, 'rechazar']);
```

- [ ] **Step 6: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 7: Commit**

```bash
git add backend
git commit -m "feat: API de pedidos inmediatos, ofertas y mapa de choferes" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Avance del viaje y cancelaciones

**Files:**
- Modify: `backend/app/Servicios/ServicioViaje.php`
- Modify: `backend/app/Http/Controllers/ViajeController.php`, `backend/routes/api.php`
- Test: `backend/tests/Feature/ViajeEnCursoTest.php`

**Interfaces:**
- Consumes: `ServicioViaje` (Task 10), `Despachador::despachar` (Task 9), `MaquinaEstadosViaje` (Task 7), `ReglaNegocio`, `AccionNoPermitida` (Task 4). La columna `ofertas_viaje.motivo` ya existe (Task 2).
- Produces:
  - `ServicioViaje::avanzar(Viaje $viaje, Usuario $chofer, EstadoViaje $hacia): Viaje` — `$hacia` ∈ {en_camino, llego, en_curso, finalizado}.
  - `ServicioViaje::cancelarPorSolicitante(Viaje $viaje, Usuario $solicitante, ?string $motivo): Viaje` — permitido antes de `en_curso`; marca vencidas las ofertas pendientes del viaje.
  - `ServicioViaje::cancelarPorChofer(Viaje $viaje, Usuario $chofer, string $motivo): Viaje` — prohibido (403) si el viaje es obligatorio; registra una oferta `rechazada` con el motivo (así no se le vuelve a ofrecer), libera el viaje (`buscando`, modo `mas_cercano`) y re-despacha.
  - Rutas: `POST /api/viajes/{viaje}/estado {estado}` (`rol:chofer`) → `ViajeResource`; `POST /api/viajes/{viaje}/cancelar {motivo?}` (auth; si el usuario es chofer, `motivo` es obligatorio) → `ViajeResource`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/ViajeEnCursoTest.php`:

```php
<?php

use App\Enums\EstadoChofer;
use App\Enums\EstadoViaje;
use App\Enums\ResultadoOferta;
use App\Models\OfertaViaje;
use App\Models\Usuario;
use App\Models\Viaje;
use App\Servicios\CalculadorEstadoChofer;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function viajeAsignado(Usuario $chofer, array $attrs = []): Viaje
{
    return Viaje::factory()->create([
        'chofer_id' => $chofer->id,
        'vehiculo_id' => $chofer->turnoAbierto->vehiculo_id,
        'estado' => EstadoViaje::Aceptado,
        'aceptado_en' => now(),
        ...$attrs,
    ]);
}

it('recorre el viaje completo y libera al chofer', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer);

    foreach (['en_camino', 'llego', 'en_curso', 'finalizado'] as $estado) {
        $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => $estado])
            ->assertOk()
            ->assertJsonPath('estado', $estado);
    }

    expect($viaje->fresh()->finalizado_en)->not->toBeNull()
        ->and(app(CalculadorEstadoChofer::class)->estado($chofer))->toBe(EstadoChofer::Libre);
});

it('no permite saltear pasos', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'finalizado'])
        ->assertStatus(422);
});

it('repetir un paso no falla ni cambia nada', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer, ['estado' => EstadoViaje::Llego, 'llego_en' => now()->subMinute()]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'llego'])->assertOk();

    expect($viaje->fresh()->llego_en->lt(now()->subSeconds(30)))->toBeTrue();
});

it('otro chofer no puede avanzar el viaje', function () {
    $viaje = viajeAsignado(choferEnTurno());

    $this->actingAs(choferEnTurno(-34.9, -58.9))
        ->postJson("/api/viajes/{$viaje->id}/estado", ['estado' => 'en_camino'])
        ->assertForbidden();
});

it('el solicitante cancela antes de iniciar el viaje', function () {
    $viaje = viajeAsignado(choferEnTurno(), ['estado' => EstadoViaje::EnCamino]);

    $this->actingAs($viaje->solicitante)
        ->postJson("/api/viajes/{$viaje->id}/cancelar", ['motivo' => 'Ya no lo necesito'])
        ->assertOk()
        ->assertJsonPath('estado', 'cancelado');

    expect($viaje->fresh()->cancelado_por)->toBe('solicitante')
        ->and($viaje->fresh()->motivo_cancelacion)->toBe('Ya no lo necesito');
});

it('el solicitante no puede cancelar un viaje en curso', function () {
    $viaje = viajeAsignado(choferEnTurno(), ['estado' => EstadoViaje::EnCurso]);

    $this->actingAs($viaje->solicitante)->postJson("/api/viajes/{$viaje->id}/cancelar")->assertStatus(422);
});

it('otro usuario no puede cancelar el viaje', function () {
    $viaje = viajeAsignado(choferEnTurno());

    $this->actingAs(Usuario::factory()->create())->postJson("/api/viajes/{$viaje->id}/cancelar")->assertForbidden();
});

it('cancelar mientras se ofrece vence la oferta pendiente', function () {
    $chofer = choferEnTurno();
    $solicitante = Usuario::factory()->create();
    $id = $this->actingAs($solicitante)->postJson('/api/viajes', [
        'modo' => 'mas_cercano', 'origen_lat' => -34.6, 'origen_lng' => -58.38,
        'destino_lat' => -34.61, 'destino_lng' => -58.39,
    ])->json('id');

    $this->actingAs($solicitante)->postJson("/api/viajes/{$id}/cancelar")->assertOk();

    expect(OfertaViaje::sole()->resultado)->toBe(ResultadoOferta::Expirada);
});

it('el chofer cancela un viaje no obligatorio y se reasigna a otro', function () {
    $a = choferEnTurno(-34.601, -58.381);
    $b = choferEnTurno(-34.620, -58.400);
    $viaje = viajeAsignado($a, ['origen_lat' => -34.600, 'origen_lng' => -58.380]);

    $this->actingAs($a)->postJson("/api/viajes/{$viaje->id}/cancelar", ['motivo' => 'Pinchadura'])
        ->assertOk()
        ->assertJsonPath('estado', 'ofrecido');

    expect(OfertaViaje::where('chofer_id', $a->id)->sole()->motivo)->toBe('Pinchadura')
        ->and(OfertaViaje::where('resultado', ResultadoOferta::Pendiente)->sole()->chofer_id)->toBe($b->id)
        ->and($viaje->fresh()->chofer_id)->toBeNull();
});

it('el chofer no puede cancelar un viaje obligatorio', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer, ['obligatorio' => true]);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/cancelar", ['motivo' => 'x'])
        ->assertForbidden();

    expect($viaje->fresh()->estado)->toBe(EstadoViaje::Aceptado)
        ->and($viaje->fresh()->chofer_id)->toBe($chofer->id);
});

it('el chofer debe indicar un motivo para cancelar', function () {
    $chofer = choferEnTurno();
    $viaje = viajeAsignado($chofer);

    $this->actingAs($chofer)->postJson("/api/viajes/{$viaje->id}/cancelar")->assertJsonValidationErrors('motivo');
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/ViajeEnCursoTest.php`
Expected: FAIL (404 en las rutas).

- [ ] **Step 3: Implementar en el servicio**

En `backend/app/Servicios/ServicioViaje.php`, agregar los imports:

```php
use App\Enums\ResultadoOferta;
use App\Excepciones\AccionNoPermitida;
use App\Models\OfertaViaje;
```

y estos métodos en la clase:

```php
    private const PASOS_CHOFER = [EstadoViaje::EnCamino, EstadoViaje::Llego, EstadoViaje::EnCurso, EstadoViaje::Finalizado];

    public function avanzar(Viaje $viaje, Usuario $chofer, EstadoViaje $hacia): Viaje
    {
        if ($viaje->chofer_id !== $chofer->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }
        if (! in_array($hacia, self::PASOS_CHOFER, true)) {
            throw new ReglaNegocio('Estado no válido para el chofer.');
        }

        $this->maquina->transicionar($viaje, $hacia);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    public function cancelarPorSolicitante(Viaje $viaje, Usuario $solicitante, ?string $motivo): Viaje
    {
        if ($viaje->solicitante_id !== $solicitante->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }

        $this->maquina->transicionar($viaje, EstadoViaje::Cancelado, [
            'cancelado_por' => 'solicitante',
            'motivo_cancelacion' => $motivo,
        ]);

        OfertaViaje::where('viaje_id', $viaje->id)
            ->where('resultado', ResultadoOferta::Pendiente)
            ->update(['resultado' => ResultadoOferta::Expirada, 'respondido_en' => now()]);

        return $viaje->load(['chofer', 'vehiculo', 'solicitante']);
    }

    public function cancelarPorChofer(Viaje $viaje, Usuario $chofer, string $motivo): Viaje
    {
        if ($viaje->chofer_id !== $chofer->id) {
            throw new AccionNoPermitida('Este viaje no es tuyo.');
        }
        if ($viaje->obligatorio) {
            throw new AccionNoPermitida('Los viajes obligatorios solo puede cancelarlos un administrador.');
        }
        if (! in_array($viaje->estado, [EstadoViaje::Aceptado, EstadoViaje::EnCamino, EstadoViaje::Llego], true)) {
            throw new ReglaNegocio('El viaje ya no se puede cancelar.');
        }

        // Queda registrado como rechazo: el despachador no volverá a ofrecérselo.
        OfertaViaje::create([
            'viaje_id' => $viaje->id,
            'chofer_id' => $chofer->id,
            'resultado' => ResultadoOferta::Rechazada,
            'ofrecido_en' => $viaje->aceptado_en ?? now(),
            'vence_en' => now(),
            'respondido_en' => now(),
            'motivo' => $motivo,
        ]);

        $this->maquina->transicionar($viaje, EstadoViaje::Buscando, [
            'chofer_id' => null,
            'vehiculo_id' => null,
            'modo' => ModoViaje::MasCercano,
        ]);
        $this->despachador->despachar($viaje);

        return $viaje->refresh()->load(['chofer', 'vehiculo', 'solicitante']);
    }
```

- [ ] **Step 4: Implementar controlador y rutas**

En `backend/app/Http/Controllers/ViajeController.php`, agregar los métodos:

```php
    public function avanzar(Request $request, Viaje $viaje): ViajeResource
    {
        $datos = $request->validate(['estado' => ['required', 'in:en_camino,llego,en_curso,finalizado']]);

        return new ViajeResource(
            $this->viajes->avanzar($viaje, $request->user(), EstadoViaje::from($datos['estado'])),
        );
    }

    public function cancelar(Request $request, Viaje $viaje): ViajeResource
    {
        $usuario = $request->user();

        if ($usuario->esChofer()) {
            $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']]);

            return new ViajeResource($this->viajes->cancelarPorChofer($viaje, $usuario, $datos['motivo']));
        }

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);

        return new ViajeResource($this->viajes->cancelarPorSolicitante($viaje, $usuario, $datos['motivo'] ?? null));
    }
```

En `backend/routes/api.php`, dentro del grupo `auth:sanctum`:

```php
Route::post('/viajes/{viaje}/cancelar', [\App\Http\Controllers\ViajeController::class, 'cancelar']);
```

Y dentro del grupo `rol:chofer`:

```php
Route::post('/viajes/{viaje}/estado', [\App\Http\Controllers\ViajeController::class, 'avanzar']);
```

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat: avance del viaje y cancelaciones" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Tiempo real con Reverb

**Files:**
- Create: `backend/app/Events/{ViajeActualizado,OfertaCreada,UbicacionChoferActualizada,EstadoChoferActualizado}.php`
- Modify: `backend/bootstrap/app.php`, `backend/routes/channels.php`
- Modify: `backend/app/Servicios/MaquinaEstadosViaje.php`, `backend/app/Servicios/Despachador.php`, `backend/app/Servicios/ServicioUbicacion.php`, `backend/app/Servicios/ServicioTurnos.php`
- Test: `backend/tests/Feature/TiempoRealTest.php`, `backend/tests/Feature/CanalesTest.php`

**Interfaces:**
- Consumes: servicios de Tasks 5–9, `ViajeResource` (Task 10), `CalculadorEstadoChofer` (Task 6).
- Produces (todos los eventos implementan `ShouldBroadcastNow` + `ShouldDispatchAfterCommit`):
  - `ViajeActualizado(Viaje $viaje, ?int $choferAnteriorId = null)` → canales `private-viaje.{id}`, `private-chofer.{chofer_id}` y `private-chofer.{choferAnteriorId}` si difiere; nombre `viaje.actualizado`; payload `ViajeResource`.
  - `OfertaCreada(OfertaViaje $oferta)` → `private-chofer.{chofer_id}`; nombre `oferta.creada`; payload `{oferta_id, vence_en, viaje}`.
  - `UbicacionChoferActualizada(int $choferId, float $lat, float $lng, ?float $rumbo, string $actualizadoEn, ?int $viajeId)` → `private-mapa.choferes` y `private-viaje.{viajeId}` si hay; nombre `chofer.ubicacion`.
  - `EstadoChoferActualizado(int $choferId, string $estado)` → `private-mapa.choferes`; nombre `chofer.estado`.
  - Autorización de canales en `POST /api/broadcasting/auth` con token Sanctum.

- [ ] **Step 1: Instalar Reverb**

```bash
cd backend
php artisan install:broadcasting --reverb --no-interaction
```

Si el instalador pregunta por dependencias de Node, responder que no (el cliente es Flutter). Verificar que existan `config/reverb.php` y `routes/channels.php`.

En `backend/bootstrap/app.php`, quitar `channels: __DIR__.'/../routes/channels.php',` de `withRouting(...)` (si el instalador lo agregó) y agregar, antes de `->withMiddleware(...)`:

```php
->withBroadcasting(
    __DIR__.'/../routes/channels.php',
    ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
)
```

- [ ] **Step 2: Escribir tests que fallan**

`backend/tests/Feature/CanalesTest.php`:

```php
<?php

use App\Models\Usuario;
use App\Models\Viaje;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'clave',
        'broadcasting.connections.reverb.secret' => 'secreto',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
});

function autorizarCanal(Usuario $u, string $canal)
{
    return test()->actingAs($u)->postJson('/api/broadcasting/auth', [
        'socket_id' => '1234.5678', 'channel_name' => "private-$canal",
    ]);
}

it('el solicitante accede al canal de su viaje y un tercero no', function () {
    $viaje = Viaje::factory()->create();

    autorizarCanal($viaje->solicitante, "viaje.{$viaje->id}")->assertOk();
    autorizarCanal(Usuario::factory()->create(), "viaje.{$viaje->id}")->assertForbidden();
});

it('el chofer asignado accede al canal del viaje', function () {
    $chofer = Usuario::factory()->chofer()->create();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id]);

    autorizarCanal($chofer, "viaje.{$viaje->id}")->assertOk();
});

it('solo el propio chofer accede a su canal personal', function () {
    $chofer = Usuario::factory()->chofer()->create();

    autorizarCanal($chofer, "chofer.{$chofer->id}")->assertOk();
    autorizarCanal(Usuario::factory()->chofer()->create(), "chofer.{$chofer->id}")->assertForbidden();
});

it('cualquier usuario activo accede al mapa', function () {
    autorizarCanal(Usuario::factory()->create(), 'mapa.choferes')->assertOk();
});
```

`backend/tests/Feature/TiempoRealTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Events\EstadoChoferActualizado;
use App\Events\OfertaCreada;
use App\Events\UbicacionChoferActualizada;
use App\Events\ViajeActualizado;
use App\Models\Viaje;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use App\Servicios\ServicioUbicacion;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('emite ViajeActualizado y el estado del chofer al cambiar de estado', function () {
    Event::fake([ViajeActualizado::class, EstadoChoferActualizado::class]);
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::EnCamino);

    Event::assertDispatched(ViajeActualizado::class, fn ($e) => $e->viaje->id === $viaje->id
        && collect($e->broadcastOn())->map->name->sort()->values()->all()
            === ["private-chofer.{$chofer->id}", "private-viaje.{$viaje->id}"]);
    Event::assertDispatched(EstadoChoferActualizado::class,
        fn ($e) => $e->choferId === $chofer->id && $e->estado === 'en_viaje');
});

it('avisa al chofer anterior cuando se lo desasigna', function () {
    Event::fake([ViajeActualizado::class]);
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Buscando, ['chofer_id' => null]);

    Event::assertDispatched(ViajeActualizado::class, fn ($e) => $e->choferAnteriorId === $chofer->id
        && collect($e->broadcastOn())->map->name->contains("private-chofer.{$chofer->id}"));
});

it('emite OfertaCreada al chofer', function () {
    Event::fake([OfertaCreada::class]);
    $chofer = choferEnTurno();

    app(Despachador::class)->despachar(Viaje::factory()->create());

    Event::assertDispatched(OfertaCreada::class, fn ($e) => $e->oferta->chofer_id === $chofer->id
        && $e->broadcastOn()[0]->name === "private-chofer.{$chofer->id}");
});

it('emite la ubicación al mapa y al canal del viaje activo', function () {
    Event::fake([UbicacionChoferActualizada::class]);
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCamino]);

    app(ServicioUbicacion::class)->registrar($chofer, [
        ['lat' => -34.61, 'lng' => -58.39, 'registrado_en' => now()->toIso8601String()],
    ]);

    Event::assertDispatched(UbicacionChoferActualizada::class, fn ($e) => $e->viajeId === $viaje->id
        && collect($e->broadcastOn())->map->name->all() === ['private-mapa.choferes', "private-viaje.{$viaje->id}"]);
});

it('emite el estado del chofer al iniciar turno', function () {
    Event::fake([EstadoChoferActualizado::class]);
    $chofer = App\Models\Usuario::factory()->chofer()->create();

    app(App\Servicios\ServicioTurnos::class)->iniciar($chofer, App\Models\Vehiculo::factory()->create()->id);

    Event::assertDispatched(EstadoChoferActualizado::class, fn ($e) => $e->choferId === $chofer->id);
});
```

- [ ] **Step 3: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/CanalesTest.php tests/Feature/TiempoRealTest.php`
Expected: FAIL (`Class "App\Events\ViajeActualizado" not found` y 403 en los canales).

- [ ] **Step 4: Crear los eventos**

`backend/app/Events/ViajeActualizado.php`:

```php
<?php

namespace App\Events;

use App\Http\Resources\ViajeResource;
use App\Models\Viaje;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ViajeActualizado implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Viaje $viaje, public ?int $choferAnteriorId = null) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $choferes = array_unique(array_filter([$this->viaje->chofer_id, $this->choferAnteriorId]));

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

`backend/app/Events/OfertaCreada.php`:

```php
<?php

namespace App\Events;

use App\Http\Resources\ViajeResource;
use App\Models\OfertaViaje;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OfertaCreada implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public OfertaViaje $oferta) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("chofer.{$this->oferta->chofer_id}")];
    }

    public function broadcastAs(): string
    {
        return 'oferta.creada';
    }

    public function broadcastWith(): array
    {
        return [
            'oferta_id' => $this->oferta->id,
            'vence_en' => $this->oferta->vence_en->toIso8601String(),
            'viaje' => (new ViajeResource($this->oferta->viaje->load(['chofer', 'vehiculo', 'solicitante'])))->resolve(),
        ];
    }
}
```

`backend/app/Events/UbicacionChoferActualizada.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class UbicacionChoferActualizada implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $choferId,
        public float $lat,
        public float $lng,
        public ?float $rumbo,
        public string $actualizadoEn,
        public ?int $viajeId,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_filter([
            new PrivateChannel('mapa.choferes'),
            $this->viajeId ? new PrivateChannel("viaje.{$this->viajeId}") : null,
        ]);
    }

    public function broadcastAs(): string
    {
        return 'chofer.ubicacion';
    }

    public function broadcastWith(): array
    {
        return [
            'chofer_id' => $this->choferId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'rumbo' => $this->rumbo,
            'actualizado_en' => $this->actualizadoEn,
        ];
    }
}
```

`backend/app/Events/EstadoChoferActualizado.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EstadoChoferActualizado implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $choferId, public string $estado) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('mapa.choferes')];
    }

    public function broadcastAs(): string
    {
        return 'chofer.estado';
    }

    public function broadcastWith(): array
    {
        return ['chofer_id' => $this->choferId, 'estado' => $this->estado];
    }
}
```

- [ ] **Step 5: Autorizar canales**

Reemplazar el contenido de `backend/routes/channels.php` por:

```php
<?php

use App\Enums\RolUsuario;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('mapa.choferes', fn (Usuario $u) => $u->activo);

Broadcast::channel('viaje.{viaje}', fn (Usuario $u, Viaje $viaje) => $u->rol === RolUsuario::Admin
    || in_array($u->id, [$viaje->solicitante_id, $viaje->chofer_id], true));

Broadcast::channel('chofer.{id}', fn (Usuario $u, int $id) => $u->id === $id || $u->rol === RolUsuario::Admin);
```

- [ ] **Step 6: Emitir eventos desde los servicios**

En `backend/app/Servicios/MaquinaEstadosViaje.php`, inyectar el calculador y emitir eventos tras guardar. Agregar imports y constructor:

```php
use App\Events\EstadoChoferActualizado;
use App\Events\ViajeActualizado;

    public function __construct(private CalculadorEstadoChofer $estados) {}
```

y reemplazar el final de `transicionar()` (desde `$viaje->fill($atributos);`) por:

```php
        $choferAnterior = $viaje->chofer_id;

        $viaje->fill($atributos);
        $viaje->estado = $hacia;
        if ($marca = self::MARCAS[$hacia->value] ?? null) {
            $viaje->{$marca} = now();
        }
        $viaje->save();

        ViajeActualizado::dispatch($viaje, $choferAnterior !== $viaje->chofer_id ? $choferAnterior : null);
        foreach (array_unique(array_filter([$choferAnterior, $viaje->chofer_id])) as $choferId) {
            $this->emitirEstadoChofer($choferId);
        }

        return true;
    }

    private function emitirEstadoChofer(int $choferId): void
    {
        $chofer = \App\Models\Usuario::find($choferId);
        EstadoChoferActualizado::dispatch($choferId, $this->estados->estado($chofer)->value);
    }
```

En `backend/app/Servicios/Despachador.php`, en `ofrecer()`, justo después de `VencerOferta::dispatch(...)`:

```php
        \App\Events\OfertaCreada::dispatch($oferta);
```

En `backend/app/Servicios/ServicioUbicacion.php`, dentro del `if` que actualiza `UbicacionChofer`, después de `updateOrCreate(...)`:

```php
            \App\Events\UbicacionChoferActualizada::dispatch(
                $chofer->id,
                (float) $ultimo['lat'],
                (float) $ultimo['lng'],
                isset($ultimo['rumbo']) ? (float) $ultimo['rumbo'] : null,
                $ultimo['momento']->toIso8601String(),
                Viaje::activosDeChofer($chofer->id)->value('id'),
            );
```

En `backend/app/Servicios/ServicioTurnos.php`, agregar el constructor y reemplazar `iniciar()` y `finalizar()` completos:

```php
    public function __construct(private CalculadorEstadoChofer $estados) {}

    public function iniciar(Usuario $chofer, int $vehiculoId, OrigenTurno $origen = OrigenTurno::Manual): Turno
    {
        if (! $chofer->esChofer()) {
            throw new AccionNoPermitida('Solo los choferes pueden iniciar turno.');
        }

        $turno = DB::transaction(function () use ($chofer, $vehiculoId, $origen) {
            Usuario::whereKey($chofer->id)->lockForUpdate()->first();
            $vehiculo = Vehiculo::whereKey($vehiculoId)->lockForUpdate()->first();

            if (! $vehiculo || ! $vehiculo->activo) {
                throw new ReglaNegocio('El vehículo no existe o no está activo.');
            }
            if (Turno::where('chofer_id', $chofer->id)->whereNull('fin')->exists()) {
                throw new ReglaNegocio('Ya tenés un turno abierto.');
            }
            if (Turno::where('vehiculo_id', $vehiculo->id)->whereNull('fin')->exists()) {
                throw new ReglaNegocio('El vehículo está en uso por otro chofer.');
            }

            return Turno::create([
                'chofer_id' => $chofer->id,
                'vehiculo_id' => $vehiculo->id,
                'inicio' => now(),
                'origen' => $origen,
            ]);
        });

        \App\Events\EstadoChoferActualizado::dispatch($chofer->id, $this->estados->estado($chofer)->value);

        return $turno;
    }

    public function finalizar(Usuario $chofer): Turno
    {
        $turno = $chofer->turnoAbierto()->first()
            ?? throw new ReglaNegocio('No tenés un turno abierto.');

        if (Viaje::activosDeChofer($chofer->id)->exists()) {
            throw new ReglaNegocio('Finalizá el viaje en curso antes de cerrar el turno.');
        }

        $turno->update(['fin' => now()]);
        // Privacidad: fuera de turno no se conserva la ubicación.
        UbicacionChofer::where('chofer_id', $chofer->id)->delete();

        \App\Events\EstadoChoferActualizado::dispatch($chofer->id, \App\Enums\EstadoChofer::FueraDeTurno->value);

        return $turno;
    }
```

- [ ] **Step 7: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 8: Probar Reverb a mano**

```bash
php artisan reverb:start --debug
```

Expected: `Starting server on 0.0.0.0:8080`. Cortar con Ctrl+C.

- [ ] **Step 9: Commit**

```bash
git add backend
git commit -m "feat: eventos en tiempo real con Reverb y autorización de canales" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Notificaciones push (FCM)

Los mensajes llevan `data.modulo = "vehiculos_oficiales"` para que la app del PJ sepa reenviarlos al paquete (puente FCM, spec 12.3).

**Files:**
- Create: `backend/app/Notificaciones/{Notificador,NotificadorFcm,NotificadorRegistro}.php`
- Create: `backend/app/Listeners/AvisosViaje.php`
- Create: `backend/app/Http/Controllers/PushController.php`
- Create: `backend/tests/Fakes/NotificadorFalso.php`
- Modify: `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php`
- Test: `backend/tests/Feature/AvisosViajeTest.php`, `backend/tests/Feature/PushTokenTest.php`

**Interfaces:**
- Consumes: `ViajeActualizado`, `OfertaCreada` (Task 12).
- Produces:
  - `Notificador::enviar(Usuario $destino, string $titulo, string $cuerpo, array $datos = []): void` (nunca lanza; sin `token_push` no hace nada).
  - `AvisosViaje` (listener en cola, auto-descubierto): `handleOfertaCreada(OfertaCreada)`, `handleViajeActualizado(ViajeActualizado)`.
  - `POST /api/push/token {token}` (auth) → 204.

- [ ] **Step 1: Escribir el fake y los tests que fallan**

`backend/tests/Fakes/NotificadorFalso.php`:

```php
<?php

namespace Tests\Fakes;

use App\Models\Usuario;
use App\Notificaciones\Notificador;

class NotificadorFalso implements Notificador
{
    /** @var array<int, array{destino: int, titulo: string, cuerpo: string, datos: array}> */
    public array $enviados = [];

    public function enviar(Usuario $destino, string $titulo, string $cuerpo, array $datos = []): void
    {
        $this->enviados[] = ['destino' => $destino->id, 'titulo' => $titulo, 'cuerpo' => $cuerpo, 'datos' => $datos];
    }

    public function titulosPara(Usuario $u): array
    {
        return collect($this->enviados)->where('destino', $u->id)->pluck('titulo')->all();
    }
}
```

`backend/tests/Feature/AvisosViajeTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Models\Viaje;
use App\Notificaciones\Notificador;
use App\Servicios\Despachador;
use App\Servicios\MaquinaEstadosViaje;
use Tests\Fakes\NotificadorFalso;

beforeEach(function () {
    $this->push = new NotificadorFalso();
    $this->app->instance(Notificador::class, $this->push);
});

it('avisa al chofer de una nueva oferta', function () {
    $chofer = choferEnTurno();

    // Con la cola sync el vencimiento correría en el acto; se falsea solo ese job.
    Illuminate\Support\Facades\Bus::fake([App\Jobs\VencerOferta::class]);
    app(Despachador::class)->despachar(Viaje::factory()->create(['destino_direccion' => 'Tribunales']));

    expect($this->push->titulosPara($chofer))->toBe(['Nuevo pedido de viaje'])
        ->and($this->push->enviados[0]['datos'])->toMatchArray(['tipo' => 'oferta']);
});

it('avisa al chofer que tiene un viaje obligatorio asignado y al solicitante que está confirmado', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['obligatorio' => true]);

    app(Despachador::class)->despachar($viaje);

    expect($this->push->titulosPara($chofer))->toBe(['Viaje asignado'])
        ->and($this->push->titulosPara($viaje->solicitante))->toBe(['Tu auto está confirmado']);
});

it('avisa al solicitante cuando el chofer llegó', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::EnCamino]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Llego);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Tu auto llegó']);
});

it('avisa al solicitante si no hay choferes', function () {
    $viaje = Viaje::factory()->create();

    app(Despachador::class)->despachar($viaje);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['No hay choferes disponibles']);
});

it('avisa al chofer si el solicitante canceló', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Cancelado, ['cancelado_por' => 'solicitante']);

    expect($this->push->titulosPara($chofer))->toBe(['Viaje cancelado']);
});

it('avisa al solicitante si su chofer canceló', function () {
    $chofer = choferEnTurno();
    $viaje = Viaje::factory()->create(['chofer_id' => $chofer->id, 'estado' => EstadoViaje::Aceptado]);

    app(MaquinaEstadosViaje::class)->transicionar($viaje, EstadoViaje::Buscando, ['chofer_id' => null]);

    expect($this->push->titulosPara($viaje->solicitante))->toBe(['Tu chofer canceló']);
});
```

`backend/tests/Feature/PushTokenTest.php`:

```php
<?php

use App\Models\Usuario;

it('guarda el token push del usuario', function () {
    $u = Usuario::factory()->create();

    $this->actingAs($u)->postJson('/api/push/token', ['token' => 'fcm-abc'])->assertNoContent();

    expect($u->fresh()->token_push)->toBe('fcm-abc');
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/AvisosViajeTest.php tests/Feature/PushTokenTest.php`
Expected: FAIL (`Interface "App\Notificaciones\Notificador" not found`).

- [ ] **Step 3: Instalar Firebase**

```bash
composer require kreait/laravel-firebase
```

Agregar a `backend/.env.example`:

```
NOTIFICACIONES_DRIVER=registro
# Ruta al JSON de la cuenta de servicio del proyecto Firebase de la app del PJ
FIREBASE_CREDENTIALS=
```

- [ ] **Step 4: Implementar notificadores**

`backend/app/Notificaciones/Notificador.php`:

```php
<?php

namespace App\Notificaciones;

use App\Models\Usuario;

interface Notificador
{
    /** Envía un push. Nunca lanza: los fallos se registran en el log. */
    public function enviar(Usuario $destino, string $titulo, string $cuerpo, array $datos = []): void;
}
```

`backend/app/Notificaciones/NotificadorRegistro.php`:

```php
<?php

namespace App\Notificaciones;

use App\Models\Usuario;
use Illuminate\Support\Facades\Log;

/** Desarrollo: escribe los push en el log en lugar de enviarlos. */
class NotificadorRegistro implements Notificador
{
    public function enviar(Usuario $destino, string $titulo, string $cuerpo, array $datos = []): void
    {
        Log::info('Push (simulado)', ['destino' => $destino->id, 'titulo' => $titulo, 'cuerpo' => $cuerpo, 'datos' => $datos]);
    }
}
```

`backend/app/Notificaciones/NotificadorFcm.php`:

```php
<?php

namespace App\Notificaciones;

use App\Models\Usuario;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Throwable;

class NotificadorFcm implements Notificador
{
    public function __construct(private Messaging $messaging) {}

    public function enviar(Usuario $destino, string $titulo, string $cuerpo, array $datos = []): void
    {
        if (! $destino->token_push) {
            return;
        }

        $mensaje = CloudMessage::withTarget('token', $destino->token_push)
            ->withNotification(Notification::create($titulo, $cuerpo))
            ->withData(array_map('strval', ['modulo' => 'vehiculos_oficiales', ...$datos]))
            ->withAndroidConfig(['priority' => 'high']);

        try {
            $this->messaging->send($mensaje);
        } catch (NotFound) {
            // Token vencido o app desinstalada.
            $destino->update(['token_push' => null]);
        } catch (Throwable $e) {
            Log::error('Fallo al enviar push FCM', ['destino' => $destino->id, 'error' => $e->getMessage()]);
        }
    }
}
```

En `backend/app/Providers/AppServiceProvider.php`, método `register()`:

```php
$this->app->bind(\App\Notificaciones\Notificador::class, fn ($app) => match (config('vehiculos.notificaciones.driver')) {
    'fcm' => new \App\Notificaciones\NotificadorFcm($app->make(\Kreait\Firebase\Contract\Messaging::class)),
    default => new \App\Notificaciones\NotificadorRegistro(),
});
```

- [ ] **Step 5: Implementar el listener**

`backend/app/Listeners/AvisosViaje.php`:

```php
<?php

namespace App\Listeners;

use App\Enums\EstadoViaje;
use App\Events\OfertaCreada;
use App\Events\ViajeActualizado;
use App\Models\Usuario;
use App\Notificaciones\Notificador;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Traduce eventos de viaje a notificaciones push. */
class AvisosViaje implements ShouldQueue
{
    public function __construct(private Notificador $push) {}

    public function handleOfertaCreada(OfertaCreada $e): void
    {
        $viaje = $e->oferta->viaje;

        $this->push->enviar($e->oferta->chofer, 'Nuevo pedido de viaje',
            'Hacia '.($viaje->destino_direccion ?? 'destino marcado en el mapa').'. Respondé antes de que venza.',
            ['tipo' => 'oferta', 'oferta_id' => $e->oferta->id, 'viaje_id' => $viaje->id]);
    }

    public function handleViajeActualizado(ViajeActualizado $e): void
    {
        $v = $e->viaje->loadMissing(['chofer', 'vehiculo', 'solicitante']);
        $datos = ['tipo' => 'viaje', 'viaje_id' => $v->id, 'estado' => $v->estado->value];

        switch ($v->estado) {
            case EstadoViaje::Aceptado:
                if (! $v->chofer) {
                    break;
                }
                if ($v->obligatorio) {
                    $this->push->enviar($v->chofer, 'Viaje asignado',
                        'Tenés un viaje obligatorio asignado.', $datos);
                }
                $this->push->enviar($v->solicitante, 'Tu auto está confirmado',
                    "Te busca {$v->chofer->nombre} en {$v->vehiculo?->marca} {$v->vehiculo?->modelo} ({$v->vehiculo?->patente}).", $datos);
                break;

            case EstadoViaje::Llego:
                $this->push->enviar($v->solicitante, 'Tu auto llegó', 'El chofer te está esperando.', $datos);
                break;

            case EstadoViaje::SinChofer:
                $this->push->enviar($v->solicitante, 'No hay choferes disponibles',
                    'Podés volver a intentar o elegir otro chofer.', $datos);
                break;

            case EstadoViaje::Cancelado:
                if ($v->cancelado_por === 'solicitante' && $v->chofer) {
                    $this->push->enviar($v->chofer, 'Viaje cancelado', 'El solicitante canceló el viaje.', $datos);
                }
                break;

            case EstadoViaje::Buscando:
                if ($e->choferAnteriorId) {
                    $this->push->enviar($v->solicitante, 'Tu chofer canceló',
                        'Estamos buscando otro chofer.', $datos);
                }
                break;

            default:
                break;
        }
    }
}
```

- [ ] **Step 6: Implementar el endpoint del token**

`backend/app/Http/Controllers/PushController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PushController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $datos = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $request->user()->update(['token_push' => $datos['token']]);

        return response()->noContent();
    }
}
```

En `backend/routes/api.php`, dentro del grupo `auth:sanctum`:

```php
Route::post('/push/token', \App\Http\Controllers\PushController::class);
```

- [ ] **Step 7: Verificar que el listener se descubre**

Run: `php artisan event:list`
Expected: `App\Events\OfertaCreada` y `App\Events\ViajeActualizado` listan `App\Listeners\AvisosViaje@handle...` (en cola).

- [ ] **Step 8: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS. (Los tests que usan `Queue::fake()` no disparan el listener porque queda encolado; es lo esperado.)

- [ ] **Step 9: Commit**

```bash
git add backend
git commit -m "feat: notificaciones push FCM para ofertas y cambios de viaje" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Simulador de choferes y purga de recorridos

**Files:**
- Create: `backend/app/Console/Commands/SimularChoferes.php`
- Create: `backend/app/Console/Commands/PurgarRecorridos.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/ComandosTest.php`

**Interfaces:**
- Consumes: `ServicioTurnos::iniciar`, `ServicioUbicacion::registrar`, `Parametros` (Tasks 4–6).
- Produces:
  - `php artisan simular:choferes {cantidad=5} {--lat=-34.6037} {--lng=-58.3816} {--iteraciones=0} {--intervalo=5}` (0 iteraciones = infinito; prohibido en producción). Choferes simulados con `id_externo` `sim-chofer-{n}`, reutilizados entre corridas.
  - `php artisan vehiculos:purgar-recorridos`, programado a diario; borra puntos de viajes finalizados o cancelados hace más de `retencion_recorrido_dias`.

- [ ] **Step 1: Escribir tests que fallan**

`backend/tests/Feature/ComandosTest.php`:

```php
<?php

use App\Enums\EstadoViaje;
use App\Models\PuntoRecorrido;
use App\Models\Turno;
use App\Models\UbicacionChofer;
use App\Models\Usuario;
use App\Models\Viaje;

it('simula choferes en turno con ubicación', function () {
    $this->artisan('simular:choferes', ['cantidad' => 3, '--iteraciones' => 2, '--intervalo' => 0])
        ->assertSuccessful();

    expect(Usuario::where('id_externo', 'like', 'sim-chofer-%')->count())->toBe(3)
        ->and(Turno::whereNull('fin')->count())->toBe(3)
        ->and(UbicacionChofer::count())->toBe(3);
});

it('reutiliza los choferes simulados en otra corrida', function () {
    $this->artisan('simular:choferes', ['cantidad' => 2, '--iteraciones' => 1, '--intervalo' => 0]);
    $this->artisan('simular:choferes', ['cantidad' => 2, '--iteraciones' => 1, '--intervalo' => 0])->assertSuccessful();

    expect(Usuario::where('id_externo', 'like', 'sim-chofer-%')->count())->toBe(2)
        ->and(Turno::whereNull('fin')->count())->toBe(2);
});

it('purga recorridos de viajes terminados hace más de 90 días', function () {
    $viejo = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDays(91)]);
    $reciente = Viaje::factory()->create(['estado' => EstadoViaje::Finalizado, 'finalizado_en' => now()->subDays(10)]);
    foreach ([$viejo, $reciente] as $v) {
        PuntoRecorrido::create(['viaje_id' => $v->id, 'lat' => 1, 'lng' => 1, 'registrado_en' => now()]);
    }

    $this->artisan('vehiculos:purgar-recorridos')->assertSuccessful();

    expect(PuntoRecorrido::pluck('viaje_id')->all())->toBe([$reciente->id]);
});
```

- [ ] **Step 2: Correr y verificar que fallan**

Run: `./vendor/bin/pest tests/Feature/ComandosTest.php`
Expected: FAIL (`The command "simular:choferes" does not exist.`).

- [ ] **Step 3: Implementar el simulador**

`backend/app/Console/Commands/SimularChoferes.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use App\Models\Vehiculo;
use App\Servicios\ServicioTurnos;
use App\Servicios\ServicioUbicacion;
use Illuminate\Console\Command;

class SimularChoferes extends Command
{
    protected $signature = 'simular:choferes {cantidad=5} {--lat=-34.6037} {--lng=-58.3816} {--iteraciones=0} {--intervalo=5}';

    protected $description = 'Crea choferes simulados en turno y los mueve por la ciudad (solo desarrollo)';

    public function handle(ServicioTurnos $turnos, ServicioUbicacion $ubicacion): int
    {
        if (app()->isProduction()) {
            $this->error('El simulador no puede correr en producción.');

            return self::FAILURE;
        }

        $choferes = collect(range(1, (int) $this->argument('cantidad')))->map(function (int $n) use ($turnos) {
            $chofer = Usuario::firstOrCreate(
                ['id_externo' => "sim-chofer-$n"],
                ['nombre' => "Chofer simulado $n", 'cargo' => 'Chofer', 'rol' => RolUsuario::Chofer],
            );
            if (! $chofer->turnoAbierto()->exists()) {
                $vehiculo = Vehiculo::firstOrCreate(
                    ['patente' => sprintf('SIM%03d', $n)],
                    ['marca' => 'Toyota', 'modelo' => 'Corolla', 'color' => 'Blanco'],
                );
                $turnos->iniciar($chofer, $vehiculo->id);
            }

            return $chofer;
        });

        // Posición inicial aleatoria en un radio de ~3 km del centro.
        $posiciones = $choferes->mapWithKeys(fn (Usuario $c) => [$c->id => [
            (float) $this->option('lat') + mt_rand(-2700, 2700) / 100000,
            (float) $this->option('lng') + mt_rand(-2700, 2700) / 100000,
        ]])->all();

        $iteraciones = (int) $this->option('iteraciones');
        for ($i = 0; $iteraciones === 0 || $i < $iteraciones; $i++) {
            foreach ($choferes as $chofer) {
                // Paso de ~50 m por iteración.
                $posiciones[$chofer->id][0] += mt_rand(-45, 45) / 100000;
                $posiciones[$chofer->id][1] += mt_rand(-45, 45) / 100000;
                $ubicacion->registrar($chofer, [[
                    'lat' => $posiciones[$chofer->id][0],
                    'lng' => $posiciones[$chofer->id][1],
                    'rumbo' => mt_rand(0, 359),
                    'registrado_en' => now()->toIso8601String(),
                ]]);
            }
            $this->line("Iteración $i: {$choferes->count()} choferes movidos.");
            sleep((int) $this->option('intervalo'));
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Implementar la purga y programarla**

`backend/app/Console/Commands/PurgarRecorridos.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\EstadoViaje;
use App\Models\PuntoRecorrido;
use App\Models\Viaje;
use App\Servicios\Parametros;
use Illuminate\Console\Command;

class PurgarRecorridos extends Command
{
    protected $signature = 'vehiculos:purgar-recorridos';

    protected $description = 'Borra los recorridos GPS de viajes terminados según el plazo de retención';

    public function handle(Parametros $parametros): int
    {
        $limite = now()->subDays($parametros->entero('retencion_recorrido_dias'));

        $viajes = Viaje::where(fn ($q) => $q
            ->where(fn ($f) => $f->where('estado', EstadoViaje::Finalizado)->where('finalizado_en', '<', $limite))
            ->orWhere(fn ($c) => $c->where('estado', EstadoViaje::Cancelado)->where('cancelado_en', '<', $limite)))
            ->select('id');

        $borrados = PuntoRecorrido::whereIn('viaje_id', $viajes)->delete();
        $this->info("Puntos de recorrido borrados: $borrados");

        return self::SUCCESS;
    }
}
```

En `backend/routes/console.php`, agregar:

```php
\Illuminate\Support\Facades\Schedule::command('vehiculos:purgar-recorridos')->dailyAt('03:00');
```

- [ ] **Step 5: Correr tests**

Run: `./vendor/bin/pest`
Expected: todos PASS.

- [ ] **Step 6: Prueba manual de punta a punta**

En tres terminales, desde `backend/` (con `.env` apuntando a una base MySQL local y `QUEUE_CONNECTION=database`):

```bash
php artisan migrate
php artisan serve
php artisan reverb:start
php artisan queue:work
php artisan simular:choferes 5
```

Luego:

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/auth/intercambio -H 'Accept: application/json' \
  -d 'token_externo=sim|1|Prueba|Empleado' | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')
curl -s localhost:8000/api/choferes -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Expected: JSON con 5 choferes en estado `libre`, con `lat`/`lng` que cambian entre llamadas.

- [ ] **Step 7: Commit**

```bash
git add backend
git commit -m "feat: simulador de choferes y purga programada de recorridos" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Cobertura del spec en este plan

| Spec | Task |
|---|---|
| 3.2 Autenticación, 3.3 ProveedorIdentidad | 3 |
| 3.3 FuenteTurno (costura `OrigenTurno`) | 5 |
| 3.3 ServicioMapas / Notificador | 8 / 13 |
| 4 Modelo de datos | 2 |
| 4.1 Estado del chofer | 6 |
| 5.1 Máquina de estados | 7 |
| 5.2 / 5.3 Pedidos inmediatos | 8, 9, 10 |
| 5.5 Durante el viaje, recorrido | 6, 11 |
| 5.6 Cancelaciones | 11 |
| 5.7 Parámetros | 4 |
| 5.8 Concurrencia | 8, 9 |
| 6 Tiempo real | 12 |
| 9 Casos borde (señal, 401/503, idempotencia) | 3, 6, 7 |
| 10 Privacidad (ubicación solo en turno, purga) | 5, 6, 14 |
| 11 Simulador | 14 |

**Fuera de este plan (planes siguientes):** 4.2 y 5.4 Reservas (plan 2); 8 Panel admin, alertas y cancelación de obligatorios por admin (plan 3); 7 App Flutter y puente FCM del lado cliente (plan 4).

