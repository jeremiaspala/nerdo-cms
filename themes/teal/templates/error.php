<?php
/**
 * @var int         $status
 * @var string      $title
 * @var string      $message
 * @var string|null $incidentId
 *
 * Nunca se muestra detalle técnico: solo el identificador, que sirve para
 * cruzarlo con storage/logs/ sin filtrarle nada a quien esté probando.
 */
?>
<div class="errorpage">
  <p class="eyebrow">Error <?= (int) $status ?></p>
  <h1><?= e($title) ?></h1>

  <?php if ($message !== ''): ?>
    <p class="errorpage-msg"><?= e($message) ?></p>
  <?php endif; ?>

  <?php if ($incidentId !== null): ?>
    <p class="errorpage-id">Referencia: <code><?= e($incidentId) ?></code></p>
  <?php endif; ?>

  <p class="errorpage-actions">
    <a class="btn" href="/">Volver a la portada</a>
  </p>
</div>
