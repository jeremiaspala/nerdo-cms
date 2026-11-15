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

    <?php if (!empty($comentarGoogle)): ?>
      <?php /* El enlace manda a Google y vuelve con un vale firmado en el
               fragmento de la URL. Nadie recibe una cookie por esto: ver
               CommentController::googleReturn(). */ ?>
      <p class="cmt-identidad" id="cmt-identidad" hidden>
        Comentás como <b id="cmt-identidad-nombre"></b>
        <button type="button" class="cmt-cancel" id="cmt-salir">No soy yo</button>
      </p>

      <p class="cmt-google" id="cmt-google">
        <a class="googlebtn" href="/comentarios/google?volver=<?= e(rawurlencode('/' . $post['slug'])) ?>">
          <svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true">
            <path fill="#4285F4" d="M45 24c0-1.6-.1-2.7-.4-4H24v7.5h12c-.2 2-1.5 5-4.4 7l6.7 5.2C42.2 36.3 45 30.7 45 24z"/>
            <path fill="#34A853" d="M24 46c5.9 0 10.9-2 14.5-5.3l-6.9-5.4c-1.9 1.3-4.4 2.2-7.6 2.2-5.8 0-10.7-3.8-12.5-9.1l-7.1 5.5C8 41.1 15.4 46 24 46z"/>
            <path fill="#FBBC05" d="M11.5 28.4c-.5-1.4-.8-2.9-.8-4.4s.3-3 .7-4.4l-7.1-5.5C2.9 17 2 20.4 2 24s.9 7 2.4 9.9z"/>
            <path fill="#EA4335" d="M24 10.7c3.2 0 5.4 1.4 6.7 2.5l5.9-5.8C32.9 4.1 29.9 2 24 2 15.4 2 8 6.9 4.4 14.1l7.1 5.5c1.8-5.3 6.7-8.9 12.5-8.9z"/>
          </svg>
          Comentar con Google
        </a>
        <?php if (($identidad ?? 'ambos') === 'ambos'): ?>
          <span class="cmt-ogoogle">o completá tus datos</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <form class="cmt-form" id="cmt-form" method="post" action="/comentarios"
          data-challenge="/comentarios/desafio?e=<?= (int) $post['id'] ?>"
          data-identidad="<?= e($identidad ?? 'ambos') ?>">
      <h3 class="cmt-form-title" id="cmt-form-title">Dejá un comentario</h3>

      <p class="cmt-replyto" id="cmt-replyto" hidden>
        <span id="cmt-replyto-text"></span>
        <button type="button" class="cmt-cancel" id="cmt-cancel">Cancelar la respuesta</button>
      </p>

      <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
      <input type="hidden" name="parent_id" id="cmt-parent" value="0">
      <input type="hidden" name="t" id="cmt-token" value="">
      <input type="hidden" name="pow" id="cmt-pow" value="">
      <input type="hidden" name="ident" id="cmt-ident" value="">

      <?php /* Campo trampa: no se ve, no se anuncia a los lectores de pantalla
               y no lo toca ningún humano. Lo completa el robot que rellena
               todos los inputs del formulario. */ ?>
      <div class="cmt-trap" aria-hidden="true">
        <label for="cmt-sitio">No completar</label>
        <input type="text" name="sitio" id="cmt-sitio" tabindex="-1" autocomplete="off">
        <input type="text" name="apodo" tabindex="-1" autocomplete="off">
      </div>

      <div class="cmt-row" id="cmt-datos">
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

      <p class="field" id="cmt-sitio-campo">
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
