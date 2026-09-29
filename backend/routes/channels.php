<?php

use App\Enums\RolUsuario;
use App\Models\Usuario;
use App\Models\Viaje;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('mapa.choferes', fn (Usuario $u) => $u->activo);

Broadcast::channel('viaje.{viaje}', fn (Usuario $u, Viaje $viaje) => $u->rol === RolUsuario::Admin
    || in_array($u->id, [$viaje->solicitante_id, $viaje->chofer_id], true));

Broadcast::channel('chofer.{id}', fn (Usuario $u, int $id) => $u->id === $id || $u->rol === RolUsuario::Admin);
