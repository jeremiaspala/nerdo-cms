# Bitácora — Nerdo CMS

Registro de qué se hizo, por qué, y qué sigue. Para retomar sin releer código.

---

## Cómo retomar

**Repositorio público**: https://github.com/jeremiaspala/nerdo-cms
(`origin/main`). El `.env`, las credenciales de desarrollo, el volcado de
WordPress y `public/uploads/` quedan fuera por `.gitignore`: tienen secretos
o datos personales de quienes comentaron.

El Apache local ya sirve el proyecto en el puerto 8080
(`/etc/apache2/sites-available/nerdadas.conf`). Alcanza con que esté
levantado:

```bash
sudo systemctl reload apache2
```

El `DocumentRoot` apunta a `public/` y no a la raíz del proyecto, así que
**no puede ir como `Alias /nerdadas` dentro del vhost por defecto**: todas las
rutas absolutas del CMS (`/panel`, `/uploads`, `/assets`) caerían fuera de la
aplicación. Para el servidor embebido de PHP, que sigue sirviendo:

```bash
php -S 127.0.0.1:8080 -t public bin/router.php
```

- **Sitio**: http://localhost:8080
- **Panel**: http://localhost:8080/panel
- **Credenciales locales**: `storage/CREDENCIALES-DEV.txt` (chmod 600) — **borrar antes de desplegar**
- **Base del CMS**: `nerdadas_cms` · **Base origen WordPress**: `nerdadas_wp_src`

### Google OAuth: qué hay creado y qué tocar en producción

Configurado el 15/11/2026 desde la consola, con la cuenta de Jeremías.

| Qué | Dónde |
|---|---|
| Proyecto | **Nerdadas** · `nerdadas-1744824396277` |
| Pantalla de consentimiento | Google Auth Platform · app **«Nerdadas!»**, tipo **Externo**, estado **Prueba** |
| Cliente OAuth | **«Nerdo CMS»**, tipo *Aplicación web* |
| Usuario de prueba | jeremiaspalazzesi@gmail.com (único) |
| Credenciales | Cargadas en el panel, en Configuración → Google. El secreto se guarda cifrado con la `APP_KEY` |

URI de redirección que tiene cargadas hoy (solo desarrollo):

```
http://localhost:8080/panel/entrar/google/vuelta
http://localhost:8080/comentarios/google/vuelta
```

**Al pasar a producción hay que hacer tres cosas, en este orden:**

1. **Agregar las URI del dominio real** al mismo cliente «Nerdo CMS»
   (Google Auth Platform → Clientes → Nerdo CMS → URI de redireccionamiento).
   Google compara la URL completa carácter por carácter, así que importa si
   es con o sin `www`, y la ruta del panel tiene que ser la real —la de
   `ADMIN_PATH`, que en producción no debería ser `/panel`—:

   ```
   https://EL-DOMINIO/LA-RUTA-DEL-PANEL/entrar/google/vuelta
   https://EL-DOMINIO/comentarios/google/vuelta
   ```

   Las de localhost pueden quedar: no molestan y sirven para seguir
   probando.

2. **Publicar la app** (Google Auth Platform → Público → «Publicar app»).
   Mientras esté en **Prueba**, solo las cuentas de la lista de usuarios de
   prueba pueden entrar — hoy, la de Jeremías—. Eso alcanza para el panel,
   pero **no para que cualquiera comente con su cuenta**: para eso hay que
   publicarla. Publicar exige completar la página de marca (logo, página
   principal, política de privacidad y términos), y esas URL tienen que ser
   públicas y del dominio real, así que no se puede hacer desde localhost.
   Con los permisos que pide el CMS —`openid`, `email`, `profile`— no hace
   falta que Google verifique la app.

3. **Volver a cargar el ID y el secreto** en el panel de producción
   (Configuración → Google): el `.env` de producción tiene otra `APP_KEY`,
   y el secreto está cifrado con la de acá, así que no se puede copiar la
   fila de la base.

Si alguna vez hay que rotar el secreto: Clientes → Nerdo CMS → «Agregar
secreto», se pega el nuevo en el panel y recién después se borra el viejo.

### Material de importación (`storage/import/`)

| Archivo | Qué es |
|---|---|
| `nerdadas_wp.sql.gz` | Volcado de WordPress sin las 970 revisiones (509 KB) |
| `uploads-originales.txt` | Lista de los 781 originales, para re-rsync |
| `head-back.jpg` | Imagen del encabezado original |

Las **781 imágenes originales (234 MB)** están en un directorio temporal que
el sistema puede limpiar solo. No es grave: ya están importadas y procesadas
en `public/uploads`. Si hiciera falta re-importar desde cero:

```bash
rsync -az --files-from=storage/import/uploads-originales.txt \
  -e ssh nerdadas:public_html/blog/wp-content/uploads/ /ruta/destino/
```

---

## Estado

