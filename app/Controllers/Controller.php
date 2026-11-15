<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Models\Category;
use App\Models\Post;

abstract class Controller
{
    protected Post $posts;
    protected Category $categories;

    public function __construct(protected readonly Kernel $app)
    {
        $this->posts = new Post($app->db);
        $this->categories = new Category($app->db);
    }

    /**
     * Datos que toda página necesita. El tema recibe esto ya resuelto: no
     * consulta la base por su cuenta.
     */
    protected function base(Request $request, array $seo = []): array
    {
        $site = $this->app->settings;

        return [
            'site' => [
                'title' => $site->get('site_title', 'Nerdadas!'),
                'tagline' => $site->get('site_tagline', ''),
                'url' => rtrim($this->app->env->get('APP_URL', '') ?? '', '/'),
                'header' => $this->headerImage(),
                'overlay' => $site->get('header_overlay', 'medio'),
            ],
            'nav' => $this->categories->allWithCounts(min: 1),
            'seo' => $seo + [
                'title' => $site->get('site_title', 'Nerdadas!'),
                'description' => $site->get('meta_description', ''),
                'canonical' => $this->app->url($request->path),
                'noindex' => false,
                'image' => null,
            ],
            'flash' => $this->app->session->isStarted() ? $this->app->session->takeFlash() : null,
            'nonce' => $this->app->security->nonce,
            'year' => (int) date('Y'),
            'social' => array_filter([
                'x' => $site->get('social_x', ''),
                'mastodon' => $site->get('social_mastodon', ''),
                'github' => $site->get('social_github', ''),
                'linkedin' => $site->get('social_linkedin', ''),
            ]),
            'shareButtons' => $site->bool('share_buttons', true),
            'rail' => $this->rail(),
            // Bloques JSON-LD: siempre va el de WebSite; cada controlador
            // agrega el suyo.
            'jsonld' => [
                \App\Services\Seo::website(
                    (string) $site->get('site_title', 'Nerdadas!'),
                    rtrim($this->app->env->get('APP_URL', '') ?? '', '/'),
                    (string) $site->get('meta_description', '')
                ),
            ],
        ];
    }

    /**
     * Las tres listas de la barra lateral.
     *
     * Son tres consultas por render, y solo se pagan cuando la página no está
     * en el caché: una página cacheada sale con la barra ya dibujada adentro.
     *
     * «Populares» y «Tendencias» salen de la analítica propia. En WordPress
     * esas dos pestañas las llenaba un plugin que vuelve a contar las visitas
     * por su cuenta; acá los números ya estaban medidos en el servidor.
     */
    private function rail(): array
    {
        return [
            'ultimas' => $this->posts->withCategories($this->posts->published(5)),
            'populares' => $this->posts->withCategories($this->posts->mostViewed(90, 5)),
            'tendencias' => $this->posts->withCategories($this->posts->mostViewed(7, 5)),
        ];
    }

    /**
     * Imagen de fondo del encabezado, si hay una elegida.
     *
     * Se devuelve solo el nombre base: la plantilla arma el srcset. Va en un
     * <img> y no en background-image porque la CSP no admite estilos en
     * línea, y además así el navegador puede elegir el tamaño.
     */
    private function headerImage(): ?array
    {
        $id = (int) $this->app->settings->get('header_media_id', '0');
        if ($id <= 0) {
            return null;
        }

        $row = $this->app->db->first(
            'SELECT filename, width, height, alt FROM media WHERE id = :id',
            ['id' => $id]
        );

        if ($row === null) {
            return null;
        }

        $base = pathinfo((string) $row['filename'], PATHINFO_FILENAME);
        $width = (int) $row['width'];

        // Solo las variantes que realmente se generaron: ImageProcessor no
        // crea tamaños más grandes que el original, y un candidato que no
        // existe es una descarga fallida en cada visita.
        $srcset = [];
        foreach (\App\Services\ImageProcessor::SIZES as $size) {
            if ($size < $width) {
                $srcset[] = "/uploads/{$base}-{$size}.jpg {$size}w";
            }
        }
        $srcset[] = "/uploads/{$base}.jpg {$width}w";

        return [
            'base' => $base,
            'width' => $width,
            'height' => (int) $row['height'],
            'srcset' => implode(', ', $srcset),
        ];
    }

    protected function view(string $template, array $data): Response
    {
        return Response::html($this->app->view->page($template, $data));
    }

    /**
     * Plantilla de título configurable, al estilo de los plugins de SEO:
     * "%title% · %site%".
     */
    protected function title(string $pageTitle): string
    {
        $pattern = $this->app->settings->get('meta_title_pattern', '%title% · %site%');

        return str_replace(
            ['%title%', '%site%'],
            [$pageTitle, (string) $this->app->settings->get('site_title', 'Nerdadas!')],
            (string) $pattern
        );
    }
}
