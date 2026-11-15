<?php
/**
 * @var array $items @var array $grupos @var array $filtros @var array $usuarios
 * @var int $fallidos @var int $page @var int $pages @var int $total
 */
$qs = static function (array $cambios) use ($filtros): string {
    $q = array_filter([
        'grupo' => $filtros['grupo'],
        'usuario' => $filtros['usuario'] > 0 ? (string) $filtros['usuario'] : '',
        'desde' => $filtros['desde'],
    ] + $cambios);

    return $q === [] ? '' : '?' . http_build_query(array_merge($q, $cambios));
};
?>
<div class="head">
  <div>
    <h1>Bitácora</h1>
    <p class="sub">
      <?= (int) $total ?> registro<?= $total === 1 ? '' : 's' ?>
      <?php if ($fallidos > 0): ?>
        · <span class="alerta"><?= (int) $fallidos ?> intento<?= $fallidos === 1 ? '' : 's' ?>
        fallido<?= $fallidos === 1 ? '' : 's' ?> en las últimas 24 h</span>
      <?php endif; ?>
    </p>
  </div>
</div>

<form class="filtros-form" method="get" action="<?= e($adminPath) ?>/bitacora">
  <nav class="filters">
    <a href="<?= e($adminPath) ?>/bitacora" class="<?= $filtros['grupo'] === '' ? 'on' : '' ?>">Todo</a>
    <?php foreach ($grupos as $g): ?>
      <a href="<?= e($adminPath) ?>/bitacora?grupo=<?= e($g) ?>"
         class="<?= $filtros['grupo'] === $g ? 'on' : '' ?>"><?= e(ucfirst($g)) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="filtros-extra">
    <?php if ($filtros['grupo'] !== ''): ?>
      <input type="hidden" name="grupo" value="<?= e($filtros['grupo']) ?>">
    <?php endif; ?>

    <label class="lbl" for="usuario">Quién</label>
    <select class="in in-sm" id="usuario" name="usuario">
      <option value="0">Cualquiera</option>
      <?php foreach ($usuarios as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $filtros['usuario'] === (int) $u['id'] ? 'selected' : '' ?>>
          <?= e($u['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label class="lbl" for="desde">Desde</label>
    <input class="in in-sm" type="date" id="desde" name="desde" value="<?= e($filtros['desde']) ?>">

    <button class="btn btn-sm btn-ghost" type="submit">Filtrar</button>
  </div>
</form>

<section class="panel">
  <table class="tbl tbl-list">
    <thead>
      <tr>
        <th>Qué pasó</th>
        <th>Quién</th>
        <th class="nowrap">Origen</th>
        <th class="nowrap">Cuándo</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $a): ?>
        <?php [$texto, $grupo] = App\Models\AuditLog::describe((string) $a['action']); ?>
        <tr>
          <td>
            <span class="strong"><?= e($texto) ?></span>
            <?php if (($a['target'] ?? '') !== ''): ?>
              <span class="slug"><?= e(mb_substr((string) $a['target'], 0, 90)) ?></span>
            <?php endif; ?>
            <?php if (($a['detail'] ?? '') !== null && ($a['detail'] ?? '') !== ''): ?>
              <span class="slug dim"><?= e(mb_substr((string) $a['detail'], 0, 90)) ?></span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <?php if (($a['user_name'] ?? null) !== null): ?>
              <?= e($a['user_name']) ?>
            <?php else: ?>
              <span class="dim">sin sesión</span>
            <?php endif; ?>
          </td>
          <td class="nowrap dim">
            <?php /* Es el HMAC de la IP, no la IP: sirve para ver que dos
                     accesos vinieron del mismo lado, no para ubicar a nadie. */ ?>
            <code><?= e(substr((string) $a['ip_hash'], 0, 8) ?: '—') ?></code>
          </td>
          <td class="nowrap dim"><?= e(fecha_ar((string) $a['created_at'], true)) ?></td>
        </tr>
      <?php endforeach; ?>

      <?php if ($items === []): ?>
        <tr><td colspan="4" class="dim pad">No hay nada con esos filtros.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= $qs(['page' => $page - 1]) ?>">← Anteriores</a><?php endif; ?>
    <span><?= (int) $page ?> / <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= $qs(['page' => $page + 1]) ?>">Siguientes →</a><?php endif; ?>
  </nav>
<?php endif; ?>
