<?php
/**
 * ajax/get_promociones_activas.php
 * Devuelve las promociones ACTIVAS y vigentes para mostrar en el panel
 * de la factura nueva del POS.
 *
 * Retorna:
 *   success  bool
 *   data     array  — cada promo con: id, nombre, resultado_tipo, resultado_valor,
 *                     objetivo_descuento, codigo_interno, descripcion_interna,
 *                     fecha_inicio, fecha_fin, prioridad, ejecucion_automatica,
 *                     requiere_autorizacion, num_condiciones
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
header('Content-Type: application/json');

try {
    if (!isset($conn)) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
    }

    $sql = "SELECT
                p.id,
                p.nombre,
                p.codigo_interno,
                p.descripcion_interna,
                p.resultado_tipo,
                p.resultado_valor,
                p.objetivo_descuento,
                p.prioridad,
                p.ejecucion_automatica,
                p.requiere_autorizacion,
                p.combinable,
                p.fecha_inicio,
                p.fecha_fin,
                (SELECT COUNT(*) FROM promo_condiciones c WHERE c.promo_id = p.id) AS num_condiciones
            FROM promo_promociones p
            WHERE p.estado = 'activa'
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= CURDATE())
              AND (p.fecha_fin   IS NULL OR p.fecha_fin   >= CURDATE())
            ORDER BY p.prioridad ASC, p.id DESC";

    $stmt = $conn->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'data' => [], 'message' => $e->getMessage()]);
}

