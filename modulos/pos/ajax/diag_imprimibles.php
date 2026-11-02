<?php
/**
 * DIAGNÓSTICO TEMPORAL - ELIMINAR DESPUÉS DE USAR
 * Acceder vía: pos.batidospitaya.com/modulos/pos/ajax/diag_imprimibles.php
 */
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json; charset=utf-8');

$resultado = [];

// 1. PHP version
$resultado['php_version'] = PHP_VERSION;
$resultado['str_starts_with_exists'] = function_exists('str_starts_with');

// 2. Últimas facturas
try {
    $stmt = $conn->query("SELECT id, nombre_cliente_general, cod_sucursal, estado, fecha_hora FROM pos_ventas_facturas ORDER BY id DESC LIMIT 5");
    $resultado['facturas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $resultado['error_facturas'] = $e->getMessage();
}

// 3. Probar el JOIN de sucursales
try {
    $stmt = $conn->query("SELECT f.id, f.cod_sucursal, s.nombre AS nombre_sucursal FROM pos_ventas_facturas f LEFT JOIN sucursales s ON s.codigo = f.cod_sucursal ORDER BY f.id DESC LIMIT 3");
    $resultado['facturas_con_sucursal'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $resultado['error_join_sucursal'] = $e->getMessage();
}

// 4. Verificar que existe la tabla endulzantes
try {
    $stmt = $conn->query("SELECT id, nombre, unidad_medida, factor FROM endulzantes WHERE activo = 1 LIMIT 5");
    $resultado['endulzantes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $resultado['error_endulzantes'] = $e->getMessage();
}

// 5. Verificar columnas de pos_ventas_factura_lineas
try {
    $stmt = $conn->query("DESCRIBE pos_ventas_factura_lineas");
    $resultado['columnas_lineas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $resultado['error_columnas'] = $e->getMessage();
}

// 6. Si hay facturas, probar query completo del endpoint con el último ID
if (!empty($resultado['facturas'])) {
    $idTest = (int)$resultado['facturas'][0]['id'];
    $resultado['id_test'] = $idTest;
    try {
        $stmtFac = $conn->prepare("
            SELECT
                f.id, f.tipo_cliente, f.id_cliente_club, f.nombre_cliente_general,
                f.subtotal, f.descuento_total, f.propina, f.porcentaje_propina,
                f.con_propina, f.total, f.fecha_hora, f.estado,
                COALESCE(c.nombre_completo, f.nombre_cliente_general) AS nombre_cliente,
                s.nombre AS nombre_sucursal
            FROM pos_ventas_facturas f
            LEFT JOIN clientesclub c ON c.id_clienteclub = f.id_cliente_club
            LEFT JOIN sucursales   s ON s.codigo = f.cod_sucursal
            WHERE f.id = :id
            LIMIT 1
        ");
        $stmtFac->execute([':id' => $idTest]);
        $resultado['query_cabecera_ok'] = $stmtFac->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $resultado['error_query_cabecera'] = $e->getMessage();
    }

    // Probar query líneas
    try {
        $stmtL = $conn->prepare("
            SELECT fl.id, fl.id_producto_presentacion, fl.nombre_producto_snapshot,
                   fl.cantidad, fl.precio_unitario_final, fl.precio_linea_total,
                   fl.notas, fl.orden_preparacion,
                   pp.Id_receta_producto AS id_receta,
                   g.id AS id_grupo, g.nombre AS nombre_grupo,
                   g.estacion_trabajo, g.lleva_endulzante,
                   fl.id_endulzante_producto, fl.nivel_endulzante
            FROM pos_ventas_factura_lineas fl
            JOIN producto_presentacion pp ON pp.id = fl.id_producto_presentacion
            LEFT JOIN catalogo_menu_pos cmp ON cmp.id = pp.id_catalogo_menu_pos
            LEFT JOIN subgrupo_presentacion_producto sg ON sg.id = cmp.id_subgrupo
            LEFT JOIN grupo_presentacion_producto g ON g.id = sg.id_grupo_presentacion_producto
            WHERE fl.id_factura = :id
            ORDER BY fl.orden_preparacion ASC, fl.id ASC
        ");
        $stmtL->execute([':id' => $idTest]);
        $resultado['query_lineas_ok'] = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $resultado['error_query_lineas'] = $e->getMessage();
    }
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