| Fase | Qué incluye | Estado |
|---|---|---|
| 1 | Núcleo: Kernel, Router, Db, Session, Csrf, Security, RateLimit, View | hecho |
| 2a | Login argon2id + bloqueo, panel, CRUD entradas y páginas, editor Quill, saneador | hecho |
| 2b | Galería, subida con reprocesado, destacada, srcset, og:image | hecho |
| 4 | Importador de WordPress | hecho |
| 5 | feed.xml, sitemap.xml, robots.txt, JSON-LD | hecho |
| 7 | Analítica propia sin cookies + panel con gráfico | hecho |
| — | Configuración por secciones, correo mail()/SMTP, encabezado con foto, video embebido | hecho |
| 6 | Comentarios: frente, antispam en capas, cola de moderación | hecho |
| 3 | Caché de página + fuentes autoalojadas | hecho |
| 8 | Instalador, TOTP, sesiones revocables, fail2ban | hecho |
| — | Formulario de contacto | hecho |

**Contenido migrado**: 145 entradas publicadas + 22 borradores, 4 páginas,
28 categorías, 49 comentarios, 554 imágenes, 342 redirecciones 301.
Rango: 2011-10-14 → 2026-11-14.

---

## Próximos pasos, en orden

### 1. Despliegue

Recién cuando lo anterior esté. El sitio nuevo en un subdominio, los dos
conviviendo unos días, comparar peticiones y errores, y después mover el DNS.

---

## Decisiones que no conviene revertir

- **Un solo punto de entrada.** Todo pasa por `public/index.php`; el resto
  vive sobre el `DocumentRoot`.
- **CSP sin `unsafe-inline`.** Por eso no hay un solo `style=""` ni
  `onclick=""` en las plantillas, ni en el panel. Si algo necesita un ancho
  variable, va por clase (ver `.bartrack i.w*`).
- **`frame-src` solo a `youtube-nocookie.com` y `player.vimeo.com`**, y el
  saneador aplica el mismo recorte con `SafeIframe`. Dos barreras para lo
  único de terceros que entra a la página.
- **El saneado es al guardar, no al mostrar.** `body_html` es lo único que se
  imprime sin escapar.
- **Las imágenes se re-codifican con GD**, nunca se guardan como llegan, y el
  nombre sale del SHA-256.
- **PHP y la base comparten zona horaria**, fijada por conexión en `Db`.
- **El visitante anónimo no recibe cookie** — condición para que el caché de
  página sirva a todos por igual.
- **Si el encabezado tiene foto, la foto manda.** El velo es neutro y cubre
  solo la franja izquierda, que es donde va el texto; del 72% del ancho para
  la derecha la imagen se ve tal cual. Lo que hace legible el título es el
  `text-shadow`, no el velo. El degradé verde al 82% sobre toda la imagen
  —como estaba— es lo mismo que no tener imagen.
- **La fecha va dentro de `visitor_hash`**: es lo que hace que la analítica no
  sea seguimiento y no necesite banner.
- **El formulario de comentarios no usa token de sesión.** Usa un desafío
  firmado con la `APP_KEY`, emitido por `GET /comentarios/desafio`. Un token
  CSRF clásico vive en la sesión, la sesión implica cookie, y hay dos reglas
  que lo impiden: el visitante anónimo no recibe cookie y la página se sirve
  cacheada igual para todos (un token de sesión incrustado en el HTML sería
  el token de otra persona). Falsificar el desafío exige la `APP_KEY`.
- **El desafío resuelto se gasta una sola vez** (`comment_nonces`, con UNIQUE).
  Es el precio de no guardar nada al emitirlo.
- **Los comentarios se escriben en texto plano**, se escapan y recién ahí se
  convierten a HTML. El visitante no puede mandar etiquetas, y las URLs del
  cuerpo no se convierten en enlaces: es la mitad del incentivo del spam.
- **Sin Gravatar.** El avatar son iniciales dibujadas con CSS. Pedirle la
  imagen a un tercero filtra, en cada visita y por cada lector, el hash de
  correo de quien comentó y la IP de quien lee, y obligaría a abrir `img-src`.
- **Quien pide JSON recibe JSON, también en los errores** (`Kernel::wantsJson`).
- **El caché de página existe porque el visitante anónimo no recibe cookie.**
  Es la misma decisión mirada al revés: sin marca por visitante, una sola
  copia del HTML sirve para todos. Si algún día se agrega algo personalizado
  por visitante, hay que sacarlo del HTML cacheado o el caché se convierte en
  una fuga.
- **El nonce no se guarda en el caché.** Se reemplaza por una marca al
  guardar y por el nonce de este request al servir. Un nonce cacheado es un
  nonce fijo, y un nonce fijo no es un nonce.
- **ETag solo donde no hay nonce** (feed, sitemap, robots). Una etiqueta que
  dijera «idéntico» sobre HTML que cambia en cada request mentiría.
- **La analítica se sigue contando en un acierto de caché**: el id de entrada
  viaja en la cabecera del archivo cacheado. Si no, encender el caché haría
  caer la medición a cero y parecería un problema de medición.
