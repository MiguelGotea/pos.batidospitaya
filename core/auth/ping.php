<?php
// /core/auth/ping.php
// Endpoint ligero de Heartbeat para mantener activa la sesion y verificar expiracion.
// El header_universal.php hace fetch a este endpoint cada 5 minutos desde todas las paginas.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/session_manager.php';

iniciarSesionSegura();

// ── Verificar expiracion por corte de medianoche o inactividad ──────────────
$esValida = verificarExpiracionSesion();

if (!$esValida || !isset($_SESSION['usuario_id'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    http_response_code(401);
    echo json_encode([
        'status'  => 'expired',
        'message' => 'La sesion ha expirado o no esta activa.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── Sesion valida: calcular tiempo restante basado en last_activity ─────────
// IMPORTANTE: usar last_activity (ventana deslizante), NO login_time (fijo).
// Si usamos login_time, el tiempo restante cae a cero aunque el usuario este activo.
$ahora             = time();
$ultimaActividad   = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? $ahora;
$segundosRestantes = max(0, SESSION_DURATION_SECONDS - ($ahora - $ultimaActividad));
$usuarioNombre     = $_SESSION['usuario_nombre'] ?? '';

// Liberar el bloqueo de sesion inmediatamente para no retrasar otras peticiones concurrentes
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

http_response_code(200);
echo json_encode([
    'status'    => 'active',
    'usuario'   => $usuarioNombre,
    'time_left' => $segundosRestantes
], JSON_UNESCAPED_UNICODE);
exit();
