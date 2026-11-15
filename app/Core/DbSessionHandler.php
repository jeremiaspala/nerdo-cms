<?php

declare(strict_types=1);

namespace App\Core;

use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sesiones en base, no en disco.
 *
 * El motivo es poder revocarlas: si alguna vez sospechás de una sesión,
 * un DELETE la cierra en el acto, en todos los dispositivos. Con archivos
 * eso no se puede hacer de forma confiable.
 */
final class DbSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    private ?int $userId = null;
    private string $ipHash = '';
    private string $userAgent = '';

    public function __construct(
        private readonly Db $db,
        private readonly int $idleSeconds,
        private readonly int $absoluteSeconds,
    ) {
    }

    /**
     * A quién pertenece esta sesión.
     *
     * Hay que fijarlo acá y no con un UPDATE después de iniciar sesión: PHP
     * recién escribe la sesión al cerrar el script, así que ese UPDATE no
     * encontraría la fila y el user_id quedaría en NULL — y sin user_id no
     * se pueden revocar las sesiones de alguien.
     */
    public function setOwner(?int $userId, string $ipHash, string $userAgent): void
    {
        $this->userId = $userId;
        $this->ipHash = $ipHash;
        $this->userAgent = $userAgent;
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->db->first(
            'SELECT payload FROM sessions
              WHERE id = :id
                AND expires_at > NOW()
                AND absolute_expires_at > NOW()',
            ['id' => $id]
        );

        return $row === null ? '' : (string) $row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        // Una sesión vacía no se persiste. Sin esto, cada request anónimo que
        // toca el panel deja una fila, y llenar la tabla es cuestión de un
        // bucle de curl.
        if ($data === '' && $this->userId === null) {
            return true;
        }

        $this->db->run(
            'INSERT INTO sessions
                 (id, user_id, payload, ip_hash, user_agent,
                  created_at, updated_at, expires_at, absolute_expires_at)
             VALUES (:id, :uid, :payload, :ip, :ua, NOW(), NOW(),
                     DATE_ADD(NOW(), INTERVAL :idle SECOND),
                     DATE_ADD(NOW(), INTERVAL :abs SECOND))
             ON DUPLICATE KEY UPDATE
                 payload    = VALUES(payload),
                 user_id    = COALESCE(VALUES(user_id), user_id),
                 ip_hash    = VALUES(ip_hash),
                 user_agent = VALUES(user_agent),
                 updated_at = NOW(),
                 expires_at = DATE_ADD(NOW(), INTERVAL :idle2 SECOND)',
            [
                'id' => $id,
                'uid' => $this->userId,
                'payload' => $data,
                'ip' => $this->ipHash,
                'ua' => $this->userAgent,
                'idle' => $this->idleSeconds,
                'abs' => $this->absoluteSeconds,
                'idle2' => $this->idleSeconds,
            ]
        );

        return true;
    }

    /**
     * PHP llama acá —y no a write()— cuando la sesión no cambió, porque
     * `session.lazy_write` está encendido. Es la mayoría de los requests del
     * panel.
     *
     * Por eso acá también se completa de quién es la sesión: si la fila se
     * recreó alguna vez y quedó sin dueño, esta es la única oportunidad de
     * volver a marcarla. Sin esto, una sesión podía seguir autenticando y al
     * mismo tiempo no aparecer en «sesiones abiertas» ni poder cerrarse.
     *
     * El COALESCE es importante en el otro sentido: nunca pisa un dueño que
     * ya esté puesto.
     */
    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db->run(
            'UPDATE sessions
                SET updated_at = NOW(),
                    expires_at = DATE_ADD(NOW(), INTERVAL :idle SECOND),
                    user_id    = COALESCE(user_id, :uid),
                    ip_hash    = IF(ip_hash = \'\', :ip, ip_hash),
                    user_agent = IF(user_agent = \'\', :ua, user_agent)
              WHERE id = :id',
            [
                'id' => $id,
                'idle' => $this->idleSeconds,
                'uid' => $this->userId,
                'ip' => $this->ipHash,
                'ua' => $this->userAgent,
            ]
        );

        return true;
    }

    public function validateId(string $id): bool
    {
        // Solo acepta identificadores con la forma que genera create_sid().
        return (bool) preg_match('/^[a-f0-9]{64}$/', $id);
    }

    public function destroy(string $id): bool
    {
        $this->db->run('DELETE FROM sessions WHERE id = :id', ['id' => $id]);

        return true;
    }

    public function gc(int $maxLifetime): int|false
    {
        return $this->db
            ->run('DELETE FROM sessions WHERE expires_at < NOW() OR absolute_expires_at < NOW()')
            ->rowCount();
    }
}
