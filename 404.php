<?php
// Cargar dependencias de autenticación si están disponibles en el sistema del POS
$auth_file = $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
$usuario = null;

if (file_exists($auth_file)) {
    require_once $auth_file;
    if (posColaboradorAutenticado()) {
        $usuario = obtenerUsuarioActual();
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enlace no encontrado - POS Batidos Pitaya</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="/core/assets/img/icon12.png" type="image/png">

    <!-- Fuentes y Estilos -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', 'Calibri', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #0E544C 0%, #176B60 50%, #51B8AC 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-x: hidden;
        }

        .error-container {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 24px;
            padding: 3.5rem 2rem;
            max-width: 550px;
            width: 100%;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
            text-align: center;
            color: white;
            animation: cardFadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardFadeIn {
            0% {
                opacity: 0;
                transform: translateY(20px);
            }

            100% {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-container {
            background: white;
            padding: 0.8rem 1.5rem;
            border-radius: 16px;
            display: inline-block;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .brand-logo {
            height: 38px;
            display: block;
        }

        .error-code {
            font-family: 'Outfit', sans-serif;
            font-size: 8rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 1rem;
            background: linear-gradient(180deg, #FFFFFF 0%, rgba(255, 255, 255, 0.45) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0px 4px 15px rgba(0, 0, 0, 0.15));
            animation: pulseCode 3s ease-in-out infinite;
        }

        @keyframes pulseCode {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.03);
            }
        }

        .error-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            letter-spacing: -0.5px;
        }

        .error-desc {
            font-size: 0.95rem;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 2rem;
        }

        /* Bloque de Usuario Conectado */
        .user-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 10px 16px;
            border-radius: 16px;
            margin: 1.5rem auto;
            max-width: 340px;
            text-align: left;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        }

        .user-avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #51B8AC;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 2px 8px rgba(81, 184, 172, 0.4);
            border: 2px solid rgba(255, 255, 255, 0.8);
        }

        .user-details {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .user-name {
            font-size: 0.9rem;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }

        .user-role {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.75);
            margin-top: 1px;
            text-transform: uppercase;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        /* Botones de Acción */
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn-primary,
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0.85rem 1.75rem;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #51B8AC;
            color: #06110f;
            box-shadow: 0 4px 15px rgba(81, 184, 172, 0.35);
        }

        .btn-primary:hover {
            background: #43a499;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(81, 184, 172, 0.45);
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.07);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.35);
            transform: translateY(-2px);
            color: white;
        }

        @media (min-width: 480px) {
            .action-buttons {
                flex-direction: row;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="error-container">
        <!-- Logo de la marca -->
        <div class="logo-container">
            <img src="/core/assets/img/Logo.svg" onerror="this.src='/core/assets/img/icon.png'" alt="Batidos Pitaya" class="brand-logo">
        </div>

        <!-- Código de error -->
        <div class="error-code">404</div>

        <!-- Título y descripción -->
        <h1 class="error-title">Página no encontrada</h1>
        <p class="error-desc">
            El enlace que has intentado cargar no existe, ha sido movido, o no tienes los permisos correspondientes dentro del Punto de Venta (POS). Contacta con el área de Sistemas/TI o inténtalo de nuevo.
        </p>

        <!-- Información de sesión activa -->
        <?php if ($usuario): ?>
            <div class="user-badge">
                <div class="user-avatar-circle">
                    <?php echo strtoupper(substr($usuario['Nombre'], 0, 1)); ?>
                </div>
                <div class="user-details">
                    <span class="user-name" title="<?php echo htmlspecialchars($usuario['Nombre'] . ' ' . $usuario['Apellido']); ?>">
                        <?php echo htmlspecialchars($usuario['Nombre'] . ' ' . $usuario['Apellido']); ?>
                    </span>
                    <span class="user-role">
                        <?php echo htmlspecialchars($usuario['cargo_nombre'] ?? 'Colaborador'); ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Botones de navegación contextuales -->
        <div class="action-buttons">
            <?php if ($usuario): ?>
                <a href="/index.php" class="btn-primary">
                    <i class="fas fa-cash-register"></i> Ir al POS
                </a>
                <a href="/logout.php" class="btn-secondary">
                    <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                </a>
            <?php else: ?>
                <a href="/login.php" class="btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
                </a>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>