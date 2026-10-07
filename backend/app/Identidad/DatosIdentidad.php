<?php

namespace App\Identidad;

final readonly class DatosIdentidad
{
    public function __construct(
        public string $idExterno,
        public string $nombre,
        public ?string $cargo,
        public ?string $telefono = null,
        // Solo si se configuró IDENTIDAD_CAMPO_DEPENDENCIA; null: no la informa.
        public ?string $dependencia = null,
    ) {}
}
