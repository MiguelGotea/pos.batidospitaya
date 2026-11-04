<?php
/**
 * get_recetas_todas.php — Precarga masiva de recetas para el POS
 *
 * Devuelve en UNA sola respuesta todos los datos necesarios para el modal
 * de personalización de TODOS los productos personalizables activos:
 *   - Por cada id_presentacion: receta, ingredientes, si lleva_endulzante
 *   - Endulzantes activos (lista única, compartida por todos los productos)
 *   - Extras activos (lista única, compartida por todos los productos)
 *   - Ingredientes alternativos (lista única, compartida)
 *
 * El JS recibe esto al cargar la página y llena _mpCache directamente,
 * de modo que al abrir cualquier producto el modal es INSTANTÁNEO.
 *
 * Resultado JSON:
 * {
 *   success: true,
 *   endulzantes: [...],          // lista global de endulzantes activos
 *   endulzante_default_id: N,    // id del endulzante con mayor prioridad
 *   extras: [...],               // lista global de extras activos
 *   ingredientes_alternativos: [...],
 *   recetas: {                   // Map: id_presentacion → datos de receta
 *     "123": { tiene_receta, receta, ingredientes, lleva_endulzante },
 *     ...
 *   }
 * }
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Método no permitido.');
    }

    // =================================================================
    // 1. Endulzantes activos (lista global — todos los productos los comparten)
    // =================================================================
    $stmtEnd = $conn->prepare("
        SELECT id, nombre, nombre_opcion, unidad_medida, COALESCE(prioridad, 0) AS prioridad
        FROM endulzantes
        WHERE activo = 1
        ORDER BY orden ASC, nombre ASC, id ASC
    ");
    $stmtEnd->execute();
    $endulzantes = $stmtEnd->fetchAll(PDO::FETCH_ASSOC);

    // Determinar endulzante default (mayor prioridad)
    $endulzanteDefaultId = null;
    $maxPrioridad = 0;
    foreach ($endulzantes as $end) {
        $prio = (int)($end['prioridad'] ?? 0);
        if ($prio > $maxPrioridad) {
            $maxPrioridad = $prio;
            $endulzanteDefaultId = (int)$end['id'];
        }
    }

    // =================================================================
    // 2. Extras activos (lista global — compartidos por todos)
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
    // 3. Ingredientes alternativos (lista global para sustitución)
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

    // =================================================================
    // 4. Recetas maestras de todos los productos personalizables activos
    //    (solo los que tienen Id_receta_producto != NULL)
    // =================================================================
    $stmtRecetas = $conn->prepare("
        SELECT
            rpg.id              AS id_receta,
            rpg.id_presentacion_producto AS id_presentacion,
            rpg.nombre          AS nombre_receta,
            rpg.descripcion     AS descripcion_receta
        FROM receta_producto_global rpg
        INNER JOIN producto_presentacion pp
            ON pp.id = rpg.id_presentacion_producto
           AND pp.Activo = 'SI'
           AND pp.es_vendible = 'SI'
    ");
    $stmtRecetas->execute();
    $recetasRaw = $stmtRecetas->fetchAll(PDO::FETCH_ASSOC);

    // Indexar recetas por id_presentacion
    $recetasPorPres = [];
    $idsReceta      = [];
    foreach ($recetasRaw as $r) {
        $pid = (int)$r['id_presentacion'];
        $recetasPorPres[$pid] = [
            'id_receta'         => (int)$r['id_receta'],
            'nombre_receta'     => $r['nombre_receta'],
            'descripcion_receta'=> $r['descripcion_receta'],
        ];
        $idsReceta[] = (int)$r['id_receta'];
    }

    // =================================================================
    // 5. Componentes (ingredientes) de TODAS las recetas en una sola query
    // =================================================================
    $ingredientesPorReceta = [];
    if (!empty($idsReceta)) {
        $phReceta = implode(',', $idsReceta);
        $stmtComp = $conn->prepare("
            SELECT
                crp.id_receta_producto_global AS id_receta,
                crp.id                        AS id_componente,
                crp.nombre                    AS nombre_componente,
                crp.id_presentacion_producto  AS id_ingrediente,
                crp.cantidad,
                crp.orden,
                crp.orden_comanda,
                crp.tipo_comanda,
                crp.notas                     AS nota_ingrediente,
                pp.Nombre                     AS nombre_ingrediente,
                pp.SKU                        AS sku_ingrediente,
                COALESCE(up.nombre, 'Unidad') AS unidad_ingrediente
            FROM componentes_receta_producto crp
            JOIN producto_presentacion pp
                ON pp.id = crp.id_presentacion_producto
            LEFT JOIN unidad_producto up
                ON up.id = pp.id_unidad_producto
            WHERE crp.id_receta_producto_global IN ($phReceta)
              AND crp.orden_comanda IS NOT NULL
            ORDER BY crp.id_receta_producto_global ASC, crp.orden_comanda ASC, crp.id ASC
        ");
        $stmtComp->execute();
        foreach ($stmtComp->fetchAll(PDO::FETCH_ASSOC) as $comp) {
            $rId = (int)$comp['id_receta'];
            if (!isset($ingredientesPorReceta[$rId])) {
                $ingredientesPorReceta[$rId] = [];
            }
            $ingredientesPorReceta[$rId][] = $comp;
        }
    }

    // =================================================================
    // 6. lleva_endulzante por id_presentacion (una sola query masiva)
    // =================================================================
    $llevaEndulzantePorPres = [];
    if (!empty($recetasPorPres)) {
        $pids = array_keys($recetasPorPres);
        $phPids = implode(',', $pids);
        $stmtGrupos = $conn->prepare("
            SELECT pp.id AS id_presentacion,
                   COALESCE(g.lleva_endulzante, 0) AS lleva_endulzante
            FROM producto_presentacion pp
            LEFT JOIN catalogo_menu_pos cmp ON pp.id_catalogo_menu_pos = cmp.id
            LEFT JOIN subgrupo_presentacion_producto sg ON cmp.id_subgrupo = sg.id
            LEFT JOIN grupo_presentacion_producto g ON sg.id_grupo_presentacion_producto = g.id
            WHERE pp.id IN ($phPids)
        ");
        $stmtGrupos->execute();
        foreach ($stmtGrupos->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $llevaEndulzantePorPres[(int)$row['id_presentacion']] = (int)$row['lleva_endulzante'];
        }
    }

    // =================================================================
    // 7. Armar el mapa final por id_presentacion (mismo formato que
    //    get_receta_producto.php devuelve individualmente)
    // =================================================================
    $recetasMap = [];
    foreach ($recetasPorPres as $pid => $receta) {
        $rId          = $receta['id_receta'];
        $llevaEndulz  = $llevaEndulzantePorPres[$pid] ?? 0;
        $ingredientes = $ingredientesPorReceta[$rId] ?? [];

        $recetasMap[(string)$pid] = [
            'success'                   => true,
            'tiene_receta'              => true,
            'receta'                    => $receta,
            'ingredientes'              => $ingredientes,
            // Endulzantes: solo se incluyen si el grupo lleva endulzante;
            // si no, se envía array vacío para mantener consistencia con
            // el formato del endpoint individual.
            'endulzantes'               => $llevaEndulz === 1 ? $endulzantes : [],
            'endulzante_default_id'     => $llevaEndulz === 1 ? $endulzanteDefaultId : null,
            'extras'                    => $extras,
            'ingredientes_alternativos' => $ingredientesAlternativos,
        ];
    }

    // También incluir productos personalizables sin receta asignada
    // (es_personalizable=1 pero sin receta → modal sin ingredientes)
    // No es necesario: si no hay receta, el endpoint individual ya maneja ese caso
    // y el _mpCache no tiene esa entrada → se consulta normalmente (caso raro).

    echo json_encode([
        'success'                   => true,
        'recetas'                   => $recetasMap,
        // Los siguientes son datos globales reutilizables por el JS
        // si necesita precargar productos sin receta también
        'endulzantes'               => $endulzantes,
        'endulzante_default_id'     => $endulzanteDefaultId,
        'extras'                    => $extras,
        'ingredientes_alternativos' => $ingredientesAlternativos,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

