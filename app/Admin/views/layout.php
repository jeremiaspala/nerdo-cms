<?php
/**
 * Armazón del panel: barra lateral fija a la izquierda y el contenido al lado.
 *
 * Sin un solo script ni estilo en línea: la CSP no los admite y el panel no
 * es excepción. Todo el comportamiento vive en /assets/js/admin.js, y los
 * íconos son SVG en línea (ver app/Admin/ui.php): ni una tipografía de
 * íconos ni un dominio de terceros.
 */
/** @var array $user @var string $adminPath @var array $counts @var string $current */

$nav = [
    ['dashboard', '', 'escritorio', 'Escritorio', null],
    ['post', '/entradas', 'entradas', 'Entradas', (int) $counts['posts']],
    ['page', '/paginas', 'paginas', 'Páginas', (int) $counts['pages']],
    ['media', '/medios', 'medios', 'Medios', null],
    ['comments', '/comentarios', 'comentarios', 'Comentarios', (int) ($counts['pending'] ?? 0)],
    ['messages', '/mensajes', 'mensajes', 'Mensajes', (int) ($counts['messages'] ?? 0)],
    ['analytics', '/analitica', 'analitica', 'Analítica', null],
    ['security', '/seguridad', 'seguridad', 'Seguridad', null],
    ['audit', '/bitacora', 'bitacora', 'Bitácora', null],
    ['settings', '/configuracion', 'configuracion', 'Configuración', null],
];

// Los dos primeros contadores son informativos; los otros dos avisan de algo
// que hay que atender, y por eso se pintan distinto y desaparecen en cero.
$alerts = ['comments', 'messages'];
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
  <link rel="stylesheet" href="<?= asset('/assets/vendor/editorjs/editorjs.css') ?>">
  <?php /* Editor.js inyecta dos hojas de estilo creando <style> desde
           JavaScript, y la CSP sin unsafe-inline las bloquea. Su propio
           loadStyles() arranca con «si ya existe este id, no hago nada», así
           que estos dos <style> vacíos —con nonce, que la CSP sí admite— le
           avisan que no haga falta. El CSS está en editorjs.css. */ ?>
  <style id="editor-js-styles" nonce="<?= e($nonce) ?>"></style>
  <style id="codex-tooltips-style" nonce="<?= e($nonce) ?>"></style>
<?php endif; ?>
</head>
<body>

<div class="adminshell">

  <aside class="sidebar">
    <a class="logo" href="<?= e($adminPath) ?>">Nerdo <span>CMS</span></a>

    <nav class="sidenav" aria-label="Secciones del panel">
      <?php foreach ($nav as [$key, $path, $icon, $label, $count]): ?>
        <?php $isAlert = in_array($key, $alerts, true); ?>
        <a href="<?= e($adminPath . $path) ?>" class="<?= $current === $key ? 'on' : '' ?>">
          <?= admin_icon($icon) ?>
          <span class="sidelabel"><?= e($label) ?></span>
          <?php if ($count !== null && ($count > 0 || !$isAlert)): ?>
            <b class="<?= $isAlert ? 'badge-alert' : 'badge' ?>"><?= $count ?></b>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidefoot">
      <a class="sidelink" href="/" target="_blank" rel="noopener">
        <?= admin_icon('sitio') ?><span class="sidelabel">Ver el sitio</span>
      </a>
      <form method="post" action="<?= e($adminPath) ?>/salir">
        <?= $csrf ?>
        <button class="sidelink" type="submit">
          <?= admin_icon('salir') ?><span class="sidelabel">Salir (<?= e($user['name']) ?>)</span>
        </button>
      </form>
    </div>
  </aside>

  <div class="adminmain">
    <?php if ($flash !== null): ?>
      <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
    <?php endif; ?>

    <main class="shell">
    <?= $content ?>
    </main>
  </div>

</div>

<script src="<?= asset('/assets/js/admin.js') ?>" defer></script>
<?php if (!empty($needsEditor)): ?>
  <?php /* Núcleo primero y después las herramientas; los defer se ejecutan
           en orden. Nada de esto lo carga el sitio público. */ ?>
  <?php foreach ([
      'editorjs', 'header', 'list', 'quote', 'code', 'image', 'embed', 'delimiter', 'table',
      'inline-code',
  ] as $tool): ?>
    <script src="<?= asset('/assets/vendor/editorjs/' . $tool . '.umd.js') ?>" defer></script>
  <?php endforeach; ?>
  <script src="<?= asset('/assets/js/editor.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
