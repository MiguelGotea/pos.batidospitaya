<?php
// mermas_get_historial.php
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['pos_colaborador_id'])) throw new Exception('No autorizado');

    $pagina            = max(1, (int)($_POST['pagina'] ?? 1));
    $registrosPorPagina = max(1, (int)($_POST['registros_por_pagina'] ?? 25));
    $filtros           = json_decode($_POST['filtros'] ?? '[]', true) ?: [];
    $orden             = json_decode($_POST['orden']   ?? '{}', true) ?: ['columna' => null, 'direccion' => 'asc'];
    $offset            = ($pagina - 1) * $registrosPorPagina;

    // Filtro fijo por sucursal de sesión
    $where  = ['mp.cod_sucursal = :sucursal'];
    $params = [':sucursal' => $_SESSION['pos_store_sucursal'] ?? ''];

    // Filtros de columna
    if (!empty($filtros['producto'])) {
        $where[] = '(pp.Nombre LIKE :producto OR pp.SKU LIKE :producto)';
        $params[':producto'] = '%' . trim($filtros['producto']) . '%';
    }
    if (!empty($filtros['cantidad'])) {
        $where[] = 'CAST(mp.cantidad AS CHAR) LIKE :cantidad';
        $params[':cantidad'] = '%' . trim($filtros['cantidad']) . '%';
    }
    if (!empty($filtros['registrado_por'])) {
        $where[] = "CONCAT(o.Nombre, ' ', o.Apellido) LIKE :registrado_por";
        $params[':registrado_por'] = '%' . trim($filtros['registrado_por']) . '%';
    }
    if (isset($filtros['tipo_incidencia']) && is_array($filtros['tipo_incidencia']) && count($filtros['tipo_incidencia'])) {
        $phs = [];
        foreach ($filtros['tipo_incidencia'] as $i => $v) {
            $k = ':tipo_' . $i; $phs[] = $k; $params[$k] = $v;
        }
        $where[] = 'mti.nombre IN (' . implode(',', $phs) . ')';
    }
    if (isset($filtros['categoria_merma']) && is_array($filtros['categoria_merma']) && count($filtros['categoria_merma'])) {
        $phs = [];
        foreach ($filtros['categoria_merma'] as $i => $v) {
            $k = ':cat_' . $i; $phs[] = $k; $params[$k] = $v;
        }
        $where[] = 'mti.categoria_merma IN (' . implode(',', $phs) . ')';
    }
    if (isset($filtros['fecha_hora']) && is_array($filtros['fecha_hora'])) {
        if (!empty($filtros['fecha_hora']['desde'])) {
            $where[] = 'DATE(mp.fecha_hora) >= :fh_desde';
            $params[':fh_desde'] = $filtros['fecha_hora']['desde'];
        }
        if (!empty($filtros['fecha_hora']['hasta'])) {
            $where[] = 'DATE(mp.fecha_hora) <= :fh_hasta';
            $params[':fh_hasta'] = $filtros['fecha_hora']['hasta'];
        }
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $columnasValidas = [
        'fecha_hora'      => 'mp.fecha_hora',
        'tipo_incidencia' => 'mti.nombre',
        'categoria_merma' => 'mti.categoria_merma',
        'producto'        => 'pp.Nombre',
        'cantidad'        => 'mp.cantidad',
        'registrado_por'  => 'registrado_por',
    ];
    $orderClause = 'ORDER BY mp.fecha_hora DESC, mp.id DESC';
    if (!empty($orden['columna']) && isset($columnasValidas[$orden['columna']])) {
        $dir = strtoupper($orden['direccion'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $orderClause = 'ORDER BY ' . $columnasValidas[$orden['columna']] . ' ' . $dir . ', mp.id DESC';
    }

    $baseJoin = "FROM mermas_productos mp
                 JOIN mermas_tipos_incidencia mti ON mp.id_tipo_incidencia = mti.id
                 JOIN producto_presentacion pp     ON mp.id_producto_presentacion = pp.id
                 LEFT JOIN Operarios o             ON mp.id_operario_registro = o.CodOperario";

    // Count
    $stmtC = $conn->prepare("SELECT COUNT(*) AS total $baseJoin $whereClause");
    foreach ($params as $k => $v) $stmtC->bindValue($k, $v);
    $stmtC->execute();
    $total = (int)$stmtC->fetch(PDO::FETCH_ASSOC)['total'];

    // Data
    $sql = "SELECT mp.id,
                   mp.fecha_hora,
                   mp.cod_sucursal,
                   mp.cantidad,
                   mp.observacion,
                   mp.estado,
                   mti.nombre     AS tipo_incidencia,
                   mti.categoria_merma,
                   mti.es_cobro,
                   mti.necesita_aprobacion,
                   pp.Nombre      AS producto,
                   pp.SKU,
                   CONCAT(o.Nombre, ' ', o.Apellido) AS registrado_por
            $baseJoin
            $whereClause
            $orderClause
            LIMIT :offset, :limit";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $registrosPorPagina, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode(['success' => true, 'datos' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_registros' => $total]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
