<?php

declare(strict_types=1);

/**
 * Arranque común a web y consola.
 *
 * Una sola dependencia de terceros en todo el proyecto: HTMLPurifier, que es
 * el saneador del contenido del editor. No es algo que convenga escribir a
 * mano, y es el único lugar donde aceptamos código ajeno.
 */

define('ROOT', dirname(__DIR__));

require ROOT . '/vendor/autoload.php';
require ROOT . '/app/Core/helpers.php';

return App\Core\Kernel::boot(ROOT);
