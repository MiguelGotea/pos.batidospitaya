<?php
// ventas_get_historial_productos.php — Lista paginada de líneas de venta (un renglón por producto facturado)
// Solo se muestran líneas "padre" (id_linea_padre IS NULL).
// Los combos aparecen como una sola fila (el combo padre); sus hijos se omiten.
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

    $where  = ["l.id_linea_padre IS NULL"];  // solo líneas raíz (padres: productos regulares o combos)
    $params = [];

    // ── Búsqueda global (multi-token separado por comas) ──────────────────────
    if ($buscar !== '') {
        $tokens = array_filter(array_map('trim', explode(',', $buscar)));
        $globalParts = [];
        foreach ($tokens as $ti => $token) {
            $k = ':buscar_' . $ti;
            $params[$k] = '%' . $token . '%';
            $globalParts[] = "(
                l.nombre_producto_snapshot LIKE $k
                OR CAST(v.id AS CHAR) LIKE $k
                OR v.id_cliente_club LIKE $k
                OR COALESCE(v.nombre_cliente_general, CONCAT(cc.nombre, ' ', cc.apellido)) LIKE $k
                OR CONCAT(o.Nombre, ' ', o.Apellido) LIKE $k
                OR EXISTS (
                    SELECT 1 FROM pos_ventas_factura_pagos pg
                    WHERE pg.id_factura = v.id AND pg.monto_cordoba > 0
                      AND pg.metodo LIKE $k
                )
            )";
        }
        if (!empty($globalParts)) {
            $where[] = '(' . implode(' AND ', $globalParts) . ')';
        }
    }

    // ── Filtros de cabecera de columna ────────────────────────────────────────

    // Filtro: nombre de producto
    if (!empty($filtros['nombre_producto']) && !is_array($filtros['nombre_producto'])) {
        $where[]                      = 'l.nombre_producto_snapshot LIKE :np';
        $params[':np']                = '%' . trim($filtros['nombre_producto']) . '%';
    }

    // Filtro: ticket (id de factura)
    if (!empty($filtros['id'])) {
        $where[]       = 'v.id LIKE :id';
        $params[':id'] = '%' . trim($filtros['id']) . '%';
    }

    // Filtro: membresía / cliente club
    if (!empty($filtros['membresia']) && !is_array($filtros['membresia'])) {
        $where[]              = 'v.id_cliente_club LIKE :membresia';
        $params[':membresia'] = '%' . trim($filtros['membresia']) . '%';
    }

    // Filtro: nombre de cliente (general o club)
    if (!empty($filtros['cliente']) && !is_array($filtros['cliente'])) {
        $where[]             = "(COALESCE(v.nombre_cliente_general, CONCAT(cc.nombre, ' ', cc.apellido)) LIKE :cliente)";
        $params[':cliente']  = '%' . trim($filtros['cliente']) . '%';
    }

    // Filtro: tipo_cliente (lista)
    if (isset($filtros['tipo_cliente']) && is_array($filtros['tipo_cliente']) && count($filtros['tipo_cliente']) > 0) {
        $phs = [];
        foreach ($filtros['tipo_cliente'] as $i => $val) {
            $key = ':tc_' . $i;
            $phs[]        = $key;
            $params[$key] = $val;
        }
        $where[] = 'tc_menu.nombre IN (' . implode(',', $phs) . ')';
    }

    // Filtro: estado (lista)
    if (isset($filtros['estado']) && is_array($filtros['estado']) && count($filtros['estado']) > 0) {
        $phs = [];
        foreach ($filtros['estado'] as $i => $val) {
            $key = ':est_' . $i;
            $phs[]        = $key;
            $params[$key] = $val;
        }
        $where[] = 'v.estado IN (' . implode(',', $phs) . ')';
    }

    // Filtro: cajero
    if (!empty($filtros['cajero']) && !is_array($filtros['cajero'])) {
        $where[]           = "CONCAT(o.Nombre, ' ', o.Apellido) LIKE :cajero";
        $params[':cajero'] = '%' . trim($filtros['cajero']) . '%';
    }

    // Filtro: metodo_pago (lista)
    if (isset($filtros['metodo_pago']) && is_array($filtros['metodo_pago']) && count($filtros['metodo_pago']) > 0) {
        $phsM = [];
        foreach ($filtros['metodo_pago'] as $i => $val) {
            $key = ':mp_' . $i;
            $phsM[]       = $key;
            $params[$key] = $val;
        }
        $inList = implode(',', $phsM);
        $where[] = "EXISTS (
            SELECT 1 FROM pos_ventas_factura_pagos pgf
            WHERE pgf.id_factura = v.id AND pgf.monto_cordoba > 0
              AND pgf.metodo IN ($inList)
        )";
    }

    // Filtro: fecha (rango)
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

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    // ── Columnas válidas para ordenar ─────────────────────────────────────────
    $columnasValidas = [
        'nombre_producto'  => 'l.nombre_producto_snapshot',
        'id'               => 'v.id',
        'fecha'            => 'v.fecha_hora',
        'fecha_hora'       => 'v.fecha_hora',
        'cliente'          => "COALESCE(v.nombre_cliente_general, CONCAT(cc.nombre, ' ', cc.apellido))",
        'tipo_cliente'     => 'tc_menu.nombre',
        'total_linea'      => 'l.precio_linea_total',
        'estado'           => 'v.estado',
        'cajero'           => "CONCAT(o.Nombre, ' ', o.Apellido)",
    ];

    $orderClause = 'ORDER BY v.fecha_hora DESC, v.id DESC, l.id ASC';
    if (!empty($orden['columna']) && isset($columnasValidas[$orden['columna']])) {
        $dir         = strtoupper($orden['direccion'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $orderClause = 'ORDER BY ' . $columnasValidas[$orden['columna']] . ' ' . $dir . ', v.id DESC, l.id ASC';
    }

    // ── JOINs ─────────────────────────────────────────────────────────────────
    $joins = "JOIN pos_ventas_facturas v ON v.id = l.id_factura
              LEFT JOIN clientesclub cc ON v.id_cliente_club = cc.membresia
              LEFT JOIN Operarios o ON v.cod_cajero = o.CodOperario
              LEFT JOIN pos_menu_precios mp_menu
                     ON mp_menu.id_menu = v.id_menu_usado
                    AND (mp_menu.cod_sucursal = v.cod_sucursal OR mp_menu.cod_sucursal IS NULL)
                    AND mp_menu.fecha_desde <= CURDATE()
                    AND (mp_menu.fecha_hasta IS NULL OR mp_menu.fecha_hasta >= CURDATE())
              LEFT JOIN pos_tipos_cliente tc_menu ON tc_menu.id = mp_menu.id_tipo_cliente";

    // ── COUNT ─────────────────────────────────────────────────────────────────
    $sqlCount = "SELECT COUNT(*) AS total
                 FROM pos_ventas_factura_lineas l
                 $joins
                 $whereClause";
    $stmtCount = $conn->prepare($sqlCount);
    foreach ($params as $k => $pv) $stmtCount->bindValue($k, $pv);
    $stmtCount->execute();
    $totalRegistros = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    // ── DATOS ─────────────────────────────────────────────────────────────────
    $sql = "SELECT
                l.id               AS id_linea,
                l.nombre_producto_snapshot,
                l.cantidad,
                l.precio_unitario_final,
                l.precio_linea_total,
                l.es_canje_puntos,
                l.id_producto_presentacion,
                v.id               AS id_factura,
                v.fecha_hora,
                v.estado,
                CASE
                    WHEN v.id_cliente_club IS NOT NULL AND v.id_cliente_club != ''
                        THEN CONCAT('(', v.id_cliente_club, ') ', COALESCE(cc.nombre, ''), ' ', COALESCE(cc.apellido, ''))
                    ELSE COALESCE(v.nombre_cliente_general, '—')
                END AS cliente,
                CONCAT(o.Nombre, ' ', o.Apellido) AS cajero,
                COALESCE(tc_menu.nombre, '—') AS tipo_nombre,
                (
                    SELECT GROUP_CONCAT(DISTINCT pgf.metodo ORDER BY pgf.metodo SEPARATOR ', ')
                    FROM pos_ventas_factura_pagos pgf
                    WHERE pgf.id_factura = v.id AND pgf.monto_cordoba > 0
                ) AS metodos_pago
            FROM pos_ventas_factura_lineas l
            $joins
            $whereClause
            $orderClause
            LIMIT :offset, :limit";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $pv) {
        if (is_int($pv)) $stmt->bindValue($k, $pv, PDO::PARAM_INT);
        else             $stmt->bindValue($k, $pv, PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $registrosPorPagina, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success'         => true,
        'datos'           => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total_registros' => $totalRegistros,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
