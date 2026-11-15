<?php
/** @var array $recent @var array $audit @var array $stats @var string $adminPath @var array $counts */
$pageTitle = 'Escritorio';
?>
<div class="head">
  <h1>Escritorio</h1>
  <p class="sub">Hola, <?= e($user['name']) ?>.</p>
</div>

<div class="tiles">
  <a class="tile" href="<?= e($adminPath) ?>/entradas">
    <b><?= (int) $counts['posts'] ?></b><span>entradas</span>
  </a>
  <a class="tile" href="<?= e($adminPath) ?>/entradas?estado=draft">
    <b><?= (int) $counts['drafts'] ?></b><span>borradores</span>
  </a>
  <a class="tile" href="<?= e($adminPath) ?>/paginas">
    <b><?= (int) $counts['pages'] ?></b><span>páginas</span>
  </a>
  <div class="tile">
    <b><?= (int) $stats['categorias'] ?></b><span>categorías</span>
  </div>
  <div class="tile">
    <b><?= (int) $stats['redirecciones'] ?></b><span>redirecciones</span>
  </div>
  <?php /* El número ahora lleva a la cola: un contador que no es un enlace
           obliga a buscar en el menú justo cuando hay algo que hacer. */ ?>
  <a class="tile<?= $stats['comentarios'] > 0 ? ' tile-alert' : '' ?>"
     href="<?= e($adminPath) ?>/comentarios">
    <b><?= (int) $stats['comentarios'] ?></b><span>comentarios por moderar</span>
  </a>
  <a class="tile<?= $stats['mensajes'] > 0 ? ' tile-alert' : '' ?>"
     href="<?= e($adminPath) ?>/mensajes">
    <b><?= (int) $stats['mensajes'] ?></b><span>mensajes sin leer</span>
  </a>
</div>

<div class="cols">
  <section class="panel">
    <div class="panel-head">
      <h2>Últimas entradas</h2>
      <a class="btn btn-sm" href="<?= e($adminPath) ?>/entradas/nueva">Nueva entrada</a>
    </div>
    <table class="tbl">
      <tbody>
      <?php foreach ($recent as $p): ?>
        <tr>
          <td>
            <a href="<?= e($adminPath) ?>/entradas/<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
          </td>
          <td class="nowrap">
            <span class="pill pill-<?= e($p['status']) ?>">
              <?= $p['status'] === 'published' ? 'publicada' : ($p['status'] === 'draft' ? 'borrador' : 'papelera') ?>
            </span>
          </td>
          <td class="nowrap dim"><?= e(substr((string) ($p['published_at'] ?? $p['updated_at']), 0, 10)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($recent === []): ?>
        <tr><td class="dim">Todavía no hay nada. Empezá por una entrada nueva.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Bitácora</h2></div>
    <table class="tbl">
      <tbody>
      <?php foreach ($audit as $a): ?>
        <tr>
          <td><code class="tag"><?= e($a['action']) ?></code></td>
          <td class="dim"><?= e($a['target']) ?></td>
          <td class="nowrap dim"><?= e(substr((string) $a['created_at'], 0, 16)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($audit === []): ?>
        <tr><td class="dim">Sin movimientos registrados.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>
