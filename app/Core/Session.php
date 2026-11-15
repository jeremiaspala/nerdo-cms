<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    private bool $started = false;
    private ?DbSessionHandler $handler = null;

    public function __construct(
        private readonly Db $db,
        private readonly string $name,
        private readonly int $idle,
        private readonly int $absolute,
        private readonly bool $secure,
    ) {
    }

    /**
     * La sesión se abre solo cuando hace falta. Un visitante anónimo que lee
     * una entrada no recibe cookie, así que el caché de página sirve para
     * todos por igual.
     */
    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            return;
        }

        $this->handler = new DbSessionHandler($this->db, $this->idle, $this->absolute);
        session_set_save_handler($this->handler, true);

        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.sid_length', '64');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.lazy_write', '1');

        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    /** Mensaje de un solo uso para mostrar después de un redirect. */
    public function flash(string $message, string $type = 'ok'): void
    {
        $this->set('_flash', ['type' => $type, 'message' => $message]);
    }

    public function takeFlash(): ?array
    {
        $flash = $this->get('_flash');
        if ($flash !== null) {
            $this->forget('_flash');
        }

        return is_array($flash) ? $flash : null;
    }

    /**
     * Marca de quién es esta sesión, para poder revocarla después.
     * Lo toma el manejador en el momento de escribir, al cerrar el script.
     */
    public function setOwner(?int $userId, string $ipHash, string $userAgent): void
    {
        $this->start();
        $this->handler?->setOwner($userId, $ipHash, $userAgent);
    }

    /** Obligatorio después de iniciar sesión: evita la fijación de sesión. */
    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $this->start();
        $_SESSION = [];
        session_destroy();
        $this->started = false;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}
