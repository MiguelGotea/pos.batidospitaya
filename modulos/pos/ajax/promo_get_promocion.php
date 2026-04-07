<?php
// ajax/promo_get_promocion.php â€” Carga una promociÃ³n y sus condiciones para editar
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['pos_colaborador_id'])) throw new Exception('No autorizado');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) throw new Exception('ID invÃ¡lido');

    $stmtP = $conn->prepare('SELECT * FROM promo_promociones WHERE id = :id');
    $stmtP->execute([':id' => $id]);
    $promo = $stmtP->fetch();
    if (!$promo) throw new Exception('PromociÃ³n no encontrada');

    $stmtC = $conn->prepare(
        'SELECT id, tipo_cond, nombre_cond, opcion_id, valor_json, orden
         FROM promo_condiciones
         WHERE promo_id = :id
         ORDER BY orden ASC'
    );
    $stmtC->execute([':id' => $id]);
    $condiciones = $stmtC->fetchAll();

    // Decodificar valor_json para enviar como objeto
    foreach ($condiciones as &$c) {
        $c['valor_json'] = json_decode($c['valor_json'], true);
    }

    echo json_encode(['success' => true, 'promo' => $promo, 'condiciones' => $condiciones]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
