<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
if (!posTiendaAutenticada()) {
    header('Location: /login.php');
    exit();
}

$dispositivo = posVerificarDispositivo();
$pinError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['colaborador_clave'])) {
    if (!$dispositivo['status']) {
        $pinError = 'No puedes ingresar: El dispositivo NO esta autorizado.';
    } else {
        $r = posValidarColaborador($_POST['colaborador_clave']);
        if ($r['status']) {
            header('Location: /index.php');
            exit();
        }
        $pinError = $r['msg'];
    }
}

$hayColaborador = posColaboradorAutenticado();
$sucursal = $_SESSION['pos_store_sucursal'] ?? '—';
$sucursalNombre = $_SESSION['pos_store_sucursal_nombre'] ?? '';
$storeUsuario = $_SESSION['pos_store_usuario'] ?? '';
$colabNombre = $_SESSION['pos_colaborador_nombre'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS — Batidos Pitaya</title>
    <link rel="icon" href="/core/assets/img/icon.png" type="image/png">
    <link rel="stylesheet" href="/core/assets/css/pos_login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>

    <?php if (!$hayColaborador): ?>
        <!-- ===== PIN PAD OVERLAY (Etapa 2) ===== -->
        <div class="pos-pin-overlay">
            <div class="pos-pin-card">
                <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Pitaya" style="width:70px;margin:0 auto 18px;display:block">

                <?php if (!$dispositivo['status']): ?>
                    <!-- DISPOSITIVO NO AUTORIZADO -->
                    <h2 style="color:#e05555">Terminal Bloqueada</h2>
                    <p style="margin-bottom:20px">La autorización de este dispositivo ha expirado o no es válida. Por favor, contacta al personal de TI para reconfigurar la terminal.</p>

                    <i class="fa fa-shield-halved" style="font-size:3rem;color:rgba(224,85,85,0.2);margin-bottom:20px"></i>
                <?php else: ?>
                    <h2 style="color: var(--pitaya-teal)">Acceso Colaborador</h2>
                    <p>Ingresa tu clave para operar &bull; Sucursal <strong><?= htmlspecialchars($sucursal) ?></strong></p>

                    <?php if ($pinError): ?>
                        <div class="pos-alert error show" style="margin-bottom:16px;">
                            <i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($pinError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="pinForm" autocomplete="off">
                        <div class="pos-field">
                            <label class="pos-label" for="colaborador_clave">Contraseña de Colaborador</label>
                            <input class="pos-input pos-input-pin" type="password" name="colaborador_clave" id="colaborador_clave" placeholder="••••••••" required autofocus>
                        </div>
                        <button type="submit" class="pos-btn" style="margin-top:10px">
                            <i class="fa fa-right-to-bracket"></i> Acceder
                        </button>
                    </form>
                <?php endif; ?>

                <div style="margin-top:26px;padding-top:10px;border-top:1px solid var(--border);">
                    <div style="color:var(--text-muted);font-size:.78rem;">
                        <i class="fa fa-shop"></i> Terminal configurada para: <strong><?= htmlspecialchars($sucursalNombre ?: $sucursal) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="pos-wrapper dashboard">
        <div class="pos-dashboard">
            <header class="pos-header">
                <div class="pos-user-info">
                    <div class="pos-avatar"><?= htmlspecialchars(mb_substr($colabNombre ?: 'P', 0, 1)) ?></div>
                    <div>
                        <div style="font-weight:700;font-size:1rem"><?= htmlspecialchars($colabNombre ?: 'Sin colaborador') ?></div>
                        <div style="font-size:.75rem;color:var(--text-muted)">
                            Sucursal <?= htmlspecialchars($sucursal) ?> — <?= htmlspecialchars($sucursalNombre) ?>
                        </div>
                    </div>
                </div>
                <div class="pos-header-actions">
                    <a href="/logout.php?type=colaborador" class="pos-btn-exit" title="Cambiar operador">
                        <i class="fa fa-user-slash"></i> Salir de Sesión
                    </a>
                </div>
            </header>

            <div class="pos-welcome">
                <div style="font-size:3.5rem;margin-bottom:20px;">🛒</div>
                <h2>¡Bienvenid@, <?= htmlspecialchars(explode(' ', trim($colabNombre))[0] ?? 'Colaborador') ?>!</h2>
                <p>Estás operando en la sucursal <strong><?= htmlspecialchars($sucursalNombre ?: $sucursal) ?></strong>.<br>
                    Tu marcación de entrada fue verificada exitosamente.</p>
                <div class="pos-actions">
                    <a href="/modulos/ventas/" class="pos-action-btn primary"><i class="fa fa-cash-register"></i> Nueva Venta</a>
                    <a href="/modulos/caja/" class="pos-action-btn"><i class="fa fa-vault"></i> Caja</a>
                    <a href="/modulos/inventario/" class="pos-action-btn"><i class="fa fa-boxes-stacked"></i> Inventario</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const colabInput = document.getElementById('colaborador_clave');
            if (colabInput) {
                colabInput.focus();
            }
        });
    </script>
</body>

</html>