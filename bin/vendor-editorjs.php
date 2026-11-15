#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prepara los archivos de Editor.js para servirlos desde este dominio.
 *
 * Los paquetes de Editor.js vienen compilados con `vite-plugin-css-injected-
 * by-js`: cada bundle arranca con un IIFE que crea un <style> y le mete el
 * CSS adentro desde JavaScript. Con la CSP de este proyecto —`style-src
 * 'self' 'nonce-…'`, sin `unsafe-inline`— ese <style> se bloquea y el editor
 * queda sin estilos.
 *
 * Aflojar la CSP del panel para que entre era la opción fácil y es
 * exactamente lo que no queremos: la CSP es la red que atrapa un XSS que se
 * nos haya escapado, y el panel es donde más caro sale perderla.
 *
 * Así que este script hace dos cosas con cada bundle:
 *
 *   1. Le saca el CSS de adentro y lo junta en un .css de verdad, que se
 *      enlaza como cualquier hoja de estilos.
 *   2. Le borra el IIFE que inyectaba, para que no quede intentando algo que
 *      la CSP va a bloquear igual.
 *
 * El núcleo además trae dos hojas más adentro, como literales de plantilla:
 * la del editor (`:root{--selectionColor…`) y la de los globos de ayuda
 * (`.ct{…`). Esas NO se tocan: las inyecta `loadStyles()`, que arranca con
 * un `if (document.getElementById(id)) return`. Alcanza con que el panel
 * deje puesto un <style> vacío con ese id —y con el nonce, que la CSP sí
 * admite— para que el núcleo no inyecte nada. El CSS se copia igual al
 * archivo, que es de donde se va a leer.
 *
 * Queda versionado para que la próxima actualización sea `composer`-menos:
 * bajar los dist nuevos y volver a correr esto.
 *
 * Uso:
 *   php bin/vendor-editorjs.php /ruta/con/los/umd
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por consola.\n");
}

$src = rtrim((string) ($argv[1] ?? ''), '/');
$dest = dirname(__DIR__) . '/public/assets/vendor/editorjs';

if ($src === '' || !is_dir($src)) {
    exit("Uso: php bin/vendor-editorjs.php /directorio/con/los/umd\n");
}

if (!is_dir($dest) && !mkdir($dest, 0775, true)) {
    exit("No se pudo crear {$dest}\n");
}

/** Orden de carga: el núcleo primero, después las herramientas. */
$files = [
    'editorjs.umd.js',
    'header.umd.js',
    'list.umd.js',
    'quote.umd.js',
    'code.umd.js',
    'image.umd.js',
    'embed.umd.js',
    'delimiter.umd.js',
    'table.umd.js',
    // Código en línea: sin esta herramienta el editor borra los <code> que
    // están adentro de un párrafo al guardar, y son 483 en el blog.
    'inline-code.umd.js',
];

/**
 * Lee un literal de cadena de JavaScript a partir de la comilla de apertura
 * y devuelve su contenido ya sin escapes.
 */
function js_string(string $js, int $start): array
{
    $quote = $js[$start];
    $out = '';
    $i = $start + 1;
    $len = strlen($js);

    while ($i < $len) {
        $ch = $js[$i];

        if ($ch === '\\') {
            $next = $js[$i + 1] ?? '';
            $out .= match ($next) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                '\\' => '\\',
                '"' => '"',
                "'" => "'",
                default => $next,
            };
            $i += 2;
            continue;
        }

        if ($ch === $quote) {
            return [$out, $i + 1];
        }

        $out .= $ch;
        $i++;
    }

    throw new RuntimeException('Cadena sin cerrar.');
}

$css = "/* Estilos de Editor.js y sus herramientas.\n"
    . " *\n"
    . " * Generado por bin/vendor-editorjs.php: es el CSS que los bundles\n"
    . " * traían adentro e inyectaban por JavaScript. Acá va como hoja de\n"
    . " * estilos para no necesitar `unsafe-inline` en la CSP del panel.\n"
    . " * No editar a mano; los ajustes propios van en admin.css.\n"
    . " */\n";

$total = 0;