- **El formulario de contacto guarda y además manda.** Contact Form 7 solo
  manda: si el correo falla, el mensaje no existió para nadie —ni para quien
  lo escribió, que vio un «gracias»—. El correo es el aviso; la tabla es el
  soporte.
- **No se le contesta nada automático a quien escribe.** Un acuse al
  remitente convierte el formulario en una forma de mandarle correo a
  cualquiera desde este servidor.
- **El From del correo es siempre el del sitio, nunca el de quien escribió.**
  Falsificar el remitente es lo que hace que SPF y DMARC manden el aviso a
  spam. Para contestar está Reply-To.
- **En contacto el correo sí se guarda en claro**, al revés que en los
  comentarios: ahí es un dato de más que solo sirve para reconocer a alguien,
  acá es la dirección a la que hay que contestar. Guardar un hash sería
  guardar algo inservible.
- **Quien comenta con Google no recibe cookie.** La identidad viaja en un
  vale firmado que guarda el navegador, no en una sesión. Si algún día se
  cambia por una cookie, el caché de página deja de servirle la misma copia
  a todo el mundo y hay que replantear la fase 3 entera.
- **Google dice quién sos, no si podés entrar.** Para el panel, la cuenta
  tiene que existir en el CMS. Sin esa regla, cualquiera con una cuenta de
  Gmail se da de alta solo.
- **La bitácora no guarda IPs en claro**, igual que la analítica: va el HMAC.
  Sirve para ver que dos accesos vinieron del mismo lado y no para ubicar a
  nadie.
- **Las tipografías se autoalojan.** Google Fonts no entra en `font-src
  'self'` y, además, le entrega a Google la IP y el user-agent de cada lector
  en cada visita — exactamente lo que se sacó al reemplazar Analytics.

---

## Bitácora

### 2026-11-14

**Migración y decisión de plataforma.** Se evaluó dejar WordPress. Se
descartaron Hugo/Astro: el stack propio en PHP conserva Apache, PHP y MariaDB,
que es lo que ya administra Jeremías. Tema elegido: **Teal**, la variante más
cercana a la estética actual.

**Fases 1 a 7, salvo 3 y 6.** Núcleo, panel con editor WYSIWYG, medios,
importador, SEO y analítica.

**Bugs encontrados y corregidos** (vale tenerlos escritos, varios eran
silenciosos):

| Bug | Consecuencia si no se veía |
|---|---|
| PHP y MariaDB en zonas horarias distintas | Entradas programadas invisibles, sesiones y límites de tasa desfasados |
| `LIMIT :limit` como string con `EMULATE_PREPARES=false` | Error de sintaxis SQL |
| `HEAD` devolvía 405 | Rompe monitores y rastreadores |
| Cada request anónimo al panel creaba fila en `sessions` | Llenar la tabla con un bucle de curl |
| Sesiones sin `user_id` | No se podían revocar |
| `HTML.Doctype => 'HTML 5'` no existe en HTMLPurifier | Borraba las 122 `<figure>` sin avisar |
| 64 entradas con enlaces al WordPress viejo | Morían al apagar WordPress |
| `URI.DisableExternalResources` bloqueaba también el iframe de video | Sin YouTube |
| `AutoFormat.RemoveEmpty` borraba los `<iframe>` | Sin YouTube |
| `style=""` en el panel | Bloqueado por la propia CSP |
| `hidden` no oculta en SVG | Puntos sueltos en el gráfico |
| Eje Y en 38/75/113/150 | Ilegible |

**Hallazgo fuera de alcance**: el puerto **3306 de nerdadas.com acepta
conexiones TCP desde internet**. El control de acceso rechaza por host, así
que no es una puerta abierta, pero filtra la versión del servidor y convierte
cualquier error futuro de permisos en un incidente. Conviene filtrarlo.

**Plugins de WordPress que este CMS reemplaza**: `advanced-google-recaptcha`
(hecho, sin Google), `autodescription` (hecho), `ga-google-analytics` (hecho),
`wp-super-cache` (fase 3), `wps-hide-login` (hecho, ruta configurable),
`contact-form-7` (pendiente).

**Datos de prueba**: se generaron 1.722 visitas falsas para probar el gráfico
de analítica y **se borraron**. El panel está en cero a propósito.

### 2026-11-14 (segunda parte)

**Fase 6, comentarios.** Completa: frente, antispam, moderación.

Archivos nuevos: `app/Services/Spam.php`, `app/Models/Comment.php`,
`app/Controllers/Front/CommentController.php`,
`app/Controllers/Admin/CommentsController.php`,
`themes/teal/templates/comments.php` y `comment.php`,
`app/Admin/views/comments.php`, `public/assets/js/comments.js`,
`migrations/003_comments.sql`.

Las ocho guardas, en orden, son las que estaban planificadas: desafío firmado
(hace de CSRF) → límite de tasa → campo trampa → piso de tiempo de 8 s →
origen → prueba de trabajo → validación → puntaje. El puntaje queda guardado
con sus razones en texto, visibles en el panel.

