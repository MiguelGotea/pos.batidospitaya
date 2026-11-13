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
        // Menús reales que han sido usados en ventas (cada menú = un tipo de cliente/canal)
        $stmtTc = $conn->query(
            "SELECT DISTINCT m.nombre AS valor, m.nombre AS texto
             FROM pos_ventas_facturas v
             JOIN pos_menu m ON m.id = v.id_menu_usado
             WHERE v.id_menu_usado IS NOT NULL
             ORDER BY m.nombre ASC"
        );
        $opciones = $stmtTc->fetchAll(PDO::FETCH_ASSOC);
    } else {
        throw new Exception("Columna de filtro no soportada: $columna");
    }

    echo json_encode(['success' => true, 'opciones' => $opciones]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

