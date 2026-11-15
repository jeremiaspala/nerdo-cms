<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Post;

final class SitemapController extends Controller
{
    public function index(Request $request): Response
    {
        $urls = [];

        $urls[] = ['loc' => $this->app->url('/'), 'priority' => '1.0', 'freq' => 'daily'];

        // Entradas: lastmod real, que es lo que miran los buscadores para
        // decidir si vale la pena volver a pasar.
        foreach ($this->app->db->select(
            "SELECT slug, updated_at, noindex FROM posts
              WHERE type = 'post' AND status = 'published' AND published_at <= NOW()
              ORDER BY published_at DESC"
        ) as $row) {
            if ((int) $row['noindex'] === 1) {
                continue;
            }

            $urls[] = [
                'loc' => $this->app->url('/' . $row['slug']),
                'lastmod' => substr((string) $row['updated_at'], 0, 10),
                'priority' => '0.8',
                'freq' => 'monthly',
            ];
        }

        foreach ($this->app->db->select(
            "SELECT slug, updated_at, noindex FROM posts
              WHERE type = 'page' AND status = 'published' AND published_at <= NOW()"
        ) as $row) {
            if ((int) $row['noindex'] === 1) {
                continue;
            }

            $urls[] = [
                'loc' => $this->app->url('/p/' . $row['slug']),
                'lastmod' => substr((string) $row['updated_at'], 0, 10),
                'priority' => '0.5',
                'freq' => 'yearly',
            ];
        }

        foreach ($this->categories->allWithCounts(1) as $cat) {
            $urls[] = [
                'loc' => $this->app->url('/categoria/' . $cat['slug']),
                'priority' => '0.6',
                'freq' => 'weekly',
            ];
        }

        // Archivo por año, que es como se llega a lo viejo.
        foreach ($this->app->db->select(
            "SELECT DISTINCT YEAR(published_at) AS y FROM posts
              WHERE type = 'post' AND status = 'published' AND published_at <= NOW()
              ORDER BY y DESC"
        ) as $row) {
            $urls[] = [
                'loc' => $this->app->url('/archivo/' . (int) $row['y']),
                'priority' => '0.3',
                'freq' => 'yearly',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $u) {
            $xml .= '<url><loc>' . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . '</loc>';
            if (isset($u['lastmod'])) {
                $xml .= '<lastmod>' . $u['lastmod'] . '</lastmod>';
            }
            $xml .= '<changefreq>' . $u['freq'] . '</changefreq>'
                . '<priority>' . $u['priority'] . '</priority></url>' . "\n";
        }

        return Response::xml($xml . "</urlset>\n");
    }

    public function robots(Request $request): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            '',
            // El panel no se indexa. No es seguridad —la ruta se adivina
            // igual— pero evita que aparezca en una búsqueda.
            'Disallow: ' . $this->app->adminPath,
            'Disallow: /buscar',
            'Disallow: /uploads/',
            '',
            'Sitemap: ' . $this->app->url('/sitemap.xml'),
        ];

        $extra = trim((string) $this->app->settings->get('robots_extra', ''));
        if ($extra !== '') {
            // Sin saltos raros: esto va a un archivo de texto con formato.
            $lines[] = '';
            foreach (preg_split('/\R/', $extra) ?: [] as $line) {
                $lines[] = mb_substr(trim($line), 0, 200);
            }
        }

        return Response::text(implode("\n", $lines) . "\n");
    }
}
