<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Models\Category;
use App\Models\Post;

/**
 * Base de todo el panel.
 *
 * Dos cosas pasan acá y no se repiten en ningún controlador hijo: la
 * verificación de sesión y la del token CSRF. Si alguna vez hay que auditar
 * quién puede hacer qué, se audita este archivo.
 */
abstract class AdminController
{
    protected Auth $auth;
    protected Post $posts;
    protected Category $categories;
    protected array $user;

    public function __construct(protected readonly Kernel $app)
    {
        $this->auth = new Auth($app);
        $this->posts = new Post($app->db);
        $this->categories = new Category($app->db);
    }

    /** Llamar al principio de cada acción del panel. */
    protected function boot(Request $request): void
    {
        $this->user = $this->auth->requireAdmin($request);

        // En POST, sin token válido no se sigue.
        $this->app->csrf->check($request);
    }

    protected function render(string $template, array $data = []): Response
    {
        // Ayudantes de presentación del panel (íconos, estado de una entrada).
        // Va acá y no en el layout porque las plantillas hijas se renderizan
        // antes que él y también los usan.
        require_once $this->app->root . '/app/Admin/ui.php';

        return Response::html($this->app->adminView->page($template, $data + [
            // Lo que la plantilla hija defina en su propio ámbito no llega al
            // layout, así que estas dos se deciden acá.
            'pageTitle' => $data['pageTitle'] ?? 'Panel',
            'needsEditor' => $template === 'content-edit',
            'user' => $this->user,
            'adminPath' => $this->app->adminPath,
            // Para armar el enlace público de una entrada desde el editor.
            'siteUrl' => rtrim($this->app->env->get('APP_URL', '') ?? '', '/'),
            'nonce' => $this->app->security->nonce,
            'csrf' => $this->app->csrf->field(),
            'flash' => $this->app->session->takeFlash(),
            'counts' => [
                'posts' => $this->posts->adminCount(Post::TYPE_POST),
                'pages' => $this->posts->adminCount(Post::TYPE_PAGE),
                'drafts' => $this->posts->adminCount(Post::TYPE_POST, Post::STATUS_DRAFT),
                // Los pendientes van en la barra de todas las pantallas: una
                // cola de moderación que hay que ir a buscar no se mira.
                'pending' => (new \App\Models\Comment($this->app->db))->adminCount(\App\Models\Comment::PENDING),
                'messages' => (new \App\Models\Message($this->app->db))->adminCount(\App\Models\Message::NEW),
            ],
            'current' => $data['current'] ?? '',
        ]));
    }

    protected function back(string $path, string $message = '', string $type = 'ok'): Response
    {
        if ($message !== '') {
            $this->app->session->flash($message, $type);
        }

        return Response::redirect($this->app->adminPath . $path);
    }
}
