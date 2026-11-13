<?php
/**
 * aligeramiento_get_historial.php
 * AJAX — Historial paginado de aligeramientos con filtros, orden y conteo de vouchers
 * POS / modulos/aligeramiento/ajax/
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    // ── Parámetros de paginación y filtros ──────────────────────────────
    $pagina              = isset($_POST['pagina'])               ? (int)$_POST['pagina']              : 1;
    $registrosPorPagina  = isset($_POST['registros_por_pagina']) ? (int)$_POST['registros_por_pagina'] : 25;
    $filtros             = isset($_POST['filtros'])              ? json_decode($_POST['filtros'], true) : [];
    $orden               = isset($_POST['orden'])               ? json_decode($_POST['orden'], true)   : ['columna' => 'fecha', 'direccion' => 'desc'];
    $busquedaGlobal      = isset($_POST['busqueda_global'])      ? trim($_POST['busqueda_global'])      : '';

    $offset = ($pagina - 1) * $registrosPorPagina;
    $params = [];
    $where  = ['1=1'];

    // ── Filtrar siempre por la sucursal del dispositivo ─────────────────
    $sucursalId = $_SESSION['pos_store_sucursal'] ?? null;
    if (!$sucursalId) {
        echo json_encode(['success' => false, 'message' => 'Sin sucursal en sesión.']);
        exit;
    }
    $where[]              = 'a.sucursal_id = :sucursal_sesion';
    $params[':sucursal_sesion'] = $sucursalId;

    // ── Búsqueda global ─────────────────────────────────────────────────
    if (!empty($busquedaGlobal)) {
        $terms = array_map('trim', explode(',', $busquedaGlobal));
        $gConds = [];
        foreach ($terms as $i => $term) {
            if (empty($term)) continue;
            $pFecha = ":gbusq_{$i}_fecha";
            $pObs   = ":gbusq_{$i}_obs";
            $pNom   = ":gbusq_{$i}_nom";
            $gConds[] = "(s.nombre LIKE $pNom OR a.observacion LIKE $pObs OR a.fecha LIKE $pFecha)";
            $params[$pNom]   = "%$term%";
            $params[$pObs]   = "%$term%";
            $params[$pFecha] = "%$term%";
        }
        if (!empty($gConds)) {
            $where[] = '(' . implode(' OR ', $gConds) . ')';
        }
    }

    // ── Filtros por columna ──────────────────────────────────────────────
    if (!empty($filtros)) {
        foreach ($filtros as $col => $val) {
            switch ($col) {
                case 'fecha':
                    if (!empty($val['desde'])) {
                        $where[] = 'a.fecha >= :fecha_desde';
                        $params[':fecha_desde'] = $val['desde'];
                    }
                    if (!empty($val['hasta'])) {
                        $where[] = 'a.fecha <= :fecha_hasta';
                        $params[':fecha_hasta'] = $val['hasta'];
                    }
                    break;
                case 'total_global':
                    if (isset($val['min']) && $val['min'] !== '') {
                        $where[] = 'a.total_global >= :tg_min';
                        $params[':tg_min'] = $val['min'];
                    }
                    if (isset($val['max']) && $val['max'] !== '') {
                        $where[] = 'a.total_global <= :tg_max';
                        $params[':tg_max'] = $val['max'];
                    }
                    break;
                case 'observacion':
                    if (!empty($val) && is_string($val)) {
                        $where[] = 'a.observacion LIKE :obs_filtro';
                        $params[':obs_filtro'] = "%$val%";
                    }
                    break;
                case 'usuario':
                    if (is_array($val) && !empty($val)) {
                        $inP = [];
                        foreach ($val as $i => $v) {
                            $k = ":usr_$i"; $inP[] = $k; $params[$k] = $v;
                        }
                        $where[] = 'a.cod_usuario IN (' . implode(',', $inP) . ')';
                    }
                    break;
            }
        }
    }

    $whereClause = implode(' AND ', $where);

    // ── Orden ────────────────────────────────────────────────────────────
    $orderBy = 'a.fecha DESC, a.hora DESC';
    if (!empty($orden['columna']) && !empty($orden['direccion'])) {
        $dir = strtoupper($orden['direccion']) === 'ASC' ? 'ASC' : 'DESC';
        switch ($orden['columna']) {
            case 'fecha':         $orderBy = "a.fecha $dir, a.hora $dir"; break;
            case 'total_global':  $orderBy = "a.total_global $dir"; break;
            case 'total_nio':     $orderBy = "a.total_cordobas $dir"; break;
            case 'total_usd':     $orderBy = "a.total_dolares $dir"; break;
            case 'observacion':   $orderBy = "a.observacion $dir"; break;
            case 'usuario':       $orderBy = "op.Nombre $dir"; break;
        }
    }

    // ── Contar total ─────────────────────────────────────────────────────
    $sqlCount = "
        SELECT COUNT(*) as total
        FROM pos_aligeramientos a
        LEFT JOIN sucursales s ON a.sucursal_id = s.codigo
        LEFT JOIN Operarios op ON a.cod_usuario = op.CodOperario
        WHERE $whereClause
    ";
    $stmtCount = $conn->prepare($sqlCount);
    foreach ($params as $k => $v) { $stmtCount->bindValue($k, $v); }
    $stmtCount->execute();
    $totalRegistros = (int)$stmtCount->fetchColumn();

    // ── Datos paginados con conteo y alerta de vouchers ─────────────────
    $sql = "
        SELECT
            a.id,
            a.sucursal_id,
            a.fecha,
            a.hora,
            a.tipo_cambio_usado,
            a.total_cordobas,
            a.total_dolares,
            a.total_dolares_en_cordobas,
            a.total_global,
            a.observacion,
            a.cod_usuario,
            a.creado_en,
            s.nombre AS tienda_nombre,
            CONCAT(COALESCE(op.Nombre,''), ' ', COALESCE(op.Apellido,'')) AS usuario_nombre,
            COALESCE((
                SELECT COUNT(*) FROM aligeramientos_vouchers v
                WHERE v.aligeramiento_id = a.id
            ), 0) AS voucher_count,
            COALESCE((
                SELECT CASE
                    WHEN COUNT(DISTINCT v.ia_denominacion) > 1 THEN 1
                    WHEN ABS(SUM(v.ia_monto) - a.total_global) > 1
                         AND COUNT(v.id) > 0 THEN 1
                    ELSE 0
                END
                FROM aligeramientos_vouchers v
                WHERE v.aligeramiento_id = a.id AND v.ia_monto IS NOT NULL
            ), 0) AS tiene_alerta_voucher
        FROM pos_aligeramientos a
        LEFT JOIN sucursales s  ON a.sucursal_id  = s.codigo
        LEFT JOIN Operarios op  ON a.cod_usuario  = op.CodOperario
        WHERE $whereClause
        ORDER BY $orderBy
        LIMIT :offset, :limit
    ";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit',  $registrosPorPagina, PDO::PARAM_INT);
    $stmt->execute();
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $meses = [
        '01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr',
        '05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Ago',
        '09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'
    ];

    $resultado = [];
    foreach ($datos as $row) {
        // Formatear fecha
        $fechaFmt = '';
        if ($row['fecha']) {
            $fObj = DateTime::createFromFormat('Y-m-d', $row['fecha']);
            if ($fObj) {
                $fechaFmt = $fObj->format('d') . '-' . $meses[$fObj->format('m')] . '-' . $fObj->format('y');
            } else {
                $fechaFmt = $row['fecha'];
            }
        }
        // Formatear hora
        $horaFmt = '';
        if ($row['hora']) {
            $hObj = DateTime::createFromFormat('H:i:s', $row['hora']);
            $horaFmt = $hObj ? $hObj->format('H:i') : substr($row['hora'], 0, 5);
        }

        $resultado[] = [
            'id'                     => (int)$row['id'],
            'sucursal_id'            => (int)$row['sucursal_id'],
            'tienda_nombre'          => $row['tienda_nombre'] ?: ('Sucursal ' . $row['sucursal_id']),
            'fecha_formateada'       => $fechaFmt,
            'hora_formateada'        => $horaFmt,
            'fecha_raw'              => $row['fecha'],
            'total_cordobas'         => (float)$row['total_cordobas'],
            'total_dolares'          => (float)$row['total_dolares'],
            'total_dolares_en_cordobas' => (float)$row['total_dolares_en_cordobas'],
            'total_global'           => (float)$row['total_global'],
            'observacion'            => $row['observacion'] ?? '',
            'usuario_nombre'         => trim($row['usuario_nombre']) ?: 'Sin nombre',
            'voucher_count'          => (int)$row['voucher_count'],
            'tiene_alerta_voucher'   => (bool)$row['tiene_alerta_voucher'],
        ];
    }

    echo json_encode([
        'success'          => true,
        'datos'            => $resultado,
        'total_registros'  => $totalRegistros,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

