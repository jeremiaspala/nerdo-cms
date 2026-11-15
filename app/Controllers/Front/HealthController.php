<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * Comprobación de vida, para el despliegue y para el monitoreo.
 * No expone versiones ni rutas: solo dice si la base responde.
 */
final class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        try {
            $this->app->db->value('SELECT 1');
            $db = 'ok';
        } catch (\Throwable) {
            $db = 'error';
        }

        return Response::json(['status' => $db === 'ok' ? 'ok' : 'degraded', 'db' => $db],
            $db === 'ok' ? 200 : 503);
    }
}
