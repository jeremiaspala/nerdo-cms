<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Media
{
    public function __construct(private readonly Db $db)
    {
    }

    /** Deduplicación por contenido: la misma imagen no se guarda dos veces. */
    public function findBySha(string $sha): ?array
    {
        return $this->db->first('SELECT * FROM media WHERE sha256 = :sha', ['sha' => $sha]);
    }

    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM media WHERE id = :id', ['id' => $id]);
    }

    public function create(array $data): int
    {
        return $this->db->insert('media', $data);
    }

    public function setAlt(int $id, string $alt): void
    {
        $this->db->run(
            'UPDATE media SET alt = :alt WHERE id = :id',
            ['id' => $id, 'alt' => mb_substr($alt, 0, 300)]
        );
    }

    public function recent(int $limit = 60, int $offset = 0): array
    {
        return $this->db->select(
            'SELECT * FROM media ORDER BY id DESC LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset]
        );
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM media');
    }

    /** Dónde se está usando: hay que saberlo antes de borrar. */
    public function usage(int $id): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM posts WHERE featured_media_id = :id OR og_media_id = :id2',
            ['id' => $id, 'id2' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM media WHERE id = :id', ['id' => $id]);
    }
}
