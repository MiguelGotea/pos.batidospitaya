<?php
/**
 * POS - Autorizar Dispositivo POS
 * Ruta: /modulos/sistemas/autorizar_pos_pc.php
 *
 * Genera un token exclusivo para el dominio pos.batidospitaya.com
 * y lo guarda en sucursales.pos_cookie_token (columna separada del ERP).
 * La cookie se llama pos_device_token (diferente a erp_device_token).
 *
 * Acceso: Solo usuario de sucursal (rol 27) autenticado en la Etapa 1.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';

// Solo usuarios con sesion de tienda activa pueden autorizar dispositivos
if (!posTiendaAutenticada()) {
    header('Location: /login.php');
    exit();
}

$mensaje     = '';
$tipo_msg    = '';
$sucursalSesion = $_SESSION['pos_store_sucursal'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['autorizar'])) {

    // Solo puede autorizar la sucursal a la que pertenece su usuario
    $codSucursal = (int)($sucursalSesion);

    if (!$codSucursal) {
        $mensaje  = 'No se pudo determinar la sucursal asociada a tu usuario.';
        $tipo_msg = 'error';
    } else {
        $token = bin2hex(random_bytes(32));

        try {
            // Guardar en la columna exclusiva del POS
            $stmt = ejecutarConsulta(
                "UPDATE sucursales SET pos_cookie_token = ? WHERE codigo = ?",
                [$token, $codSucursal]
            );

            if ($stmt && $stmt->rowCount() > 0) {
                // Cookie exclusiva del POS (pos_device_token)
                $expiracion = time() + (10 * 365 * 24 * 60 * 60); // 10 anos
                $secure     = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

                setcookie('pos_device_token', $token, [
                    'expires'  => $expiracion,
                    'path'     => '/',
                    'domain'   => $_SERVER['HTTP_HOST'], // pos.batidospitaya.com
                    'secure'   => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                $mensaje  = 'Dispositivo autorizado correctamente para la Sucursal ' . $codSucursal . '. La cookie POS ha sido establecida en este navegador.';
                $tipo_msg = 'success';
            } else {
                $mensaje  = 'No se encontro la sucursal en la base de datos o ya estaba actualizada.';
                $tipo_msg = 'error';
            }
        } catch (Exception $e) {
            error_log('POS autorizar_pos_pc error: ' . $e->getMessage());
            $mensaje  = 'Error al autorizar: ' . $e->getMessage();
            $tipo_msg = 'error';
        }
    }
}

// Obtener nombre de la sucursal para mostrar
$nombreSucursal = '';
if ($sucursalSesion) {
    $stmtS = ejecutarConsulta("SELECT nombre FROM sucursales WHERE codigo = ? LIMIT 1", [$sucursalSesion]);
    if ($stmtS) {
        $row = $stmtS->fetch();
        $nombreSucursal = $row['nombre'] ?? '';
    }
}
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
    </style>
</head>
<body>
<div class="pos-bg"></div>
<div class="wrap">
    <div class="pos-card" style="max-width:460px">

        <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Pitaya" class="pos-logo">
        <h1 class="pos-title" style="font-size:1.45rem">Autorizar Dispositivo POS</h1>
        <p class="pos-subtitle">Exclusivo para <strong>pos.batidospitaya.com</strong></p>

        <?php if ($mensaje): ?>
            <div class="msg-<?= $tipo_msg === 'success' ? 'ok' : 'err' ?>">
                <i class="fa fa-<?= $tipo_msg === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
                <?= htmlspecialchars($mensaje) ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <i class="fa fa-circle-info"></i>
            Al presionar <strong>Autorizar esta PC</strong>, se guardar&aacute; un token secreto en este navegador ligado a la
            <strong>Sucursal <?= htmlspecialchars($sucursalSesion ?? '?') ?> — <?= htmlspecialchars($nombreSucursal) ?></strong>.
            <br><br>
            &bull; Este token es <strong>independiente</strong> del ERP.<br>
            &bull; La cookie dura <strong>10 a&ntilde;os</strong> en este navegador.<br>
            &bull; No borres las cookies ni cambies de perfil de navegador.
        </div>

        <form method="POST">
            <button class="pos-btn" type="submit" name="autorizar">
                <i class="fa fa-shield-halved"></i> Autorizar esta PC para Sucursal <?= htmlspecialchars($sucursalSesion ?? '') ?>
            </button>
        </form>

        <a href="/index.php" class="back-link">
            <i class="fa fa-arrow-left"></i> Volver al POS
        </a>

        <div class="pos-badge" style="margin-top:28px">
            Sesion activa: <span><?= htmlspecialchars($_SESSION['pos_store_usuario'] ?? '') ?></span>
            &bull; Sucursal <span><?= htmlspecialchars($sucursalSesion ?? '—') ?></span>
        </div>
    </div>
</div>
</body>
</html>