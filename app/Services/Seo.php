<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Datos estructurados (JSON-LD).
 *
 * Es lo que leen Google y las redes para armar el resultado enriquecido:
 * fecha, autor, miga de pan y el buscador interno del sitio.
 *
 * Se genera acá y no en la plantilla por una razón concreta: el JSON va
 * dentro de un <script>, donde escapar mal no es un detalle de formato sino
 * un XSS. json_encode con HEX_TAG hace que un "</script>" en un título
 * salga como < y no pueda cerrar la etiqueta.
 */
final class Seo
{
    private const FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    public static function website(string $siteName, string $url, string $description): string
    {
        return self::encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $siteName,
            'url' => $url,
            'description' => $description,
            'inLanguage' => 'es',
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $url . '/buscar?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ]);
    }

    public static function article(array $post, array $categories, string $siteName, string $url, ?string $image, string $author): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => mb_substr((string) $post['title'], 0, 110),
            'description' => (string) ($post['meta_description'] ?: $post['excerpt']),
            'datePublished' => date('c', (int) strtotime((string) $post['published_at'])),
            'dateModified' => date('c', (int) strtotime((string) $post['updated_at'])),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'author' => ['@type' => 'Person', 'name' => $author],
            'publisher' => ['@type' => 'Organization', 'name' => $siteName],
            'inLanguage' => 'es',
            'wordCount' => (int) ($post['word_count'] ?? 0),
        ];

        if ($image !== null) {
            $data['image'] = $image;
        }

        if ($categories !== []) {
            $data['articleSection'] = array_values(array_map(
                static fn (array $c): string => (string) $c['name'],
                $categories
            ));
        }

        return self::encode($data);
    }

    /**
     * @param array<int,array{name:string,url:string}> $crumbs
     */
    public static function breadcrumbs(array $crumbs): string
    {
        $items = [];
        foreach (array_values($crumbs) as $i => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
        }

        return self::encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ]);
    }

    private static function encode(array $data): string
    {
        return (string) json_encode($data, self::FLAGS);
    }
}
