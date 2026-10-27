<?php
// get_tipo_cambio.php — Retorna la tasa USD/NIO vigente (la mas reciente con fecha_desde <= hoy)
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $stmt = $conn->prepare(
        "SELECT tasa, fecha_desde
         FROM pos_ventas_tipo_cambio
         WHERE fecha_desde <= CURDATE()
         ORDER BY fecha_desde DESC
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            'success' => false,
            'message' => 'No hay tipo de cambio registrado. Contacte al administrador.'
        ]);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'tasa'         => (float)$row['tasa'],
        'fecha_desde'  => $row['fecha_desde']
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
