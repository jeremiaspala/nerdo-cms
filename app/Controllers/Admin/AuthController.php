<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Exceptions\HttpException;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\Mailer;
use App\Services\Spam;
use App\Services\Totp;

/**
 * Entrada y salida del panel.
 *
 * No extiende AdminController a propósito: si lo hiciera, pediría sesión
 * iniciada para poder iniciar sesión.
 */
final class AuthController
{
    private readonly Auth $auth;

    public function __construct(private readonly Kernel $app)
    {
        $this->auth = new Auth($app);
    }

    /**
     * Alcance del desafío antispam. Es un número que no puede ser el id de
     * ninguna entrada, así que un desafío emitido para comentar no sirve
     * para probar contraseñas ni al revés.
     */
    private const SCOPE_LOGIN = -1;
    private const SCOPE_RESET = -2;

    public function form(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect($this->app->adminPath);
        }

        return $this->loginPage(flash: $this->app->session->isStarted()
            ? (string) (($this->app->session->takeFlash()['message'] ?? ''))
            : '');
    }

    public function login(Request $request): Response
    {
        // El token igual se exige: el formulario de login es un objetivo
        // clásico de CSRF para forzar el inicio de sesión con otra cuenta.
        $this->app->csrf->check($request);

        // Y antes de tocar la base, la prueba de trabajo. Contra una persona
        // es medio segundo; contra quien prueba listas de contraseñas es el
        // costo de CPU lo que lo frena, sin pedirle a nadie que identifique
        // semáforos ni meter un script de terceros en la puerta del panel.
        if (!$this->powOk($request, self::SCOPE_LOGIN)) {
            return $this->loginPage(
                'La verificación del navegador no cerró. Recargá la página.',
                $request->post('email'),
                422
            );
        }

        try {
            $user = $this->auth->attempt($request, $request->post('email'), $request->postRaw('password'));
        } catch (HttpException $e) {
            return $this->loginPage($e->getMessage(), $request->post('email'), $e->status);
        }

        // Segundo factor, si la cuenta lo tiene puesto. Hasta que el código
        // no se verifique, la sesión no tiene el id del usuario: la clave
        // sola no abre el panel.
        if ((int) ($user['totp_confirmed'] ?? 0) === 1) {
            $this->app->session->set('totp_pending', (int) $user['id']);
            $this->app->session->set('totp_pending_at', time());

            return $this->page('totp', ['error' => '']);
        }

        $this->auth->login($request, $user);

        return Response::redirect($this->app->adminPath);
    }

    /**
     * Segundo paso: el código de la aplicación de autenticación.
     *
     * El «pendiente» vive cinco minutos. Pasado eso hay que volver a poner
     * la contraseña: una pestaña abierta en el segundo paso no puede quedar
     * esperando para siempre.
     */
    public function totp(Request $request): Response
    {
        $this->app->csrf->check($request);

        $userId = (int) ($this->app->session->get('totp_pending') ?? 0);
        $since = (int) ($this->app->session->get('totp_pending_at') ?? 0);

        if ($userId === 0 || time() - $since > 300) {
            $this->app->session->forget('totp_pending');

            return $this->loginPage('Se venció el paso del código. Entrá de nuevo.', '', 401);
        }

        // Seis dígitos son un millón de combinaciones y el código dura
        // treinta segundos: sin límite de tasa, probarlos todos es viable.
        $this->app->limiter->hit('totp_ip', $request->ipHash, max: 10, perSeconds: 900);
        $this->app->limiter->hit('totp_user', (string) $userId, max: 10, perSeconds: 900);

        $users = new User($this->app->db);
        $user = $users->findById($userId);
        $totp = new Totp($this->app->env->require('APP_KEY'));

        if ($user === null
            || !$totp->verify($totp->decrypt($users->totpSecret($userId)), $request->post('codigo'))
        ) {
            $this->auth->audit($userId, 'totp.fail', (string) ($user['email'] ?? ''), $request);

            return $this->page('totp', ['error' => 'Ese código no es el correcto.'], 401);
        }

        $this->app->session->forget('totp_pending');
        $this->app->session->forget('totp_pending_at');

        $this->auth->login($request, $user);

        return Response::redirect($this->app->adminPath);
    }

    // ------------------------------------------------------------------
    // Recuperación de contraseña
    // ------------------------------------------------------------------

    public function forgotForm(Request $request): Response
    {
        return $this->page('forgot', ['error' => '', 'sent' => '']);
    }

    /**
     * Pide el enlace.
     *
     * La respuesta es siempre la misma, exista o no la cuenta: decir «ese
     * correo no está registrado» es confirmar qué cuentas hay, que es lo
     * mismo que se evita en el login.
     */
    public function forgotSend(Request $request): Response
    {
        $this->app->csrf->check($request);

        $generic = 'Si ese correo tiene una cuenta, te mandamos el enlace.';

        if (!$this->powOk($request, self::SCOPE_RESET)) {
            return $this->page('forgot', [
                'error' => 'La verificación del navegador no cerró. Recargá la página.',
                'sent' => '',
            ], 422);
        }

        // Dos límites, igual que en el login: por IP y por cuenta.
        $email = mb_strtolower($request->post('email'));
        $this->app->limiter->hit('reset_ip', $request->ipHash, max: 5, perSeconds: 3600);
        $this->app->limiter->hit(
            'reset_user',
            substr(hash('sha256', $email), 0, 32),
            max: 5,
            perSeconds: 3600
        );

        $user = filter_var($email, FILTER_VALIDATE_EMAIL)
            ? (new User($this->app->db))->findByEmail($email)
            : null;

        if ($user !== null) {
            $resets = new PasswordReset($this->app->db);

            // Tres pedidos por hora y cuenta: suficiente para quien no
            // recibe el correo, poco para llenarle la casilla a alguien.
            if ($resets->recentForUser((int) $user['id']) < 3) {
                $token = $resets->create((int) $user['id'], $request->ipHash);
                $this->sendResetMail((array) $user, $token);
            }

            $this->auth->audit((int) $user['id'], 'password.reset_requested', $email, $request);
        } else {
            $this->auth->audit(null, 'password.reset_unknown', $email, $request);
        }

        return $this->page('forgot', ['error' => '', 'sent' => $generic]);
    }

    public function resetForm(Request $request, string $token): Response
    {
        $row = (new PasswordReset($this->app->db))->find($token);

        if ($row === null) {
            return $this->loginPage(
                'Ese enlace ya no sirve: puede haber vencido o haberse usado. Pedí uno nuevo.',
                '',
                410
            );
        }

        return $this->page('reset', [
            'error' => '',
            'token' => $token,
            'nombre' => (string) $row['name'],
        ]);
    }

    public function reset(Request $request, string $token): Response
    {
        $this->app->csrf->check($request);

        $resets = new PasswordReset($this->app->db);
        $row = $resets->find($token);

        if ($row === null) {
            return $this->loginPage('Ese enlace ya no sirve. Pedí uno nuevo.', '', 410);
        }

        $password = $request->postRaw('password');
        $repeat = $request->postRaw('password2');

        if ($password !== $repeat) {
            return $this->page('reset', [
                'error' => 'Las dos contraseñas no coinciden.',
                'token' => $token,
                'nombre' => (string) $row['name'],
            ], 422);
        }

        if (mb_strlen($password) < 12) {
            return $this->page('reset', [
                'error' => 'Mínimo 12 caracteres.',
                'token' => $token,
                'nombre' => (string) $row['name'],
            ], 422);
        }

        $userId = (int) $row['user_id'];

        (new User($this->app->db))->updatePassword($userId, $password);
        $resets->consume($token, $userId);

        // Todas las sesiones abiertas de esa cuenta se cierran. Si la
        // contraseña se cambió porque alguien más la tenía, dejarle la
        // sesión viva sería cambiar la cerradura con el otro adentro.
        $cerradas = $this->app->db->run(
            'DELETE FROM sessions WHERE user_id = :u',
            ['u' => $userId]
        )->rowCount();

        $this->auth->audit($userId, 'password.reset_done', (string) $row['email'], $request,
            $cerradas . ' sesión(es) cerradas');

        // La sesión de este mismo navegador también se cierra, y hay que
        // hacerlo acá: el DELETE de arriba borra su fila, pero PHP vuelve a
        // escribirla al terminar el script con el contenido que tiene en
        // memoria. Sin esto, la única sesión que sobrevive a un cambio de
        // contraseña es justo la que lo hizo, y la pantalla promete lo
        // contrario.
        $this->app->session->destroy();
        $this->app->session->flash('Listo, ya podés entrar con la contraseña nueva.');

        return Response::redirect($this->app->adminPath . '/entrar');
    }

    // ------------------------------------------------------------------

    /** Verifica el desafío que venía en el formulario. */
    private function powOk(Request $request, int $scope): bool
    {
        $spam = new Spam($this->app);
        $claim = $spam->openToken($request->post('t'), $scope);

        if ($claim === null || !$spam->timingOk($claim['t'], floor: 0)) {
            return false;
        }

        if (!$spam->powOk($claim['n'], $request->post('pow'), $claim['b'])) {
            return false;
        }

        // Un desafío resuelto sirve para un intento. Sin esto, se resuelve
        // una vez y se reusa para probar mil contraseñas.
        return $spam->consumeNonce($claim['n']);
    }

    private function sendResetMail(array $user, string $token): void
    {
        $url = $this->app->url($this->app->adminPath . '/recuperar/' . $token);

        try {
            (new Mailer($this->app->settings, $this->app->env->require('APP_KEY')))->send(
                (string) $user['email'],
                'Recuperar la contraseña de ' . $this->app->settings->get('site_title', 'Nerdadas!'),
                "Alguien pidió un enlace para cambiar la contraseña de tu cuenta.\n\n"
                . $url . "\n\n"
                . 'El enlace vale una hora y se usa una sola vez. '
                . "Si no fuiste vos, no hace falta que hagas nada: sin este enlace nadie puede entrar.\n"
            );
        } catch (\Throwable $e) {
            $this->app->logger->warning('No se pudo mandar el enlace de recuperación', [
                'usuario' => (int) $user['id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Las páginas sueltas del login comparten cabeceras. */
    private function page(string $template, array $data, int $status = 200): Response
    {
        $html = $this->app->adminView->render($template, $data + [
            'pow' => (new Spam($this->app))->issue(self::SCOPE_RESET),
            'csrf' => $this->app->csrf->field(),
            'adminPath' => $this->app->adminPath,
            'siteTitle' => (string) $this->app->settings->get('site_title', 'Nerdadas!'),
        ]);

        return Response::html($html, $status, [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->app->csrf->check($request);
        $this->auth->logout($request);

        return Response::redirect($this->app->adminPath . '/entrar');
    }

    private function loginPage(
        string $error = '',
        string $email = '',
        int $status = 200,
        string $flash = '',
    ): Response {
        $html = $this->app->adminView->render('login', [
            'error' => $error,
            'email' => $email,
            'flash' => $flash,
            'pow' => (new Spam($this->app))->issue(self::SCOPE_LOGIN),
            'csrf' => $this->app->csrf->field(),
            'adminPath' => $this->app->adminPath,
            'siteTitle' => (string) $this->app->settings->get('site_title', 'Nerdadas!'),
        ]);

        // La página de login nunca se indexa ni se cachea.
        return Response::html($html, $status, [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
