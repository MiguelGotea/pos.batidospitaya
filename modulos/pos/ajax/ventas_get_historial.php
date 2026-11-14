<?php
// ventas_get_historial.php — Lista paginada de ventas del POS (pos_ventas_facturas)
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $pagina             = isset($_POST['pagina'])               ? max(1, (int)$_POST['pagina'])               : 1;
    $registrosPorPagina = isset($_POST['registros_por_pagina']) ? max(1, (int)$_POST['registros_por_pagina']) : 25;
    $filtros            = isset($_POST['filtros'])              ? json_decode($_POST['filtros'], true)         : [];
    $orden              = isset($_POST['orden'])                ? json_decode($_POST['orden'], true)           : ['columna' => null, 'direccion' => 'asc'];
    $buscar             = isset($_POST['buscar'])               ? trim($_POST['buscar'])                       : '';
    $offset             = ($pagina - 1) * $registrosPorPagina;

    $where  = [];
    $params = [];

    // ── Búsqueda global (multi-token separado por comas) ──────────────────────
    // Genera nombres únicos por campo × token para evitar HY093 en PDO
    if ($buscar !== '') {
        $tokens = array_values(array_filter(array_map('trim', explode(',', $buscar))));
        $globalParts = [];
        foreach ($tokens as $ti => $token) {
            $pTicket  = ':bg_ticket_'  . $ti;
            $pMemb    = ':bg_memb_'    . $ti;
            $pCliente = ':bg_cliente_' . $ti;
            $pCajero  = ':bg_cajero_'  . $ti;
            $pPago    = ':bg_pago_'    . $ti;

            $params[$pTicket]  = '%' . $token . '%';
            $params[$pMemb]    = '%' . $token . '%';
            $params[$pCliente] = '%' . $token . '%';
            $params[$pCajero]  = '%' . $token . '%';
            $params[$pPago]    = '%' . $token . '%';

            $globalParts[] = "(
                CAST(v.id AS CHAR) LIKE $pTicket
                OR v.id_cliente_club LIKE $pMemb
                OR COALESCE(v.nombre_cliente_general, CONCAT(c.nombre, ' ', c.apellido)) LIKE $pCliente
                OR CONCAT(o.Nombre, ' ', o.Apellido) LIKE $pCajero
                OR EXISTS (
                    SELECT 1 FROM pos_ventas_factura_pagos bg_pg
                    WHERE bg_pg.id_factura = v.id AND bg_pg.monto_cordoba > 0
                      AND bg_pg.metodo LIKE $pPago
                )
            )";
        }
        if (!empty($globalParts)) {
            $where[] = '(' . implode(' AND ', $globalParts) . ')';
        }
    }

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

    // Filtro: tipo_cliente (lista: por nombre de pos_tipos_cliente derivado del menú usado)
    if (isset($filtros['tipo_cliente']) && is_array($filtros['tipo_cliente']) && count($filtros['tipo_cliente']) > 0) {
        $phs = [];
        foreach ($filtros['tipo_cliente'] as $i => $val) {
            $key = ':tc_' . $i;
            $phs[]       = $key;
            $params[$key] = $val;
        }
        $where[] = 'tc_menu.nombre IN (' . implode(',', $phs) . ')';
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

    // Filtro: fecha (rango de fechas) — acepta tanto 'fecha' como 'fecha_hora' por retrocompatibilidad
    $filtroFecha = $filtros['fecha'] ?? $filtros['fecha_hora'] ?? null;
    if (isset($filtroFecha) && is_array($filtroFecha)) {
        if (!empty($filtroFecha['desde'])) {
            $where[]             = 'DATE(v.fecha_hora) >= :fh_desde';
            $params[':fh_desde'] = $filtroFecha['desde'];
        }
        if (!empty($filtroFecha['hasta'])) {
            $where[]             = 'DATE(v.fecha_hora) <= :fh_hasta';
            $params[':fh_hasta'] = $filtroFecha['hasta'];
        }
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    // Columnas válidas para ordenar
    $columnasValidas = [
        'id'          => 'v.id',
        'fecha'       => 'v.fecha_hora',
        'fecha_hora'  => 'v.fecha_hora',
        'cliente'     => "COALESCE(v.nombre_cliente_general, CONCAT(c.nombre, ' ', c.apellido))",
        'tipo_cliente' => 'tc_menu.nombre',
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
    $joins = "LEFT JOIN clientesclub c ON v.id_cliente_club = c.membresia
              LEFT JOIN Operarios o ON v.cod_cajero = o.CodOperario
              LEFT JOIN pos_menu_precios mp_menu
                     ON mp_menu.id_menu = v.id_menu_usado
                    AND (mp_menu.cod_sucursal = v.cod_sucursal OR mp_menu.cod_sucursal IS NULL)
                    AND mp_menu.fecha_desde <= CURDATE()
                    AND (mp_menu.fecha_hasta IS NULL OR mp_menu.fecha_hasta >= CURDATE())
              LEFT JOIN pos_tipos_cliente tc_menu ON tc_menu.id = mp_menu.id_tipo_cliente";

    // COUNT
    $sqlCount = "SELECT COUNT(DISTINCT v.id) AS total
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
                   v.id_cliente_club,
                   v.nombre_cliente_general,
                   v.total,
                   v.estado,
                   v.cod_sucursal,
                   CASE
                       WHEN v.id_cliente_club IS NOT NULL AND v.id_cliente_club != ''
                           THEN CONCAT('(', v.id_cliente_club, ') ', COALESCE(c.nombre, ''), ' ', COALESCE(c.apellido, ''))
                       ELSE COALESCE(v.nombre_cliente_general, '—')
                   END AS cliente,
                   CONCAT(o.Nombre, ' ', o.Apellido) AS cajero,
                   COALESCE(tc_menu.nombre, '—') AS tipo_nombre,
                   (
                       SELECT GROUP_CONCAT(DISTINCT p.metodo ORDER BY p.metodo SEPARATOR ', ')
                       FROM pos_ventas_factura_pagos p
                       WHERE p.id_factura = v.id AND p.monto_cordoba > 0
                   ) AS metodos_pago
            FROM pos_ventas_facturas v
            $joins
            $whereClause
            GROUP BY v.id
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

