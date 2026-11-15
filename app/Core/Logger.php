<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Log en JSON por línea, un archivo por día.
 *
 * Nada de lo que se escribe acá se le muestra nunca al visitante: en la
 * respuesta sale solo el identificador del incidente.
 */
final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function exception(string $incidentId, Throwable $e, array $context = []): void
    {
        $this->write('error', $incidentId, $context + [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'ts' => date('c'),
            'level' => $level,
            'msg' => $message,
            'ctx' => $this->scrub($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($line === false) {
            return;
        }

        $file = rtrim($this->dir, '/') . '/app-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** Nunca dejar que una contraseña o un token terminen en el log. */
    private function scrub(array $context): array
    {
        $secret = '/pass|secret|token|key|pepper|totp|cookie|authorization/i';

        foreach ($context as $k => $v) {
            if (is_string($k) && preg_match($secret, $k)) {
                $context[$k] = '[oculto]';
            } elseif (is_array($v)) {
                $context[$k] = $this->scrub($v);
            }
        }

        return $context;
    }
}
