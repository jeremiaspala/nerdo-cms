<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

final class Category
{
    public function __construct(private readonly Db $db)
    {
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->first(
            'SELECT id, slug, name, description, meta_title, meta_description
               FROM categories WHERE slug = :slug',
            ['slug' => $slug]
        );
    }

    /** Con el conteo de entradas publicadas, que es lo que se muestra. */
    public function allWithCounts(int $min = 1): array
    {
        return $this->db->select(
            'SELECT c.id, c.slug, c.name, COUNT(pc.post_id) AS total
               FROM categories c
               LEFT JOIN post_categories pc ON pc.category_id = c.id
               LEFT JOIN posts p ON p.id = pc.post_id
                    AND p.status = :status AND p.published_at <= NOW()
              GROUP BY c.id, c.slug, c.name
             HAVING total >= :min
              ORDER BY total DESC, c.name',
            ['status' => Post::STATUS_PUBLISHED, 'min' => $min]
        );
    }

    /**
     * Crea una categoría y devuelve la fila.
     *
     * Si ya existe una con el mismo slug, devuelve esa en vez de fallar: dos
     * categorías que se llaman igual no le sirven a nadie, y desde el editor
     * lo esperable es «ponela si no está».
     *
     * @return array{id:int,slug:string,name:string,nueva:bool}
     */
    public function create(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $slug = slugify($name, 80);

        $existing = $this->db->first(
            'SELECT id, slug, name FROM categories WHERE slug = :slug',
            ['slug' => $slug]
        );

        if ($existing !== null) {
            return [
                'id' => (int) $existing['id'],
                'slug' => (string) $existing['slug'],
                'name' => (string) $existing['name'],
                'nueva' => false,
            ];
        }

        $id = $this->db->insert('categories', [
            'slug' => $slug,
            'name' => mb_substr($name, 0, 80),
        ]);

        return ['id' => $id, 'slug' => $slug, 'name' => mb_substr($name, 0, 80), 'nueva' => true];
    }

    public function countPublished(int $categoryId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*)
               FROM posts p
               JOIN post_categories pc ON pc.post_id = p.id
              WHERE pc.category_id = :id
                AND p.status = :status
                AND p.published_at <= NOW()',
            ['id' => $categoryId, 'status' => Post::STATUS_PUBLISHED]
        );
    }
}
