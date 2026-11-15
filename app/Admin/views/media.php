<?php
/** @var array $items @var int $total @var int $page @var int $pages @var string $adminPath @var string $csrf */
?>
<div class="head">
  <div>
    <h1>Medios</h1>
    <p class="sub"><?= (int) $total ?> imagen<?= $total === 1 ? '' : 'es' ?></p>
  </div>
</div>

<section class="panel uploadpanel">
  <div class="dropzone" id="dropzone" data-endpoint="<?= e($adminPath) ?>/medios/subir">
    <p class="dz-main">Arrastrá imágenes acá</p>
    <p class="dz-sub">o</p>
    <label class="btn btn-sm" for="file-input">Elegir archivos</label>
    <input type="file" id="file-input" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
    <p class="hint">
      Se vuelven a codificar al subirlas: lo que queda en disco es un JPEG
      nuestro, no el archivo original. Máximo 12 MB cada una.
    </p>
    <div id="dz-token" hidden><?= $csrf ?></div>
  </div>
  <ul class="dz-log" id="dz-log"></ul>
</section>

<section class="medialist">
  <?php foreach ($items as $m): ?>
    <?php $base = pathinfo((string) $m['filename'], PATHINFO_FILENAME); ?>
    <figure class="mediaitem">
      <img src="/uploads/<?= e($base) ?>-400.jpg" alt="<?= e($m['alt']) ?>"
           loading="lazy" decoding="async" width="400">
      <figcaption>
        <form method="post" action="<?= e($adminPath) ?>/medios/alt" class="altform">
          <?= $csrf ?>
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <label class="sr-only" for="alt-<?= (int) $m['id'] ?>">Texto alternativo</label>
          <input class="in in-sm" type="text" id="alt-<?= (int) $m['id'] ?>" name="alt"
                 value="<?= e($m['alt']) ?>" maxlength="300" placeholder="Texto alternativo">
          <button class="linkbtn" type="submit">Guardar</button>
        </form>
        <p class="mediameta">
          <?= (int) $m['width'] ?>×<?= (int) $m['height'] ?> ·
          <?= number_format((int) $m['bytes'] / 1024, 0, ',', '.') ?> KB
        </p>
        <form method="post" action="<?= e($adminPath) ?>/medios/borrar"
              data-confirm="¿Borrar esta imagen y todas sus variantes?">
          <?= $csrf ?>
          <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
          <button class="linkbtn linkbtn-danger" type="submit">Borrar</button>
        </form>
      </figcaption>
    </figure>
  <?php endforeach; ?>

  <?php if ($items === []): ?>
    <p class="dim pad">Todavía no subiste ninguna imagen.</p>
  <?php endif; ?>
</section>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">← Anteriores</a><?php endif; ?>
    <span><?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Siguientes →</a><?php endif; ?>
  </nav>
<?php endif; ?>
