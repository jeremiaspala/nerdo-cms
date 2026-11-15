<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;

final class PostController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $post = $this->posts->findBySlug($slug);

        if ($post === null) {
            // Que no exista como entrada no cierra el tema: el router todavía
            // va a buscar en redirects antes de dar 404. Pero acá ya estamos
            // dentro del controlador, así que el 404 lo tiramos nosotros.
            throw new NotFound();
        }

        $categories = $this->posts->categoriesOf((int) $post['id']);

        $data = $this->base($request, [
            'title' => $this->title((string) ($post['meta_title'] ?: $post['title'])),
            'description' => (string) ($post['meta_description'] ?: $post['excerpt']),
            'canonical' => $post['canonical'] ?: $this->app->url('/' . $post['slug']),
            'noindex' => (bool) $post['noindex'],
            'type' => 'article',
            'published' => $post['published_at'],
            'modified' => $post['updated_at'],
            // La tarjeta que se ve cuando alguien pega el link en un chat.
            'image' => empty($post['image'])
                ? null
                : $this->app->url('/uploads/' . $post['image']),
        ]);

        $url = (string) $data['seo']['canonical'];

        $data['jsonld'][] = \App\Services\Seo::article(
            $post,
            $categories,
            (string) $data['site']['title'],
            $url,
            $data['seo']['image'],
            'Jeremías Palazzesi'
        );

        $crumbs = [['name' => 'Inicio', 'url' => $this->app->url('/')]];
        if ($categories !== []) {
            $crumbs[] = [
                'name' => (string) $categories[0]['name'],
                'url' => $this->app->url('/categoria/' . $categories[0]['slug']),
            ];
        }
        $crumbs[] = ['name' => (string) $post['title'], 'url' => $url];

        $data['jsonld'][] = \App\Services\Seo::breadcrumbs($crumbs);

        // La visita se registra acá, donde ya sabemos de qué entrada se trata.
        $this->app->analytics->record($request, (int) $post['id']);

        $comments = new \App\Models\Comment($this->app->db);
        $thread = $comments->threadFor((int) $post['id']);
        [$open, $closedReason] = $this->commentState($post);

        return $this->view('post', $data + [
            'post' => $post,
            'categories' => $categories,
            'prev' => $this->posts->adjacent((string) $post['published_at'], 'prev'),
            'next' => $this->posts->adjacent((string) $post['published_at'], 'next'),
            'comments' => $thread,
            'commentsCount' => count($thread),
            'commentsOpen' => $open,
            'commentsClosedReason' => $closedReason,
            // El tema carga comments.js solo si hay formulario que atender.
            'needsComments' => $open,
            // Cómo se identifica quien comenta, y si hay botón de Google.
            'identidad' => (string) $this->app->settings->get('comments_identidad', 'ambos'),
            'comentarGoogle' => $open
                && $this->app->settings->bool('google_enabled', false)
                && $this->app->settings->bool('google_comentarios', true)
                && (new \App\Services\GoogleOAuth($this->app))->configurado()
                && $this->app->settings->get('comments_identidad', 'ambos') !== 'anonimo',
        ]);
    }

    /**
     * Si la entrada acepta comentarios y, si no, por qué.
     *
     * Son tres candados y cada uno tiene su motivo: el global por si hay que
     * apagar todo de golpe, el de la entrada para las notas que no se discuten,
     * y el de antigüedad porque el spam de enlaces vive en las entradas viejas.
     *
     * @return array{0:bool,1:?string}
     */
    private function commentState(array $post): array
    {
        if (!$this->app->settings->bool('comments_enabled', true)) {
            return [false, 'Los comentarios están cerrados en todo el sitio.'];
        }

        if ((int) $post['comments_open'] !== 1) {
            return [false, 'Los comentarios de esta entrada están cerrados.'];
        }

        $days = max(0, (int) $this->app->settings->get('comments_open_days', '90'));

        if (!CommentController::withinOpenWindow((string) $post['published_at'], $days)) {
            return [false, "Esta entrada tiene más de {$days} días y ya no acepta comentarios nuevos."];
        }

        return [true, null];
    }
}
