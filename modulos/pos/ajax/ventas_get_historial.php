<?php
// ventas_get_historial.php — Lista paginada de ventas del POS (pos_ventas_facturas)
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $pagina            = isset($_POST['pagina'])              ? max(1, (int)$_POST['pagina'])              : 1;
    $registrosPorPagina = isset($_POST['registros_por_pagina']) ? max(1, (int)$_POST['registros_por_pagina']) : 25;
    $filtros           = isset($_POST['filtros'])             ? json_decode($_POST['filtros'], true)       : [];
    $orden             = isset($_POST['orden'])               ? json_decode($_POST['orden'], true)         : ['columna' => null, 'direccion' => 'asc'];
    $offset            = ($pagina - 1) * $registrosPorPagina;

    $where  = [];
    $params = [];

    // Filtro: # venta
    if (!empty($filtros['id'])) {
        $where[]          = 'v.id LIKE :id';
        $params[':id']    = '%' . trim($filtros['id']) . '%';
    }

    // Filtro: cliente (nombre general o nombre del club)
    if (!empty($filtros['cliente']) && !is_array($filtros['cliente'])) {
        $where[]             = "(COALESCE(v.nombre_cliente_general, CONCAT(c.nombre, ' ', c.apellido)) LIKE :cliente)";
        $params[':cliente']  = '%' . trim($filtros['cliente']) . '%';
    }

    // Filtro: tipo_cliente (lista: club / general)
    if (isset($filtros['tipo_cliente']) && is_array($filtros['tipo_cliente']) && count($filtros['tipo_cliente']) > 0) {
        $phs = [];
        foreach ($filtros['tipo_cliente'] as $i => $val) {
            $key = ':tc_' . $i;
            $phs[]       = $key;
            $params[$key] = $val;
        }
        $where[] = 'v.tipo_cliente IN (' . implode(',', $phs) . ')';
    }

    // Filtro: estado (lista: bloqueada / anulada)
    if (isset($filtros['estado']) && is_array($filtros['estado']) && count($filtros['estado']) > 0) {
        $phs = [];
        foreach ($filtros['estado'] as $i => $val) {
            $key = ':est_' . $i;
            $phs[]        = $key;
            $params[$key]  = $val;
        }
        $where[] = 'v.estado IN (' . implode(',', $phs) . ')';
    }

    // Filtro: cajero
    if (!empty($filtros['cajero']) && !is_array($filtros['cajero'])) {
        $where[]            = "CONCAT(o.Nombre, ' ', o.Apellido) LIKE :cajero";
        $params[':cajero']  = '%' . trim($filtros['cajero']) . '%';
    }

    // Filtro: total
    if (!empty($filtros['total'])) {
        $where[]            = "CAST(v.total AS CHAR) LIKE :total";
        $params[':total']   = '%' . trim($filtros['total']) . '%';
    }

    // Filtro: fecha_hora (rango de fechas)
    if (isset($filtros['fecha_hora']) && is_array($filtros['fecha_hora'])) {
        if (!empty($filtros['fecha_hora']['desde'])) {
            $where[]                   = 'DATE(v.fecha_hora) >= :fh_desde';
            $params[':fh_desde']       = $filtros['fecha_hora']['desde'];
        }
        if (!empty($filtros['fecha_hora']['hasta'])) {
            $where[]                   = 'DATE(v.fecha_hora) <= :fh_hasta';
            $params[':fh_hasta']       = $filtros['fecha_hora']['hasta'];
        }
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Columnas válidas para ordenar
    $columnasValidas = [
        'id'          => 'v.id',
        'fecha_hora'  => 'v.fecha_hora',
        'cliente'     => "COALESCE(v.nombre_cliente_general, CONCAT(c.nombre, ' ', c.apellido))",
        'tipo_cliente' => 'v.tipo_cliente',
        'total'       => 'v.total',
        'estado'      => 'v.estado',
        'cajero'      => "CONCAT(o.Nombre, ' ', o.Apellido)",
    ];

    $orderClause = 'ORDER BY v.fecha_hora DESC, v.id DESC';
    if (!empty($orden['columna']) && isset($columnasValidas[$orden['columna']])) {
        $dir         = strtoupper($orden['direccion'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $orderClause = 'ORDER BY ' . $columnasValidas[$orden['columna']] . ' ' . $dir . ', v.id DESC';
    }

    // JOIN base
    $joins = "LEFT JOIN clientes_club c ON v.id_cliente_club = c.id
              LEFT JOIN Operarios o ON v.cod_cajero = o.CodOperario";

    // COUNT
    $sqlCount = "SELECT COUNT(*) AS total
                 FROM pos_ventas_facturas v
                 $joins
                 $whereClause";
    $stmtCount = $conn->prepare($sqlCount);
    foreach ($params as $k => $v2) $stmtCount->bindValue($k, $v2);
    $stmtCount->execute();
    $totalRegistros = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    // Datos — métodos de pago agregados como cadena
    $sql = "SELECT v.id,
                   v.fecha_hora,
                   v.tipo_cliente,
                   v.total,
                   v.estado,
                   v.cod_sucursal,
                   COALESCE(v.nombre_cliente_general, CONCAT(c.nombre, ' ', c.apellido)) AS cliente,
                   CONCAT(o.Nombre, ' ', o.Apellido) AS cajero,
                   (
                       SELECT GROUP_CONCAT(DISTINCT p.metodo ORDER BY p.metodo SEPARATOR ', ')
                       FROM pos_ventas_factura_pagos p
                       WHERE p.id_factura = v.id AND p.monto_cordoba > 0
                   ) AS metodos_pago
            FROM pos_ventas_facturas v
            $joins
            $whereClause
            $orderClause
            LIMIT :offset, :limit";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v2) {
        if (is_int($v2)) $stmt->bindValue($k, $v2, PDO::PARAM_INT);
        else             $stmt->bindValue($k, $v2, PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $registrosPorPagina, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'          => true,
        'datos'            => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total_registros'  => $totalRegistros,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

