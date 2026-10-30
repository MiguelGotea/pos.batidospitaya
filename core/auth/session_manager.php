<?php
// /core/auth/session_manager.php
// Gestor centralizado de sesiones del ERP
// - Inactividad maxima: 8 horas (ventana deslizante por last_activity)
// - Corte automatico a medianoche (12:00 AM UTC-6 / America/Managua)
// - Registro de auditoria en erp_sesiones_activas
//
// REQUISITO: Las tablas php_sessions y erp_sesiones_activas deben existir en BD.
// Script SQL: core/auth/sql/crear_tablas_sesiones.sql

if (!defined('SESSION_DURATION_SECONDS')) {
    define('SESSION_DURATION_SECONDS', 28800); // 8 horas
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers internos (sin lanzar excepciones al exterior)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Obtiene la conexion PDO global, cargando conexion.php si aun no esta lista.
 * @return PDO|null
 */
function _sm_getConn(): ?PDO
{
    global $conn;
    if (!isset($conn) || !($conn instanceof PDO)) {
        $conexionPath = $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
        if (file_exists($conexionPath)) {
            require_once $conexionPath;
            global $conn;
        }
    }
    return ($conn instanceof PDO) ? $conn : null;
}

/**
 * Obtiene la IP real del cliente (considera proxies de Hostinger / Cloudflare).
 */
function _sm_getIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            return trim(explode(',', $_SERVER[$h])[0]);
        }
    }
    return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// API PUBLICA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Inicia la sesion de forma segura usando MySQL como almacen de sesiones.
 * Evita que el GC externo de Hostinger destruya los archivos de sesion.
 */
function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    date_default_timezone_set('America/Managua');

    // ── Handler de sesiones en MySQL ─────────────────────────────────────────
    // La tabla php_sessions debe existir previamente (ver SQL en core/auth/sql/).
    require_once __DIR__ . '/DbSessionHandler.php';

    global $conn;
    if (!isset($conn) || !($conn instanceof PDO)) {
        // Usar __DIR__ en vez de DOCUMENT_ROOT (más fiable en Hostinger con subdominios)
        $conexionPath = dirname(__DIR__, 2) . '/core/database/conexion.php';
        if (file_exists($conexionPath)) {
            require_once $conexionPath;
            global $conn;
        }
    }

    // Usar handler de BD solo si la conexión está disponible
    if ($conn instanceof PDO) {
        try {
            $handler = new DbSessionHandler($conn, SESSION_DURATION_SECONDS * 2);
            session_set_save_handler($handler, true);
        } catch (Throwable $e) {
            // Captura PDOException, TypeError u otro error al instanciar el handler
            error_log('[SessionManager] ADVERTENCIA: No se pudo iniciar handler de BD. Usando archivos. ' . $e->getMessage());
            _sm_usarSesionesPorArchivo();
        }
    } else {
        error_log('[SessionManager] ADVERTENCIA: $conn no disponible. Usando sesiones en archivos.');
        _sm_usarSesionesPorArchivo();
    }

    ini_set('session.gc_maxlifetime',  SESSION_DURATION_SECONDS * 2);
    ini_set('session.cookie_lifetime', SESSION_DURATION_SECONDS);
    ini_set('session.gc_probability',  0);
    ini_set('session.gc_divisor',      1);

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])   && strtolower($_SERVER['HTTP_X_FORWARDED_SSL'])   === 'on');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => SESSION_DURATION_SECONDS,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(SESSION_DURATION_SECONDS, '/; samesite=Lax', '', $isSecure, true);
    }

    session_start();
}

/**
 * Verifica si la sesion actual ha expirado segun las reglas de negocio:
 *   1. Inactividad > 8 h (ventana deslizante, renovada por ping.php cada 5 min).
 *   2. Cambio de dia (medianoche en hora de Nicaragua).
 *
 * Si expiro, destruye la sesion Y registra el motivo en erp_sesiones_activas.
 *
 * @return bool true = sesion valida (o sin usuario); false = expiro y fue destruida
 */
function verificarExpiracionSesion(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        iniciarSesionSegura();
    }

    if (!isset($_SESSION['usuario_id'])) {
        return true;
    }

    date_default_timezone_set('America/Managua');
    $ahora        = time();
    $fechaActual  = date('Y-m-d');
    $haExpirado   = false;
    $motivoExpiracion = '';

    // Regla 1: corte de medianoche
    if (isset($_SESSION['login_date']) && $_SESSION['login_date'] !== $fechaActual) {
        $haExpirado       = true;
        $motivoExpiracion = 'medianoche';
    }

    // Regla 2: inactividad de 8 h (ventana deslizante)
    if (!$haExpirado) {
        $ultimaActividad = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? null;
        if ($ultimaActividad !== null && ($ahora - $ultimaActividad) > SESSION_DURATION_SECONDS) {
            $haExpirado       = true;
            $motivoExpiracion = 'inactividad';
        }
    }

    // Inicializar marcas faltantes en sesiones antiguas (transicion)
    if (!$haExpirado && (!isset($_SESSION['login_time']) || !isset($_SESSION['login_date']))) {
        $_SESSION['login_time'] = $ahora;
        $_SESSION['login_date'] = $fechaActual;
    }

    if ($haExpirado) {
        // Registrar cierre en auditoria ANTES de destruir la sesion
        _sm_registrarCierreSesion(session_id(), $motivoExpiracion);

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return false;
    }

    // Renovar ventana deslizante
    $_SESSION['last_activity'] = $ahora;
    return true;
}

