<?php
/**
 * Armazón del panel.
 *
 * Sin un solo script ni estilo en línea: la CSP no los admite y el panel no
 * es excepción. Todo el comportamiento vive en /assets/js/admin.js
 */
/** @var array $user @var string $adminPath @var array $counts @var string $current */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle ?? 'Panel') ?> · Nerdo CMS</title>
<link rel="stylesheet" href="<?= asset('/assets/css/admin.css') ?>">
<?php if (!empty($needsEditor)): ?>
  <link rel="stylesheet" href="<?= asset('/assets/vendor/quill/quill.snow.css') ?>">
<?php endif; ?>
</head>
<body>

<header class="topbar">
  <a class="logo" href="<?= e($adminPath) ?>">Nerdo <span>CMS</span></a>
  <nav class="topnav">
    <a href="<?= e($adminPath) ?>" class="<?= $current === 'dashboard' ? 'on' : '' ?>">Escritorio</a>
    <a href="<?= e($adminPath) ?>/entradas" class="<?= $current === 'post' ? 'on' : '' ?>">
      Entradas <b><?= (int) $counts['posts'] ?></b>
    </a>
    <a href="<?= e($adminPath) ?>/paginas" class="<?= $current === 'page' ? 'on' : '' ?>">
      Páginas <b><?= (int) $counts['pages'] ?></b>
    </a>
    <a href="<?= e($adminPath) ?>/medios" class="<?= $current === 'media' ? 'on' : '' ?>">Medios</a>
    <a href="<?= e($adminPath) ?>/comentarios" class="<?= $current === 'comments' ? 'on' : '' ?>">
      Comentarios
      <?php if ((int) ($counts['pending'] ?? 0) > 0): ?>
        <b class="badge-alert"><?= (int) $counts['pending'] ?></b>
      <?php endif; ?>
    </a>
    <a href="<?= e($adminPath) ?>/mensajes" class="<?= $current === 'messages' ? 'on' : '' ?>">
      Mensajes
      <?php if ((int) ($counts['messages'] ?? 0) > 0): ?>
        <b class="badge-alert"><?= (int) $counts['messages'] ?></b>
      <?php endif; ?>
    </a>
    <a href="<?= e($adminPath) ?>/analitica"
       class="<?= $current === 'analytics' ? 'on' : '' ?>">Analítica</a>
    <a href="<?= e($adminPath) ?>/configuracion"
       class="<?= $current === 'settings' ? 'on' : '' ?>">Configuración</a>
  </nav>
  <div class="topright">
    <a class="viewsite" href="/" target="_blank" rel="noopener">Ver el sitio</a>
    <span class="who"><?= e($user['name']) ?></span>
    <form method="post" action="<?= e($adminPath) ?>/salir">
      <?= $csrf ?>
      <button class="linkbtn" type="submit">Salir</button>
    </form>
  </div>
</header>

<?php if ($flash !== null): ?>
  <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>

<main class="shell">
<?= $content ?>
</main>

<script src="<?= asset('/assets/js/admin.js') ?>" defer></script>
<?php if (!empty($needsEditor)): ?>
  <script src="<?= asset('/assets/vendor/quill/quill.js') ?>" defer></script>
  <script src="<?= asset('/assets/js/editor.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
