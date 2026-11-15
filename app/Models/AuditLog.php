<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/**
 * La bitácora de accesos y de lo que se hizo en el panel.
 *
 * Es append-only desde el código: no hay nada que edite ni borre una fila
 * salvo la limpieza por antigüedad, y eso es a propósito. Una bitácora que
 * se puede corregir no sirve para lo único que sirve una bitácora, que es
 * mirar para atrás cuando algo no cierra.
 *
 * No guarda la IP en claro —va el mismo HMAC que usa el resto del CMS—, así
 * que sirve para ver que dos accesos vinieron del mismo lugar y no para
 * ubicar a nadie.
 */
final class AuditLog
{
    /**
     * Qué es cada acción, en castellano y agrupada.
     *
     * El grupo decide el color en el panel: lo que tiene que ver con entrar
     * y salir se mira distinto que una entrada publicada.
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const ACCIONES = [
        'login.ok' => ['Entró al panel', 'acceso'],
        'login.fail' => ['Intento fallido', 'alerta'],
        'login.locked' => ['Cuenta bloqueada por intentos', 'alerta'],
        'logout' => ['Salió del panel', 'acceso'],
        'login.google' => ['Entró con Google', 'acceso'],
        'login.google_rechazado' => ['Google rechazado: sin cuenta', 'alerta'],
        'session.fingerprint_mismatch' => ['Sesión cerrada por cambio de origen', 'alerta'],
        'session.revoke' => ['Cerró una sesión', 'acceso'],
        'session.revoke_others' => ['Cerró las otras sesiones', 'acceso'],
        'totp.enabled' => ['Activó el segundo factor', 'acceso'],
        'totp.disabled' => ['Desactivó el segundo factor', 'alerta'],
        'totp.disabled_cli' => ['Segundo factor desactivado por consola', 'alerta'],
        'totp.fail' => ['Código de verificación incorrecto', 'alerta'],
        'password.reset_requested' => ['Pidió recuperar la contraseña', 'acceso'],
        'password.reset_unknown' => ['Pidió recuperar un correo sin cuenta', 'alerta'],
        'password.reset_done' => ['Cambió la contraseña', 'alerta'],
        'content.create' => ['Creó contenido', 'contenido'],
        'content.update' => ['Editó contenido', 'contenido'],
        'content.trash' => ['Mandó a la papelera', 'contenido'],
        'content.restore' => ['Restauró de la papelera', 'contenido'],
        'category.create' => ['Creó una categoría', 'contenido'],
        'media.upload' => ['Subió una imagen', 'contenido'],
        'media.delete' => ['Borró una imagen', 'contenido'],
        'comment.approved' => ['Aprobó un comentario', 'moderación'],
        'comment.pending' => ['Devolvió a moderación', 'moderación'],
        'comment.spam' => ['Marcó como spam', 'moderación'],
        'comment.trash' => ['Mandó un comentario a la papelera', 'moderación'],
        'comment.purge' => ['Borró comentarios', 'moderación'],
        'comment.empty' => ['Vació una bandeja de comentarios', 'moderación'],
        'message.delete' => ['Borró mensajes', 'moderación'],
        'message.empty' => ['Vació el spam de mensajes', 'moderación'],
        'settings.update' => ['Cambió la configuración', 'contenido'],
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{0:string,1:string} descripción y grupo */
    public static function describe(string $action): array
    {
        return self::ACCIONES[$action] ?? [$action, 'otro'];
    }

    public function list(array $filtros, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($filtros);

        return $this->db->select(
            'SELECT a.id, a.user_id, a.action, a.target, a.detail, a.ip_hash, a.created_at,
                    u.name AS user_name, u.email AS user_email
               FROM audit_log a
               LEFT JOIN users u ON u.id = a.user_id
              ' . $where . '
              ORDER BY a.id DESC
              LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    public function count(array $filtros): int
    {
        [$where, $params] = $this->where($filtros);

        return (int) $this->db->value('SELECT COUNT(*) FROM audit_log a ' . $where, $params);
    }

    /** Cuántas veces pasó cada cosa, para los filtros. */
    public function counts(): array
    {
        $out = [];

        foreach ($this->db->select(
            'SELECT action, COUNT(*) AS n FROM audit_log GROUP BY action'
        ) as $row) {
            $out[(string) $row['action']] = (int) $row['n'];
        }

        return $out;
    }

    /** Accesos fallidos recientes, que es lo que se mira primero. */
    public function recentFailures(int $hours = 24): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM audit_log
              WHERE action IN ('login.fail', 'login.locked', 'totp.fail',
                               'session.fingerprint_mismatch')
                AND created_at > DATE_SUB(NOW(), INTERVAL :h HOUR)",
            ['h' => $hours]
        );
    }

    /**
     * Borra lo más viejo que el plazo configurado.
     *
     * Una bitácora que no se limpia nunca termina siendo la tabla más grande
     * de la base y, sobre todo, un registro de quién hizo qué durante años
     * que a nadie le sirve y a cualquiera que entre le viene bien.
     */
    public function purge(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        return $this->db
            ->run('DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)',
                ['d' => $days])
            ->rowCount();
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function where(array $filtros): array
    {
        $cond = [];
        $params = [];

        if (($filtros['grupo'] ?? '') !== '') {
            $acciones = array_keys(array_filter(
                self::ACCIONES,
                static fn (array $d): bool => $d[1] === $filtros['grupo']
            ));

            if ($acciones === []) {
                return ['WHERE 1 = 0', []];
            }

            $marcas = [];
            foreach (array_values($acciones) as $i => $accion) {
                $marcas[] = ':a' . $i;
                $params['a' . $i] = $accion;
            }

            $cond[] = 'a.action IN (' . implode(',', $marcas) . ')';
        }

        if (($filtros['usuario'] ?? 0) > 0) {
            $cond[] = 'a.user_id = :usuario';
            $params['usuario'] = (int) $filtros['usuario'];
        }

        if (($filtros['desde'] ?? '') !== '') {
            $cond[] = 'a.created_at >= :desde';
            $params['desde'] = $filtros['desde'] . ' 00:00:00';
        }

        return [$cond === [] ? '' : 'WHERE ' . implode(' AND ', $cond), $params];
    }
}
