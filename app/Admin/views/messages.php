<?php
/**
 * Bandeja de mensajes de contacto.
 *
 * @var array   $items
 * @var ?string $status
 * @var array   $byStatus
 * @var int     $page @var int $pages @var int $total
 *
 * El cuerpo sale con e() y nl2br(): es texto plano tal como se escribió, y no
 * pasa por ningún saneador porque nunca se admitió una etiqueta. Un mensaje
 * de contacto es el lugar más probable del sitio donde alguien va a pegar
 * algo con la intención de que se ejecute.
 */
$labels = [
    'new' => 'Nuevos',
    'read' => 'Leídos',
    'archived' => 'Archivados',
    'spam' => 'Spam',
];
?>
<div class="head">
  <div>
    <h1>Mensajes</h1>
    <p class="sub">
      <?= (int) $total ?> en <?= e($status === null ? 'la bandeja' : mb_strtolower($labels[$status])) ?>
    </p>
  </div>
  <?php if ($status === 'spam' && $items !== []): ?>
    <form method="post" action="<?= e($adminPath) ?>/mensajes/vaciar-spam"
          data-confirm="¿Borrar definitivamente todo el spam?">
      <?= $csrf ?>
      <button class="linkbtn linkbtn-danger" type="submit">Vaciar spam</button>
    </form>
  <?php endif; ?>
</div>

<nav class="filters">
  <a href="<?= e($adminPath) ?>/mensajes" class="<?= $status === null ? 'on' : '' ?>">Todos</a>
  <?php foreach ($labels as $key => $label): ?>
    <a href="<?= e($adminPath) ?>/mensajes?estado=<?= e($key) ?>"
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

<form method="post" action="<?= e($adminPath) ?>/mensajes/accion" id="modform">
  <?= $csrf ?>
  <input type="hidden" name="volver_a" value="<?= e($status ?? '') ?>">

  <div class="bulkbar">
    <label class="check">
      <input type="checkbox" id="check-all">
      <span>Marcar todos</span>
    </label>

    <span class="bulk-actions">
      <button class="linkbtn" type="submit" name="accion" value="read">Leído</button>
      <button class="linkbtn" type="submit" name="accion" value="archived">Archivar</button>
      <button class="linkbtn" type="submit" name="accion" value="spam">Spam</button>
      <button class="linkbtn linkbtn-danger" type="submit" name="accion" value="borrar">Borrar</button>
    </span>
  </div>

  <ul class="modlist">
    <?php foreach ($items as $m): ?>
      <li class="moditem<?= $m['status'] === 'new' ? ' moditem-new' : '' ?>">
        <label class="check modcheck">
          <input type="checkbox" name="ids[]" value="<?= (int) $m['id'] ?>">
          <span class="sr-only">Marcar el mensaje de <?= e($m['name']) ?></span>
        </label>

        <div class="modbody">
          <p class="modmeta">
            <b><?= e($m['name']) ?></b>
            <?php /* El mailto es la razón de ser de esta pantalla: contestar
                     se contesta desde el cliente de correo. */ ?>
            <a href="mailto:<?= e($m['email']) ?>?subject=<?= rawurlencode('Re: ' . ($m['subject'] !== '' ? $m['subject'] : 'tu mensaje')) ?>"><?= e($m['email']) ?></a>
            <span class="dim"><?= e(substr((string) $m['created_at'], 0, 16)) ?></span>
            <span class="pill pill-<?= e($m['status']) ?>"><?= e($labels[$m['status']] ?? $m['status']) ?></span>
            <?php if ((int) $m['mailed'] === 0 && $m['status'] !== 'spam'): ?>
              <span class="pill pill-spam" title="Quedó guardado igual">no se pudo avisar por correo</span>
            <?php endif; ?>
            <?php if ((int) $m['spam_score'] > 0): ?>
              <span class="pill pill-score" title="<?= e($m['spam_reasons']) ?>">puntaje <?= (int) $m['spam_score'] ?></span>
            <?php endif; ?>
          </p>

          <?php if ($m['subject'] !== ''): ?>
            <p class="modsubject"><?= e($m['subject']) ?></p>
          <?php endif; ?>

          <div class="modtext"><?= nl2br(e($m['body']), false) ?></div>

          <?php if (($m['spam_reasons'] ?? '') !== ''): ?>
            <p class="modreasons dim">Señales: <?= e($m['spam_reasons']) ?></p>
          <?php endif; ?>

          <p class="modactions">
            <?php if ($m['status'] !== 'read'): ?>
              <button class="linkbtn" type="submit" name="accion" value="read"
                      data-one="<?= (int) $m['id'] ?>">Leído</button>
            <?php endif; ?>
            <?php if ($m['status'] !== 'archived'): ?>
              <button class="linkbtn" type="submit" name="accion" value="archived"
                      data-one="<?= (int) $m['id'] ?>">Archivar</button>
            <?php endif; ?>
            <?php if ($m['status'] !== 'spam'): ?>
              <button class="linkbtn" type="submit" name="accion" value="spam"
                      data-one="<?= (int) $m['id'] ?>">Spam</button>
            <?php else: ?>
              <button class="linkbtn" type="submit" name="accion" value="new"
                      data-one="<?= (int) $m['id'] ?>">No es spam</button>
            <?php endif; ?>
            <button class="linkbtn linkbtn-danger" type="submit" name="accion" value="borrar"
                    data-one="<?= (int) $m['id'] ?>"
                    data-confirm-one="¿Borrar este mensaje para siempre?">Borrar</button>
          </p>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</form>

<?php endif; ?>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php $q = $status === null ? '' : 'estado=' . e($status) . '&'; ?>
    <?php if ($page > 1): ?>
      <a href="?<?= $q ?>page=<?= $page - 1 ?>">← Anteriores</a>
    <?php endif; ?>
    <span><?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?>
      <a href="?<?= $q ?>page=<?= $page + 1 ?>">Siguientes →</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