**La prueba de trabajo lleva un SHA-256 propio en JavaScript.** La duda que
estaba anotada quedó resuelta a favor de la implementación síncrona:
`crypto.subtle` devuelve una promesa por hash y 262.000 promesas cuestan
segundos, no milisegundos. Como el mensaje es siempre de un bloque (nonce de
32 caracteres más un contador), la compresión entra en cincuenta líneas.
Medido: **547.000 hashes por segundo**, o medio segundo a 18 bits, y
verificado contra el SHA-256 de Node en 500 entradas al azar.

**Bugs encontrados y corregidos:**

| Bug | Consecuencia si no se veía |
|---|---|
| `php_admin_flag` en `public/uploads/.htaccess` | Apache no lo admite en un .htaccess: **500 en todas las imágenes del sitio**. La capa 1 se movió al vhost y en el .htaccess quedó `php_flag` |
| El 429 del límite de tasa devolvía HTML a un endpoint JSON | El formulario mostraba «se cortó la conexión» en lugar del motivo real |
| `[hidden]` lo pisaba cualquier `display` explícito | El «Cancelar la respuesta» se veía siempre. Misma familia que el bug del gráfico |
| `css` y `js` servidos `immutable` por un año, sin hash en el nombre | Un cambio de estilo no le llega nunca a quien ya visitó. Se agregó `asset()` con la marca de tiempo del archivo |
| El importador leía `comment_parent` y no lo usaba | Los 49 comentarios importados quedaron planos: 10 eran respuestas. Se corrigió el importador y se reconstruyeron los `parent_id` verificando autor y fecha uno por uno |

**Probado**: envío correcto, reenvío del mismo desafío (409), campo trampa,
envío instantáneo, prueba de trabajo inválida, firma alterada, cuerpo vacío,
correo inválido, cuatro enlaces (85 puntos → spam), entrada fuera de la
ventana de 90 días, origen ajeno, los dos límites de tasa, hilo anidado,
moderación desde el panel de a uno y en lote, y el circuito completo en
Chrome: desafío, resolución en 0,6 s, publicación directa de un comentarista
conocido e inserción en el lugar correcto del hilo sin recargar.

### 2026-11-14 (tercera parte)

**Fase 3: caché de página y tipografías propias.**

`app/Services/PageCache.php` guarda el HTML de los visitantes anónimos en
`storage/cache/pages/` y lo devuelve antes del router. Reemplaza a
`wp-super-cache`. La clave es la ruta más el número de página; cualquier otro
parámetro de query deja el request fuera del caché en vez de crear una clave
nueva, que es como se llena un disco gratis. Nunca se guarda ni se sirve si
hay cookie de sesión, y las rutas cacheables son una lista explícita.

**Medido**, con conexión reusada para que no lo tape el arranque de curl:

| | mediana | p90 |
|---|---|---|
| Entrada sin caché | 5,2 ms | 6,7 ms |
| Entrada desde el caché | 2,2 ms | 3,3 ms |
| Archivo estático (piso de Apache) | 0,2 ms | 0,4 ms |

Invalidación: vaciado completo al publicar, editar, mandar a papelera,
restaurar, cambiar un ajuste o tocar una imagen; selectivo —solo la entrada
afectada— al moderar comentarios, que es lo que más pasa.

**Tipografías**: Inter y Montserrat salen del propio dominio. Inter va como
fuente variable con el eje de peso recortado a 400–800 y el óptico fijo en
16: **70 KB cubren todos los pesos**, contra 149 KB sin recortar y 111 KB por
cada peso estático sin subsetear. De Montserrat quedaron los dos pesos que el
tema usa (700 y 800). Todo subseteado a latin + latin-ext con fontTools, sin
paso de compilación en el proyecto: los woff2 ya están en
`public/assets/fonts/` con sus licencias.

**Bugs y hallazgos:**

| Qué | Consecuencia |
|---|---|
| `shareViewDefaults()` corría en el constructor del Kernel | Dos consultas (ajustes y categorías) en **cada** request, incluidos los que se sirven del caché sin renderizar nada. Ahora se arma solo al renderizar un error: bajó también el camino sin caché, de 6,6 a 5,2 ms |
| Una entrada programada no dispara ninguna invalidación | Se publica sola cuando pasa su fecha y la portada cacheada la seguiría ocultando hasta que venza el TTL. Se agregó `cache:refresh`, que va por cron y vacía solo si algo se publicó en el intervalo |
| La primera visita se iba sin ETag | `PageCache::store()` ahora devuelve la respuesta con la etiqueta ya puesta, así el primer lector de feeds ya puede revalidar |

**Probado**: acierto y fallo, sustitución del nonce en requests sucesivos
(CSP y HTML coinciden siempre), 304 contra `If-None-Match` por acierto y por
fallo, que no se cachee nada con sesión, ni `/buscar`, ni un 404, ni
`/salud`, ni con `?utm_source=`, que sí se cacheen portada, entrada,
categoría, paginación, feed y sitemap conservando su Content-Type, la
invalidación al aprobar un comentario, las redirecciones 301 de WordPress
intactas, y en Chrome: sin violaciones de CSP sobre una página servida del
caché, los tres JSON-LD con nonce válido, TTFB de 9 ms y las fuentes bajando
del propio dominio (70 + 34 + 33 KB).

