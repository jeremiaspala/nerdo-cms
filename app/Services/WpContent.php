<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Traductor del contenido de WordPress a HTML limpio.
 *
 * Es la parte de la migración donde se pierde contenido si uno se apura.
 * Tres trampas concretas en este blog:
 *
 *  1. 96 de las entradas vienen del editor clásico y NO tienen <p>: los
 *     párrafos los arma WordPress al renderizar, con wpautop. Si se importa
 *     el campo crudo, el texto queda en un bloque sin cortes.
 *  2. 72 usan bloques de Gutenberg, con comentarios <!-- wp:... --> que no
 *     deben llegar al HTML final.
 *  3. Hay 91 [caption], 25 [pastacode] y 5 [gallery]: shortcodes que sin el
 *     plugin correspondiente se verían como texto literal entre corchetes.
 */
final class WpContent
{
    /** @var array<string,int> conteo de lo que se transformó o se descartó */
    private array $stats = [];

    public function __construct(
        /** @var callable(string): ?string URL original -> URL local, o null */
        private readonly mixed $resolveImage,
    ) {
    }

    public function stats(): array
    {
        return $this->stats;
    }

    public function transform(string $content): string
    {
        $html = $content;

        $html = $this->stripBlockComments($html);
        $html = $this->expandCaption($html);
        $html = $this->expandPastacode($html);
        $html = $this->dropGallery($html);
        $html = $this->convertEmbeds($html);
        $html = $this->dropRemainingShortcodes($html);
        $html = $this->autop($html);
        $html = $this->rewriteImages($html);
        $html = $this->rewriteLinks($html);

        return trim($html);
    }

    // ------------------------------------------------------------------

    /** Los comentarios de Gutenberg no son contenido. */
    private function stripBlockComments(string $html): string
    {
        $n = 0;
        $html = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $html, -1, $n) ?? $html;
        $this->bump('bloques_gutenberg', $n);

