<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Kernel;
use App\Models\Media;
use App\Models\Post;
use RuntimeException;
use Throwable;

/**
 * Importador de WordPress, leyendo directo de su base.
 *
 * Se lee de la base y no de la REST API por dos motivos: la API no expone
 * borradores ni comentarios, y pegarle 150 veces al sitio en producción para
 * algo que se puede hacer con un volcado es maltratar el servidor.
 *
 * Es idempotente: identifica por (tipo, slug), así que se puede correr
 * muchas veces mientras se afina la conversión sin duplicar nada.
 */
final class WordPressImporter
{
    private array $stats = [];
    /** @var array<string,int|null> URL original -> id de media (o null si no se pudo) */
    private array $mediaCache = [];
    private array $categoryMap = [];

    private readonly Post $posts;
    private readonly Media $media;
    private readonly ImageProcessor $images;
    private readonly HtmlSanitizer $sanitizer;

    public function __construct(
        private readonly Kernel $app,
        private readonly Db $src,
        private readonly string $prefix,
        private readonly string $uploadsDir,
        private readonly array $sourceHosts,
        private readonly string $oldPathPrefix,
        private readonly bool $dryRun,
        private readonly bool $fetchExternal = false,
    ) {
        $this->posts = new Post($app->db);
        $this->media = new Media($app->db);
        $this->images = new ImageProcessor($app->root . '/public/uploads');
        $this->sanitizer = new HtmlSanitizer($app->root . '/storage/cache/purifier');
    }

    public function run(callable $log): array
    {
        $log($this->dryRun ? 'MODO PRUEBA: no se escribe nada.' : 'Importando de verdad.');

        $this->importCategories($log);
        $this->importContent($log, 'post');
        $this->importContent($log, 'page');
        $this->importComments($log);

        return $this->stats;
    }

    // ------------------------------------------------------------------

    private function importCategories(callable $log): void
    {
        $rows = $this->src->select(
            "SELECT t.term_id, t.name, t.slug, tt.description
               FROM {$this->prefix}terms t
               JOIN {$this->prefix}term_taxonomy tt ON tt.term_id = t.term_id
              WHERE tt.taxonomy = 'category'
              ORDER BY t.name"
        );

        foreach ($rows as $row) {
            $slug = slugify((string) $row['slug']);

            // "Sin categoría" en WordPress es el cajón de lo que nunca se
            // clasificó. No aporta nada al sitio nuevo.
            if (in_array($slug, ['sin-categoria', 'uncategorized'], true)) {
                continue;
            }

            $existing = $this->app->db->first(
                'SELECT id FROM categories WHERE slug = :slug',
                ['slug' => $slug]
            );

            if ($existing !== null) {
                $this->categoryMap[(int) $row['term_id']] = (int) $existing['id'];
                continue;
            }

            $this->bump('categorias');

            if ($this->dryRun) {
                $this->categoryMap[(int) $row['term_id']] = 0;
                continue;
            }

            $this->categoryMap[(int) $row['term_id']] = $this->app->db->insert('categories', [
                'slug' => $slug,
                'name' => mb_substr((string) $row['name'], 0, 120),
                'description' => mb_substr((string) ($row['description'] ?? ''), 0, 500),
                'meta_description' => '',
            ]);
        }

        $log(sprintf('Categorías: %d nuevas, %d en total.',
            $this->stats['categorias'] ?? 0, count($this->categoryMap)));
    }

    private function importContent(callable $log, string $wpType): void
    {
        $rows = $this->src->select(
            "SELECT ID, post_title, post_name, post_content, post_excerpt,
                    post_status, post_date, post_modified
               FROM {$this->prefix}posts
              WHERE post_type = :type
                AND post_status IN ('publish', 'draft', 'pending')
              ORDER BY post_date ASC",
            ['type' => $wpType]
        );

        $target = $wpType === 'page' ? Post::TYPE_PAGE : Post::TYPE_POST;

        foreach ($rows as $row) {
            try {
                $this->importOne($row, $target, $log);
            } catch (Throwable $e) {
                $this->bump('errores');
                $log(sprintf('  ERROR en #%d "%s": %s',
                    (int) $row['ID'], mb_substr((string) $row['post_title'], 0, 50), $e->getMessage()));
            }
        }

        $log(sprintf('%s: %d importadas, %d actualizadas, %d borradores.',
            $wpType === 'page' ? 'Páginas' : 'Entradas',
            $this->stats[$target . '_nuevas'] ?? 0,
            $this->stats[$target . '_actualizadas'] ?? 0,
            $this->stats[$target . '_borradores'] ?? 0));
    }

