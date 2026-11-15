<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Security;

/**
 * Antispam en capas, sin un solo servicio de terceros.
 *
 * Reemplaza a `advanced-google-recaptcha`. La diferencia que importa no es de
 * eficacia sino de superficie: reCAPTCHA mete un script de Google en todas
 * las páginas con comentarios, le filtra la IP y el user-agent de cada lector
 * a un tercero, y obliga a abrir la CSP. Todo lo de acá corre en el servidor
 * propio y en el navegador del visitante, y no habla con nadie.
 *
 * Son ocho barreras, de la más barata a la más cara. Ninguna alcanza sola;
 * juntas dejan el spam automatizado en un costo que no cierra.
 *
 *  1. Desafío firmado  — hace de token CSRF sin necesitar sesión.
 *  2. Límite de tasa   — por hash de IP, antes de tocar contenido.
 *  3. Campo trampa     — un input que un humano no ve y un robot completa.
 *  4. Piso de tiempo   — nadie escribe un comentario en tres segundos.
 *  5. Origen           — Origin/Referer del propio dominio.
 *  6. Prueba de trabajo— CPU del visitante: barata una vez, carísima mil.
 *  7. Validación       — largos, formato, enlaces.
 *  8. Puntaje          — señales que deciden si entra a moderación o a spam.
 *
 * ── Por qué el desafío es firmado y no un token de sesión ──
 *
 * Un token CSRF clásico vive en la sesión, y la sesión implica cookie. Dos
 * decisiones del proyecto lo prohíben: el visitante anónimo no recibe cookie,
 * y la página se sirve desde el caché igual para todos (si el HTML llevara un
 * token de sesión incrustado, el caché serviría el token de otra persona).
 *
 * Así que el desafío se emite aparte, por GET, firmado con la APP_KEY y con
 * todo lo que hace falta adentro: a qué entrada corresponde, cuándo se emitió,
 * con cuánta dificultad y un nonce. El servidor no guarda nada hasta que
 * alguien lo gasta. Para falsificarlo hay que tener la APP_KEY, que es una
 * condición más fuerte que adivinar un token de sesión.
 */
final class Spam
{
    /** Segundos mínimos entre pedir el desafío y enviar el comentario. */
    private const TIME_FLOOR = 8;

    /** Y máximos: pasado eso hay que recargar la página. */
    private const TIME_CEIL = 7200;

    /** A partir de este puntaje el comentario entra como spam y no se avisa. */
    public const SPAM_THRESHOLD = 50;

    public function __construct(private readonly Kernel $app)
    {
    }

    // ------------------------------------------------------------------
    // Emisión
    // ------------------------------------------------------------------

    /**
     * Desafío nuevo.
     *
     * El alcance ata el desafío a dónde se va a usar: el id de la entrada
     * para un comentario, 0 para el formulario de contacto. Uno emitido para
     * comentar en una entrada no sirve para comentar en otra ni para mandar
     * un mensaje, porque el alcance viaja adentro de la firma.
     *
     * @return array{token:string,nonce:string,bits:int}
     */
    public function issue(int $scope): array
    {
        $nonce = bin2hex(random_bytes(16));
        $bits = $this->bits();

        $payload = [
            'p' => $scope,
            't' => time(),
            'b' => $bits,
            'n' => $nonce,
        ];

        $encoded = $this->b64(json_encode($payload, JSON_THROW_ON_ERROR));

        return [
            'token' => $encoded . '.' . $this->b64($this->sign($encoded)),
            'nonce' => $nonce,
            'bits' => $bits,
        ];
    }

    /** Dificultad configurada, con un rango sano alrededor. */
    public function bits(): int
    {
        return max(12, min(24, (int) $this->app->settings->get('pow_bits', '18')));
    }

    // ------------------------------------------------------------------
    // Verificación
    // ------------------------------------------------------------------

