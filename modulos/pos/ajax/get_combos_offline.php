<?php
/**
 * get_combos_offline.php
 * Endpoint de sincronización offline (SQLite PWA).
 * Devuelve todos los combos activos con sus slots e items
 * para que el service worker los almacene en SQLite local.
 *
 * GET (sin parámetros requeridos)
 * Respuesta:
 * {
 *   "success": true,
 *   "slots": [ { id, id_catalogo_menu_pos, nombre, ... } ],
 *   "slot_items": [ { id, id_slot, tipo_fuente, ... } ]
 * }
 */
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    // Obtener todos los slots de combos activos
    $stmtSlots = $conn->prepare("
        SELECT s.id, s.id_catalogo_menu_pos, s.nombre, s.descripcion,
               s.min_seleccion, s.max_seleccion, s.orden
        FROM pos_combo_slots s
        JOIN catalogo_menu_pos cmp ON cmp.id = s.id_catalogo_menu_pos AND cmp.activo = 1
        JOIN subgrupo_presentacion_producto sg ON sg.id = cmp.id_subgrupo
        JOIN grupo_presentacion_producto g ON g.id = sg.id_grupo_presentacion_producto AND g.es_combo = 1
        ORDER BY s.id_catalogo_menu_pos ASC, s.orden ASC
    ");
    $stmtSlots->execute();
    $slots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

    // Obtener todos los items de esos slots
    $stmtItems = $conn->prepare("
        SELECT si.id, si.id_slot, si.tipo_fuente, si.id_catalogo, si.id_subgrupo,
               si.tamano_producto, si.nombre_catalogo_familia, si.id_grupo_mayor,
               si.upcharge, si.es_default, si.orden
        FROM pos_combo_slot_items si
        JOIN pos_combo_slots s ON s.id = si.id_slot
        JOIN catalogo_menu_pos cmp ON cmp.id = s.id_catalogo_menu_pos AND cmp.activo = 1
        JOIN subgrupo_presentacion_producto sg ON sg.id = cmp.id_subgrupo
        JOIN grupo_presentacion_producto g ON g.id = sg.id_grupo_presentacion_producto AND g.es_combo = 1
        ORDER BY si.id_slot ASC, si.orden ASC
    ");
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'slots'      => $slots,
        'slot_items' => $items
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

