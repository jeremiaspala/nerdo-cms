# Editor.js, autoalojado y modificado

Archivos de [Editor.js](https://editorjs.io) y sus herramientas, servidos
desde este dominio. Ninguna página del sitio público los carga: solo el
editor del panel, que está detrás del login.

| Paquete | Versión | Licencia |
|---|---|---|
| `@editorjs/editorjs` | 2.31.7 | Apache-2.0 (`LICENSE-editorjs.txt`) |
| `@editorjs/header` | 2.8.9 | MIT (`LICENSE-herramientas.txt`) |
| `@editorjs/list` | 2.0.9 | MIT |
| `@editorjs/quote` | 2.7.6 | MIT |
| `@editorjs/code` | 2.9.4 | MIT |
| `@editorjs/image` | 2.10.3 | MIT |
| `@editorjs/embed` | 2.8.0 | MIT |
| `@editorjs/delimiter` | 1.4.2 | MIT |
| `@editorjs/table` | 2.4.6 | MIT |

## Qué se les cambió

La Apache-2.0 pide dejar constancia de las modificaciones, así que acá va.

Los `.umd.js` **no son los originales**: se les quitó el bloque que inyecta
el CSS creando un `<style>` desde JavaScript (`vite-plugin-css-injected-by-
js`). La CSP de este proyecto es `style-src 'self' 'nonce-…'`, sin
`unsafe-inline`, así que ese `<style>` se bloquea y el editor queda sin
estilos. El CSS que inyectaban está extraído en `editorjs.css`, que se
enlaza como cualquier hoja.

El núcleo guarda otras dos hojas como literal de plantilla y las inyecta
desde `loadStyles()`, que arranca con `if (document.getElementById(id))
return`. Esas quedaron intactas: el panel deja puestos dos `<style>` vacíos
con los id `editor-js-styles` y `codex-tooltips-style` —con nonce, que la CSP
sí admite— y el núcleo no inyecta nada. Su CSS también está en
`editorjs.css`.

Nada de esto cambia el comportamiento del editor.

## Cómo actualizarlos

```bash
# bajar los dist nuevos a un directorio aparte
php bin/vendor-editorjs.php /ruta/con/los/umd
```

El script hace las dos cosas de arriba y vuelve a generar `editorjs.css`. Si
algún paquete cambia la forma de inyectar, falla con un mensaje en vez de
dejar un editor sin estilos.
