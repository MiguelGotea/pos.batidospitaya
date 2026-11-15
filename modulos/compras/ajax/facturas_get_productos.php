<?php
// facturas_get_productos.php — devuelve presentaciones de producto para agregar a la factura
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $busqueda = trim($_GET['q'] ?? '');

    $sql = "SELECT pp.id,
                   pp.Nombre          AS nombre_presentacion,
                   pm.Nombre          AS nombre_maestro,
                   up.nombre          AS nombre_unidad,
                   pp.costo_unitario  AS costo_unitario_ref
            FROM producto_presentacion pp
            INNER JOIN producto_maestro pm ON pp.id_producto_maestro = pm.id
            LEFT  JOIN unidad_producto  up ON pp.id_unidad_producto  = up.id
            WHERE pp.vigente = 1
              AND pm.vigente = 1
              " . ($busqueda !== '' ? "AND (pp.Nombre LIKE :q OR pm.Nombre LIKE :q)" : "") . "
            ORDER BY pm.Nombre ASC, pp.Nombre ASC
            LIMIT 80";

    $stmt = $conn->prepare($sql);
    if ($busqueda !== '') {
        $stmt->bindValue(':q', '%' . $busqueda . '%');
    }
    $stmt->execute();

    echo json_encode([
        'success'  => true,
        'productos' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
?>
