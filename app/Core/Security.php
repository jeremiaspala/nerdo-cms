<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cabeceras de seguridad.
 *
 * La CSP no lleva 'unsafe-inline': los pocos scripts propios van con el nonce
 * de este request. Es la red que atrapa un XSS que se nos haya escapado.
 */
final class Security
{
    public readonly string $nonce;

    public function __construct(private readonly bool $https)
    {
        $this->nonce = base64_encode(random_bytes(16));
    }

    public function sendHeaders(): void
    {
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$this->nonce}'",
            "style-src 'self' 'nonce-{$this->nonce}'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            // Único resquicio para contenido de terceros: los marcos de video,
            // y solo en sus dominios sin cookies. El saneador aplica el mismo
            // recorte; esto es la segunda barrera, del lado del navegador.
            'frame-src https://www.youtube-nocookie.com https://player.vimeo.com',
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        header('Content-Security-Policy: ' . implode('; ', $csp));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), interest-cohort=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('X-Frame-Options: DENY');

        if ($this->https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        header_remove('X-Powered-By');
    }

    /**
     * Comprobación de origen para peticiones que modifican estado.
     * Segunda capa detrás del token CSRF, no reemplazo.
     */
    public static function sameOrigin(Request $r): bool
    {
        $origin = $r->header('origin');
        $referer = $r->header('referer');
        $source = $origin !== '' ? $origin : $referer;

        if ($source === '') {
            // Algunos clientes legítimos no mandan ninguna de las dos.
            // El token CSRF sigue siendo obligatorio igual.
            return true;
        }

        $host = parse_url($source, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, explode(':', $r->host)[0]) === 0;
    }
}
