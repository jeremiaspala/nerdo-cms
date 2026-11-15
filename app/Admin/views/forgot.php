<?php
/** @var string $error @var string $sent @var string $csrf @var string $adminPath */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Recuperar la contraseña · <?= e($siteTitle) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/admin.css') ?>">
</head>
<body class="loginbody">

<main class="loginbox">
  <h1>Nerdo <span>CMS</span></h1>

  <?php if ($sent !== ''): ?>

    <p class="loginok" role="status"><?= e($sent) ?></p>
    <p class="loginfoot">
      El enlace vale una hora y se usa una sola vez. Si no llega, fijate en
      la carpeta de spam.
    </p>

  <?php else: ?>

    <?php if ($error !== ''): ?>
      <p class="loginerror" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <p class="loginintro">
      Escribí el correo de tu cuenta y te mandamos un enlace para poner una
      contraseña nueva.
    </p>

    <form method="post" action="<?= e($adminPath) ?>/recuperar" id="login-form"
          data-nonce="<?= e($pow['nonce']) ?>" data-bits="<?= (int) $pow['bits'] ?>">
      <?= $csrf ?>
      <input type="hidden" name="t" value="<?= e($pow['token']) ?>">
      <input type="hidden" name="pow" id="login-pow" value="">

      <label for="email">Correo</label>
      <input type="email" id="email" name="email" required autofocus
             autocomplete="username" maxlength="190">

      <button type="submit" id="login-send">Mandame el enlace</button>
      <p class="loginstate" id="login-state" role="status" aria-live="polite"></p>
    </form>

  <?php endif; ?>

  <p class="loginlinks">
    <a href="<?= e($adminPath) ?>/entrar">Volver a entrar</a>
  </p>
</main>

<script src="<?= asset('/assets/js/pow.js') ?>" defer></script>
<script src="<?= asset('/assets/js/login.js') ?>" defer></script>
</body>
</html>
