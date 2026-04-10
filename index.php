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
    <style>
        body {
            overflow: auto;
        }

        .pos-wrapper {
            min-height: 100vh;
            display: block;
            padding: 20px;
        }

        .pos-dashboard {
            max-width: 1100px;
            margin: 0 auto;
        }

        .pos-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 14px 22px;
            gap: 16px;
            margin-bottom: 24px;
        }

        .pos-user-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .pos-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #51B8AC, #0E544C);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.1rem;
            color: #fff;
            flex-shrink: 0;
        }

        .pos-header-actions {
            display: flex;
            gap: 10px;
        }

        .pos-btn-exit {
            padding: 8px 14px;
            border-radius: 9px;
            font-size: .8rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            border: 1px solid rgba(224, 85, 85, .35);
            background: rgba(224, 85, 85, .1);
            color: #e05555;
            transition: .2s;
            text-decoration: none;
            white-space: nowrap;
        }

        .pos-btn-exit:hover {
            background: #e05555;
            color: #fff;
        }

        .pos-btn-exit.secondary:hover {
            background: #F1F5F9;
            color: var(--text);
        }

        .pos-welcome {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 60px 40px;
            text-align: center;
        }

        .pos-welcome h2 {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .pos-welcome p {
            color: var(--text-muted);
            max-width: 480px;
            margin: 0 auto 32px;
        }

        .pos-actions {
            display: flex;
            justify-content: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .pos-action-btn {
            padding: 14px 28px;
            border-radius: 13px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            font-family: inherit;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            transition: .2s;
            text-decoration: none;
        }

        .pos-action-btn:hover {
            background: var(--surface-hover);
            border-color: #51B8AC;
        }

        .pos-action-btn.primary {
            background: #51B8AC;
            color: #06110f;
            border-color: #51B8AC;
        }

        .pos-action-btn.primary:hover {
            background: #68cfc4;
        }
    </style>
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
                    <h2>Acceso Colaborador</h2>
                    <p>Ingresa tu clave para operar &bull; Sucursal <strong><?= htmlspecialchars($sucursal) ?></strong></p>

                    <?php if ($pinError): ?>
                        <div class="pos-alert error show" style="margin-bottom:16px;">
                            <i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($pinError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="pinForm" autocomplete="off">
                        <div class="pos-field">
                            <label class="pos-label" for="colaborador_clave">Contraseña de Colaborador</label>
                            <input class="pos-input" type="password" name="colaborador_clave" id="colaborador_clave" placeholder="••••••••" required autofocus style="text-align:center; letter-spacing:8px; font-size:1.5rem">
                        </div>
                        <button type="submit" class="pos-btn" style="margin-top:10px">
                            <i class="fa fa-right-to-bracket"></i> Entrar al POS
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

    <div class="pos-wrapper">
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