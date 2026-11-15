<?php
/**
 * Bloque de comentarios de una entrada.
 *
 * @var array $post
 * @var array $comments      hilo aplanado, con 'depth'
 * @var bool  $commentsOpen  si se aceptan nuevos
 * @var int   $commentsCount
 * @var string|null $commentsClosedReason
 *
 * El formulario no lleva token de sesión: lo pide por fetch a
 * /comentarios/desafio y recibe uno firmado. Es lo que permite que esta
 * página se sirva desde el caché igual para todo el mundo y que quien solo
 * viene a leer no reciba una cookie.
 */
?>
<section class="comments" id="comentarios">
  <h2 class="comments-title">
    <?php if ($commentsCount === 0): ?>
      Comentarios
    <?php elseif ($commentsCount === 1): ?>
      Un comentario
    <?php else: ?>
      <?= (int) $commentsCount ?> comentarios
    <?php endif; ?>
  </h2>

  <?php if ($comments !== []): ?>
    <ol class="cmt-list" id="cmt-list">
      <?php foreach ($comments as $c): ?>
        <?php include __DIR__ . '/comment.php'; ?>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <?php if (!$commentsOpen): ?>
    <p class="cmt-closed">
      <?= e($commentsClosedReason ?? 'Los comentarios de esta entrada están cerrados.') ?>
    </p>
  <?php else: ?>

    <form class="cmt-form" id="cmt-form" method="post" action="/comentarios"
          data-challenge="/comentarios/desafio?e=<?= (int) $post['id'] ?>">
      <h3 class="cmt-form-title" id="cmt-form-title">Dejá un comentario</h3>

      <p class="cmt-replyto" id="cmt-replyto" hidden>
        <span id="cmt-replyto-text"></span>
        <button type="button" class="cmt-cancel" id="cmt-cancel">Cancelar la respuesta</button>
      </p>

      <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
      <input type="hidden" name="parent_id" id="cmt-parent" value="0">
      <input type="hidden" name="t" id="cmt-token" value="">
      <input type="hidden" name="pow" id="cmt-pow" value="">

      <?php /* Campo trampa: no se ve, no se anuncia a los lectores de pantalla
               y no lo toca ningún humano. Lo completa el robot que rellena
               todos los inputs del formulario. */ ?>
      <div class="cmt-trap" aria-hidden="true">
        <label for="cmt-sitio">No completar</label>
        <input type="text" name="sitio" id="cmt-sitio" tabindex="-1" autocomplete="off">
        <input type="text" name="apodo" tabindex="-1" autocomplete="off">
      </div>

      <div class="cmt-row">
        <p class="field">
          <label for="cmt-nombre">Nombre</label>
          <input type="text" name="nombre" id="cmt-nombre" maxlength="120" required autocomplete="name">
        </p>
        <p class="field">
          <label for="cmt-email">Correo</label>
          <input type="email" name="email" id="cmt-email" maxlength="190" required autocomplete="email">
          <span class="hint">No se publica. Se guarda hasheado, nunca en claro.</span>
        </p>
      </div>

      <p class="field">
        <label for="cmt-url">Tu sitio <span class="opt">(opcional)</span></label>
        <input type="url" name="url" id="cmt-url" maxlength="300" placeholder="https://">
      </p>

      <p class="field">
        <label for="cmt-cuerpo">Comentario</label>
        <textarea name="cuerpo" id="cmt-cuerpo" rows="6" maxlength="5000" required></textarea>
        <span class="hint">Texto plano. Las direcciones no se convierten en enlaces.</span>
      </p>

      <div class="cmt-actions">
        <button class="btn" type="submit" id="cmt-send">Publicar</button>
        <span class="cmt-state" id="cmt-state" role="status" aria-live="polite"></span>
      </div>

      <p class="cmt-note" id="cmt-error" role="alert" hidden></p>

      <noscript>
        <p class="cmt-closed">
          Para comentar hace falta JavaScript: el formulario resuelve una
          verificación en tu navegador en lugar de usar un captcha de Google.
        </p>
      </noscript>
    </form>

  <?php endif; ?>
</section>
