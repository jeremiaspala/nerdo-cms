<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;

/**
 * RSS 2.0.
 *
 * Importa más de lo que parece: /feed es una de las rutas que WordPress
 * expone y que los lectores tienen guardada desde hace años. Si deja de
 * responder, se pierden silenciosamente.
 */
final class FeedController extends Controller
{
    private const ITEMS = 20;

    public function index(Request $request): Response
    {
        $posts = $this->posts->published(self::ITEMS);
        $site = $this->app->settings;

        $title = (string) $site->get('site_title', 'Nerdadas!');
        $desc = (string) $site->get('meta_description', '');
        $self = $this->app->url('/feed.xml');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" '
            . 'xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n"
            . "<channel>\n"
            . '<title>' . $this->x($title) . "</title>\n"
            . '<link>' . $this->x($this->app->url('/')) . "</link>\n"
            . '<description>' . $this->x($desc) . "</description>\n"
            . "<language>es</language>\n"
            . '<lastBuildDate>' . date('r') . "</lastBuildDate>\n"
            . '<atom:link href="' . $this->x($self) . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($posts as $post) {
            $url = $this->app->url('/' . $post['slug']);
            $cats = $this->posts->categoriesOf((int) $post['id']);

            $xml .= "<item>\n"
                . '<title>' . $this->x((string) $post['title']) . "</title>\n"
                . '<link>' . $this->x($url) . "</link>\n"
                . '<guid isPermaLink="true">' . $this->x($url) . "</guid>\n"
                . '<pubDate>' . date('r', (int) strtotime((string) $post['published_at'])) . "</pubDate>\n"
                . '<description>' . $this->x((string) $post['excerpt']) . "</description>\n";

            foreach ($cats as $cat) {
                $xml .= '<category>' . $this->x((string) $cat['name']) . "</category>\n";
            }

            // El cuerpo ya está saneado en la base; igual va en CDATA porque
            // es HTML dentro de XML.
            $xml .= '<content:encoded><![CDATA[' . $this->cdata((string) $post['body_html']) . "]]></content:encoded>\n"
                . "</item>\n";
        }

        $xml .= "</channel>\n</rss>\n";

        return Response::xml($xml);
    }

    private function x(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Un "]]>" dentro del contenido cerraría la sección antes de tiempo. */
    private function cdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }
}
