<?php
// Diagnóstico temporal — ELIMINAR DESPUÉS DE USAR
require_once '../../../core/auth/auth_pos.php';
header('Content-Type: application/json');

$autenticado = posTiendaAutenticada();
$dev_mode    = defined('POS_DEV_MODE') ? POS_DEV_MODE : 'no_definido';
$sucursal    = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? 'NO_SESION');

require_once '../../../core/database/conexion.php';

// Contar precios disponibles
$n = 0;
try {
    $st = $conn->query("SELECT COUNT(*) FROM pos_menu_precios WHERE id_menu=1");
    $n  = $st->fetchColumn();
} catch (Exception $e) {
    $n = 'error: ' . $e->getMessage();
}

// Query de prueba
$productos = [];
try {
    $st = $conn->prepare(
        "SELECT pp.id, pp.Nombre, pglob.precio
           FROM producto_presentacion pp
           LEFT JOIN catalogo_menu_pos cmp ON pp.id_catalogo_menu_pos = cmp.id
           LEFT JOIN pos_menu_precios pglob
                  ON pglob.id_menu = 1
                 AND pglob.id_catalogo_menu_pos = pp.id_catalogo_menu_pos
                 AND pglob.cod_sucursal IS NULL
                 AND pglob.fecha_desde <= CURDATE()
                 AND (pglob.fecha_hasta IS NULL OR pglob.fecha_hasta >= CURDATE())
          WHERE pp.es_vendible = 'SI' AND pp.Activo = 'SI'
            AND pglob.precio IS NOT NULL
          LIMIT 5"
    );
    $st->execute();
    $productos = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $productos = ['error' => $e->getMessage()];
}

echo json_encode([
    'autenticado'   => $autenticado,
    'dev_mode'      => $dev_mode,
    'sucursal_ses'  => $sucursal,
    'session_keys'  => array_keys($_SESSION),
    'total_precios' => $n,
    'sample_prods'  => $productos,
]);
?>

