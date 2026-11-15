-- 002_analytics — medición propia, del lado del servidor.
--
-- Por qué no hay cookie ni JavaScript: sin identificador persistente en el
-- navegador del visitante, esto no es "seguimiento" en el sentido del RGPD
-- ni de la ley local, y por lo tanto no necesita banner de consentimiento.
--
-- visitor_hash es HMAC(ip + user-agent + FECHA + pepper). Al entrar la fecha
-- en el hash, el identificador cambia solo cada medianoche: alcanza para
-- contar visitantes únicos de un día, y es inútil para seguir a alguien de
-- un día para otro. La IP cruda no se guarda en ningún lado.

CREATE TABLE hits (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    path         VARCHAR(255) NOT NULL,
    post_id      INT UNSIGNED NULL,
    referer_host VARCHAR(120) NOT NULL DEFAULT '',
    device       ENUM('desktop','mobile','tablet','bot','other') NOT NULL DEFAULT 'other',
    browser      VARCHAR(40)  NOT NULL DEFAULT '',
    os           VARCHAR(40)  NOT NULL DEFAULT '',
    visitor_hash CHAR(32)     NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_hits_created (created_at),
    KEY ix_hits_post (post_id, created_at),
    KEY ix_hits_visitor (visitor_hash, created_at),
    CONSTRAINT fk_hits_post FOREIGN KEY (post_id)
        REFERENCES posts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- El agregado no caduca; el detalle de arriba se borra a los N días.
CREATE TABLE stats_daily (
    day      DATE         NOT NULL,
    path     VARCHAR(255) NOT NULL,
    post_id  INT UNSIGNED NULL,
    views    INT UNSIGNED NOT NULL DEFAULT 0,
    visitors INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, path),
    KEY ix_daily_post (post_id, day),
    KEY ix_daily_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stats_referers (
    day   DATE         NOT NULL,
    host  VARCHAR(120) NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, host),
    KEY ix_ref_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
    ('analytics_retention_days', '30'),
    ('analytics_ignore_admin',   '1'),
    ('cache_enabled',            '1'),
    ('cache_ttl',                '3600'),
    ('comments_enabled',         '1'),
    ('comments_max_links',       '2'),
    ('pow_bits',                 '18'),
    ('share_buttons',            '1'),
    ('header_overlay',           'medio'),
    ('mail_transport',           'mail'),
    ('mail_from_name',           'Nerdadas')
ON DUPLICATE KEY UPDATE `key` = `key`;
