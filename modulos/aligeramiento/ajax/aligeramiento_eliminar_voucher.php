<?php
/**
 * aligeramiento_eliminar_voucher.php
 * Elimina un voucher (archivo + BD) y devuelve el nuevo resumen de validación.
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $id              = isset($_POST['id'])              ? (int)$_POST['id']              : 0;
    $aligeramientoId = isset($_POST['aligeramiento_id'])? (int)$_POST['aligeramiento_id']: 0;
    $montoReg        = isset($_POST['monto_registro'])  ? (float)$_POST['monto_registro'] : null;

    if ($id < 1 || $aligeramientoId < 1) {
        throw new Exception('Parámetros inválidos.');
    }

    // Verificar pertenencia a la sucursal del dispositivo
    $sucursalSesion = $_SESSION['pos_store_sucursal'] ?? null;
    $stmtChk = $conn->prepare("SELECT sucursal_id, total_global FROM pos_aligeramientos WHERE id = :id");
    $stmtChk->execute([':id' => $aligeramientoId]);
    $aRow = $stmtChk->fetch(PDO::FETCH_ASSOC);

    if (!$aRow) throw new Exception('Aligeramiento no encontrado.');
    if ($sucursalSesion && (int)$aRow['sucursal_id'] !== (int)$sucursalSesion) {
        throw new Exception('Sin permiso para eliminar vouchers de este aligeramiento.');
    }

    if ($montoReg === null) $montoReg = (float)$aRow['total_global'];

    // ── Obtener ruta de la foto ──────────────────────────────────────────
    $stmtGet = $conn->prepare("
        SELECT foto_path FROM aligeramientos_vouchers
        WHERE id = :id AND aligeramiento_id = :alid
    ");
    $stmtGet->execute([':id' => $id, ':alid' => $aligeramientoId]);
    $row = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$row) throw new Exception('Voucher no encontrado o no pertenece a este aligeramiento.');

    // ── Borrar archivo físico ────────────────────────────────────────────
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    $fotoAbs = $docRoot . '/' . ltrim($row['foto_path'], '/');
    if (!file_exists($fotoAbs)) {
        $fotoAbs = realpath(__DIR__ . '/../../../../' . $row['foto_path']);
    }
    if ($fotoAbs && file_exists($fotoAbs)) {
        @unlink($fotoAbs);
    }

    // ── Borrar fila en BD ────────────────────────────────────────────────
    $stmtDel = $conn->prepare("DELETE FROM aligeramientos_vouchers WHERE id = :id");
    $stmtDel->execute([':id' => $id]);

    // ── Recalcular resumen ───────────────────────────────────────────────
    $stmtSum = $conn->prepare("
        SELECT ia_denominacion, SUM(ia_monto) as total
        FROM aligeramientos_vouchers
        WHERE aligeramiento_id = :alid AND ia_monto IS NOT NULL
        GROUP BY ia_denominacion
    ");
    $stmtSum->execute([':alid' => $aligeramientoId]);
    $sumas = $stmtSum->fetchAll(PDO::FETCH_ASSOC);

    $stmtCount = $conn->prepare("SELECT COUNT(*) as total FROM aligeramientos_vouchers WHERE aligeramiento_id = :alid");
    $stmtCount->execute([':alid' => $aligeramientoId]);
    $totalVouchers = (int)$stmtCount->fetchColumn();

    $resumen = [
        'monto_registro'     => $montoReg,
        'totales_por_moneda' => [],
        'tiene_alerta'       => false,
        'alerta_msg'         => '',
        'total_vouchers'     => $totalVouchers,
    ];

    $totales = [];
    foreach ($sumas as $s) {
        $totales[$s['ia_denominacion']] = (float)$s['total'];
        $resumen['totales_por_moneda'][$s['ia_denominacion']] = (float)$s['total'];
    }

    if (!empty($totales) && $montoReg !== null) {
        $totalSuma = array_sum($totales);
        if (abs($totalSuma - $montoReg) > 1) {
            $resumen['tiene_alerta'] = true;
            $diff  = $totalSuma - $montoReg;
            $signo = $diff > 0 ? '+' : '';
            $resumen['alerta_msg'] = "Suma vouchers: C$ " . number_format($totalSuma, 2)
                . " | Registrado: C$ " . number_format($montoReg, 2)
                . " | Diferencia: C$ " . $signo . number_format($diff, 2);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Voucher eliminado correctamente.',
        'resumen' => $resumen,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

