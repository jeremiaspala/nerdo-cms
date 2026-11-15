-- Fase 6: comentarios.
--
-- La tabla `comments` ya existía desde 001 (el importador la llenó). Acá va
-- lo que hace falta para aceptarlos desde el frente.

-- Desafíos de prueba de trabajo ya gastados.
--
-- El desafío es un token firmado con la APP_KEY y no se guarda en ninguna
-- parte al emitirse: eso es lo que permite que la página se sirva desde el
-- caché y que el visitante anónimo no reciba cookie. El precio es que un
-- desafío resuelto se podría reusar hasta que venza, así que al consumirlo
-- se registra acá. El UNIQUE es la garantía: dos POST simultáneos con el
-- mismo nonce, uno entra y el otro choca.
CREATE TABLE comment_nonces (
    nonce   CHAR(32) NOT NULL,
    used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (nonce),
    KEY ix_nonces_used (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;

-- La cola de moderación ordena por fecha dentro de un estado, y el índice
-- que había arranca por post_id, así que no le servía.
--
-- body_text_hash sirve para una sola señal, que es de las más confiables:
-- el mismo cuerpo enviado dos veces. Se guarda el hash y no el texto porque
-- lo único que hace falta es comparar.
--
-- spam_reasons queda a la vista en el panel: una regla que no se puede
-- explicar es una regla que después nadie se anima a ajustar.
ALTER TABLE comments
    ADD body_text_hash CHAR(64)     NOT NULL DEFAULT '' AFTER body_html,
    ADD spam_reasons   VARCHAR(400) NOT NULL DEFAULT '' AFTER spam_score,
    ADD KEY ix_comments_moderation (status, created_at),
    ADD KEY ix_comments_dupe (body_text_hash);

INSERT INTO settings (`key`, `value`) VALUES
    ('comments_notify',        '1'),
    ('comments_notify_to',     ''),
    ('comments_max_per_hour',  '5'),
    ('comments_blocklist',     ''),
    ('comments_autoapprove_known', '0')
ON DUPLICATE KEY UPDATE `key` = `key`;
