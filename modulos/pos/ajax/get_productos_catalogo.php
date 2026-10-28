<?php
/**
 * get_productos_catalogo.php
 * Devuelve los productos (producto_presentacion) que pertenecen a un
 * catalogo_menu_pos específico, con precio vigente para la sucursal.
 */
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // Acepta ids_catalogo (uno o varios separados por coma) o id_catalogo (legado)
    $rawIds = isset($_GET['ids_catalogo']) ? $_GET['ids_catalogo']
             : (isset($_GET['id_catalogo'])  ? $_GET['id_catalogo'] : '0');
    // Filtrar: solo enteros positivos
    $idsLimpios = array_filter(
        array_map('intval', explode(',', $rawIds)),
        fn($v) => $v > 0
    );
    if (empty($idsLimpios)) {
        throw new Exception('ID de catálogo no válido.');
    }
    $placeholders = implode(',', array_fill(0, count($idsLimpios), '?'));

    $sucursal = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? null);

    $stmt = $conn->prepare("
        SELECT pp.id,
               pp.SKU,
               pp.Nombre,
               COALESCE(up.nombre, 'Unidad') AS nombre_unidad,
               pp.presentacion,
               CASE WHEN pp.Id_receta_producto IS NOT NULL THEN 1 ELSE 0 END AS es_personalizable,
               COALESCE(psuc.precio, pglob.precio, 0.00) AS precio_venta,
               pp.id_catalogo_menu_pos,
               CASE
                   WHEN psuc.precio IS NOT NULL THEN 'sucursal'
                   WHEN pglob.precio IS NOT NULL THEN 'global'
                   ELSE 'sin_precio'
               END AS origen_precio,
               COALESCE(
                   (SELECT puntos FROM pos_ventas_puntos_reglas
                     WHERE tipo_regla = 'acumulacion'
                       AND id_catalogo_menu_pos = pp.id_catalogo_menu_pos
                       AND id_producto = pp.id
                       AND fecha_desde <= CURDATE()
                       AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
                     ORDER BY fecha_desde DESC LIMIT 1),
                   (SELECT puntos FROM pos_ventas_puntos_reglas
                     WHERE tipo_regla = 'acumulacion'
                       AND id_catalogo_menu_pos = pp.id_catalogo_menu_pos
                       AND id_producto IS NULL
                       AND fecha_desde <= CURDATE()
                       AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
                     ORDER BY fecha_desde DESC LIMIT 1)
               ) AS puntos_acumulacion,
               COALESCE(
                   (SELECT puntos FROM pos_ventas_puntos_reglas
                     WHERE tipo_regla = 'canje'
                       AND id_catalogo_menu_pos = pp.id_catalogo_menu_pos
                       AND id_producto = pp.id
                       AND fecha_desde <= CURDATE()
                       AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
                     ORDER BY fecha_desde DESC LIMIT 1),
                   (SELECT puntos FROM pos_ventas_puntos_reglas
                     WHERE tipo_regla = 'canje'
                       AND id_catalogo_menu_pos = pp.id_catalogo_menu_pos
                       AND id_producto IS NULL
                       AND fecha_desde <= CURDATE()
                       AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
                     ORDER BY fecha_desde DESC LIMIT 1)
               ) AS puntos_canje
        FROM producto_presentacion pp
        LEFT JOIN unidad_producto up
            ON up.id = pp.id_unidad_producto
        LEFT JOIN pos_ventas_precios_producto psuc
            ON psuc.id_producto_presentacion = pp.id
           AND psuc.cod_sucursal = ?
           AND psuc.fecha_desde <= CURDATE()
           AND (psuc.fecha_hasta IS NULL OR psuc.fecha_hasta >= CURDATE())
        LEFT JOIN pos_ventas_precios_producto pglob
            ON pglob.id_producto_presentacion = pp.id
           AND pglob.cod_sucursal IS NULL
           AND pglob.fecha_desde <= CURDATE()
           AND (pglob.fecha_hasta IS NULL OR pglob.fecha_hasta >= CURDATE())
        WHERE pp.id_catalogo_menu_pos IN ($placeholders)
          AND pp.es_vendible = 'SI'
          AND pp.Activo = 'SI'
          AND (psuc.precio IS NOT NULL OR pglob.precio IS NOT NULL)
        ORDER BY pp.Nombre ASC
    ");
    $stmt->execute(array_merge([$sucursal], array_values($idsLimpios)));
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data'    => $productos
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