    /**
     * Abre el token si la firma es nuestra y no venció.
     *
     * @return array{p:int,t:int,b:int,n:string}|null
     */
    public function openToken(string $token, int $scope): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $sig] = $parts;

        $expected = $this->sign($encoded);
        $given = $this->unb64($sig);

        // hash_equals y no ===: la comparación no puede filtrar cuántos bytes
        // acertó quien está probando firmas.
        if ($given === null || !hash_equals($expected, $given)) {
            return null;
        }

        $json = $this->unb64($encoded);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);

        if (!is_array($data)
            || !isset($data['p'], $data['t'], $data['b'], $data['n'])
            || (int) $data['p'] !== $scope
            || !is_string($data['n'])
            || strlen($data['n']) !== 32
            || !ctype_xdigit($data['n'])
        ) {
            return null;
        }

        return [
            'p' => (int) $data['p'],
            't' => (int) $data['t'],
            'b' => max(12, min(24, (int) $data['b'])),
            'n' => $data['n'],
        ];
    }

    /**
     * Piso y techo de tiempo.
     *
     * El piso es la barrera más efectiva de todas en relación a lo que cuesta:
     * los robots completan y envían en el mismo request, y un humano que
     * escribe tres líneas tarda bastante más que ocho segundos.
     */
    /**
     * @param int|null $floor segundos mínimos; null usa el piso de los
     *                        formularios públicos. El login pasa 0: ahí el
     *                        piso no aporta nada —escribir una contraseña
     *                        rápido no es sospechoso— y castigaría a quien
     *                        usa un gestor de contraseñas, que completa y
     *                        envía al instante.
     */
    public function timingOk(int $issuedAt, ?int $floor = null): bool
    {
        $elapsed = time() - $issuedAt;

        return $elapsed >= ($floor ?? self::TIME_FLOOR) && $elapsed <= self::TIME_CEIL;
    }

    public function originOk(Request $request): bool
    {
        return Security::sameOrigin($request);
    }

    /**
     * Prueba de trabajo: sha256(nonce:solución) con N bits en cero adelante.
     *
     * Verificar cuesta un hash. Resolver cuesta 2^N en promedio. Esa asimetría
     * es todo el punto: al visitante le lleva medio segundo una vez, y a quien
     * quiera publicar diez mil comentarios le lleva una tarde de CPU por cada
     * intento, sin paralelizar nada gratis.
     */
    public function powOk(string $nonce, string $solution, int $bits): bool
    {
        // La solución es un contador: dígitos y nada más. Sin esto, el campo
        // es una cadena arbitraria que entra a hash() desde el formulario.
        if ($solution === '' || strlen($solution) > 20 || !ctype_digit($solution)) {
            return false;
        }

        return self::leadingZeroBits(hash('sha256', $nonce . ':' . $solution, true)) >= $bits;
    }

    /**
     * Gasta el nonce. Devuelve false si ya estaba usado.
     *
     * El UNIQUE de la tabla es la cerradura: dos envíos simultáneos con el
     * mismo desafío resuelto no pueden entrar los dos.
     */
    public function consumeNonce(string $nonce): bool
    {
        try {
            $this->app->db->run(
                'INSERT INTO comment_nonces (nonce) VALUES (:n)',
                ['n' => $nonce]
            );
        } catch (\PDOException) {
            return false;
        }

        return true;
    }

    public function purgeNonces(): int
    {
        return $this->app->db
            ->run('DELETE FROM comment_nonces WHERE used_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')
            ->rowCount();
    }

    // ------------------------------------------------------------------
    // Puntaje
    // ------------------------------------------------------------------

    /**
     * Señales que deciden si el comentario entra a moderación o a spam.
     *
     * Devuelve el puntaje y el detalle, que queda en el panel: una regla que
     * no se puede explicar es una regla que nadie va a poder ajustar después.
     *
     * @return array{score:int,reasons:array<int,string>}
     */
    public function score(
        string $name,
        string $emailHash,
        string $url,
        string $text,
        Request $request,
    ): array {
        $score = 0;
        $reasons = [];

        $add = static function (int $points, string $why) use (&$score, &$reasons): void {
            $score += $points;
            $reasons[] = $why;
        };

        $maxLinks = max(0, (int) $this->app->settings->get('comments_max_links', '2'));
        $links = preg_match_all('#\bhttps?://|\bwww\.#i', $text);

        if ($links > $maxLinks) {
            $add(25 * ($links - $maxLinks), "enlaces: {$links} (máximo {$maxLinks})");
        }

        $length = mb_strlen($text);

        if ($length < 15) {
            $add(15, 'cuerpo muy corto');
        }

        // Spam de enlaces clásico: un comentario que es solo una URL.
        if (preg_match('#^\s*https?://#i', $text) === 1) {
            $add(20, 'empieza con una URL');
        }

        // Nombre con enlace adentro: ningún humano firma así.
        if (preg_match('#https?://|www\.|\[url#i', $name) === 1) {
            $add(30, 'el nombre contiene un enlace');
        }

        if ($length > 20 && mb_strtoupper($text, 'UTF-8') === $text) {
            $add(10, 'todo en mayúsculas');
        }

        // BBCode: no lo genera ningún formulario de este sitio.
        if (preg_match('#\[(url|link|img)[\s=\]]#i', $text) === 1) {
            $add(30, 'etiquetas BBCode');
        }

        // Una palabra de 40 caracteres sin espacios es una cadena generada.
        if (preg_match('/\S{45,}/u', $text) === 1) {
            $add(15, 'palabra larguísima sin espacios');
        }

        if ($request->userAgent() === '') {
            $add(15, 'sin user-agent');
        }

        foreach ($this->blocklist() as $word) {
            if (mb_stripos($text . ' ' . $name . ' ' . $url, $word) !== false) {
                $add(50, "palabra vetada: {$word}");
                break;
            }
        }

        // Mismo cuerpo que algo que ya está en la base: reenvío masivo.
        $duplicate = $this->app->db->exists(
            'SELECT 1 FROM comments WHERE body_text_hash = :h LIMIT 1',
            ['h' => hash('sha256', mb_strtolower(trim($text)))]
        );

        if ($duplicate) {
            $add(40, 'cuerpo repetido');
        }

        // Y las señales a favor: alguien que ya tiene un comentario aprobado
        // con el mismo correo es, de lejos, la mejor evidencia que tenemos.
        if ($this->knownCommenter($emailHash)) {
            $add(-30, 'ya tiene un comentario aprobado');
        }

        if ($request->ipHash !== '' && $this->app->db->exists(
            "SELECT 1 FROM comments WHERE ip_hash = :ip AND status = 'approved' LIMIT 1",
            ['ip' => $request->ipHash]
        )) {
            $add(-10, 'misma IP que un comentario aprobado');
        }

        return ['score' => max(0, $score), 'reasons' => $reasons];
    }

    public function knownCommenter(string $emailHash): bool
    {
        return $this->app->db->exists(
            "SELECT 1 FROM comments WHERE email_hash = :h AND status = 'approved' LIMIT 1",
            ['h' => $emailHash]
        );
    }

    /** @return array<int,string> */
    private function blocklist(): array
    {
        $raw = (string) $this->app->settings->get('comments_blocklist', '');

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/[\r\n,]+/', $raw) ?: []
        )));
    }

    // ------------------------------------------------------------------

    public static function leadingZeroBits(string $binary): int
    {
        $bits = 0;

        for ($i = 0, $len = strlen($binary); $i < $len; $i++) {
            $byte = ord($binary[$i]);

            if ($byte === 0) {
                $bits += 8;
                continue;
            }

            // Bits en cero que quedan en el byte donde apareció el primer uno.
            for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                if (($byte & $mask) !== 0) {
                    return $bits;
                }
                $bits++;
            }
        }

        return $bits;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->app->env->require('APP_KEY'), true);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64(string $encoded): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,512}$/', $encoded)) {
            return null;
        }

        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
