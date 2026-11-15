<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * Comentarios.
 *
 * El correo no se guarda nunca en claro: lo que va a la base es el mismo HMAC
 * con el pepper de la instalación que usa el resto del CMS. Alcanza para
 * reconocer a quien ya comentó, para el avatar de iniciales y para ponerlo en
 * una lista negra, y deja de ser un dato personal almacenado. También es lo
 * que hace que un volcado de la base no sea una lista de correos.
 */
final class Comment
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const SPAM = 'spam';
    public const TRASH = 'trash';

    /** Hasta dónde se anida visualmente antes de aplanar. */
    public const MAX_DEPTH = 3;

    public function __construct(private readonly Db $db)
    {
    }

    // ------------------------------------------------------------------
    // Frente
    // ------------------------------------------------------------------

    /**
     * Hilo completo de una entrada: en orden de lectura y con la profundidad
     * de cada comentario ya resuelta.
     *
     * Una sola consulta; el árbol se arma en PHP y se devuelve aplanado. La
     * plantilla sangra por clase (`cmt-d1`, `cmt-d2`) en vez de abrir una
     * lista anidada: así el tema no necesita recursión y el panel y el frente
     * recorren la misma estructura.
     *
     * @return array<int,array<string,mixed>> cada fila con 'depth'
     */
    public function threadFor(int $postId): array
    {
        $rows = $this->db->select(
            'SELECT id, parent_id, author_name, author_url, author_source, email_hash,
                    body_html, created_at
               FROM comments
              WHERE post_id = :post AND status = :status
              ORDER BY created_at ASC, id ASC',
            ['post' => $postId, 'status' => self::APPROVED]
        );

        return self::nest($rows);
    }

    public function countApproved(int $postId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM comments WHERE post_id = :post AND status = :status',
            ['post' => $postId, 'status' => self::APPROVED]
        );
    }

    /**
     * Cuántos comentarios aprobados tiene cada entrada de una lista.
     * Para los listados, que muestran el número sin traer los comentarios.
     *
     * @param  array<int,int> $postIds
     * @return array<int,int> id de entrada => cantidad
     */
    public function countsFor(array $postIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $postIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT post_id, COUNT(*) AS n
               FROM comments
              WHERE status = ? AND post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              GROUP BY post_id',
            array_merge([self::APPROVED], $ids)
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['post_id']] = (int) $row['n'];
        }

        return $out;
    }

    /** Padre válido: tiene que estar aprobado y ser de la misma entrada. */
    public function validParent(int $parentId, int $postId): ?int
    {
        if ($parentId <= 0) {
            return null;
        }

        $ok = $this->db->exists(
            'SELECT 1 FROM comments WHERE id = :id AND post_id = :post AND status = :status',
            ['id' => $parentId, 'post' => $postId, 'status' => self::APPROVED]
        );

        return $ok ? $parentId : null;
    }

    public function create(array $data): int
    {
        return $this->db->insert('comments', $data);
    }

    /** Cuántos mandó esta IP en la última hora, sin importar el estado. */
    public function recentFromIp(string $ipHash, int $minutes = 60): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM comments
              WHERE ip_hash = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :mins MINUTE)',
            ['ip' => $ipHash, 'mins' => $minutes]
        );
    }

    // ------------------------------------------------------------------
    // Panel
    // ------------------------------------------------------------------

    public function adminList(?string $status, int $limit, int $offset): array
    {
        $sql = 'SELECT c.*, p.title AS post_title, p.slug AS post_slug, p.type AS post_type,
                       parent.author_name AS parent_author
                  FROM comments c
                  JOIN posts p ON p.id = c.post_id
                  LEFT JOIN comments parent ON parent.id = c.parent_id';
        $params = ['limit' => $limit, 'offset' => $offset];

        if ($status !== null) {
            $sql .= ' WHERE c.status = :status';
            $params['status'] = $status;
        } else {
            $sql .= " WHERE c.status <> 'trash'";
        }

        return $this->db->select(
            $sql . ' ORDER BY c.created_at DESC, c.id DESC LIMIT :limit OFFSET :offset',
            $params
        );
    }

    public function adminCount(?string $status): int
    {
        if ($status !== null) {
            return (int) $this->db->value(
                'SELECT COUNT(*) FROM comments WHERE status = :status',
                ['status' => $status]
            );
        }

        return (int) $this->db->value("SELECT COUNT(*) FROM comments WHERE status <> 'trash'");
    }

    /** @return array<string,int> */
    public function countsByStatus(): array
    {
        $out = [self::PENDING => 0, self::APPROVED => 0, self::SPAM => 0, self::TRASH => 0];

        foreach ($this->db->select('SELECT status, COUNT(*) AS n FROM comments GROUP BY status') as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT c.*, p.title AS post_title, p.slug AS post_slug, p.type AS post_type
               FROM comments c JOIN posts p ON p.id = c.post_id
              WHERE c.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Cambia el estado de uno o varios.
     *
     * Los ids llegan de checkboxes, así que se fuerzan a entero y se arma la
     * lista de marcadores a mano: es la única forma de pasar un IN variable
     * sin concatenar nada que venga del formulario.
     *
     * @param array<int,int> $ids
     */
    public function setStatus(array $ids, string $status): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === [] || !in_array($status, [self::PENDING, self::APPROVED, self::SPAM, self::TRASH], true)) {
            return 0;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->run(
            "UPDATE comments
                SET status = ?,
                    approved_at = CASE WHEN ? = 'approved' THEN NOW() ELSE NULL END
              WHERE id IN ({$marks})",
            array_merge([$status, $status], $ids)
        )->rowCount();
    }

    /**
     * Rutas públicas de las entradas a las que pertenecen estos comentarios.
     *
     * Las usa el caché de página: aprobar un comentario cambia esa entrada y
     * ninguna otra página del sitio, así que no hay motivo para vaciar todo.
     * Hay que pedirlas **antes** de borrar, por razones obvias.
     *
     * @param  array<int,int> $ids
     * @return array<int,string>
     */
    public function pathsFor(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT DISTINCT p.type, p.slug
               FROM comments c JOIN posts p ON p.id = c.post_id
              WHERE c.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );

        return array_map(
            static fn (array $r): string => ($r['type'] === 'page' ? '/p/' : '/') . $r['slug'],
            $rows
        );
    }

    /** Borrado definitivo. Solo de lo que ya está en la papelera o en spam. */
    public function purge(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->run(
            "DELETE FROM comments WHERE id IN ({$marks}) AND status IN ('trash','spam')",
            $ids
        )->rowCount();
    }

    public function purgeAll(string $status): int
    {
        if (!in_array($status, [self::SPAM, self::TRASH], true)) {
            return 0;
        }

        return $this->db->run('DELETE FROM comments WHERE status = ?', [$status])->rowCount();
    }

    // ------------------------------------------------------------------

    /**
     * Arma el árbol a partir de las filas planas.
     *
     * Pasada la profundidad máxima el comentario se cuelga del último nivel
     * visible en vez de seguir sangrando: a los cuatro niveles la columna de
     * texto en un teléfono tiene seis caracteres de ancho.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function nest(array $rows): array
    {
        $present = [];
        foreach ($rows as $row) {
            $present[(int) $row['id']] = true;
        }

        // Una respuesta a un comentario que no está publicado (borrado, en
        // moderación o marcado como spam después) cuelga de la raíz en vez
        // de desaparecer con él.
        $childrenOf = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] !== null && isset($present[(int) $row['parent_id']])
                ? (int) $row['parent_id']
                : 0;

            $childrenOf[$parentId][] = $row;
        }

        return self::branch($childrenOf, 0, 0);
    }

    /**
     * Recorre el árbol en orden de lectura: cada comentario seguido de sus
     * respuestas. Pasada la profundidad máxima la sangría deja de crecer —
     * al cuarto nivel, en un teléfono, la columna de texto tiene seis
     * caracteres de ancho.
     *
     * @param  array<int,array<int,array<string,mixed>>> $childrenOf
     * @return array<int,array<string,mixed>>
     */
    private static function branch(array $childrenOf, int $parentId, int $depth): array
    {
        $out = [];
        $childDepth = min($depth + 1, self::MAX_DEPTH - 1);

        foreach ($childrenOf[$parentId] ?? [] as $row) {
            $row['depth'] = $depth;
            $out[] = $row;

            foreach (self::branch($childrenOf, (int) $row['id'], $childDepth) as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }
}
