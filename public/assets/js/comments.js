/**
 * Formulario de comentarios: desafío, envío e inserción.
 *
 * La prueba de trabajo vive en pow.js, que este archivo da por cargado (van
 * los dos con defer y en orden). Acá queda solo lo del formulario.
 */
(function () {
  'use strict';

  var form = document.getElementById('cmt-form');
  if (!form || !window.ncmsPow) { return; }

  var section = document.getElementById('comentarios');
  var tokenField = document.getElementById('cmt-token');
  var powField = document.getElementById('cmt-pow');
  var parentField = document.getElementById('cmt-parent');
  var sendBtn = document.getElementById('cmt-send');
  var state = document.getElementById('cmt-state');
  var errorBox = document.getElementById('cmt-error');
  var replyTo = document.getElementById('cmt-replyto');
  var replyToText = document.getElementById('cmt-replyto-text');
  var cancelReply = document.getElementById('cmt-cancel');
  var formTitle = document.getElementById('cmt-form-title');

  var ready = false;
  var working = false;

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

  // El desafío se pide cuando alguien se acerca a comentar, no en cada
  // visita: la mayoría de los lectores no comenta y no tiene por qué pagar
  // un request ni un hash.
  ['focusin', 'pointerdown'].forEach(function (ev) {
    form.addEventListener(ev, prepare, { once: true });
  });

  // ------------------------------------------------------------------
  // Responder: el formulario se muda abajo del comentario.
  // ------------------------------------------------------------------

  if (section) {
    section.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-reply]');
      if (!btn) { return; }

      parentField.value = btn.dataset.reply;
      replyToText.textContent = 'Respondiendo a ' + btn.dataset.author;
      replyTo.hidden = false;
      formTitle.textContent = 'Tu respuesta';

      // Adentro del <li>, no como hermano: un <form> suelto dentro de un
      // <ol> no es HTML válido.
      var item = btn.closest('.cmt');
      if (item) { item.appendChild(form); }

      prepare();
      document.getElementById('cmt-cuerpo').focus();
    });
  }

  if (cancelReply) {
    cancelReply.addEventListener('click', function () {
      parentField.value = '0';
      replyTo.hidden = true;
      formTitle.textContent = 'Dejá un comentario';
      section.appendChild(form);
    });
  }

  // ------------------------------------------------------------------
  // Envío
  // ------------------------------------------------------------------

  function fail(message, field) {
    errorBox.textContent = message;
    errorBox.hidden = false;

    var el = field && document.getElementById('cmt-' + field);
    if (el) { el.focus(); }
  }

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
        // Un desafío gastado o vencido no se puede reusar: hay que pedir otro.
        ready = false;
        powField.value = '';
        tokenField.value = '';
        fail(r.json.error || 'No pudimos publicar el comentario.', r.json.field);
        return;
      }

      if (r.json.status === 'approved' && r.json.html) {
        appendComment(r.json.html, parentField.value);
      } else {
        thanks(r.json.message || 'Gracias. Tu comentario quedó esperando moderación.');
      }
    }).catch(function () {
      sendBtn.disabled = false;
      state.textContent = '';
      fail('Se cortó la conexión. Probá de nuevo.');
    });
  });

  function depthOf(item) {
    var match = /cmt-d(\d)/.exec(item.className);
    return match ? parseInt(match[1], 10) : 0;
  }

  function appendComment(html, parentId) {
    var list = document.getElementById('cmt-list');

    if (!list) {
      list = document.createElement('ol');
      list.className = 'cmt-list';
      list.id = 'cmt-list';
      section.querySelector('.comments-title').after(list);
    }

    // El HTML lo armó el tema en el servidor, con todo escapado y saneado;
    // acá solo se parsea y se inserta. Nada se construye con lo que escribió
    // la persona.
    var tpl = document.createElement('template');
    tpl.innerHTML = html.trim();

    var item = tpl.content.firstElementChild;
    if (!item) { return; }

    // La lista está aplanada y la sangría va por clase, así que una respuesta
    // se inserta después del último descendiente de su padre y con un nivel
    // más. Sin esto, el comentario aparece al final y se muda de lugar solo
    // al recargar, que es exactamente la clase de salto que hace dudar de si
    // se publicó.
    var parent = parentId && parentId !== '0'
      ? document.getElementById('comentario-' + parentId)
      : null;

    if (parent) {
      var parentDepth = depthOf(parent);
      item.className = 'cmt cmt-d' + Math.min(parentDepth + 1, 2);

      var last = parent;
      while (last.nextElementSibling && depthOf(last.nextElementSibling) > parentDepth) {
        last = last.nextElementSibling;
      }

      last.after(item);
    } else {
      list.appendChild(item);
    }

    thanks('Publicado.');
    item.scrollIntoView({ block: 'center' });
  }

  function thanks(message) {
    var note = document.createElement('p');
    note.className = 'cmt-thanks';
    note.setAttribute('role', 'status');
    note.textContent = message;

    form.replaceWith(note);
  }
})();
