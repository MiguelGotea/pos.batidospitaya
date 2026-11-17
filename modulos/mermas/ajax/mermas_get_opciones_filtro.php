<?php
// mermas_get_opciones_filtro.php
// Devuelve las opciones únicas para los filtros tipo 'list' del historial
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $columna     = trim($_POST['columna'] ?? '');
    $codSucursal = $_SESSION['pos_store_sucursal'] ?? '';

    $opciones = [];

    if ($columna === 'tipo_incidencia') {
        $stmt = $conn->prepare("
            SELECT DISTINCT mti.nombre AS valor, mti.nombre AS texto
            FROM mermas_productos mp
            JOIN mermas_tipos_incidencia mti ON mp.id_tipo_incidencia = mti.id
            WHERE mp.cod_sucursal = :suc
            ORDER BY mti.nombre");
        $stmt->execute([':suc' => $codSucursal]);
        $opciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($columna === 'categoria_merma') {
        $stmt = $conn->prepare("
            SELECT DISTINCT mti.categoria_merma AS valor, mti.categoria_merma AS texto
            FROM mermas_productos mp
            JOIN mermas_tipos_incidencia mti ON mp.id_tipo_incidencia = mti.id
            WHERE mp.cod_sucursal = :suc
              AND mti.categoria_merma IS NOT NULL
            ORDER BY mti.categoria_merma");
        $stmt->execute([':suc' => $codSucursal]);
        $opciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        throw new Exception('Columna de filtro no soportada.');
    }

    echo json_encode(['success' => true, 'opciones' => $opciones]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
