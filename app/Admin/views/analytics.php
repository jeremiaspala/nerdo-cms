<?php
/**
 * @var array $summary @var array $chart @var array $topPosts @var array $referers
 * @var array $devices @var array $browsers @var int $days @var array $ranges
 *
 * El gráfico es SVG generado en el servidor. El único JavaScript es la capa
 * de hover, que vive en admin.js.
 */
$fmt = static fn (int $n): string => number_format($n, 0, ',', '.');
$maxBar = static function (array $rows): int {
    $m = 0;
    foreach ($rows as $r) { $m = max($m, (int) $r['vistas']); }
    return max(1, $m);
};
?>
<div class="head">
  <div>
    <h1>Analítica</h1>
    <p class="sub">
      Sin cookies, sin JavaScript de seguimiento y sin terceros.
      El detalle se guarda <?= (int) $retention ?> días; el agregado diario no caduca.
    </p>
  </div>
  <nav class="filters">
    <?php foreach ($ranges as $d => $label): ?>
      <a href="?dias=<?= (int) $d ?>" class="<?= $d === $days ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if (!$enabled): ?>
  <p class="flash flash-error flash-inline">
    El registro de visitas está apagado en Configuración → Analítica.
  </p>
<?php endif; ?>

<div class="tiles">
  <div class="tile"><b><?= $fmt((int) $summary['vistas']) ?></b><span>vistas</span></div>
  <div class="tile"><b><?= $fmt((int) $summary['visitantes']) ?></b><span>visitantes</span></div>
  <div class="tile"><b><?= $fmt((int) $summary['paginas']) ?></b><span>páginas distintas</span></div>
  <div class="tile">
    <b><?= $summary['visitantes'] > 0 ? round($summary['vistas'] / $summary['visitantes'], 1) : '0' ?></b>
    <span>vistas por visitante</span>
  </div>
</div>

<section class="panel chartpanel">
  <div class="panel-head">
    <h2>Visitas por día</h2>
    <div class="legend">
      <span class="legend-item"><i class="sw-views"></i>Vistas</span>
      <span class="legend-item"><i class="sw-visitors"></i>Visitantes</span>
    </div>
  </div>

  <div class="chartwrap">
    <svg class="chart" viewBox="0 0 <?= (int) $chart['w'] ?> <?= (int) $chart['h'] ?>"
         role="img" aria-label="Vistas y visitantes por día en los últimos <?= (int) $days ?> días"
         preserveAspectRatio="xMidYMid meet">

      <?php foreach ($chart['grid'] as $g): ?>
        <line class="grid" x1="<?= (int) $chart['padL'] ?>" x2="<?= (int) $chart['w'] - 8 ?>"
              y1="<?= $g['y'] ?>" y2="<?= $g['y'] ?>"></line>
        <text class="axis" x="<?= (int) $chart['padL'] - 7 ?>" y="<?= $g['y'] + 3.5 ?>"
              text-anchor="end"><?= (int) $g['label'] ?></text>
      <?php endforeach; ?>

      <path class="area" d="<?= e($chart['area']) ?>"></path>
      <path class="line-views" d="<?= e($chart['views']) ?>"></path>
      <path class="line-visitors" d="<?= e($chart['visitors']) ?>"></path>

      <line class="crosshair" id="crosshair" x1="0" x2="0"
            y1="<?= (int) $chart['padT'] ?>"
            y2="<?= (int) $chart['padT'] + (int) $chart['innerH'] ?>" hidden></line>
      <circle class="dot dot-views" id="dot-views" r="4" hidden></circle>
      <circle class="dot dot-visitors" id="dot-visitors" r="4" hidden></circle>

      <?php foreach ($chart['points'] as $p): ?>
        <rect class="hit" x="<?= max(0, $p['x'] - $chart['bandW'] / 2) ?>"
              y="<?= (int) $chart['padT'] ?>" width="<?= $chart['bandW'] ?>"
              height="<?= (int) $chart['innerH'] ?>"
              data-x="<?= $p['x'] ?>" data-yv="<?= $p['yv'] ?>" data-yu="<?= $p['yu'] ?>"
              data-dia="<?= e($p['dia']) ?>" data-vistas="<?= $p['vistas'] ?>"
              data-visitantes="<?= $p['visitantes'] ?>"></rect>
      <?php endforeach; ?>
    </svg>

    <div class="charttip" id="charttip" hidden></div>
  </div>

  <?php if ((int) $summary['vistas'] === 0): ?>
    <p class="hint pad">Todavía no hay visitas registradas en este período.</p>
  <?php endif; ?>
</section>

<div class="cols">
  <section class="panel">
    <div class="panel-head"><h2>Más vistas</h2></div>
    <table class="tbl">
      <tbody>
      <?php foreach ($topPosts as $row): ?>
        <tr>
          <td>
            <?php if (!empty($row['slug'])): ?>
              <a href="/<?= e($row['slug']) ?>" target="_blank" rel="noopener"><?= e($row['title']) ?></a>
            <?php else: ?>
              <span class="dim"><?= e($row['path']) ?></span>
            <?php endif; ?>
          </td>
          <td class="nowrap num"><?= $fmt((int) $row['vistas']) ?></td>
          <td class="nowrap num dim"><?= $fmt((int) $row['visitantes']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($topPosts === []): ?><tr><td class="dim pad">Sin datos.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>De dónde llegan</h2></div>
    <table class="tbl">
      <tbody>
      <?php foreach ($referers as $row): ?>
        <tr>
          <td><?= e($row['host']) ?></td>
          <td class="nowrap num"><?= $fmt((int) $row['vistas']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($referers === []): ?>
        <tr><td class="dim pad">Nadie llegó desde otro sitio todavía.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</div>

<div class="cols">
  <?php foreach ([['Dispositivo', $devices], ['Navegador', $browsers]] as [$titulo, $rows]): ?>
    <section class="panel">
      <div class="panel-head"><h2><?= e($titulo) ?></h2></div>
      <div class="barlist">
        <?php $m = $maxBar($rows); ?>
        <?php foreach ($rows as $row): ?>
          <div class="barrow">
            <span class="barlabel"><?= e($row['etiqueta']) ?></span>
            <?php /* Ancho por clase y no por style="": la CSP no admite
                     estilos en línea, ni en el panel. Pasos de 5%. */ ?>
            <span class="bartrack">
              <i class="w<?= (int) (round((int) $row['vistas'] / $m * 20) * 5) ?>"></i>
            </span>
            <span class="barnum"><?= $fmt((int) $row['vistas']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><p class="hint pad">Sin datos.</p><?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
