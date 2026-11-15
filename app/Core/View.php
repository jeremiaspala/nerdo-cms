<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Motor de plantillas mínimo.
 *
 * Son archivos PHP, pero con dos candados:
 *  - El nombre de la plantilla se valida contra [a-z0-9_-] y se resuelve con
 *    realpath dentro del directorio del tema. No hay forma de salirse.
 *  - Las plantillas no reciben la base ni servicios: solo el arreglo de datos
 *    que les pasa el controlador. Un tema no puede consultar ni ejecutar nada.
 *
 * Toda salida va con e(). Cuando entre Twig, esta clase se reemplaza y las
 * plantillas se traducen; el contrato con los controladores no cambia.
 */
final class View
{
    private array $shared = [];

    public function __construct(private readonly string $themeDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $template, array $data = []): string
    {
        $file = $this->resolve($template);

        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            require $__file;

            return (string) ob_get_clean();
        };

        // Los datos del controlador ganan sobre los compartidos, no al revés.
        return $render($file, $data + $this->shared);
    }

    /** Render dentro de layout.php, que recibe $content ya armado. */
    public function page(string $template, array $data = []): string
    {
        $content = $this->render($template, $data);

        return $this->render('layout', ['content' => $content] + $data);
    }

    public function exists(string $template): bool
    {
        try {
            $this->resolve($template);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function resolve(string $template): string
    {
        if (!preg_match('/^[a-z0-9_-]{1,60}$/', $template)) {
            throw new RuntimeException('Nombre de plantilla inválido.');
        }

        $base = realpath($this->themeDir);
        $file = realpath($this->themeDir . '/' . $template . '.php');

        if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('No existe la plantilla: ' . $template);
        }

        return $file;
    }
}
