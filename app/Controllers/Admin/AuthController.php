<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Exceptions\HttpException;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;

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

    public function form(Request $request): Response
    {
        if ($this->auth->check()) {
            return Response::redirect($this->app->adminPath);
        }

        return $this->loginPage();
    }

    public function login(Request $request): Response
    {
        // El token igual se exige: el formulario de login es un objetivo
        // clásico de CSRF para forzar el inicio de sesión con otra cuenta.
        $this->app->csrf->check($request);

        try {
            $user = $this->auth->attempt($request, $request->post('email'), $request->postRaw('password'));
        } catch (HttpException $e) {
            return $this->loginPage($e->getMessage(), $request->post('email'), $e->status);
        }

        $this->auth->login($request, $user);

        return Response::redirect($this->app->adminPath);
    }

    public function logout(Request $request): Response
    {
        $this->app->csrf->check($request);
        $this->auth->logout($request);

        return Response::redirect($this->app->adminPath . '/entrar');
    }

    private function loginPage(string $error = '', string $email = '', int $status = 200): Response
    {
        $html = $this->app->adminView->render('login', [
            'error' => $error,
            'email' => $email,
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