### 2026-11-14 (cuarta parte)

**Formulario de contacto.** Reemplaza a Contact Form 7, que era el último
plugin de la lista.

El formulario no tiene ruta propia: se dibuja dentro de la página que diga
`settings.contact_page` (por defecto `contacto`, que es la URL que ya estaba
indexada), debajo del texto que se edite ahí como en cualquier página. La
página había quedado vacía en la importación porque solo tenía el shortcode.

Mismas ocho capas que los comentarios. El alcance del desafío firmado pasó a
ser genérico: el id de la entrada para comentar, `0` para contacto. **Un
desafío emitido para comentar no sirve para mandar un mensaje** —probado— y
al revés, porque el alcance viaja adentro de la firma.

**Refactor**: el SHA-256 y el solucionador salieron de `comments.js` a
`public/assets/js/pow.js`, que es donde la bitácora los había planeado desde
el principio. Los dos formularios lo cargan; el resto del sitio sigue sin una
línea de JavaScript. Verificado de nuevo contra el SHA-256 de Node en 500
entradas al azar, y los comentarios siguen funcionando en Chrome.

**Lo que se guarda y lo que se manda**: el mensaje entra en `messages` y
además sale un correo de aviso con `Reply-To` apuntando a quien escribió. Si
el correo falla —`mail()` devuelve false sin lanzar nada, que es la forma más
común de perder un mensaje sin enterarse— queda en el log y la bandeja lo
muestra con el cartel «no se pudo avisar por correo». Acá en local no hay MTA
y el camino se probó justamente así.

Bandeja nueva en el panel (`/panel/mensajes`): nuevos, leídos, archivados y
spam, acciones de a uno o en lote, contador en la barra y en el escritorio.
Contestar se contesta por `mailto:` desde el cliente de correo; meter un
editor de respuestas sería construir medio webmail.

**Probado**: envío correcto desde curl y desde Chrome, campo trampa, mensaje
demasiado corto, correo inválido, **salto de línea en el asunto** (inyección
de cabeceras: cortado antes del Mailer), desafío de comentarios reusado en
contacto (400), envío instantáneo, cuatro enlaces (50 puntos → spam, y a
quien lo mandó se le contesta lo mismo que a cualquiera), y en el panel:
marcar leído de a uno con el contador bajando.

### 2026-11-14 (quinta parte)

**El CMS pasó a llamarse Nerdo CMS.** Antes era «Nerdadas CMS», que mezclaba
el nombre del blog con el del software. Renombrado en el panel, el login, la
cabecera `X-Mailer`, el user-agent del importador, el `HTML.DefinitionID` de
HTMLPurifier (se vació su caché a mano para que reconstruya la definición) y
la documentación. El título del sitio —«Nerdadas!»— no se tocó: es el blog,
no el CMS.

**Repositorio público**: https://github.com/jeremiaspala/nerdo-cms

Se publicaron 113 archivos. Antes de subir se verificó, archivo por archivo
de lo que estaba en el índice, que no fueran: el `.env`, las credenciales de
desarrollo, el volcado de WordPress (tiene los correos de quienes comentaron
en diez años) ni las 554 imágenes. Todo eso ya estaba cubierto por
`.gitignore`.

Se agregó `.env.example` con los catorce ajustes y, sobre todo, con el
**porqué** de los dos secretos: si `HASH_PEPPER` cambia, la analítica pierde
la continuidad de visitantes y los comentarios dejan de reconocer a quien ya
comentó.

**Capturas** en `docs/capturas/`, enlazadas desde el README: portada, entrada
con hilos, contacto, escritorio, editor, moderación y analítica. Las de
moderación y analítica se sacaron con datos de ejemplo generados para eso
—dos comentarios, un mensaje y 9.158 visitas— y **se borraron después**, como
ya se había hecho con los datos de prueba del gráfico. El panel vuelve a
estar en cero a propósito.

**Pendiente de decidir**: el repositorio no tiene licencia, así que por
defecto es «todos los derechos reservados». Si la idea es que alguien más lo
pueda usar, hay que agregar un `LICENSE`.

### 2026-11-14 (sexta parte)

**Licencia MIT** y **encabezado con foto arreglado.**

El encabezado tapaba la imagen con el degradé verde de marca al 82%: se veía
una mancha verde con una textura adentro, que es justamente lo que el tema de
WordPress no hacía —ahí la foto va a tamaño completo y sin nada encima—. Se
reemplazó por un velo neutro, horizontal, sobre la franja izquierda donde va
el texto; a partir del 72% del ancho no hay nada. El ajuste «Intensidad del
degradé» pasó a llamarse «Velo detrás del título» y ahora regula eso.

Las capturas 01 y 03 del repositorio se rehicieron con el encabezado nuevo.

### 2026-11-14 (séptima parte)

**Ancho completo y barra lateral**, como el tema que se reemplaza.

