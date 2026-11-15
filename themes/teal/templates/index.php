<?php
/**
 * Listado: portada, categoría, archivo y resultados de búsqueda.
 *
 * @var array      $posts
 * @var int        $page
 * @var int        $pages
 * @var int        $total
 * @var array|null $heading
 */
$first = $posts[0] ?? null;
$rest = array_slice($posts, 1);
?>

<?php if ($heading !== null): ?>
  <div class="pagehead">
    <p class="eyebrow"><?= e($heading['eyebrow']) ?></p>
    <h1><?= e($heading['title']) ?></h1>
    <?php if ($heading['description'] !== ''): ?>
      <p class="pagehead-desc"><?= e($heading['description']) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($posts === []): ?>
  <p class="empty">Todavía no hay entradas acá.</p>
<?php else: ?>

  <?php if ($heading === null && $page === 1 && $first !== null): ?>
    <article class="hero<?= empty($first['image']) ? '' : ' hero-img' ?>">
      <?php if (!empty($first['image'])): ?>
        <a class="hero-media" href="/<?= e($first['slug']) ?>">
          <?= featured_img($first, 'hero-pic', '(max-width: 760px) 100vw, 55vw', true) ?>
        </a>
      <?php endif; ?>
      <div class="hero-in">
        <?php if (!empty($first['categories'])): ?>
          <p class="chips">
            <?php foreach (array_slice($first['categories'], 0, 3) as $c): ?>
              <a class="chip" href="/categoria/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
        <h2><a href="/<?= e($first['slug']) ?>"><?= e($first['title']) ?></a></h2>
        <p class="hero-excerpt"><?= e($first['excerpt']) ?></p>
        <p class="meta">
          <time datetime="<?= e(substr((string) $first['published_at'], 0, 10)) ?>">
            <?= e(fecha((string) $first['published_at'])) ?>
          </time>
          <span><?= (int) $first['reading_minutes'] ?> min de lectura</span>
        </p>
      </div>
    </article>
    <?php $cards = $rest; ?>
  <?php else: ?>
    <?php $cards = $posts; ?>
  <?php endif; ?>

  <div class="grid">
    <?php foreach ($cards as $post): ?>
      <article class="card">
        <?php if (!empty($post['image'])): ?>
          <a class="card-media" href="/<?= e($post['slug']) ?>">
            <?= featured_img($post, 'card-pic', '(max-width: 640px) 100vw, 300px') ?>
          </a>
        <?php endif; ?>
        <?php if (!empty($post['categories'])): ?>
          <p class="chips">
            <?php foreach (array_slice($post['categories'], 0, 2) as $c): ?>
              <a class="chip" href="/categoria/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
        <h3><a href="/<?= e($post['slug']) ?>"><?= e($post['title']) ?></a></h3>
        <p class="card-excerpt"><?= e(mb_substr((string) $post['excerpt'], 0, 160)) ?></p>
        <p class="meta">
          <time datetime="<?= e(substr((string) $post['published_at'], 0, 10)) ?>">
            <?= e(fecha((string) $post['published_at'])) ?>
          </time>
          <span><?= (int) $post['reading_minutes'] ?> min</span>
        </p>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Paginación">
      <?php if ($page > 1): ?>
        <a class="pager-link" rel="prev" href="?page=<?= $page - 1 ?>">← Anteriores</a>
      <?php endif; ?>
      <span class="pager-pos">Página <?= (int) $page ?> de <?= (int) $pages ?></span>
      <?php if ($page < $pages): ?>
        <a class="pager-link" rel="next" href="?page=<?= $page + 1 ?>">Siguientes →</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

<?php endif; ?>
