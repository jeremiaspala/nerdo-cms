/**
 * Editor WYSIWYG.
 *
 * Quill arranca vacío y toma el HTML del <textarea>, que el servidor ya
 * escapó. Antes de enviar, se vuelca el contenido al mismo textarea.
 *
 * Importante: la lista de formatos de acá abajo es comodidad, NO seguridad.
 * Lo que de verdad decide qué HTML sobrevive es el saneador del servidor
 * (App\Services\HtmlSanitizer). Cualquiera puede mandar un POST sin pasar
 * por esta página.
 */
(function () {
  'use strict';

  var host = document.getElementById('editor');
  var field = document.getElementById('body_html');
  var form = document.getElementById('editor-form');

  if (!host || !field || !form || typeof Quill === 'undefined') {
    return;
  }

  var quill = new Quill(host, {
    theme: 'snow',
    placeholder: 'Escribí acá…',
    formats: [
      'bold', 'italic', 'underline', 'strike',
      'header', 'list', 'indent', 'align',
      'blockquote', 'code-block', 'code',
      'link', 'image'
    ],
    modules: {
      toolbar: [
        [{ header: [2, 3, 4, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['blockquote', 'code-block'],
        ['link', 'image'],
        [{ align: [] }],
        ['clean']
      ],
      // Pegar desde Word o desde una web no debe arrastrar estilos.
      clipboard: { matchVisual: false }
    }
  });

  // Insertar imagen: en vez del comportamiento por defecto de Quill, que
  // mete la imagen como data: URI dentro del HTML (infla la base y la CSP
  // lo bloquea), se sube al servidor y se inserta la URL del archivo.
  var fbox = document.getElementById('featured-box');
  var uploadEndpoint = fbox ? fbox.dataset.endpoint : null;

  if (uploadEndpoint && typeof window.ncmsUpload === 'function') {
    quill.getModule('toolbar').addHandler('image', function () {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/jpeg,image/png,image/webp,image/gif';

      input.addEventListener('change', function () {
        var file = input.files[0];
        if (!file) { return; }

        var range = quill.getSelection(true);
        quill.insertText(range.index, 'Subiendo imagen…', 'silent');

        window.ncmsUpload(file, uploadEndpoint).then(function (m) {
          quill.deleteText(range.index, 'Subiendo imagen…'.length, 'silent');
          quill.insertEmbed(range.index, 'image', m.url, 'user');
          quill.setSelection(range.index + 1, 0, 'silent');
        }).catch(function (err) {
          quill.deleteText(range.index, 'Subiendo imagen…'.length, 'silent');
          quill.insertText(range.index, '[no se pudo subir: ' + err.message + ']', 'user');
        });
      });

      input.click();
    });
  }

  // Carga inicial: el textarea trae el HTML guardado.
  var initial = field.value.trim();
  if (initial !== '') {
    quill.clipboard.dangerouslyPasteHTML(initial, 'silent');
  }

  function sync() {
    var html = quill.getSemanticHTML().trim();
    // Quill devuelve un párrafo vacío cuando no hay nada escrito.
    field.value = (html === '<p></p>' || html === '<p><br></p>') ? '' : html;
  }

  form.addEventListener('submit', sync);

  // Ctrl/Cmd + S guarda como borrador en lugar de abrir el diálogo del navegador.
  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') {
      ev.preventDefault();
      sync();
      var draft = form.querySelector('button[value="draft"]');
      if (draft) { draft.click(); }
    }
  });

  // Aviso al salir con cambios sin guardar.
  var dirty = false;
  quill.on('text-change', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (ev) {
    if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
  });
})();
