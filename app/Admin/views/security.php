<?php
/**
 * @var array  $sessions
 * @var string $sessionActual
 * @var bool   $totpActivo
 * @var string $totpPendiente
 * @var string $totpUri
 */
?>
<div class="head">
  <div>
    <h1>Seguridad</h1>
    <p class="sub">Las sesiones abiertas de tu cuenta y el segundo factor.</p>
  </div>
</div>

<div class="cols">

  <section class="panel">
    <div class="panel-head">
      <h2>Sesiones abiertas</h2>
      <?php if (count($sessions) > 1): ?>
        <form method="post" action="<?= e($adminPath) ?>/seguridad/cerrar-otras"
              data-confirm="¿Cerrar todas las otras sesiones?">
          <?= $csrf ?>
          <button class="linkbtn linkbtn-danger" type="submit">Cerrar las demás</button>
        </form>
      <?php endif; ?>
    </div>

    <table class="tbl tbl-list">
      <thead>
        <tr><th>Dónde</th><th class="nowrap">Última actividad</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($sessions as $s): ?>
          <?php $esActual = hash_equals($sessionActual, (string) $s['id']); ?>
          <tr>
            <td>
              <span class="strong"><?= e(navegador((string) $s['user_agent'])) ?></span>
              <?php if ($esActual): ?>
                <span class="pill pill-published">esta</span>
              <?php endif; ?>
              <?php /* La IP no se guarda: lo que hay es su HMAC, que sirve
                       para distinguir una sesión de otra y no para ubicar a
                       nadie. Se muestran ocho caracteres, que alcanzan. */ ?>
              <span class="slug">origen <?= e(substr((string) $s['ip_hash'], 0, 8)) ?> ·
                desde el <?= e(fecha_ar((string) $s['created_at'], true)) ?></span>
            </td>
            <td class="nowrap dim"><?= e(fecha_ar((string) $s['updated_at'], true)) ?></td>
            <td class="nowrap actions">
              <form method="post" action="<?= e($adminPath) ?>/seguridad/cerrar"
                    <?= $esActual ? 'data-confirm="Esta es la sesión de este navegador: te va a sacar del panel."' : '' ?>>
                <?= $csrf ?>
                <input type="hidden" name="sid" value="<?= e($s['id']) ?>">
                <button class="linkbtn linkbtn-danger" type="submit">Cerrar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if ($sessions === []): ?>
          <tr><td colspan="3" class="dim pad">No hay ninguna abierta.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2>Verificación en dos pasos</h2>
      <?php if ($totpActivo): ?>
        <span class="pill pill-published">activa</span>
      <?php endif; ?>
    </div>

    <div class="pad">
      <?php if ($totpActivo): ?>

        <p class="hint">
          El panel pide un código de seis dígitos después de la contraseña.
          Si perdés el teléfono, se saca por consola con
          <code>php bin/console user:totp-off --email=…</code>.
        </p>

        <form method="post" action="<?= e($adminPath) ?>/seguridad/2fa/desactivar" class="stack">
          <?= $csrf ?>
          <label class="lbl" for="password">Para desactivarla, poné tu contraseña</label>
          <input class="in" type="password" id="password" name="password" required
                 autocomplete="current-password" maxlength="200">
          <button class="btn btn-ghost" type="submit">Desactivar</button>
        </form>

      <?php elseif ($totpPendiente !== ''): ?>

        <p class="hint">
          Cargá este secreto en tu aplicación de autenticación y escribí el
          código que te muestre. Desde el teléfono, el enlace la abre sola.
        </p>

        <p class="totpsecret"><?= e(App\Services\Totp::readable($totpPendiente)) ?></p>
        <p><a class="btn btn-sm btn-ghost" href="<?= e($totpUri) ?>">Abrir en la aplicación</a></p>

        <form method="post" action="<?= e($adminPath) ?>/seguridad/2fa/confirmar" class="stack">
          <?= $csrf ?>
          <label class="lbl" for="codigo">Código de seis dígitos</label>
          <input class="in in-code" type="text" id="codigo" name="codigo" required
                 inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="000000"
                 autocomplete="one-time-code">
          <button class="btn" type="submit">Confirmar y activar</button>
        </form>

      <?php else: ?>

        <p class="hint">
          Agrega un segundo paso después de la contraseña: un código de seis
          dígitos que cambia cada treinta segundos. Sirve cualquier
          aplicación de autenticación; el CMS no habla con ningún servicio.
        </p>

        <form method="post" action="<?= e($adminPath) ?>/seguridad/2fa/empezar">
          <?= $csrf ?>
          <button class="btn" type="submit">Activar</button>
        </form>

      <?php endif; ?>
    </div>
  </section>

</div>
