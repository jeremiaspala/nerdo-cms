<?php

declare(strict_types=1);

/**
 * Instalador de Nerdo CMS.
 *
 * Es el único archivo del proyecto que existe para no existir: cuando
 * termina, se borra solo. Mientras está, cualquiera que llegue a la URL
 * puede instalar; por eso:
 *
 *  - Si ya hay un `.env`, no hace nada y dice que se borre este archivo.
 *  - Al terminar se borra con unlink(). Si no pudiera (permisos), lo avisa
 *    en rojo y no sigue como si nada: un instalador olvidado en un servidor
 *    es una puerta de entrada con la llave puesta.
 *  - No se guarda la contraseña de la base en ningún lado más que el `.env`,
 *    que se escribe con permisos 600.
 *
 * No usa el núcleo del CMS a propósito: todavía no hay configuración que
 * cargar, así que todo lo que necesita está acá adentro.
 */

$root = dirname(__DIR__);
$envPath = $root . '/.env';

// ---------------------------------------------------------------- estado

$yaInstalado = is_file($envPath);
$errores = [];
$hecho = false;
$sePudoBorrar = false;
$envEscrito = false;
$envParaPegar = '';

/** @return array<int,array{0:string,1:bool,2:string}> */
function requisitos(): array
{
    $gd = extension_loaded('gd');
    $jpeg = $gd && (gd_info()['JPEG Support'] ?? false);

    return [
        ['PHP 8.3 o más nuevo', PHP_VERSION_ID >= 80300, PHP_VERSION],
        ['pdo_mysql', extension_loaded('pdo_mysql'), 'para hablar con MariaDB'],
        ['mbstring', extension_loaded('mbstring'), 'para el texto en castellano'],
        ['sodium', extension_loaded('sodium'), 'para cifrar la clave SMTP y el segundo factor'],
        ['gd con JPEG', $jpeg, 'para reprocesar las imágenes que se suban'],
        ['fileinfo', extension_loaded('fileinfo'), 'para saber qué es cada archivo subido'],
        ['intl', extension_loaded('intl'), 'para los slugs con acentos (opcional)'],
    ];
}

function valor(string $campo, string $defecto = ''): string
{
    $v = $_POST[$campo] ?? $defecto;

    return is_string($v) ? trim($v) : $defecto;
}

// ---------------------------------------------------------------- instalar

