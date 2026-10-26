<?php
/**
 * get_receta_producto.php — Datos de Receta para Personalización en el POS
 *
 * Devuelve:
 *  - ingredientes: componentes de la receta base del producto
 *  - endulzantes: endulzantes activos (lista global + endulzante sugerido por asignación)
 *  - extras: lista de extras activos con precio (SÍ alteran el monto total)
 *  - ingredientes_alternativos: catálogo para sustitución (presentacion_receta=1)
 *
 * NOTAS PARA DESARROLLO FUTURO:
 *  - La sustitución de ingredientes base actualmente NO altera el precio.
 *    Solo los extras tienen costo adicional.
 *  - El catálogo alternativo muestra TODOS los de presentacion_receta=1 sin filtro
 *    de compatibilidad por ahora (pendiente definir reglas de negocio).
 *  - En producción, personalización debe registrarse en tabla propia ligada al pedido.
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido.');
    }

    $idPresentacion = isset($_GET['id_presentacion']) ? (int)$_GET['id_presentacion'] : 0;
    if ($idPresentacion <= 0) {
        throw new Exception('ID de producto no válido.');
    }

    // =================================================================
    // 1. Receta maestra del producto
    // =================================================================
    $stmtReceta = $conn->prepare("
        SELECT
            rpg.id          AS id_receta,
            rpg.nombre      AS nombre_receta,
            rpg.descripcion AS descripcion_receta
        FROM receta_producto_global rpg
        WHERE rpg.id_presentacion_producto = :id
        LIMIT 1
    ");
    $stmtReceta->execute([':id' => $idPresentacion]);
    $receta = $stmtReceta->fetch(PDO::FETCH_ASSOC);

    $ingredientes = [];
    if ($receta) {
        // =================================================================
        // 2. Componentes (ingredientes) de la receta base
        // =================================================================
        $stmtComp = $conn->prepare("
            SELECT
                crp.id                        AS id_componente,
                crp.nombre                    AS nombre_componente,
                crp.id_presentacion_producto  AS id_ingrediente,
                crp.cantidad,
                crp.orden,
                crp.notas                     AS nota_ingrediente,
                pp.Nombre                     AS nombre_ingrediente,
                pp.SKU                        AS sku_ingrediente,
                COALESCE(up.nombre, 'Unidad') AS unidad_ingrediente
            FROM componentes_receta_producto crp
            JOIN producto_presentacion pp
                ON pp.id = crp.id_presentacion_producto
            LEFT JOIN unidad_producto up
                ON up.id = pp.id_unidad_producto
            WHERE crp.id_receta_producto_global = :id_receta
            ORDER BY crp.orden ASC, crp.id ASC
        ");
        $stmtComp->execute([':id_receta' => $receta['id_receta']]);
        $ingredientes = $stmtComp->fetchAll(PDO::FETCH_ASSOC);
    }

    // =================================================================
    // 3. Endulzantes activos (lista completa para dropdown)
    // =================================================================
    $stmtEndulzantes = $conn->prepare("
        SELECT id, nombre, nombre_opcion, unidad_medida
        FROM endulzantes
        WHERE activo = 1
        ORDER BY orden ASC, nombre ASC, id ASC
    ");
    $stmtEndulzantes->execute();
    $endulzantes = $stmtEndulzantes->fetchAll(PDO::FETCH_ASSOC);

    // Endulzante sugerido por defecto (asignación producto > subgrupo > grupo)
    $stmtEndDefault = $conn->prepare("
        SELECT ea.endulzante_id
        FROM endulzantes_asignaciones ea
        WHERE ea.activo = 1
          AND (
                ea.producto_id = :pid
             OR (ea.producto_id IS NULL AND ea.subgrupo_id = (
                    SELECT id_subgrupo_presentacion_producto
                    FROM producto_presentacion WHERE id = :pid2 LIMIT 1
                ))
             OR (ea.producto_id IS NULL AND ea.subgrupo_id IS NULL AND ea.grupo_id = (
                    SELECT sg.id_grupo_presentacion_producto
                    FROM producto_presentacion pp2
                    JOIN subgrupo_presentacion_producto sg ON sg.id = pp2.id_subgrupo_presentacion_producto
                    WHERE pp2.id = :pid3 LIMIT 1
                ))
          )
        ORDER BY
            CASE WHEN ea.producto_id  IS NOT NULL THEN 0
                 WHEN ea.subgrupo_id  IS NOT NULL THEN 1
                 ELSE 2 END ASC
        LIMIT 1
    ");
    $stmtEndDefault->execute([':pid' => $idPresentacion, ':pid2' => $idPresentacion, ':pid3' => $idPresentacion]);
    $endulzanteDefault = $stmtEndDefault->fetchColumn();

    // =================================================================
    // 4. Extras activos (precio propio — SÍ alteran el total de la orden)
    // =================================================================
    $stmtExtras = $conn->prepare("
        SELECT id, nombre, precio, grupo
        FROM extras
        WHERE activo = 1
        ORDER BY COALESCE(grupo,'') ASC, precio ASC, nombre ASC
    ");
    $stmtExtras->execute();
    $extras = $stmtExtras->fetchAll(PDO::FETCH_ASSOC);

    // =================================================================
    // 5. Catálogo de ingredientes alternativos para sustitución
    //    (presentacion_receta = 1)
    //    PENDIENTE: filtrar por compatibilidad tipo/categoría cuando se definan las reglas.
    // =================================================================
    $stmtAlt = $conn->prepare("
        SELECT
            pp.id,
            pp.Nombre,
            pp.SKU,
            COALESCE(up.nombre, 'Unidad') AS unidad
        FROM producto_presentacion pp
        LEFT JOIN unidad_producto up ON up.id = pp.id_unidad_producto
        WHERE pp.presentacion_receta = 1
          AND pp.Activo = 'SI'
        ORDER BY pp.Nombre ASC
    ");
    $stmtAlt->execute();
    $ingredientesAlternativos = $stmtAlt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'                   => true,
        'tiene_receta'              => !empty($receta),
        'receta'                    => $receta,
        'ingredientes'              => $ingredientes,
        'endulzantes'               => $endulzantes,
        'endulzante_default_id'     => $endulzanteDefault ? (int)$endulzanteDefault : null,
        'extras'                    => $extras,
        'ingredientes_alternativos' => $ingredientesAlternativos,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
