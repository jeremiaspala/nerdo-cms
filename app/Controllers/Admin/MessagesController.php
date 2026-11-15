<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Models\Message;

/**
 * Bandeja del formulario de contacto.
 *
 * Es deliberadamente chica: ver, marcar leído, archivar, borrar. Contestar se
 * contesta desde el cliente de correo, que es donde ya está la conversación —
 * meter un editor de respuestas acá sería construir medio webmail y pedirle
 * al servidor que mande correo saliente de verdad.
 */
final class MessagesController extends AdminController
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->boot($request);

        $messages = new Message($this->app->db);

        $status = $request->get('estado');
        $status = in_array($status, Message::STATUSES, true) ? $status : null;

        $page = $request->page();
        $total = $messages->adminCount($status);

        return $this->render('messages', [
            'current' => 'messages',
            'pageTitle' => 'Mensajes',
            'status' => $status,
            'byStatus' => $messages->countsByStatus(),
            'items' => $messages->adminList($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    public function act(Request $request): Response
    {
        $this->boot($request);

        $messages = new Message($this->app->db);
        $ids = $this->ids($request);
        $action = $request->post('accion');
        $back = '/mensajes' . ($request->post('volver_a') !== '' ? '?estado=' . $request->post('volver_a') : '');

        if ($ids === []) {
            return $this->back($back, 'No marcaste ninguno.', 'error');
        }

        if ($action === 'borrar') {
            $n = $messages->delete($ids);
            $this->auth->audit(
                (int) $this->user['id'],
                'message.delete',
                'messages:' . implode(',', array_slice($ids, 0, 20)),
                $request
            );

            return $this->back($back, $n === 1 ? 'Mensaje borrado.' : "{$n} mensajes borrados.");
        }

        $labels = [
            Message::READ => ['marcado como leído', 'marcados como leídos'],
            Message::NEW => ['marcado como nuevo', 'marcados como nuevos'],
            Message::ARCHIVED => ['archivado', 'archivados'],
            Message::SPAM => ['marcado como spam', 'marcados como spam'],
        ];

        if (!isset($labels[$action])) {
            return $this->back($back, 'Acción desconocida.', 'error');
        }

        $n = $messages->setStatus($ids, $action);

        return $this->back($back, $n === 1 ? ucfirst($labels[$action][0]) . '.' : "{$n} " . $labels[$action][1] . '.');
    }

    /** Vaciar la bandeja de spam de una sola vez. */
    public function emptySpam(Request $request): Response
    {
        $this->boot($request);

        $n = (new Message($this->app->db))->purgeSpam();
        $this->auth->audit((int) $this->user['id'], 'message.empty', 'spam:' . $n, $request);

        return $this->back('/mensajes?estado=spam', "{$n} borrados definitivamente.");
    }

    /** @return array<int,int> */
    private function ids(Request $request): array
    {
        $raw = $request->body['ids'] ?? [];

        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('intval', $raw), static fn (int $id): bool => $id > 0));
    }
}
