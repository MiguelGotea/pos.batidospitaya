<?php
// mermas_get_detalle.php
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('ID inválido.');

    // Cabecera de la merma (1 registro = 1 línea, pero mostramos la agrupación por id)
    $stmtM = $conn->prepare("
        SELECT mp.id, mp.fecha_hora, mp.cod_sucursal, mp.observacion, mp.estado,
               mti.nombre           AS tipo_incidencia,
               mti.categoria_merma,
               mti.es_cobro,
               mti.necesita_aprobacion,
               CONCAT(o.Nombre, ' ', o.Apellido) AS registrado_por
        FROM mermas_productos mp
        JOIN mermas_tipos_incidencia mti ON mp.id_tipo_incidencia = mti.id
        LEFT JOIN Operarios o            ON mp.id_operario_registro = o.CodOperario
        WHERE mp.id = :id
        LIMIT 1");
    $stmtM->execute([':id' => $id]);
    $merma = $stmtM->fetch(PDO::FETCH_ASSOC);
    if (!$merma) throw new Exception('Registro no encontrado.');

    // El registro EN SÍ MISMO es la línea (tabla flat)
    // Devolvemos el producto como línea
    $stmtL = $conn->prepare("
        SELECT pp.Nombre AS producto, pp.SKU,
               mp.cantidad, mp.observacion
        FROM mermas_productos mp
        JOIN producto_presentacion pp ON mp.id_producto_presentacion = pp.id
        WHERE mp.id = :id LIMIT 1");
    $stmtL->execute([':id' => $id]);
    $lineas = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'merma' => $merma, 'lineas' => $lineas]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
