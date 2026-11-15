<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Lector de .env.
 *
 * A propósito no usa putenv() ni toca $_ENV: así los valores no se heredan
 * a procesos hijos ni aparecen en un phpinfo() o en getenv() desde una
 * librería de terceros.
 */
final class Env
{
    /** @var array<string,string> */
    private array $values = [];

    private function __construct()
    {
    }

    public static function load(string $path): self
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Falta el archivo .env. Corré: php bin/console install');
        }

        $env = new self();
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Comillas opcionales alrededor del valor.
            if (strlen($value) >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                    $value = substr($value, 1, -1);
                }
            }

            $env->values[$key] = $value;
        }

        return $env;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->values[$key] ?? $default;
    }

    public function require(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if ($value === null || $value === '') {
            throw new RuntimeException("Falta la variable {$key} en .env");
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }
}
