<?php

declare(strict_types=1);

/**
 * Único lugar donde se permite escribir HTML a mano.
 *
 * Toda salida de las plantillas pasa por e(). La regla no se discute:
 * si un valor llega a una plantilla sin e(), es un bug de seguridad.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Atributo seguro para href: solo esquemas que no ejecutan código. */
function safe_url(?string $url): string
{
    $url = trim($url ?? '');
    if ($url === '') {
        return '';
    }

    // Relativas propias: siempre bien.
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return e($url);
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https', 'mailto'], true) ? e($url) : '';
}

/** Formato de fecha en castellano sin depender de la locale del sistema. */
function fecha(string $sqlDate): string
{
    static $meses = [
        1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    $ts = strtotime($sqlDate);
    if ($ts === false) {
        return '';
    }

    return sprintf('%d de %s de %d', (int) date('j', $ts), $meses[(int) date('n', $ts)], (int) date('Y', $ts));
}

/**
 * Imagen destacada de una entrada, con srcset.
 *
 * Devuelve cadena vacía si la entrada no tiene imagen, así la plantilla no
 * necesita envolver todo en un if.
 */
function featured_img(array $post, string $class = '', string $sizes = '100vw', bool $eager = false): string
{
    if (empty($post['image'])) {
        return '';
    }

    $base = pathinfo((string) $post['image'], PATHINFO_FILENAME);
    $width = (int) $post['image_width'];

    $srcset = [];
    foreach ([400, 800, 1600] as $size) {
        if ($size < $width) {
            $srcset[] = "/uploads/{$base}-{$size}.jpg {$size}w";
        }
    }
    $srcset[] = "/uploads/{$base}.jpg {$width}w";

    // El alt sale de la base; si está vacío, la imagen es decorativa y se
    // marca como tal en vez de inventarle un texto.
    $alt = (string) ($post['image_alt'] ?? '');

    return sprintf(
        '<img class="%s" src="/uploads/%s.jpg" srcset="%s" sizes="%s" width="%d" height="%d" alt="%s" loading="%s" decoding="async">',
        e($class),
        e($base),
        e(implode(', ', $srcset)),
        e($sizes),
        $width,
        (int) $post['image_height'],
        e($alt),
        $eager ? 'eager' : 'lazy'
    );
}

/**
 * URL de un archivo estático, con versión.
 *
 * El .htaccess sirve css/js con `max-age=31536000, immutable`, pero los
 * nombres no llevan hash: sin este sufijo, un cambio de estilo no le llega
 * nunca a quien ya visitó el sitio —y el error aparece en el navegador de
 * otro, que es donde no se puede depurar—. La marca de tiempo del archivo
 * alcanza y no obliga a un paso de compilación.
 */
function asset(string $path): string
{
    $mtime = @filemtime(ROOT . '/public' . $path);

    return e($mtime === false ? $path : $path . '?v=' . $mtime);
}

/**
 * Iniciales para el avatar de un comentario.
 *
 * El avatar se dibuja con CSS a partir de esto. No hay Gravatar: pedirle la
 * imagen a un tercero le filtra, por cada lector y en cada visita, el hash de
 * correo de quien comentó, la IP del que lee y qué entrada está leyendo. Y
 * obligaría a abrir img-src en la CSP, que hoy es solo 'self'.
 */
function initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    if ($words === []) {
        return '?';
    }

    $first = mb_strtoupper(mb_substr($words[0], 0, 1), 'UTF-8');

    if (count($words) === 1) {
        return $first;
    }

    return $first . mb_strtoupper(mb_substr((string) end($words), 0, 1), 'UTF-8');
}

/**
 * Color del avatar, derivado del hash de correo.
 *
 * Sale una clase y no un style="": la CSP no admite estilos en línea. Los
 * ocho colores están en el tema. Estable para la misma persona, porque el
 * hash lo es.
 */
function avatar_class(string $emailHash): string
{
    $seed = $emailHash === '' ? 0 : (int) hexdec(substr($emailHash, 0, 2));

    return 'av-' . ($seed % 8);
}

/**
 * Fecha en el formato que se usa en Argentina: dd/mm/aaaa.
 *
 * El panel la usa en todos lados. No alcanza con confiar en el navegador:
 * un <input type="datetime-local"> se dibuja con el formato del idioma del
 * navegador, no el de la página, así que en un Chrome en inglés muestra
 * 11/14/2026 aunque el sitio esté en castellano y el servidor en America/
 * Argentina/Buenos_Aires.
 */
function fecha_ar(?string $sqlDate, bool $conHora = false): string
{
    if ($sqlDate === null || trim($sqlDate) === '') {
        return '';
    }

    $ts = strtotime($sqlDate);

    if ($ts === false) {
        return '';
    }

    return date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

/**
 * Lee una fecha escrita a mano en dd/mm/aaaa (con o sin hora) y la devuelve
 * en el formato de la base. Devuelve null si no se entiende.
 *
 * `strtotime` interpreta 03/04/2026 como el 4 de marzo, porque asume el
 * formato de Estados Unidos. Por eso se parsea a mano antes de dejárselo.
 */
function fecha_ar_a_sql(string $texto): ?string
{
    $texto = trim($texto);

    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[ ,]+(\d{1,2}):(\d{2}))?$#', $texto, $m)) {
        return null;
    }

    [, $d, $mes, $anio] = $m;
    $hora = (int) ($m[4] ?? 0);
    $min = (int) ($m[5] ?? 0);

    if (!checkdate((int) $mes, (int) $d, (int) $anio) || $hora > 23 || $min > 59) {
        return null;
    }

    return sprintf('%04d-%02d-%02d %02d:%02d:00', (int) $anio, (int) $mes, (int) $d, $hora, $min);
}

/** Fecha y hora cortas, para los comentarios. */
function fecha_hora(string $sqlDate): string
{
    $ts = strtotime($sqlDate);

    return $ts === false ? '' : fecha($sqlDate) . ' a las ' . date('H:i', $ts);
}

/** Slug ASCII, estable y acotado. */
function slugify(string $text, int $max = 120): string
{
    $text = trim($text);
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        if ($t !== false) {
            $text = $t;
        }
    }

    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text === '' ? 'entrada' : mb_substr($text, 0, $max);
}
