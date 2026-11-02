<?php
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json; charset=utf-8');
$r = [];

// Columnas de clientesclub
try {
    $s = $conn->query("DESCRIBE clientesclub");
    $r['columnas_clientesclub'] = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'Field');
} catch(Exception $e) { $r['err_clientesclub'] = $e->getMessage(); }

// Muestra los primeros registros para ver qué tiene
try {
    $s = $conn->query("SELECT * FROM clientesclub LIMIT 2");
    $r['muestra_clientesclub'] = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) { $r['err_muestra'] = $e->getMessage(); }

// Ver notas de la factura id=3 y sus lineas
try {
    $s = $conn->query("SELECT id, notas, id_endulzante_producto FROM pos_ventas_factura_lineas WHERE id_factura = 3");
    $r['lineas_factura3'] = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) { $r['err_lineas'] = $e->getMessage(); }

echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

