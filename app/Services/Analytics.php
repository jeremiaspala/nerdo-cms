<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Request;
use App\Models\Setting;

/**
 * Medición propia, sin cookies ni JavaScript.
 *
 * Reemplaza a Google Analytics. La diferencia que importa no es técnica
 * sino de a quién le llegan los datos: acá no sale nada del servidor, y el
 * visitante no carga ni un byte de un tercero.
 *
 * El identificador de visitante lleva la fecha adentro del hash, así que
 * rota solo cada medianoche. Sirve para contar únicos de un día y no sirve
 * para seguir a nadie entre días. Sin identificador persistente no hay
 * seguimiento, y sin seguimiento no hace falta banner de consentimiento.
 */
final class Analytics
{
    /**
     * De qué entrada fue la última visita registrada.
     *
     * Lo lee el caché de página para guardarlo junto al HTML: cuando después
     * sirve esa copia sin pasar por el controlador, sigue sabiendo a qué
     * entrada atribuir la visita. Sin esto, encender el caché haría caer la
     * analítica a casi cero y parecería un problema de medición.
     */
    public ?int $lastPostId = null;

    public function __construct(
        private readonly Db $db,
        private readonly Setting $settings,
        private readonly string $pepper,
    ) {
    }

    /**
     * Registra una visita. No debe hacer fallar el request pase lo que pase:
     * si la medición se rompe, el blog sigue sirviendo la página.
     */
    public function record(Request $request, ?int $postId = null): void
    {
        // Antes de cualquier salida temprana: el caché necesita el id aunque
        // la medición esté apagada o el visitante sea un bot.
        $this->lastPostId = $postId;

        if (!$this->settings->bool('analytics_enabled', true)) {
            return;
        }

        $ua = $request->userAgent();
        $device = self::device($ua);

        // Los bots se identifican pero no se cuentan: ensucian todo.
        if ($device === 'bot') {
            return;
        }

        try {
            $this->db->insert('hits', [
                'path' => mb_substr($request->path, 0, 255),
                'post_id' => $postId,
                'referer_host' => self::refererHost($request->header('referer'), $request->host),
                'device' => $device,
                'browser' => self::browser($ua),
                'os' => self::os($ua),
                'visitor_hash' => $this->visitorHash($request),
            ]);
        } catch (\Throwable) {
            // Silencio a propósito: medir no puede romper la lectura.
        }
    }

    /**
     * Pasa el detalle del día a los agregados y borra lo que ya venció.
     * Va por cron, una vez por noche.
     */
    public function aggregate(?string $day = null): array
    {
        $day ??= date('Y-m-d', strtotime('-1 day'));

        $this->db->run(
            'INSERT INTO stats_daily (day, path, post_id, views, visitors)
             SELECT DATE(created_at), path, MAX(post_id), COUNT(*), COUNT(DISTINCT visitor_hash)
               FROM hits
              WHERE DATE(created_at) = :day
              GROUP BY DATE(created_at), path
             ON DUPLICATE KEY UPDATE
                views = VALUES(views), visitors = VALUES(visitors), post_id = VALUES(post_id)',
            ['day' => $day]
        );

        $this->db->run(
            "INSERT INTO stats_referers (day, host, views)
             SELECT DATE(created_at), referer_host, COUNT(*)
               FROM hits
              WHERE DATE(created_at) = :day AND referer_host <> ''
              GROUP BY DATE(created_at), referer_host
             ON DUPLICATE KEY UPDATE views = VALUES(views)",
            ['day' => $day]
        );

        $days = max(1, (int) $this->settings->get('analytics_retention_days', '30'));

        $purged = $this->db
            ->run('DELETE FROM hits WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)', ['d' => $days])
            ->rowCount();

        return ['dia' => $day, 'detalle_borrado' => $purged, 'retencion_dias' => $days];
    }

    // ---------------- consultas para el panel ----------------

