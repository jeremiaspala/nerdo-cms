<?php

declare(strict_types=1);

namespace App\Services;

/**
 * De bloques a HTML.
 *
 * Este archivo reemplaza al saneador como punto crítico del contenido, y lo
 * hace cambiando la pregunta. El saneador tiene que decidir qué sobrevive de
 * un HTML que vino de afuera y puede traer cualquier cosa: es un problema de
 * lista negra disfrazado de lista blanca, y se gana solo si la lista está
 * completa. Acá el editor manda **datos** —un bloque de tipo `header` con un
 * texto y un nivel— y el HTML lo escribe este archivo.
 *
 * Reglas, todas sin excepción:
 *
 *  - Un tipo de bloque que no esté en `render()` no produce nada.
 *  - Todo texto sale por `inline()`, que admite seis etiquetas de formato y
 *    escapa el resto. Ni atributos de estilo, ni clases, ni ids.
 *  - Las imágenes solo pueden apuntar a /uploads/ de este mismo sitio.
 *  - Los videos solo a youtube-nocookie.com y player.vimeo.com, que es el
 *    mismo recorte que tiene la CSP.
 *
 * Lo que sale de acá es lo único que después se imprime sin escapar.
 */
final class BlockRenderer
{
    /**
     * Etiquetas de formato admitidas dentro del texto de un bloque.
     *
     * Son las que produce la barra flotante del editor. `<a>` lleva su href
     * validado aparte; las demás no tienen atributos, así que no hay nada
     * que validarles.
     */
    private const INLINE = ['b', 'strong', 'i', 'em', 'u', 's', 'code', 'mark', 'br'];

    /** @param array<string,mixed> $data lo que guardó el editor */
    public function render(array $data): string
    {
        $blocks = $data['blocks'] ?? [];

        if (!is_array($blocks)) {
            return '';
        }

        $html = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            $d = is_array($block['data'] ?? null) ? $block['data'] : [];

            $piece = match ($type) {
                'paragraph' => $this->paragraph($d),
                'header' => $this->header($d),
                'list' => $this->list($d),
                'quote' => $this->quote($d),
                'code' => $this->code($d),
                'image' => $this->image($d),
                'embed' => $this->embed($d),
                'delimiter' => '<hr>',
                'table' => $this->table($d),
                default => '',
            };

            if ($piece !== '') {
                $html[] = $piece;
            }
        }

