<?php
/** @var string $error @var string $token @var string $csrf @var string $adminPath @var string $nombre */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Contraseña nueva · <?= e($siteTitle) ?></title>
<link rel="stylesheet" href="<?= asset('/assets/css/admin.css') ?>">
</head>
<body class="loginbody">

<main class="loginbox">
  <h1>Nerdo <span>CMS</span></h1>

  <?php if ($error !== ''): ?>
    <p class="loginerror" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <p class="loginintro">Hola <?= e($nombre) ?>, poné la contraseña nueva.</p>

  <form method="post" action="<?= e($adminPath) ?>/recuperar/<?= e($token) ?>" autocomplete="on">
    <?= $csrf ?>

    <label for="password">Contraseña nueva</label>
    <input type="password" id="password" name="password" required autofocus
           autocomplete="new-password" minlength="12" maxlength="200">

    <label for="password2">Repetila</label>
    <input type="password" id="password2" name="password2" required
           autocomplete="new-password" minlength="12" maxlength="200">

    <button type="submit">Guardar y entrar</button>
  </form>

  <p class="loginfoot">
    Mínimo 12 caracteres. Al guardarla se cierran todas las sesiones abiertas
    de la cuenta, acá y en cualquier otro navegador.
  </p>
</main>

</body>
</html>
