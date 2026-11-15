<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * De HTML a bloques.
 *
 * Solo hace falta una vez por entrada: para pasar las 167 que venían de
 * WordPress al editor nuevo. No corre en ningún request.
 *
 * Es un problema acotado porque el HTML de entrada no es cualquier HTML: ya
 * pasó por el saneador, así que es la lista blanca de `HtmlSanitizer::post()`
 * y nada más. Lo que no se reconoce no se tira en silencio: se devuelve en
 * `$desconocidos` para que la migración lo muestre y se pueda mirar.
 */
final class HtmlToBlocks
{
    /** @var array<int,string> etiquetas que aparecieron y no se convirtieron */
    public array $desconocidos = [];

    /** @return array{blocks:array<int,array<string,mixed>>} */
    public function convert(string $html): array
    {
        $this->desconocidos = [];
        $blocks = [];

        if (trim($html) === '') {
            return ['blocks' => []];
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // El HTML viene sin <html> ni <body>; el prefijo fija la codificación
        // sin que libxml invente un <meta charset> en el medio del contenido.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><div id="raiz">' . $html . '</div>',
            LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('raiz');

        if ($root === null) {
            return ['blocks' => []];
        }

        foreach ($root->childNodes as $node) {
            foreach ($this->node($node) as $block) {
                $blocks[] = $block;
            }
        }

        return ['blocks' => $blocks];
    }

    /** @return array<int,array<string,mixed>> */
    private function node(DOMNode $node): array
    {
        if ($node instanceof DOMText) {
            $text = trim($node->textContent);

            return $text === '' ? [] : [$this->block('paragraph', ['text' => e($text)])];
        }

        if (!$node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->tagName);

        return match ($tag) {
            'p', 'div' => $this->paragraph($node),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->header($node, $tag),
            'ul', 'ol' => $this->list($node, $tag),
            'blockquote' => $this->quote($node),
            'pre' => $this->code($node),
            'figure' => $this->figure($node),
            'img' => $this->image($node),
            'iframe' => $this->embed($node),
            'hr' => [$this->block('delimiter', [])],
            'table' => $this->table($node),
            'br' => [],
            default => $this->unknown($node, $tag),
        };
    }

    // ------------------------------------------------------------------

    /**
     * Un <p> puede traer adentro una imagen o un video: WordPress los metía
     * así. En ese caso el párrafo se parte en los bloques que correspondan en
     * vez de perderlos.
     */
    private function paragraph(DOMElement $node): array
    {
        // WordPress convertía en video una URL sola en su propio renglón
        // (oEmbed). Al importar quedó como texto, así que hoy esas ocho
        // entradas muestran una URL pelada donde había un video.
        if ($video = $this->videoUrl($node->textContent)) {
            return [$video];
        }

        $embedded = [];

        foreach ($node->getElementsByTagName('*') as $child) {
            if (in_array(strtolower($child->tagName), ['img', 'iframe'], true)) {
                $embedded[] = $child;
            }
        }

        if ($embedded !== []) {
            $blocks = [];

            foreach ($embedded as $child) {
                foreach ($this->node($child) as $block) {
                    $blocks[] = $block;
                }
                $child->parentNode?->removeChild($child);
            }

            // Lo que quedaba de texto alrededor, si quedaba algo.
            $text = $this->inner($node);
            if (trim(strip_tags($text)) !== '') {
                array_unshift($blocks, $this->block('paragraph', ['text' => $text]));
            }

            return $blocks;
        }

        $text = $this->inner($node);

        return trim(strip_tags($text)) === '' ? [] : [$this->block('paragraph', ['text' => $text])];
    }

    private function header(DOMElement $node, string $tag): array
    {
        $text = $this->inner($node);

        if (trim(strip_tags($text)) === '') {
            return [];
        }

        // h1 y h5/h6 se acomodan al rango que admite el editor.
        $level = min(4, max(2, (int) substr($tag, 1)));

        return [$this->block('header', ['text' => $text, 'level' => $level])];
    }

    private function list(DOMElement $node, string $tag): array
    {
        $items = $this->listItems($node);

        return $items === []
            ? []
            : [$this->block('list', [
                'style' => $tag === 'ol' ? 'ordered' : 'unordered',
                'items' => $items,
            ])];
    }

    /** @return array<int,array<string,mixed>> */
    private function listItems(DOMElement $list, int $depth = 0): array
    {
        $items = [];

        foreach ($list->childNodes as $li) {
            if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }

            $children = [];

            // Las listas anidadas viven adentro del <li>: se sacan antes de
            // leer el texto para que no queden pegadas al contenido.
            foreach (iterator_to_array($li->childNodes) as $child) {
                if ($child instanceof DOMElement
                    && in_array(strtolower($child->tagName), ['ul', 'ol'], true)
                ) {
                    if ($depth < 4) {
                        foreach ($this->listItems($child, $depth + 1) as $sub) {
                            $children[] = $sub;
                        }
                    }
                    $li->removeChild($child);
                }
            }

            $items[] = [
                'content' => $this->inner($li),
                'meta' => (object) [],
                'items' => $children,
            ];
        }

        return $items;
    }

