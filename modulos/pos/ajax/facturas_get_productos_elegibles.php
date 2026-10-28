<?php
// facturas_get_productos_elegibles.php
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';
    $sucursal = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? null);

    $sql = "SELECT pp.id,
                   pp.SKU,
                   pp.Nombre,
                   COALESCE(pm.Nombre, pp.Nombre) AS nombre_maestro,
                   COALESCE(g.id, 0) AS id_grupo,
                   COALESCE(g.nombre, 'Otros') AS grupo,
                   COALESCE(sg.id, 0) AS id_subgrupo,
                   COALESCE(sg.nombre, 'General') AS subgrupo,
                   COALESCE(up.nombre, 'Unidad') AS nombre_unidad,
                   pp.presentacion,
                   CASE WHEN pp.Id_receta_producto IS NOT NULL THEN 1 ELSE 0 END AS es_personalizable,
                   COALESCE(psuc.precio, pglob.precio, 0.00) AS precio_venta,
                   pp.id_catalogo_menu_pos,
                   CASE 
                       WHEN psuc.precio IS NOT NULL THEN 'sucursal'
                       WHEN pglob.precio IS NOT NULL THEN 'global'
                       ELSE 'sin_precio'
                   END AS origen_precio
            FROM producto_presentacion pp
            LEFT JOIN producto_maestro pm 
                ON pp.id_producto_maestro = pm.id
            LEFT JOIN catalogo_menu_pos cmp
                ON pp.id_catalogo_menu_pos = cmp.id
            LEFT JOIN subgrupo_presentacion_producto sg
                ON cmp.id_subgrupo = sg.id
            LEFT JOIN grupo_presentacion_producto g
                ON sg.id_grupo_presentacion_producto = g.id
            LEFT JOIN unidad_producto up 
                ON pp.id_unidad_producto = up.id
            LEFT JOIN pos_ventas_precios_producto psuc
                ON psuc.id_producto_presentacion = pp.id
               AND psuc.cod_sucursal = :sucursal
               AND psuc.fecha_desde <= CURDATE()
               AND (psuc.fecha_hasta IS NULL OR psuc.fecha_hasta >= CURDATE())
            LEFT JOIN pos_ventas_precios_producto pglob
                ON pglob.id_producto_presentacion = pp.id
               AND pglob.cod_sucursal IS NULL
               AND pglob.fecha_desde <= CURDATE()
               AND (pglob.fecha_hasta IS NULL OR pglob.fecha_hasta >= CURDATE())
            WHERE pp.es_vendible = 'SI'
              AND pp.Activo = 'SI'
              AND (pm.id IS NULL OR pm.Estado = 1)
              AND (psuc.precio IS NOT NULL OR pglob.precio IS NOT NULL)";

    $params = [
        ':sucursal' => $sucursal
    ];

    if ($busqueda !== '') {
        $sql .= " AND (pp.Nombre LIKE :q OR pm.Nombre LIKE :q2 OR pp.SKU LIKE :q3 OR g.nombre LIKE :q4 OR sg.nombre LIKE :q5)";
        $params[':q']  = "%$busqueda%";
        $params[':q2'] = "%$busqueda%";
        $params[':q3'] = "%$busqueda%";
        $params[':q4'] = "%$busqueda%";
        $params[':q5'] = "%$busqueda%";
    }

    $sql .= " ORDER BY CASE WHEN g.id IS NULL THEN 1 ELSE 0 END ASC, g.nombre ASC, sg.nombre ASC, pp.Nombre ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $productos
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
