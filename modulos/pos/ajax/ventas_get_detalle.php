<?php
// ventas_get_detalle.php — Detalle completo de una venta POS
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception('ID de venta inválido.');

    // Cabecera
    $stmtV = $conn->prepare(
        "SELECT v.id, v.fecha_hora, v.tipo_cliente, v.total, v.subtotal,
                v.propina, v.porcentaje_propina, v.con_propina, v.estado, v.cod_sucursal,
                COALESCE(v.nombre_cliente_general, CONCAT(c.nombre, ' ', c.apellido)) AS cliente,
                CONCAT(o.Nombre, ' ', o.Apellido) AS cajero
         FROM pos_ventas_facturas v
         LEFT JOIN clientesclub c ON v.id_cliente_club = c.id
         LEFT JOIN Operarios o ON v.cod_cajero = o.CodOperario
         WHERE v.id = :id"
    );
    $stmtV->execute([':id' => $id]);
    $venta = $stmtV->fetch(PDO::FETCH_ASSOC);
    if (!$venta) throw new Exception('Venta no encontrada.');

    // Líneas
    $stmtL = $conn->prepare(
        "SELECT l.nombre_producto_snapshot, l.cantidad,
                l.precio_unitario_final, l.precio_linea_total,
                l.es_canje_puntos, l.notas
         FROM pos_ventas_factura_lineas l
         WHERE l.id_factura = :id
         ORDER BY l.id ASC"
    );
    $stmtL->execute([':id' => $id]);
    $lineas = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    // Pagos
    $stmtP = $conn->prepare(
        "SELECT metodo, moneda, monto_original, monto_cordoba, tipo_cambio_aplicado, referencia, codigo_cupon
         FROM pos_ventas_factura_pagos
         WHERE id_factura = :id
         ORDER BY id ASC"
    );
    $stmtP->execute([':id' => $id]);
    $pagos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'venta'   => $venta,
        'lineas'  => $lineas,
        'pagos'   => $pagos,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

