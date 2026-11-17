<?php
// mermas_get_tipos.php
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $stmt = $conn->query("
        SELECT id, nombre, descripcion, categoria_merma, es_cobro, necesita_aprobacion
        FROM mermas_tipos_incidencia
        WHERE activo = 1
        ORDER BY categoria_merma, nombre");
    echo json_encode(['success' => true, 'tipos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
