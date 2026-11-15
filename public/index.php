<?php

declare(strict_types=1);

/**
 * Único archivo PHP alcanzable desde internet.
 *
 * Todo lo demás vive por encima del DocumentRoot. Si este archivo es el único
 * punto de entrada, la mitad de las clases de ataque de un CMS clásico
 * dejan de aplicar: no hay wp-login.php, no hay xmlrpc.php, no hay un .php
 * por plugin esperando a que alguien lo invoque directo.
 */

use App\Core\Request;
use App\Core\Response;

$app = require dirname(__DIR__) . '/app/bootstrap.php';

// Cabeceras antes de cualquier byte de salida.
$app->security->sendHeaders();

try {
    $request = Request::capture(
        $app->env->require('HASH_PEPPER'),
        $app->env->bool('TRUST_PROXY')
    );

    $response = $app->handle($request);
} catch (Throwable $e) {
    $response = $app->handleThrowable($e);
}

$response->send();
