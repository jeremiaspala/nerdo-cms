<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use RuntimeException;

/**
 * Envío de correo, con dos transportes a elección.
 *
 *  - mail()  : la función de PHP. No hay nada que configurar, pero depende
 *              del MTA del servidor y suele terminar en spam: el correo sale
 *              sin autenticar, con el remitente que diga el script.
 *  - smtp    : se conecta a un servidor real y se autentica. Es más trabajo
 *              de configurar y es lo que hay que usar si querés que el
 *              correo llegue a destino.
 *
 * Sin dependencias: un cliente SMTP que hable AUTH y STARTTLS entra en 150
 * líneas y es una superficie que se puede leer entera. Meter una librería de
 * correo con su propio árbol de dependencias para esto sería ir en contra de
 * todo el proyecto.
 */
final class Mailer
{
    public function __construct(
        private readonly Setting $settings,
        private readonly string $appKey,
    ) {
    }

    /**
     * @param string $to      destinatario
     * @param string $subject asunto
     * @param string $text    cuerpo en texto plano
     * @param string|null $html cuerpo HTML opcional
     * @param string|null $replyTo a quién contesta el botón «responder»
     *
     * El remitente (From) es siempre el del sitio: poner ahí la dirección de
     * quien escribió el formulario es falsificar el remitente, y es lo que
     * hace que SPF y DMARC manden el correo a spam —o lo rechacen—. Para que
     * «responder» vaya a la persona correcta está Reply-To, que es el
     * mecanismo que existe justamente para esto.
     */
    public function send(
        string $to,
        string $subject,
        string $text,
        ?string $html = null,
        ?string $replyTo = null,
    ): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Dirección de destino inválida.');
        }

        // Inyección de cabeceras: un salto de línea en el asunto permite
        // agregar un Bcc y usar tu servidor para mandar spam.
        if (preg_match('/[\r\n]/', $subject . $to)) {
            throw new RuntimeException('El asunto o el destinatario tienen saltos de línea.');
        }

        $transport = $this->settings->get('mail_transport', 'mail');

        if ($transport === 'none') {
            return false;
        }

        $from = (string) $this->settings->get('mail_from', 'no-reply@localhost');
        $fromName = (string) $this->settings->get('mail_from_name', 'Nerdadas');

        if ($replyTo !== null
            && (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $replyTo) === 1)
        ) {
            throw new RuntimeException('La dirección de respuesta no es válida.');
        }

        [$headers, $body] = $this->compose($from, $fromName, $to, $subject, $text, $html, $replyTo);

        return $transport === 'smtp'
            ? $this->sendSmtp($from, $to, $subject, $headers, $body)
            : $this->sendMail($to, $subject, $headers, $body);
    }

    /** Prueba de configuración, para el botón del panel. */
    public function test(string $to): string
    {
        $transport = (string) $this->settings->get('mail_transport', 'mail');

        try {
            $ok = $this->send(
                $to,
                'Prueba de envío de Nerdo CMS',
                "Si estás leyendo esto, el transporte \"{$transport}\" funciona.\n\n"
                . 'Enviado el ' . date('d/m/Y H:i') . '.'
            );
        } catch (RuntimeException $e) {
            return 'Falló: ' . $e->getMessage();
        }

        return $ok
            ? "Enviado por {$transport}. Revisá la casilla (y la carpeta de spam)."
            : 'El envío está desactivado (transporte "ninguno").';
    }

    // ------------------------------------------------------------------

    /** @return array{0:array<string,string>,1:string} */
    private function compose(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $text,
        ?string $html,
        ?string $replyTo = null,
    ): array {
        $headers = [
            'From' => $this->encodeName($fromName) . ' <' . $from . '>',
            'Reply-To' => $replyTo ?? $from,
            'MIME-Version' => '1.0',
            'Date' => date('r'),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $this->hostname($from) . '>',
            'X-Mailer' => 'NerdoCMS',
        ];

        if ($html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'base64';

            return [$headers, chunk_split(base64_encode($text))];
        }

        $boundary = 'b' . bin2hex(random_bytes(12));
        $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--{$boundary}--\r\n";

        return [$headers, $body];
    }

    private function sendMail(string $to, string $subject, array $headers, string $body): bool
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return mail($to, $this->encodeSubject($subject), $body, implode("\r\n", $lines));
    }

    /**
     * Cliente SMTP mínimo: EHLO, STARTTLS, AUTH LOGIN, MAIL FROM, RCPT TO, DATA.
     */
    private function sendSmtp(string $from, string $to, string $subject, array $headers, string $body): bool
    {
        $host = (string) $this->settings->get('smtp_host', '');
        $port = (int) $this->settings->get('smtp_port', '587');
        $user = (string) $this->settings->get('smtp_user', '');
        $pass = $this->smtpPassword();
        $secure = (string) $this->settings->get('smtp_secure', 'tls');

        if ($host === '') {
            throw new RuntimeException('Falta configurar el servidor SMTP.');
        }

        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );

        if ($socket === false) {
            throw new RuntimeException("No se pudo conectar a {$host}:{$port} ({$errstr}).");
        }

        stream_set_timeout($socket, 20);

        try {
            $this->expect($socket, 220);

            $ehloHost = $this->hostname($from);
            $this->cmd($socket, 'EHLO ' . $ehloHost, 250);

            if ($secure === 'tls') {
                $this->cmd($socket, 'STARTTLS', 220);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Falló la negociación TLS.');
                }

                // Tras STARTTLS hay que volver a saludar.
                $this->cmd($socket, 'EHLO ' . $ehloHost, 250);
            }

            if ($user !== '') {
                $this->cmd($socket, 'AUTH LOGIN', 334);
                $this->cmd($socket, base64_encode($user), 334);
                $this->cmd($socket, base64_encode($pass), 235);
            }

            $this->cmd($socket, 'MAIL FROM:<' . $from . '>', 250);
            $this->cmd($socket, 'RCPT TO:<' . $to . '>', 250);
            $this->cmd($socket, 'DATA', 354);

            $data = '';
            foreach ($headers as $name => $value) {
                $data .= $name . ': ' . $value . "\r\n";
            }
            $data .= 'To: ' . $to . "\r\n";
            $data .= 'Subject: ' . $this->encodeSubject($subject) . "\r\n";
            $data .= "\r\n" . $body;

            // Punto al principio de línea = fin de mensaje. Se duplica.
            $data = preg_replace('/^\./m', '..', $data) ?? $data;

            fwrite($socket, $data . "\r\n.\r\n");
            $this->expect($socket, 250);

            $this->cmd($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }

        return true;
    }

    private function cmd($socket, string $command, int $expected): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $expected);
    }

    private function expect($socket, int $code): void
    {
        $line = '';
        while (($read = fgets($socket, 515)) !== false) {
            $line = $read;
            // Las respuestas de varias líneas llevan guion en la 4ta posición.
            if (strlen($read) < 4 || $read[3] !== '-') {
                break;
            }
        }

        $got = (int) substr(trim($line), 0, 3);

        if ($got !== $code) {
            throw new RuntimeException("SMTP respondió {$got} y se esperaba {$code}: " . trim($line));
        }
    }

    /**
     * La clave SMTP no se guarda en claro.
     *
     * Va cifrada con la APP_KEY de la instalación: quien consiga un volcado
     * de la base no se lleva además la credencial del servidor de correo.
     */
    public function encryptPassword(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->boxKey();

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    private function smtpPassword(): string
    {
        $stored = (string) $this->settings->get('smtp_pass', '');
        if ($stored === '') {
            return '';
        }

        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            return '';
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->boxKey());

        return $plain === false ? '' : $plain;
    }

    private function boxKey(): string
    {
        return sodium_crypto_generichash($this->appKey, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private function encodeSubject(string $subject): string
    {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }

    private function encodeName(string $name): string
    {
        return preg_match('/[^\x20-\x7E]/', $name) === 1
            ? mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n")
            : '"' . str_replace('"', '', $name) . '"';
    }

    private function hostname(string $from): string
    {
        $parts = explode('@', $from);

        return $parts[1] ?? 'localhost';
    }
}
