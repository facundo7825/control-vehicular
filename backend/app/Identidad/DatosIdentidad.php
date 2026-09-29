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
