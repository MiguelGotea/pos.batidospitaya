<?php
/**
 * get_combo_config.php
 * Devuelve la configuración completa de un combo: slots y los productos
 * elegibles en cada slot, resolviendo los 4 tipos de fuente.
 * Gate: tienda autenticada. El menú fue elegido desde index.php.
 *
 * GET ?id_catalogo_menu_pos=X&id_menu=Y
 */
require_once '../../../core/auth/auth_pos.php';
posRequiereMenuAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $idCatalogo = isset($_GET['id_catalogo_menu_pos']) ? intval($_GET['id_catalogo_menu_pos']) : 0;
    if ($idCatalogo <= 0) {
        throw new Exception('ID de catálogo no válido.');
    }

    $sucursal = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? null);
    $id_menu = isset($_GET['id_menu']) ? (int)$_GET['id_menu'] : 0;
    if ($id_menu <= 0) {
        throw new Exception('ID de menú no proporcionado');
    }

    // ── 1. Validar que el catálogo existe y pertenece a un grupo combo ──────
    $stmtCombo = $conn->prepare("
        SELECT cmp.id,
               cmp.nombre,
               cmp.observaciones,
               COALESCE(psuc.precio, pglob.precio, 0.00) AS precio_venta
        FROM catalogo_menu_pos cmp
        JOIN subgrupo_presentacion_producto sg
            ON sg.id = cmp.id_subgrupo
        JOIN grupo_presentacion_producto g
            ON g.id = sg.id_grupo_presentacion_producto
        LEFT JOIN pos_menu_precios psuc
            ON psuc.id_menu = :id_menu
           AND psuc.id_catalogo_menu_pos = cmp.id
           AND psuc.cod_sucursal = :sucursal
           AND psuc.fecha_desde <= CURDATE()
           AND (psuc.fecha_hasta IS NULL OR psuc.fecha_hasta >= CURDATE())
        LEFT JOIN pos_menu_precios pglob
            ON pglob.id_menu = :id_menu
           AND pglob.id_catalogo_menu_pos = cmp.id
           AND pglob.cod_sucursal IS NULL
           AND pglob.fecha_desde <= CURDATE()
           AND (pglob.fecha_hasta IS NULL OR pglob.fecha_hasta >= CURDATE())
        WHERE cmp.id = :id
          AND cmp.activo = 1
          AND g.es_combo = 1
        LIMIT 1
    ");
    $stmtCombo->execute([':id' => $idCatalogo, ':sucursal' => $sucursal, ':id_menu' => $id_menu]);
    $combo = $stmtCombo->fetch(PDO::FETCH_ASSOC);

    if (!$combo) {
        throw new Exception('El catálogo no existe, no está activo o no es un combo.');
    }

    // ── 2. Obtener slots del combo, ordenados ───────────────────────────────
    $stmtSlots = $conn->prepare("
        SELECT id, nombre, descripcion, min_seleccion, max_seleccion, orden
        FROM pos_combo_slots
        WHERE id_catalogo_menu_pos = :id
        ORDER BY orden ASC, id ASC
    ");
    $stmtSlots->execute([':id' => $idCatalogo]);
    $slots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. Para cada slot, resolver sus items elegibles ─────────────────────
    // SQL base: productos vigentes del catálogo (sin combos).
    // El menú ya selecciona el tipo de cliente — no se filtra por id_tipo_cliente.
    $sqlBaseProductos = "
        SELECT pp.id                       AS id_presentacion,
               pp.id_catalogo_menu_pos,
               COALESCE(cmp.nombre, pp.Nombre) AS nombre_catalogo,
               cmp.tamano_producto,
               CASE WHEN pp.Id_receta_producto IS NOT NULL THEN 1 ELSE 0 END AS es_personalizable,
               COALESCE(psuc.precio, pglob.precio, 0.00) AS precio_venta
        FROM producto_presentacion pp
        JOIN catalogo_menu_pos cmp
            ON cmp.id = pp.id_catalogo_menu_pos
           AND cmp.activo = 1
        JOIN subgrupo_presentacion_producto sg
            ON sg.id = cmp.id_subgrupo
        JOIN grupo_presentacion_producto g
            ON g.id = sg.id_grupo_presentacion_producto
           AND g.es_combo = 0
        LEFT JOIN pos_menu_precios psuc
            ON psuc.id_menu = :id_menu
           AND psuc.id_catalogo_menu_pos = pp.id_catalogo_menu_pos
           AND psuc.cod_sucursal = :sucursal
           AND psuc.fecha_desde <= CURDATE()
           AND (psuc.fecha_hasta IS NULL OR psuc.fecha_hasta >= CURDATE())
        LEFT JOIN pos_menu_precios pglob
            ON pglob.id_menu = :id_menu
           AND pglob.id_catalogo_menu_pos = pp.id_catalogo_menu_pos
           AND pglob.cod_sucursal IS NULL
           AND pglob.fecha_desde <= CURDATE()
           AND (pglob.fecha_hasta IS NULL OR pglob.fecha_hasta >= CURDATE())
        WHERE pp.es_vendible = 'SI'
          AND pp.Activo = 'SI'
          AND (psuc.precio IS NOT NULL OR pglob.precio IS NOT NULL)
    ";

    // Obtener items de slot con upcharge y es_default
    $stmtSlotItems = $conn->prepare("
        SELECT id, tipo_fuente,
               id_catalogo, id_subgrupo, tamano_producto,
               nombre_catalogo_familia, id_grupo_mayor,
               upcharge, es_default, orden
        FROM pos_combo_slot_items
        WHERE id_slot = :id_slot
        ORDER BY orden ASC, id ASC
    ");

    foreach ($slots as &$slot) {
        $stmtSlotItems->execute([':id_slot' => $slot['id']]);
        $slotItems = $stmtSlotItems->fetchAll(PDO::FETCH_ASSOC);

        $productosSlot = [];

        foreach ($slotItems as $slotItem) {
            $productosResueltos = [];

            switch ($slotItem['tipo_fuente']) {

                // ── Tipo 1: catálogo específico por ID ─────────────────────
                case 'catalogo_especifico':
                    $stmt = $conn->prepare($sqlBaseProductos . "
                        AND pp.id_catalogo_menu_pos = :id_catalogo
                        ORDER BY pp.Nombre ASC
                    ");
                    $stmt->execute([
                        ':sucursal'    => $sucursal,
                        ':id_menu'     => $id_menu,
                        ':id_catalogo' => intval($slotItem['id_catalogo'])
                    ]);
                    $productosResueltos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                // ── Tipo 2: subgrupo + tamaño (NULL = todos los tamaños) ───
                case 'subgrupo_con_tamano':
                    if (!empty($slotItem['tamano_producto'])) {
                        $stmt = $conn->prepare($sqlBaseProductos . "
                            AND cmp.id_subgrupo = :id_subgrupo
                            AND cmp.tamano_producto = :tamano
                            ORDER BY cmp.nombre ASC, pp.Nombre ASC
                        ");
                        $stmt->execute([
                            ':sucursal'    => $sucursal,
                            ':id_menu'     => $id_menu,
                            ':id_subgrupo' => intval($slotItem['id_subgrupo']),
                            ':tamano'      => $slotItem['tamano_producto']
                        ]);
                    } else {
                        $stmt = $conn->prepare($sqlBaseProductos . "
                            AND cmp.id_subgrupo = :id_subgrupo
                            ORDER BY cmp.tamano_producto ASC, cmp.nombre ASC, pp.Nombre ASC
                        ");
                        $stmt->execute([
                            ':sucursal'    => $sucursal,
                            ':id_menu'     => $id_menu,
                            ':id_subgrupo' => intval($slotItem['id_subgrupo'])
                        ]);
                    }
                    $productosResueltos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                // ── Tipo 3: familia de subgrupo por nombre de catálogo ─────
                case 'familia_subgrupo':
                    $stmt = $conn->prepare($sqlBaseProductos . "
                        AND cmp.nombre = :nombre_familia
                        ORDER BY cmp.tamano_producto ASC, pp.Nombre ASC
                    ");
                    $stmt->execute([
                        ':sucursal'       => $sucursal,
                        ':id_menu'        => $id_menu,
                        ':nombre_familia' => $slotItem['nombre_catalogo_familia']
                    ]);
                    $productosResueltos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;

                // ── Tipo 4: grupo mayor (todos los subgrupos del grupo) ────
                case 'grupo_mayor':
                    $stmt = $conn->prepare($sqlBaseProductos . "
                        AND sg.id_grupo_presentacion_producto = :id_grupo_mayor
                        ORDER BY sg.nombre ASC, cmp.nombre ASC, pp.Nombre ASC
                    ");
                    $stmt->execute([
                        ':sucursal'       => $sucursal,
                        ':id_menu'        => $id_menu,
                        ':id_grupo_mayor' => intval($slotItem['id_grupo_mayor'])
                    ]);
                    $productosResueltos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
            }

            // Enriquecer cada producto resuelto con datos del slot item
            foreach ($productosResueltos as $prod) {
                $key = $prod['id_presentacion'];
                if (!isset($productosSlot[$key])) {
                    $productosSlot[$key] = [
                        'id_slot_item'         => intval($slotItem['id']),
                        'id_presentacion'      => intval($prod['id_presentacion']),
                        'id_catalogo_menu_pos' => intval($prod['id_catalogo_menu_pos']),
                        'nombre_catalogo'      => $prod['nombre_catalogo'],
                        'tamano_producto'      => $prod['tamano_producto'],
                        'es_personalizable'    => intval($prod['es_personalizable']),
                        'precio_venta'         => floatval($prod['precio_venta']),
                        'upcharge'             => floatval($slotItem['upcharge']),
                        'es_default'           => intval($slotItem['es_default']),
                    ];
                }
            }
        }

        $slot['items'] = array_values($productosSlot);
    }
    unset($slot);

    echo json_encode([
        'success' => true,
        'combo'   => [
            'id_catalogo'   => intval($combo['id']),
            'nombre'        => $combo['nombre'],
            'observaciones' => $combo['observaciones'],
            'precio_venta'  => floatval($combo['precio_venta']),
            'slots'         => $slots,
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
