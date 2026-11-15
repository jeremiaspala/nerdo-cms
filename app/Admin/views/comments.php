<?php
/**
 * Cola de moderación.
 *
 * @var array  $items
 * @var string $status
 * @var array  $byStatus
 * @var int    $page @var int $pages @var int $total
 *
 * body_html se imprime sin e() porque entró saneado con el perfil `comment`,
 * igual que en el frente. Es el mismo HTML que vería un lector: moderar sobre
 * una versión distinta de la que se publica sería moderar otra cosa.
 */
$labels = [
    'pending' => 'Pendientes',
    'approved' => 'Aprobados',
    'spam' => 'Spam',
    'trash' => 'Papelera',
];
?>
<div class="head">
  <div>
    <h1>Comentarios</h1>
    <p class="sub"><?= (int) $total ?> en <?= e(mb_strtolower($labels[$status])) ?></p>
  </div>
  <?php if (in_array($status, ['spam', 'trash'], true) && $items !== []): ?>
    <form method="post" action="<?= e($adminPath) ?>/comentarios/vaciar"
          data-confirm="¿Borrar definitivamente todo lo que hay en <?= e(mb_strtolower($labels[$status])) ?>?">
      <?= $csrf ?>
      <input type="hidden" name="estado" value="<?= e($status) ?>">
      <button class="linkbtn linkbtn-danger" type="submit">Vaciar</button>
    </form>
  <?php endif; ?>
</div>

<nav class="filters">
  <?php foreach ($labels as $key => $label): ?>
    <a href="<?= e($adminPath) ?>/comentarios?estado=<?= e($key) ?>"
       class="<?= $status === $key ? 'on' : '' ?>">
      <?= e($label) ?> <span class="dim"><?= (int) ($byStatus[$key] ?? 0) ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($items === []): ?>
  <section class="panel">
    <p class="dim pad">Nada en esta vista.</p>
  </section>
<?php else: ?>

<form method="post" action="<?= e($adminPath) ?>/comentarios/moderar" id="modform">
  <?= $csrf ?>
  <input type="hidden" name="volver_a" value="<?= e($status) ?>">

  <div class="bulkbar">
    <label class="check">
      <input type="checkbox" id="check-all">
      <span>Marcar todos</span>
    </label>

    <span class="bulk-actions">
      <?php if ($status !== 'approved'): ?>
        <button class="linkbtn" type="submit" name="accion" value="approved">Aprobar</button>
      <?php endif; ?>
      <?php if ($status !== 'pending'): ?>
        <button class="linkbtn" type="submit" name="accion" value="pending">A moderación</button>
      <?php endif; ?>
      <?php if ($status !== 'spam'): ?>
        <button class="linkbtn" type="submit" name="accion" value="spam">Spam</button>
      <?php endif; ?>
      <?php if ($status !== 'trash'): ?>
        <button class="linkbtn" type="submit" name="accion" value="trash">Papelera</button>
      <?php endif; ?>
      <?php if (in_array($status, ['spam', 'trash'], true)): ?>
        <button class="linkbtn linkbtn-danger" type="submit" name="accion" value="purgar">Borrar</button>
      <?php endif; ?>
    </span>
  </div>

  <ul class="modlist">
    <?php foreach ($items as $c): ?>
      <?php $verPrefijo = $c['post_type'] === 'page' ? '/p/' : '/'; ?>
      <li class="moditem">
        <label class="check modcheck">
          <input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>">
          <span class="sr-only">Marcar el comentario de <?= e($c['author_name']) ?></span>
        </label>

        <div class="modbody">
          <p class="modmeta">
            <b><?= e($c['author_name']) ?></b>
            <?php if (($c['author_url'] ?? '') !== '' && safe_url($c['author_url']) !== ''): ?>
              <a class="dim" href="<?= safe_url($c['author_url']) ?>"
                 target="_blank" rel="noopener nofollow ugc"><?= e(parse_url((string) $c['author_url'], PHP_URL_HOST) ?: 'sitio') ?></a>
            <?php endif; ?>
            <span class="dim"><?= e(substr((string) $c['created_at'], 0, 16)) ?></span>
            <span class="pill pill-<?= e($c['status']) ?>"><?= e($c['status']) ?></span>
            <?php if ((int) $c['spam_score'] > 0): ?>
              <span class="pill pill-score" title="<?= e($c['spam_reasons']) ?>">puntaje <?= (int) $c['spam_score'] ?></span>
            <?php endif; ?>
          </p>

          <p class="modwhere dim">
            en <a href="<?= e($verPrefijo . $c['post_slug']) ?>#comentario-<?= (int) $c['id'] ?>"
                  target="_blank" rel="noopener"><?= e($c['post_title']) ?></a>
            <?php if (($c['parent_author'] ?? null) !== null): ?>
              · respuesta a <?= e($c['parent_author']) ?>
            <?php endif; ?>
          </p>

          <div class="modtext prose-mini"><?= $c['body_html'] ?></div>

          <?php if (($c['spam_reasons'] ?? '') !== ''): ?>
            <p class="modreasons dim">Señales: <?= e($c['spam_reasons']) ?></p>
          <?php endif; ?>

          <?php /* Los botones de la fila mandan el mismo formulario: admin.js
                   marca solo esta casilla antes de enviarlo. Sin JavaScript
                   no pasa nada raro —se envía con lo que esté marcado, que en
                   general es nada— y las acciones en lote siguen andando. */ ?>
          <p class="modactions">
            <?php if ($c['status'] !== 'approved'): ?>
              <button class="linkbtn" type="submit" name="accion" value="approved"
                      data-one="<?= (int) $c['id'] ?>">Aprobar</button>
            <?php endif; ?>
            <?php if ($c['status'] !== 'spam'): ?>
              <button class="linkbtn" type="submit" name="accion" value="spam"
                      data-one="<?= (int) $c['id'] ?>">Spam</button>
            <?php endif; ?>
            <?php if ($c['status'] === 'trash' || $c['status'] === 'spam'): ?>
              <button class="linkbtn" type="submit" name="accion" value="pending"
                      data-one="<?= (int) $c['id'] ?>">A moderación</button>
              <button class="linkbtn linkbtn-danger" type="submit" name="accion" value="purgar"
                      data-one="<?= (int) $c['id'] ?>"
                      data-confirm-one="¿Borrar este comentario para siempre?">Borrar</button>
            <?php else: ?>
              <button class="linkbtn linkbtn-danger" type="submit" name="accion" value="trash"
                      data-one="<?= (int) $c['id'] ?>">Papelera</button>
            <?php endif; ?>
          </p>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</form>

<?php endif; ?>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?>
      <a href="?estado=<?= e($status) ?>&page=<?= $page - 1 ?>">← Anteriores</a>
    <?php endif; ?>
    <span><?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?>
      <a href="?estado=<?= e($status) ?>&page=<?= $page + 1 ?>">Siguientes →</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
