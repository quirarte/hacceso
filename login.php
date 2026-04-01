<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$error = null;
$info = null;
$hasUsers = false;
$redirect = auth_normalize_redirect($_GET['redirect'] ?? null);

try {
    $pdo = db_pdo($config);
    auth_ensure_schema($pdo);

    if (auth_bootstrap_admin_if_configured($pdo, $config)) {
        $info = 'Se creo el administrador inicial desde la configuracion temporal. Inicia sesion y elimina esas variables de .htaccess.';
    }

    $hasUsers = auth_has_users($pdo);
    $currentUser = auth_current_user($pdo, $config);

    if ($currentUser !== null) {
        auth_redirect(auth_normalize_redirect($_GET['redirect'] ?? null, $currentUser));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        auth_require_csrf_token($config);

        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if (!$hasUsers) {
            $error = 'Todavia no existe ningun usuario web. Configura el bootstrap inicial y vuelve a intentar.';
        } else {
            $authenticatedUser = auth_authenticate($pdo, $username, $password);

            if ($authenticatedUser === null) {
                $error = 'Credenciales invalidas o usuario sin permisos.';
            } else {
                auth_login_user($config, $authenticatedUser);
                auth_redirect(auth_normalize_redirect($_GET['redirect'] ?? null, $authenticatedUser));
            }
        }
    }
} catch (Throwable $exception) {
    $error = 'No se pudo iniciar la autenticacion: ' . $exception->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hacceso - Iniciar sesion</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f6fb;
            --card: #ffffff;
            --line: #d1d5db;
            --text: #1f2937;
            --muted: #6b7280;
            --primary: #1f6feb;
            --ok-bg: #ebfbee;
            --ok-border: #80c98d;
            --err-bg: #fff1f1;
            --err-border: #e19797;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(circle at top left, #dbeafe 0, transparent 28%),
                radial-gradient(circle at bottom right, #fde68a 0, transparent 24%),
                var(--bg);
            color: var(--text);
            font-family: Arial, sans-serif;
        }

        .shell {
            width: 100%;
            max-width: 460px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }

        p {
            margin-top: 0;
            color: var(--muted);
            line-height: 1.5;
        }

        label {
            display: block;
            font-weight: 700;
            margin-top: 14px;
            margin-bottom: 6px;
        }

        input, button {
            width: 100%;
            border-radius: 10px;
            border: 1px solid var(--line);
            padding: 11px 12px;
            font: inherit;
        }

        button {
            margin-top: 18px;
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            cursor: pointer;
            font-weight: 700;
        }

        .ok,
        .err,
        .note {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
        }

        .ok { background: var(--ok-bg); border: 1px solid var(--ok-border); }
        .err { background: var(--err-bg); border: 1px solid var(--err-border); }
        .note { background: #f8fafc; border: 1px solid var(--line); }

        code,
        pre {
            font-family: Consolas, monospace;
        }

        pre {
            white-space: pre-wrap;
            margin: 10px 0 0;
            background: #111827;
            color: #f9fafb;
            border-radius: 12px;
            padding: 12px;
            overflow: auto;
        }

        .footer {
            margin-top: 14px;
            font-size: 13px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <div class="shell">
        <div class="card">
            <h1>Hacceso</h1>
            <p>Inicia sesion para abrir el panel admin o el panel operativo de empleados.</p>

            <?php if ($info !== null): ?>
                <div class="ok"><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($error !== null): ?>
                <div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!$hasUsers): ?>
                <div class="note">
                    <strong>No hay usuarios web configurados todavia.</strong>
                    <p style="margin:8px 0 0;">Agrega temporalmente estas variables a tu <code>.htaccess</code>, abre esta pagina una vez y luego borralas despues de entrar:</p>
                    <pre>SetEnv AUTH_BOOTSTRAP_ADMIN_USERNAME "admin"
SetEnv AUTH_BOOTSTRAP_ADMIN_PASSWORD "cambia-esta-clave"
SetEnv AUTH_BOOTSTRAP_ADMIN_NAME "Administrador inicial"</pre>
                </div>
            <?php else: ?>
                <form method="post">
                    <?= auth_csrf_input($config) ?>
                    <label for="username">Usuario</label>
                    <input id="username" name="username" type="text" autocomplete="username" required>

                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>

                    <button type="submit">Entrar</button>
                </form>
            <?php endif; ?>

            <div class="footer">Si intentaste abrir una ruta protegida, volveras a ella despues de iniciar sesion.</div>
        </div>
    </div>
</body>
</html>
