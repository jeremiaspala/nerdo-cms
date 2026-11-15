<?php
/**
 * @var array      $post
 * @var array      $categories
 * @var array|null $prev
 * @var array|null $next
 *
 * body_html es lo ÚNICO que sale sin e() en todo el tema, y puede hacerlo
 * porque entra saneado por lista blanca al guardarse. Si alguna vez se toca
 * el saneador, este es el lugar que paga el error.
 */
?>
<article class="entry">
  <header class="entry-head">
    <?php if ($categories !== []): ?>
      <p class="chips">
        <?php foreach (array_slice($categories, 0, 3) as $c): ?>
          <a class="chip" href="/categoria/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <h1><?= e($post['title']) ?></h1>

    <p class="meta">
      <time datetime="<?= e(substr((string) $post['published_at'], 0, 10)) ?>">
        <?= e(fecha((string) $post['published_at'])) ?>
      </time>
      <span><?= (int) $post['reading_minutes'] ?> min de lectura</span>
    </p>
  </header>

  <?php if (!empty($post['image'])): ?>
    <figure class="entry-media">
      <?= featured_img($post, 'entry-pic', '(max-width: 820px) 100vw, 760px', true) ?>
      <?php if (($post['image_alt'] ?? '') !== ''): ?>
        <figcaption><?= e($post['image_alt']) ?></figcaption>
      <?php endif; ?>
    </figure>
  <?php endif; ?>

  <div class="prose">
    <?= $post['body_html'] ?>
  </div>

  <footer class="entry-foot">
    <p class="share-label">Compartir</p>
    <p class="share">
      <?php
        $url = rawurlencode($seo['canonical']);
        $txt = rawurlencode((string) $post['title']);
      ?>
      <a rel="noopener nofollow" target="_blank"
         href="https://twitter.com/intent/tweet?url=<?= $url ?>&amp;text=<?= $txt ?>">X</a>
      <a rel="noopener nofollow" target="_blank"
         href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $url ?>">LinkedIn</a>
      <a rel="noopener nofollow" target="_blank"
         href="https://api.whatsapp.com/send?text=<?= $txt ?>%20<?= $url ?>">WhatsApp</a>
      <a rel="noopener nofollow" target="_blank"
         href="https://t.me/share/url?url=<?= $url ?>&amp;text=<?= $txt ?>">Telegram</a>
    </p>
  </footer>
</article>

<?php include __DIR__ . '/comments.php'; ?>

<?php if ($prev !== null || $next !== null): ?>
  <nav class="adjacent" aria-label="Más entradas">
    <?php if ($prev !== null): ?>
      <a class="adj" href="/<?= e($prev['slug']) ?>">
        <span>Anterior</span><b><?= e($prev['title']) ?></b>
      </a>
    <?php endif; ?>
    <?php if ($next !== null): ?>
      <a class="adj adj-next" href="/<?= e($next['slug']) ?>">
        <span>Siguiente</span><b><?= e($next['title']) ?></b>
      </a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
