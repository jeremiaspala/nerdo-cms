<?php

declare(strict_types=1);

/**
 * Router del servidor embebido de PHP, solo para desarrollo.
 *
 * Imita lo que hace el .htaccess: si el archivo existe se sirve tal cual,
 * y si no, entra todo por public/index.php.
 *
 *   php -S 127.0.0.1:8080 -t public bin/router.php
 *
 * En producción esto no se usa: manda Apache.
 */

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$file = __DIR__ . '/../public' . $path;

// Archivos reales (css, imágenes): que los sirva el servidor embebido.
if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) {
    return false;
}

require __DIR__ . '/../public/index.php';
