<?php
/** @var string $error @var string $csrf @var string $adminPath */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Código de verificación · <?= e($siteTitle) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/admin.css') ?>">
</head>
<body class="loginbody">

<main class="loginbox">
  <h1>Nerdo <span>CMS</span></h1>

  <?php if ($error !== ''): ?>
    <p class="loginerror" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <p class="loginintro">
    La contraseña está bien. Ahora el código de seis dígitos de tu
    aplicación de autenticación.
  </p>

  <form method="post" action="<?= e($adminPath) ?>/entrar/codigo" autocomplete="off">
    <?= $csrf ?>

    <label for="codigo">Código</label>
    <input class="in-code" type="text" id="codigo" name="codigo" required autofocus
           inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
           autocomplete="one-time-code" placeholder="000000">

    <button type="submit">Entrar</button>
  </form>

  <p class="loginfoot">
    Si perdiste el teléfono, el acceso se recupera por consola:
    <code>php bin/console user:totp-off --email=…</code>
  </p>
</main>

</body>
</html>
