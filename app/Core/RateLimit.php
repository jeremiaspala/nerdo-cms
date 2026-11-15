<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;

/**
 * Límite de tasa por ventana fija, contra la base.
 *
 * Corre temprano en el ciclo del request, antes de consultar contenido:
 * el objetivo es que un atacante consuma lo menos posible antes de que se
 * lo corte. Para un blog, una ventana fija alcanza de sobra.
 */
final class RateLimit
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Suma un intento y falla si se pasó del máximo.
     *
     * @param string $bucket  qué se está limitando: login, comment, search...
     * @param string $subject normalmente el ipHash del request
     */
    public function hit(string $bucket, string $subject, int $max, int $perSeconds): void
    {
        $window = $this->windowStart($perSeconds);

        $this->db->run(
            'INSERT INTO rate_limits (bucket, subject, window_start, hits)
             VALUES (:bucket, :subject, :window, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            ['bucket' => $bucket, 'subject' => $subject, 'window' => $window]
        );

        $hits = (int) $this->db->value(
            'SELECT hits FROM rate_limits
              WHERE bucket = :bucket AND subject = :subject AND window_start = :window',
            ['bucket' => $bucket, 'subject' => $subject, 'window' => $window]
        );

        if ($hits > $max) {
            $retry = $perSeconds - (time() % $perSeconds);

            throw new HttpException(429, 'Demasiados intentos. Probá de nuevo en un rato.', [
                'Retry-After' => (string) $retry,
            ]);
        }
    }

    /** Cuántos intentos quedan, sin sumar uno. */
    public function remaining(string $bucket, string $subject, int $max, int $perSeconds): int
    {
        $hits = (int) $this->db->value(
            'SELECT hits FROM rate_limits
              WHERE bucket = :bucket AND subject = :subject AND window_start = :window',
            [
                'bucket' => $bucket,
                'subject' => $subject,
                'window' => $this->windowStart($perSeconds),
            ]
        );

        return max(0, $max - $hits);
    }

    public function clear(string $bucket, string $subject): void
    {
        $this->db->run(
            'DELETE FROM rate_limits WHERE bucket = :bucket AND subject = :subject',
            ['bucket' => $bucket, 'subject' => $subject]
        );
    }

    public function purge(): int
    {
        return $this->db
            ->run('DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)')
            ->rowCount();
    }

    private function windowStart(int $perSeconds): string
    {
        return date('Y-m-d H:i:s', intdiv(time(), $perSeconds) * $perSeconds);
    }
}
