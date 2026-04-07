<?php
/**
 * POS - Autorizar Dispositivo POS
 * Ruta: /modulos/sistemas/autorizar_pos_pc.php
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';

$mensaje     = '';
$tipo_msg    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autorizar'])) {
    $codSucursal = (int)($_POST['sucursal_codigo'] ?? 0);

    if (!$codSucursal) {
        $mensaje  = 'Debes seleccionar una sucursal válida.';
        $tipo_msg = 'error';
    } else {
        $token = bin2hex(random_bytes(32));
        try {
            $stmt = ejecutarConsulta(
                "UPDATE sucursales SET pos_cookie_token = ? WHERE codigo = ?",
                [$token, $codSucursal]
            );

            if ($stmt) {
                $expiracion = time() + (10 * 365 * 24 * 60 * 60); 
                $secure     = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

                setcookie('pos_device_token', $token, [
                    'expires'  => $expiracion,
                    'path'     => '/',
                    'domain'   => $_SERVER['HTTP_HOST'],
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                $mensaje  = "¡Dispositivo autorizado con éxito para la sucursal $codSucursal! La cookie POS ha sido establecida.";
                $tipo_msg = 'success';
            } else {
                $mensaje  = 'Error al actualizar la base de datos.';
                $tipo_msg = 'error';
            }
        } catch (Exception $e) {
            $mensaje  = 'Error: ' . $e->getMessage();
            $tipo_msg = 'error';
        }
    }
}

$sucursales = obtenerSucursalesFisicas();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autorizar Dispositivo POS — Batidos Pitaya</title>
    <link rel="icon" href="/core/assets/img/icon.png" type="image/png">
    <link rel="stylesheet" href="/core/assets/css/pos_login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { overflow: auto; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; position: relative; z-index: 1; }
        .info-box { background: rgba(81,184,172,.08); border: 1px solid rgba(81,184,172,.25); color: #a8d8d4; padding: 14px 18px; border-radius: 14px; font-size: .85rem; line-height: 1.7; margin-bottom: 22px; }
        .msg-ok  { background: rgba(81,184,172,.1);  border: 1px solid rgba(81,184,172,.35); color: #51B8AC; padding: 12px 16px; border-radius: 12px; margin-bottom: 18px; }
        .msg-err { background: rgba(224,85,85,.1);   border: 1px solid rgba(224,85,85,.35);  color: #e05555; padding: 12px 16px; border-radius: 12px; margin-bottom: 18px; }
        .back-link { display: inline-flex; align-items: center; gap: 7px; color: var(--text-muted); font-size: .82rem; text-decoration: none; margin-top: 20px; }
        .back-link:hover { color: #fff; }
        .pos-select { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 13px; padding: 13px 16px; font-size: 1rem; color: #fff; outline: none; margin-bottom: 15px; }
        .pos-select option { background: #080d10; color: #fff; }
    </style>
</head>
<body>
<div class="pos-bg"></div>
<div class="wrap">
    <div class="pos-card" style="max-width:460px">
        <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Pitaya" class="pos-logo">
        <h1 class="pos-title" style="font-size:1.45rem">Autorizar Dispositivo POS</h1>
        <p class="pos-subtitle">Acceso sin inicio de sesión necesario</p>

        <?php if ($mensaje): ?>
            <div class="msg-<?= $tipo_msg === 'success' ? 'ok' : 'err' ?>">
                <i class="fa fa-<?= $tipo_msg === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <label class="pos-label">Seleccionar Sucursal:</label>
            <select name="sucursal_codigo" class="pos-select" required>
                <option value="">-- Selecciona Sucursal --</option>
                <?php foreach ($sucursales as $s): ?>
                    <option value="<?= $s['codigo'] ?>"><?= htmlspecialchars($s['nombre']) ?> (<?= $s['codigo'] ?>)</option>
                <?php endforeach; ?>
            </select>
            
            <button class="pos-btn" type="submit" name="autorizar">
                <i class="fa fa-shield-halved"></i> Autorizar esta PC ahora
            </button>
        </form>

        <a href="/login.php" class="back-link">
            <i class="fa fa-arrow-left"></i> Ir al Login del POS
        </a>
    </div>
</div>
</body>
</html>