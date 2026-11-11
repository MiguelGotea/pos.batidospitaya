<?php
// get_producto_membresia.php
// Retorna el primer producto activo y vendible con id_catalogo_menu_pos = 13 (Membresía)
// La membresía se busca en pos_menu_precios sin filtrar por menú específico
// ya que es un producto transversal que se agrega independientemente del menú activo
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $sucursal = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? null);

    $sql = "SELECT pp.id,
                   pp.SKU,
                   COALESCE(pm.Nombre, pp.Nombre) AS nombre,
                   COALESCE(up.nombre, 'Unidad') AS nombre_unidad,
                   pp.id_catalogo_menu_pos,
                   CASE WHEN pp.Id_receta_producto IS NOT NULL THEN 1 ELSE 0 END AS es_personalizable,
                   COALESCE(psuc.precio, pglob.precio) AS precio_venta
            FROM producto_presentacion pp
            LEFT JOIN producto_maestro pm
                ON pp.id_producto_maestro = pm.id
            LEFT JOIN unidad_producto up
                ON pp.id_unidad_producto = up.id
            -- Precio de membresía: busca en cualquier menú vigente para esta sucursal
            LEFT JOIN pos_menu_precios psuc
                ON psuc.id_catalogo_menu_pos = pp.id_catalogo_menu_pos
               AND psuc.cod_sucursal = :sucursal
               AND psuc.id_tipo_cliente IS NULL
               AND psuc.fecha_desde <= CURDATE()
               AND (psuc.fecha_hasta IS NULL OR psuc.fecha_hasta >= CURDATE())
            LEFT JOIN pos_menu_precios pglob
                ON pglob.id_catalogo_menu_pos = pp.id_catalogo_menu_pos
               AND pglob.cod_sucursal IS NULL
               AND pglob.id_tipo_cliente IS NULL
               AND pglob.fecha_desde <= CURDATE()
               AND (pglob.fecha_hasta IS NULL OR pglob.fecha_hasta >= CURDATE())
            WHERE pp.id_catalogo_menu_pos = 13
              AND pp.es_vendible = 'SI'
              AND pp.Activo = 'SI'
              AND (pm.id IS NULL OR pm.Estado = 1)
              AND (psuc.precio IS NOT NULL OR pglob.precio IS NOT NULL)
            ORDER BY pp.id ASC
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute([':sucursal' => $sucursal]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        echo json_encode([
            'success' => false,
            'message' => 'No se encontró un producto de membresía activo (Catálogo POS #13).'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data'    => $producto
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
