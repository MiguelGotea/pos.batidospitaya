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

// ---------------------------------------------------------------
// Nombres de campo aleatorios para bloquear el autocompletado del
// navegador. Chrome/Edge asocian credenciales por nombre de campo;
// al cambiarlos en cada carga no puede encontrar coincidencias.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // GET: generar nombres nuevos y guardarlos en sesión
    $_SESSION['_pos_fn_u'] = 'f_' . bin2hex(random_bytes(4));
    $_SESSION['_pos_fn_p'] = 'f_' . bin2hex(random_bytes(4));
}
$fieldUser = $_SESSION['_pos_fn_u'] ?? 'usuario';
$fieldPass = $_SESSION['_pos_fn_p'] ?? 'clave';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$dispositivo['status']) {
        $error = "Dispositivo no autorizado. Acceso denegado.";
    } else {
        $usuarioVal = trim($_POST[$fieldUser] ?? '');
        $claveVal   = $_POST[$fieldPass] ?? '';
        $r = posValidarLoginTienda($usuarioVal, $claveVal);
        if ($r['status']) {
            // Responder JSON si es petición fetch/ajax
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'redirect' => '/index.php']);
                exit();
            }
            header('Location: /index.php');
            exit();
        }
        $error = $r['msg'];
    }
    // Si falla y es ajax, responder JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => $error]);
        exit();
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
    <style>
        /* Enmascara el campo de contraseña mientras es type="text" (webkit: Chrome/Edge/Safari) */
        input.pwd-mask {
            -webkit-text-security: disc;
        }
    </style>
</head>

<body>
    <div class="pos-wrapper">
        <div class="logo-container">
            <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Batidos Pitaya" class="pos-logo">
        </div>
        <div class="pos-card">
            <h1 class="pos-title">Punto de Venta</h1>
            <p class="pos-subtitle">Acceso de Sucursal &mdash; Nivel 1</p>

            <?php if ($error): ?>
                <div class="pos-alert error show" id="loginAlert"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
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
                <!-- Campos con nombres aleatorios generados por PHP cada carga.
                     El navegador no puede asociar credenciales guardadas porque
                     los nombres cambian en cada petición GET.
                     El campo de acceso nunca usa type="password" para evitar
                     que Chrome active el gestor de contraseñas. -->
                <form method="POST" autocomplete="off" id="loginForm">
                    <div class="pos-field">
                        <label class="pos-label" for="inp_user">Usuario</label>
                        <input class="pos-input" type="text" id="inp_user" name="<?= $fieldUser ?>" placeholder="usuario" autocomplete="off" readonly required autofocus>
                    </div>
                    <div class="pos-field">
                        <label class="pos-label" for="inp_pass">Contraseña</label>
                        <input class="pos-input" type="text" id="inp_pass" name="<?= $fieldPass ?>" placeholder="••••••••" autocomplete="off" readonly required class="pwd-mask">
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
        document.addEventListener('DOMContentLoaded', function () {
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            var usuarioField  = document.getElementById('inp_user');
            var passwordField = document.getElementById('inp_pass');
            var loginForm     = document.getElementById('loginForm');
            var btnLogin      = document.getElementById('btnLogin');

            // Limpiar cualquier valor que el navegador haya inyectado al cargar
            if (usuarioField)  usuarioField.value  = '';
            if (passwordField) passwordField.value = '';

            // ---------------------------------------------------------------
            // El campo de contraseña NUNCA cambia a type="password".
            // Permanece como type="text" con la máscara CSS (-webkit-text-security)
            // para que Chrome no lo reconozca como campo de contraseña y no
            // ofrezca autocompletado ni gestión de contraseñas.
            //
            // Solo se quita el atributo readonly cuando el usuario interactúa
            // de forma real (click, toque o tecla).
            // ---------------------------------------------------------------

            var usuarioActivated = false;
            var activateUsuario = function () {
                if (usuarioActivated) return;
                usuarioActivated = true;
                if (usuarioField) {
                    usuarioField.removeAttribute('readonly');
                    usuarioField.focus();
                }
            };

            var passwordActivated = false;
            var activatePassword = function () {
                if (passwordActivated) return;
                passwordActivated = true;
                if (passwordField) {
                    passwordField.removeAttribute('readonly');
                    passwordField.focus();
                }
            };

            if (usuarioField) {
                usuarioField.addEventListener('touchend', activateUsuario, { once: true });
                usuarioField.addEventListener('keydown',  activateUsuario, { once: true });
                usuarioField.addEventListener('click',    activateUsuario, { once: true });
                usuarioField.addEventListener('focus',    activateUsuario, { once: true });
            }
            if (passwordField) {
                passwordField.addEventListener('touchend', activatePassword, { once: true });
                passwordField.addEventListener('keydown',  activatePassword, { once: true });
                passwordField.addEventListener('paste',    activatePassword, { once: true });
                passwordField.addEventListener('click',    activatePassword, { once: true });
                // Sin 'focus': el navegador lo dispara automáticamente y abre el autofill.
            }

            // ---------------------------------------------------------------
            // Envío del formulario via fetch() para que Chrome no vea una
            // petición POST con un campo de contraseña y no ofrezca guardarla.
            // ---------------------------------------------------------------
            if (loginForm) {
                loginForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (btnLogin) {
                        btnLogin.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verificando...';
                        btnLogin.disabled = true;
                    }
                    var formData = new FormData(loginForm);
                    fetch('/login.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        redirect: 'follow'
                    }).then(function (response) {
                        if (response.redirected) {
                            window.location.href = response.url;
                        } else {
                            return response.json().then(function (data) {
                                if (data && data.ok) {
                                    window.location.href = data.redirect || '/index.php';
                                } else {
                                    // Mostrar alerta de error
                                    var alertEl = document.getElementById('loginAlert');
                                    if (!alertEl) {
                                        alertEl = document.createElement('div');
                                        alertEl.id = 'loginAlert';
                                        alertEl.className = 'pos-alert error show';
                                        loginForm.parentNode.insertBefore(alertEl, loginForm);
                                    }
                                    alertEl.innerHTML = '<i class="fa fa-circle-exclamation"></i> ' + ((data && data.msg) ? data.msg : 'Credenciales incorrectas.');
                                    alertEl.style.display = 'block';
                                    alertEl.style.opacity = '1';
                                    setTimeout(function () {
                                        alertEl.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                                        alertEl.style.opacity = '0';
                                        alertEl.style.transform = 'translateY(-6px)';
                                        setTimeout(function () { alertEl.remove(); }, 500);
                                    }, 5000);
                                    if (btnLogin) {
                                        btnLogin.innerHTML = '<i class="fa fa-right-to-bracket"></i> Iniciar Sesión de Sucursal';
                                        btnLogin.disabled = false;
                                    }
                                }
                            });
                        }
                    }).catch(function () {
                        // Fallback: enviar el formulario normalmente si fetch falla
                        loginForm.submit();
                    });
                });
            }

            // Fade-out de alerta renderizada por PHP
            var alertEl = document.getElementById('loginAlert');
            if (alertEl) {
                setTimeout(function () {
                    alertEl.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    alertEl.style.opacity = '0';
                    alertEl.style.transform = 'translateY(-6px)';
                    setTimeout(function () { alertEl.remove(); }, 500);
                }, 5000);
            }
        });
    </script>
</body>

</html>