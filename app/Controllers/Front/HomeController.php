<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        // La primera página gasta una entrada en la destacada de arriba, así
        // que pide una de más: si no, la grilla de abajo queda con un hueco
        // justo en la página que ve todo el mundo.
        $cards = $this->cardsPerPage();
        $firstPage = $cards + 1;

        $page = $request->page();
        $total = $this->posts->countPublished();
        $pages = $total <= $firstPage
            ? 1
            : 1 + (int) ceil(($total - $firstPage) / $cards);

        if ($page > $pages && $total > 0) {
            throw new NotFound();
        }

        $items = $page === 1
            ? $this->posts->published($firstPage)
            : $this->posts->published($cards, $firstPage + ($page - 2) * $cards);
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
