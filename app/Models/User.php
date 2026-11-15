<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class User
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->first(
            'SELECT id, email, name, pass_hash, role, failed_logins, locked_until,
                    totp_secret, totp_confirmed
               FROM users WHERE email = :email',
            ['email' => mb_strtolower(trim($email))]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->first(
            'SELECT id, email, name, role, totp_confirmed FROM users WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * El secreto TOTP cifrado de una cuenta.
     *
     * Va en un método aparte y no en findById(): esa consulta corre en cada
     * request del panel y no tiene por qué andar cargando el segundo factor
     * en memoria. Acá se pide solo en el paso que lo necesita.
     */
    public function totpSecret(int $id): ?string
    {
        $row = $this->db->first(
            'SELECT totp_secret FROM users WHERE id = :id AND totp_confirmed = 1',
            ['id' => $id]
        );

        return $row === null ? null : $row['totp_secret'];
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM users');
    }

    public function registerFailure(int $id, int $maxAttempts, int $lockMinutes): void
    {
        // Bloqueo escalonado: a partir del quinto intento, cada fallo suma
        // más tiempo. Un ataque de diccionario deja de ser viable sin que a
        // vos te moleste equivocarte una vez.
        $this->db->run(
            'UPDATE users
                SET failed_logins = failed_logins + 1,
                    locked_until = CASE
                        WHEN failed_logins + 1 >= :max
                        THEN DATE_ADD(NOW(), INTERVAL :mins MINUTE)
                        ELSE locked_until
                    END
              WHERE id = :id',
            ['id' => $id, 'max' => $maxAttempts, 'mins' => $lockMinutes]
        );
    }

    public function registerSuccess(int $id): void
    {
        $this->db->run(
            'UPDATE users
                SET failed_logins = 0, locked_until = NULL, last_login_at = NOW()
              WHERE id = :id',
            ['id' => $id]
        );
    }

    public function isLocked(array $user): bool
    {
        return $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time();
    }

    public function updatePassword(int $id, string $plain): void
    {
        $this->db->run(
            'UPDATE users SET pass_hash = :hash WHERE id = :id',
            ['id' => $id, 'hash' => password_hash($plain, PASSWORD_ARGON2ID)]
        );
    }
}
