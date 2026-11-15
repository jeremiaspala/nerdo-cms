<?php
/**
 * @var array $post @var array $allCategories @var array $selectedCategories
 * @var string $type @var string $adminPath @var string $csrf
 *
 * El contenido viaja en un <textarea> escapado con e(). Quill lo lee de ahí
 * al arrancar y lo vuelve a escribir antes de enviar el formulario, así que
 * el editor nunca necesita JavaScript en línea ni un JSON embebido.
 */
$seg = $type === 'page' ? 'paginas' : 'entradas';
$esNueva = ((int) $post['id']) === 0;
$verPrefijo = $type === 'page' ? '/p/' : '/';
?>
<form method="post" action="<?= e($adminPath) ?>/<?= $seg ?>/guardar" class="editor" id="editor-form">
  <?= $csrf ?>
  <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">

  <div class="head">
    <div>
      <h1><?= $esNueva ? 'Nueva ' . ($type === 'page' ? 'página' : 'entrada') : 'Editar' ?></h1>
      <?php if (!$esNueva): ?>
        <?php [$estado, $etiqueta] = post_state($post); ?>
        <p class="sub">
          <span class="pill pill-<?= e($estado) ?>"><?= e($etiqueta) ?></span>
          <?php if ($estado === 'published'): ?>
            <a href="<?= e($verPrefijo . $post['slug']) ?>" target="_blank" rel="noopener">Ver publicada ↗</a>
          <?php elseif ($estado === 'scheduled'): ?>
            sale el <?= e(fecha_hora((string) $post['published_at'])) ?>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
    <div class="headactions">
      <button class="btn btn-ghost" type="submit" name="status" value="draft">Guardar borrador</button>
      <?php /* El texto lo cambia admin.js a «Programar» cuando la fecha es
               futura: el botón tiene que decir lo que va a pasar. */ ?>
      <button class="btn" type="submit" name="status" value="published" id="publish-btn"
              data-publicar="Publicar" data-programar="Programar">Publicar</button>
    </div>
  </div>

  <div class="editor-grid">

    <div class="editor-main">
      <label class="lbl" for="title">Título</label>
      <input class="in in-title" type="text" id="title" name="title" maxlength="300" required
             value="<?= e($post['title']) ?>" placeholder="Título de la entrada">

      <label class="lbl" for="editor">Contenido</label>
      <div id="editor" class="quill-host"></div>
      <textarea id="body_html" name="body_html" hidden><?= e($post['body_html']) ?></textarea>

      <label class="lbl" for="excerpt">Extracto</label>
      <textarea class="in" id="excerpt" name="excerpt" rows="3" maxlength="500"
                placeholder="Si lo dejás vacío se arma solo con el comienzo del texto."><?= e($post['excerpt']) ?></textarea>
    </div>

    <aside class="editor-side">

      <section class="box">
        <h2>Publicación</h2>

        <label class="lbl" for="published_at">Fecha</label>
        <input class="in" type="datetime-local" id="published_at" name="published_at"
               value="<?= e(date('Y-m-d\TH:i', strtotime((string) ($post['published_at'] ?: 'now')))) ?>">
        <p class="hint">Una fecha futura deja la entrada agendada: no aparece hasta que llegue.</p>

        <?php /* El enlace definitivo, desde el primer borrador.
                 Es lo que se pega en el programador de LinkedIn o Instagram
                 días antes de que la entrada salga. Si después se cambia el
                 slug, el CMS deja un 301 del viejo al nuevo, así que el que
                 ya se programó sigue llevando a la entrada. */ ?>
        <label class="lbl" for="permalink">Enlace</label>
        <div class="copyrow">
          <input class="in in-sm" type="text" id="permalink" readonly
                 data-base="<?= e($siteUrl . $verPrefijo) ?>"
                 value="<?= e($siteUrl . $verPrefijo . ($post['slug'] !== '' ? $post['slug'] : 'se-genera-del-titulo')) ?>">
          <button class="btn btn-sm btn-ghost" type="button" id="copy-permalink"
                  data-copied="¡Copiado!">Copiar</button>
        </div>
        <p class="hint" id="permalink-hint">
          <?php if ($esNueva): ?>
            Se arma con el slug. Empieza a funcionar cuando publiques.
          <?php elseif (($estado ?? '') === 'scheduled'): ?>
            Ya podés programarlo en tus redes: va a funcionar cuando la entrada salga.
          <?php elseif (($estado ?? '') === 'published'): ?>
            En línea. Si cambiás el slug, el viejo sigue funcionando con un 301.
          <?php else: ?>
            Mientras sea borrador devuelve 404. Si lo programás en tus redes, publicá antes de esa hora.
          <?php endif; ?>
        </p>

        <label class="lbl" for="slug">Slug</label>
        <input class="in" type="text" id="slug" name="slug" maxlength="190"
               value="<?= e($post['slug']) ?>" placeholder="se-genera-del-titulo">
        <p class="hint">
          <?php if (!$esNueva && $post['status'] === 'published'): ?>
            Si lo cambiás, se crea un redirect 301 del viejo al nuevo.
          <?php else: ?>
            Se genera del título si lo dejás vacío.
          <?php endif; ?>
        </p>

        <?php if ($type !== 'page'): ?>
          <label class="chk">
            <input type="checkbox" name="comments_open" value="1"
                   <?= $post['comments_open'] ? 'checked' : '' ?>>
            Aceptar comentarios
          </label>
        <?php endif; ?>
      </section>

      <section class="box" id="featured-box" data-endpoint="<?= e($adminPath) ?>/medios/subir">
        <h2>Imagen destacada</h2>

        <input type="hidden" name="featured_media_id" id="featured_media_id"
               value="<?= (int) ($post['featured_media_id'] ?? 0) ?>">

        <div class="featured-preview" id="featured-preview" <?= $featured ? '' : 'hidden' ?>>
          <?php if ($featured): ?>
            <img src="/uploads/<?= e(pathinfo((string) $featured['filename'], PATHINFO_FILENAME)) ?>-400.jpg"
                 alt="" width="400" decoding="async">
          <?php endif; ?>
        </div>

        <div class="featured-actions">
          <label class="btn btn-sm btn-ghost" for="featured-file">Subir</label>
          <input type="file" id="featured-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
          <button class="linkbtn" type="button" id="featured-pick">Elegir de la galería</button>
          <button class="linkbtn linkbtn-danger" type="button" id="featured-clear"
                  <?= $featured ? '' : 'hidden' ?>>Quitar</button>
        </div>

        <p class="hint" id="featured-status"></p>

        <div class="library" id="library" hidden>
          <?php foreach ($library as $m): ?>
            <?php $b = pathinfo((string) $m['filename'], PATHINFO_FILENAME); ?>
            <button type="button" class="libitem" data-id="<?= (int) $m['id'] ?>"
                    data-thumb="/uploads/<?= e($b) ?>-400.jpg" title="<?= e($m['alt']) ?>">
              <img src="/uploads/<?= e($b) ?>-400.jpg" alt="<?= e($m['alt']) ?>"
                   loading="lazy" decoding="async" width="400">
            </button>
          <?php endforeach; ?>
          <?php if ($library === []): ?>
            <p class="hint">La galería está vacía. Subí una imagen primero.</p>
          <?php endif; ?>
        </div>
      </section>

      <?php if ($type !== 'page'): ?>
        <section class="box">
          <h2>Categorías</h2>
          <div class="catlist">
            <?php foreach ($allCategories as $c): ?>
              <label class="chk">
                <input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>"
                       <?= in_array((int) $c['id'], $selectedCategories, true) ? 'checked' : '' ?>>
                <?= e($c['name']) ?>
              </label>
            <?php endforeach; ?>
            <?php if ($allCategories === []): ?>
              <p class="hint">Todavía no hay categorías.</p>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <section class="box">
        <h2>SEO</h2>

        <label class="lbl" for="meta_title">Título META</label>
        <input class="in" type="text" id="meta_title" name="meta_title" maxlength="190"
               value="<?= e($post['meta_title']) ?>" placeholder="Si está vacío usa el título">
        <p class="hint" data-counter-for="meta_title" data-ideal="60"></p>

        <label class="lbl" for="meta_description">Descripción META</label>
        <textarea class="in" id="meta_description" name="meta_description" rows="3" maxlength="320"
                  placeholder="Si está vacía usa el extracto."><?= e($post['meta_description']) ?></textarea>
        <p class="hint" data-counter-for="meta_description" data-ideal="155"></p>

        <label class="lbl" for="canonical">Canonical</label>
        <input class="in" type="url" id="canonical" name="canonical" maxlength="400"
               value="<?= e($post['canonical']) ?>" placeholder="Solo si apunta a otra URL">

        <label class="chk">
          <input type="checkbox" name="noindex" value="1" <?= $post['noindex'] ? 'checked' : '' ?>>
          No indexar (noindex)
        </label>
      </section>

    </aside>
  </div>
</form>

<?php if (!$esNueva): ?>
  <form class="danger" method="post" action="<?= e($adminPath) ?>/<?= $seg ?>/papelera"
        data-confirm="¿Mandar esta <?= $type === 'page' ? 'página' : 'entrada' ?> a la papelera?">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
    <button class="linkbtn linkbtn-danger" type="submit">Mandar a la papelera</button>
  </form>
<?php endif; ?>
