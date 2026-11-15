<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFound;

/**
 * Tabla de rutas explícita.
 *
 * No hay descubrimiento automático de controladores ni rutas derivadas del
 * nombre de una clase: si una ruta no está escrita acá, no existe. Es más
 * verboso y es exactamente lo que queremos — la superficie es legible de
 * una sola lectura.
 */
final class Router
{
    /** @var array<int,array{0:string,1:string,2:array{0:class-string,1:string}}> */
    private array $routes = [];

    public function __construct(private readonly Kernel $app)
    {
    }

    public function get(string $pattern, array $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, array $handler): self
    {
        $this->routes[] = [$method, $pattern, $handler];

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $allowedForPath = [];

        // HEAD se resuelve con la ruta GET y Response::send() se encarga de
        // no mandar el cuerpo. Devolver 405 a un HEAD rompe monitores,
        // proxies y buena parte de los rastreadores.
        $requestMethod = $request->method === 'HEAD' ? 'GET' : $request->method;

        foreach ($this->routes as [$method, $pattern, $handler]) {
            if (!preg_match($pattern, $request->path, $m)) {
                continue;
            }

            if ($requestMethod !== $method) {
                $allowedForPath[] = $method;
                continue;
            }

            [$class, $action] = $handler;
            $controller = new $class($this->app);

            return $controller->{$action}($request, ...array_slice($m, 1));
        }

        // La ruta existe pero con otro verbo: 405, no 404.
        if ($allowedForPath !== []) {
            throw new HttpException(405, 'Método no permitido.', [
                'Allow' => implode(', ', array_unique($allowedForPath)),
            ]);
        }

        // Antes de rendirse: los permalinks viejos de WordPress.
        if ($to = $this->lookupRedirect($request->path)) {
            return Response::redirect($to['to_path'], (int) $to['code']);
        }

        throw new NotFound();
    }

    /**
     * Una sola consulta por índice único. Se prueba también con barra final,
     * porque los permalinks de WordPress la llevan.
     */
    private function lookupRedirect(string $path): ?array
    {
        $candidates = array_unique([$path, $path . '/', rtrim($path, '/')]);

        $row = $this->app->db->first(
            'SELECT to_path, code FROM redirects
              WHERE from_path IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')
              LIMIT 1',
            array_values($candidates)
        );

        if ($row !== null) {
            $this->app->db->run(
                'UPDATE redirects SET hits = hits + 1, last_hit_at = NOW() WHERE to_path = ? LIMIT 1',
                [$row['to_path']]
            );
        }

        return $row;
    }
}
