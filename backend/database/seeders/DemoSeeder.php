<?php

namespace Database\Seeders;

use App\Enums\RolUsuario;
use App\Models\CargoPrioritario;
use App\Models\Usuario;
use App\Models\Vehiculo;
use Illuminate\Database\Seeder;

/**
 * Datos para una demo local (solo desarrollo): vehículos, el cargo "Juez" como obligatorio, un admin del
 * panel y los perfiles del login falso de host_prueba ya creados (Carlos ya es chofer).
 *
 *   php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * La contraseña del admin sale de DEMO_ADMIN_PASSWORD (por defecto "demo1234").
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['patente' => 'AB123CD', 'marca' => 'Toyota', 'modelo' => 'Etios', 'color' => 'Blanco'],
            ['patente' => 'AC456EF', 'marca' => 'Volkswagen', 'modelo' => 'Voyage', 'color' => 'Gris'],
            ['patente' => 'AD789GH', 'marca' => 'Chevrolet', 'modelo' => 'Cruze', 'color' => 'Negro'],
        ] as $vehiculo) {
            Vehiculo::updateOrCreate(['patente' => $vehiculo['patente']], $vehiculo + ['activo' => true]);
        }

        CargoPrioritario::updateOrCreate(['cargo' => 'Juez'], ['obligatorio' => true]);

        Usuario::updateOrCreate(['email' => 'admin@demo.local'], [
            'id_externo' => 'panel:admin@demo.local',
            'nombre' => 'Administración',
            'rol' => RolUsuario::Admin,
            'activo' => true,
            'password' => env('DEMO_ADMIN_PASSWORD', 'demo1234'),
        ]);

        // Los mismos perfiles que ofrece el login falso de host_prueba (lib/login_falso.dart).
        foreach ([
            ['id_externo' => '100', 'nombre' => 'Ana Pérez', 'cargo' => 'Secretaria', 'rol' => RolUsuario::Solicitante],
            ['id_externo' => '101', 'nombre' => 'Jorge Juez', 'cargo' => 'Juez', 'rol' => RolUsuario::Solicitante],
            ['id_externo' => '200', 'nombre' => 'Carlos Chofer', 'cargo' => 'Chofer', 'rol' => RolUsuario::Chofer,
                'telefono' => '3834000000'],
        ] as $usuario) {
            Usuario::updateOrCreate(['id_externo' => $usuario['id_externo']], $usuario + ['activo' => true]);
        }
    }
}