        return $html;
    }

    /**
     * [caption ...]<a><img></a> Texto del pie[/caption]
     * se convierte en una <figure> con <figcaption>.
     */
    private function expandCaption(string $html): string
    {
        return preg_replace_callback(
            '/\[caption[^\]]*\](.*?)\[\/caption\]/s',
            function (array $m): string {
                $inner = trim($m[1]);

                // La imagen (puede venir envuelta en un enlace) y el resto
                // del texto, que es el pie.
                if (!preg_match('/<img[^>]*>/i', $inner, $img)) {
                    return $inner;
                }

                $caption = trim(strip_tags(str_replace($img[0], '', $inner)));
                $caption = trim($caption, " \t\n\r\0\x0B\u{00A0}");

                $this->bump('caption');

                return $caption === ''
                    ? '<figure>' . $img[0] . '</figure>'
                    : '<figure>' . $img[0] . '<figcaption>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</figcaption></figure>';
            },
            $html
        ) ?? $html;
    }

    /** [pastacode ...]<pre><code>…</code></pre>[/pastacode] → el <pre> solo. */
    private function expandPastacode(string $html): string
    {
        return preg_replace_callback(
            '/\[pastacode[^\]]*\](.*?)\[\/pastacode\]/s',
            function (array $m): string {
                $this->bump('pastacode');
                $inner = trim($m[1]);

                // Si ya trae <pre><code>, se devuelve tal cual.
                if (stripos($inner, '<pre') !== false) {
                    return $inner;
                }

                return '<pre><code>' . htmlspecialchars(
                    html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</code></pre>';
            },
            $html
        ) ?? $html;
    }

    /** Las galerías dependían del plugin; se quitan y se avisa. */
    private function dropGallery(string $html): string
    {
        $n = 0;
        $html = preg_replace('/\[gallery[^\]]*\]/', '', $html, -1, $n) ?? $html;
        $this->bump('gallery_descartada', $n);

        return $html;
    }

    /**
     * Video embebido.
     *
     * YouTube y Vimeo se conservan como iframe, pero reescritos a sus
     * dominios "sin cookies": youtube-nocookie.com no deja cookies de
     * seguimiento hasta que el visitante le da play. Sumado a loading="lazy",
     * el marco ni siquiera se carga mientras no se acerque a la pantalla.
     *
     * Lo que no sea video de esos dos dominios no puede quedar como marco:
     * la CSP solo habilita frame-src para ellos. Eso pasa a ser un enlace.
     */
    private function convertEmbeds(string $html): string
    {
        $html = preg_replace_callback(
            '/<iframe[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>.*?<\/iframe>/is',
            function (array $m): string {
                $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

                if ($embed = self::videoEmbedUrl($url)) {
                    $this->bump('video_embebido');

                    return '<figure class="video"><iframe src="' . htmlspecialchars($embed, ENT_QUOTES, 'UTF-8')
                        . '" title="Video" loading="lazy" allowfullscreen></iframe></figure>';
                }

                $this->bump('embed_a_enlace');

                $label = match (true) {
                    str_contains($url, 'ted.com') => 'Ver la charla en TED',
                    str_contains($url, 'docs.google') => 'Abrir el documento',
                    default => 'Abrir el contenido embebido',
                };

                return '<p class="embed-link"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                    . '" rel="noopener nofollow" target="_blank">' . $label . '</a></p>';
            },
            $html
        ) ?? $html;

        // Flash y compañía: ya no se reproducen en ningún navegador.
        $n = 0;
        $html = preg_replace('/<(object|embed)\b[^>]*>.*?<\/\1>|<embed\b[^>]*\/?>/is', '', $html, -1, $n) ?? $html;
        $this->bump('object_embed_descartado', $n);

        return $html;
    }

    /** Cualquier shortcode suelto que quede se borra en vez de mostrarse. */
    private function dropRemainingShortcodes(string $html): string
    {
        $known = 'contact-form-7|wappointment|embed|audio|video|playlist|vc_[a-z_]+';
        $n = 0;
        $html = preg_replace('/\[\/?(?:' . $known . ')[^\]]*\]/i', '', $html, -1, $n) ?? $html;
        $this->bump('shortcode_descartado', $n);

        return $html;
    }

    /**
     * El wpautop de WordPress, acotado a lo que necesitamos.
     *
     * Solo corre si el contenido no trae <p> propios: a los de Gutenberg no
     * hay que tocarlos.
     */
    private function autop(string $html): string
    {
        if (stripos($html, '<p>') !== false) {
            return $html;
        }

        $this->bump('autop_aplicado');

        $blocks = 'table|thead|tbody|tr|td|th|ul|ol|li|pre|blockquote|figure|figcaption'
            . '|h[1-6]|div|hr|iframe|form|address|section|article|aside|header|footer';

        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Los bloques no se envuelven en <p>: se aíslan primero.
        $html = preg_replace('#(</?(?:' . $blocks . ')[^>]*>)#i', "\n\n$1\n\n", $html) ?? $html;
        $html = preg_replace('/\n{3,}/', "\n\n", $html) ?? $html;

        $out = [];
        foreach (preg_split('/\n\s*\n/', $html) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            // Si el trozo ya es un bloque, va tal cual.
            if (preg_match('#^</?(?:' . $blocks . ')#i', $chunk)) {
                $out[] = $chunk;
                continue;
            }

            // Salto simple dentro de un párrafo = <br>.
            $out[] = '<p>' . nl2br($chunk, false) . '</p>';
        }

        return implode("\n", $out);
    }

    /**
     * Las imágenes pasan a ser locales.
     *
     * Hace falta porque el saneador bloquea recursos externos: una <img> que
     * siga apuntando al WordPress viejo se perdería al guardar, y además
     * ataría el sitio nuevo al servidor que queremos apagar.
     */
    private function rewriteImages(string $html): string
    {
        $resolve = $this->resolveImage;

        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function (array $m) use ($resolve): string {
                $tag = $m[0];

                if (!preg_match('/\bsrc=["\']([^"\']+)["\']/i', $tag, $src)) {
                    $this->bump('img_sin_src');

                    return '';
                }

                $local = $resolve(html_entity_decode($src[1], ENT_QUOTES, 'UTF-8'));

                if ($local === null) {
                    $this->bump('img_no_resuelta');

                    return '';
                }

                $this->bump('img_reescrita');

                $alt = preg_match('/\balt=["\']([^"\']*)["\']/i', $tag, $a) ? $a[1] : '';

                return '<img src="' . htmlspecialchars($local, ENT_QUOTES, 'UTF-8')
                    . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8')
                    . '" loading="lazy">';
            },
            $html
        ) ?? $html;
    }

    /**
     * Enlaces al WordPress viejo.
     *
     * El [caption] de WordPress envuelve la imagen en un <a> a la versión
     * original del archivo. Si eso queda apuntando a nerdadas.com/blog, el
     * día que apagues WordPress son 64 entradas con enlaces rotos — y
     * mientras tanto, el sitio nuevo depende del viejo para funcionar.
     */
    private function rewriteLinks(string $html): string
    {
        $resolve = $this->resolveImage;

        return preg_replace_callback(
            '/<a\b([^>]*)\bhref=["\']([^"\']+)["\']/i',
            function (array $m) use ($resolve): string {
                $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');

                if (!str_contains($url, '/wp-content/uploads/')) {
                    return $m[0];
                }

                $local = $resolve($url);
                if ($local === null) {
                    return $m[0];
                }

                $this->bump('enlace_a_imagen_reescrito');

                return '<a' . $m[1] . 'href="' . htmlspecialchars($local, ENT_QUOTES, 'UTF-8') . '"';
            },
            $html
        ) ?? $html;
    }

    /**
     * URL de embed segura, o null si no es un video que admitamos.
     *
     * Devuelve siempre el dominio sin cookies. Es la única forma en que un
     * iframe puede entrar al contenido: lo que no pase por acá, el saneador
     * lo descarta igual.
     */
    public static function videoEmbedUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

        // youtube.com/watch?v=ID · youtu.be/ID · youtube.com/embed/ID
        if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=)|youtu\.be/)([A-Za-z0-9_-]{6,20})#i', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }

        if (preg_match('#vimeo\.com/(?:video/)?(\d{6,12})#i', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    }

    private function bump(string $key, int $by = 1): void
    {
        if ($by > 0) {
            $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
        }
    }
}
