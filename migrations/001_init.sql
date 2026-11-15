-- 001_init — esquema base del CMS.
--
-- Notas de diseño:
--  * posts.type separa entradas de páginas sin duplicar tablas ni consultas.
--  * body_html es lo que sale del editor, ya saneado. body_text es la versión
--    sin etiquetas y existe solo para el índice FULLTEXT: indexar HTML
--    ensucia los resultados con nombres de etiqueta y atributos.
--  * De comentarios y visitas no se guarda IP ni correo en claro, solo HMAC.
--  * Todo utf8mb4: el contenido tiene acentos, comillas tipográficas y emoji.

CREATE TABLE users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email           VARCHAR(190)  NOT NULL,
    name            VARCHAR(120)  NOT NULL,
    pass_hash       VARCHAR(255)  NOT NULL,
    role            ENUM('admin','editor') NOT NULL DEFAULT 'editor',
    totp_secret     VARBINARY(255) NULL,
    totp_confirmed  TINYINT(1)    NOT NULL DEFAULT 0,
    failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME      NULL,
    last_login_at   DATETIME      NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(80)  NOT NULL,
    name             VARCHAR(120) NOT NULL,
    description      VARCHAR(500) NOT NULL DEFAULT '',
    parent_id        INT UNSIGNED NULL,
    meta_title       VARCHAR(190) NOT NULL DEFAULT '',
    meta_description VARCHAR(320) NOT NULL DEFAULT '',
    position         SMALLINT     NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY ix_categories_parent (parent_id),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id)
        REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tags (
    id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80)  NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename   VARCHAR(190) NOT NULL,
    mime       VARCHAR(100) NOT NULL,
    bytes      INT UNSIGNED NOT NULL,
    width      SMALLINT UNSIGNED NULL,
    height     SMALLINT UNSIGNED NULL,
    alt        VARCHAR(300) NOT NULL DEFAULT '',
    sha256     CHAR(64)     NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_sha (sha256),
    UNIQUE KEY uq_media_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type              ENUM('post','page') NOT NULL DEFAULT 'post',
    slug              VARCHAR(190) NOT NULL,
    title             VARCHAR(300) NOT NULL,
    excerpt           VARCHAR(500) NOT NULL DEFAULT '',
    body_html         MEDIUMTEXT   NOT NULL,
    body_text         MEDIUMTEXT   NOT NULL,
    status            ENUM('draft','published','trash') NOT NULL DEFAULT 'draft',
    published_at      DATETIME     NULL,
    author_id         INT UNSIGNED NULL,
    featured_media_id INT UNSIGNED NULL,
    og_media_id       INT UNSIGNED NULL,
    meta_title        VARCHAR(190) NOT NULL DEFAULT '',
    meta_description  VARCHAR(320) NOT NULL DEFAULT '',
    canonical         VARCHAR(400) NOT NULL DEFAULT '',
    noindex           TINYINT(1)   NOT NULL DEFAULT 0,
    comments_open     TINYINT(1)   NOT NULL DEFAULT 1,
    reading_minutes   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    word_count        MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_posts_type_slug (type, slug),
    KEY ix_posts_listing (type, status, published_at),
    KEY ix_posts_author (author_id),
    FULLTEXT KEY ft_posts (title, excerpt, body_text),
    CONSTRAINT fk_posts_author FOREIGN KEY (author_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_posts_featured FOREIGN KEY (featured_media_id)
        REFERENCES media (id) ON DELETE SET NULL,
    CONSTRAINT fk_posts_og FOREIGN KEY (og_media_id)
        REFERENCES media (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_categories (
    post_id     INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, category_id),
    KEY ix_pc_category (category_id),
    CONSTRAINT fk_pc_post FOREIGN KEY (post_id)
        REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_pc_category FOREIGN KEY (category_id)
        REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_tags (
    post_id INT UNSIGNED NOT NULL,
    tag_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (post_id, tag_id),
    KEY ix_pt_tag (tag_id),
    CONSTRAINT fk_pt_post FOREIGN KEY (post_id)
        REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_tag FOREIGN KEY (tag_id)
        REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id     INT UNSIGNED NOT NULL,
    parent_id   INT UNSIGNED NULL,
    author_name VARCHAR(120) NOT NULL,
    author_url  VARCHAR(300) NOT NULL DEFAULT '',
    email_hash  CHAR(64)     NOT NULL,
    body_html   TEXT         NOT NULL,
    status      ENUM('pending','approved','spam','trash') NOT NULL DEFAULT 'pending',
    spam_score  SMALLINT     NOT NULL DEFAULT 0,
    ip_hash     CHAR(32)     NOT NULL,
    user_agent  VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_comments_post (post_id, status, created_at),
    KEY ix_comments_email (email_hash, status),
    KEY ix_comments_parent (parent_id),
    CONSTRAINT fk_comments_post FOREIGN KEY (post_id)
        REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id)
        REFERENCES comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
    `key`      VARCHAR(80)  NOT NULL,
    `value`    TEXT         NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acá viven los permalinks de WordPress. El router la consulta antes del 404.
CREATE TABLE redirects (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_path   VARCHAR(400) NOT NULL,
    to_path     VARCHAR(400) NOT NULL,
    code        SMALLINT     NOT NULL DEFAULT 301,
    hits        INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_redirects_from (from_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- En base y no en disco, para poder revocarlas de un DELETE.
CREATE TABLE sessions (
    id                 CHAR(64)     NOT NULL,
    user_id            INT UNSIGNED NULL,
    payload            MEDIUMTEXT   NOT NULL,
    ip_hash            CHAR(32)     NOT NULL DEFAULT '',
    user_agent         VARCHAR(255) NOT NULL DEFAULT '',
    created_at         DATETIME     NOT NULL,
    updated_at         DATETIME     NOT NULL,
    expires_at         DATETIME     NOT NULL,
    absolute_expires_at DATETIME    NOT NULL,
    PRIMARY KEY (id),
    KEY ix_sessions_user (user_id),
    KEY ix_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    bucket       VARCHAR(32) NOT NULL,
    subject      VARCHAR(64) NOT NULL,
    window_start DATETIME    NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, subject, window_start),
    KEY ix_rl_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(60)  NOT NULL,
    target     VARCHAR(190) NOT NULL DEFAULT '',
    detail     TEXT         NULL,
    ip_hash    CHAR(32)     NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_user (user_id, created_at),
    KEY ix_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La tabla migrations la crea bin/console antes de correr nada: es suya,
-- no de una migración.

INSERT INTO settings (`key`, `value`) VALUES
    ('site_title',          'Nerdadas!'),
    ('site_tagline',        '¿A quién va usted a creer?, ¿a mí, o a sus propios ojos?'),
    ('meta_description',    'Redes, seguridad, sistemas y oficio. Desde 2011.'),
    ('meta_title_pattern',  '%title% · %site%'),
    ('posts_per_page',      '10'),
    ('comments_open_days',  '90'),
    ('comments_require_moderation', '1'),
    ('social_mastodon',     ''),
    ('social_x',            ''),
    ('analytics_enabled',   '1');
