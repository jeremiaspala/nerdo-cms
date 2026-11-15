<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;

/**
 * Categorías.
 *
 * Por ahora solo crear, y desde el editor: es lo que hace falta mientras se
 * escribe. Renombrar y borrar no están, y es a propósito —borrar una
 * categoría que ya está en la URL de un listado rompe enlaces, así que
 * cuando se agregue va a necesitar su redirección 301, igual que el slug de
 * una entrada—.
 */
final class CategoriesController extends AdminController
{
    public function create(Request $request): Response
    {
        $this->boot($request);

        $name = mb_substr($request->post('nombre'), 0, 80);

        if (mb_strlen(trim($name)) < 2) {
            return Response::json(['error' => 'El nombre es muy corto.'], 422);
        }

        // Sin etiquetas: el nombre se imprime en el menú, en los chips y en
        // el título de la página de la categoría.
        $name = trim(strip_tags($name));

        if (slugify($name, 80) === 'entrada') {
            return Response::json(['error' => 'Ese nombre no deja armar una URL.'], 422);
        }

        $category = $this->categories->create($name);

        if ($category['nueva']) {
            $this->auth->audit(
                (int) $this->user['id'],
                'category.create',
                $category['slug'],
                $request
            );
        }

        return Response::json($category);
    }
}
