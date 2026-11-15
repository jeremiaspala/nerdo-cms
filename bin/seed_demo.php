<?php

declare(strict_types=1);

use App\Core\Kernel;
use App\Models\Post;

/**
 * Contenido de prueba para ver el tema andando.
 *
 * Usa títulos y categorías reales del blog para que los listados, la
 * paginación y la búsqueda se prueben con datos parecidos a los de verdad.
 * La importación completa de WordPress es otra cosa y va en su fase.
 */
function seed_demo(Kernel $app): void
{
    $db = $app->db;

    if ((int) $db->value('SELECT COUNT(*) FROM posts') > 0) {
        echo "Ya hay contenido. Si querés rehacerlo: TRUNCATE y de nuevo.\n";

        return;
    }

    $categories = [
        'seguridad' => 'seguridad',
        'redes' => 'Redes',
        'hardening' => 'hardening',
        'ciberseguridad' => 'Ciberseguridad',
        'programacion' => 'Programación',
        'tecnologia' => 'Tecnología',
        'psicologia' => 'Psicología',
        'montando-tu-red-corporativa-de-0' => 'Montando tu Red Corporativa de 0',
    ];

    $catIds = [];
    foreach ($categories as $slug => $name) {
        $catIds[$slug] = $db->insert('categories', [
            'slug' => $slug,
            'name' => $name,
            'description' => '',
            'meta_description' => 'Entradas sobre ' . $name . ' en Nerdadas.',
        ]);
    }

    $posts = [
        ['ARP Spoofing para Estudiantes de redes', ['ciberseguridad', 'hardening', 'redes'], 5, '2026-11-14 09:15:00'],
        ['Cálculo de Radioenlaces para Curiosos', ['redes', 'tecnologia'], 11, '2026-11-07 10:12:00'],
        ['De user a Root || Hágalo usted mismo!', ['ciberseguridad', 'hardening'], 6, '2026-10-29 09:30:00'],
        ['Hacking for dummies – Metasploit + Metasploitable 2', ['ciberseguridad', 'redes'], 5, '2026-10-27 18:05:00'],
        ['PHP2Shell: cómo parchear y mitigar', ['seguridad', 'hardening'], 6, '2026-10-22 11:45:00'],
        ['Windows Updates y el tráfico en PYMEs', ['montando-tu-red-corporativa-de-0', 'redes'], 9, '2026-10-01 08:20:00'],
        ['Bypass de Biometría Dactilar', ['ciberseguridad', 'seguridad'], 5, '2026-09-29 16:00:00'],
        ['Qué tan difícil es hackear a través de un link', ['ciberseguridad', 'seguridad'], 4, '2026-06-29 12:10:00'],
        ['Congestión Celular en Estadios', ['redes', 'tecnologia'], 7, '2026-06-09 19:30:00'],
        ['Segmentar la red con VLANs sin romper nada', ['montando-tu-red-corporativa-de-0', 'redes'], 8, '2026-05-18 09:00:00'],
        ['Por qué nos cuesta tanto cambiar de hábito', ['psicologia'], 6, '2026-04-20 07:45:00'],
        ['Inyección SQL explicada sin misterio', ['seguridad', 'programacion'], 7, '2026-03-11 14:25:00'],
        ['Backups que de verdad se restauran', ['hardening', 'tecnologia'], 6, '2026-02-02 10:00:00'],
        ['Monitoreo con lo que ya tenés instalado', ['montando-tu-red-corporativa-de-0', 'tecnologia'], 5, '2026-01-15 11:30:00'],
    ];

    $cuerpo = static function (string $titulo): string {
        return '<p>Esta es una entrada de prueba para <strong>' . e($titulo) . '</strong>. '
            . 'Sirve para ver cómo se comporta el tema con texto real: párrafos largos, '
            . 'enlaces como <a href="/categoria/redes">este</a>, y código.</p>'
            . '<h2>Un subtítulo</h2>'
            . '<p>El cuerpo se guarda ya saneado por lista blanca. Si el editor manda algo '
            . 'que no está permitido, se descarta al guardar y no al mostrar.</p>'
            . "<pre><code>ip link show\nip -br addr\ntcpdump -i eth0 arp</code></pre>"
            . '<ul><li>Primer punto</li><li>Segundo punto</li><li>Tercero</li></ul>'
            . '<blockquote>Una cita, para ver el estilo.</blockquote>';
    };

    foreach ($posts as [$title, $cats, $mins, $date]) {
        $html = $cuerpo($title);
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');

        $id = $db->insert('posts', [
            'type' => Post::TYPE_POST,
            'slug' => slugify($title),
            'title' => $title,
            'excerpt' => mb_substr($text, 0, 180),
            'body_html' => $html,
            'body_text' => $text,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $date,
            'meta_description' => mb_substr($text, 0, 150),
            'reading_minutes' => $mins,
            'word_count' => str_word_count($text),
        ]);

        foreach ($cats as $slug) {
            $db->insert('post_categories', ['post_id' => $id, 'category_id' => $catIds[$slug]]);
        }
    }

    // Una página, para probar la rama /p/{slug}.
    $pageHtml = '<p>Soy Jeremías Palazzesi. Administro sistemas y redes, y acá escribo '
        . 'sobre lo que rompo y lo que arreglo.</p>'
        . '<h2>De qué va esto</h2>'
        . '<p>El blog arrancó el 14 de octubre de 2011 y sigue.</p>';

    $db->insert('posts', [
        'type' => Post::TYPE_PAGE,
        'slug' => 'acerca-de-mi',
        'title' => 'Acerca de mí',
        'excerpt' => 'Quién escribe Nerdadas.',
        'body_html' => $pageHtml,
        'body_text' => trim(strip_tags($pageHtml)),
        'status' => Post::STATUS_PUBLISHED,
        'published_at' => '2011-10-14 15:39:00',
        'comments_open' => 0,
    ]);

    // Un permalink viejo de WordPress, para probar el 301 del router.
    $db->insert('redirects', [
        'from_path' => '/blog/arp-spoofing-para-estudiantes-de-redes',
        'to_path' => '/arp-spoofing-para-estudiantes-de-redes',
        'code' => 301,
    ]);

    echo "Sembrado: " . count($posts) . " entradas, 1 página, "
        . count($categories) . " categorías, 1 redirección.\n";
}
