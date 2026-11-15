<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Códigos de un solo uso por tiempo (TOTP, RFC 6238).
 *
 * Son ochenta líneas y no una dependencia: el algoritmo es HMAC-SHA1 sobre
 * el número de ventana de treinta segundos, y lo único delicado —el truncado
 * dinámico— son cuatro líneas que están en el RFC. Traer un paquete con su
 * propio árbol de dependencias para esto iría en contra de todo el proyecto,
 * y además este archivo se puede leer entero antes de confiarle el segundo
 * factor del panel.
 *
 * El secreto se guarda **cifrado con la APP_KEY**, igual que la clave SMTP:
 * quien consiga un volcado de la base no se lleva además los segundos
 * factores. Si cambia la APP_KEY, los secretos dejan de poder descifrarse y
 * hay que volver a dar de alta el TOTP —está dicho en .env.example—.
 */
final class Totp
{
    /** Ventana de treinta segundos, que es lo que usan todas las apps. */
    private const PERIOD = 30;

    /** Seis dígitos, también por convención. */
    private const DIGITS = 6;

    /**
     * Cuántas ventanas para atrás y para adelante se aceptan.
     *
     * Una. Cubre el reloj desfasado de un teléfono y el tiempo que tarda
     * alguien en copiar el código; más que eso es alargar la vida de un
     * código que ya se usó.
     */
    private const DRIFT = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly string $appKey)
    {
    }

    /** Secreto nuevo, en base32, de 160 bits. */
    public function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    /**
     * Verifica un código contra el secreto.
     *
     * La comparación es con hash_equals: un `===` sobre seis dígitos filtra,
     * por tiempo, cuántos acertó quien está probando.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $window = (int) floor(time() / self::PERIOD);

        for ($i = -self::DRIFT; $i <= self::DRIFT; $i++) {
            if (hash_equals($this->code($secret, $window + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /** El código de una ventana. */
    public function code(string $secret, int $window): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return '';
        }

        // El contador va en ocho bytes, big-endian.
        $counter = pack('J', $window);
        $hash = hash_hmac('sha1', $counter, $key, true);

        // Truncado dinámico: los cuatro bits bajos del último byte dicen de
        // dónde salen los cuatro bytes que forman el número.
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * La URI que leen las aplicaciones de autenticación.
     *
     * No se dibuja el QR del lado del servidor: hacerlo con un servicio de
     * terceros sería mandarle el secreto a otro, y una librería de QR es
     * mucho código para una pantalla que se usa una vez. En el teléfono, el
     * enlace abre la app directamente; en la computadora se copia el secreto
     * a mano, que es la opción que todas las apps ofrecen.
     */
    public function uri(string $secret, string $email, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($email)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** El secreto en grupos de cuatro, para poder tipearlo sin perderse. */
    public static function readable(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    // ------------------------------------------------------------------
    // Guardado: el secreto nunca va en claro a la base.
    // ------------------------------------------------------------------

    public function encrypt(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($secret, $nonce, $this->boxKey());
    }

    public function decrypt(?string $stored): string
    {
        if ($stored === null || strlen($stored) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            return '';
        }

        $nonce = substr($stored, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($stored, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->boxKey());

        return $plain === false ? '' : $plain;
    }

    private function boxKey(): string
    {
        return sodium_crypto_generichash($this->appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::ALPHABET, $char);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
