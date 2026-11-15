<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Enlaces de recuperación de contraseña.
 *
 * Reglas, todas con su motivo:
 *
 *  - **Se guarda el hash del token, no el token.** Un enlace pendiente es,
 *    por una hora, equivalente a la contraseña.
 *  - **Un solo uso y una hora de vida.** Un enlace que queda sirviendo en la
 *    bandeja de entrada para siempre es una llave de repuesto escondida bajo
 *    el felpudo.
 *  - **Al usar uno se invalidan todos los de esa cuenta.** Si alguien pidió
 *    tres y uno se usó, los otros dos no tienen por qué seguir sirviendo.
 */
final class PasswordReset
{
    /** Cuánto vive un enlace. Una hora es lo que tarda alguien en leer el correo. */
    public const TTL_MINUTES = 60;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Crea un enlace y devuelve el token en claro, que es lo único que sale
     * de acá sin hashear y va directo al correo.
     */
    public function create(int $userId, string $ipHash): string
    {
        $token = bin2hex(random_bytes(32));

        $this->db->run(
            'INSERT INTO password_resets (token_hash, user_id, ip_hash, expires_at)
             VALUES (:h, :u, :ip, DATE_ADD(NOW(), INTERVAL :min MINUTE))',
            [
                'h' => hash('sha256', $token),
                'u' => $userId,
                'ip' => $ipHash,
                'min' => self::TTL_MINUTES,
            ]
        );

        return $token;
    }

    /** La fila del token si sirve: existe, no venció y no se usó. */
    public function find(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return $this->db->first(
            'SELECT r.token_hash, r.user_id, r.expires_at, u.email, u.name
               FROM password_resets r
               JOIN users u ON u.id = r.user_id
              WHERE r.token_hash = :h
                AND r.used_at IS NULL
                AND r.expires_at > NOW()',
            ['h' => hash('sha256', $token)]
        );
    }

    /** Marca el usado y quema el resto de los de esa cuenta. */
    public function consume(string $token, int $userId): void
    {
        $this->db->transaction(function () use ($token, $userId): void {
            $this->db->run(
                'UPDATE password_resets SET used_at = NOW() WHERE token_hash = :h',
                ['h' => hash('sha256', $token)]
            );

            $this->db->run(
                'DELETE FROM password_resets WHERE user_id = :u AND used_at IS NULL',
                ['u' => $userId]
            );
        });
    }

    /** Cuántos pidió esta cuenta en la última hora. */
    public function recentForUser(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM password_resets
              WHERE user_id = :u AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            ['u' => $userId]
        );
    }

    public function purge(): int
    {
        return $this->db
            ->run('DELETE FROM password_resets WHERE expires_at < NOW() OR used_at IS NOT NULL')
            ->rowCount();
    }
}
