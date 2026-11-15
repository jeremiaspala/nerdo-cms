<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;
use App\Models\Media;
use App\Models\Post;
use App\Services\HtmlSanitizer;

/**
 * Entradas y páginas: listar, crear, editar, publicar, borrar.
 *
 * El tipo (post / page) viaja por la ruta, no por el formulario: así no hay
 * forma de convertir una cosa en otra mandando un campo distinto.
 */
final class ContentController extends AdminController
{
    private const PER_PAGE = 25;

    public function index(Request $request, string $type): Response
    {
        $this->boot($request);
        $type = $this->normalizeType($type);

        $status = $request->get('estado');
        $status = in_array($status, [Post::STATUS_DRAFT, Post::STATUS_PUBLISHED, 'trash'], true)
            ? $status
            : null;

        $page = $request->page();
        $total = $this->posts->adminCount($type, $status);

        return $this->render('content-list', [
            'current' => $type,
            'type' => $type,
            'status' => $status,
            'items' => $this->posts->adminList($type, $status, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
        ]);
    }

    public function create(Request $request, string $type): Response
    {
        $this->boot($request);
        $type = $this->normalizeType($type);

        return $this->render('content-edit', [
            'current' => $type,
            'type' => $type,
            'post' => [
                'id' => 0,
                'title' => '',
                'slug' => '',
                'excerpt' => '',
                'body_html' => '',
                'status' => Post::STATUS_DRAFT,
                'published_at' => date('Y-m-d H:i'),
                'meta_title' => '',
                'meta_description' => '',
                'canonical' => '',
                'noindex' => 0,
                'comments_open' => 1,
                'featured_media_id' => null,
            ],
            'selectedCategories' => [],
            'allCategories' => $this->categories->allWithCounts(0),
            'featured' => null,
            'library' => (new Media($this->app->db))->recent(24),
        ]);
    }

    public function edit(Request $request, string $type, string $id): Response
    {
        $this->boot($request);
        $type = $this->normalizeType($type);

        $post = $this->posts->findForEdit((int) $id);
        if ($post === null || $post['type'] !== $type) {
            throw new NotFound();
        }

        return $this->render('content-edit', [
            'current' => $type,
            'type' => $type,
            'post' => $post,
            'selectedCategories' => $this->posts->categoryIdsOf((int) $post['id']),
            'allCategories' => $this->categories->allWithCounts(0),
            'featured' => $post['featured_media_id']
                ? (new Media($this->app->db))->find((int) $post['featured_media_id'])
                : null,
            'library' => (new Media($this->app->db))->recent(24),
        ]);
    }

    public function save(Request $request, string $type): Response
    {
        $this->boot($request);
        $type = $this->normalizeType($type);

        $id = $request->int('id');
        $title = mb_substr($request->post('title'), 0, 300);

        if ($title === '') {
            return $this->back($this->listPath($type), 'El título no puede quedar vacío.', 'error');
        }

        // ---- El saneado: única barrera real contra lo que mande el editor.
        $sanitizer = new HtmlSanitizer($this->app->root . '/storage/cache/purifier');
        $bodyHtml = $sanitizer->post($request->postRaw('body_html'));
        $bodyText = HtmlSanitizer::toText($bodyHtml);

        $status = $request->post('status') === Post::STATUS_PUBLISHED
            ? Post::STATUS_PUBLISHED
            : Post::STATUS_DRAFT;

        $publishedAt = $this->parseDate($request->post('published_at'));

        $excerpt = $request->post('excerpt');
        if ($excerpt === '') {
            $excerpt = mb_substr($bodyText, 0, 180);
        }

        $data = [
            'title' => $title,
            'excerpt' => mb_substr($excerpt, 0, 500),
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'status' => $status,
            'published_at' => $status === Post::STATUS_PUBLISHED ? $publishedAt : null,
            'meta_title' => mb_substr($request->post('meta_title'), 0, 190),
            'meta_description' => mb_substr($request->post('meta_description'), 0, 320),
            'canonical' => mb_substr($request->post('canonical'), 0, 400),
            'noindex' => $request->post('noindex') === '1' ? 1 : 0,
            'comments_open' => $request->post('comments_open') === '1' ? 1 : 0,
            // La destacada se valida contra la base: un id inventado queda en
            // NULL en vez de dejar una referencia rota.
            'featured_media_id' => $this->validMediaId($request->int('featured_media_id')),
            'reading_minutes' => HtmlSanitizer::readingMinutes($bodyText),
            'word_count' => count(preg_split('/\s+/u', $bodyText, -1, PREG_SPLIT_NO_EMPTY) ?: []),
        ];

        $requestedSlug = $request->post('slug');
        $baseSlug = slugify($requestedSlug !== '' ? $requestedSlug : $title);

        if ($id > 0) {
            $existing = $this->posts->findForEdit($id);
            if ($existing === null || $existing['type'] !== $type) {
                throw new NotFound();
            }

            $data['slug'] = $this->posts->uniqueSlug($baseSlug, $type, $id);

            // Si el slug cambió y la entrada ya estaba publicada, se deja un
            // 301 del viejo al nuevo. Es lo que evita perder posicionamiento
            // por una corrección de título.
            if ($existing['slug'] !== $data['slug'] && $existing['status'] === Post::STATUS_PUBLISHED) {
                $this->createRedirect($type, (string) $existing['slug'], $data['slug']);
            }

            $this->posts->update($id, $data);
            $this->auth->audit((int) $this->user['id'], 'content.update', $type . '#' . $id, $request);
        } else {
            $data['type'] = $type;
            $data['slug'] = $this->posts->uniqueSlug($baseSlug, $type);
            $data['author_id'] = (int) $this->user['id'];

            $id = $this->posts->create($data);
            $this->auth->audit((int) $this->user['id'], 'content.create', $type . '#' . $id, $request);
        }

        if ($type === Post::TYPE_POST) {
            $this->posts->setCategories($id, (array) ($request->body['categories'] ?? []));
        }

        // Lo guardado tiene que verse ya. El caché se vacía entero: una
        // entrada aparece además en la portada, en su categoría, en el
        // archivo del mes y en el feed.
        $this->app->pages->purgeAll();

        return $this->back(
            $this->editPath($type, $id),
            $status === Post::STATUS_PUBLISHED ? 'Publicado.' : 'Guardado como borrador.'
        );
    }

    public function trash(Request $request, string $type): Response
    {
        $this->boot($request);
        $type = $this->normalizeType($type);

        $id = $request->int('id');
        $post = $this->posts->findForEdit($id);

        if ($post === null || $post['type'] !== $type) {
            throw new NotFound();
        }

        // A papelera, no borrado definitivo: lo segundo necesita otra acción
        // explícita y no se hace desde un listado.
        $this->posts->update($id, ['status' => 'trash']);
        $this->auth->audit((int) $this->user['id'], 'content.trash', $type . '#' . $id, $request);
        $this->app->pages->purgeAll();

        return $this->back($this->listPath($type), 'Mandado a la papelera.');
    }

    public function restore(Request $request, string $type): Response
    {
        $this->boot($request);
        $type = $this->normalizeType($type);

        $id = $request->int('id');
        $post = $this->posts->findForEdit($id);

        if ($post === null || $post['type'] !== $type) {
            throw new NotFound();
        }

        $this->posts->update($id, ['status' => Post::STATUS_DRAFT]);
        $this->auth->audit((int) $this->user['id'], 'content.restore', $type . '#' . $id, $request);
        $this->app->pages->purgeAll();

        return $this->back($this->listPath($type) . '?estado=draft', 'Restaurado como borrador.');
    }

    // ------------------------------------------------------------------

    private function validMediaId(int $id): ?int
    {
        if ($id <= 0) {
            return null;
        }

        return (new Media($this->app->db))->find($id) === null ? null : $id;
    }

    private function normalizeType(string $type): string
    {
        return $type === 'paginas' ? Post::TYPE_PAGE : Post::TYPE_POST;
    }

    private function segment(string $type): string
    {
        return $type === Post::TYPE_PAGE ? 'paginas' : 'entradas';
    }

    private function listPath(string $type): string
    {
        return '/' . $this->segment($type);
    }

    private function editPath(string $type, int $id): string
    {
        return '/' . $this->segment($type) . '/' . $id;
    }

    private function parseDate(string $value): string
    {
        $ts = strtotime($value);

        return date('Y-m-d H:i:s', $ts === false ? time() : $ts);
    }

    private function createRedirect(string $type, string $oldSlug, string $newSlug): void
    {
        $prefix = $type === Post::TYPE_PAGE ? '/p/' : '/';

        $this->app->db->run(
            'INSERT INTO redirects (from_path, to_path, code) VALUES (:from, :to, 301)
             ON DUPLICATE KEY UPDATE to_path = VALUES(to_path)',
            ['from' => $prefix . $oldSlug, 'to' => $prefix . $newSlug]
        );
    }
}
