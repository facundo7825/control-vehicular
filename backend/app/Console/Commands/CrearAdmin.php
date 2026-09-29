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