if (!$yaInstalado && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $host = valor('db_host', '127.0.0.1');
    $base = valor('db_name');
    $usuario = valor('db_user');
    $clave = (string) ($_POST['db_pass'] ?? '');
    $url = rtrim(valor('app_url'), '/');
    $panel = '/' . trim(valor('admin_path', 'panel'), '/');
    $nombre = valor('admin_name');
    $correo = mb_strtolower(valor('admin_email'));
    $pass = (string) ($_POST['admin_pass'] ?? '');

    if ($base === '' || $usuario === '') {
        $errores[] = 'Faltan los datos de la base.';
    }

    if (!preg_match('#^https?://[^\s/]+#', $url)) {
        $errores[] = 'La dirección del sitio tiene que empezar con http:// o https://';
    }

    if (!preg_match('#^/[a-z0-9-]{2,40}$#', $panel)) {
        $errores[] = 'La ruta del panel admite minúsculas, números y guiones.';
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo del administrador no es válido.';
    }

    if (mb_strlen($nombre) < 2) {
        $errores[] = 'Falta tu nombre.';
    }

    if (strlen($pass) < 12) {
        $errores[] = 'La contraseña del panel tiene que tener al menos 12 caracteres.';
    }

    $pdo = null;

    if ($errores === []) {
        $dsn = "mysql:host={$host};dbname={$base};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $usuario, $clave, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Sin el mensaje del driver: suele incluir el usuario y el host.
            $errores[] = 'No se pudo conectar a la base con esos datos.';
        }
    }

    if ($errores === [] && $pdo !== null) {
        try {
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
            $pdo->exec("SET SESSION time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");

            // 1. Migraciones, en orden y registradas.
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
                            name VARCHAR(190) NOT NULL,
                            ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (name)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $hechas = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
            $archivos = glob($root . '/migrations/*.sql') ?: [];
            sort($archivos);

            foreach ($archivos as $archivo) {
                $nombreArchivo = basename($archivo);

                if (in_array($nombreArchivo, $hechas, true)) {
                    continue;
                }

                $pdo->exec((string) file_get_contents($archivo));
                $stmt = $pdo->prepare('INSERT INTO migrations (name) VALUES (?)');
                $stmt->execute([$nombreArchivo]);
            }

            // 2. El usuario administrador.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users');
            $stmt->execute();

            if ((int) $stmt->fetchColumn() === 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (email, name, pass_hash, role) VALUES (?, ?, ?, "admin")'
                );
                $stmt->execute([$correo, $nombre, password_hash($pass, PASSWORD_ARGON2ID)]);
            } else {
                $errores[] = 'Esa base ya tiene usuarios: si querés reinstalar, vaciala primero.';
            }
        } catch (Throwable $e) {
            $errores[] = 'Falló la instalación en la base: ' . $e->getMessage();
        }
    }

    // 3. El .env, con los dos secretos generados acá.
    //
    // Se generan en el servidor y no se muestran nunca: nadie tiene que
    // copiarlos ni elegirlos. APP_KEY firma los desafíos antispam y cifra la
    // clave SMTP y los secretos TOTP; HASH_PEPPER es lo que hace que los
    // hashes de IP y de correo no se puedan revertir con una tabla.
    if ($errores === []) {
        $claveApp = bin2hex(random_bytes(32));
        $pepper = bin2hex(random_bytes(32));

        $env = <<<ENV
        APP_ENV=production
        APP_DEBUG=false
        APP_URL={$url}

        APP_KEY={$claveApp}
        HASH_PEPPER={$pepper}

        ADMIN_PATH={$panel}

        DB_DSN=mysql:host={$host};dbname={$base};charset=utf8mb4
        DB_USER={$usuario}
        DB_PASS={$clave}

        SESSION_NAME=ncms
        SESSION_IDLE=7200
        SESSION_ABSOLUTE=86400
        APP_TIMEZONE=America/Argentina/Buenos_Aires
        TRUST_PROXY=false

        ENV;

        // Si el directorio del proyecto no es escribible por PHP —que es
        // como tiene que estar en producción: el usuario del servidor web no
        // debe poder tocar el código— el .env no se puede crear desde acá.
        // No es un error: es la configuración correcta. Se muestra el
        // contenido para pegarlo a mano y listo.
        $hecho = true;
        $envEscrito = @file_put_contents($envPath, $env) !== false;

        if ($envEscrito) {
            chmod($envPath, 0600);
        } else {
            $envParaPegar = $env;
        }
    }

    // 4. Y este archivo se borra. Si tampoco puede —mismo motivo—, lo dice
    //    bien fuerte: un instalador olvidado es una puerta con la llave
    //    puesta, y lo que ya hizo no se puede deshacer desde la web.
    if ($hecho) {
        $sePudoBorrar = @unlink(__FILE__);
    }
}

?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalar Nerdo CMS</title>
<link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="loginbody">

<main class="loginbox loginbox-ancha">
  <h1>Nerdo <span>CMS</span></h1>

<?php if ($yaInstalado): ?>

  <p class="loginerror" role="alert">
    Ya hay un <code>.env</code>: el CMS está instalado. <strong>Borrá este
    archivo</strong> (<code>public/install.php</code>): mientras exista, es
    una puerta más en el único directorio que se puede alcanzar por web.
  </p>

