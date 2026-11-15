<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Comment;
use App\Models\Post;
use App\Services\HtmlSanitizer;
use App\Services\GoogleOAuth;
use App\Services\Mailer;
use App\Services\Spam;
use Throwable;

/**
 * Comentarios del frente: emitir el desafío y recibir el envío.
 *
 * Las dos rutas contestan JSON y el formulario se envía por fetch. No es por
 * moda: la prueba de trabajo se resuelve en JavaScript, así que un envío sin
 * JavaScript no podría pasar la sexta barrera igual. Haciéndolo por fetch,
 * además, no hace falta sesión ni cookie para mostrarle el resultado a quien
 * comenta, y la página de la entrada sigue siendo cacheable tal cual.
 *
 * El orden de las guardas es el mismo que está escrito en la bitácora y no es
 * arbitrario: lo que se puede rechazar sin tocar la base va primero.
 */
final class CommentController extends Controller
{
    private const MAX_BODY = 5000;

    public function challenge(Request $request): Response
    {
        // Pedir desafíos es barato pero no gratis: un hash y una firma.
        $this->app->limiter->hit('comment_challenge', $request->ipHash, max: 60, perSeconds: 3600);

        $post = $this->commentablePost($request->int('e'));

        if ($post === null) {
            return Response::json(['error' => 'Esta entrada no acepta comentarios.'], 403);
        }

        $spam = new Spam($this->app);
        $challenge = $spam->issue((int) $post['id']);

        return Response::json([
            'token' => $challenge['token'],
            'nonce' => $challenge['nonce'],
            'bits' => $challenge['bits'],
            // El piso de tiempo es del servidor; el cliente lo recibe solo
            // para no dejar apretar "Enviar" antes de que sirva.
            'floor' => 8,
        ]);
    }

    public function store(Request $request): Response
    {
        $spam = new Spam($this->app);
        $comments = new Comment($this->app->db);

        $post = $this->commentablePost($request->int('post_id'));

        if ($post === null) {
            return $this->fail('Esta entrada ya no acepta comentarios.', 403);
        }

        $postId = (int) $post['id'];

        // ---- 1. Desafío firmado. Hace de token CSRF: sin la APP_KEY no se
        //         puede fabricar, y viene atado a esta entrada.
        $claim = $spam->openToken($request->post('t'), $postId);

        if ($claim === null) {
            return $this->fail('El formulario venció. Recargá la página y volvé a intentar.', 400);
        }

        // ---- 2. Límite de tasa. Antes de cualquier consulta de contenido.
        $this->app->limiter->hit('comment', $request->ipHash, max: 10, perSeconds: 600);

        // ---- 3. Campo trampa. Está en el HTML, oculto por CSS, y un humano
        //         no lo ve. Lo completa el que rellena todos los inputs.
        if ($request->post('sitio') !== '' || $request->post('apodo') !== '') {
            return $this->fail('No pudimos aceptar el comentario.', 422);
        }

        // ---- 4. Piso de tiempo.
        if (!$spam->timingOk($claim['t'])) {
            return $this->fail('Demasiado rápido (o demasiado tarde). Recargá la página.', 422);
        }

        // ---- 5. Origen.
        if (!$spam->originOk($request)) {
            return $this->fail('Origen no válido.', 403);
        }

        // ---- 6. Prueba de trabajo, y el nonce se gasta acá.
        if (!$spam->powOk($claim['n'], $request->post('pow'), $claim['b'])) {
            return $this->fail('La verificación del navegador no cerró. Recargá la página.', 422);
        }

        if (!$spam->consumeNonce($claim['n'])) {
            return $this->fail('Ese formulario ya se usó. Recargá la página.', 409);
        }

        // ---- 7. Validación.
        //
        // Si vino un vale de Google, el nombre y el correo salen de ahí y no
        // del formulario: son los que verificó Google y no los que alguien
        // escribió en un input.
        $identidad = $this->abrirIdentidad($request->post('ident'));
        $fuente = $identidad === null ? 'anonimo' : 'google';

        $identidadRequerida = $this->app->settings->get('comments_identidad', 'ambos') === 'google';

        if ($identidadRequerida && $identidad === null) {
            return $this->fail('Para comentar hace falta identificarse con una cuenta de Google.', 403);
        }

        $name = $identidad !== null
            ? $identidad['nombre']
            : mb_substr($request->post('nombre'), 0, 120);
        $email = $identidad !== null
            ? $identidad['email']
            : mb_strtolower($request->post('email'));
        $url = $identidad !== null ? '' : mb_substr($request->post('url'), 0, 300);
        $text = mb_substr($request->postRaw('cuerpo'), 0, self::MAX_BODY);

        if (mb_strlen($name) < 2) {
            return $this->fail('Falta el nombre.', 422, 'nombre');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Ese correo no parece válido. No se publica ni se guarda en claro.', 422, 'email');
        }

        if (mb_strlen(trim($text)) < 3) {
            return $this->fail('El comentario está vacío.', 422, 'cuerpo');
        }

        if ($url !== '' && !preg_match('#^https?://[^\s]+$#i', $url)) {
            return $this->fail('La dirección de tu sitio tiene que empezar con http:// o https://', 422, 'url');
        }

        // Límite por persona además del límite por ráfaga: diez por ventana
        // de diez minutos corta un bombardeo, esto corta el goteo.
        $perHour = max(1, (int) $this->app->settings->get('comments_max_per_hour', '5'));

        if ($comments->recentFromIp($request->ipHash) >= $perHour) {
            return $this->fail('Ya mandaste varios comentarios en la última hora. Dejalo descansar un rato.', 429);
        }

        // ---- 8. Puntaje.
        $emailHash = hash_hmac('sha256', $email, $this->app->env->require('HASH_PEPPER'));
        $verdict = $spam->score($name, $emailHash, $url, $text, $request);

        $status = $this->decideStatus($verdict['score'], $emailHash, $spam, $fuente);

        // ---- 9. Inserción. El cuerpo llega como texto plano y se convierte
        //         a HTML acá: el visitante no puede mandar etiquetas. Igual
        //         pasa por el saneador, que es la barrera que vale.
        $sanitizer = new HtmlSanitizer($this->app->root . '/storage/cache/purifier');
        $bodyHtml = $sanitizer->comment(nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false));

