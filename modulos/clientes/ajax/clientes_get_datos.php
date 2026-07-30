<?php
// clientes_get_datos.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/permissions/permissions.php';

header('Content-Type: application/json');

try {
    $usuario = obtenerUsuarioActual();
    $cargoOperario = $usuario['CodNivelesCargos'];

    // Verificar acceso básico
    if (!tienePermiso('clientes_club_pos', 'vista', $cargoOperario)) {
        throw new Exception('No tiene permiso para ver el listado de clientes.');
    }

    $pagina = isset($_POST['pagina']) ? (int) $_POST['pagina'] : 1;
    $limit = isset($_POST['registros_por_pagina']) ? (int) $_POST['registros_por_pagina'] : 25;
    $search = isset($_POST['search']) ? trim($_POST['search']) : '';
    $offset = ($pagina - 1) * $limit;

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(membresia LIKE :search OR nombre LIKE :search OR apellido LIKE :search OR celular LIKE :search OR cedula LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Conteo de registros
    $sqlCount = "SELECT COUNT(*) as total FROM clientesclub $whereClause";
    $stmtCount = $conn->prepare($sqlCount);
    $stmtCount->execute($params);
    $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    // Consulta de datos
    $sql = "SELECT id_clienteclub, membresia, nombre, apellido, celular, cedula, nombre_sucursal, fecha_registro 
            FROM clientesclub 
            $whereClause 
            ORDER BY fecha_registro DESC 
            LIMIT :offset, :limit";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'datos' => $datos,
        'total_registros' => $totalRegistros
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
