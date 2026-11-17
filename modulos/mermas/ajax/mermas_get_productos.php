<?php
// mermas_get_productos.php
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $q = trim($_GET['q'] ?? '');

    $params = [];
    $extraWhere = '';

    if ($q !== '') {
        $extraWhere = "AND (pp.Nombre LIKE :q OR pp.SKU LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $stmt = $conn->prepare("
        SELECT pp.id, pp.SKU, pp.Nombre
        FROM producto_presentacion pp
        WHERE pp.presentacion_basica_inventario = 1
          AND pp.Activo = 'SI'
          $extraWhere
        ORDER BY pp.Nombre
        LIMIT 60");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();

    echo json_encode(['success' => true, 'productos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
