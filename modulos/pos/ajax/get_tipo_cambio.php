<?php
// get_tipo_cambio.php — Retorna la tasa USD/NIO vigente del día (o la más reciente)
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    // Tasa del día exacto; si no existe, la más reciente anterior a hoy
    $stmt = $conn->prepare(
        "SELECT tasa, fecha
         FROM pos_ventas_tipo_cambio
         WHERE fecha <= CURDATE()
         ORDER BY fecha DESC
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
        'success' => true,
        'tasa'    => (float)$row['tasa'],
        'fecha'   => $row['fecha']
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