        return implode("\n", $html);
    }

    // ------------------------------------------------------------------
    // Bloques
    // ------------------------------------------------------------------

    private function paragraph(array $d): string
    {
        $text = $this->inline((string) ($d['text'] ?? ''));

        return trim(strip_tags($text)) === '' ? '' : '<p>' . $text . '</p>';
    }

    private function header(array $d): string
    {
        // h1 lo usa el título de la entrada: el cuerpo empieza en h2.
        $level = min(4, max(2, (int) ($d['level'] ?? 2)));
        $text = $this->inline((string) ($d['text'] ?? ''));

        return trim(strip_tags($text)) === '' ? '' : "<h{$level}>{$text}</h{$level}>";
    }

    private function list(array $d): string
    {
        $tag = ($d['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $items = $this->listItems(is_array($d['items'] ?? null) ? $d['items'] : [], $tag);

        return $items === '' ? '' : "<{$tag}>{$items}</{$tag}>";
    }

    /**
     * Las listas del editor pueden anidarse, así que esto es recursivo. El
     * tope de profundidad no es un detalle: sin él, un JSON armado a mano con
     * mil niveles de anidado tumba el renderizador por recursión.
     */
    private function listItems(array $items, string $tag, int $depth = 0): string
    {
        if ($depth > 4) {
            return '';
        }

        $out = '';

        foreach ($items as $item) {
            // El formato viejo guardaba cadenas sueltas; el nuevo, objetos.
            $content = is_array($item) ? (string) ($item['content'] ?? '') : (string) $item;
            $text = $this->inline($content);

            $children = '';
            if (is_array($item) && is_array($item['items'] ?? null) && $item['items'] !== []) {
                $inner = $this->listItems($item['items'], $tag, $depth + 1);
                $children = $inner === '' ? '' : "<{$tag}>{$inner}</{$tag}>";
            }

            if (trim(strip_tags($text)) === '' && $children === '') {
                continue;
            }

            $out .= '<li>' . $text . $children . '</li>';
        }

        return $out;
    }

    private function quote(array $d): string
    {
        $text = $this->inline((string) ($d['text'] ?? ''));

        if (trim(strip_tags($text)) === '') {
            return '';
        }

        $caption = $this->inline((string) ($d['caption'] ?? ''));
        $cite = trim(strip_tags($caption)) === '' ? '' : '<footer>' . $caption . '</footer>';

        return '<blockquote>' . $text . $cite . '</blockquote>';
    }

    private function code(array $d): string
    {
        $code = (string) ($d['code'] ?? '');

        return trim($code) === ''
            ? ''
            : '<pre><code>' . e($code) . '</code></pre>';
    }

    /**
     * Imagen. El `file.url` lo devolvió nuestro propio endpoint de subida, y
     * aun así se valida: una ruta de este sitio, dentro de /uploads/.
     */
    private function image(array $d): string
    {
        $url = (string) ($d['file']['url'] ?? $d['url'] ?? '');

        if (!preg_match('#^/uploads/[A-Za-z0-9._-]{1,120}\.(jpe?g|png|webp|avif)$#', $url)) {
            return '';
        }

        $caption = (string) ($d['caption'] ?? '');
        $captionText = trim(strip_tags($this->inline($caption)));

        // El alt y el pie son dos cosas: el pie se lee, el alt lo lee quien
        // no ve la imagen. El editor solo ofrece el pie, así que si no hay
        // alt propio se usa el pie —mejor eso que una imagen sin alt—, pero
        // el alt que ya traía la entrada no se pisa.
        $alt = trim((string) ($d['file']['alt'] ?? '')) ?: $captionText;

        $img = '<img src="' . e($url) . '" alt="' . e($alt) . '" loading="lazy" decoding="async">';
        $figcaption = $captionText === '' ? '' : '<figcaption>' . $this->inline($caption) . '</figcaption>';

        return '<figure>' . $img . $figcaption . '</figure>';
    }

    /**
     * Video embebido. Mismo recorte que la CSP: si alguna vez este archivo
     * fallara, el navegador sigue sin cargar el marco.
     */
    private function embed(array $d): string
    {
        $src = (string) ($d['embed'] ?? '');

        if (!preg_match(
            '#^https://(?:www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{6,20}|player\.vimeo\.com/video/\d{6,12})(?:\?[A-Za-z0-9=&_-]{0,120})?$#',
            $src
        )) {
            return '';
        }

        $caption = $this->inline((string) ($d['caption'] ?? ''));
        $figcaption = trim(strip_tags($caption)) === '' ? '' : '<figcaption>' . $caption . '</figcaption>';

        return '<figure class="video"><iframe src="' . e($src) . '" title="Video"'
            . ' loading="lazy" allowfullscreen></iframe>' . $figcaption . '</figure>';
    }

    private function table(array $d): string
    {
        $rows = is_array($d['content'] ?? null) ? $d['content'] : [];

        if ($rows === []) {
            return '';
        }

        $withHeadings = (bool) ($d['withHeadings'] ?? false);
        $head = '';
        $body = '';

        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }

            $isHead = $withHeadings && $i === 0;
            $tag = $isHead ? 'th' : 'td';
            $cells = '';

            foreach ($row as $cell) {
                $scope = $isHead ? ' scope="col"' : '';
                $cells .= "<{$tag}{$scope}>" . $this->inline((string) $cell) . "</{$tag}>";
            }

            if ($isHead) {
                $head = '<tr>' . $cells . '</tr>';
            } else {
                $body .= '<tr>' . $cells . '</tr>';
            }
        }

        if ($head === '' && $body === '') {
            return '';
        }

        return '<table>'
            . ($head === '' ? '' : '<thead>' . $head . '</thead>')
            . ($body === '' ? '' : '<tbody>' . $body . '</tbody>')
            . '</table>';
    }

    // ------------------------------------------------------------------

    /**
     * Texto con formato: se admiten seis etiquetas sin atributos y el enlace
     * con su href validado. Todo lo demás se escapa.
     *
     * El método es el único camino por el que un texto del editor llega al
     * HTML, y trabaja sobre el texto ya escapado: se escapa todo primero y
     * después se "desescapan" solo las etiquetas de la lista. Al revés —
     * borrar lo que no gusta— siempre se escapa algo.
     */
    private function inline(string $text): string
    {
        // El texto de un bloque ya viene como HTML —el editor guarda ahí las
        // etiquetas de formato—, así que primero se decodifica y recién
        // después se escapa todo. Sin ese paso, un `->` guardado como `-&gt;`
        // salía publicado como `-&amp;gt;`: doble escapado.
        //
        // Decodificar antes no abre nada: lo que venga, entidad o no, pasa
        // igual por e() en la línea siguiente y solo vuelven a ser etiquetas
        // las seis de la lista.
        $escaped = e(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // Etiquetas simples, sin atributos.
        foreach (self::INLINE as $tag) {
            $escaped = str_replace(
                ['&lt;' . $tag . '&gt;', '&lt;/' . $tag . '&gt;', '&lt;' . $tag . '/&gt;', '&lt;' . $tag . ' /&gt;'],
                ['<' . $tag . '>', '</' . $tag . '>', '<' . $tag . '>', '<' . $tag . '>'],
                $escaped
            );
        }

        // El <code> en línea del editor viene con su clase. Se admite esa
        // forma exacta y se publica sin la clase: el tema ya le da estilo
        // por la etiqueta.
        $escaped = str_replace(
            '&lt;code class=&quot;inline-code&quot;&gt;',
            '<code>',
            $escaped
        );

        // Enlaces: solo href, y solo esquemas que no ejecutan nada.
        $escaped = preg_replace_callback(
            '#&lt;a href=&quot;(.*?)&quot;&gt;#i',
            static function (array $m): string {
                $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                $safe = safe_url($url);

                if ($safe === '') {
                    return '';
                }

                $external = preg_match('#^https?://#i', $url) === 1;

                return '<a href="' . $safe . '"'
                    . ($external ? ' rel="noopener" target="_blank"' : '') . '>';
            },
            $escaped
        ) ?? $escaped;

        return str_replace('&lt;/a&gt;', '</a>', $escaped);
    }
}
