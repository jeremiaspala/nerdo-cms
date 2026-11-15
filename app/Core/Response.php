<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            $headers + ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * 304: el cliente ya tiene esta versión.
     *
     * Va sin cuerpo por definición. Lo usa el caché de página para el feed y
     * el sitemap, que son lo que más se pide del sitio: un lector de feeds
     * pregunta cada quince minutos y casi siempre la respuesta correcta es
     * «lo mismo que la vez pasada».
     */
    public static function notModified(array $headers = []): self
    {
        return new self('', 304, $headers);
    }

    /**
     * Respuesta que sale del caché de página.
     *
     * Las cabeceras son las que tenía la respuesta original —incluido el
     * Content-Type, que no siempre es HTML: el feed y el sitemap también se
     * cachean—. Se reconstruye tal cual se guardó.
     */
    public static function cached(string $body, array $headers): self
    {
        return new self($body, 200, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Redirección con destino validado.
     *
     * Solo rutas internas o el propio dominio: así no se puede usar el CMS
     * como trampolín de phishing (open redirect).
     */
    public static function redirect(string $to, int $status = 302): self
    {
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }

        return new self('', $status, ['Location' => $to]);
    }

    /**
     * Redirección a otro dominio, para la única cosa que la necesita: mandar
     * a alguien a Google a autorizar.
     *
     * Va aparte de redirect() a propósito. Esa función solo admite rutas
     * propias, y está bien que así sea: es lo que evita que el CMS se use de
     * trampolín de phishing. Acá la URL no viene de la entrada del usuario
     * sino que la arma el servicio, y se comprueba igual que sea https y del
     * dominio que esperamos.
     */
    public static function away(string $url): self
    {
        if (!str_starts_with($url, 'https://accounts.google.com/')) {
            return self::redirect('/');
        }

        return new self('', 302, ['Location' => $url, 'Cache-Control' => 'no-store']);
    }

    /**
     * Página puente para volver de otro sitio sin perder la sesión.
     *
     * La cookie de sesión es `SameSite=Strict`, que es lo que queremos: el
     * navegador no la manda en nada que venga de otro dominio. El efecto
     * colateral aparece al volver de Google: la cookie **se guarda** en esa
     * respuesta, pero el redirect que sigue todavía es parte de una
     * navegación empezada en accounts.google.com, así que el navegador no la
     * manda y el panel ve a alguien sin sesión. Pasó tal cual: el login se
     * registró dos veces en la bitácora y el navegador volvía al formulario.
     *
     * La solución es dar un salto más desde el propio dominio. Va con
     * `<meta http-equiv="refresh">` y no con JavaScript porque un script en
     * línea necesitaría nonce y porque esto tiene que funcionar incluso si
     * algo del script falla; el enlace de abajo es la red por si el refresh
     * estuviera deshabilitado.
     *
     * La alternativa era aflojar la cookie a `SameSite=Lax`. Se descartó:
     * esto resuelve el único caso que lo necesita sin tocar el resto.
     */
    public static function landing(string $to, string $titulo = 'Entrando…'): self
    {
        if (!str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = '/';
        }

        $destino = htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<meta http-equiv="refresh" content="0; url=' . $destino . '">'
            . '<title>' . htmlspecialchars($titulo, ENT_QUOTES) . '</title></head>'
            . '<body><p><a href="' . $destino . '">Seguí acá si no te lleva solo.</a></p></body></html>';

        return self::html($html, 200, ['Cache-Control' => 'no-store, max-age=0']);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            // Defensa contra inyección de cabeceras: nada con saltos de línea.
            if (preg_match('/[\r\n]/', (string) $value)) {
                continue;
            }
            header($name . ': ' . $value, true);
        }

        // Un HEAD lleva las mismas cabeceras y ningún cuerpo.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }

        echo $this->body;
    }
}
