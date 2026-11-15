<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;

/**
 * Caché de página completa, en disco.
 *
 * Reemplaza a `wp-super-cache`. Guarda el HTML ya armado de los visitantes
 * anónimos y lo devuelve sin tocar la base: un request servido desde acá no
 * abre conexión, no consulta entradas y no renderiza plantillas.
 *
 * Lo que lo hace posible es una decisión anterior: **el visitante anónimo no
 * recibe cookie**. Sin cookie, dos lectores distintos piden exactamente la
 * misma página y una sola copia sirve para los dos. Si el sitio marcara a
 * cada visitante, esto no podría existir sin convertirse en una fuga —
 * servirle a alguien el HTML generado para otro— que es el accidente clásico
 * de los cachés de página mal armados.
 *
 * Tres reglas que no se tocan:
 *
 *  1. **Nunca se guarda ni se sirve a un request con sesión.** Si hay cookie
 *     de sesión, el caché no existe para ese request.
 *  2. **Solo respuestas 200 y solo rutas públicas de lectura.** Nada del
 *     panel, nada de `/comentarios`, nada de `/buscar` (su clave la elige
 *     quien escribe la consulta, así que llenar el disco sería gratis).
 *  3. **El nonce no se guarda.** Se reemplaza por una marca al guardar y por
 *     el nonce de este request al servir. Un nonce cacheado sería un nonce
 *     fijo, y un nonce fijo no es un nonce: la CSP dejaría de valer.
 *
 * La visita se sigue contando en un acierto: el id de la entrada va en la
 * cabecera del archivo. Si no, encender el caché haría caer la analítica a
 * cero y parecería un problema de medición.
 */
final class PageCache
{
    /** Ocupa el lugar del nonce dentro del HTML guardado. */
    private const NONCE_MARK = '@@ncms-nonce@@';

    /** Más grande que esto no se guarda: algo raro está pasando. */
    private const MAX_BYTES = 2097152;

    /**
     * Rutas que se cachean. Todo lo que no esté acá, no.
     *
     * La lista es explícita por el mismo motivo que la tabla de rutas: se
     * lee de una sola pasada y no hay forma de que algo entre por descuido.
     */
    private const CACHEABLE = [
        '#^/$#',
        '#^/[a-z0-9-]{1,120}$#',          // entradas
        '#^/p/[a-z0-9-]{1,120}$#',        // páginas
        '#^/categoria/[a-z0-9-]{1,80}$#',
        '#^/archivo/\d{4}(?:/\d{2})?$#',
        '#^/feed(?:\.xml)?$#',
        '#^/sitemap\.xml$#',
        '#^/robots\.txt$#',
    ];

    /** Y estas nunca, aunque coincidan con el patrón amplio de arriba. */
    private const NEVER = ['/buscar', '/salud', '/comentarios', '/contacto'];

    public function __construct(
        private readonly Kernel $app,
        private readonly string $dir,
    ) {
    }

    public function enabled(): bool
    {
        return $this->app->settings->bool('cache_enabled', true);
    }

    public function ttl(): int
    {
        return max(60, min(86400, (int) $this->app->settings->get('cache_ttl', '3600')));
    }

    // ------------------------------------------------------------------
    // Lectura
    // ------------------------------------------------------------------

