-- De dónde salió la identidad de quien comentó.
--
-- `anonimo` es lo de siempre: escribió un nombre y un correo que nadie
-- comprobó. `google` quiere decir que Google verificó ese correo y que el
-- nombre es el de su cuenta.
--
-- Se guarda para dos cosas: mostrar la diferencia en el frente —un
-- comentario firmado con una cuenta verificada se lee distinto— y para que
-- el puntaje de spam pueda ser más indulgente con quien se tomó el trabajo
-- de identificarse.
ALTER TABLE comments
    ADD author_source ENUM('anonimo','google') NOT NULL DEFAULT 'anonimo' AFTER author_url;

INSERT INTO settings (`key`, `value`) VALUES
    ('comments_identidad',        'ambos'),
    ('comments_google_autoaprueba', '1'),
    ('google_enabled',            '0'),
    ('google_panel',              '1'),
    ('google_comentarios',        '1')
ON DUPLICATE KEY UPDATE `key` = `key`;
