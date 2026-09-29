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
