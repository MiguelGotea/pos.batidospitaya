<?php
/**
 * cierre_get_ventas_concepto.php
 * GET ?cierre_id=N&concepto=efectivo|cupones|transferencia|tarjeta|pedidosya
 *
 * Devuelve el detalle de pagos/facturas del turno para un concepto dado.
 * Solo se permite consultar un cierre que pertenezca a la sucursal de sesión.
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
$concepto = trim($_GET['concepto'] ?? '');
$validos  = ['efectivo', 'cupones', 'transferencia', 'tarjeta', 'pedidosya'];

if ($cierreId <= 0 || !in_array($concepto, $validos, true)) {
    echo json_encode(['ok' => false, 'mensaje' => 'Parámetros inválidos.']);
    exit;
}

try {
    // Verificar que el cierre pertenece a esta sucursal y obtener rango horario
    $stmtC = $conn->prepare(
        "SELECT corte_anterior, corte_actual
         FROM pos_cierres_turno
         WHERE id = ? AND cod_sucursal = ?
         LIMIT 1"
    );
    $stmtC->execute([$cierreId, $codSucursal]);
    $cierre = $stmtC->fetch(PDO::FETCH_ASSOC);

    if (!$cierre) {
        echo json_encode(['ok' => false, 'mensaje' => 'Cierre no encontrado.']);
        exit;
    }

    $desde = $cierre['corte_anterior'];
    $hasta = $cierre['corte_actual'];
    $filas = [];
    $total = 0;

    switch ($concepto) {

        case 'efectivo':
            $stmt = $conn->prepare(
                "SELECT f.id AS factura_id, f.fecha_hora,
                        p.moneda, p.monto_original, p.monto_cordoba
                 FROM pos_ventas_factura_pagos p
                 JOIN pos_ventas_facturas f ON f.id = p.id_factura
                 WHERE f.cod_sucursal = :suc
                   AND f.estado IN ('completada','bloqueada')
                   AND f.fecha_hora > :d AND f.fecha_hora <= :h
                   AND p.metodo = 'efectivo'
                 ORDER BY f.fecha_hora, f.id"
            );
            $stmt->execute([':suc' => $codSucursal, ':d' => $desde, ':h' => $hasta]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_column($filas, 'monto_cordoba'));
            break;

        case 'cupones':
            $stmt = $conn->prepare(
                "SELECT f.id AS factura_id, f.fecha_hora,
                        p.monto_cordoba, p.codigo_cupon
                 FROM pos_ventas_factura_pagos p
                 JOIN pos_ventas_facturas f ON f.id = p.id_factura
                 WHERE f.cod_sucursal = :suc
                   AND f.estado IN ('completada','bloqueada')
                   AND f.fecha_hora > :d AND f.fecha_hora <= :h
                   AND p.metodo = 'cupon'
                 ORDER BY f.fecha_hora, f.id"
            );
            $stmt->execute([':suc' => $codSucursal, ':d' => $desde, ':h' => $hasta]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_column($filas, 'monto_cordoba'));
            break;

        case 'transferencia':
            $stmt = $conn->prepare(
                "SELECT f.id AS factura_id, f.fecha_hora,
                        p.monto_cordoba, p.referencia
                 FROM pos_ventas_factura_pagos p
                 JOIN pos_ventas_facturas f ON f.id = p.id_factura
                 WHERE f.cod_sucursal = :suc
                   AND f.estado IN ('completada','bloqueada')
                   AND f.fecha_hora > :d AND f.fecha_hora <= :h
                   AND p.metodo = 'transferencia'
                 ORDER BY f.fecha_hora, f.id"
            );
            $stmt->execute([':suc' => $codSucursal, ':d' => $desde, ':h' => $hasta]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_column($filas, 'monto_cordoba'));
            break;

        case 'tarjeta':
            $stmt = $conn->prepare(
                "SELECT f.id AS factura_id, f.fecha_hora,
                        p.monto_cordoba
                 FROM pos_ventas_factura_pagos p
                 JOIN pos_ventas_facturas f ON f.id = p.id_factura
                 WHERE f.cod_sucursal = :suc
                   AND f.estado IN ('completada','bloqueada')
                   AND f.fecha_hora > :d AND f.fecha_hora <= :h
                   AND p.metodo = 'pos'
                 ORDER BY f.fecha_hora, f.id"
            );
            $stmt->execute([':suc' => $codSucursal, ':d' => $desde, ':h' => $hasta]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_column($filas, 'monto_cordoba'));
            break;

        case 'pedidosya':
            // Facturas cuyo método de pago sea pedidosya
            $stmt = $conn->prepare(
                "SELECT f.id AS factura_id, f.fecha_hora,
                        f.total AS monto_cordoba
                 FROM pos_ventas_facturas f
                 WHERE f.cod_sucursal = :suc
                   AND f.estado IN ('completada','bloqueada')
                   AND f.fecha_hora > :d AND f.fecha_hora <= :h
                   AND EXISTS (
                       SELECT 1 FROM pos_ventas_factura_pagos p2
                       WHERE p2.id_factura = f.id AND p2.metodo = 'pedidosya'
                   )
                 ORDER BY f.fecha_hora, f.id"
            );
            $stmt->execute([':suc' => $codSucursal, ':d' => $desde, ':h' => $hasta]);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_column($filas, 'monto_cordoba'));
            break;
    }

    echo json_encode([
        'ok'      => true,
        'concepto'=> $concepto,
        'filas'   => $filas,
        'total'   => $total,
    ]);

} catch (PDOException $e) {
    error_log('[cierre_get_ventas_concepto] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error en base de datos.']);
}

