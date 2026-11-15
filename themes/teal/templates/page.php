<?php
/** @var array $page */
?>
<article class="entry entry-page">
  <header class="entry-head">
    <h1><?= e($page['title']) ?></h1>
    <p class="meta">
      Actualizada el
      <time datetime="<?= e(substr((string) $page['updated_at'], 0, 10)) ?>">
        <?= e(fecha((string) $page['updated_at'])) ?>
      </time>
    </p>
  </header>

  <div class="prose">
    <?= $page['body_html'] /* saneado al guardar */ ?>
  </div>

  <?php if (!empty($contactForm)): ?>
    <?php include __DIR__ . '/contact-form.php'; ?>
  <?php endif; ?>
</article>