        $id = $comments->create([
            'post_id' => $postId,
            'parent_id' => $comments->validParent($request->int('parent_id'), $postId),
            'author_name' => $name,
            'author_url' => $url,
            'author_source' => $fuente,
            'email_hash' => $emailHash,
            'body_html' => $bodyHtml,
            'body_text_hash' => hash('sha256', mb_strtolower(trim($text))),
            'status' => $status,
            'spam_score' => $verdict['score'],
            'spam_reasons' => mb_substr(implode(' · ', $verdict['reasons']), 0, 400),
            'ip_hash' => $request->ipHash,
            'user_agent' => $request->userAgent(),
            'approved_at' => $status === Comment::APPROVED ? date('Y-m-d H:i:s') : null,
        ]);

        // Al spam no se le avisa a nadie: es la mitad del sentido de tener un
        // puntaje. Un correo por cada intento sería el propio ataque.
        if ($status !== Comment::SPAM) {
            $this->notify($id, $name, $text, (string) $post['title'], $status);
        }

        if ($status === Comment::APPROVED) {
            // Se publicó sin pasar por moderación: la copia cacheada de la
            // entrada ya no tiene este comentario. Al que lo escribió se lo
            // inserta el JavaScript, pero el próximo visitante vería la
            // página vieja.
            $this->app->pages->purge('/' . $post['slug']);

            return Response::json([
                'ok' => true,
                'status' => 'approved',
                'id' => $id,
                // Se devuelve ya renderizado por el tema: el JavaScript lo
                // inserta tal cual y no arma HTML con datos del usuario.
                'html' => $this->app->view->render('comment', [
                    'commentsOpen' => true,
                    'c' => [
                        'id' => $id,
                        'author_name' => $name,
                        'author_url' => $url,
                        'author_source' => $fuente,
                        'email_hash' => $emailHash,
                        'body_html' => $bodyHtml,
                        'created_at' => date('Y-m-d H:i:s'),
                        'depth' => 0,
                        'children' => [],
                    ],
                ]),
            ]);
        }

