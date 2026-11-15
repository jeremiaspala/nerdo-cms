<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\Admin;
use App\Controllers\Front;
use App\Core\Exceptions\HttpException;
use App\Models\Setting;
use Throwable;

final class Kernel
{
    public readonly Env $env;
    public readonly Logger $logger;
    public readonly Db $db;
    public readonly Session $session;
    public readonly Csrf $csrf;
    public readonly Security $security;
    public readonly RateLimit $limiter;
    public readonly View $view;
    public readonly View $adminView;
    public readonly Router $router;
    public readonly Setting $settings;
    public readonly \App\Services\Analytics $analytics;
    public readonly \App\Services\PageCache $pages;
    public readonly string $adminPath;
    public readonly bool $debug;

    private function __construct(public readonly string $root)
    {
        $this->env = Env::load($root . '/.env');
        $this->debug = $this->env->bool('APP_DEBUG');
        $this->adminPath = '/' . trim($this->env->get('ADMIN_PATH', '/panel') ?? '/panel', '/');

        $this->logger = new Logger($root . '/storage/logs');

        // La zona horaria se fija antes de abrir la conexión: el offset que
        // recibe la base sale de acá.
        date_default_timezone_set($this->env->get('APP_TIMEZONE', 'America/Argentina/Buenos_Aires') ?? 'UTC');

        $this->db = new Db(
            $this->env->require('DB_DSN'),
            $this->env->require('DB_USER'),
            $this->env->get('DB_PASS', '') ?? '',
            (new \DateTimeImmutable('now'))->format('P')
        );

        $https = str_starts_with($this->env->get('APP_URL', '') ?? '', 'https://');

        $this->session = new Session(
            $this->db,
            $this->env->get('SESSION_NAME', 'ncms') ?? 'ncms',
            $this->env->int('SESSION_IDLE', 7200),
            $this->env->int('SESSION_ABSOLUTE', 86400),
            $https
        );

        $this->csrf = new Csrf($this->session);
        $this->security = new Security($https);
        $this->limiter = new RateLimit($this->db);
        $this->settings = new Setting($this->db);
        $this->analytics = new \App\Services\Analytics(
            $this->db,
            $this->settings,
            $this->env->require('HASH_PEPPER')
        );
        $this->pages = new \App\Services\PageCache($this, $root . '/storage/cache/pages');
        $this->view = new View($root . '/themes/teal/templates');
        // El panel tiene sus propias plantillas, fuera del tema: cambiar de
        // tema no puede romper ni alterar la administración.
        $this->adminView = new View($root . '/app/Admin/views');
        $this->router = new Router($this);

        $this->registerRoutes();
    }

    /**
     * Valores mínimos para que el layout pueda renderizar incluso cuando
     * fallamos temprano — una página de error no puede depender de que la
     * base esté sana.
     *
     * Se arma solo cuando hace falta, que es al renderizar un error. Las
     * páginas normales traen sus datos del controlador y pisan estos; armar
     * esto en el constructor costaba dos consultas (ajustes y categorías) en
     * cada request, incluidos los que se sirven del caché sin tocar nada.
     */
    private bool $defaultsShared = false;

    private function shareViewDefaults(): void
    {
        if ($this->defaultsShared) {
            return;
        }

        $this->defaultsShared = true;

        $title = 'Nerdadas!';
        $tagline = '';
        $nav = [];

        try {
            $title = (string) $this->settings->get('site_title', $title);
            $tagline = (string) $this->settings->get('site_tagline', '');
            $nav = (new \App\Models\Category($this->db))->allWithCounts(1);
        } catch (Throwable) {
            // Base caída: seguimos con los valores de arriba.
        }

        $this->view->share('site', [
            'title' => $title,
            'tagline' => $tagline,
            'url' => rtrim($this->env->get('APP_URL', '') ?? '', '/'),
        ]);
        $this->view->share('nav', $nav);
        $this->view->share('flash', null);
        $this->view->share('nonce', $this->security->nonce);
        $this->view->share('year', (int) date('Y'));
        $this->view->share('searchTerm', '');
        $this->view->share('seo', [
            'title' => $title,
            'description' => '',
            'canonical' => $this->url('/'),
            'noindex' => true,
            'image' => null,
            'type' => 'website',
        ]);
    }

    public static function boot(string $root): self
    {
        $app = new self($root);

        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        mb_internal_encoding('UTF-8');

        return $app;
    }

