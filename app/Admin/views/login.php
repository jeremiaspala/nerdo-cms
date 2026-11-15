<?php
/** @var string $error @var string $email @var string $csrf @var string $adminPath */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Entrar · <?= e($siteTitle) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="loginbody">

<main class="loginbox">
  <h1>Nerdo <span>CMS</span></h1>

  <?php if ($error !== ''): ?>
    <p class="loginerror" role="alert"><?= e($error) ?></p>
  <?php endif; ?>

  <form method="post" action="<?= e($adminPath) ?>/entrar" autocomplete="on">
    <?= $csrf ?>

    <label for="email">Correo</label>
    <input type="email" id="email" name="email" required autofocus
           autocomplete="username" value="<?= e($email) ?>" maxlength="190">

    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" required
           autocomplete="current-password" maxlength="200">

    <button type="submit">Entrar</button>
  </form>

  <p class="loginfoot">El acceso queda registrado en la bitácora.</p>
</main>

</body>
</html>
