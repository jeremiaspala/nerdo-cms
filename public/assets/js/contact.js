/**
 * Formulario de contacto.
 *
 * Mismo trato que los comentarios: desafío firmado por fetch, prueba de
 * trabajo en pow.js y envío por JSON. Sin sesión, sin cookie y sin captcha
 * de terceros, así que la página de contacto se sigue sirviendo del caché
 * igual para todo el mundo.
 */
(function () {
  'use strict';

  var form = document.getElementById('contact-form');
  if (!form || !window.ncmsPow) { return; }

  var tokenField = document.getElementById('contact-token');
  var powField = document.getElementById('contact-pow');
  var sendBtn = document.getElementById('contact-send');
  var state = document.getElementById('contact-state');
  var errorBox = document.getElementById('contact-error');

  var ready = false;
  var working = false;

  function fail(message, field) {
    errorBox.textContent = message;
    errorBox.hidden = false;

    var el = field && document.getElementById('contact-' + field);
    if (el) { el.focus(); }
  }

  function prepare() {
    if (ready || working) { return; }
    working = true;
    state.textContent = 'Verificando el navegador…';

    window.ncmsPow.prepare(form.dataset.challenge).then(function (r) {
      tokenField.value = r.token;
      powField.value = r.pow;
      ready = true;
      working = false;
      state.textContent = 'Listo (' + (r.ms / 1000).toFixed(1) + ' s).';
    }).catch(function (err) {
      working = false;
      state.textContent = '';
      fail(err.message);
    });
  }

  ['focusin', 'pointerdown'].forEach(function (ev) {
    form.addEventListener(ev, prepare, { once: true });
  });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    errorBox.hidden = true;

    if (!ready) {
      prepare();
      state.textContent = working
        ? 'Un segundo, terminando la verificación…'
        : 'Un segundo…';
      return;
    }

    sendBtn.disabled = true;
    state.textContent = 'Enviando…';

    fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'omit',
      headers: { 'Accept': 'application/json' }
    }).then(function (res) {
      return res.json().then(function (json) {
        return { ok: res.ok, json: json };
      });
    }).then(function (r) {
      state.textContent = '';

      if (!r.ok) {
        sendBtn.disabled = false;
        // El desafío ya se gastó: para reintentar hace falta otro.
        ready = false;
        tokenField.value = '';
        powField.value = '';
        fail(r.json.error || 'No pudimos enviar el mensaje.', r.json.field);
        return;
      }

      var note = document.createElement('p');
      note.className = 'cmt-thanks';
      note.setAttribute('role', 'status');
      note.textContent = r.json.message || 'Listo, llegó. Te contesto a la brevedad.';

      form.replaceWith(note);
      note.scrollIntoView({ block: 'center' });
    }).catch(function () {
      sendBtn.disabled = false;
      state.textContent = '';
      fail('Se cortó la conexión. Probá de nuevo.');
    });
  });
})();
