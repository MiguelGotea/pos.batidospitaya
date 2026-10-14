<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';

if (!posTiendaAutenticada()) {
    header('Location: /login.php');
    exit();
}

$dispositivo = posVerificarDispositivo();
$pinError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['colaborador_clave'])) {
    $isAjax = (isset($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

    if (!$dispositivo['status']) {
        $pinError = 'No puedes ingresar: El dispositivo NO está autorizado.';
    } else {
        $r = posValidarColaborador($_POST['colaborador_clave']);
        if ($r['status']) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'redirect' => '/index.php']);
                exit();
            }
            header('Location: /index.php');
            exit();
        }
        $pinError = $r['msg'];
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => $pinError]);
        exit();
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
    <link rel="icon" href="/core/assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="/core/assets/css/pos_login.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background-color: #F6F6F6 !important;
            color: #333333 !important;
            overflow: auto;
        }

        .pos-dashboard {
            max-width: 1100px;
            margin: 0 auto;
        }

        /* Layout Modular con Sidebar */
        .pos-main-container {
            margin-left: 70px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            background: #F6F6F6;
        }

        /* Cuando el sidebar esté en hover, el contenido no tiene que moverse necesariamente 
           pues el sidebar suele estar encima (fixed), pero si queremos que el ERP sea fiel: */
        /* .pos-sidebar:hover + .pos-main-container { margin-left: 260px; } */

        .pos-content {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .pos-btn-exit.secondary:hover {
            background: var(--surface-hover);
            color: var(--text);
        }

        .pos-welcome {
            background: #FFFFFF !important;
            color: #333333 !important;
            border: 1px solid #E2E8F0;
            border-radius: 20px;
            padding: 60px 40px;
            text-align: center;
        }

        .pos-welcome h2 {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 12px;
            color: var(--pitaya-teal);
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
            background: var(--pitaya-dark-teal);
            color: #fff;
        }

        .pos-pin-overlay {
            position: fixed;
            inset: 0;
            background: #F6F6F6;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .pos-pin-card {
            width: 100%;
            max-width: 420px;
            background: #FFFFFF !important;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            box-shadow: var(--shadow-soft);
        }
    </style>
</head>

<body>

    <?php if (!$hayColaborador): ?>
        <!-- ===== PIN PAD OVERLAY (Etapa 2) ===== -->
        <div class="pos-pin-overlay">
            <div class="logo-container">
                <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Pitaya" class="pos-logo">
            </div>
            <div class="pos-pin-card">
                <?php if (!$dispositivo['status']): ?>
                    <!-- DISPOSITIVO NO AUTORIZADO -->
                    <h2 style="color:#e05555">Terminal Bloqueada</h2>
                    <p style="margin-bottom:20px">La autorización de este dispositivo ha expirado o no es válida. Por favor, contacta al personal de TI para reconfigurar la terminal.</p>

                    <i class="fa fa-shield-halved" style="font-size:3rem;color:rgba(224,85,85,0.2);margin-bottom:20px"></i>
                <?php else: ?>
                    <h2 style="color: var(--pitaya-teal)">Acceso Colaborador</h2>
                    <p>Ingresa tu clave para operar &bull; Sucursal <strong><?= htmlspecialchars($sucursal) ?></strong></p>

                    <?php if ($pinError): ?>
                        <div class="pos-alert error show" id="pinAlert" style="margin-bottom:16px;">
                            <i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($pinError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="pinForm" autocomplete="off">
                        <div class="pos-field">
                            <label class="pos-label" for="colaborador_clave">Contraseña de Colaborador</label>
                            <input class="pos-input" type="password" name="colaborador_clave" id="colaborador_clave" placeholder="••••••••" required autofocus style="text-align:center; letter-spacing:8px; font-size:1.5rem; color: #000;">
                        </div>
                        <button type="submit" id="btnAcceder" class="pos-btn" style="margin-top:10px">
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

    <?php if ($hayColaborador): ?>
        <!-- Renderizar Menú Lateral -->
        <?= renderPOSSidebar('inicio') ?>

        <div class="pos-main-container">
            <!-- Renderizar Cabecera -->
            <?= renderPOSHeader('Panel de Control') ?>

            <div class="pos-content">
                <div class="pos-welcome">
                    <div style="font-size:3.5rem;margin-bottom:20px;">🛒</div>
                    <h2>¡Bienvenid@, <?= htmlspecialchars(explode(' ', trim($colabNombre))[0] ?? 'Colaborador') ?>!</h2>
                    <p>Estás operando en la sucursal <strong><?= htmlspecialchars($sucursalNombre ?: $sucursal) ?></strong>.<br>
                        Tu marcación de entrada fue verificada exitosamente.</p>
                    <div class="pos-actions">
                        <a href="/modulos/pos/facturas_nueva.php" class="pos-action-btn primary"><i class="fa fa-cash-register"></i> Nueva Venta</a>
                        <a href="/modulos/inicial/" class="pos-action-btn"><i class="fa fa-vault"></i> Caja Inicial</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Eliminar estado POST del historial para evitar la advertencia al presionar F5/recargar
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            const pinForm = document.getElementById('pinForm');
            const colabInput = document.getElementById('colaborador_clave');
            const btnAcceder = document.getElementById('btnAcceder');
            let alertTimer = null;

            function iniciarFadeOutAlerta(el) {
                if (!el) return;
                if (alertTimer) clearTimeout(alertTimer);
                el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                el.style.opacity = '1';
                el.style.transform = 'translateY(0)';
                alertTimer = setTimeout(() => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(-6px)';
                    setTimeout(() => {
                        el.style.display = 'none';
                    }, 500);
                }, 5000);
            }

            function ocultarAlertaInmediata() {
                const el = document.getElementById('pinAlert');
                if (el && el.style.display !== 'none') {
                    if (alertTimer) clearTimeout(alertTimer);
                    el.style.transition = 'opacity 0.2s ease';
                    el.style.opacity = '0';
                    setTimeout(() => {
                        el.style.display = 'none';
                    }, 200);
                }
            }

            function mostrarAlerta(msg) {
                let el = document.getElementById('pinAlert');
                if (!el) {
                    el = document.createElement('div');
                    el.id = 'pinAlert';
                    el.className = 'pos-alert error show';
                    el.style.marginBottom = '16px';
                    if (pinForm) {
                        pinForm.parentNode.insertBefore(el, pinForm);
                    }
                }
                el.innerHTML = '<i class="fa fa-circle-exclamation"></i> ' + msg;
                el.style.display = 'block';
                el.classList.add('show');
                // Reiniciar animación de sacudida
                el.style.animation = 'none';
                el.offsetHeight; /* trigger reflow */
                el.style.animation = '';
                iniciarFadeOutAlerta(el);
            }

            // Si hay alerta renderizada por PHP al cargar
            const existingAlert = document.getElementById('pinAlert');
            if (existingAlert) {
                iniciarFadeOutAlerta(existingAlert);
            }

            if (colabInput) {
                colabInput.focus();
                // Al comenzar a escribir de nuevo, ocultar la alerta de inmediato
                colabInput.addEventListener('input', () => {
                    ocultarAlertaInmediata();
                });
            }

            if (pinForm) {
                pinForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const clave = colabInput ? colabInput.value.trim() : '';
                    if (!clave) return;

                    // Deshabilitar botón para prevenir doble submit
                    if (btnAcceder) {
                        btnAcceder.disabled = true;
                        btnAcceder.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verificando...';
                    }

                    const formData = new FormData(pinForm);
                    formData.append('ajax', '1');

                    fetch('/index.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.ok) {
                            window.location.href = data.redirect || '/index.php';
                        } else {
                            mostrarAlerta((data && data.msg) ? data.msg : 'Error al validar credenciales.');
                            if (colabInput) {
                                colabInput.value = '';
                                colabInput.focus();
                            }
                            if (btnAcceder) {
                                btnAcceder.disabled = false;
                                btnAcceder.innerHTML = '<i class="fa fa-right-to-bracket"></i> Acceder';
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Error de autenticación:', err);
                        mostrarAlerta('Error de conexión con el servidor. Intenta de nuevo.');
                        if (btnAcceder) {
                            btnAcceder.disabled = false;
                            btnAcceder.innerHTML = '<i class="fa fa-right-to-bracket"></i> Acceder';
                        }
                    });
                });
            }
        });
    </script>
</body>

</html>