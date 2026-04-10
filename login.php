<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';

// 1. Verificar si el dispositivo está autorizado
// Si lo está, la función posVerificarDispositivo() poblará la sesión automáticamente
$dispositivo = posVerificarDispositivo();

// 2. Si ya está autenticado (sea por auto-login o sesión previa), redirigir al index (PIN Colaborador)
if (posTiendaAutenticada()) {
    header('Location: /index.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Esto solo se ejecutará si el auto-login falla pero permitimos intento manual 
    // SOLO si el dispositivo no está bloqueado (opcional, pero el usuario dijo "no permita poner nada si no lo es")
    if (!$dispositivo['status']) {
        $error = "Dispositivo no autorizado. Acceso denegado.";
    } else {
        $r = posValidarLoginTienda(trim($_POST['usuario'] ?? ''), $_POST['clave'] ?? '');
        if ($r['status']) {
            header('Location: /index.php');
            exit();
        }
        $error = $r['msg'];
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso POS — Batidos Pitaya</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/core/assets/img/icon.png" type="image/png">
    <link rel="stylesheet" href="/core/assets/css/pos_login.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>
    <div class="pos-wrapper">
        <div class="pos-card">
            <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Batidos Pitaya" class="pos-logo">
            <h1 class="pos-title">Punto de Venta</h1>
            <p class="pos-subtitle">Acceso de Sucursal &mdash; Nivel 1</p>

            <?php if ($error): ?>
                <div class="pos-alert error show"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!$dispositivo['status']): ?>
                <div class="pos-alert error show">
                    <i class="fa fa-shield-halved"></i> <strong>Terminal Bloqueada:</strong> Por favor, contacta al área de TI para configurar la autorización antes de operar.
                </div>
                <div style="text-align:center; padding: 20px; color: var(--text-muted); font-size: 0.9rem;">
                    Este dispositivo no cuenta con una firma digital válida en la base de datos de Batidos Pitaya.
                </div>
            <?php else: ?>
                <!-- El formulario solo se muestra si NO hubo auto-login y el dispositivo está habilitado -->
                <form method="POST" autocomplete="off" id="loginForm">
                    <div class="pos-field">
                        <label class="pos-label" for="usuario">Usuario</label>
                        <input class="pos-input" type="text" id="usuario" name="usuario" placeholder="usuario" autocomplete="username" required autofocus value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>">
                    </div>
                    <div class="pos-field">
                        <label class="pos-label" for="clave">Contrasena</label>
                        <input class="pos-input" type="password" id="clave" name="clave" placeholder="********" autocomplete="current-password" required>
                    </div>
                    <button class="pos-btn" type="submit" id="btnLogin"><i class="fa fa-right-to-bracket"></i> Iniciar Sesión de Sucursal</button>
                </form>
            <?php endif; ?>

            <div class="pos-badge">
                Estado de Terminal: <span><?= $dispositivo['status'] ? 'Habilitada' : 'Bloqueada' ?></span>
                &bull; <i class="fa fa-circle" style="color:<?= $dispositivo['status'] ? '#51B8AC' : '#e05555' ?>;font-size:.55rem;vertical-align:middle"></i>
                Sucursal Código: <?= htmlspecialchars((string)($dispositivo['sucursal_codigo'] ?? '—')) ?>
            </div>
        </div>
    </div>
    <script>
        const form = document.getElementById('loginForm'),
            btn = document.getElementById('btnLogin');
        if (form) form.addEventListener('submit', () => {
            if (btn) {
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verificando...';
                btn.disabled = true;
            }
        });
    </script>
</body>

</html>