    private function importOne(array $row, string $type, callable $log): void
    {
        $wpId = (int) $row['ID'];
        $title = trim(html_entity_decode((string) $row['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($title === '') {
            $title = 'Sin título #' . $wpId;
        }

        $slug = slugify((string) $row['post_name'] !== '' ? (string) $row['post_name'] : $title);

        // 1. Contenido: shortcodes, bloques, wpautop, imágenes locales.
        $converter = new WpContent(fn (string $url): ?string => $this->resolveImageUrl($url));
        $html = $converter->transform((string) $row['post_content']);

        foreach ($converter->stats() as $k => $v) {
            $this->bump('contenido_' . $k, $v);
        }

        // 2. Saneado: exactamente el mismo que para algo escrito en el panel.
        $html = $this->sanitizer->post($html);
        $text = HtmlSanitizer::toText($html);

        // 3. Imagen destacada.
        $featuredId = $this->featuredMediaId($wpId);

        $status = $row['post_status'] === 'publish' ? Post::STATUS_PUBLISHED : Post::STATUS_DRAFT;
        if ($status === Post::STATUS_DRAFT) {
            $this->bump($type . '_borradores');
        }

        $excerpt = trim(strip_tags((string) $row['post_excerpt']));
        if ($excerpt === '') {
            $excerpt = mb_substr($text, 0, 180);
        }

        $data = [
            'title' => mb_substr($title, 0, 300),
            'excerpt' => mb_substr($excerpt, 0, 500),
            'body_html' => $html,
            'body_text' => $text,
            'status' => $status,
            'published_at' => $status === Post::STATUS_PUBLISHED ? (string) $row['post_date'] : null,
            'featured_media_id' => $featuredId,
            'reading_minutes' => HtmlSanitizer::readingMinutes($text),
            'word_count' => count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            'comments_open' => 1,
            // La fecha de modificación real de WordPress. Sin esto, todas
            // las entradas quedan «actualizadas» el día de la importación:
            // en el sitio, en el dateModified del JSON-LD y en el lastmod
            // del sitemap, que es donde más caro sale.
            'updated_at' => (string) $row['post_modified'],
        ];

        $existing = $this->app->db->first(
            'SELECT id FROM posts WHERE type = :type AND slug = :slug',
            ['type' => $type, 'slug' => $slug]
        );

        if ($this->dryRun) {
            $this->bump($type . ($existing ? '_actualizadas' : '_nuevas'));
            // Se registra igual el mapeo para que la prueba también valide
            // que cada comentario encuentra su entrada.
            $this->wpToNew[$wpId] = $existing !== null ? (int) $existing['id'] : 0;

            return;
        }

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->posts->update($id, $data);
            $this->bump($type . '_actualizadas');
        } else {
            $data['type'] = $type;
            $data['slug'] = $slug;
            $id = $this->posts->create($data);
            $this->bump($type . '_nuevas');
        }

        // 4. Categorías.
        if ($type === Post::TYPE_POST) {
            $this->posts->setCategories($id, $this->categoryIdsFor($wpId));
        }

        // 5. El permalink viejo, para que no quede un 404.
        $this->addRedirect($type, $slug);

        $this->wpToNew[$wpId] = $id;
    }

    /** @var array<int,int> id de WordPress -> id nuevo */
    private array $wpToNew = [];

    private function categoryIdsFor(int $wpPostId): array
    {
        $rows = $this->src->select(
            "SELECT tt.term_id
               FROM {$this->prefix}term_relationships tr
               JOIN {$this->prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
              WHERE tr.object_id = :id AND tt.taxonomy = 'category'",
            ['id' => $wpPostId]
        );

        $ids = [];
        foreach ($rows as $row) {
            $mapped = $this->categoryMap[(int) $row['term_id']] ?? null;
            if ($mapped) {
                $ids[] = $mapped;
            }
        }

        return $ids;
    }

    private function featuredMediaId(int $wpPostId): ?int
    {
        $file = $this->src->value(
            "SELECT f.meta_value
               FROM {$this->prefix}postmeta t
               JOIN {$this->prefix}postmeta f
                 ON f.post_id = t.meta_value AND f.meta_key = '_wp_attached_file'
              WHERE t.post_id = :id AND t.meta_key = '_thumbnail_id'
              LIMIT 1",
            ['id' => $wpPostId]
        );

        if (!is_string($file) || $file === '') {
            return null;
        }

        return $this->ingestLocalFile($file);
    }

    /**
     * Mete en la galería una imagen del uploads de WordPress.
     *
     * Pasa por el mismo ImageProcessor que una subida del panel: se
     * re-codifica y se renombra por hash. Lo que traiga adentro el archivo
     * viejo no sobrevive.
     */
    private function ingestLocalFile(string $relative): ?int
    {
        $relative = ltrim($relative, '/');

        if (array_key_exists($relative, $this->mediaCache)) {
            return $this->mediaCache[$relative];
        }

        $path = $this->uploadsDir . '/' . $relative;

        // Que ninguna ruta del volcado nos saque del directorio de uploads.
        $real = realpath($path);
        $base = realpath($this->uploadsDir);

        if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            $this->bump('imagen_no_encontrada');

            return $this->mediaCache[$relative] = null;
        }

        if ($this->dryRun) {
            $this->bump('imagenes');

            return $this->mediaCache[$relative] = 0;
        }

        try {
            $info = $this->images->storeFromPath($real);
        } catch (RuntimeException $e) {
            $this->bump('imagen_rechazada');

            return $this->mediaCache[$relative] = null;
        }

        $existing = $this->media->findBySha($info['sha256']);
        if ($existing !== null) {
            $this->bump('imagen_duplicada');

            return $this->mediaCache[$relative] = (int) $existing['id'];
        }

        $info['alt'] = '';
        $this->bump('imagenes');

        return $this->mediaCache[$relative] = $this->media->create($info);
    }

    /**
     * De una URL de imagen del HTML viejo al archivo local, si se puede.
     *
     * WordPress genera miniaturas con sufijo -800x600: el original es el
     * mismo nombre sin ese sufijo, que es el que tenemos bajado.
     */
    private function resolveImageUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        // Imágenes alojadas en otros sitios (blogspot y demás). No se pueden
        // dejar apuntando afuera: la CSP solo admite img-src 'self', y además
        // un servidor ajeno puede borrarlas cuando se le antoje. Se bajan.
        if ($host !== null && !in_array(strtolower($host), $this->sourceHosts, true)) {
            if (!$this->fetchExternal) {
                $this->bump('imagen_externa_descartada');

                return null;
            }

            $id = $this->ingestRemote($url);
            if ($id === null) {
                return null;
            }

            if ($this->dryRun) {
                return '/uploads/pendiente.jpg';
            }

            $row = $this->media->find($id);

            return $row === null ? null : '/uploads/' . $row['filename'];
        }

        if (!preg_match('#/wp-content/uploads/(.+)$#', $path, $m)) {
            return null;
        }

        $relative = rawurldecode($m[1]);

        // Quitar el sufijo de miniatura para quedarse con el original.
        $original = preg_replace('/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $relative) ?? $relative;

        $id = $this->ingestLocalFile($original);
        if ($id === null && $original !== $relative) {
            $id = $this->ingestLocalFile($relative);
        }

        if ($id === null) {
            return null;
        }

        if ($this->dryRun) {
            return '/uploads/pendiente.jpg';
        }

        $row = $this->media->find($id);

        return $row === null ? null : '/uploads/' . $row['filename'];
    }

    /**
     * Baja una imagen de otro sitio y la mete en la galería.
     *
     * El archivo remoto es un archivo cualquiera hasta que se demuestre lo
     * contrario, así que va a un directorio temporal propio y pasa por el
     * mismo ImageProcessor que todo lo demás: se valida con finfo y se
     * re-codifica. Después se borra el temporal.
     */
    private function ingestRemote(string $url): ?int
    {
        if (array_key_exists($url, $this->mediaCache)) {
            return $this->mediaCache[$url];
        }

        if ($this->dryRun) {
            $this->bump('imagen_externa_bajada');

            return $this->mediaCache[$url] = 0;
        }

        $tmpDir = $this->app->root . '/storage/cache/remote';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0770, true);
        }

