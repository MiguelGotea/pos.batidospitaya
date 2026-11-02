<?php
/**
 * DIAGNÓSTICO TEMPORAL - ELIMINAR DESPUÉS
 * Simula la llamada real del JS al endpoint con id_factura=3
 */
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json; charset=utf-8');

// Simular la petición
$_REQUEST['id_factura'] = 3;

// Incluir el endpoint directamente
ob_start();
include 'facturas_get_imprimibles.php';
$output = ob_get_clean();

// Mostrar bonito
$data = json_decode($output, true);
if ($data === null) {
    echo json_encode(['raw_output' => $output, 'json_error' => json_last_error_msg()], JSON_PRETTY_PRINT);
} else {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>