    private function quote(DOMElement $node): string|array
    {
        $text = trim($this->inner($node));

        return trim(strip_tags($text)) === ''
            ? []
            : [$this->block('quote', ['text' => $text, 'caption' => '', 'alignment' => 'left'])];
    }

    /**
     * Bloque de código.
     *
     * Ojo con los <br>: WordPress guardaba los saltos de línea de un <pre>
     * como <br>, y `textContent` los descarta sin dejar nada en su lugar.
     * Sin esto, siete entradas quedaban con el código pegado en una sola
     * línea —«/var/log/home/root/etc»— y encima sin que se notara en el
     * recuento de caracteres.
     */
    private function code(DOMElement $node): array
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        $code = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $code = html_entity_decode(strip_tags($code), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($code) === '' ? [] : [$this->block('code', ['code' => $code])];
    }

    /** Una <figure> es imagen o video con pie: se mira qué trae adentro. */
    private function figure(DOMElement $node): array
    {
        $caption = '';

        foreach ($node->getElementsByTagName('figcaption') as $cap) {
            $caption = $this->inner($cap);
            break;
        }

        foreach ($node->getElementsByTagName('iframe') as $iframe) {
            return $this->embed($iframe, $caption);
        }

        foreach ($node->getElementsByTagName('img') as $img) {
            return $this->image($img, $caption);
        }

        if ($video = $this->videoUrl($node->textContent)) {
            return [$video];
        }

        return $this->unknown($node, 'figure');
    }

    private function image(DOMElement $node, string $caption = ''): array
    {
        $src = $node->getAttribute('src');

        if (!str_starts_with($src, '/uploads/')) {
            $this->desconocidos[] = 'img:' . substr($src, 0, 60);

            return [];
        }

        // El alt NO se convierte en pie: son dos cosas distintas y mezclarlas
        // le agregaría texto visible a 139 entradas que hoy no lo tienen. Va
        // adentro de `file`, que el editor conserva tal cual al guardar.
        $alt = $node->getAttribute('alt');

        return [$this->block('image', [
            'file' => array_filter(['url' => $src, 'alt' => $alt]),
            'caption' => $caption,
            'withBorder' => false,
            'withBackground' => false,
            'stretched' => false,
        ])];
    }

    private function embed(DOMElement $node, string $caption = ''): array
    {
        $src = $node->getAttribute('src');

        if (preg_match('#youtube-nocookie\.com/embed/([A-Za-z0-9_-]{6,20})#', $src, $m) === 1) {
            return [$this->block('embed', [
                'service' => 'youtube',
                'source' => 'https://www.youtube.com/watch?v=' . $m[1],
                'embed' => 'https://www.youtube-nocookie.com/embed/' . $m[1],
                'width' => 580,
                'height' => 320,
                'caption' => $caption,
            ])];
        }

        if (preg_match('#player\.vimeo\.com/video/(\d{6,12})#', $src, $m) === 1) {
            return [$this->block('embed', [
                'service' => 'vimeo',
                'source' => 'https://vimeo.com/' . $m[1],
                'embed' => 'https://player.vimeo.com/video/' . $m[1],
                'width' => 580,
                'height' => 320,
                'caption' => $caption,
            ])];
        }

        $this->desconocidos[] = 'iframe:' . substr($src, 0, 60);

        return [];
    }

