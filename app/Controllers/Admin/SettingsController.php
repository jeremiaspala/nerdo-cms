<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Exceptions\NotFound;
use App\Core\Request;
use App\Core\Response;
use App\Models\Media;
use App\Services\Mailer;

/**
 * Configuración.
 *
 * Es genérico a propósito: no conoce ningún ajuste en particular, los lee de
 * app/Admin/settings_schema.php. Agregar una opción es agregar una línea
 * allá, no tocar el controlador, la vista y el modelo.
 */
final class SettingsController extends AdminController
{
    private function schema(): array
    {
        return require $this->app->root . '/app/Admin/settings_schema.php';
    }

    public function show(Request $request, string $section = ''): Response
    {
        $this->boot($request);

        $schema = $this->schema();
        $section = $section !== '' && isset($schema[$section]) ? $section : array_key_first($schema);

        return $this->render('settings', [
            'current' => 'settings',
            'pageTitle' => 'Configuración · ' . $schema[$section]['titulo'],
            'schema' => $schema,
            'section' => $section,
            'values' => $this->app->settings->all(),
            'library' => (new Media($this->app->db))->recent(24),
        ]);
    }

    public function save(Request $request, string $section): Response
    {
        $this->boot($request);

        $schema = $this->schema();
        if (!isset($schema[$section])) {
            throw new NotFound();
        }

        $saved = 0;

        foreach ($schema[$section]['campos'] as $key => $field) {
            $type = $field['type'];

            // Las casillas no se envían cuando están apagadas: hay que
            // interpretar la ausencia como "no", no como "sin cambios".
            if ($type === 'bool') {
                $this->app->settings->set($key, $request->post($key) === '1' ? '1' : '0');
                $saved++;
                continue;
            }

            if (!array_key_exists($key, $request->body)) {
                continue;
            }

            $value = $request->postRaw($key);

            $value = match ($type) {
                'number' => (string) max(
                    (int) ($field['min'] ?? PHP_INT_MIN),
                    min((int) ($field['max'] ?? PHP_INT_MAX), (int) $value)
                ),
                'select' => isset($field['options'][$value]) ? $value : (string) ($field['default'] ?? ''),
                'email' => filter_var(trim($value), FILTER_VALIDATE_EMAIL) === false ? '' : trim($value),
                'media' => (string) $this->validMediaId((int) $value),
                'textarea' => mb_substr(trim($value), 0, (int) ($field['maxlength'] ?? 2000)),
                default => mb_substr(trim($value), 0, 500),
            };

            // La clave SMTP se guarda cifrada, y el campo vacío significa
            // "dejar la que está", no "borrarla".
            if ($type === 'password') {
                if (trim($value) === '') {
                    continue;
                }
                $value = $this->mailer()->encryptPassword(trim($value));
            }

            $this->app->settings->set($key, $value);
            $saved++;
        }

        $this->auth->audit((int) $this->user['id'], 'settings.update', $section, $request);

        // Casi cualquier ajuste cambia el HTML de todas las páginas: el
        // título, el encabezado, las redes del pie, si hay comentarios.
        $this->app->pages->purgeAll();

        return $this->back('/configuracion/' . $section, "Guardado ({$saved} ajustes).");
    }

    /** Botón de prueba del correo. */
    public function testMail(Request $request): Response
    {
        $this->boot($request);

        $to = $request->post('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->back('/configuracion/correo', 'Poné una dirección válida para la prueba.', 'error');
        }

        $this->app->limiter->hit('mailtest', $request->ipHash, max: 5, perSeconds: 600);

        $result = $this->mailer()->test($to);
        $this->auth->audit((int) $this->user['id'], 'settings.mailtest', $to, $request);

        return $this->back('/configuracion/correo', $result,
            str_starts_with($result, 'Falló') ? 'error' : 'ok');
    }

    private function mailer(): Mailer
    {
        return new Mailer($this->app->settings, $this->app->env->require('APP_KEY'));
    }

    private function validMediaId(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return (new Media($this->app->db))->find($id) === null ? 0 : $id;
    }
}