/**
 * Registra los metadatos de sesion al iniciar sesion exitosamente.
 * Guarda en $_SESSION Y en erp_sesiones_activas.
 *
 * @param mixed  $usuarioId         CodOperario o id del usuario
 * @param string $usuarioNombre     Nombre del usuario
 * @param array  $datosAdicionales  Claves extra para $_SESSION (cargo_cod, cargo_nombre, etc.)
 */
function registrarInicioSesion($usuarioId, $usuarioNombre, array $datosAdicionales = []): void
{
    date_default_timezone_set('America/Managua');
    $ahora       = time();
    $fechaActual = date('Y-m-d');

    $_SESSION['usuario_id']     = $usuarioId;
    $_SESSION['usuario_nombre'] = $usuarioNombre;
    $_SESSION['login_time']     = $ahora;
    $_SESSION['login_date']     = $fechaActual;
    $_SESSION['last_activity']  = $ahora;

    foreach ($datosAdicionales as $key => $val) {
        $_SESSION[$key] = $val;
    }

    // ── Auditoria: insertar registro de nueva sesion ──────────────────────────
    // La tabla erp_sesiones_activas debe existir previamente (ver SQL en core/auth/sql/).
    $pdo = _sm_getConn();
    if ($pdo) {
        try {
            $pdo->prepare("
                INSERT INTO `erp_sesiones_activas`
                    (`session_id`, `cod_operario`, `usuario_nombre`,
                     `cargo_cod`, `cargo_nombre`, `ip`, `user_agent`,
                     `login_at`, `last_activity_at`)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ")->execute([
                session_id(),
                $usuarioId,
                $usuarioNombre,
                $datosAdicionales['cargo_cod']    ?? null,
                $datosAdicionales['cargo_nombre'] ?? null,
                _sm_getIp(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
            ]);
        } catch (Throwable $e) {
            error_log('[SessionManager] No se pudo insertar en erp_sesiones_activas: ' . $e->getMessage());
        }
    }
}

/**
 * Registra el logout manual en auditoria y destruye la sesion.
 * Usar desde logout.php en lugar de session_destroy() directamente.
 */
function cerrarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['usuario_id'])) {
        _sm_registrarCierreSesion(session_id(), 'logout_manual');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Actualiza last_activity_at en erp_sesiones_activas.
 * Llamar desde ping.php en cada heartbeat exitoso.
 *
 * @param string $sessionId  session_id() actual
 */
function actualizarPingAuditoria(string $sessionId): void
{
    $pdo = _sm_getConn();
    if (!$pdo) return;
    try {
        $pdo->prepare("
            UPDATE `erp_sesiones_activas`
            SET `last_activity_at` = NOW()
            WHERE `session_id` = ? AND `logout_at` IS NULL
            LIMIT 1
        ")->execute([$sessionId]);
    } catch (Throwable $e) {
        // No critico: no interrumpir el heartbeat por un fallo de auditoria
        error_log('[SessionManager] actualizarPingAuditoria: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Privadas / internas
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fallback: usa sesiones en archivos locales si el handler de BD no está disponible.
 */
function _sm_usarSesionesPorArchivo(): void
{
    $sessionSavePath = dirname(__DIR__, 2) . '/core/sessions';
    if (!is_dir($sessionSavePath)) {
        @mkdir($sessionSavePath, 0777, true);
    }
    @chmod($sessionSavePath, 0777);
    if (is_dir($sessionSavePath) && is_writable($sessionSavePath)) {
        session_save_path($sessionSavePath);
    }
}

/**
 * Marca como cerrada una sesion en erp_sesiones_activas.
 *
 * @param string $sessionId  session_id() a cerrar
 * @param string $motivo     logout_manual | inactividad | medianoche | forzado_sistema
 */
function _sm_registrarCierreSesion(string $sessionId, string $motivo): void
{
    $pdo = _sm_getConn();
    if (!$pdo) return;
    try {
        $pdo->prepare("
            UPDATE `erp_sesiones_activas`
            SET `logout_at`     = NOW(),
                `motivo_cierre` = ?
            WHERE `session_id` = ? AND `logout_at` IS NULL
            LIMIT 1
        ")->execute([$motivo, $sessionId]);
    } catch (Throwable $e) {
        error_log('[SessionManager] _sm_registrarCierreSesion: ' . $e->getMessage());
    }
}
