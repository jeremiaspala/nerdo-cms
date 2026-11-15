-- Recuperación de contraseña.
--
-- Del token se guarda el SHA-256, nunca el token: quien consiga un volcado
-- de la base no puede usar los enlaces que estén pendientes. Es el mismo
-- criterio que con las contraseñas, y por el mismo motivo —un enlace de
-- recuperación válido es, por un rato, equivalente a la contraseña—.
--
-- No hace falta pepper ni argon2 acá: el token son 32 bytes aleatorios, no
-- algo que alguien eligió, así que no hay diccionario que probar contra el
-- hash. Lo que protege es la entropía, no el costo de calcularlo.
CREATE TABLE password_resets (
    token_hash CHAR(64)     NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    ip_hash    CHAR(32)     NOT NULL DEFAULT '',
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME     NULL,
    PRIMARY KEY (token_hash),
    KEY ix_resets_user (user_id, used_at),
    KEY ix_resets_expira (expires_at),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin;
