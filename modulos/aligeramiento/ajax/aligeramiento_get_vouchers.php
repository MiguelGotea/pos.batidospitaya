<?php
/**
 * aligeramiento_get_vouchers.php
 * Devuelve vouchers de un aligeramiento y el resumen de validación total.
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $aligeramientoId = isset($_POST['aligeramiento_id']) ? (int)$_POST['aligeramiento_id'] : 0;
    $montoReg        = isset($_POST['monto_registro'])   ? (float)$_POST['monto_registro']  : null;

    if ($aligeramientoId < 1) {
        throw new Exception('aligeramiento_id inválido.');
    }

    // Verificar que pertenezca a la sucursal del dispositivo
    $sucursalSesion = $_SESSION['pos_store_sucursal'] ?? null;
    $stmtChk = $conn->prepare("SELECT sucursal_id, total_global FROM pos_aligeramientos WHERE id = :id");
    $stmtChk->execute([':id' => $aligeramientoId]);
    $aRow = $stmtChk->fetch(PDO::FETCH_ASSOC);

    if (!$aRow) throw new Exception('Aligeramiento no encontrado.');
    if ($sucursalSesion && (int)$aRow['sucursal_id'] !== (int)$sucursalSesion) {
        throw new Exception('Sin permiso para ver vouchers de este aligeramiento.');
    }

    if ($montoReg === null) {
        $montoReg = (float)$aRow['total_global'];
    }

    // ── Lista de vouchers ────────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT
            v.id,
            v.foto_path,
            v.ia_monto,
            v.ia_denominacion,
            v.ia_fecha_hora,
            v.ia_lugar,
            v.ia_proveedor,
            v.alerta,
            v.alerta_detalle,
            v.creado_en,
            CONCAT(COALESCE(o.Nombre,''), ' ', COALESCE(o.Apellido,'')) AS subido_por_nombre
        FROM aligeramientos_vouchers v
        LEFT JOIN Operarios o ON v.subido_por = o.CodOperario
        WHERE v.aligeramiento_id = :id
        ORDER BY v.creado_en ASC
    ");
    $stmt->execute([':id' => $aligeramientoId]);
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($vouchers as &$v) {
        $v['id']       = (int)$v['id'];
        $v['alerta']   = (bool)$v['alerta'];
        $v['ia_monto'] = $v['ia_monto'] !== null ? (float)$v['ia_monto'] : null;
        // URL pública
        $v['foto_url'] = '/' . ltrim($v['foto_path'], '/');
    }
    unset($v);

    // ── Resumen de validación ────────────────────────────────────────────
    $stmtSum = $conn->prepare("
        SELECT ia_denominacion, SUM(ia_monto) as total, COUNT(*) as cantidad
        FROM aligeramientos_vouchers
        WHERE aligeramiento_id = :id AND ia_monto IS NOT NULL
        GROUP BY ia_denominacion
    ");
    $stmtSum->execute([':id' => $aligeramientoId]);
    $sumas = $stmtSum->fetchAll(PDO::FETCH_ASSOC);

    $resumen = [
        'monto_registro'     => $montoReg,
        'totales_por_moneda' => [],
        'tiene_alerta'       => false,
        'alerta_msg'         => '',
        'total_vouchers'     => count($vouchers),
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
        'success'  => true,
        'vouchers' => $vouchers,
        'resumen'  => $resumen,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

