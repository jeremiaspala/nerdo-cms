<?php

declare(strict_types=1);

/**
 * Dos ayudantes de presentación del panel: los íconos y el estado de una
 * entrada.
 *
 * ── Íconos, como SVG en línea ──
 *
 * Dibujados acá y no traídos de una tipografía de íconos ni de un paquete:
 * una fuente de íconos son 70 KB para usar diez glifos, y cualquier CDN de
 * íconos es un dominio de terceros más en una CSP que hoy no tiene ninguno.
 * Un SVG en línea no es un recurso: es parte del HTML, hereda el color del
 * texto con `currentColor` y no pide nada a nadie.
 *
 * Son trazos sobre una grilla de 24 y sin relleno, así que se ven igual en
 * cualquier tamaño y acompañan el color del enlace activo sin reglas aparte.
 */
function admin_icon(string $name): string
{
    $paths = [
        'escritorio' => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/>'
            . '<rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/>'
            . '<rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/>'
            . '<rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',

        'entradas' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>'
            . '<path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/>',

        'paginas' => '<rect x="8" y="3" width="12" height="15" rx="2"/>'
            . '<path d="M16 21H6a2 2 0 0 1-2-2V7"/>',

        'medios' => '<rect x="3" y="4.5" width="18" height="15" rx="2"/>'
            . '<circle cx="8.5" cy="10" r="1.8"/><path d="M4 16.5 9 12l3.5 3 3-2.5 4.5 4"/>',

        'comentarios' => '<path d="M20.5 14.5a3 3 0 0 1-3 3H9l-4.5 3.2V6.5a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3z"/>'
            . '<path d="M8.5 9h7"/><path d="M8.5 12.5h4"/>',

        'mensajes' => '<rect x="3" y="5" width="18" height="14" rx="2"/>'
            . '<path d="M3.6 6.6 12 13l8.4-6.4"/>',

        'analitica' => '<path d="M3 20.5h18"/><path d="M6 20.5V12"/><path d="M12 20.5V4.5"/>'
            . '<path d="M18 20.5v-6"/>',

        'configuracion' => '<path d="M3 7.5h8"/><path d="M15.5 7.5H21"/><path d="M3 16.5h4.5"/>'
            . '<path d="M12 16.5h9"/><circle cx="13" cy="7.5" r="2.4"/><circle cx="9.5" cy="16.5" r="2.4"/>',

        'bitacora' => '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H18a1 1 0 0 1 1 1v15.5a1.5 1.5 0 0 1-1.5 1.5H6.5A1.5 1.5 0 0 1 5 19.5z"/>'
            . '<path d="M8.5 8h7"/><path d="M8.5 12h7"/><path d="M8.5 16h4"/>',

        'seguridad' => '<path d="M12 3 4.5 6v6c0 4.2 3 7.9 7.5 9 4.5-1.1 7.5-4.8 7.5-9V6z"/>'
            . '<path d="M9 12.2l2 2 4-4.2"/>',

        'sitio' => '<path d="M14 3.5h6.5V10"/><path d="M20.5 3.5 12 12"/>'
            . '<path d="M19 14.5V19a2 2 0 0 1-2 2H5.5a2 2 0 0 1-2-2V7.5a2 2 0 0 1 2-2H10"/>',

        'salir' => '<path d="M9.5 21H5.5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>'
            . '<path d="M16 16.5 20.5 12 16 7.5"/><path d="M20.5 12H9.5"/>',
    ];

    return '<svg class="ic" viewBox="0 0 24 24" width="19" height="19" fill="none"'
        . ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Estado de una entrada, para la etiqueta de color de los listados.
 *
 * «Programada» no es un estado en la base: es `published` con una fecha que
 * todavía no llegó. En los listados hay que distinguirla igual, porque decir
 * «publicada» de algo que no está en línea es mentir justo donde se mira
 * para saber qué salió y qué no.
 *
 * @return array{0:string,1:string} clave para la clase CSS y etiqueta
 */
function post_state(array $post): array
{
    if (($post['status'] ?? '') === 'trash') {
        return ['trash', 'papelera'];
    }

    if (($post['status'] ?? '') !== 'published') {
        return ['draft', 'borrador'];
    }

    $at = (string) ($post['published_at'] ?? '');

    if ($at !== '' && strtotime($at) > time()) {
        return ['scheduled', 'programada'];
    }

    return ['published', 'publicada'];
}

/**
 * Resumen legible de un user-agent, para la lista de sesiones.
 *
 * No es identificación ni le sirve a nadie para rastrear: es para que quien
 * mira la lista reconozca cuál es la suya y cuál no.
 */
function navegador(string $ua): string
{
    if (trim($ua) === '') {
        return 'Navegador desconocido';
    }

    $nav = match (true) {
        str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Edg/') => 'Edge',
        str_contains($ua, 'OPR/') => 'Opera',
        str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari',
        default => 'Otro navegador',
    };

    $so = match (true) {
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS X') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux',
        default => 'sistema desconocido',
    };

    return $nav . ' en ' . $so;
}