    private function registerRoutes(): void
    {
        $a = preg_quote($this->adminPath, '#');

        // ---- Panel. Va primero para que ninguna ruta pública lo tape.
        $this->router
            ->get("#^{$a}/entrar$#", [Admin\AuthController::class, 'form'])
            ->post("#^{$a}/entrar$#", [Admin\AuthController::class, 'login'])
            ->post("#^{$a}/salir$#", [Admin\AuthController::class, 'logout'])
            ->get("#^{$a}/recuperar$#", [Admin\AuthController::class, 'forgotForm'])
            ->post("#^{$a}/recuperar$#", [Admin\AuthController::class, 'forgotSend'])
            ->get("#^{$a}/recuperar/([a-f0-9]{64})$#", [Admin\AuthController::class, 'resetForm'])
            ->post("#^{$a}/recuperar/([a-f0-9]{64})$#", [Admin\AuthController::class, 'reset'])
            ->get("#^{$a}$#", [Admin\DashboardController::class, 'index'])
            ->get("#^{$a}/(entradas|paginas)$#", [Admin\ContentController::class, 'index'])
            ->get("#^{$a}/(entradas|paginas)/nueva$#", [Admin\ContentController::class, 'create'])
            ->get("#^{$a}/(entradas|paginas)/(\d+)$#", [Admin\ContentController::class, 'edit'])
            ->post("#^{$a}/(entradas|paginas)/guardar$#", [Admin\ContentController::class, 'save'])
            ->post("#^{$a}/(entradas|paginas)/papelera$#", [Admin\ContentController::class, 'trash'])
            ->post("#^{$a}/(entradas|paginas)/restaurar$#", [Admin\ContentController::class, 'restore'])
            ->post("#^{$a}/categorias/crear$#", [Admin\CategoriesController::class, 'create'])
            ->get("#^{$a}/medios$#", [Admin\MediaController::class, 'index'])
            ->post("#^{$a}/medios/subir$#", [Admin\MediaController::class, 'upload'])
            ->post("#^{$a}/medios/alt$#", [Admin\MediaController::class, 'updateAlt'])
            ->post("#^{$a}/medios/borrar$#", [Admin\MediaController::class, 'delete'])
            ->get("#^{$a}/comentarios$#", [Admin\CommentsController::class, 'index'])
            ->post("#^{$a}/comentarios/moderar$#", [Admin\CommentsController::class, 'moderate'])
            ->post("#^{$a}/comentarios/vaciar$#", [Admin\CommentsController::class, 'empty'])
            ->get("#^{$a}/mensajes$#", [Admin\MessagesController::class, 'index'])
            ->post("#^{$a}/mensajes/accion$#", [Admin\MessagesController::class, 'act'])
            ->post("#^{$a}/mensajes/vaciar-spam$#", [Admin\MessagesController::class, 'emptySpam'])
            ->get("#^{$a}/analitica$#", [Admin\AnalyticsController::class, 'index'])
            ->get("#^{$a}/configuracion$#", [Admin\SettingsController::class, 'show'])
            ->get("#^{$a}/configuracion/([a-z]{1,20})$#", [Admin\SettingsController::class, 'show'])
            ->post("#^{$a}/configuracion/([a-z]{1,20})/guardar$#", [Admin\SettingsController::class, 'save'])
            ->post("#^{$a}/configuracion/correo/probar$#", [Admin\SettingsController::class, 'testMail']);

        // ---- Público.
        $this->router
            ->get('#^/$#', [Front\HomeController::class, 'index'])
            ->get('#^/categoria/([a-z0-9-]{1,80})$#', [Front\CategoryController::class, 'show'])
            ->get('#^/archivo/(\d{4})(?:/(\d{2}))?$#', [Front\ArchiveController::class, 'show'])
            ->get('#^/buscar$#', [Front\SearchController::class, 'index'])
            ->get('#^/salud$#', [Front\HealthController::class, 'index'])
            // Rutas que WordPress también exponía: los lectores de feeds las
            // tienen guardadas desde hace años.
            ->get('#^/feed(?:\.xml)?$#', [Front\FeedController::class, 'index'])
            ->get('#^/sitemap\.xml$#', [Front\SitemapController::class, 'index'])
            ->get('#^/robots\.txt$#', [Front\SitemapController::class, 'robots'])
            // Comentarios. El desafío se pide por GET y el envío va por POST;
            // las dos contestan JSON. Ver CommentController para por qué el
            // formulario no usa un token de sesión.
            ->get('#^/comentarios/desafio$#', [Front\CommentController::class, 'challenge'])
            ->post('#^/comentarios$#', [Front\CommentController::class, 'store'])
            // Contacto: el formulario se dibuja dentro de la página que diga
            // el ajuste, pero el envío tiene ruta propia.
            ->get('#^/contacto/desafio$#', [Front\ContactController::class, 'challenge'])
            ->post('#^/contacto$#', [Front\ContactController::class, 'send'])
            // Las páginas llevan prefijo propio para no competir con los slugs
            // de entradas, que son los que hay que preservar tal cual.
            ->get('#^/p/([a-z0-9-]{1,120})$#', [Front\PageController::class, 'show'])
            // El patrón más amplio va último, siempre.
            ->get('#^/([a-z0-9-]{1,120})$#', [Front\PostController::class, 'show']);
    }

