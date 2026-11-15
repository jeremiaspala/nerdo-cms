<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Models\User;

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;
    private const SESSION_KEY = '_uid';
    private const FINGERPRINT_KEY = '_fp';

    private ?array $user = null;
    private readonly User $users;

    public function __construct(private readonly Kernel $app)
    {
        $this->users = new User($app->db);
    }

    /**
     * Devuelve el usuario si las credenciales son válidas.
     *
     * El mensaje de error es siempre el mismo: si decís "ese correo no
     * existe" estás confirmando qué cuentas hay. Y el hash se verifica
     * incluso cuando el usuario no existe, para que el tiempo de respuesta
     * no delate la diferencia.
     */
    public function attempt(Request $request, string $email, string $password): array
    {
        $generic = 'Correo o contraseña incorrectos.';

        // Dos límites a la vez: por IP y por cuenta. El primero frena el
        // barrido; el segundo, el ataque dirigido desde muchas IP.
        $this->app->limiter->hit('login_ip', $request->ipHash, max: 10, perSeconds: 900);
        $this->app->limiter->hit('login_user', substr(hash('sha256', mb_strtolower($email)), 0, 32), max: 10, perSeconds: 900);

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            // Hash falso para igualar el tiempo con el camino real.
            password_verify($password, '$argon2id$v=19$m=65536,t=4,p=1$ZmFrZXNhbHRmYWtlcw$Zm9vYmFyZm9vYmFyZm9vYmFyZm9vYmFyZm9vYmFyZm8');
            $this->audit(null, 'login.fail', $email, $request);

            throw new HttpException(401, $generic);
        }

        if ($this->users->isLocked($user)) {
            $this->audit((int) $user['id'], 'login.locked', $email, $request);

            throw new HttpException(429, 'La cuenta está bloqueada por intentos fallidos. Esperá unos minutos.');
        }

        if (!password_verify($password, (string) $user['pass_hash'])) {
            $this->users->registerFailure((int) $user['id'], self::MAX_ATTEMPTS, self::LOCK_MINUTES);
            $this->audit((int) $user['id'], 'login.fail', $email, $request);

            throw new HttpException(401, $generic);
        }

        // Si cambió el costo del algoritmo, se rehashea en silencio.
        if (password_needs_rehash((string) $user['pass_hash'], PASSWORD_ARGON2ID)) {
            $this->users->updatePassword((int) $user['id'], $password);
        }

        return $user;
    }

    /** Inicia la sesión del usuario ya verificado. */
    public function login(Request $request, array $user): void
    {
        // Obligatorio: evita la fijación de sesión.
        $this->app->session->regenerate();

        $this->app->session->set(self::SESSION_KEY, (int) $user['id']);
        $this->app->session->set(self::FINGERPRINT_KEY, $this->fingerprint($request));

        // La fila de sesión guarda a quién pertenece, para poder cerrarlas
        // todas de un DELETE. Se fija en el manejador y no con un UPDATE,
        // porque la fila todavía no existe: PHP escribe al cerrar el script.
        $this->app->session->setOwner((int) $user['id'], $request->ipHash, $request->userAgent());

        $this->users->registerSuccess((int) $user['id']);
        $this->app->limiter->clear('login_ip', $request->ipHash);
        $this->audit((int) $user['id'], 'login.ok', (string) $user['email'], $request);
    }

    public function logout(Request $request): void
    {
        $id = $this->id();
        if ($id !== null) {
            $this->audit($id, 'logout', '', $request);
        }

        $this->app->session->destroy();
    }

    public function id(): ?int
    {
        $id = $this->app->session->get(self::SESSION_KEY);

        return is_int($id) ? $id : null;
    }

    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $id = $this->id();

        return $this->user = $id === null ? null : $this->users->findById($id);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Puerta de todo el panel.
     *
     * Verifica además que la sesión se siga usando desde el mismo contexto:
     * si la cookie aparece en otra IP o con otro navegador, se cierra. Es
     * una heurística, no una garantía, pero encarece bastante el robo de
     * cookie.
     */
    public function requireAdmin(Request $request): array
    {
        $user = $this->user();

        if ($user === null) {
            throw new HttpException(401, 'Iniciá sesión para continuar.');
        }

        $expected = $this->app->session->get(self::FINGERPRINT_KEY);
        if (!is_string($expected) || !hash_equals($expected, $this->fingerprint($request))) {
            $this->audit((int) $user['id'], 'session.fingerprint_mismatch', '', $request);
            $this->app->session->destroy();

            throw new HttpException(401, 'Tu sesión se cerró por seguridad. Iniciá sesión de nuevo.');
        }

        if ($user['role'] !== 'admin' && $user['role'] !== 'editor') {
            throw new HttpException(403, 'No tenés permiso.');
        }

        // Se vuelve a marcar de quién es la sesión en cada request del panel,
        // y no solo al iniciarla.
        //
        // La fila de sesión puede recrearse —por ejemplo, si se borraron
        // todas las de la cuenta mientras esta estaba en uso—, y en esa
        // recreación el user_id se perdía. El resultado era una sesión que
        // seguía autenticando pero no aparecía en «sesiones abiertas» ni se
        // podía cerrar desde ahí: invisible y no revocable, que es la peor
        // combinación posible.
        $this->app->session->setOwner((int) $user['id'], $request->ipHash, $request->userAgent());

        return $user;
    }

    public function audit(?int $userId, string $action, string $target, Request $request, ?string $detail = null): void
    {
        $this->app->db->insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'target' => mb_substr($target, 0, 190),
            'detail' => $detail,
            'ip_hash' => $request->ipHash,
        ]);
    }

    private function fingerprint(Request $request): string
    {
        return hash_hmac(
            'sha256',
            $request->ipHash . '|' . $request->userAgent(),
            $this->app->env->require('APP_KEY')
        );
    }
}
