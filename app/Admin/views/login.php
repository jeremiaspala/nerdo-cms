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
