<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Models\Post;

final class DashboardController extends AdminController
{
    public function index(Request $request): Response
    {
        $this->boot($request);

        return $this->render('dashboard', [
            'current' => 'dashboard',
            'recent' => $this->posts->adminList(Post::TYPE_POST, null, 8, 0),
            'audit' => $this->app->db->select(
                'SELECT a.action, a.target, a.created_at, u.name
                   FROM audit_log a
                   LEFT JOIN users u ON u.id = a.user_id
                  ORDER BY a.id DESC LIMIT 10'
            ),
            'stats' => [
                'categorias' => (int) $this->app->db->value('SELECT COUNT(*) FROM categories'),
                'redirecciones' => (int) $this->app->db->value('SELECT COUNT(*) FROM redirects'),
                'medios' => (int) $this->app->db->value('SELECT COUNT(*) FROM media'),
                'comentarios' => (int) $this->app->db->value(
                    "SELECT COUNT(*) FROM comments WHERE status = 'pending'"
                ),
                'mensajes' => (int) $this->app->db->value(
                    "SELECT COUNT(*) FROM messages WHERE status = 'new'"
                ),
            ],
        ]);
    }
}
