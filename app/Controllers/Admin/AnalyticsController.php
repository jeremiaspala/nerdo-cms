<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;

final class AnalyticsController extends AdminController
{
    private const RANGES = [7 => '7 días', 30 => '30 días', 90 => '90 días', 365 => '1 año'];

    public function index(Request $request): Response
    {
        $this->boot($request);

        $days = (int) ($request->get('dias') ?? 30);
        $days = isset(self::RANGES[$days]) ? $days : 30;

        $a = $this->app->analytics;
        $daily = $a->daily($days);

        return $this->render('analytics', [
            'current' => 'analytics',
            'pageTitle' => 'Analítica',
            'days' => $days,
            'ranges' => self::RANGES,
            'summary' => $a->summary($days),
            'daily' => $daily,
            'chart' => $this->chart($daily),
            'topPosts' => $a->topPosts($days, 12),
            'referers' => $a->topReferers($days, 10),
            'devices' => $a->breakdown('device', $days),
            'browsers' => $a->breakdown('browser', $days),
            'enabled' => $this->app->settings->bool('analytics_enabled', true),
            'retention' => (int) $this->app->settings->get('analytics_retention_days', '30'),
        ]);
    }

    /**
     * Geometría del gráfico, calculada en PHP.
     *
     * Se arma acá y no con una librería de JavaScript por lo mismo de
     * siempre: una librería de gráficos son cientos de kilobytes de código
     * de terceros corriendo en el panel, y acá alcanza con dos rutas SVG.
     *
     * Las dos series comparten eje porque miden lo mismo —cuentas de
     * visitas— en la misma unidad. Un segundo eje haría que dos escalas
     * distintas se vieran comparables, que es la forma más común de mentir
     * con un gráfico.
     */
    private function chart(array $daily): array
    {
        $w = 760;
        $h = 190;
        $padL = 34;
        $padB = 22;
        $padT = 10;

        $max = 0;
        foreach ($daily as $d) {
            $max = max($max, (int) $d['vistas'], (int) $d['visitantes']);
        }

        // Techo "redondo" para que las 4 divisiones del eje caigan en
        // números legibles. Sin esto el eje queda en 38 / 75 / 113 / 150,
        // que es exacto y no lo lee nadie.
        $step = 1;
        foreach ([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000] as $candidate) {
            $step = $candidate;
            if ($candidate * 4 >= $max) {
                break;
            }
        }
        $top = $step * 4;

        $n = max(1, count($daily) - 1);
        $innerW = $w - $padL - 8;
        $innerH = $h - $padB - $padT;

        $x = static fn (int $i): float => round($padL + ($i / $n) * $innerW, 2);
        $y = static fn (int $v): float => round($padT + $innerH - ($v / max(1, $top)) * $innerH, 2);

        $points = [];
        $lineViews = [];
        $lineVisitors = [];

        foreach ($daily as $i => $d) {
            $px = $x($i);
            $lineViews[] = $px . ',' . $y((int) $d['vistas']);
            $lineVisitors[] = $px . ',' . $y((int) $d['visitantes']);

            $points[] = [
                'x' => $px,
                'yv' => $y((int) $d['vistas']),
                'yu' => $y((int) $d['visitantes']),
                'dia' => (string) $d['dia'],
                'vistas' => (int) $d['vistas'],
                'visitantes' => (int) $d['visitantes'],
            ];
        }

        $area = 'M' . $x(0) . ',' . ($padT + $innerH)
            . ' L' . implode(' L', $lineViews)
            . ' L' . $x(count($daily) - 1) . ',' . ($padT + $innerH) . ' Z';

        // Cuatro líneas de referencia, discretas.
        $grid = [];
        for ($i = 0; $i <= 4; $i++) {
            $value = (int) round($top * $i / 4);
            $grid[] = ['y' => $y($value), 'label' => $value];
        }

        return [
            'w' => $w, 'h' => $h, 'padL' => $padL, 'padT' => $padT, 'innerH' => $innerH,
            'top' => $top,
            'area' => $area,
            'views' => 'M' . implode(' L', $lineViews),
            'visitors' => 'M' . implode(' L', $lineVisitors),
            'grid' => $grid,
            'points' => $points,
            'bandW' => round($innerW / max(1, count($daily)), 2),
        ];
    }
}
