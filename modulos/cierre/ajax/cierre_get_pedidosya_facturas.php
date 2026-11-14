<?php
/**
 * cierre_get_pedidosya_facturas.php — Lista facturas PedidosYa del rango del turno.
 *
 * GET ?cierre_id=N
 * Responde: { ok: true, facturas: [...], total_esperado: N }
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
    $stmtC = $conn->prepare(
        "SELECT corte_anterior, corte_actual
         FROM pos_cierres_turno
         WHERE id = ? AND cod_sucursal = ? LIMIT 1"
    );
    $stmtC->execute([$cierreId, $codSucursal]);
    $cierre = $stmtC->fetch(PDO::FETCH_ASSOC);
    if (!$cierre) {
        echo json_encode(['ok' => false, 'mensaje' => 'Cierre no encontrado.']);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT f.id, f.fecha_hora, f.total,
                COALESCE(cc.nombre_cliente_general,
                    CONCAT(cl.nombres,' ',cl.apellidos), 'General') AS cliente
         FROM pos_ventas_facturas f
         LEFT JOIN clientesclub cl ON cl.id = f.id_cliente_club
         LEFT JOIN (SELECT id, nombre_cliente_general FROM pos_ventas_facturas) cc ON cc.id = f.id
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND EXISTS (
               SELECT 1 FROM pos_ventas_factura_pagos p2
               WHERE p2.id_factura = f.id AND p2.metodo = 'pedidosya'
           )
         ORDER BY f.fecha_hora"
    );
    $stmt->execute([
        ':suc'   => $codSucursal,
        ':desde' => $cierre['corte_anterior'],
        ':hasta' => $cierre['corte_actual'],
    ]);
    $facturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = array_sum(array_column($facturas, 'total'));

    echo json_encode([
        'ok'             => true,
        'facturas'       => $facturas,
        'total_esperado' => round($total, 2),
    ]);
} catch (PDOException $e) {
    error_log('[cierre_get_pedidosya_facturas] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error en base de datos.']);
}