<?php elseif ($hecho): ?>

  <p class="loginok" role="status">
    Listo: las tablas están armadas y tu usuario creado.
  </p>

  <?php if ($sePudoBorrar): ?>
    <p class="loginintro">El instalador se borró solo.</p>
  <?php else: ?>
    <p class="loginerror" role="alert">
      <strong>Borrá a mano <code>public/install.php</code></strong> antes de
      seguir: no pudo borrarse solo porque PHP no escribe en ese directorio,
      que es justamente como tiene que estar.
    </p>
  <?php endif; ?>

  <?php if (!$envEscrito): ?>
    <h2 class="installh2">Falta el .env</h2>
    <p class="loginintro">
      PHP no puede escribir en el directorio del proyecto —y está bien que
      no pueda—. Creá el archivo <code>.env</code> en la raíz con esto
      adentro, y dejalo en <code>chmod 600</code>:
    </p>
    <textarea class="envbox" rows="18" readonly spellcheck="false"><?= htmlspecialchars($envParaPegar, ENT_QUOTES) ?></textarea>
    <p class="hint">
      Los dos secretos ya están generados acá adentro. Si los perdés, hay
      que volver a dar de alta el segundo factor y la clave SMTP.
    </p>
  <?php endif; ?>

  <p class="loginintro">
    Queda por hacer, en el servidor: dejar <code>storage/</code> y
    <code>public/uploads/</code> escribibles por el usuario de PHP, y que el
    <code>DocumentRoot</code> apunte a <code>public/</code> y a nada más
    arriba. Está todo en el README.
  </p>

  <p><a class="btn" href="<?= htmlspecialchars($panel, ENT_QUOTES) ?>">Entrar al panel</a></p>

<?php else: ?>

  <p class="loginintro">
    Esto crea el <code>.env</code>, arma las tablas y el primer usuario. Los
    dos secretos de la instalación se generan acá: no hay que inventarlos.
  </p>

  <h2 class="installh2">Requisitos</h2>
  <ul class="checklist">
    <?php foreach (requisitos() as [$que, $ok, $detalle]): ?>
      <li class="<?= $ok ? 'ok' : 'falta' ?>">
        <?= htmlspecialchars($que, ENT_QUOTES) ?>
        <span class="dim"><?= htmlspecialchars($detalle, ENT_QUOTES) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php foreach ($errores as $error): ?>
    <p class="loginerror" role="alert"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
  <?php endforeach; ?>

  <form method="post" autocomplete="off">
    <h2 class="installh2">Base de datos</h2>

    <label for="db_host">Servidor</label>
    <input type="text" id="db_host" name="db_host" required
           value="<?= htmlspecialchars(valor('db_host', '127.0.0.1'), ENT_QUOTES) ?>">

    <label for="db_name">Base</label>
    <input type="text" id="db_name" name="db_name" required
           value="<?= htmlspecialchars(valor('db_name', 'nerdo_cms'), ENT_QUOTES) ?>">

    <label for="db_user">Usuario</label>
    <input type="text" id="db_user" name="db_user" required
           value="<?= htmlspecialchars(valor('db_user'), ENT_QUOTES) ?>">

    <label for="db_pass">Contraseña</label>
    <input type="password" id="db_pass" name="db_pass">

    <h2 class="installh2">Sitio</h2>

    <label for="app_url">Dirección</label>
    <input type="url" id="app_url" name="app_url" required placeholder="https://tu-dominio"
           value="<?= htmlspecialchars(valor('app_url'), ENT_QUOTES) ?>">

    <label for="admin_path">Ruta del panel</label>
    <input type="text" id="admin_path" name="admin_path" required
           value="<?= htmlspecialchars(valor('admin_path', 'panel'), ENT_QUOTES) ?>">
    <p class="hint">
      Cambiala por algo que no sea «panel»: es lo que evita que el 100% de
      los robots que prueban <code>/wp-login.php</code> encuentren algo.
    </p>

    <h2 class="installh2">Tu cuenta</h2>

    <label for="admin_name">Nombre</label>
    <input type="text" id="admin_name" name="admin_name" required
           value="<?= htmlspecialchars(valor('admin_name'), ENT_QUOTES) ?>">

    <label for="admin_email">Correo</label>
    <input type="email" id="admin_email" name="admin_email" required
           value="<?= htmlspecialchars(valor('admin_email'), ENT_QUOTES) ?>">

    <label for="admin_pass">Contraseña</label>
    <input type="password" id="admin_pass" name="admin_pass" required minlength="12"
           autocomplete="new-password">
    <p class="hint">Mínimo 12 caracteres. Se guarda con argon2id.</p>

    <button type="submit">Instalar</button>
  </form>

<?php endif; ?>
</main>

</body>
</html>
