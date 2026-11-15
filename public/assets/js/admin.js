/**
 * Comportamiento común del panel.
 *
 * Sin dependencias y sin nada en línea, porque la CSP no admite scripts
 * embebidos. Tres cosas: confirmación antes de acciones destructivas,
 * contadores de longitud en los campos SEO, y autogenerado del slug.
 */
(function () {
  'use strict';

  // ---- Confirmar antes de mandar algo a la papelera.
  // No se usa confirm() del navegador: se reemplaza el botón por una
  // pregunta en la propia página, que además no bloquea nada.
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (form.dataset.confirmed === '1') { return; }
      ev.preventDefault();

      var box = document.createElement('span');
      box.className = 'confirmbox';

      var text = document.createElement('span');
      text.textContent = form.dataset.confirm;

      var yes = document.createElement('button');
      yes.type = 'button';
      yes.className = 'linkbtn linkbtn-danger';
      yes.textContent = 'Sí, dale';
      yes.addEventListener('click', function () {
        form.dataset.confirmed = '1';
        form.submit();
      });

      var no = document.createElement('button');
      no.type = 'button';
      no.className = 'linkbtn';
      no.textContent = 'Cancelar';
      no.addEventListener('click', function () { box.replaceWith(original); });

      box.append(text, yes, no);

      var original = form.querySelector('button[type="submit"]');
      if (original) { original.replaceWith(box); }
    });
  });

  // ---- Cola de moderación: marcar todos y acciones de una sola fila.
  (function moderation() {
    var form = document.getElementById('modform');
    if (!form) { return; }

    var boxes = form.querySelectorAll('input[name="ids[]"]');
    var all = document.getElementById('check-all');

    if (all) {
      all.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = all.checked; });
      });
    }

    // Un botón de fila actúa sobre esa fila y nada más: marca su casilla,
    // desmarca el resto y manda el mismo formulario. Así hay un solo camino
    // en el servidor (una lista de ids) en vez de dos rutas distintas.
    form.querySelectorAll('[data-one]').forEach(function (btn) {
      btn.addEventListener('click', function (ev) {
        if (btn.dataset.confirmOne && btn.dataset.confirmed !== '1') {
          ev.preventDefault();
          btn.dataset.confirmed = '1';
          btn.textContent = 'Confirmar';
          btn.title = btn.dataset.confirmOne;
          return;
        }

        boxes.forEach(function (box) {
          box.checked = box.value === btn.dataset.one;
        });
        if (all) { all.checked = false; }
      });
    });
  })();

  // ---- Contadores de longitud SEO.
  document.querySelectorAll('[data-counter-for]').forEach(function (hint) {
    var field = document.getElementById(hint.dataset.counterFor);
    if (!field) { return; }

    var ideal = parseInt(hint.dataset.ideal, 10) || 60;

    function update() {
      var n = field.value.length;
      hint.textContent = n + ' de ~' + ideal + ' caracteres';
      hint.classList.toggle('hint-warn', n > ideal);
    }

    field.addEventListener('input', update);
    update();
  });

  // ---- Subida de imágenes.
  // El token CSRF se toma del propio DOM: el panel siempre tiene al menos
  // un formulario con él, así que no hace falta embeberlo en línea.
  function csrfToken() {
    var field = document.querySelector('input[name="_token"]');
    return field ? field.value : '';
  }

  function uploadImage(file, endpoint) {
    var data = new FormData();
    data.append('_token', csrfToken());
    data.append('file', file);

    return fetch(endpoint, {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!res.ok) { throw new Error(json.error || 'No se pudo subir.'); }
        return json;
      });
    });
  }

  window.ncmsUpload = uploadImage;

  // ---- Zona de arrastre de la galería.
  var dz = document.getElementById('dropzone');
  var log = document.getElementById('dz-log');

  if (dz && log) {
    var endpoint = dz.dataset.endpoint;

    function note(text, isError) {
      var li = document.createElement('li');
      li.textContent = text;
      if (isError) { li.className = 'dz-error'; }
      log.prepend(li);
    }

    function handleFiles(files) {
      Array.prototype.forEach.call(files, function (file) {
        note('Subiendo ' + file.name + '…');
        uploadImage(file, endpoint).then(function () {
          note(file.name + ' listo. Recargá para verlo en la galería.');
        }).catch(function (err) {
          note(file.name + ': ' + err.message, true);
        });
      });
    }

    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) {
        e.preventDefault();
        dz.classList.add('dz-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) {
        e.preventDefault();
        dz.classList.remove('dz-over');
      });
    });
    dz.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) { handleFiles(e.dataTransfer.files); }
    });

    var input = document.getElementById('file-input');
    if (input) {
      input.addEventListener('change', function () { handleFiles(input.files); });
    }
  }

  // ---- Imagen destacada en el editor.
  var fbox = document.getElementById('featured-box');

  if (fbox) {
    var fid = document.getElementById('featured_media_id');
    var preview = document.getElementById('featured-preview');
    var status = document.getElementById('featured-status');
    var clear = document.getElementById('featured-clear');
    var library = document.getElementById('library');

    function setFeatured(id, thumb) {
      fid.value = String(id);
      preview.innerHTML = '';

      var img = document.createElement('img');
      img.src = thumb;
      img.alt = '';
      img.width = 400;
      preview.appendChild(img);

      preview.hidden = false;
      clear.hidden = false;
      status.textContent = '';
      if (library) { library.hidden = true; }
    }

    document.getElementById('featured-file').addEventListener('change', function (ev) {
      var file = ev.target.files[0];
      if (!file) { return; }

      status.textContent = 'Subiendo…';
      uploadImage(file, fbox.dataset.endpoint).then(function (m) {
        setFeatured(m.id, m.url.replace(/\.jpg$/, '-400.jpg'));
      }).catch(function (err) {
        status.textContent = err.message;
        status.classList.add('hint-warn');
      });
    });

    clear.addEventListener('click', function () {
      fid.value = '0';
      preview.hidden = true;
      preview.innerHTML = '';
      clear.hidden = true;
    });

    var pick = document.getElementById('featured-pick');
    if (pick && library) {
      pick.addEventListener('click', function () { library.hidden = !library.hidden; });

      library.querySelectorAll('.libitem').forEach(function (btn) {
        btn.addEventListener('click', function () {
          setFeatured(btn.dataset.id, btn.dataset.thumb);
        });
      });
    }
  }

  // ---- Capa de hover del gráfico: cruz, puntos y globo.
  (function chartHover() {
    var svg = document.querySelector('.chart');
    var tip = document.getElementById('charttip');
    if (!svg || !tip) { return; }

    var cross = document.getElementById('crosshair');
    var dotV = document.getElementById('dot-views');
    var dotU = document.getElementById('dot-visitors');
    var wrap = svg.parentElement;

    function show(rect) {
      var d = rect.dataset;

      cross.setAttribute('x1', d.x);
      cross.setAttribute('x2', d.x);
      cross.hidden = false;

      dotV.setAttribute('cx', d.x); dotV.setAttribute('cy', d.yv); dotV.hidden = false;
      dotU.setAttribute('cx', d.x); dotU.setAttribute('cy', d.yu); dotU.hidden = false;

      tip.innerHTML = '';
      var title = document.createElement('b');
      title.textContent = d.dia;
      tip.appendChild(title);

      [['#0f9e8e', d.vistas, 'vistas'], ['#b45309', d.visitantes, 'visitantes']]
        .forEach(function (row) {
          var line = document.createElement('span');
          var sw = document.createElement('i');
          sw.style.background = row[0];   // propiedad por JS: la CSP no la bloquea
          line.appendChild(sw);
          line.appendChild(document.createTextNode(row[1] + ' ' + row[2]));
          tip.appendChild(line);
        });

      // De coordenadas del viewBox a píxeles reales del contenedor.
      var box = svg.getBoundingClientRect();
      var vb = svg.viewBox.baseVal;
      var scale = box.width / vb.width;

      tip.style.left = (parseFloat(d.x) * scale) + 'px';
      tip.style.top = (parseFloat(Math.min(d.yv, d.yu)) * scale + svg.offsetTop) + 'px';
      tip.hidden = false;
    }

    function hide() {
      cross.hidden = dotV.hidden = dotU.hidden = tip.hidden = true;
    }

    svg.querySelectorAll('.hit').forEach(function (rect) {
      rect.addEventListener('mouseenter', function () { show(rect); });
      rect.addEventListener('focus', function () { show(rect); });
    });

    wrap.addEventListener('mouseleave', hide);
  })();

  // ---- Campos de imagen en la configuración (genéricos).
  document.querySelectorAll('[data-media-field]').forEach(function (wrap) {
    var input = wrap.querySelector('input[type="hidden"]');
    var preview = wrap.querySelector('.mediafield-preview');
    var clear = wrap.querySelector('[data-media-clear]');
    var pick = wrap.querySelector('[data-media-pick]');
    var library = wrap.querySelector('.library');

    if (pick && library) {
      pick.addEventListener('click', function () { library.hidden = !library.hidden; });
    }

    if (library) {
      library.querySelectorAll('.libitem').forEach(function (btn) {
        btn.addEventListener('click', function () {
          input.value = btn.dataset.id;
          preview.innerHTML = '';

          var img = document.createElement('img');
          img.src = btn.dataset.thumb;
          img.alt = '';
          preview.appendChild(img);

          preview.hidden = false;
          clear.hidden = false;
          library.hidden = true;
        });
      });
    }

    if (clear) {
      clear.addEventListener('click', function () {
        input.value = '0';
        preview.hidden = true;
        preview.innerHTML = '';
        clear.hidden = true;
      });
    }
  });

  // ---- Campos que dependen del valor de otro (data-depends="clave=valor").
  (function dependencies() {
    var fields = document.querySelectorAll('[data-depends]');
    if (!fields.length) { return; }

    var watched = {};

    fields.forEach(function (field) {
      var parts = field.dataset.depends.split('=');
      var key = parts[0];
      var want = parts[1];
      var source = document.getElementById(key);
      if (!source) { return; }

      (watched[key] = watched[key] || []).push({ el: field, want: want, src: source });
    });

    function refresh() {
      Object.keys(watched).forEach(function (key) {
        watched[key].forEach(function (dep) {
          var value = dep.src.type === 'checkbox' ? (dep.src.checked ? '1' : '0') : dep.src.value;
          dep.el.hidden = value !== dep.want;
        });
      });
    }

    Object.keys(watched).forEach(function (key) {
      watched[key][0].src.addEventListener('change', refresh);
    });

    refresh();
  })();

  // ---- Agregar una categoría sin salir de la entrada.
  (function nuevaCategoria() {
    var caja = document.querySelector('.catnew');
    if (!caja) { return; }

    var campo = document.getElementById('cat-nueva');
    var boton = document.getElementById('cat-agregar');
    var aviso = document.getElementById('cat-aviso');
    var lista = document.querySelector('.catlist');

    function decir(texto, esError) {
      aviso.textContent = texto;
      aviso.classList.toggle('hint-warn', !!esError);
      aviso.hidden = false;
    }

    function agregar() {
      var nombre = campo.value.trim();
      if (nombre === '') { return; }

      boton.disabled = true;

      var datos = new FormData();
      datos.append('_token', csrfToken());
      datos.append('nombre', nombre);

      fetch(caja.dataset.endpoint, { method: 'POST', body: datos, credentials: 'same-origin' })
        .then(function (res) { return res.json().then(function (j) { return { ok: res.ok, j: j }; }); })
        .then(function (r) {
          boton.disabled = false;

          if (!r.ok) { decir(r.j.error || 'No se pudo crear.', true); return; }

          var yaEsta = lista.querySelector('input[value="' + r.j.id + '"]');

          if (yaEsta) {
            yaEsta.checked = true;
            decir('«' + r.j.name + '» ya existía: quedó marcada.');
          } else {
            var etiqueta = document.createElement('label');
            etiqueta.className = 'chk';

            var casilla = document.createElement('input');
            casilla.type = 'checkbox';
            casilla.name = 'categories[]';
            casilla.value = r.j.id;
            casilla.checked = true;

            etiqueta.appendChild(casilla);
            etiqueta.appendChild(document.createTextNode(' ' + r.j.name));
            lista.appendChild(etiqueta);

            var vacio = document.getElementById('cat-vacio');
            if (vacio) { vacio.remove(); }

            decir('Agregada. Se guarda con la entrada.');
          }

          campo.value = '';
        })
        .catch(function () {
          boton.disabled = false;
          decir('Se cortó la conexión.', true);
        });
    }

    boton.addEventListener('click', agregar);

    // Enter en el campo agrega la categoría, no envía la entrada entera.
    campo.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); agregar(); }
    });
  })();

  // ---- Enlace público: copiar, y mantenerlo al día con el slug.
  //
  // Es el enlace que se pega en el programador de LinkedIn o Instagram antes
  // de que la entrada salga, así que tiene que mostrar siempre el definitivo
  // y no el que había al abrir el formulario.
  (function permalink() {
    var field = document.getElementById('permalink');
    var copy = document.getElementById('copy-permalink');
    var slugField = document.getElementById('slug');
    var titleField = document.getElementById('title');
    if (!field) { return; }

    function refresh() {
      var slug = (slugField && slugField.value) || '';
      if (slug === '' && titleField) { slug = slugify(titleField.value); }
      field.value = field.dataset.base + (slug || 'se-genera-del-titulo');
    }

    if (slugField) { slugField.addEventListener('input', refresh); }
    if (titleField) { titleField.addEventListener('input', refresh); }

    if (copy) {
      copy.addEventListener('click', function () {
        var done = function () {
          var original = copy.textContent;
          copy.textContent = copy.dataset.copied;
          setTimeout(function () { copy.textContent = original; }, 1600);
        };

        // navigator.clipboard necesita contexto seguro; en http:// local no
        // existe, así que queda la selección del campo como alternativa.
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(field.value).then(done);
          return;
        }

        field.removeAttribute('readonly');
        field.select();
        field.setSelectionRange(0, field.value.length);
        try { document.execCommand('copy'); done(); } catch (e) { /* queda seleccionado */ }
        field.setAttribute('readonly', 'readonly');
      });
    }
  })();

  // ---- El botón dice lo que va a pasar: publicar o programar.
  (function publishLabel() {
    var btn = document.getElementById('publish-btn');
    var date = document.getElementById('published_at');
    if (!btn || !date) { return; }

    // dd/mm/aaaa hh:mm → Date. new Date("04/03/2026") lo leería como 3 de
    // abril, que es el mismo error que se arregló del lado del servidor.
    function leer(texto) {
      var m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[ ,]+(\d{1,2}):(\d{2}))?$/.exec(texto.trim());
      if (!m) { return null; }
      return new Date(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0));
    }

    function refresh() {
      var when = leer(date.value);
      var future = when !== null && when.getTime() > Date.now() + 60000;
      btn.textContent = future ? btn.dataset.programar : btn.dataset.publicar;
    }

    date.addEventListener('input', refresh);
    date.addEventListener('change', refresh);
    refresh();
  })();

  // ---- Slug sugerido a partir del título, solo si está vacío.
  var title = document.getElementById('title');
  var slug = document.getElementById('slug');

  // El rango de marcas combinantes se arma por código: así el archivo queda
  // en ASCII puro y no depende de que el servidor mande el charset correcto.
  var COMBINING = new RegExp(
    '[' + String.fromCharCode(0x300) + '-' + String.fromCharCode(0x36f) + ']', 'g'
  );

  function slugify(text) {
    return text
      .toLowerCase()
      .normalize('NFD')
      .replace(COMBINING, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 120);
  }

  if (title && slug) {
    var touched = slug.value !== '';
    slug.addEventListener('input', function () { touched = true; });

    title.addEventListener('input', function () {
      if (touched) { return; }
      slug.value = slugify(title.value);
    });
  }
})();
