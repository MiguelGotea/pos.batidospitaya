<?php
// /core/auth/ping.php
// Endpoint de Heartbeat: renueva la sesion activa y confirma que sigue viva.
// El header_universal.php hace fetch aqui cada 5 minutos desde todas las paginas.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/session_manager.php';

iniciarSesionSegura();

// ── Verificar expiracion (medianoche o inactividad) ──────────────────────────
// verificarExpiracionSesion() ya actualiza last_activity en $_SESSION
// y registra el motivo en erp_sesiones_activas si expira.
$esValida = verificarExpiracionSesion();

if (!$esValida || !isset($_SESSION['usuario_id'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    http_response_code(401);
    echo json_encode([
        'status'  => 'expired',
        'message' => 'La sesion ha expirado o no esta activa.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ── Sesion activa: actualizar auditoría y responder ──────────────────────────
$currentSessionId  = session_id();
$ahora             = time();
$ultimaActividad   = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? $ahora;
$segundosRestantes = max(0, SESSION_DURATION_SECONDS - ($ahora - $ultimaActividad));
$usuarioNombre     = $_SESSION['usuario_nombre'] ?? '';

// Guardar sesion y liberar el bloqueo ANTES de la consulta de auditoría
// para no bloquear otras peticiones concurrentes de la misma pestaña.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Actualizar last_activity_at en erp_sesiones_activas (no critico)
actualizarPingAuditoria($currentSessionId);

http_response_code(200);
echo json_encode([
    'status'    => 'active',
    'usuario'   => $usuarioNombre,
    'time_left' => $segundosRestantes,
], JSON_UNESCAPED_UNICODE);
exit();
