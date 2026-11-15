<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Services\Totp;

/**
 * Seguridad de la cuenta: sesiones abiertas y verificación en dos pasos.
 *
 * Las dos cosas viven juntas porque se miran juntas: si uno sospecha que
 * alguien entró, lo primero es ver qué sesiones hay abiertas y lo segundo
 * es poner el segundo factor.
 */
final class SecurityController extends AdminController
{
    public function index(Request $request): Response
    {
        $this->boot($request);

        $totp = new Totp($this->app->env->require('APP_KEY'));
        $user = $this->app->db->first(
            'SELECT totp_secret, totp_confirmed FROM users WHERE id = :id',
            ['id' => (int) $this->user['id']]
        ) ?? [];

        return $this->render('security', [
            'current' => 'security',
            'pageTitle' => 'Seguridad',
            'sessions' => $this->sessions(),
            'sessionActual' => $this->currentSessionId(),
            'totpActivo' => (int) ($user['totp_confirmed'] ?? 0) === 1,
            // El secreto pendiente de confirmar vive en la sesión, no en la
            // base: si alguien cierra el navegador a mitad del alta, no queda
            // una cuenta con un segundo factor que nadie tiene.
            'totpPendiente' => (string) ($this->app->session->get('totp_setup') ?? ''),
            'totpUri' => $this->app->session->get('totp_setup') === null
                ? ''
                : $totp->uri(
                    (string) $this->app->session->get('totp_setup'),
                    (string) $this->user['email'],
                    (string) $this->app->settings->get('site_title', 'Nerdo CMS')
                ),
        ]);
    }

    /** Cierra una sesión por su id. */
    public function revoke(Request $request): Response
    {
        $this->boot($request);

        $id = $request->post('sid');

        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            return $this->back('/seguridad', 'Esa sesión no existe.', 'error');
        }

        // Solo las propias: un editor no puede cerrarle la sesión a otro.
        $n = $this->app->db->run(
            'DELETE FROM sessions WHERE id = :id AND user_id = :u',
            ['id' => $id, 'u' => (int) $this->user['id']]
        )->rowCount();

        if ($n === 0) {
            return $this->back('/seguridad', 'Esa sesión ya no estaba abierta.', 'error');
        }

        $this->auth->audit((int) $this->user['id'], 'session.revoke', substr($id, 0, 12), $request);

        // Si cerró la suya, el próximo request ya no la encuentra.
        return $this->back('/seguridad', 'Sesión cerrada.');
    }

    /** Cierra todas menos la actual. */
    public function revokeOthers(Request $request): Response
    {
        $this->boot($request);

        $n = $this->app->db->run(
            'DELETE FROM sessions WHERE user_id = :u AND id <> :actual',
            ['u' => (int) $this->user['id'], 'actual' => $this->currentSessionId()]
        )->rowCount();

        $this->auth->audit((int) $this->user['id'], 'session.revoke_others', (string) $n, $request);

        return $this->back('/seguridad', $n === 1
            ? 'Se cerró la otra sesión.'
            : "Se cerraron {$n} sesiones.");
    }

    // ------------------------------------------------------------------
    // Verificación en dos pasos
    // ------------------------------------------------------------------

    /** Genera un secreto y lo deja pendiente de confirmación. */
    public function totpStart(Request $request): Response
    {
        $this->boot($request);

        $this->app->session->set('totp_setup', (new Totp($this->app->env->require('APP_KEY')))->secret());

        return $this->back('/seguridad');
    }

    public function totpConfirm(Request $request): Response
    {
        $this->boot($request);

        $secret = (string) ($this->app->session->get('totp_setup') ?? '');

        if ($secret === '') {
            return $this->back('/seguridad', 'Volvé a empezar: no había un alta en curso.', 'error');
        }

        $totp = new Totp($this->app->env->require('APP_KEY'));

        // Un código son seis dígitos y vale treinta segundos: probarlos
        // todos son un millón de intentos, así que el límite de tasa es
        // parte del mecanismo y no un extra.
        $this->app->limiter->hit('totp_setup', $request->ipHash, max: 10, perSeconds: 600);

        if (!$totp->verify($secret, $request->post('codigo'))) {
            return $this->back('/seguridad', 'Ese código no es el correcto. Fijate que el reloj del teléfono esté en hora.', 'error');
        }

        $this->app->db->run(
            'UPDATE users SET totp_secret = :s, totp_confirmed = 1 WHERE id = :id',
            ['s' => $totp->encrypt($secret), 'id' => (int) $this->user['id']]
        );

        $this->app->session->forget('totp_setup');
        $this->auth->audit((int) $this->user['id'], 'totp.enabled', (string) $this->user['email'], $request);

        return $this->back('/seguridad', 'Listo: desde ahora el panel pide el código después de la contraseña.');
    }

    public function totpDisable(Request $request): Response
    {
        $this->boot($request);

        // Para sacarlo hay que poner la contraseña: si alguien se sienta en
        // una sesión abierta, lo primero que haría es apagar el segundo
        // factor.
        $user = $this->app->db->first(
            'SELECT pass_hash FROM users WHERE id = :id',
            ['id' => (int) $this->user['id']]
        );

        $this->app->limiter->hit('totp_off', $request->ipHash, max: 10, perSeconds: 900);

        if ($user === null || !password_verify($request->postRaw('password'), (string) $user['pass_hash'])) {
            return $this->back('/seguridad', 'La contraseña no coincide.', 'error');
        }

        $this->app->db->run(
            'UPDATE users SET totp_secret = NULL, totp_confirmed = 0 WHERE id = :id',
            ['id' => (int) $this->user['id']]
        );

        $this->auth->audit((int) $this->user['id'], 'totp.disabled', (string) $this->user['email'], $request);

        return $this->back('/seguridad', 'Se desactivó la verificación en dos pasos.');
    }

    // ------------------------------------------------------------------

    private function sessions(): array
    {
        return $this->app->db->select(
            'SELECT id, ip_hash, user_agent, created_at, updated_at, expires_at
               FROM sessions
              WHERE user_id = :u AND expires_at > NOW()
              ORDER BY updated_at DESC',
            ['u' => (int) $this->user['id']]
        );
    }

    private function currentSessionId(): string
    {
        return PHP_SAPI === 'cli' ? '' : (string) session_id();
    }
}
