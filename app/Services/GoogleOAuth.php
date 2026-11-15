<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Kernel;
use RuntimeException;

/**
 * Entrar con Google (OAuth 2.0 + OpenID Connect).
 *
 * Se usa en dos lugares distintos y por motivos distintos:
 *
 *  - En el **panel**, como alternativa a la contraseña. La cuenta tiene que
 *    existir: Google dice quién sos, no si podés entrar. Sin esta regla,
 *    cualquiera con una cuenta de Gmail se daría de alta solo.
 *  - En los **comentarios**, para que alguien pueda firmar con su nombre
 *    verificado en vez de escribir cualquier cosa.
 *
 * ── Por qué no se verifica la firma del id_token ──
 *
 * El `id_token` no llega por el navegador: lo pide este servidor al endpoint
 * de Google, por TLS, autenticándose con el client secret. Viene de la
 * fuente, por un canal autenticado, así que lo que hay que comprobar son las
 * afirmaciones —para quién es, quién lo emitió, cuándo vence— y no la firma.
 * Es lo que dice la propia especificación de OpenID Connect para el flujo de
 * código. Verificar RS256 contra el JWKS de Google implicaría además bajarlo
 * y cachearlo, que es más código y más superficie para la misma garantía.
 *
 * ── Por qué el `state` va firmado y no en la sesión ──
 *
 * El flujo de los comentarios tiene que funcionar para alguien que **no
 * tiene cookie**: el visitante anónimo no recibe ninguna, que es lo que
 * permite el caché de página. Así que el `state` lleva su propia firma con
 * la APP_KEY y adentro a dónde volver y cuándo vence, igual que el desafío
 * antispam.
 */
final class GoogleOAuth
{
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';

    /** Cuánto vale un `state`. Lo que tarda alguien en elegir la cuenta. */
    private const STATE_TTL = 900;

    public function __construct(private readonly Kernel $app)
    {
    }

    public function configurado(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function clientId(): string
    {
        return trim((string) $this->app->settings->get('google_client_id', ''));
    }

    /**
     * El secreto se guarda cifrado con la APP_KEY, igual que la clave SMTP:
     * un volcado de la base no alcanza para hacerse pasar por este sitio
     * ante Google.
     */
    public function clientSecret(): string
    {
        $stored = (string) $this->app->settings->get('google_client_secret', '');

        if ($stored === '') {
            return '';
        }

        $raw = base64_decode($stored, true);

        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            return '';
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->boxKey()
        );

        return $plain === false ? '' : $plain;
    }

    public function encryptSecret(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->boxKey()));
    }

    /** La URL a la que se manda a la persona. */
    public function authUrl(string $redirectUri, array $state): string
    {
        return self::AUTH . '?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $this->signState($state),
            // Que elija la cuenta cada vez: en una computadora compartida,
            // entrar «solo» con la sesión de Google de otro es un accidente
            // esperando.
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Cambia el código por el id_token y devuelve quién es.
     *
     * @return array{email:string,nombre:string,sub:string}
     */
    public function identity(string $code, string $redirectUri): array
    {
        $respuesta = $this->post(self::TOKEN, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $idToken = (string) ($respuesta['id_token'] ?? '');
        $partes = explode('.', $idToken);

        if (count($partes) !== 3) {
            throw new RuntimeException('Google no devolvió un id_token.');
        }

        $payload = json_decode((string) self::b64($partes[1]), true);

        if (!is_array($payload)) {
            throw new RuntimeException('El id_token vino ilegible.');
        }

        $iss = (string) ($payload['iss'] ?? '');
        $aud = (string) ($payload['aud'] ?? '');
        $exp = (int) ($payload['exp'] ?? 0);
        $email = mb_strtolower((string) ($payload['email'] ?? ''));

        if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new RuntimeException('El id_token no lo emitió Google.');
        }

        if (!hash_equals($this->clientId(), $aud)) {
            throw new RuntimeException('Ese id_token es para otra aplicación.');
        }

        if ($exp < time()) {
            throw new RuntimeException('El id_token ya venció.');
        }

        // Un correo sin verificar es un correo que Google no comprobó: para
        // este CMS vale lo mismo que uno escrito a mano.
        if (($payload['email_verified'] ?? false) !== true || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Esa cuenta de Google no tiene el correo verificado.');
        }

        return [
            'email' => $email,
            'nombre' => trim((string) ($payload['name'] ?? '')) ?: strstr($email, '@', true) ?: 'Alguien',
            'sub' => (string) ($payload['sub'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // El `state`, firmado
    // ------------------------------------------------------------------

    public function signState(array $data): string
    {
        $data['t'] = time();
        $data['n'] = bin2hex(random_bytes(8));

        $payload = self::b64url((string) json_encode($data, JSON_THROW_ON_ERROR));

        return $payload . '.' . self::b64url($this->sign($payload));
    }

    /** @return array<string,mixed>|null */
    public function openState(string $state): ?array
    {
        $partes = explode('.', $state, 2);

        if (count($partes) !== 2) {
            return null;
        }

        $firma = self::b64($partes[1]);

        if ($firma === null || !hash_equals($this->sign($partes[0]), $firma)) {
            return null;
        }

        $json = self::b64($partes[0]);
        $data = $json === null ? null : json_decode($json, true);

        if (!is_array($data) || time() - (int) ($data['t'] ?? 0) > self::STATE_TTL) {
            return null;
        }

        return $data;
    }

    // ------------------------------------------------------------------

    private function post(string $url, array $campos): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($campos),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Sin esto no hay nada que discutir: si la verificación del
            // certificado falla, no hablamos.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'NerdoCMS',
        ]);

        $cuerpo = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($cuerpo === false || $estado !== 200) {
            throw new RuntimeException('Google rechazó el intercambio del código.'
                . ($error !== '' ? ' (' . $error . ')' : ' (HTTP ' . $estado . ')'));
        }

        $data = json_decode((string) $cuerpo, true);

        if (!is_array($data)) {
            throw new RuntimeException('Google devolvió algo que no es JSON.');
        }

        return $data;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', 'google:' . $payload, $this->app->env->require('APP_KEY'), true);
    }

    private function boxKey(): string
    {
        return sodium_crypto_generichash(
            $this->app->env->require('APP_KEY'),
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64(string $encoded): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,4096}$/', $encoded)) {
            return null;
        }

        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
