/**
 * Editor por bloques.
 *
 * El editor lee los bloques de un <textarea> que el servidor ya escapó, y
 * antes de enviar el formulario escribe ahí el JSON actualizado. No hay
 * JavaScript en línea ni un JSON embebido en la página: la CSP no lo
 * admitiría y tampoco hace falta.
 *
 * Importante: las herramientas que se habilitan acá abajo son comodidad, NO
 * seguridad. Lo que decide qué HTML se publica es BlockRenderer, en el
 * servidor, que emite solo los bloques que conoce. Cualquiera puede mandar
 * un POST sin pasar por esta página.
 */
(function () {
  'use strict';

  var host = document.getElementById('editor');
  var field = document.getElementById('body_blocks');
  var form = document.getElementById('editor-form');

  if (!host || !field || !form || typeof EditorJS === 'undefined') {
    return;
  }

  var initial = { blocks: [] };

  try {
    var raw = field.value.trim();
    if (raw !== '') { initial = JSON.parse(raw); }
  } catch (e) {
    // Un JSON roto no puede dejar a la persona sin editor: arranca vacío y
    // el contenido viejo sigue en la base hasta que se guarde.
    window.console && console.error('Bloques ilegibles, el editor arranca vacío.', e);
  }

  /**
   * Subida de imágenes: usa el mismo endpoint y el mismo token CSRF que la
   * galería (ncmsUpload, en admin.js). La herramienta de imagen espera
   * {success: 1, file: {url}}.
   */
  var uploader = {
    uploadByFile: function (file) {
      return window.ncmsUpload(file, host.dataset.upload).then(function (m) {
        return { success: 1, file: { url: m.url, alt: m.alt || '' } };
      }).catch(function (err) {
        window.console && console.error(err);
        return { success: 0 };
      });
    }
  };

  var editor = new EditorJS({
    holder: host,
    placeholder: 'Escribí acá, o apretá Tab para elegir un bloque…',
    data: initial,
    minHeight: 240,

    // El editor viene en inglés; esto es lo que se ve mientras se escribe.
    i18n: {
      messages: {
        ui: {
          blockTunes: { toggler: { 'Click to tune': 'Opciones del bloque' } },
          inlineToolbar: { converter: { 'Convert to': 'Convertir en' } },
          toolbar: { toolbox: { Add: 'Agregar' } },
          popover: { Filter: 'Buscar', 'Nothing found': 'No hay nada con ese nombre' }
        },
        toolNames: {
          Text: 'Párrafo',
          Heading: 'Título',
          List: 'Lista',
          Quote: 'Cita',
          Code: 'Código',
          Image: 'Imagen',
          Embed: 'Video',
          Delimiter: 'Separador',
          Table: 'Tabla',
          Link: 'Enlace',
          InlineCode: 'Código en línea',
          Bold: 'Negrita',
          Italic: 'Itálica'
        },
        blockTunes: {
          delete: { Delete: 'Borrar', 'Click to delete': 'Confirmar' },
          moveUp: { 'Move up': 'Subir' },
          moveDown: { 'Move down': 'Bajar' }
        }
      }
    },

    tools: {
      header: {
        class: Header,
        inlineToolbar: true,
        config: {
          placeholder: 'Título',
          // h1 es el título de la entrada: el cuerpo empieza en h2.
          levels: [2, 3, 4],
          defaultLevel: 2
        }
      },
      list: { class: EditorjsList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
      quote: { class: Quote, inlineToolbar: true, config: { quotePlaceholder: 'Cita', captionPlaceholder: 'Quién lo dijo' } },
      code: { class: CodeTool, config: { placeholder: 'Código' } },
      image: {
        class: ImageTool,
        config: {
          uploader: uploader,
          captionPlaceholder: 'Pie de imagen (opcional)',
          buttonContent: 'Elegir una imagen'
        }
      },
      embed: {
        class: Embed,
        config: {
          // Los mismos dos de siempre: son los únicos que admite la CSP y el
          // renderizador del servidor.
          services: { youtube: true, vimeo: true }
        }
      },
      inlineCode: { class: InlineCode, shortcut: 'CMD+SHIFT+C' },
      delimiter: Delimiter,
      table: { class: Table, inlineToolbar: true, config: { withHeadings: true } }
    }
  });

  // El envío espera a que el editor termine de serializar. Sin esto, el
  // formulario puede salir con el JSON de antes del último cambio.
  var ready = false;

  form.addEventListener('submit', function (ev) {
    if (ready) { return; }

    ev.preventDefault();
    var button = ev.submitter;

    editor.save().then(function (data) {
      field.value = JSON.stringify(data);
      ready = true;

      // Se reenvía apretando el mismo botón, porque el nombre y el valor del
      // botón (status=draft o published) son parte de lo que se manda.
      if (button) { button.click(); } else { form.submit(); }
    }).catch(function (err) {
      window.console && console.error(err);
      ready = false;

      var aviso = document.getElementById('editor-error');
      if (aviso) {
        aviso.textContent = 'No se pudo leer el contenido del editor. Copiá lo que escribiste antes de recargar.';
        aviso.hidden = false;
      }
    });
  });
})();