    private function table(DOMElement $node): array
    {
        $content = [];
        $withHeadings = false;

        foreach ($node->getElementsByTagName('tr') as $tr) {
            $row = [];
            $isHead = false;

            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof DOMElement) {
                    continue;
                }

                $name = strtolower($cell->tagName);

                if ($name === 'th') {
                    $isHead = true;
                }

                if ($name === 'th' || $name === 'td') {
                    $row[] = $this->inner($cell);
                }
            }

            if ($row === []) {
                continue;
            }

            if ($isHead && $content === []) {
                $withHeadings = true;
            }

            $content[] = $row;
        }

        return $content === []
            ? []
            : [$this->block('table', ['withHeadings' => $withHeadings, 'content' => $content])];
    }

    /**
     * Si el nodo es solo una URL de YouTube o Vimeo, devuelve el bloque de
     * video. Tiene que ser eso y nada más: una URL en medio de un párrafo es
     * un enlace, no un video.
     */
    private function videoUrl(string $text): ?array
    {
        $text = trim($text);

        if ($text === '' || preg_match('#\s#u', $text) === 1) {
            return null;
        }

        if (preg_match('#^https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,20})#', $text, $m) === 1) {
            return $this->block('embed', [
                'service' => 'youtube',
                'source' => 'https://www.youtube.com/watch?v=' . $m[1],
                'embed' => 'https://www.youtube-nocookie.com/embed/' . $m[1],
                'width' => 580,
                'height' => 320,
                'caption' => '',
            ]);
        }

        if (preg_match('#^https?://(?:www\.)?vimeo\.com/(\d{6,12})#', $text, $m) === 1) {
            return $this->block('embed', [
                'service' => 'vimeo',
                'source' => 'https://vimeo.com/' . $m[1],
                'embed' => 'https://player.vimeo.com/video/' . $m[1],
                'width' => 580,
                'height' => 320,
                'caption' => '',
            ]);
        }

        return null;
    }

    private function unknown(DOMElement $node, string $tag): array
    {
        $this->desconocidos[] = $tag;

        // No se pierde el contenido: se baja un nivel y se convierte lo de
        // adentro, que suele ser texto o bloques conocidos.
        $blocks = [];

        foreach ($node->childNodes as $child) {
            foreach ($this->node($child) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    // ------------------------------------------------------------------

    /**
     * HTML de adentro de un nodo, recortado a lo que el editor entiende como
     * formato en línea. El resto se escapa: lo que no es formato es texto.
     */
    private function inner(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= e($child->textContent);
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if ($tag === 'br') {
                $out .= '<br>';
                continue;
            }

            if ($tag === 'a') {
                $href = safe_url($child->getAttribute('href'));
                $inner = $this->inner($child);
                $out .= $href === '' ? $inner : '<a href="' . $href . '">' . $inner . '</a>';
                continue;
            }

            // El editor sanea al guardar y solo admite <b>, <i>, <a> y el
            // <code class="inline-code"> de su herramienta. Un <strong> que
            // entre tal cual se pierde la primera vez que se guarda la
            // entrada —son 1.732 en el blog—, así que se traduce acá.
            if (in_array($tag, ['b', 'strong'], true)) {
                $out .= '<b>' . $this->inner($child) . '</b>';
                continue;
            }

            if (in_array($tag, ['i', 'em'], true)) {
                $out .= '<i>' . $this->inner($child) . '</i>';
                continue;
            }

            if ($tag === 'code') {
                $out .= '<code class="inline-code">' . $this->inner($child) . '</code>';
                continue;
            }

            if (in_array($tag, ['u', 's'], true)) {
                $out .= '<' . $tag . '>' . $this->inner($child) . '</' . $tag . '>';
                continue;
            }

            // Cualquier otra cosa adentro de un párrafo —span, figure
            // suelta— aporta su texto y pierde la etiqueta.
            $out .= $this->inner($child);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function block(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }
}
