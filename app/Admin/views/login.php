<?php
/**
 * @var string $error @var string $email @var string $csrf @var string $adminPath
 * @var array  $pow   desafío de prueba de trabajo (token, nonce, bits)
 * @var string $flash
 */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · <?= e($siteTitle) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/admin.css') ?>">
</head>
<body class="loginbody">

<main class="loginbox">
  <h1>Nerdo <span>CMS</span></h1>

  <?php if ($error !== ''): ?>
    <p class="loginerror" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <?php if (($flash ?? '') !== ''): ?>
    <p class="loginok" role="status"><?= e($flash) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= e($adminPath) ?>/entrar" autocomplete="on"
        id="login-form"
        data-nonce="<?= e($pow['nonce']) ?>" data-bits="<?= (int) $pow['bits'] ?>">
    <?= $csrf ?>

    <?php /* El captcha no se ve ni se resuelve a mano: el navegador paga
             medio segundo de CPU antes de que el formulario se pueda enviar.
             Contra una persona no se nota; contra un robot que prueba
             contraseñas a mil por minuto, es el costo lo que lo frena. No
             hay imágenes de semáforos ni un script de Google mirando quién
             entra al panel. */ ?>
    <input type="hidden" name="t" value="<?= e($pow['token']) ?>">
    <input type="hidden" name="pow" id="login-pow" value="">

    <label for="email">Correo</label>
    <input type="email" id="email" name="email" required autofocus
           autocomplete="username" value="<?= e($email) ?>" maxlength="190">

    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" required
           autocomplete="current-password" maxlength="200">

    <button type="submit" id="login-send">Entrar</button>
    <p class="loginstate" id="login-state" role="status" aria-live="polite"></p>
  </form>

  <?php if (!empty($google)): ?>
    <p class="logino">o</p>
    <a class="googlebtn" href="<?= e($adminPath) ?>/entrar/google">
      <?php /* El logo de Google como SVG en línea: su guía de marca pide el
               logo, y bajarlo de un dominio de Google en la pantalla de
               login sería avisarle a Google cada vez que alguien la abre. */ ?>
      <svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true">
        <path fill="#4285F4" d="M45 24c0-1.6-.1-2.7-.4-4H24v7.5h12c-.2 2-1.5 5-4.4 7l6.7 5.2C42.2 36.3 45 30.7 45 24z"/>
        <path fill="#34A853" d="M24 46c5.9 0 10.9-2 14.5-5.3l-6.9-5.4c-1.9 1.3-4.4 2.2-7.6 2.2-5.8 0-10.7-3.8-12.5-9.1l-7.1 5.5C8 41.1 15.4 46 24 46z"/>
        <path fill="#FBBC05" d="M11.5 28.4c-.5-1.4-.8-2.9-.8-4.4s.3-3 .7-4.4l-7.1-5.5C2.9 17 2 20.4 2 24s.9 7 2.4 9.9z"/>
        <path fill="#EA4335" d="M24 10.7c3.2 0 5.4 1.4 6.7 2.5l5.9-5.8C32.9 4.1 29.9 2 24 2 15.4 2 8 6.9 4.4 14.1l7.1 5.5c1.8-5.3 6.7-8.9 12.5-8.9z"/>
      </svg>
      Entrar con Google
    </a>
  <?php endif; ?>

  <p class="loginlinks">
    <a href="<?= e($adminPath) ?>/recuperar">¿Olvidaste la contraseña?</a>
  </p>

  <noscript>
    <p class="loginerror">
      Para entrar hace falta JavaScript: el formulario resuelve una
      verificación en tu navegador en lugar de usar un captcha de terceros.
    </p>
  </noscript>

  <p class="loginfoot">El acceso queda registrado en la bitácora.</p>
</main>

<script src="<?= asset('/assets/js/pow.js') ?>" defer></script>
<script src="<?= asset('/assets/js/login.js') ?>" defer></script>
</body>
</html>
