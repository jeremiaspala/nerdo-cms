/**
 * Captcha del login.
 *
 * El desafío viene en el propio formulario (el servidor lo firmó al dibujar
 * la página), así que acá solo se resuelve. Se arranca apenas carga: para
 * cuando la persona terminó de escribir la contraseña, ya está listo.
 *
 * Es el mismo mecanismo que usan los comentarios y el contacto, y está acá
 * por el mismo motivo: frenar al que prueba contraseñas en masa sin pedirle
 * a nadie que identifique semáforos ni cargar un script de Google en la
 * puerta de entrada del panel.
 */
(function () {
  'use strict';

  var form = document.getElementById('login-form');
  if (!form || !window.ncmsPow) { return; }

  var campo = document.getElementById('login-pow');
  var boton = document.getElementById('login-send');
  var estado = document.getElementById('login-state');

  var listo = false;

  estado.textContent = 'Verificando el navegador…';

  window.ncmsPow.solve(form.dataset.nonce, parseInt(form.dataset.bits, 10), function (solucion, ms) {
    campo.value = solucion;
    listo = true;
    estado.textContent = 'Listo (' + (ms / 1000).toFixed(1) + ' s).';
  });

  form.addEventListener('submit', function (ev) {
    if (listo) { return; }

    // Todavía resolviendo: se espera en vez de rechazar el intento, que
    // sería castigar a quien escribe rápido en una máquina lenta.
    ev.preventDefault();
    boton.disabled = true;
    estado.textContent = 'Un segundo, terminando la verificación…';

    var esperar = setInterval(function () {
      if (!listo) { return; }

      clearInterval(esperar);
      boton.disabled = false;
      form.submit();
    }, 100);
  });
})();
