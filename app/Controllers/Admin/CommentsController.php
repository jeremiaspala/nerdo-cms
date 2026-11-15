<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Models\Comment;

/**
 * Cola de moderación.
 *
 * Todas las acciones son POST con token CSRF (lo exige AdminController::boot)
 * y aceptan varios ids: moderar de a uno con 40 pendientes es la forma más
 * rápida de que alguien apruebe sin leer.
 */
final class CommentsController extends AdminController
{
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        $this->boot($request);

        $comments = new Comment($this->app->db);

        $status = $request->get('estado');
        $status = in_array($status, [Comment::PENDING, Comment::APPROVED, Comment::SPAM, Comment::TRASH], true)
            ? $status
            : Comment::PENDING;

        $page = $request->page();
        $total = $comments->adminCount($status);

        return $this->render('comments', [
            'current' => 'comments',
            'pageTitle' => 'Comentarios',
            'status' => $status,
            // Va aparte de $counts, que es el de la barra de navegación.
            'byStatus' => $comments->countsByStatus(),
            'items' => $comments->adminList($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    /**
     * Cambio de estado, de uno o de varios.
     *
     * La acción viaja en el formulario pero se valida contra la lista de
     * estados del modelo: un valor que no esté ahí no hace nada.
     */
    public function moderate(Request $request): Response
    {
        $this->boot($request);

        $comments = new Comment($this->app->db);
        $ids = $this->ids($request);
        $action = $request->post('accion');
        $back = '/comentarios?estado=' . ($request->post('volver_a') ?: Comment::PENDING);

        if ($ids === []) {
            return $this->back($back, 'No marcaste ninguno.', 'error');
        }

        // Antes de tocar nada: después de un borrado definitivo ya no hay
        // forma de saber a qué entradas pertenecían.
        $paths = $comments->pathsFor($ids);

        if ($action === 'purgar') {
            $n = $comments->purge($ids);
            $this->audit($request, 'comment.purge', $ids);
            $this->purgeCache($paths);

            return $this->back($back, $n === 1 ? 'Borrado definitivamente.' : "{$n} borrados definitivamente.");
        }

        $labels = [
            Comment::APPROVED => ['aprobado', 'aprobados'],
            Comment::PENDING => ['devuelto a moderación', 'devueltos a moderación'],
            Comment::SPAM => ['marcado como spam', 'marcados como spam'],
            Comment::TRASH => ['mandado a la papelera', 'mandados a la papelera'],
        ];

        if (!isset($labels[$action])) {
            return $this->back($back, 'Acción desconocida.', 'error');
        }

        $n = $comments->setStatus($ids, $action);
        $this->audit($request, 'comment.' . $action, $ids);
        $this->purgeCache($paths);

        return $this->back($back, $n === 1 ? ucfirst($labels[$action][0]) . '.' : "{$n} " . $labels[$action][1] . '.');
    }

    /** Vaciar spam o papelera de una vez. */
    public function empty(Request $request): Response
    {
        $this->boot($request);

        $status = $request->post('estado');

        if (!in_array($status, [Comment::SPAM, Comment::TRASH], true)) {
            return $this->back('/comentarios', 'Solo se puede vaciar spam o papelera.', 'error');
        }

        $comments = new Comment($this->app->db);
        $paths = $comments->pathsFor(array_column(
            $this->app->db->select('SELECT id FROM comments WHERE status = ?', [$status]),
            'id'
        ));

        $n = $comments->purgeAll($status);
        $this->auth->audit((int) $this->user['id'], 'comment.empty', $status . ':' . $n, $request);
        $this->purgeCache($paths);

        return $this->back('/comentarios?estado=' . $status, "{$n} borrados definitivamente.");
    }

    // ------------------------------------------------------------------

    /** @return array<int,int> */
    private function ids(Request $request): array
    {
        $raw = $request->body['ids'] ?? [];

        // Un solo id también llega por acá: los botones de cada fila mandan
        // ids[] con un único valor, así no hay dos caminos para lo mismo.
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_filter(array_map('intval', $raw), static fn (int $id): bool => $id > 0));
    }

    /**
     * Saca del caché las entradas afectadas.
     *
     * Moderar es mucho más frecuente que publicar, y un comentario aprobado
     * solo cambia la página de su entrada: vaciar el caché entero cada vez
     * sería tirar las 150 páginas por una.
     *
     * @param array<int,string> $paths
     */
    private function purgeCache(array $paths): void
    {
        foreach ($paths as $path) {
            $this->app->pages->purge($path);
        }
    }

    private function audit(Request $request, string $action, array $ids): void
    {
        $this->auth->audit(
            (int) $this->user['id'],
            $action,
            'comments:' . implode(',', array_slice($ids, 0, 20)),
            $request
        );
    }
}
