<?php
// ajax/promo_eliminar.php â€” Archivar (eliminaciÃ³n lÃ³gica) una promociÃ³n
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['pos_colaborador_id'])) throw new Exception('No autorizado');

    $id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $uid = $_SESSION['pos_colaborador_id'];
    if ($id <= 0) throw new Exception('ID invÃ¡lido');

    $stmtChk = $conn->prepare('SELECT estado FROM promo_promociones WHERE id = :id');
    $stmtChk->execute([':id' => $id]);
    $promo = $stmtChk->fetch();
    if (!$promo) throw new Exception('PromociÃ³n no encontrada');

    $stmt = $conn->prepare(
        'UPDATE promo_promociones SET estado = :est, usuario_modificacion = :uid WHERE id = :id'
    );
    $stmt->execute([':est' => 'archivada', ':uid' => $uid, ':id' => $id]);

    echo json_encode(['success' => true, 'message' => 'PromociÃ³n archivada']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
