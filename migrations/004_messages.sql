-- Formulario de contacto. Reemplaza a Contact Form 7.
--
-- Los mensajes se guardan además de mandarse por correo. Contact Form 7 solo
-- manda: si el MTA rebota, si el correo cae en spam o si el SMTP está caído,
-- el mensaje se perdió y nadie se entera —ni quien lo escribió, que vio un
-- "gracias"—. Guardarlo cuesta una tabla y hace que el correo sea un aviso y
-- no el único soporte.
--
-- Acá el correo SÍ se guarda en claro, al revés que en los comentarios. No es
-- una inconsistencia: en un comentario el correo es un dato de más que solo
-- sirve para reconocer a alguien, y por eso va hasheado. En un mensaje de
-- contacto el correo es el mensaje — es la dirección a la que hay que
-- contestar—. Guardar un hash sería guardar algo inservible.
CREATE TABLE messages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    email        VARCHAR(190) NOT NULL,
    subject      VARCHAR(200) NOT NULL DEFAULT '',
    body         TEXT         NOT NULL,
    status       ENUM('new','read','archived','spam') NOT NULL DEFAULT 'new',
    spam_score   SMALLINT     NOT NULL DEFAULT 0,
    spam_reasons VARCHAR(400) NOT NULL DEFAULT '',
    ip_hash      CHAR(32)     NOT NULL,
    user_agent   VARCHAR(255) NOT NULL DEFAULT '',
    mailed       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at      DATETIME     NULL,
    PRIMARY KEY (id),
    KEY ix_messages_status (status, created_at),
    KEY ix_messages_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
    ('contact_enabled',  '1'),
    ('contact_page',     'contacto'),
    ('contact_to',       ''),
    ('contact_intro',    'Escribime y te contesto. No uso el correo para nada más.'),
    ('contact_max_per_day', '5')
ON DUPLICATE KEY UPDATE `key` = `key`;
