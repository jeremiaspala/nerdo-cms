<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Message;
use App\Services\Mailer;
use App\Services\Spam;
use Throwable;

/**
 * Formulario de contacto. Reemplaza a Contact Form 7.
 *
 * Mismo esqueleto que los comentarios —desafío firmado, límite de tasa, campo
 * trampa, piso de tiempo, origen, prueba de trabajo, validación, puntaje— y
 * por las mismas razones. Lo que cambia es qué se hace con lo que entra:
 *
 *  - **Se guarda y además se manda.** Contact Form 7 solo manda. Si el MTA
 *    rebota o el SMTP está caído, ese mensaje no existió para nadie, ni
 *    siquiera para quien lo escribió, que vio un «gracias». Acá el correo es
 *    un aviso; el soporte es la tabla.
 *  - **No se le contesta nada a quien escribe.** Un acuse automático al
 *    remitente convierte al formulario en una forma de mandarle correo a
 *    cualquiera desde este servidor: se pone la dirección de la víctima y se
 *    aprieta enviar. El único correo que sale va al dueño del sitio.
 *  - **El cuerpo es texto plano**, sin saneador y sin HTML. Un mensaje de
 *    contacto no necesita ni una etiqueta, y lo que no se admite no hay que
 *    sanearlo.
 */
final class ContactController extends Controller
{
    private const MAX_BODY = 5000;

    /**
     * El desafío de los comentarios va atado al id de la entrada. Este no
     * cuelga de ningún contenido, así que usa 0 como alcance: un desafío
     * emitido para contacto no sirve para comentar ni al revés.
     */
    private const SCOPE = 0;

    public function challenge(Request $request): Response
    {
        $this->app->limiter->hit('contact_challenge', $request->ipHash, max: 30, perSeconds: 3600);

        if (!$this->enabled()) {
            return Response::json(['error' => 'El formulario de contacto está cerrado.'], 403);
        }

        $challenge = (new Spam($this->app))->issue(self::SCOPE);

        return Response::json([
            'token' => $challenge['token'],
            'nonce' => $challenge['nonce'],
            'bits' => $challenge['bits'],
            'floor' => 8,
        ]);
    }

