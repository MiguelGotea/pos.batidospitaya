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
        $extraWhere = "AND (pp.Nombre LIKE :q OR pp.presentacion LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    $stmt = $conn->prepare("
        SELECT pp.id, pp.Nombre, pp.presentacion
        FROM producto_presentacion pp
        INNER JOIN producto_maestro pm ON pp.id_producto_maestro = pm.id
        WHERE pp.vigente = 1
          AND pm.vigente = 1
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
