<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;

final class ArchiveController extends Controller
{
    /** Múltiplo de 12, por lo mismo que el resto: la grilla cierra. */
    private const PER_PAGE = 36;

    private const MESES = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    public function show(Request $request, string $year, string $month = ''): Response
    {
        $y = (int) $year;
        $m = $month === '' ? null : (int) $month;

        if ($y < 2000 || $y > (int) date('Y') + 1) {
            throw new NotFound();
        }
        if ($m !== null && ($m < 1 || $m > 12)) {
            throw new NotFound();
        }

        $page = $request->page();
        $items = $this->posts->byYearMonth($y, $m, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        if ($items === [] && $page === 1) {
            throw new NotFound();
        }

        $items = $this->posts->withCategories($items);

        $this->app->analytics->record($request);

        $label = $m === null ? (string) $y : self::MESES[$m] . ' de ' . $y;

        $data = $this->base($request, [
            'title' => $this->title('Archivo de ' . $label),
            'description' => 'Entradas publicadas en ' . $label . '.',
            'canonical' => $this->app->url('/archivo/' . $year . ($month !== '' ? '/' . $month : '')),
        ]);

        return $this->view('index', $data + [
            'posts' => $items,
            'page' => $page,
            'pages' => $page + (count($items) === self::PER_PAGE ? 1 : 0),
            'total' => count($items),
            'heading' => [
                'eyebrow' => 'Archivo',
                'title' => ucfirst($label),
                'description' => '',
            ],
        ]);
    }
}