    /**
     * Respuesta guardada para este request, si hay y no venció.
     *
     * @return array{response:Response,post:?int}|null
     */
    public function fetch(Request $request): ?array
    {
        $key = $this->keyFor($request);

        if ($key === null) {
            return null;
        }

        $file = $this->path($key);
        $raw = @file_get_contents($file);

        if ($raw === false) {
            return null;
        }

        $split = strpos($raw, "\n");
        if ($split === false) {
            return null;
        }

        $meta = json_decode(substr($raw, 0, $split), true);

        if (!is_array($meta) || !isset($meta['t'], $meta['h'])) {
            @unlink($file);

            return null;
        }

        if (time() - (int) $meta['t'] > $this->ttl()) {
            @unlink($file);

            return null;
        }

        $body = str_replace(
            self::NONCE_MARK,
            $this->app->security->nonce,
            substr($raw, $split + 1)
        );

        $headers = is_array($meta['h']) ? $meta['h'] : [];
        $headers['X-Cache'] = 'HIT';
        $headers['Age'] = (string) (time() - (int) $meta['t']);

        $post = isset($meta['post']) ? (int) $meta['post'] : null;

        // ETag solo para lo que no lleva nonce adentro —el feed, el sitemap,
        // robots.txt—. Una página HTML cambia en cada request justamente por
        // el nonce, así que una etiqueta que dijera «idéntico» mentiría, y
        // un navegador reusando HTML con un nonce viejo contra la CSP nueva
        // es exactamente el enredo que no queremos.
        if (isset($meta['etag'])) {
            $headers['ETag'] = (string) $meta['etag'];

            if ($this->matches($request, (string) $meta['etag'])) {
                return ['response' => Response::notModified($headers), 'post' => $post];
            }
        }

        return [
            'response' => Response::cached($body, $headers),
            'post' => $post,
        ];
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /**
     * Guarda la respuesta si corresponde y devuelve la que hay que mandar.
     *
     * Devuelve una respuesta y no void para poder agregarle el ETag en el
     * mismo request que la generó: si no, la primera visita se va sin
     * etiqueta y recién la segunda puede revalidar.
     */
    public function store(Request $request, Response $response, ?int $postId): Response
    {
        if ($response->status !== 200 || strlen($response->body) > self::MAX_BYTES) {
            return $response;
        }

        // Si la respuesta manda cookie, es personal por definición.
        if (isset($response->headers['Set-Cookie'])) {
            return $response;
        }

        $key = $this->keyFor($request);

        if ($key === null) {
            return $response;
        }

        $body = str_replace($this->app->security->nonce, self::NONCE_MARK, $response->body);

        $meta = [
            'p' => $request->path,
            't' => time(),
            'h' => $response->headers,
            'post' => $postId,
        ];

        if (!str_contains($body, self::NONCE_MARK)) {
            $meta['etag'] = '"' . substr(hash('sha256', $body), 0, 32) . '"';
        }

        $this->write($this->path($key), json_encode($meta, JSON_UNESCAPED_SLASHES) . "\n" . $body);

        $headers = $response->headers + ['X-Cache' => 'MISS'];

        if (isset($meta['etag'])) {
            $headers['ETag'] = $meta['etag'];

            if ($this->matches($request, (string) $meta['etag'])) {
                return Response::notModified($headers);
            }
        }

        return Response::cached($response->body, $headers);
    }

    // ------------------------------------------------------------------
    // Invalidación
    // ------------------------------------------------------------------

    /**
     * Borra todo. Es lo que corre al publicar, editar o cambiar un ajuste.
     *
     * Podría ser más fino —solo la portada, la categoría y la entrada—, pero
     * un blog publica unas pocas veces por semana y rearmar 150 páginas sale
     * milisegundos cada una. Una invalidación selectiva mal hecha deja una
     * página vieja dando vueltas sin que nadie se entere, que es mucho peor
     * que un caché frío.
     */
    public function purgeAll(): int
    {
        $n = 0;

        foreach ($this->files() as $file) {
            if (@unlink($file)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Borra las copias de una ruta concreta, incluidas sus paginaciones.
     *
     * Para moderar comentarios: aprobar uno afecta a esa entrada y a ninguna
     * otra página del sitio, y moderar es bastante más frecuente que
     * publicar.
     */
    public function purge(string $path): int
    {
        $n = 0;

        foreach ($this->files() as $file) {
            $head = $this->readMeta($file);

            if ($head !== null && ($head['p'] ?? null) === $path && @unlink($file)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array{archivos:int,bytes:int,mas_viejo:?string} */
    public function stats(): array
    {
        $files = $this->files();
        $bytes = 0;
        $oldest = null;

        foreach ($files as $file) {
            $bytes += (int) @filesize($file);
            $time = (int) @filemtime($file);

            if ($time > 0 && ($oldest === null || $time < $oldest)) {
                $oldest = $time;
            }
        }

        return [
            'archivos' => count($files),
            'bytes' => $bytes,
            'mas_viejo' => $oldest === null ? null : date('Y-m-d H:i:s', $oldest),
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Clave del request, o null si este request no se cachea.
     *
     * Todo lo que decide si una página entra al caché está acá y en ningún
     * otro lado: guardar y servir usan la misma función, así que no pueden
     * desincronizarse.
     */
    public function keyFor(Request $request): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return null;
        }

        // Cookie de sesión presente: este request es de alguien con sesión
        // (o a punto de tenerla). No se guarda ni se sirve.
        $cookie = $request->header('cookie');
        $name = $this->app->env->get('SESSION_NAME', 'ncms') ?? 'ncms';

        if ($cookie !== '' && str_contains($cookie, $name . '=')) {
            return null;
        }

        $path = $request->path;

        foreach (self::NEVER as $never) {
            if ($path === $never || str_starts_with($path, $never . '/')) {
                return null;
            }
        }

        if ($path === $this->app->adminPath || str_starts_with($path, $this->app->adminPath . '/')) {
            return null;
        }

        $matched = false;
        foreach (self::CACHEABLE as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return null;
        }

        // De la query solo se admite `page`. Cualquier otro parámetro —de un
        // enlace con utm_, de un rastreador, del que quiera llenar el disco—
        // deja al request fuera del caché en vez de crear una clave nueva.
        $query = $request->query;
        $page = 1;

        if (isset($query['page'])) {
            $page = max(1, min(500, (int) $query['page']));
            unset($query['page']);
        }

        if ($query !== []) {
            return null;
        }

        return hash('sha256', $path . '|' . $page);
    }

    /**
     * Si el cliente ya tiene esta versión.
     *
     * If-None-Match puede traer varias etiquetas separadas por coma, y un
     * proxy puede marcarlas como débiles con el prefijo W/. Se compara
     * contra la lista, no contra la cabecera entera.
     */
    private function matches(Request $request, string $etag): bool
    {
        $header = $request->header('if-none-match');

        if ($header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag || $candidate === '*') {
                return true;
            }
        }

        return false;
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . $key . '.cache';
    }

    /** @return array<int,string> */
    private function files(): array
    {
        return glob($this->dir . '/*.cache') ?: [];
    }

    private function readMeta(string $file): ?array
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        $line = fgets($handle, 4096);
        fclose($handle);

        if ($line === false) {
            return null;
        }

        $meta = json_decode(trim($line), true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * Escritura atómica: primero a un temporal del mismo directorio y
     * después rename(). Sin esto, dos requests simultáneos sobre la misma
     * página pueden dejar un archivo a medio escribir, y el siguiente
     * visitante recibe medio HTML.
     */
    private function write(string $file, string $contents): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
            return;
        }

        $tmp = $this->dir . '/.' . bin2hex(random_bytes(8)) . '.tmp';

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return;
        }

        @chmod($tmp, 0660);

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
