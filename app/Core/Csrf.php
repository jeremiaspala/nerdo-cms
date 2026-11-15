<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

final class Csrf
{
    private const KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::KEY);

        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    /** Campo listo para pegar en cualquier formulario. */
    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e($this->token()) . '">';
    }

    /**
     * Si esto falla, el request muere acá. No se registra nada, no se toca
     * la base y no se le explica al atacante qué salió mal.
     */
    public function check(Request $request): void
    {
        if (!$request->isPost()) {
            return;
        }

        if (!Security::sameOrigin($request)) {
            throw new HttpException(403, 'Origen no válido.');
        }

        $sent = $request->post('_token');
        $expected = $this->session->get(self::KEY);

        if (!is_string($expected) || $sent === '' || !hash_equals($expected, $sent)) {
            throw new HttpException(419, 'La sesión del formulario venció. Volvé a cargar la página.');
        }
    }
}
