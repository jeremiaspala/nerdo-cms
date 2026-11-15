<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Mensajes del formulario de contacto.
 *
 * El cuerpo se guarda como texto plano y se imprime escapado: un mensaje de
 * contacto no tiene por qué permitir ni una etiqueta. Es la diferencia con
 * los comentarios, que sí pasan por el saneador porque necesitan párrafos.
 */
final class Message
{
    public const NEW = 'new';
    public const READ = 'read';
    public const ARCHIVED = 'archived';
    public const SPAM = 'spam';

    public const STATUSES = [self::NEW, self::READ, self::ARCHIVED, self::SPAM];

    public function __construct(private readonly Db $db)
    {
    }

    public function create(array $data): int
    {
        return $this->db->insert('messages', $data);
    }

    public function markMailed(int $id): void
    {
        $this->db->run('UPDATE messages SET mailed = 1 WHERE id = :id', ['id' => $id]);
    }

    /** Cuántos mandó esta IP en las últimas horas, para el límite diario. */
    public function recentFromIp(string $ipHash, int $hours = 24): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM messages
              WHERE ip_hash = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :h HOUR)',
            ['ip' => $ipHash, 'h' => $hours]
        );
    }

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------

    public function adminList(?string $status, int $limit, int $offset): array
    {
        $sql = 'SELECT * FROM messages';
        $params = ['limit' => $limit, 'offset' => $offset];

        if ($status !== null) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        } else {
            $sql .= " WHERE status <> 'spam'";
        }

        return $this->db->select(
            $sql . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            $params
        );
    }

    public function adminCount(?string $status): int
    {
        if ($status !== null) {
            return (int) $this->db->value(
                'SELECT COUNT(*) FROM messages WHERE status = :status',
                ['status' => $status]
            );
        }

        return (int) $this->db->value("SELECT COUNT(*) FROM messages WHERE status <> 'spam'");
    }

    /** @return array<string,int> */
    public function countsByStatus(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);

        foreach ($this->db->select('SELECT status, COUNT(*) AS n FROM messages GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * @param array<int,int> $ids
     */
    public function setStatus(array $ids, string $status): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === [] || !in_array($status, self::STATUSES, true)) {
            return 0;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->run(
            "UPDATE messages
                SET status = ?,
                    read_at = CASE WHEN read_at IS NULL AND ? <> 'new' THEN NOW() ELSE read_at END
              WHERE id IN ({$marks})",
            array_merge([$status, $status], $ids)
        )->rowCount();
    }

    /** @param array<int,int> $ids */
    public function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return 0;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->run("DELETE FROM messages WHERE id IN ({$marks})", $ids)->rowCount();
    }

    public function purgeSpam(): int
    {
        return $this->db->run("DELETE FROM messages WHERE status = 'spam'")->rowCount();
    }
}
