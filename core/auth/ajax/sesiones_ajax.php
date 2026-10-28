<?php
// /core/auth/ajax/sesiones_ajax.php
// Endpoint AJAX exclusivo para la pantalla de Monitor de Sesiones.
// Solo accesible con sesion activa.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../session_manager.php';
iniciarSesionSegura();
verificarExpiracionSesion();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'no_auth']);
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
global $conn;

$accion = $_GET['accion'] ?? '';

// ── KPIs ──────────────────────────────────────────────────────────────────────
if ($accion === 'kpis') {
    try {
        $hoy = date('Y-m-d');

        $activas = (int) $conn->query("
            SELECT COUNT(*) FROM erp_sesiones_activas WHERE logout_at IS NULL
        ")->fetchColumn();

        $loginHoy = (int) $conn->query("
            SELECT COUNT(*) FROM erp_sesiones_activas WHERE DATE(login_at) = '$hoy'
        ")->fetchColumn();

        $inactividadHoy = (int) $conn->query("
            SELECT COUNT(*) FROM erp_sesiones_activas
            WHERE DATE(logout_at) = '$hoy' AND motivo_cierre = 'inactividad'
        ")->fetchColumn();

        $logoutHoy = (int) $conn->query("
            SELECT COUNT(*) FROM erp_sesiones_activas
            WHERE DATE(logout_at) = '$hoy' AND motivo_cierre = 'logout_manual'
        ")->fetchColumn();

        echo json_encode([
            'activas'     => $activas,
            'hoy'         => $loginHoy,
            'inactividad' => $inactividadHoy,
            'logout'      => $logoutHoy,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['activas' => 0, 'hoy' => 0, 'inactividad' => 0, 'logout' => 0]);
    }
    exit();
}

// ── Sesiones activas ahora ────────────────────────────────────────────────────
if ($accion === 'activas') {
    try {
        $stmt = $conn->query("
            SELECT
                s.id,
                s.session_id,
                s.cod_operario,
                s.usuario_nombre,
                s.cargo_cod,
                s.cargo_nombre,
                s.ip,
                s.user_agent,
                s.login_at,
                s.last_activity_at
            FROM erp_sesiones_activas s
            WHERE s.logout_at IS NULL
            ORDER BY s.last_activity_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Formatear fechas como strings (MySQL ya devuelve en hora de Nicaragua)
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode([]);
    }
    exit();
}

// ── Historial paginado ────────────────────────────────────────────────────────
if ($accion === 'historial') {
    try {
        $fecha1   = $_GET['fecha1']   ?? date('Y-m-d');
        $fecha2   = $_GET['fecha2']   ?? date('Y-m-d');
        $motivo   = $_GET['motivo']   ?? '';
        $pagina   = max(1, (int) ($_GET['pagina']    ?? 1));
        $porPag   = max(1, min(200, (int) ($_GET['por_pagina'] ?? 50)));
        $offset   = ($pagina - 1) * $porPag;

        // Construir WHERE dinámico con parámetros preparados
        $where  = ['DATE(s.login_at) BETWEEN ? AND ?'];
        $params = [$fecha1, $fecha2];

        if ($motivo === 'activa') {
            $where[] = 's.logout_at IS NULL';
        } elseif (!empty($motivo)) {
            $where[] = 's.motivo_cierre = ?';
            $params[] = $motivo;
        }

        $whereStr = implode(' AND ', $where);

        // Total
        $stmtCount = $conn->prepare("
            SELECT COUNT(*) FROM erp_sesiones_activas s WHERE $whereStr
        ");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        // Filas de la página
        $stmtRows = $conn->prepare("
            SELECT
                s.id, s.cod_operario, s.usuario_nombre,
                s.cargo_cod, s.cargo_nombre,
                s.ip, s.user_agent,
                s.login_at, s.last_activity_at, s.logout_at,
                s.motivo_cierre
            FROM erp_sesiones_activas s
            WHERE $whereStr
            ORDER BY s.login_at DESC
            LIMIT $porPag OFFSET $offset
        ");
        $stmtRows->execute($params);
        $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'total' => $total,
            'rows'  => $rows,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['total' => 0, 'rows' => [], 'error' => $e->getMessage()]);
    }
    exit();
}

// Acción desconocida
http_response_code(400);
echo json_encode(['error' => 'accion_desconocida']);
exit();