El contenido estaba en una columna de 1180 px centrada: en una pantalla de
1900 quedaban 360 px de aire a cada lado. Ahora el armazón es de dos
columnas (`.shell`) con tope en 1800 px —el tope existe porque a partir de
ahí las líneas se vuelven ilegibles— y una barra lateral de 340 px. Abajo de
1080 px la barra pasa debajo del contenido y deja de ser sticky.

La entrada se centra dentro de su columna: pegada a la izquierda dejaba un
hueco en el medio que parecía un error de maquetado.

**Las tres pestañas de la barra —Últimas, Populares, Tendencias— no llevan
JavaScript**: son tres radios ocultos y tres labels, y el panel visible lo
decide el CSS con `:checked ~`. Funciona con teclado, que es el
comportamiento nativo de un grupo de radios. El widget equivalente en
WordPress cargaba jQuery y los tabs de Bootstrap en todas las páginas.

«Populares» (90 días) y «Tendencias» (7 días) salen de `Post::mostViewed()`,
que suma el agregado de `stats_daily` con el detalle de `hits` posterior al
último día agregado —si no, un día recién agregado se contaría dos veces—.
En WordPress esas dos listas las llenaba un plugin que vuelve a contar las
visitas por su cuenta.

**De paso, un N+1 que estaba desde el principio**: los listados pedían las
categorías de a una entrada por vez (diez entradas, diez consultas) y la
barra lateral iba a sumar quince más. `Post::withCategories()` las trae todas
en una. Medido en la portada sin caché: 8,0 ms con la barra lateral incluida,
contra 5,2 ms que costaba antes sin barra. Desde el caché, 1,7 ms.

### 2026-11-14 (octava parte)

**La grilla cierra en cualquier pantalla.** Con `auto-fill` la cantidad de
columnas salía de una cuenta entre el ancho disponible y el mínimo de la
tarjeta: daba 4 en una pantalla y 5 en otra, y la última fila quedaba con
huecos. Ahora las columnas son fijas por tamaño —4, 3, 2 o 1— y la página
trae múltiplos de 12, que es divisible por todas. La portada pide una de más
porque gasta la primera en la destacada de arriba.

De paso se arregló un ajuste que **no hacía nada**: `posts_per_page` existía
en Configuración desde la fase 2 y los controladores usaban una constante.
Ahora se usa de verdad, y pasó a ser una lista (12 / 24 / 36) en vez de un
número libre, justamente para que la grilla siga cerrando.

**El menú del panel pasó a una barra lateral izquierda con íconos.** Los
íconos son SVG en línea dibujados en `app/Admin/icons.php`: una tipografía de
íconos son 70 KB para usar diez glifos, y cualquier CDN de íconos es un
dominio de terceros más en una CSP que hoy no tiene ninguno. Heredan el color
del texto con `currentColor`, así que el estado activo no necesita reglas
aparte. Abajo de 900 px la barra se convierte en una fila horizontal que se
desplaza y quedan solo los íconos.

La sección activa se marca con color **y** con una barra a la izquierda: el
color solo no alcanza para quien no lo distingue.

### 2026-11-14 (novena parte)

Tanda de pedidos chicos del panel y del tema:

- **Categoría nueva desde la entrada que se está escribiendo.** Antes no se
  podía desde ningún lado: las 28 categorías venían de la importación.
  Renombrar y borrar siguen sin estar, y es a propósito: borrar una
  categoría que ya está en una URL rompe enlaces, así que va a necesitar su
  301 igual que el slug de una entrada.
- **Fechas en dd/mm/aaaa** en todo el panel. El campo de publicación dejó de
  ser `<input type="datetime-local">`: ese lo dibuja el navegador con el
  formato de SU idioma, no el de la página, así que en un Chrome en inglés
  pedía 11/14/2026. Ahora es un campo de texto con el formato fijado por el
  CMS, y lo lee `fecha_ar_a_sql()`. **Ojo con `strtotime`**: lee 03/04/2026
  como el 4 de marzo, así que una entrada agendada para el 3 de abril habría
  salido un mes antes sin que nadie se enterara.
- **Miniatura de la imagen destacada** en el listado de entradas, con la
  variante de 400 px que ya se genera al subir. Las que no tienen quedan
  marcadas con una trama, que también es información.
- **«Hecho con Nerdo CMS» en el pie**, apuntando al repositorio. Va ahí y no
  en un `<meta name="generator">`: eso es lo primero que mira un escáner
  para saber qué versión atacar, y es justo lo que se le sacó a WordPress.

### 2026-11-14 (décima parte)

**Captcha en el login y recuperación de contraseña**, que son dos de las
cosas que faltaban de la fase 8.

El captcha es la misma prueba de trabajo que los comentarios y el contacto,
con el desafío embebido en el formulario (la página de login no se cachea
nunca, así que no hace falta pedirlo aparte). El alcance es −1 para el login
y −2 para la recuperación: un desafío emitido para comentar no sirve para
probar contraseñas. El piso de tiempo acá va en 0 a propósito —escribir
rápido no es sospechoso, y castigaría a quien usa un gestor de contraseñas—;
lo que frena al que prueba listas es el costo de CPU, no la espera.

