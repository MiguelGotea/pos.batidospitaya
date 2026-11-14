<?php
/**
 * cierre_get_snapshot.php — Obtiene el snapshot de un cierre cerrado (para reimpresión).
 *
 * GET ?cierre_id=N
 * Responde: { ok: true, snapshot: {...} }
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

$codSucursal = $_SESSION['pos_store_sucursal'] ?? null;
if (!$codSucursal) {
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión inválida.']);
    exit;
}

$cierreId = isset($_GET['cierre_id']) ? (int)$_GET['cierre_id'] : 0;
if ($cierreId <= 0) {
    echo json_encode(['ok' => false, 'mensaje' => 'ID inválido.']);
    exit;
}

try {
    $stmt = $conn->prepare(
        "SELECT sn.snapshot_json
         FROM pos_cierres_snapshot sn
         JOIN pos_cierres_turno ct ON ct.id = sn.cierre_id
         WHERE sn.cierre_id = ? AND ct.cod_sucursal = ?
         LIMIT 1"
    );
    $stmt->execute([$cierreId, $codSucursal]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'mensaje' => 'Snapshot no encontrado.']);
        exit;
    }
    echo json_encode([
        'ok'       => true,
        'snapshot' => json_decode($row['snapshot_json'], true),
    ]);
} catch (PDOException $e) {
    error_log('[cierre_get_snapshot] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error en base de datos.']);
}

