<?php
/**
 * Barra lateral: Últimas, Populares y Tendencias.
 *
 * @var array $rail  ['ultimas' => [...], 'populares' => [...], 'tendencias' => [...]]
 *
 * ── Las pestañas no llevan JavaScript ──
 *
 * Son tres <input type="radio"> ocultos y tres <label>: marcar uno muestra su
 * panel por CSS (`#rt-1:checked ~ .rail-panels #panel-1`). Funciona con
 * teclado —las flechas cambian de pestaña, que es el comportamiento nativo de
 * un grupo de radios—, funciona sin JavaScript y no agrega ni un byte de
 * script a una página que, si no, no carga ninguno.
 *
 * El equivalente en WordPress era un widget con jQuery y Bootstrap tabs: dos
 * librerías enteras cargadas en todas las páginas para alternar tres listas.
 */
$tabs = [
    'ultimas' => 'Últimas',
    'populares' => 'Populares',
    'tendencias' => 'Tendencias',
];
$first = true;
?>
<aside class="rail" aria-label="Más entradas">
  <section class="widget">
    <?php foreach (array_keys($tabs) as $key): ?>
      <input class="rail-radio" type="radio" name="railtab" id="rt-<?= e($key) ?>"
             <?= $first ? 'checked' : '' ?>>
      <?php $first = false; ?>
    <?php endforeach; ?>

    <div class="rail-tabs">
      <?php foreach ($tabs as $key => $label): ?>
        <label for="rt-<?= e($key) ?>"><?= e($label) ?></label>
      <?php endforeach; ?>
    </div>

    <div class="rail-panels">
      <?php foreach ($tabs as $key => $label): ?>
        <div class="rail-panel" id="panel-<?= e($key) ?>">
          <?php if (($rail[$key] ?? []) === []): ?>
            <p class="rail-empty">
              <?= $key === 'ultimas'
                  ? 'Todavía no hay entradas publicadas.'
                  : 'Todavía no hay visitas suficientes para armar esta lista.' ?>
            </p>
          <?php else: ?>
            <ol class="rail-list">
              <?php foreach ($rail[$key] as $item): ?>
                <li class="rail-item">
                  <?php if (!empty($item['image'])): ?>
                    <a class="rail-thumb" href="/<?= e($item['slug']) ?>" tabindex="-1" aria-hidden="true">
                      <?php $base = pathinfo((string) $item['image'], PATHINFO_FILENAME); ?>
                      <img src="/uploads/<?= e($base) ?>-400.jpg" alt="" width="104" height="78"
                           loading="lazy" decoding="async">
                    </a>
                  <?php endif; ?>
                  <div class="rail-text">
                    <?php if (!empty($item['categories'])): ?>
                      <p class="chips">
                        <?php foreach (array_slice($item['categories'], 0, 2) as $c): ?>
                          <a class="chip" href="/categoria/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
                        <?php endforeach; ?>
                      </p>
                    <?php endif; ?>
                    <a class="rail-title" href="/<?= e($item['slug']) ?>"><?= e($item['title']) ?></a>
                    <p class="rail-meta">
                      <time datetime="<?= e(substr((string) $item['published_at'], 0, 10)) ?>">
                        <?= e(fecha((string) $item['published_at'])) ?>
                      </time>
                      <?php if (isset($item['vistas'])): ?>
                        <?php $v = (int) $item['vistas']; ?>
                        <span><?= number_format($v, 0, ',', '.') ?> <?= $v === 1 ? 'vista' : 'vistas' ?></span>
                      <?php endif; ?>
                    </p>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</aside>
