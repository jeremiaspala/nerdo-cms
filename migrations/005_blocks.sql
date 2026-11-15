-- Editor por bloques.
--
-- `body_blocks` guarda lo que produce el editor: un JSON con la lista de
-- bloques. `body_html` sigue existiendo y es lo que se publica, pero pasa a
-- ser un **derivado**: lo genera el servidor a partir de los bloques cada vez
-- que se guarda.
--
-- Esa división es la ganancia de seguridad del cambio. Hasta ahora el editor
-- mandaba HTML y el saneador tenía que decidir qué sobrevivía de algo que
-- podía venir con cualquier cosa adentro. Ahora el editor manda datos y el
-- servidor **emite** el HTML a partir de una lista cerrada de bloques: lo que
-- no está contemplado no se renderiza, no hay nada que sanear.
--
-- Las entradas viejas siguen teniendo body_blocks NULL hasta que se las
-- convierte (`php bin/console blocks:migrate`). Mientras tanto se publican
-- igual, con el HTML que ya tenían.
ALTER TABLE posts
    ADD body_blocks JSON NULL AFTER body_html;