    public function send(Request $request): Response
    {
        $spam = new Spam($this->app);
        $messages = new Message($this->app->db);

        if (!$this->enabled()) {
            return $this->fail('El formulario de contacto está cerrado.', 403);
        }

        // ---- 1. Desafío firmado: hace de token CSRF sin sesión.
        $claim = $spam->openToken($request->post('t'), self::SCOPE);

        if ($claim === null) {
            return $this->fail('El formulario venció. Recargá la página y volvé a intentar.', 400);
        }

        // ---- 2. Límite de tasa.
        $this->app->limiter->hit('contact', $request->ipHash, max: 5, perSeconds: 600);

        // ---- 3. Campo trampa.
        if ($request->post('sitio') !== '' || $request->post('apodo') !== '') {
            return $this->fail('No pudimos enviar el mensaje.', 422);
        }

        // ---- 4. Piso de tiempo.
        if (!$spam->timingOk($claim['t'])) {
            return $this->fail('Demasiado rápido (o demasiado tarde). Recargá la página.', 422);
        }

        // ---- 5. Origen.
        if (!$spam->originOk($request)) {
            return $this->fail('Origen no válido.', 403);
        }

        // ---- 6. Prueba de trabajo, y el desafío se gasta acá.
        if (!$spam->powOk($claim['n'], $request->post('pow'), $claim['b'])) {
            return $this->fail('La verificación del navegador no cerró. Recargá la página.', 422);
        }

        if (!$spam->consumeNonce($claim['n'])) {
            return $this->fail('Ese formulario ya se usó. Recargá la página.', 409);
        }

        // ---- 7. Validación.
        $name = mb_substr($request->post('nombre'), 0, 120);
        $email = mb_strtolower($request->post('email'));
        $subject = mb_substr($request->post('asunto'), 0, 200);
        $body = mb_substr(trim($request->postRaw('cuerpo')), 0, self::MAX_BODY);

        if (mb_strlen($name) < 2) {
            return $this->fail('Falta el nombre.', 422, 'nombre');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Ese correo no parece válido, y es a donde te contesto.', 422, 'email');
        }

        // Un salto de línea en el nombre o en el asunto termina adentro de
        // una cabecera del correo. El Mailer también lo rechaza; esto corta
        // antes y sin que parezca un error del servidor.
        if (preg_match('/[\r\n]/', $name . $subject . $email) === 1) {
            return $this->fail('Hay un salto de línea donde no va.', 422);
        }

        if (mb_strlen($body) < 10) {
            return $this->fail('Contame un poco más: el mensaje es muy corto.', 422, 'cuerpo');
        }

        $perDay = max(1, (int) $this->app->settings->get('contact_max_per_day', '5'));

        if ($messages->recentFromIp($request->ipHash) >= $perDay) {
            return $this->fail('Ya mandaste varios mensajes hoy. Si es urgente, escribime por las redes.', 429);
        }

        // ---- 8. Puntaje, con las mismas señales que los comentarios.
        $emailHash = hash_hmac('sha256', $email, $this->app->env->require('HASH_PEPPER'));
        $verdict = $spam->score($name, $emailHash, '', $subject . "\n" . $body, $request);

        $isSpam = $verdict['score'] >= Spam::SPAM_THRESHOLD;

        // ---- 9. Guardar. Siempre, incluso el spam: queda en su bandeja y se
        //         vacía de a uno o en lote, igual que los comentarios.
        $id = $messages->create([
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'body' => $body,
            'status' => $isSpam ? Message::SPAM : Message::NEW,
            'spam_score' => $verdict['score'],
            'spam_reasons' => mb_substr(implode(' · ', $verdict['reasons']), 0, 400),
            'ip_hash' => $request->ipHash,
            'user_agent' => $request->userAgent(),
        ]);

        if (!$isSpam && $this->notify($id, $name, $email, $subject, $body)) {
            $messages->markMailed($id);
        }

        // A quien escribió se le contesta lo mismo pase lo que pase, incluso
        // si el puntaje lo mandó a spam: decirle «tu mensaje parece spam» es
        // enseñarle al que sí manda spam qué tiene que cambiar.
        return Response::json([
            'ok' => true,
            'message' => 'Listo, llegó. Te contesto a la brevedad.',
        ]);
    }

    // ------------------------------------------------------------------

    private function enabled(): bool
    {
        return $this->app->settings->bool('contact_enabled', true);
    }

    private function notify(int $id, string $name, string $email, string $subject, string $body): bool
    {
        $to = (string) $this->app->settings->get('contact_to', '');

        if ($to === '') {
            $to = (string) $this->app->db->value(
                "SELECT email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"
            );
        }

        if ($to === '') {
            return false;
        }

        $title = $subject !== '' ? $subject : 'sin asunto';

        try {
            $sent = (new Mailer($this->app->settings, $this->app->env->require('APP_KEY')))->send(
                $to,
                'Contacto: ' . mb_substr($title, 0, 120),
                "{$name} <{$email}> escribió desde el formulario de contacto:\n\n"
                . $body . "\n\n"
                . '— Mensaje #' . $id . ' · ' . $this->app->url($this->app->adminPath . '/mensajes'),
                null,
                // Responder al correo contesta a quien escribió, no al propio
                // servidor. La dirección ya pasó por FILTER_VALIDATE_EMAIL y
                // por el control de saltos de línea.
                $email
            );

            // mail() devuelve false sin lanzar nada: es la forma más común de
            // perder un mensaje sin enterarse. Queda en el log y, además, en
            // la bandeja del panel con el cartel de que no salió.
            if (!$sent) {
                $this->app->logger->warning('El aviso del mensaje #' . $id . ' no salió', [
                    'mensaje' => $id,
                    'transporte' => (string) $this->app->settings->get('mail_transport', 'mail'),
                ]);
            }

            return $sent;
        } catch (Throwable $e) {
            $this->app->logger->warning('No se pudo avisar del mensaje #' . $id, [
                'mensaje' => $id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function fail(string $message, int $status, ?string $field = null): Response
    {
        return Response::json(array_filter([
            'error' => $message,
            'field' => $field,
        ]), $status);
    }
}
