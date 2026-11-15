<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Models\AuditLog;

/**
 * La bitácora, con filtros.
 *
 * Es de solo lectura desde la web, a propósito: lo único que la toca es la
 * limpieza por antigüedad, que corre por consola.
 */
final class AuditController extends AdminController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $this->boot($request);

        $log = new AuditLog($this->app->db);

        $grupo = (string) $request->get('grupo', '');
        $grupos = array_values(array_unique(array_column(AuditLog::ACCIONES, 1)));

        $filtros = [
            'grupo' => in_array($grupo, $grupos, true) ? $grupo : '',
            'usuario' => (int) $request->get('usuario', '0'),
            'desde' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->get('desde', ''))
                ? (string) $request->get('desde')
                : '',
        ];

        $page = $request->page();
        $total = $log->count($filtros);

        return $this->render('audit', [
            'current' => 'audit',
            'pageTitle' => 'Bitácora',
            'items' => $log->list($filtros, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'grupos' => $grupos,
            'filtros' => $filtros,
            'usuarios' => $this->app->db->select('SELECT id, name FROM users ORDER BY name'),
            'fallidos' => $log->recentFailures(),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }
}
