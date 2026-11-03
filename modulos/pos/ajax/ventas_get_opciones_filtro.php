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
        $opciones = [
            ['valor' => 'general', 'texto' => 'General'],
            ['valor' => 'club',    'texto' => 'Club Pitaya'],
        ];
    } else {
        throw new Exception("Columna de filtro no soportada: $columna");
    }

    echo json_encode(['success' => true, 'opciones' => $opciones]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
