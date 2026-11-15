<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * Error esperado, con código HTTP.
 *
 * Lo que lleva en $message sí se le muestra al visitante, así que nunca
 * debe contener detalle interno. Para eso está el log.
 */
class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
