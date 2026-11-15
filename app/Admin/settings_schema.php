<?php

declare(strict_types=1);

/**
 * Esquema de la configuración.
 *
 * Todo lo configurable del CMS está declarado acá: el controlador y la vista
 * son genéricos y no saben de ningún ajuste en particular. Agregar una
 * opción nueva es agregar una línea en este archivo, no tocar tres lugares.
 *
 * Tipos: text, textarea, email, number, select, bool, password, media, info
 */
return [

    'sitio' => [
        'titulo' => 'Sitio',
        'descripcion' => 'Lo que identifica al blog y lo que ve el lector arriba de todo.',
        'campos' => [
            'site_title' => ['label' => 'Nombre del sitio', 'type' => 'text', 'default' => 'Nerdadas!'],
            'site_tagline' => ['label' => 'Bajada', 'type' => 'text', 'default' => ''],
            'header_media_id' => [
                'label' => 'Imagen de fondo del encabezado',
                'type' => 'media',
                'help' => 'Se muestra detrás del nombre, con el degradé verde encima. Si la dejás vacía, queda solo el degradé.',
            ],
            'header_overlay' => [
                'label' => 'Velo detrás del título',
                'type' => 'select',
                'default' => 'medio',
                'options' => ['suave' => 'Suave', 'medio' => 'Medio', 'fuerte' => 'Fuerte'],
                'help' => 'Una sombra neutra sobre la franja izquierda del encabezado, que es donde va el texto. No tiñe la foto ni la tapa: del centro hacia la derecha se ve tal cual. Subilo solo si la imagen es clara y el título no se lee.',
            ],
            'posts_per_page' => ['label' => 'Entradas por página', 'type' => 'number', 'default' => '10', 'min' => 3, 'max' => 50],
        ],
    ],

    'seo' => [
        'titulo' => 'SEO',
        'descripcion' => 'Lo que leen Google y las redes cuando alguien comparte un enlace.',
        'campos' => [
            'meta_description' => ['label' => 'Descripción por defecto', 'type' => 'textarea', 'maxlength' => 320],
            'meta_title_pattern' => [
                'label' => 'Patrón del título',
                'type' => 'text',
                'default' => '%title% · %site%',
                'help' => 'Usá %title% para el título de la entrada y %site% para el del sitio.',
            ],
            'og_media_id' => ['label' => 'Imagen por defecto para compartir', 'type' => 'media',
                'help' => 'La que se usa cuando una entrada no tiene imagen destacada.'],
            'robots_extra' => ['label' => 'Líneas extra en robots.txt', 'type' => 'textarea',
                'help' => 'Se agregan tal cual al final del archivo generado.'],
        ],
    ],

    'redes' => [
        'titulo' => 'Redes sociales',
        'descripcion' => 'Solo enlaces y metadatos: no se carga ningún script de terceros.',
        'campos' => [
            'social_x' => ['label' => 'X (sin @)', 'type' => 'text'],
            'social_mastodon' => ['label' => 'Mastodon (URL completa)', 'type' => 'text'],
            'social_github' => ['label' => 'GitHub (usuario)', 'type' => 'text'],
            'social_linkedin' => ['label' => 'LinkedIn (URL completa)', 'type' => 'text'],
            'share_buttons' => ['label' => 'Mostrar botones de compartir', 'type' => 'bool', 'default' => '1'],
        ],
    ],

    'correo' => [
        'titulo' => 'Correo',
        'descripcion' => 'Para los avisos de comentarios y el formulario de contacto.',
        'campos' => [
            'mail_transport' => [
                'label' => 'Cómo se envía',
                'type' => 'select',
                'default' => 'mail',
                'options' => [
                    'mail' => 'mail() de PHP — sin configurar nada',
                    'smtp' => 'SMTP — servidor propio o externo',
                    'none' => 'No enviar correo',
                ],
                'help' => 'mail() depende del MTA del servidor y suele caer en spam porque el correo sale sin autenticar. SMTP es más trabajo y es lo que hay que usar para que llegue.',
            ],
            'mail_from' => ['label' => 'Remitente', 'type' => 'email', 'default' => ''],
            'mail_from_name' => ['label' => 'Nombre del remitente', 'type' => 'text', 'default' => 'Nerdadas'],
            'mail_to' => ['label' => 'Avisos a', 'type' => 'email',
                'help' => 'Dónde llegan los avisos de comentarios por moderar y los mensajes de contacto.'],
            'smtp_host' => ['label' => 'Servidor SMTP', 'type' => 'text', 'depends' => 'mail_transport=smtp'],
            'smtp_port' => ['label' => 'Puerto', 'type' => 'number', 'default' => '587', 'min' => 1, 'max' => 65535, 'depends' => 'mail_transport=smtp'],
            'smtp_secure' => [
                'label' => 'Cifrado',
                'type' => 'select',
                'default' => 'tls',
                'options' => ['tls' => 'STARTTLS (puerto 587)', 'ssl' => 'SSL/TLS directo (puerto 465)', 'none' => 'Sin cifrar'],
                'depends' => 'mail_transport=smtp',
            ],
            'smtp_user' => ['label' => 'Usuario', 'type' => 'text', 'depends' => 'mail_transport=smtp'],
            'smtp_pass' => [
                'label' => 'Contraseña',
                'type' => 'password',
                'depends' => 'mail_transport=smtp',
                'help' => 'Se guarda cifrada con la APP_KEY de la instalación: un volcado de la base no alcanza para leerla.',
            ],
        ],
    ],

    'comentarios' => [
        'titulo' => 'Comentarios',
        'descripcion' => 'Moderación y antispam. Sin reCAPTCHA ni servicios de terceros.',
        'campos' => [
            'comments_enabled' => ['label' => 'Aceptar comentarios', 'type' => 'bool', 'default' => '1'],
            'comments_open_days' => [
                'label' => 'Cerrar a los (días)',
                'type' => 'number', 'default' => '90', 'min' => 0, 'max' => 3650,
                'help' => 'Pasado ese tiempo desde la publicación no se aceptan más. 0 = nunca cerrar. El spam de enlaces empieza justo en las entradas viejas.',
            ],
            'comments_require_moderation' => [
                'label' => 'Moderar siempre',
                'type' => 'bool', 'default' => '1',
                'help' => 'Si lo apagás, quien ya tenga un comentario aprobado pasa directo.',
            ],
            'pow_bits' => [
                'label' => 'Dificultad del captcha',
                'type' => 'select', 'default' => '18',
                'options' => ['16' => 'Baja (16 bits, ~0,2 s)', '18' => 'Media (18 bits, ~0,5 s)', '20' => 'Alta (20 bits, ~2 s)'],
                'help' => 'Prueba de trabajo en el navegador del visitante. No tiene que resolver nada; solo le cuesta CPU, que es lo que hace inviable el spam masivo.',
            ],
            'comments_max_links' => ['label' => 'Máximo de enlaces por comentario', 'type' => 'number', 'default' => '2', 'min' => 0, 'max' => 20],
            'comments_max_per_hour' => [
                'label' => 'Máximo por visitante y por hora',
                'type' => 'number', 'default' => '5', 'min' => 1, 'max' => 50,
                'help' => 'Corta el goteo. El límite de ráfaga es aparte y más estricto.',
            ],
            'comments_blocklist' => [
                'label' => 'Palabras vetadas',
                'type' => 'textarea',
                'help' => 'Una por línea. Si aparece en el cuerpo, el nombre o la dirección, el comentario entra directo a spam.',
            ],
            'comments_notify' => [
                'label' => 'Avisarme por correo',
                'type' => 'bool', 'default' => '1',
                'help' => 'Solo de lo que entra a moderación o se publica. Del spam no se avisa nunca: ese correo sería el ataque.',
            ],
            'comments_notify_to' => [
                'label' => 'Avisar a',
                'type' => 'text', 'depends' => 'comments_notify=1',
                'help' => 'Si lo dejás vacío, va al correo del primer administrador.',
            ],
        ],
    ],

    'contacto' => [
        'titulo' => 'Contacto',
        'descripcion' => 'El formulario que reemplaza a Contact Form 7. Mismas capas antispam que los comentarios.',
        'campos' => [
            'contact_enabled' => ['label' => 'Mostrar el formulario', 'type' => 'bool', 'default' => '1'],
            'contact_page' => [
                'label' => 'En qué página',
                'type' => 'text', 'default' => 'contacto',
                'help' => 'El slug de la página donde se dibuja el formulario, debajo del texto que escribas ahí. Por defecto: contacto (la URL vieja, que ya está indexada).',
            ],
            'contact_intro' => [
                'label' => 'Texto arriba del formulario',
                'type' => 'textarea',
                'maxlength' => 400,
            ],
            'contact_to' => [
                'label' => 'Mandar los mensajes a',
                'type' => 'text',
                'help' => 'Si lo dejás vacío, van al correo del primer administrador. Los mensajes se guardan en el panel aunque el correo falle.',
            ],
            'contact_max_per_day' => [
                'label' => 'Máximo por visitante y por día',
                'type' => 'number', 'default' => '5', 'min' => 1, 'max' => 50,
            ],
        ],
    ],

    'analitica' => [
        'titulo' => 'Analítica',
        'descripcion' => 'Propia, del lado del servidor. Sin cookies, sin JavaScript y sin terceros: por eso no necesitás banner de consentimiento.',
        'campos' => [
            'analytics_enabled' => ['label' => 'Registrar visitas', 'type' => 'bool', 'default' => '1'],
            'analytics_retention_days' => [
                'label' => 'Guardar el detalle (días)',
                'type' => 'number', 'default' => '30', 'min' => 1, 'max' => 365,
                'help' => 'Pasado ese plazo queda solo el agregado diario, que no caduca. El detalle crudo se borra.',
            ],
            'analytics_ignore_admin' => ['label' => 'No contar mis propias visitas', 'type' => 'bool', 'default' => '1'],
        ],
    ],

    'rendimiento' => [
        'titulo' => 'Rendimiento',
        'descripcion' => 'El caché sirve HTML ya armado a los visitantes anónimos, sin tocar la base.',
        'campos' => [
            'cache_enabled' => ['label' => 'Caché de página', 'type' => 'bool', 'default' => '1'],
            'cache_ttl' => ['label' => 'Vigencia (segundos)', 'type' => 'number', 'default' => '3600', 'min' => 60, 'max' => 604800,
                'help' => 'Se invalida solo al publicar o editar, así que podés poner un número alto.'],
        ],
    ],
];