**El desafío se gasta por intento.** Sin eso se resuelve una vez y se reusa
mil veces, que es exactamente lo que había que evitar.

La recuperación: enlace por correo, una hora de vida, un solo uso, y al
usarlo se queman los demás de esa cuenta. De la base no sale ningún enlace
usable, porque **se guarda el SHA-256 del token y no el token** —un enlace
pendiente es, por una hora, equivalente a la contraseña—. La respuesta es
siempre la misma exista o no la cuenta, igual que en el login.

**Un bug que apareció probando**: al cambiar la contraseña se borran todas
las sesiones de la cuenta, pero PHP vuelve a escribir la fila de la sesión
en curso al terminar el script. Resultado: la única sesión que sobrevivía a
un cambio de contraseña era justo la del navegador que lo hizo, mientras la
pantalla prometía lo contrario. Se cierra explícitamente antes de redirigir.

Probado: login con captcha, login con el captcha vacío y con uno inventado
(422 las dos veces), pedido de enlace para una cuenta que existe y para una
que no (misma respuesta), enlace usado dos veces (410 la segunda), token
inventado (410), y el cierre de las seis sesiones abiertas.

### 2026-11-14 (undécima parte)

**Fase 8 terminada**: instalador, segundo factor, sesiones revocables y
fail2ban.

El **instalador** (`public/install.php`) verifica requisitos, corre las
migraciones, crea el primer usuario, genera los dos secretos y se borra
solo. Probado de verdad contra una base descartable, y ahí apareció lo que
nunca se prueba hasta el peor momento: **en un servidor bien configurado PHP
no puede escribir en la raíz del proyecto**, así que no puede crear el
`.env` ni borrarse a sí mismo. No es un error, es la configuración correcta.
Ahora muestra el `.env` en pantalla para pegarlo y avisa en rojo que hay que
borrar el archivo a mano. También probado: segunda instalación sobre una
base con usuarios (se niega) y los seis controles de los datos del
formulario.

El **TOTP** son ochenta líneas propias, verificadas contra el vector de
prueba del RFC 6238 (secreto `12345678901234567890`, t=59 → 287082). El
secreto va cifrado con la `APP_KEY`. No se dibuja el QR: hacerlo con un
servicio de terceros sería mandarle el secreto a otro, y una librería de QR
es mucho código para una pantalla que se usa una vez. Se muestra el secreto
en grupos de cuatro y el enlace `otpauth://`, que en el teléfono abre la app
sola. La salida de emergencia es `php bin/console user:totp-off --email=…`.

**fail2ban lee el log de Apache y no el del CMS**, y esa es la parte que
había que pensar: el CMS no guarda IPs en claro, así que no tiene con qué
alimentar a fail2ban. El log del servidor web sí las tiene, porque es su
registro y no un dato que el CMS haya decidido almacenar. Quedaron escritos
los dos filtros y el jail en el README.

**Dos bugs de sesiones**, los dos de la misma familia —una sesión que
autentica pero no se puede ver ni cerrar—:

| Qué pasaba | Por qué |
|---|---|
| La fila perdía el `user_id` al recrearse | Si se borraban todas las sesiones de la cuenta mientras una estaba en uso, PHP la reescribía al terminar el script y el dueño se perdía |
| La marca de dueño casi nunca llegaba | Con `session.lazy_write`, una sesión sin cambios pasa por `updateTimestamp()` y no por `write()`, y ese camino no tocaba el `user_id`. Era el de la mayoría de los requests |

Y uno de modelo: `User::findById()` no traía `totp_secret`, así que el
segundo paso del login rechazaba códigos correctos. Ahora hay un método
aparte, que además evita cargar el secreto en memoria en cada request del
panel.

### 2026-11-15

**Bitácora de accesos, Google OAuth y comentarios con identidad.**

La **bitácora** ya se escribía desde la fase 2 pero solo se veían las últimas
diez en el escritorio. Ahora tiene pantalla propia con filtros por tipo
—acceso, contenido, moderación, alerta—, por persona y por fecha, y avisa
arriba si hubo intentos fallidos en las últimas 24 horas. Se limpia por
antigüedad con `audit:purge`: una bitácora que no se limpia nunca termina
siendo un registro de años que a nadie le sirve y a cualquiera que entre le
viene bien.

**Google** quedó apagado por defecto y se configura en su sección, que
muestra las dos URL de redirección listas para copiar en Google Cloud
Console. El client secret se guarda cifrado con la APP_KEY.

Dos decisiones que importan más que el código:

1. **Para el panel, la cuenta tiene que existir.** Google verifica el correo;
   quién puede entrar lo sigue decidiendo el CMS. Si la cuenta tiene segundo
   factor, se pide igual: Google confirma el correo, no que del otro lado
   esté quien dice ser.
