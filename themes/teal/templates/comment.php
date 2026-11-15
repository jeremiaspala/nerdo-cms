<?php
/**
 * Un comentario.
 *
 * @var array $c  fila con 'depth' ya resuelta por Comment::threadFor()
 *
 * body_html sale sin e() y puede hacerlo por lo mismo que el cuerpo de una
 * entrada: entró como texto plano, se escapó, se convirtió a HTML y pasó por
 * el perfil `comment` del saneador, que es mucho más cerrado que el de las
 * entradas — ni imágenes, ni tablas, ni clases.
 *
 * Esta plantilla se renderiza también sola, desde CommentController, para
 * devolver el comentario recién publicado por JSON. El JavaScript lo inserta
 * tal cual: nunca arma HTML a partir de lo que escribió el visitante.
 */
$depth = max(0, min(2, (int) ($c['depth'] ?? 0)));
?>
<li class="cmt cmt-d<?= $depth ?>" id="comentario-<?= (int) $c['id'] ?>">
  <article>
    <header class="cmt-head">
      <span class="av <?= e(avatar_class((string) ($c['email_hash'] ?? ''))) ?>" aria-hidden="true"><?= e(initials((string) $c['author_name'])) ?></span>

      <span class="cmt-who">
        <?php $link = safe_url((string) ($c['author_url'] ?? '')); ?>
        <?php if ($link !== ''): ?>
          <?php /* ugc + nofollow: el enlace del comentarista no pasa autoridad.
                   Es la mitad de la razón por la que existe el spam de enlaces. */ ?>
          <a class="cmt-name" href="<?= $link ?>" rel="nofollow ugc noopener" target="_blank"><?= e($c['author_name']) ?></a>
        <?php else: ?>
          <b class="cmt-name"><?= e($c['author_name']) ?></b>
        <?php endif; ?>

        <?php if (($c['author_source'] ?? 'anonimo') === 'google'): ?>
          <?php /* Dice que el correo lo verificó Google, no que la persona
                   sea de fiar. Es un dato, no un premio. */ ?>
          <span class="cmt-verificado" title="Se identificó con una cuenta de Google">cuenta verificada</span>
        <?php endif; ?>

        <a class="cmt-when" href="#comentario-<?= (int) $c['id'] ?>">
          <?php /* El atributo lleva la T de ISO 8601; el texto va en castellano. */ ?>
          <time datetime="<?= e(str_replace(' ', 'T', substr((string) $c['created_at'], 0, 16))) ?>">
            <?= e(fecha_hora((string) $c['created_at'])) ?>
          </time>
        </a>
      </span>

      <?php /* Sin formulario no hay a dónde mandar la respuesta: el botón
               aparece solo si la entrada sigue aceptando comentarios. */ ?>
      <?php if (!empty($commentsOpen)): ?>
        <button class="cmt-reply" type="button"
                data-reply="<?= (int) $c['id'] ?>"
                data-author="<?= e($c['author_name']) ?>">Responder</button>
      <?php endif; ?>
    </header>

    <div class="cmt-body prose-mini"><?= $c['body_html'] ?></div>
  </article>
</li>
