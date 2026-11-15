<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;

final class CategoryController extends Controller
{
    private const PER_PAGE = 10;

    public function show(Request $request, string $slug): Response
    {
        $category = $this->categories->findBySlug($slug);

        if ($category === null) {
            throw new NotFound();
        }

        $page = $request->page();
        $total = $this->categories->countPublished((int) $category['id']);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        $items = $this->posts->byCategory($slug, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $this->app->analytics->record($request);
        $items = $this->posts->withCategories($items);

        $data = $this->base($request, [
            'title' => $this->title((string) ($category['meta_title'] ?: $category['name'])),
            'description' => (string) ($category['meta_description'] ?: $category['description']),
            'canonical' => $this->app->url('/categoria/' . $category['slug']),
            'noindex' => $page > 1,
        ]);

        return $this->view('index', $data + [
            'posts' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'heading' => [
                'eyebrow' => 'Categoría',
                'title' => (string) $category['name'],
                'description' => (string) $category['description'],
            ],
        ]);
    }
}