2. **Quien comenta con Google no recibe cookie.** Era la parte difícil: una
   sesión para visitantes habría roto el caché de página, que es la fase 3
   entera. En su lugar, la vuelta de Google devuelve un vale firmado con la
   APP_KEY en el **fragmento** de la URL —que no viaja al servidor ni queda
   en ningún log—, el navegador lo guarda en localStorage y lo manda con el
   comentario. Dura ocho horas.

El `id_token` no se verifica contra el JWKS de Google y está bien que no:
no llega por el navegador sino que lo pide este servidor al endpoint de
Google por TLS, autenticándose con el client secret. Lo que se comprueba son
las afirmaciones (aud, iss, exp, email_verified).

**Probado sin credenciales reales**: la redirección lleva los parámetros
correctos y el `state` firmado; un `volver=https://evil.example` se descarta
y queda en `/` (sería un redirector abierto con cara de sitio conocido); el
vale válido entra como «cuenta verificada» y se publica sin moderar; el vale
adulterado **no da ningún privilegio** y cae a anónimo; con «solo Google»
configurado, el envío anónimo se rechaza con 403 y el formulario esconde los
campos de nombre y correo.

Lo que no se pudo probar acá es la vuelta real de Google, que necesita un
cliente OAuth de verdad: queda para cuando estén las credenciales.

### 2026-11-15 (segunda parte)

**Google OAuth configurado de verdad**, con la cuenta de Jeremías y desde la
consola. Lo que hay creado y lo que hay que tocar en producción está arriba,
en «Google OAuth: qué hay creado y qué tocar en producción», que es el lugar
donde se va a buscar.

Cosas que aparecieron al hacerlo:

- El proyecto **Nerdadas** ya existía en la consola, vacío. Se usó ese en vez
  de crear otro: la pantalla de consentimiento es por proyecto, así que meter
  el cliente en «Hal9000» habría hecho que a quien comenta le apareciera
  «Hal9000 quiere acceder a tu cuenta».
- Para crear credenciales hay que aceptar la **Política de Datos del Usuario
  de los Servicios de las APIs de Google**. Es un acuerdo legal a nombre del
  dueño de la cuenta: se preguntó antes de tildarlo y Jeremías lo autorizó.
- La app queda en **modo Prueba**, y en ese modo **solo entran las cuentas de
  la lista de usuarios de prueba**. Se agregó la de Jeremías. Esto alcanza
  para el panel pero no para la idea de que cualquiera comente con su
  cuenta: eso necesita publicar la app, y publicar necesita el dominio real.
- El usuario del CMS pasó de `jeremias@nerdadas.local` a
  **jeremiaspalazzesi@gmail.com**, que es lo que permite que el login con
  Google encuentre la cuenta. Sigue siendo el único usuario, y la contraseña
  no cambió. `storage/CREDENCIALES-DEV.txt` quedó actualizado.
- **La extensión del navegador no puede operar en `accounts.google.com`**, así
  que el paso de elegir la cuenta y aceptar los permisos lo tiene que hacer
  una persona. Era de esperar y está bien que sea así.

**El bug que apareció al probarlo de verdad, y que valía la pena encontrar
ahora y no en producción:** entrar con Google «no entraba». El login sí
funcionaba —quedaron dos `login.google` en la bitácora y dos sesiones con
`user_id` en la tabla—, pero el navegador volvía al formulario.

La causa es la cookie de sesión en **`SameSite=Strict`**, que es como la
queremos: el navegador no la manda en nada que venga de otro dominio. Al
volver de Google la cookie **se guarda**, pero el redirect que sigue todavía
es parte de una navegación empezada en `accounts.google.com`, así que no se
manda, y el panel ve a alguien sin sesión.

Se arregló con un salto más desde el propio dominio (`Response::landing()`):
una página mínima con `<meta http-equiv="refresh">` —no JavaScript, que
necesitaría nonce— y un enlace de respaldo. La alternativa era aflojar la
cookie a `SameSite=Lax`; se descartó porque esto resuelve el único caso que
lo necesita sin tocar el resto del sitio.

Vale para cualquier vuelta de otro dominio que tenga que dejar sesión
iniciada: si algún día se agrega otro proveedor, el salto va igual.

**Dos cosas que se cerraron antes de probar con Google de verdad:**

1. La comprobación del `id_token` se separó del intercambio del código
   (`GoogleOAuth::parseIdToken()`), así se puede probar sin hablar con
   Google. Probados los seis caminos: token para otra aplicación, emisor
   falso, vencido, correo sin verificar, correo ausente y malformado — todos
   rechazados. Un token sin nombre cae al usuario del correo, que es lo
   buscado.
2. Si la vuelta de Google falla o alguien cancela, ahora **se ve un aviso**
   arriba del formulario. Importa justo en modo Prueba: una cuenta que no
   está en la lista de usuarios de prueba vuelve sin nada y antes no pasaba
   nada visible, que es la peor forma de fallar.

Verificado también que con Google habilitado el circuito anónimo sigue
intacto: comentario enviado, guardado como `anonimo`, y el desafío
reutilizado rebota.
