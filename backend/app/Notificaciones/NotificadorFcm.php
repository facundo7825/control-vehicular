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
