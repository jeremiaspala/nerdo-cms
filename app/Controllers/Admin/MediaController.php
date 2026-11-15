<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Models\Media;
use App\Services\ImageProcessor;
use RuntimeException;

final class MediaController extends AdminController
{
    public function index(Request $request): Response
    {
        $this->boot($request);

        $media = new Media($this->app->db);
        $page = $request->page();

        return $this->render('media', [
            'current' => 'media',
            'pageTitle' => 'Medios',
            'items' => $media->recent(48, ($page - 1) * 48),
            'total' => $media->count(),
            'page' => $page,
            'pages' => max(1, (int) ceil($media->count() / 48)),
        ]);
    }

    /**
     * Subida. Responde JSON porque la llama el editor por fetch, pero
     * exige el mismo token CSRF que cualquier otro POST del panel.
     */
    public function upload(Request $request): Response
    {
        $this->boot($request);

        // Subir es caro: tiene su propio límite, aparte del general.
        $this->app->limiter->hit('upload', $request->ipHash, max: 60, perSeconds: 3600);

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            return Response::json(['error' => 'No llegó ningún archivo.'], 400);
        }

        $media = new Media($this->app->db);
        $processor = new ImageProcessor($this->app->root . '/public/uploads');

        try {
            $info = $processor->store($file);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        // Si ya teníamos esta imagen exacta, se reusa en vez de duplicar.
        $existing = $media->findBySha($info['sha256']);
        if ($existing !== null) {
            return Response::json($this->payload($existing));
        }

        $info['alt'] = mb_substr($request->post('alt'), 0, 300);
        $id = $media->create($info);

        $this->auth->audit((int) $this->user['id'], 'media.upload', (string) $id, $request);

        return Response::json($this->payload(['id' => $id] + $info));
    }

    public function updateAlt(Request $request): Response
    {
        $this->boot($request);

        $media = new Media($this->app->db);
        $media->setAlt($request->int('id'), $request->post('alt'));

        // El alt sale impreso en las páginas que usan la imagen.
        $this->app->pages->purgeAll();

        return $this->back('/medios', 'Texto alternativo actualizado.');
    }

    public function delete(Request $request): Response
    {
        $this->boot($request);

        $media = new Media($this->app->db);
        $id = $request->int('id');
        $item = $media->find($id);

        if ($item === null) {
            return $this->back('/medios', 'Esa imagen ya no existe.', 'error');
        }

        // No se borra algo que una entrada todavía usa: el agujero en la
        // publicación aparecería después y sin aviso.
        $used = $media->usage($id);
        if ($used > 0) {
            return $this->back('/medios',
                "No se puede borrar: la usan {$used} entrada(s) como destacada.", 'error');
        }

        (new ImageProcessor($this->app->root . '/public/uploads'))->delete((string) $item['filename']);
        $media->delete($id);

        $this->auth->audit((int) $this->user['id'], 'media.delete', (string) $id, $request);
        $this->app->pages->purgeAll();

        return $this->back('/medios', 'Imagen borrada.');
    }

    private function payload(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'url' => '/uploads/' . $m['filename'],
            'srcset' => ImageProcessor::srcset((string) $m['filename'], (int) $m['width']),
            'alt' => (string) ($m['alt'] ?? ''),
            'width' => (int) $m['width'],
            'height' => (int) $m['height'],
        ];
    }
}
