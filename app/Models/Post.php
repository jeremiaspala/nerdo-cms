<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Entradas y páginas.
 *
 * Una sola tabla con una columna type, igual que WordPress: comparten el 95%
 * del comportamiento (slug, META, estado, autor) y separarlas en dos tablas
 * obliga a duplicar todas las consultas.
 */
final class Post
{
    public const TYPE_POST = 'post';
    public const TYPE_PAGE = 'page';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Toda consulta pública sale por acá, con la imagen destacada ya
     * resuelta. Si cada listado hiciera su propio JOIN, tarde o temprano
     * alguno se olvidaría de filtrar por estado o por fecha.
     */
    private const FIELDS = 'p.id, p.type, p.slug, p.title, p.excerpt, p.body_html,
            p.published_at, p.updated_at, p.meta_title, p.meta_description, p.canonical,
            p.noindex, p.reading_minutes, p.comments_open,
            m.filename AS image, m.alt AS image_alt,
            m.width AS image_width, m.height AS image_height';

    private const FROM = ' FROM posts p LEFT JOIN media m ON m.id = p.featured_media_id';

    private const SELECT = 'SELECT ' . self::FIELDS . self::FROM;

    /** Portada y listados: solo publicadas, nunca futuras. */
    public function published(int $limit, int $offset = 0): array
    {
        return $this->db->select(
            self::SELECT . '
              WHERE p.type = :type
                AND p.status = :status
                AND p.published_at <= NOW()
              ORDER BY p.published_at DESC
              LIMIT :limit OFFSET :offset',
            [
                'type' => self::TYPE_POST,
                'status' => self::STATUS_PUBLISHED,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    public function countPublished(): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM posts
              WHERE type = :type AND status = :status AND published_at <= NOW()',
            ['type' => self::TYPE_POST, 'status' => self::STATUS_PUBLISHED]
        );
    }

    public function findBySlug(string $slug, string $type = self::TYPE_POST): ?array
    {
        return $this->db->first(
            self::SELECT . '
              WHERE p.slug = :slug
                AND p.type = :type
                AND p.status = :status
                AND p.published_at <= NOW()',
            ['slug' => $slug, 'type' => $type, 'status' => self::STATUS_PUBLISHED]
        );
    }

    public function byCategory(string $categorySlug, int $limit, int $offset = 0): array
    {
        return $this->db->select(
            self::SELECT . '
               JOIN post_categories pc ON pc.post_id = p.id
               JOIN categories c ON c.id = pc.category_id
              WHERE c.slug = :slug
                AND p.type = :type
                AND p.status = :status
                AND p.published_at <= NOW()
              ORDER BY p.published_at DESC
              LIMIT :limit OFFSET :offset',
            [
                'slug' => $categorySlug,
                'type' => self::TYPE_POST,
                'status' => self::STATUS_PUBLISHED,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    public function byYearMonth(int $year, ?int $month, int $limit, int $offset = 0): array
    {
        $sql = self::SELECT . '
                 WHERE p.type = :type
                   AND p.status = :status
                   AND p.published_at <= NOW()
                   AND YEAR(p.published_at) = :year';

        $params = [
            'type' => self::TYPE_POST,
            'status' => self::STATUS_PUBLISHED,
            'year' => $year,
            'limit' => $limit,
            'offset' => $offset,
        ];

        if ($month !== null) {
            $sql .= ' AND MONTH(p.published_at) = :month';
            $params['month'] = $month;
        }

        return $this->db->select($sql . ' ORDER BY p.published_at DESC LIMIT :limit OFFSET :offset', $params);
    }

    /**
     * Búsqueda con índice FULLTEXT en modo booleano.
     * El término se limpia de los operadores para que nadie arme consultas raras.
     */
    public function search(string $term, int $limit): array
    {
        $clean = preg_replace('/[+\-><()~*"@]+/', ' ', $term) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        if (mb_strlen($clean) < 3) {
            return [];
        }

        return $this->db->select(
            'SELECT ' . self::FIELDS . ',
                    MATCH(p.title, p.excerpt, p.body_text) AGAINST (:term IN NATURAL LANGUAGE MODE) AS score'
            . self::FROM . '
              WHERE p.status = :status
                AND p.published_at <= NOW()
                AND MATCH(p.title, p.excerpt, p.body_text) AGAINST (:term2 IN NATURAL LANGUAGE MODE)
              ORDER BY score DESC
              LIMIT :limit',
            ['term' => $clean, 'term2' => $clean, 'status' => self::STATUS_PUBLISHED, 'limit' => $limit]
        );
    }

    /** @return array<int,array<string,mixed>> categorías de una entrada */
    public function categoriesOf(int $postId): array
    {
        return $this->db->select(
            'SELECT c.id, c.slug, c.name
               FROM categories c
               JOIN post_categories pc ON pc.category_id = c.id
              WHERE pc.post_id = :id
              ORDER BY c.name',
            ['id' => $postId]
        );
    }

    // ---------------------------------------------------------------
    // Panel. Acá sí se ven borradores y papelera.
    // ---------------------------------------------------------------

    public function adminList(string $type, ?string $status, int $limit, int $offset): array
    {
        $sql = 'SELECT p.id, p.type, p.slug, p.title, p.status, p.published_at, p.updated_at,
                       p.reading_minutes
                  FROM posts p
                 WHERE p.type = :type';
        $params = ['type' => $type, 'limit' => $limit, 'offset' => $offset];

        if ($status !== null) {
            $sql .= ' AND p.status = :status';
            $params['status'] = $status;
        } else {
            $sql .= " AND p.status <> 'trash'";
        }

        return $this->db->select(
            $sql . ' ORDER BY COALESCE(p.published_at, p.updated_at) DESC LIMIT :limit OFFSET :offset',
            $params
        );
    }

    public function adminCount(string $type, ?string $status = null): int
    {
        if ($status !== null) {
            return (int) $this->db->value(
                'SELECT COUNT(*) FROM posts WHERE type = :type AND status = :status',
                ['type' => $type, 'status' => $status]
            );
        }

        return (int) $this->db->value(
            "SELECT COUNT(*) FROM posts WHERE type = :type AND status <> 'trash'",
            ['type' => $type]
        );
    }

    public function findForEdit(int $id): ?array
    {
        return $this->db->first('SELECT * FROM posts WHERE id = :id', ['id' => $id]);
    }

    public function slugTaken(string $slug, string $type, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM posts WHERE slug = :slug AND type = :type';
        $params = ['slug' => $slug, 'type' => $type];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return $this->db->exists($sql, $params);
    }

    /** Slug libre, agregando sufijo numérico si hace falta. */
    public function uniqueSlug(string $base, string $type, ?int $exceptId = null): string
    {
        $slug = $base;
        $n = 2;

        while ($this->slugTaken($slug, $type, $exceptId)) {
            $slug = mb_substr($base, 0, 110) . '-' . $n;
            $n++;
        }

        return $slug;
    }

    public function create(array $data): int
    {
        return $this->db->insert('posts', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('posts', $data, 'id = :where_id', ['where_id' => $id]);
    }

    /** Reemplaza el juego de categorías de una entrada. */
    public function setCategories(int $postId, array $categoryIds): void
    {
        $this->db->transaction(function () use ($postId, $categoryIds): void {
            $this->db->run('DELETE FROM post_categories WHERE post_id = :id', ['id' => $postId]);

            foreach (array_unique(array_map('intval', $categoryIds)) as $catId) {
                if ($catId > 0) {
                    $this->db->run(
                        'INSERT IGNORE INTO post_categories (post_id, category_id) VALUES (:p, :c)',
                        ['p' => $postId, 'c' => $catId]
                    );
                }
            }
        });
    }

    /** @return int[] */
    public function categoryIdsOf(int $postId): array
    {
        return array_map(
            'intval',
            array_column($this->db->select(
                'SELECT category_id FROM post_categories WHERE post_id = :id',
                ['id' => $postId]
            ), 'category_id')
        );
    }

    public function adjacent(string $publishedAt, string $direction): ?array
    {
        $isPrev = $direction === 'prev';

        return $this->db->first(
            'SELECT p.slug, p.title
               FROM posts p
              WHERE p.type = :type
                AND p.status = :status
                AND p.published_at <= NOW()
                AND p.published_at ' . ($isPrev ? '<' : '>') . ' :at
              ORDER BY p.published_at ' . ($isPrev ? 'DESC' : 'ASC') . '
              LIMIT 1',
            ['type' => self::TYPE_POST, 'status' => self::STATUS_PUBLISHED, 'at' => $publishedAt]
        );
    }
}
