<?php
/**
 * @var array $site
 * @var array $seo
 * @var array $nav
 * @var string $content
 * @var string $nonce
 * @var int $year
 * @var array|null $flash
 *
 * Nada se imprime sin e(). La CSP no admite estilos ni scripts en línea,
 * así que acá no hay ni un style="" ni un onclick="".
 */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($seo['title']) ?></title>
<?php if ($seo['description'] !== ''): ?>
<meta name="description" content="<?= e($seo['description']) ?>">
<?php endif; ?>
<?php if (!empty($seo['noindex'])): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>
<link rel="canonical" href="<?= e($seo['canonical']) ?>">

<meta property="og:type" content="<?= e($seo['type'] ?? 'website') ?>">
<meta property="og:title" content="<?= e($seo['title']) ?>">
<meta property="og:description" content="<?= e($seo['description']) ?>">
<meta property="og:url" content="<?= e($seo['canonical']) ?>">
<meta property="og:site_name" content="<?= e($site['title']) ?>">
<meta property="og:locale" content="es_AR">
<?php if (!empty($seo['image'])): ?>
<meta property="og:image" content="<?= e($seo['image']) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= empty($seo['image']) ? 'summary' : 'summary_large_image' ?>">

<link rel="alternate" type="application/rss+xml" title="<?= e($site['title']) ?>" href="/feed.xml">
<?php /* La tipografía del cuerpo se adelanta: el navegador recién la
         descubriría después de bajar y parsear el CSS, y hasta entonces el
         texto se ve con la del sistema. El crossorigin es obligatorio
         incluso siendo del mismo dominio, o la precarga se descarta y el
         archivo se baja dos veces. */ ?>
<link rel="preload" href="/assets/fonts/inter-var-4.1.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('/assets/css/theme.css') ?>">
<?php foreach (($jsonld ?? []) as $block): ?>
  <?php /* El nonce hace falta: la CSP no admite scripts sin él, ni siquiera
           los de datos. El JSON viene escapado con JSON_HEX_TAG. */ ?>
  <script type="application/ld+json" nonce="<?= e($nonce) ?>"><?= $block ?></script>
<?php endforeach; ?>
</head>
<body>

<header class="mast<?= empty($site['header']) ? '' : ' mast-photo' ?>"
        data-overlay="<?= e($site['overlay'] ?? 'medio') ?>">
  <?php if (!empty($site['header'])): ?>
    <?php $h = $site['header']; ?>
    <img class="mast-bg" alt="" aria-hidden="true" decoding="async"
         src="/uploads/<?= e($h['base']) ?>.jpg"
         srcset="<?= e($h['srcset']) ?>" sizes="100vw"
         width="<?= (int) $h['width'] ?>" height="<?= (int) $h['height'] ?>">
  <?php endif; ?>
  <div class="mast-in">
    <a class="brand" href="/"><?= e($site['title']) ?></a>
    <?php if ($site['tagline'] !== ''): ?>
      <p class="tagline"><?= e($site['tagline']) ?></p>
    <?php endif; ?>
  </div>
</header>

<nav class="nav" aria-label="Principal">
  <div class="nav-in">
    <a class="on" href="/">Home</a>
    <?php foreach (array_slice($nav, 0, 5) as $cat): ?>
      <a href="/categoria/<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></a>
    <?php endforeach; ?>
    <form class="nav-search" action="/buscar" method="get" role="search">
      <label class="sr-only" for="q">Buscar</label>
      <input type="search" id="q" name="q" placeholder="Buscar" maxlength="120"
             value="<?= e($searchTerm ?? '') ?>">
    </form>
  </div>
</nav>

<?php if ($flash !== null): ?>
  <p class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></p>
<?php endif; ?>

<main id="contenido">
<?= $content /* ya viene escapado desde la plantilla hija */ ?>
</main>

<footer class="foot">
  <div class="foot-in">
    <p>© <?= (int) $year ?> <?= e($site['title']) ?></p>
    <p class="foot-links">
      <a href="/feed.xml">RSS</a>
      <a href="/archivo/<?= (int) $year ?>">Archivo</a>
      <a href="/p/acerca-de-mi">Acerca de mí</a>
    </p>
  </div>
</footer>

<?php if (!empty($needsComments) || !empty($needsContact)): ?>
  <?php /* Solo donde hay un formulario que mandar: el resto del sitio no
           carga una línea de JavaScript. pow.js va primero —los dos
           formularios lo usan— y los defer se ejecutan en orden. */ ?>
  <script src="<?= asset('/assets/js/pow.js') ?>" defer></script>
<?php endif; ?>
<?php if (!empty($needsComments)): ?>
  <script src="<?= asset('/assets/js/comments.js') ?>" defer></script>
<?php endif; ?>
<?php if (!empty($needsContact)): ?>
  <script src="<?= asset('/assets/js/contact.js') ?>" defer></script>
<?php endif; ?>

</body>
</html>
