<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Instantánea inmutable del request.
 *
 * La IP nunca se guarda cruda en ningún lado: lo que circula es ipHash,
 * un HMAC con el pepper de la instalación. Alcanza para contar y limitar,
 * y deja de ser un dato personal almacenado.
 */
final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $ip,
        public readonly string $ipHash,
        public readonly bool $secure,
        public readonly string $host,
    ) {
    }

    public static function capture(string $pepper, bool $trustProxy = false): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = rawurldecode($path);

        // Normalización: una sola barra, sin barra final salvo la raíz.
        $path = preg_replace('#/{2,}#', '/', $path) ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $ip = self::clientIp($trustProxy);

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            path: $path,
            query: $_GET,
            body: $_POST,
            headers: self::headers(),
            ip: $ip,
            ipHash: substr(hash_hmac('sha256', $ip, $pepper), 0, 32),
            secure: (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
                || ($trustProxy && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            host: (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        );
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function post(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    public function postRaw(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function userAgent(): string
    {
        return mb_substr($this->header('user-agent'), 0, 255);
    }

    /** Página de un listado, siempre dentro de un rango sano. */
    public function page(int $max = 500): int
    {
        $page = (int) ($this->query['page'] ?? 1);

        return max(1, min($page, $max));
    }

    private static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $out[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        return $out;
    }

    /**
     * Solo se mira X-Forwarded-For si la configuración lo habilita. Sin eso,
     * cualquiera manda la cabecera y se saltea el límite de tasa.
     */
    private static function clientIp(bool $trustProxy): string
    {
        if ($trustProxy) {
            $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($fwd !== '') {
                $first = trim(explode(',', $fwd)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
