<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;
use App\Models\Post;

final class PageController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $page = $this->posts->findBySlug($slug, Post::TYPE_PAGE);

        if ($page === null) {
            throw new NotFound();
        }

        $this->app->analytics->record($request, (int) $page['id']);

        $data = $this->base($request, [
            'title' => $this->title((string) ($page['meta_title'] ?: $page['title'])),
            'description' => (string) ($page['meta_description'] ?: $page['excerpt']),
            'canonical' => $page['canonical'] ?: $this->app->url('/p/' . $page['slug']),
            'noindex' => (bool) $page['noindex'],
        ]);

        // El formulario de contacto va en la página que diga el ajuste, no en
        // una ruta propia: así conserva la URL que ya estaba indexada y el
        // texto de arriba se edita desde el panel como cualquier página.
        $contact = $this->app->settings->bool('contact_enabled', true)
            && $page['slug'] === trim((string) $this->app->settings->get('contact_page', 'contacto'), '/');

        return $this->view('page', $data + [
            'page' => $page,
            'contactForm' => $contact,
            'contactIntro' => $contact ? (string) $this->app->settings->get('contact_intro', '') : '',
            'needsContact' => $contact,
        ]);
    }
}