    /** Request en curso, para decidir cómo presentar un error. */
    private string $currentPath = '/';
    private ?Request $currentRequest = null;

    public function handle(Request $request): Response
    {
        $this->currentPath = $request->path;
        $this->currentRequest = $request;

        // Barato y primero: corta las ráfagas antes de tocar contenido.
        $this->limiter->hit('request', $request->ipHash, max: 240, perSeconds: 60);

        // El caché va antes del router: un acierto no consulta contenido, no
        // renderiza plantillas y no abre una sola sesión. La visita sí se
        // sigue contando, con el id de entrada que quedó guardado junto al
        // HTML.
        if ($hit = $this->pages->fetch($request)) {
            $this->analytics->record($request, $hit['post']);

            return $hit['response'];
        }

        return $this->pages->store(
            $request,
            $this->router->dispatch($request),
            $this->analytics->lastPostId
        );
    }

    /** Página de error sin una sola pista de lo que pasó adentro. */
    public function errorPage(int $status, string $message, ?string $incidentId = null): Response
    {
        // Único lugar donde se renderiza una plantilla sin pasar por un
        // controlador, así que es el único que necesita los valores por
        // defecto del layout.
        $this->shareViewDefaults();

        $titles = [
            404 => 'No encontramos esta página',
            403 => 'No tenés permiso',
            405 => 'Método no permitido',
            419 => 'La sesión del formulario venció',
            429 => 'Demasiados intentos',
            500 => 'Algo se rompió de nuestro lado',
        ];

        $body = $this->view->page('error', [
            'status' => $status,
            'title' => $titles[$status] ?? 'Error',
            'message' => $message,
            'incidentId' => $incidentId,
        ]);

        return Response::html($body, $status);
    }

    /**
     * Quien pidió JSON tiene que recibir JSON, también cuando falla.
     *
     * Sin esto, un 429 del límite de tasa le llega al formulario de
     * comentarios como una página HTML entera: el fetch intenta parsearla,
     * explota, y la persona ve «se cortó la conexión» en lugar de «esperá un
     * rato». El error quedaba escondido en el peor momento posible.
     */
    private function wantsJson(): bool
    {
        return $this->currentRequest !== null
            && str_contains($this->currentRequest->header('accept'), 'application/json');
    }

    public function handleThrowable(Throwable $e): Response
    {
        if ($e instanceof HttpException && $this->wantsJson()) {
            return Response::json(['error' => $e->getMessage()], $e->status, $e->headers);
        }

        if ($e instanceof HttpException) {
            // Sesión vencida o ausente dentro del panel: al login, no a una
            // página de error que no lleva a ningún lado.
            if ($e->status === 401 && str_starts_with($this->currentPath, $this->adminPath)) {
                return Response::redirect($this->adminPath . '/entrar');
            }

            $response = $this->errorPage($e->status, $e->getMessage());

            return $e->headers === []
                ? $response
                : Response::html($response->body, $e->status, $e->headers);
        }

        $incidentId = bin2hex(random_bytes(6));
        $this->logger->exception($incidentId, $e);

        if ($this->wantsJson()) {
            return Response::json([
                'error' => 'Algo se rompió de nuestro lado. Ya quedó registrado.',
                'incidente' => $incidentId,
            ], 500);
        }

        if ($this->debug) {
            return Response::text(
                "[{$incidentId}] " . $e::class . ': ' . $e->getMessage()
                . "\n" . $e->getFile() . ':' . $e->getLine()
                . "\n\n" . $e->getTraceAsString(),
                500
            );
        }

        return $this->errorPage(500, 'Ya quedó registrado y lo vamos a mirar.', $incidentId);
    }

    public function url(string $path = '/'): string
    {
        return rtrim($this->env->get('APP_URL', '') ?? '', '/') . $path;
    }
}
