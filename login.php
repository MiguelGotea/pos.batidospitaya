<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
if (posTiendaAutenticada()) { header('Location: /index.php'); exit(); }
$dispositivo = posVerificarDispositivo();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Permitimos el intento de login incluso si el dispositivo no esta autorizado
    // para que el encargado pueda entrar y autorizarlo.
    $r = posValidarLoginTienda(trim($_POST['usuario'] ?? ''), $_POST['clave'] ?? '');
    if ($r['status']) { 
        header('Location: /index.php'); 
        exit(); 
    }
    $error = $r['msg'];
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
    <link rel="stylesheet" href="/core/assets/css/pos_login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
<div class="pos-bg"></div>
<div class="pos-wrapper">
    <div class="pos-card">
        <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Batidos Pitaya" class="pos-logo">
        <h1 class="pos-title">Punto de Venta</h1>
        <p class="pos-subtitle">Acceso de Sucursal &mdash; Nivel 1</p>
        
        <?php if ($error): ?>
            <div class="pos-alert error show"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!$dispositivo['status']): ?>
            <div class="pos-alert error show" style="background:rgba(224,85,85,0.05)">
                <i class="fa fa-shield-halved"></i> <strong>Terminal Bloqueada:</strong> Este dispositivo no se encuentra autorizado todavía. Por favor, contacta al área de TI para configurar la autorización antes de operar.
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" id="loginForm">
            <div class="pos-field">
                <label class="pos-label" for="usuario">Usuario</label>
                <input class="pos-input" type="text" id="usuario" name="usuario" placeholder="tu.usuario" autocomplete="username" required autofocus value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>">
            </div>
            <div class="pos-field">
                <label class="pos-label" for="clave">Contrasena</label>
                <input class="pos-input" type="password" id="clave" name="clave" placeholder="********" autocomplete="current-password" required>
            </div>
            <button class="pos-btn" type="submit" id="btnLogin"><i class="fa fa-right-to-bracket"></i> Iniciar Sesión de Sucursal</button>
        </form>

        <div class="pos-badge">
            Estado de Terminal: <span><?= $dispositivo['status'] ? 'Habilitada' : 'Bloqueada' ?></span>
            &bull; <i class="fa fa-circle" style="color:<?= $dispositivo['status'] ? '#51B8AC' : '#e05555' ?>;font-size:.55rem;vertical-align:middle"></i>
            Sucursal ID: <?= htmlspecialchars((string)($dispositivo['sucursal_codigo'] ?? '—')) ?>
        </div>
    </div>
</div>
<script>
const form=document.getElementById('loginForm'),btn=document.getElementById('btnLogin');
if(form)form.addEventListener('submit',()=>{if(btn){btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Verificando...';btn.disabled=true;}});
</script>
</body>
</html>