        return Response::json([
            'ok' => true,
            'status' => 'pending',
            'message' => 'Gracias. Tu comentario quedó esperando moderación.',
        ]);
    }

    // ------------------------------------------------------------------
    // Identidad de Google, sin una sola cookie
    // ------------------------------------------------------------------

    /**
     * Empieza el camino a Google.
     *
     * `volver` tiene que ser una ruta propia: si no, esto sería un
     * redirector abierto con la cara de un sitio conocido, que es la mitad
     * del trabajo de un phishing.
     */
    public function googleStart(Request $request): Response
    {
        $google = new GoogleOAuth($this->app);

        if (!$this->googleHabilitado($google)) {
            return $this->fail('Comentar con Google no está habilitado.', 404);
        }

        $this->app->limiter->hit('comment_google', $request->ipHash, max: 20, perSeconds: 900);

        $volver = (string) $request->get('volver', '/');

        if (!preg_match('#^/[a-z0-9/\-]{0,160}$#i', $volver)) {
            $volver = '/';
        }

        return Response::away($google->authUrl(
            $this->app->url('/comentarios/google/vuelta'),
            ['tipo' => 'comentario', 'volver' => $volver]
        ));
    }

    /**
     * La vuelta de Google.
     *
     * Acá está la decisión que hace que esto no rompa nada: **no se abre
     * sesión ni se manda cookie**. Se arma un vale firmado con la APP_KEY
     * —nombre, correo, vencimiento— y se lo devuelve al navegador en el
     * fragmento de la URL, que no viaja al servidor ni queda en ningún log.
     * El JavaScript lo guarda en localStorage y lo manda con el comentario.
     *
     * Con cookie habría sido más corto, pero el visitante anónimo dejaría de
     * ser anónimo y el caché de página dejaría de servirle a todo el mundo
     * la misma copia. Esa decisión vale más que la comodidad de dos líneas.
     */
    public function googleReturn(Request $request): Response
    {
        $google = new GoogleOAuth($this->app);

        if (!$this->googleHabilitado($google)) {
            return $this->fail('Comentar con Google no está habilitado.', 404);
        }

        $state = $google->openState((string) $request->get('state', ''));

        if ($state === null || ($state['tipo'] ?? '') !== 'comentario') {
            return Response::redirect('/');
        }

        $volver = (string) ($state['volver'] ?? '/');

        if (($request->get('code') ?? '') === '') {
            return Response::redirect($volver . '#ncms-google=cancelado');
        }

        try {
            $identidad = $google->identity(
                (string) $request->get('code'),
                $this->app->url('/comentarios/google/vuelta')
            );
        } catch (\Throwable $e) {
            $this->app->logger->warning('Falló identificar para comentar', ['error' => $e->getMessage()]);

            return Response::redirect($volver . '#ncms-google=error');
        }

        $vale = $this->firmarIdentidad($identidad['nombre'], $identidad['email']);

        return Response::redirect($volver . '#ncms-id=' . $vale);
    }

    /** Vale de identidad: nombre, correo y cuándo vence, firmado. */
    private function firmarIdentidad(string $nombre, string $email): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'n' => mb_substr($nombre, 0, 120),
            'e' => $email,
            't' => time(),
        ], JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');

        $firma = rtrim(strtr(base64_encode(hash_hmac(
            'sha256',
            'ident:' . $payload,
            $this->app->env->require('HASH_PEPPER'),
            true
        )), '+/', '-_'), '=');

        return $payload . '.' . $firma;
    }

    /**
     * Abre el vale si la firma es nuestra y no venció.
     *
     * Dura ocho horas: lo suficiente para leer una entrada, pensarlo y
     * volver a comentar, y poco para que quede dando vueltas en el navegador
     * de una computadora compartida.
     *
     * @return array{nombre:string,email:string}|null
     */
    private function abrirIdentidad(string $vale): ?array
    {
        $partes = explode('.', $vale, 2);

        if (count($partes) !== 2) {
            return null;
        }

        $esperada = hash_hmac('sha256', 'ident:' . $partes[0], $this->app->env->require('HASH_PEPPER'), true);
        $recibida = base64_decode(strtr($partes[1], '-_', '+/'), true);

        if ($recibida === false || !hash_equals($esperada, $recibida)) {
            return null;
        }

        $json = base64_decode(strtr($partes[0], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (!is_array($data) || time() - (int) ($data['t'] ?? 0) > 28800) {
            return null;
        }

        $email = mb_strtolower((string) ($data['e'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return ['nombre' => mb_substr((string) ($data['n'] ?? ''), 0, 120), 'email' => $email];
    }

    private function googleHabilitado(GoogleOAuth $google): bool
    {
        return $this->app->settings->bool('google_enabled', false)
            && $this->app->settings->bool('google_comentarios', true)
            && $google->configurado()
            && in_array($this->app->settings->get('comments_identidad', 'ambos'), ['google', 'ambos'], true);
    }

    // ------------------------------------------------------------------

    /**
     * La entrada tiene que existir, estar publicada y aceptar comentarios.
     *
     * Son tres condiciones y se resuelven en un solo lugar porque las usan
     * las dos rutas: si solo una de las dos las chequeara, la otra sería el
     * agujero.
     */
    private function commentablePost(int $id): ?array
    {
        if ($id <= 0 || !$this->app->settings->bool('comments_enabled', true)) {
            return null;
        }

        $post = $this->app->db->first(
            'SELECT id, title, slug, published_at, comments_open
               FROM posts
              WHERE id = :id AND type = :type AND status = :status AND published_at <= NOW()',
            ['id' => $id, 'type' => Post::TYPE_POST, 'status' => Post::STATUS_PUBLISHED]
        );

        if ($post === null || (int) $post['comments_open'] !== 1) {
            return null;
        }

        return self::withinOpenWindow((string) $post['published_at'], $this->openDays()) ? $post : null;
    }

    public function openDays(): int
    {
        return max(0, (int) $this->app->settings->get('comments_open_days', '90'));
    }

    /**
     * Cierre automático por antigüedad.
     *
     * El spam de enlaces vive casi todo en las entradas viejas: nadie discute
     * una nota de 2013, pero el robot que busca dónde dejar un enlace la
     * encuentra igual. Con 0 no se cierra nunca.
     */
    public static function withinOpenWindow(string $publishedAt, int $days): bool
    {
        if ($days <= 0) {
            return true;
        }

        $published = strtotime($publishedAt);

        return $published !== false && $published >= strtotime("-{$days} days");
    }

    private function decideStatus(int $score, string $emailHash, Spam $spam, string $fuente): string
    {
        if ($score >= Spam::SPAM_THRESHOLD) {
            return Comment::SPAM;
        }

        // Quien se identificó con una cuenta de Google verificada puede
        // pasar directo, si está configurado así. No es confianza ciega: el
        // puntaje de arriba se le aplicó igual, y hacerse diez cuentas de
        // Google para spamear cuesta bastante más que escribir diez nombres
        // distintos en un formulario.
        if ($fuente === 'google' && $this->app->settings->bool('comments_google_autoaprueba', true)) {
            return Comment::APPROVED;
        }

        // Moderar siempre es el valor por defecto y la opción correcta para un
        // blog de una persona. Si se apaga, pasa directo solo quien ya tiene
        // un comentario aprobado con el mismo correo.
        if ($this->app->settings->bool('comments_require_moderation', true)) {
            return Comment::PENDING;
        }

        return $spam->knownCommenter($emailHash) ? Comment::APPROVED : Comment::PENDING;
    }

    private function notify(int $id, string $author, string $text, string $postTitle, string $status): void
    {
        if (!$this->app->settings->bool('comments_notify', true)) {
            return;
        }

        $to = (string) $this->app->settings->get('comments_notify_to', '');

        if ($to === '') {
            $to = (string) $this->app->db->value(
                "SELECT email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"
            );
        }

        if ($to === '') {
            return;
        }

        $queue = $status === Comment::PENDING ? ' (en moderación)' : '';

        try {
            (new Mailer($this->app->settings, $this->app->env->require('APP_KEY')))->send(
                $to,
                "Comentario nuevo{$queue} en «" . mb_substr($postTitle, 0, 80) . '»',
                "{$author} comentó en «{$postTitle}»:\n\n"
                . mb_substr($text, 0, 1200) . "\n\n"
                . 'Moderar: ' . $this->app->url($this->app->adminPath . '/comentarios') . "\n"
                . 'Comentario #' . $id
            );
        } catch (Throwable $e) {
            // Que el correo falle no puede costarle el comentario a quien lo
            // escribió. Queda en el log y la cola del panel lo muestra igual.
            $this->app->logger->warning('No se pudo avisar del comentario #' . $id, [
                'comentario' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fail(string $message, int $status, ?string $field = null): Response
    {
        return Response::json(array_filter([
            'error' => $message,
            'field' => $field,
        ]), $status);
    }
}