foreach ($files as $name) {
    $path = $src . '/' . $name;

    if (!is_file($path)) {
        exit("Falta {$path}\n");
    }

    $js = (string) file_get_contents($path);

    if (!str_contains($js, 'vite-plugin-css-injected-by-js')) {
        // Algún paquete podría venir ya sin inyección: se copia tal cual.
        file_put_contents($dest . '/' . $name, $js);
        echo "  {$name}: sin CSS inyectado, copiado tal cual\n";
        continue;
    }

    // Puede haber más de un IIFE por archivo: el núcleo trae el suyo y
    // además el de la herramienta de párrafo, que viene adentro.
    $extracted = '';
    $removed = 0;

    while (($at = strpos($js, 'vite-plugin-css-injected-by-js')) !== false) {
        $tryAt = strrpos(substr($js, 0, $at), 'try{if(typeof document<"u")');
        $start = $tryAt === false ? false : strrpos(substr($js, 0, $tryAt), '(function(){');
        $end = strpos($js, '})();', $at);

        if ($start === false || $end === false) {
            exit("No se pudo delimitar la inyección en {$name}: revisar a mano\n");
        }

        $block = substr($js, $start, $end + 5 - $start);
        $textAt = strpos($block, 'document.createTextNode(');

        if ($textAt === false) {
            exit("Inyección sin CSS en {$name}: revisar a mano\n");
        }

        [$cssText, ] = js_string($block, $textAt + strlen('document.createTextNode('));
        $extracted .= trim($cssText) . "\n";
        $removed++;

        $js = substr($js, 0, $start) . substr($js, $end + 5);
    }

    $clean = ltrim($js, "\n");
    file_put_contents($dest . '/' . $name, $clean);

    $css .= "\n/* ---- {$name} ---- */\n" . $extracted;
    $total += strlen($extracted);

    printf("  %-20s js %6.1f KB  ·  %d inyección(es), %5.1f KB de CSS\n",
        $name, strlen($clean) / 1024, $removed, strlen($extracted) / 1024);
}

// ---- La hoja que el núcleo inyecta con style-loader (codex-notifier).
//
// Esta no tiene guarda de id: la mete el runtime de style-loader, sin nonce,
// y la CSP la bloquea en silencio —el <style> queda sin hoja asociada—. Se
// le saca el CSS y se le borra la llamada que inyecta, así no queda un
// elemento bloqueado en cada carga del editor.
$core = (string) file_get_contents($dest . '/editorjs.umd.js');
$loaderCss = '';

if (preg_match_all('#\(t\.exports=i\(\d+\)\(!1\)\)\.push\(\[t\.i,`#', $core, $m, PREG_OFFSET_CAPTURE)) {
    foreach (array_reverse($m[0]) as [$needle, $at]) {
        $start = $at + strlen($needle) - 1;
        $end = strpos($core, '`', $start + 1);

        if ($end === false) {
            exit("Hoja de style-loader sin cerrar en el núcleo: revisar a mano\n");
        }

        $loaderCss = substr($core, $start + 1, $end - $start - 1) . "\n" . $loaderCss;
    }
}

// Se borra SOLO la llamada que inyecta, no el módulo entero: la variable que
// la precede la sigue usando la línea siguiente (`s.locals && …`). Borrando
// de más, el núcleo se quedaba sin esa variable, tiraba un ReferenceError y
// el editor no arrancaba —con la página entera en blanco donde va el texto—.
$calls = preg_replace(
    '#(var (\w+)=\{hmr:!0,[^}]*\};)i\(\d+\)\(\w+,\2\),#',
    '$1',
    $core,
    -1,
    $removidas
);

if ($loaderCss !== '') {
    if ($removidas === 0) {
        exit("Se encontró CSS de style-loader pero no la llamada que lo inyecta: revisar a mano\n");
    }

    file_put_contents($dest . '/editorjs.umd.js', $calls);
    $css .= "\n/* ---- núcleo: codex-notifier (inyectada por style-loader) ---- */\n" . trim($loaderCss) . "\n";
    $total += strlen($loaderCss);

    printf("  %-20s %5.1f KB de CSS (%d inyección(es) de style-loader borradas)\n",
        'codex-notifier', strlen($loaderCss) / 1024, $removidas);
}

// ---- Las dos hojas que el núcleo guarda como literal de plantilla.
//
// Se copian al .css pero se dejan intactas en el bundle: no se inyectan
// porque el panel pone los <style> con esos id (ver la cabecera).
$core = (string) file_get_contents($dest . '/editorjs.umd.js');

foreach ([
    'editor-js-styles' => ':root{--selectionColor',
    'codex-tooltips-style' => '.ct{z-index:999',
] as $id => $needle) {
    $at = strpos($core, $needle);

    if ($at === false) {
        exit("No se encontró la hoja «{$id}» en el núcleo: revisar a mano\n");
    }

    $start = strrpos(substr($core, 0, $at), '`');
    $end = strpos($core, '`', $at);

    if ($start === false || $end === false) {
        exit("No se pudo delimitar la hoja «{$id}»: revisar a mano\n");
    }

    $block = substr($core, $start + 1, $end - $start - 1);
    $css .= "\n/* ---- núcleo: {$id} (inyectada por loadStyles) ---- */\n" . trim($block) . "\n";
    $total += strlen($block);

    printf("  %-20s %5.1f KB de CSS (se neutraliza con un <style id=\"%s\">)\n",
        $id, strlen($block) / 1024, $id);
}

file_put_contents($dest . '/editorjs.css', $css);

printf("\nListo: %d archivos en %s · CSS total %.1f KB\n", count($files), $dest, $total / 1024);
