<?php

declare(strict_types=1);

namespace App\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * El control de seguridad del contenido del editor.
 *
 * Lo que sale de acá es lo único que después se imprime sin escapar, así que
 * este archivo es el punto más sensible del CMS.
 *
 * Dos aclaraciones que importan:
 *
 *  1. La lista de formatos que configura Quill del lado del cliente NO es una
 *     medida de seguridad: cualquiera puede mandar un POST directo con el
 *     cuerpo que quiera. La única barrera real es esta.
 *
 *  2. Se sanea AL GUARDAR, no al mostrar. Así el costo se paga una vez por
 *     edición en lugar de en cada visita, y lo que hay en la base ya es
 *     seguro aunque algún día se lo consuma desde otro lado.
 */
final class HtmlSanitizer
{
    private ?HTMLPurifier $post = null;
    private ?HTMLPurifier $comment = null;

    public function __construct(private readonly string $cacheDir)
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0770, true);
        }
    }

    /** Contenido de entradas y páginas: lo que produce el editor. */
    public function post(string $html): string
    {
        $this->post ??= new HTMLPurifier($this->config(
            // El [class] va en los elementos que Quill marca con clase para
            // alineación y sangría. Qué valores de clase sobreviven lo decide
            // Attr.AllowedClasses, más abajo.
            allowed: 'p[class],br,strong,em,u,s,blockquote,pre[class],code,'
                . 'h2[class],h3[class],h4[class],ul,ol,li[class],hr,'
                . 'a[href|title|rel|target],'
                . 'img[src|alt|width|height|loading],'
                . 'figure[class],figcaption,'
                . 'iframe[src|title|loading|allowfullscreen|width|height],'
                . 'table,thead,tbody,tr,th[scope|colspan|rowspan],td[colspan|rowspan],'
                . 'span[class],div[class]',
            // Quill marca alineación y listas con clases propias. Se admiten
            // solo esas, por nombre: nada de class libre.
            allowedClasses: [
                'ql-syntax', 'ql-align-center', 'ql-align-right', 'ql-align-justify',
                'ql-indent-1', 'ql-indent-2', 'ql-indent-3',
                'video', 'embed-link',
            ],
            videos: true,
        ));

        return self::stripRemoteImages($this->post->purify($html));
    }

    /**
     * Fuera las imágenes alojadas en otros sitios.
     *
     * Una <img> remota es un píxel de rastreo: le filtra a un tercero la IP
     * y el user-agent de cada persona que lee la entrada, sin que el lector
     * se entere. Además la CSP del sitio solo admite img-src 'self', así que
     * igual se vería rota. El importador las baja; lo que llegue por otro
     * lado se descarta acá.
     *
     * Los <iframe> no entran en esta regla: ya pasaron por una lista blanca
     * de dominios mucho más estrecha.
     */
    private static function stripRemoteImages(string $html): string
    {
        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $m): string {
                if (!preg_match('/\bsrc=["\']([^"\']*)["\']/i', $m[0], $src)) {
                    return '';
                }

                $url = trim(html_entity_decode($src[1], ENT_QUOTES, 'UTF-8'));

                // Solo rutas propias: /uploads/... y similares.
                $isLocal = str_starts_with($url, '/') && !str_starts_with($url, '//');

                return $isLocal ? $m[0] : '';
            },
            $html
        ) ?? $html;
    }

    /** Comentarios: mucho más cerrado. Ni imágenes ni tablas ni clases. */
    public function comment(string $html): string
    {
        $this->comment ??= new HTMLPurifier($this->config(
            allowed: 'p,br,strong,em,code,pre,blockquote,ul,ol,li,a[href]',
            allowedClasses: [],
            nofollow: true,
        ));

        return $this->comment->purify($html);
    }

    private function config(
        string $allowed,
        array $allowedClasses,
        bool $nofollow = false,
        bool $videos = false,
    ): HTMLPurifier_Config {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('Cache.SerializerPath', $this->cacheDir);
        $config->set('Cache.SerializerPermissions', 0770);

        if ($videos) {
            $this->allowVideoFrames($config);
        }

        // HTMLPurifier no tiene doctype HTML5: el suyo es XHTML 1.0, que no
        // conoce <figure> ni <figcaption> y los borra en silencio. Se los
        // agregamos a la definición, que es la forma soportada de hacerlo.
        $config->set('HTML.DefinitionID', 'nerdo-cms');
        $config->set('HTML.DefinitionRev', 4);

        $config->set('HTML.Allowed', $allowed);
        $config->set('Core.Encoding', 'UTF-8');

        // Nada de estilos en línea: la CSP del sitio no los admite y además
        // son un vector clásico (position:fixed encima de otra cosa).
        $config->set('CSS.AllowedProperties', []);

        if ($allowedClasses !== []) {
            $config->set('Attr.AllowedClasses', $allowedClasses);
        } else {
            $config->set('Attr.AllowedClasses', []);
        }

        // Solo esquemas que no ejecutan nada. Sin javascript:, sin data:.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // Ojo con esta: DisableExternalResources bloquea TODO recurso
        // externo, incluido el iframe de video que sí queremos. Por eso
        // queda apagada acá y las imágenes remotas se filtran aparte, en
        // stripRemoteImages(), que es una regla sola y explícita.
        $config->set('URI.DisableExternalResources', false);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);

        // Todo enlace externo sale con rel seguro: sin window.opener y, en
        // comentarios, sin pasar autoridad de enlace.
        $config->set('HTML.TargetBlank', false);
        $config->set('HTML.Nofollow', $nofollow);
        $config->set('Attr.DefaultInvalidImageAlt', '');

        // IDs libres permiten pisar elementos del tema por DOM clobbering.
        $config->set('Attr.EnableID', false);

        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);

        // Un <iframe> y una <img> no tienen contenido por definición: sin
        // esta excepción, "quitar elementos vacíos" se los lleva puestos.
        $config->set('AutoFormat.RemoveEmpty.Predicate', [
            'colgroup' => [], 'th' => [], 'td' => [], 'br' => [], 'hr' => [],
            'iframe' => ['src'], 'img' => ['src'],
        ]);

        // Devuelve null si la definición ya está cacheada: solo se arma una vez.
        $def = $config->maybeGetRawHTMLDefinition();
        if ($def !== null) {
            $def->addElement('figure', 'Block', 'Flow', 'Common');
            $def->addElement('figcaption', 'Block', 'Flow', 'Common');

            // HTMLPurifier no conoce estos atributos de HTML5. Son inocuos
            // —no ejecutan nada— y hacen falta: loading="lazy" evita que el
            // marco de YouTube se cargue antes de que el lector llegue.
            $def->addAttribute('img', 'loading', 'Enum#lazy,eager');
            $def->addAttribute('iframe', 'loading', 'Enum#lazy,eager');
            $def->addAttribute('iframe', 'allowfullscreen', 'Bool#allowfullscreen');
            $def->addAttribute('iframe', 'title', 'Text');
        }

        return $config;
    }

    /**
     * Marcos de video permitidos.
     *
     * Un iframe es el único elemento que puede traer código de otro dominio
     * a la página, así que se admite solo contra una lista cerrada y en sus
     * dominios sin cookies. SafeIframe de HTMLPurifier compara el src contra
     * esta expresión y descarta todo lo demás.
     *
     * La CSP lleva el mismo recorte en frame-src: si alguna vez este
     * saneador fallara, el navegador sigue sin cargar el marco.
     */
    private function allowVideoFrames(HTMLPurifier_Config $config): void
    {
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', '%^https://(?:www\.youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)%');
    }

    /** Versión en texto plano, para el índice FULLTEXT y el extracto. */
    public static function toText(string $html): string
    {
        $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#<(br|/p|/h[1-6]|/li|/div)[^>]*>#i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    public static function readingMinutes(string $text): int
    {
        $words = max(1, count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        return max(1, (int) round($words / 220));
    }
}
