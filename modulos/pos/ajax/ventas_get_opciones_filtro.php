<?php
// ventas_get_opciones_filtro.php — Opciones para los filtros tipo "list" del historial de ventas
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $columna = isset($_POST['columna']) ? trim($_POST['columna']) : '';

    $opciones = [];

    if ($columna === 'estado') {
        $opciones = [
            ['valor' => 'bloqueada', 'texto' => 'Bloqueada'],
            ['valor' => 'anulada',   'texto' => 'Anulada'],
        ];
    } elseif ($columna === 'tipo_cliente') {
        // Tipos de cliente reales derivados del menú usado en las ventas
        $stmtTc = $conn->query(
            "SELECT DISTINCT tc.nombre AS valor, tc.nombre AS texto
             FROM pos_ventas_facturas v
             JOIN pos_menu_precios mp ON mp.id_menu = v.id_menu_usado
                  AND (mp.cod_sucursal = v.cod_sucursal OR mp.cod_sucursal IS NULL)
                  AND mp.fecha_desde <= CURDATE()
                  AND (mp.fecha_hasta IS NULL OR mp.fecha_hasta >= CURDATE())
             JOIN pos_tipos_cliente tc ON tc.id = mp.id_tipo_cliente
             ORDER BY tc.nombre ASC"
        );
        $opciones = $stmtTc->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($columna === 'metodo_pago') {
        $stmtMp = $conn->query(
            "SELECT DISTINCT pgf.metodo AS valor, pgf.metodo AS texto
             FROM pos_ventas_factura_pagos pgf
             WHERE pgf.monto_cordoba > 0
             ORDER BY pgf.metodo ASC"
        );
        $opciones = $stmtMp->fetchAll(PDO::FETCH_ASSOC);
    } else {
        throw new Exception("Columna de filtro no soportada: $columna");
    }

    echo json_encode(['success' => true, 'opciones' => $opciones]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

