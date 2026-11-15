<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;

final class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $term = mb_substr(trim((string) $request->get('q', '')), 0, 120);

        // La búsqueda toca FULLTEXT, así que tiene su propio límite.
        if ($term !== '') {
            $this->app->limiter->hit('search', $request->ipHash, max: 30, perSeconds: 60);
        }

        $items = $term === '' ? [] : $this->posts->search($term, 40);
        foreach ($items as $i => $post) {
            $items[$i]['categories'] = $this->posts->categoriesOf((int) $post['id']);
        }

        $data = $this->base($request, [
            'title' => $this->title($term === '' ? 'Buscar' : 'Buscar: ' . $term),
            'description' => '',
            // Una página de resultados nunca va al índice.
            'noindex' => true,
        ]);

        return $this->view('index', $data + [
            'posts' => $items,
            'page' => 1,
            'pages' => 1,
            'total' => count($items),
            'searchTerm' => $term,
            'heading' => [
                'eyebrow' => 'Búsqueda',
                'title' => $term === '' ? 'Buscar en el blog' : count($items) . ' resultado' . (count($items) === 1 ? '' : 's'),
                'description' => $term === '' ? '' : 'Para "' . $term . '".',
            ],
        ]);
    }
}