    public function summary(int $days = 30): array
    {
        return $this->db->first(
            'SELECT COUNT(*) AS vistas,
                    COUNT(DISTINCT visitor_hash) AS visitantes,
                    COUNT(DISTINCT path) AS paginas
               FROM hits
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)',
            ['d' => $days]
        ) ?? ['vistas' => 0, 'visitantes' => 0, 'paginas' => 0];
    }

    /** Serie diaria para el gráfico, sin huecos. */
    public function daily(int $days = 30): array
    {
        $rows = $this->db->select(
            'SELECT DATE(created_at) AS dia, COUNT(*) AS vistas,
                    COUNT(DISTINCT visitor_hash) AS visitantes
               FROM hits
              WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
              GROUP BY DATE(created_at)
              ORDER BY dia',
            ['d' => $days]
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['dia']] = $row;
        }

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} day"));
            $out[] = [
                'dia' => $day,
                'vistas' => (int) ($byDay[$day]['vistas'] ?? 0),
                'visitantes' => (int) ($byDay[$day]['visitantes'] ?? 0),
            ];
        }

        return $out;
    }

    public function topPosts(int $days = 30, int $limit = 12): array
    {
        return $this->db->select(
            'SELECT h.path, p.title, p.slug,
                    COUNT(*) AS vistas, COUNT(DISTINCT h.visitor_hash) AS visitantes
               FROM hits h
               LEFT JOIN posts p ON p.id = h.post_id
              WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
              GROUP BY h.path, p.title, p.slug
              ORDER BY vistas DESC
              LIMIT :l',
            ['d' => $days, 'l' => $limit]
        );
    }

    public function topReferers(int $days = 30, int $limit = 10): array
    {
        return $this->db->select(
            "SELECT referer_host AS host, COUNT(*) AS vistas
               FROM hits
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
                AND referer_host <> ''
              GROUP BY referer_host
              ORDER BY vistas DESC
              LIMIT :l",
            ['d' => $days, 'l' => $limit]
        );
    }

    public function breakdown(string $column, int $days = 30): array
    {
        $column = in_array($column, ['device', 'browser', 'os'], true) ? $column : 'device';

        return $this->db->select(
            "SELECT {$column} AS etiqueta, COUNT(*) AS vistas
               FROM hits
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY) AND {$column} <> ''
              GROUP BY {$column}
              ORDER BY vistas DESC
              LIMIT 8",
            ['d' => $days]
        );
    }

    // ---------------- detección ----------------

    private function visitorHash(Request $request): string
    {
        // La fecha dentro del hash es lo que hace que esto no sea seguimiento.
        return substr(hash_hmac(
            'sha256',
            $request->ipHash . '|' . $request->userAgent() . '|' . date('Y-m-d'),
            $this->pepper
        ), 0, 32);
    }

    public static function device(string $ua): string
    {
        if ($ua === '') {
            return 'bot';
        }

        if (preg_match('/bot|crawl|spider|slurp|curl|wget|python-requests|headless|lighthouse|monitor|uptime|preview|fetch/i', $ua)) {
            return 'bot';
        }

        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Mobile|Android|iPhone|iPod|Windows Phone/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    public static function browser(string $ua): string
    {
        // El orden importa: casi todos mienten diciendo ser los otros.
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Vivaldi') => 'Vivaldi',
            str_contains($ua, 'Brave') => 'Brave',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Otro',
        };
    }

    public static function os(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Android') => 'Android',
            preg_match('/iPhone|iPad|iPod/', $ua) === 1 => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            preg_match('/Ubuntu|Debian|Fedora|Linux/', $ua) === 1 => 'Linux',
            default => 'Otro',
        };
    }

    /** Dominio del referente, y solo el dominio: la URL completa no aporta. */
    private static function refererHost(string $referer, string $ownHost): string
    {
        if ($referer === '') {
            return '';
        }

        $host = parse_url($referer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower(preg_replace('/^www\./', '', $host) ?? $host);

        // El tráfico interno no es un referente.
        return $host === strtolower(preg_replace('/^www\./', '', explode(':', $ownHost)[0]) ?? '')
            ? ''
            : mb_substr($host, 0, 120);
    }
}
