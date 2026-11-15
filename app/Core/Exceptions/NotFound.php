<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

final class NotFound extends HttpException
{
    public function __construct(string $message = 'No encontramos esta página.')
    {
        parent::__construct(404, $message);
    }
}
