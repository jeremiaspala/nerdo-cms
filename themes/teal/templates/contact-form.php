<?php
/**
 * Formulario de contacto. Reemplaza al shortcode de Contact Form 7.
 *
 * @var string $contactIntro
 *
 * Las mismas capas que los comentarios y por los mismos motivos: el desafío
 * firmado hace de token CSRF sin necesitar sesión, así que esta página se
 * sigue sirviendo del caché igual para todo el mundo y quien solo viene a
 * leer no recibe una cookie.
 */
?>
<section class="contact" id="contacto">
  <?php if ($contactIntro !== ''): ?>
    <p class="contact-intro"><?= e($contactIntro) ?></p>
  <?php endif; ?>

  <form class="cmt-form contact-form" id="contact-form" method="post" action="/contacto"
        data-challenge="/contacto/desafio">
    <input type="hidden" name="t" id="contact-token" value="">
    <input type="hidden" name="pow" id="contact-pow" value="">

    <?php /* Campo trampa: no se ve, no lo anuncia el lector de pantalla y no
             lo toca ningún humano. */ ?>
    <div class="cmt-trap" aria-hidden="true">
      <label for="contact-sitio">No completar</label>
      <input type="text" name="sitio" id="contact-sitio" tabindex="-1" autocomplete="off">
      <input type="text" name="apodo" tabindex="-1" autocomplete="off">
    </div>

    <div class="cmt-row">
      <p class="field">
        <label for="contact-nombre">Nombre</label>
        <input type="text" name="nombre" id="contact-nombre" maxlength="120" required autocomplete="name">
      </p>
      <p class="field">
        <label for="contact-email">Correo</label>
        <input type="email" name="email" id="contact-email" maxlength="190" required autocomplete="email">
        <span class="hint">Es a donde te contesto. No va a ninguna lista.</span>
      </p>
    </div>

    <p class="field">
      <label for="contact-asunto">Asunto <span class="opt">(opcional)</span></label>
      <input type="text" name="asunto" id="contact-asunto" maxlength="200">
    </p>

    <p class="field">
      <label for="contact-cuerpo">Mensaje</label>
      <textarea name="cuerpo" id="contact-cuerpo" rows="8" maxlength="5000" required></textarea>
    </p>

    <div class="cmt-actions">
      <button class="btn" type="submit" id="contact-send">Enviar</button>
      <span class="cmt-state" id="contact-state" role="status" aria-live="polite"></span>
    </div>

    <p class="cmt-note" id="contact-error" role="alert" hidden></p>

    <noscript>
      <p class="cmt-closed">
        Para enviar el formulario hace falta JavaScript: resuelve una
        verificación en tu navegador en lugar de usar un captcha de Google.
        Si lo tenés apagado, escribime directamente por las redes del pie.
      </p>
    </noscript>
  </form>
</section>
