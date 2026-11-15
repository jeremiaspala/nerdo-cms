<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;

final class HomeController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): Response
    {
        $page = $request->page();
        $total = $this->posts->countPublished();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        if ($page > $pages && $total > 0) {
            throw new NotFound();
        }

        $items = $this->posts->published(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $this->app->analytics->record($request);

        $items = $this->posts->withCategories($items);

        $data = $this->base($request, [
            'title' => $page > 1
                ? $this->title('Página ' . $page)
                : (string) $this->app->settings->get('site_title', 'Nerdadas!'),
            // Las paginadas no se indexan: son contenido duplicado.
            'noindex' => $page > 1,
        ]);

        return $this->view('index', $data + [
            'posts' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'heading' => null,
        ]);
    }
}
