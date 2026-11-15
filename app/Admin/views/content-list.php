<?php
/** @var array $items @var string $type @var ?string $status @var int $page @var int $pages @var int $total */
$seg = $type === 'page' ? 'paginas' : 'entradas';
$label = $type === 'page' ? 'páginas' : 'entradas';
$verPrefijo = $type === 'page' ? '/p/' : '/';
?>
<div class="head">
  <div>
    <h1><?= e(ucfirst($label)) ?></h1>
    <p class="sub"><?= (int) $total ?> en total</p>
  </div>
  <a class="btn" href="<?= e($adminPath) ?>/<?= $seg ?>/nueva">Nueva</a>
</div>

<nav class="filters">
  <a href="<?= e($adminPath) ?>/<?= $seg ?>" class="<?= $status === null ? 'on' : '' ?>">Todas</a>
  <a href="<?= e($adminPath) ?>/<?= $seg ?>?estado=published" class="<?= $status === 'published' ? 'on' : '' ?>">Publicadas</a>
  <a href="<?= e($adminPath) ?>/<?= $seg ?>?estado=draft" class="<?= $status === 'draft' ? 'on' : '' ?>">Borradores</a>
  <a href="<?= e($adminPath) ?>/<?= $seg ?>?estado=trash" class="<?= $status === 'trash' ? 'on' : '' ?>">Papelera</a>
</nav>

<section class="panel">
  <table class="tbl tbl-list">
    <thead>
      <tr>
        <th>Título</th>
        <th class="nowrap">Estado</th>
        <th class="nowrap">Fecha</th>
        <th class="nowrap">Acciones</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $p): ?>
      <tr>
        <td>
          <div class="rowtitle">
            <?php /* La miniatura es la variante de 400 px que ya genera
                     ImageProcessor al subir; no se crea nada nuevo. */ ?>
            <a class="thumb<?= empty($p['image']) ? ' thumb-vacia' : '' ?>"
               href="<?= e($adminPath) ?>/<?= $seg ?>/<?= (int) $p['id'] ?>"
               tabindex="-1" aria-hidden="true">
              <?php if (!empty($p['image'])): ?>
                <img src="/uploads/<?= e(pathinfo((string) $p['image'], PATHINFO_FILENAME)) ?>-400.jpg"
                     alt="" width="64" height="48" loading="lazy" decoding="async">
              <?php endif; ?>
            </a>
            <div class="rowtext">
              <a class="strong" href="<?= e($adminPath) ?>/<?= $seg ?>/<?= (int) $p['id'] ?>">
                <?= e($p['title']) ?>
              </a>
              <span class="slug">/<?= e($p['slug']) ?></span>
            </div>
          </div>
        </td>
        <td class="nowrap">
          <?php [$estado, $etiqueta] = post_state($p); ?>
          <span class="pill pill-<?= e($estado) ?>"><?= e($etiqueta) ?></span>
        </td>
        <td class="nowrap dim">
          <?= e(fecha_ar((string) ($p['published_at'] ?? $p['updated_at']), true)) ?>
        </td>
        <td class="nowrap actions">
          <?php if ($p['status'] === 'published'): ?>
            <a href="<?= e($verPrefijo . $p['slug']) ?>" target="_blank" rel="noopener">Ver</a>
          <?php endif; ?>

          <?php if ($p['status'] === 'trash'): ?>
            <form method="post" action="<?= e($adminPath) ?>/<?= $seg ?>/restaurar">
              <?= $csrf ?>
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="linkbtn" type="submit">Restaurar</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= e($adminPath) ?>/<?= $seg ?>/papelera"
                  data-confirm="¿Mandar «<?= e($p['title']) ?>» a la papelera?">
              <?= $csrf ?>
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="linkbtn linkbtn-danger" type="submit">Papelera</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>

    <?php if ($items === []): ?>
      <tr><td colspan="4" class="dim pad">No hay nada en esta vista.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page - 1 ?><?= $status ? '&estado=' . e($status) : '' ?>">← Anteriores</a>
    <?php endif; ?>
    <span><?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?>
      <a href="?page=<?= $page + 1 ?><?= $status ? '&estado=' . e($status) : '' ?>">Siguientes →</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
