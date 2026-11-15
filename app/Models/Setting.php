<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Ajustes del sitio: título, META por defecto, redes, analytics.
 *
 * Se leen todos de una sola consulta por request y quedan en memoria. Son
 * pocos y se consultan en cada página; pedirlos de a uno sería absurdo.
 */
final class Setting
{
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'on'], true);
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->select('SELECT `key`, `value` FROM settings') as $row) {
                $this->cache[(string) $row['key']] = (string) $row['value'];
            }
        }

        return $this->cache;
    }

    public function set(string $key, string $value): void
    {
        $this->db->run(
            'INSERT INTO settings (`key`, `value`, updated_at) VALUES (:k, :v, NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()',
            ['k' => $key, 'v' => $value]
        );

        $this->cache = null;
    }
}
