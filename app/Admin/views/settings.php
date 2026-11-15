<?php
/**
 * Configuración: índice de secciones a la izquierda, campos a la derecha.
 *
 * La vista es genérica: recorre el esquema y dibuja cada campo según su
 * tipo. No sabe qué ajustes existen.
 *
 * @var array $schema @var string $section @var array $values @var array $library
 */
$sec = $schema[$section];
$val = static fn (string $k, array $f): string => (string) ($values[$k] ?? ($f['default'] ?? ''));
?>
<div class="head">
  <div>
    <h1>Configuración</h1>
    <p class="sub"><?= e($sec['descripcion']) ?></p>
  </div>
</div>

<div class="settings">
  <nav class="settings-nav" aria-label="Secciones">
    <?php foreach ($schema as $key => $s): ?>
      <a href="<?= e($adminPath) ?>/configuracion/<?= e($key) ?>"
         class="<?= $key === $section ? 'on' : '' ?>"><?= e($s['titulo']) ?></a>
    <?php endforeach; ?>
  </nav>

  <form class="settings-form" method="post"
        action="<?= e($adminPath) ?>/configuracion/<?= e($section) ?>/guardar">
    <?= $csrf ?>

    <?php foreach ($sec['campos'] as $key => $f): ?>
      <?php
        $v = $val($key, $f);
        $depends = $f['depends'] ?? '';
      ?>
      <div class="field<?= $f['type'] === 'bool' ? ' field-bool' : '' ?>"
           <?= $depends !== '' ? 'data-depends="' . e($depends) . '"' : '' ?>>

        <?php if ($f['type'] === 'bool'): ?>
          <label class="chk">
            <input type="checkbox" id="<?= e($key) ?>" name="<?= e($key) ?>" value="1"
                   <?= $v === '1' ? 'checked' : '' ?>>
            <span><?= e($f['label']) ?></span>
          </label>

        <?php elseif ($f['type'] === 'select'): ?>
          <label class="lbl" for="<?= e($key) ?>"><?= e($f['label']) ?></label>
          <select class="in" id="<?= e($key) ?>" name="<?= e($key) ?>">
            <?php foreach ($f['options'] as $ov => $ol): ?>
              <option value="<?= e((string) $ov) ?>" <?= (string) $ov === $v ? 'selected' : '' ?>>
                <?= e($ol) ?>
              </option>
            <?php endforeach; ?>
          </select>

        <?php elseif ($f['type'] === 'textarea'): ?>
          <label class="lbl" for="<?= e($key) ?>"><?= e($f['label']) ?></label>
          <textarea class="in" id="<?= e($key) ?>" name="<?= e($key) ?>" rows="4"
                    maxlength="<?= (int) ($f['maxlength'] ?? 2000) ?>"><?= e($v) ?></textarea>

        <?php elseif ($f['type'] === 'password'): ?>
          <label class="lbl" for="<?= e($key) ?>"><?= e($f['label']) ?></label>
          <input class="in" type="password" id="<?= e($key) ?>" name="<?= e($key) ?>"
                 autocomplete="new-password"
                 placeholder="<?= $v !== '' ? 'Guardada · escribí para cambiarla' : 'Sin configurar' ?>">

        <?php elseif ($f['type'] === 'media'): ?>
          <label class="lbl"><?= e($f['label']) ?></label>
          <?php $m = $v !== '' && $v !== '0' ? array_values(array_filter($library, fn ($x) => (int) $x['id'] === (int) $v))[0] ?? null : null; ?>
          <div class="mediafield" data-media-field="<?= e($key) ?>">
            <input type="hidden" name="<?= e($key) ?>" id="<?= e($key) ?>" value="<?= e($v) ?>">
            <div class="mediafield-preview" <?= $m ? '' : 'hidden' ?>>
              <?php if ($m): ?>
                <img src="/uploads/<?= e(pathinfo((string) $m['filename'], PATHINFO_FILENAME)) ?>-800.jpg" alt="">
              <?php endif; ?>
            </div>
            <div class="mediafield-actions">
              <button type="button" class="linkbtn" data-media-pick>Elegir de la galería</button>
              <button type="button" class="linkbtn linkbtn-danger" data-media-clear <?= $m ? '' : 'hidden' ?>>Quitar</button>
            </div>
            <div class="library" hidden>
              <?php foreach ($library as $item): ?>
                <?php $b = pathinfo((string) $item['filename'], PATHINFO_FILENAME); ?>
                <button type="button" class="libitem" data-id="<?= (int) $item['id'] ?>"
                        data-thumb="/uploads/<?= e($b) ?>-800.jpg">
                  <img src="/uploads/<?= e($b) ?>-400.jpg" alt="<?= e($item['alt']) ?>" loading="lazy">
                </button>
              <?php endforeach; ?>
            </div>
          </div>

        <?php else: ?>
          <label class="lbl" for="<?= e($key) ?>"><?= e($f['label']) ?></label>
          <input class="in" type="<?= e($f['type'] === 'number' ? 'number' : ($f['type'] === 'email' ? 'email' : 'text')) ?>"
                 id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($v) ?>"
                 <?= isset($f['min']) ? 'min="' . (int) $f['min'] . '"' : '' ?>
                 <?= isset($f['max']) ? 'max="' . (int) $f['max'] . '"' : '' ?>>
        <?php endif; ?>

        <?php if (!empty($f['help'])): ?>
          <p class="hint"><?= e($f['help']) ?></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="settings-save">
      <button class="btn" type="submit">Guardar cambios</button>
    </div>
  </form>
</div>

<?php if ($section === 'correo'): ?>
  <section class="panel mailtest">
    <div class="panel-head"><h2>Probar el envío</h2></div>
    <form method="post" action="<?= e($adminPath) ?>/configuracion/correo/probar" class="mailtest-form">
      <?= $csrf ?>
      <label class="sr-only" for="mailtest-to">Dirección de prueba</label>
      <input class="in" type="email" id="mailtest-to" name="to" required
             placeholder="tu@correo.com" value="<?= e($values['mail_to'] ?? '') ?>">
      <button class="btn btn-ghost" type="submit">Enviar prueba</button>
    </form>
    <p class="hint">
      Guardá los cambios antes de probar: la prueba usa lo que está guardado,
      no lo que tenés escrito en el formulario.
    </p>
  </section>
<?php endif; ?>