        $tmp = $tmpDir . '/' . bin2hex(random_bytes(8));

        $ch = curl_init($url);
        $fh = fopen($tmp, 'wb');

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            // Tope de bytes: un servidor hostil no nos llena el disco.
            CURLOPT_MAXFILESIZE => 12 * 1024 * 1024,
            CURLOPT_USERAGENT => 'NerdoCMS-Importer/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status !== 200) {
            @unlink($tmp);
            $this->bump('imagen_externa_fallida');

            return $this->mediaCache[$url] = null;
        }

        try {
            $info = $this->images->storeFromPath($tmp);
        } catch (RuntimeException) {
            @unlink($tmp);
            $this->bump('imagen_externa_rechazada');

            return $this->mediaCache[$url] = null;
        } finally {
            @unlink($tmp);
        }

        $existing = $this->media->findBySha($info['sha256']);
        if ($existing !== null) {
            return $this->mediaCache[$url] = (int) $existing['id'];
        }

        $info['alt'] = '';
        $this->bump('imagen_externa_bajada');

        return $this->mediaCache[$url] = $this->media->create($info);
    }

    /** Permalinks viejos -> ruta nueva, con y sin barra final. */
    private function addRedirect(string $type, string $slug): void
    {
        $new = ($type === Post::TYPE_PAGE ? '/p/' : '/') . $slug;

        foreach ([$this->oldPathPrefix . $slug, $this->oldPathPrefix . $slug . '/'] as $from) {
            if ($from === $new) {
                continue;
            }

            $this->bump('redirecciones');

            if (!$this->dryRun) {
                $this->app->db->run(
                    'INSERT INTO redirects (from_path, to_path, code) VALUES (:from, :to, 301)
                     ON DUPLICATE KEY UPDATE to_path = VALUES(to_path)',
                    ['from' => $from, 'to' => $new]
                );
            }
        }
    }

    /**
     * Comentarios aprobados.
     *
     * El correo no se guarda en claro: va el mismo HMAC que usa el resto del
     * CMS, que alcanza para reconocer a un comentarista repetido sin
     * almacenar un dato personal de alguien que comentó hace diez años.
     */
    private function importComments(callable $log): void
    {
        $pepper = $this->app->env->require('HASH_PEPPER');

        $rows = $this->src->select(
            "SELECT comment_ID, comment_post_ID, comment_author, comment_author_email,
                    comment_author_url, comment_date, comment_content, comment_parent
               FROM {$this->prefix}comments
              WHERE comment_approved = '1'
              ORDER BY comment_ID ASC"
        );

        // Los hilos de WordPress se conservan. Se recorre por comment_ID
        // ascendente, que es el orden en que se escribieron, así que cuando
        // aparece una respuesta su padre ya tiene id nuevo. Una respuesta a
        // un comentario que no se importó queda como comentario de primer
        // nivel en vez de perderse.
        $map = [];

        foreach ($rows as $row) {
            $postId = $this->wpToNew[(int) $row['comment_post_ID']] ?? null;
            if ($postId === null) {
                $this->bump('comentario_sin_entrada');
                continue;
            }

            $body = $this->sanitizer->comment(nl2br(htmlspecialchars(
                (string) $row['comment_content'], ENT_QUOTES, 'UTF-8'
            ), false));

            $this->bump('comentarios');

            if ($this->dryRun) {
                continue;
            }

            $map[(int) $row['comment_ID']] = $this->app->db->insert('comments', [
                'post_id' => $postId,
                'parent_id' => $map[(int) $row['comment_parent']] ?? null,
                'author_name' => mb_substr(trim((string) $row['comment_author']) ?: 'Anónimo', 0, 120),
                'author_url' => mb_substr((string) $row['comment_author_url'], 0, 300),
                'email_hash' => hash_hmac('sha256', mb_strtolower((string) $row['comment_author_email']), $pepper),
                'body_html' => $body,
                'body_text_hash' => hash('sha256', mb_strtolower(trim(
                    \App\Services\HtmlSanitizer::toText($body)
                ))),
                'status' => 'approved',
                'ip_hash' => '',
                'created_at' => (string) $row['comment_date'],
                'approved_at' => (string) $row['comment_date'],
            ]);
        }

        $log(sprintf('Comentarios: %d importados.', $this->stats['comentarios'] ?? 0));
    }

    private function bump(string $key, int $by = 1): void
    {
        if ($by > 0) {
            $this->stats[$key] = ($this->stats[$key] ?? 0) + $by;
        }
    }
}
