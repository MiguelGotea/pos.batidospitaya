<?php
/**
 * cierre_helpers.php — Funciones compartidas del módulo Cierre
 * POS Batidos Pitaya
 */

require_once __DIR__ . '/cierre_config.php';

/**
 * Obtiene el tipo de cambio vigente para una fecha (DATE string 'Y-m-d').
 * Retorna null si no existe TC para ese día.
 *
 * @param PDO    $conn
 * @param string $fecha  Formato 'Y-m-d'
 * @return float|null
 */
function cierre_obtenerTC(PDO $conn, string $fecha): ?float
{
    $stmt = $conn->prepare(
        "SELECT tasa FROM pos_ventas_tipo_cambio
         WHERE fecha_desde <= :fecha
         ORDER BY fecha_desde DESC
         LIMIT 1"
    );
    $stmt->execute([':fecha' => $fecha]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (float)$row['tasa'] : null;
}

/**
 * Redondeo C$ igual que la facturación: round() PHP estándar, 0 decimales (entero C$).
 * Para montos que ya están en C$ enteros no hace nada diferente.
 * Para conversión USD→C$: round(monto_usd * tc, 0).
 */
function cierre_usdACordoba(float $monto_usd, float $tc): int
{
    return (int) round($monto_usd * $tc, 0);
}

/**
 * Obtiene el id entero de sucursal a partir del código varchar de sesión.
 * pos_aligeramientos usa sucursal_id = sucursales.id (int).
 * pos_ventas_facturas usa cod_sucursal = sucursales.codigo (varchar).
 */
function cierre_idSucursalDesdeCodigo(PDO $conn, string $codigo): ?int
{
    $stmt = $conn->prepare("SELECT id FROM sucursales WHERE codigo = ? LIMIT 1");
    $stmt->execute([$codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
}

/**
 * Calcula el esperado de efectivo para el rango [corte_anterior, corte_actual].
 *
 * esperado = caja_inicial_equiv_c
 *          + SUM pagos efectivo NIO con signo (monto_cordoba, puede ser negativo = cambio)
 *          + SUM pagos efectivo USD * tc (redondeo entero)
 *          - SUM aligeramientos equiv_c en el rango
 *          - SUM facturas_proveedor en el rango
 *
 * @return array ['esperado' => int, 'detalle' => array]
 */
function cierre_calcularEsperadoEfectivo(
    PDO    $conn,
    string $cod_sucursal,
    int    $sucursal_id,
    float  $tc,
    float  $caja_inicial_equiv_c,
    string $corte_anterior,   // datetime
    string $corte_actual      // datetime
): array {

    // 1. Pagos efectivo NIO (con signo): suma de monto_cordoba
    $stmtNIO = $conn->prepare(
        "SELECT COALESCE(SUM(p.monto_cordoba), 0) AS total
         FROM pos_ventas_factura_pagos p
         JOIN pos_ventas_facturas f ON f.id = p.id_factura
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND p.metodo = 'efectivo'
           AND p.moneda = 'NIO'"
    );
    $stmtNIO->execute([':suc' => $cod_sucursal, ':desde' => $corte_anterior, ':hasta' => $corte_actual]);
    $totalNIOConSigno = (float)$stmtNIO->fetchColumn();

    // 2. Pagos efectivo USD: suma monto_original (en USD), convertir a C$
    $stmtUSD = $conn->prepare(
        "SELECT COALESCE(SUM(p.monto_original), 0) AS total_usd
         FROM pos_ventas_factura_pagos p
         JOIN pos_ventas_facturas f ON f.id = p.id_factura
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND p.metodo = 'efectivo'
           AND p.moneda = 'USD'"
    );
    $stmtUSD->execute([':suc' => $cod_sucursal, ':desde' => $corte_anterior, ':hasta' => $corte_actual]);
    $totalUSD = (float)$stmtUSD->fetchColumn();
    $totalUSDenC = round($totalUSD * $tc, 2);

    // 3. Aligeramientos: total_global ya en C$ equivalente (guardado con TC del momento)
    $stmtAlig = $conn->prepare(
        "SELECT COALESCE(SUM(a.total_global), 0) AS total
         FROM pos_aligeramientos a
         WHERE a.sucursal_id = :sid
           AND a.creado_en > :desde
           AND a.creado_en <= :hasta"
    );
    $stmtAlig->execute([':sid' => $sucursal_id, ':desde' => $corte_anterior, ':hasta' => $corte_actual]);
    $totalAlig = (float)$stmtAlig->fetchColumn();

    // 4. Facturas a proveedores: total_factura en C$ (pos_facturas.fecha_hora_regsys)
    // pos_facturas no tiene cod_sucursal. Se relaciona por registrado_por.
    // Obtenemos todos los cajeros que han operado en esta sucursal (en el histórico).
    $stmtCajeros = $conn->prepare(
        "SELECT DISTINCT cod_cajero FROM pos_ventas_facturas WHERE cod_sucursal = :suc"
    );
    $stmtCajeros->execute([':suc' => $cod_sucursal]);
    $cajeros = array_column($stmtCajeros->fetchAll(PDO::FETCH_ASSOC), 'cod_cajero');

    $totalProv = 0.0;
    if (!empty($cajeros)) {
        $placeholders = implode(',', array_fill(0, count($cajeros), '?'));
        $stmtProv = $conn->prepare(
            "SELECT COALESCE(SUM(f.total_factura), 0) AS total
             FROM pos_facturas f
             WHERE f.registrado_por IN ($placeholders)
               AND f.estado = 'activa'
               AND f.fecha_hora_regsys > ?
               AND f.fecha_hora_regsys <= ?"
        );
        $params = array_merge($cajeros, [$corte_anterior, $corte_actual]);
        $stmtProv->execute($params);
        $totalProv = (float)$stmtProv->fetchColumn();
    }

    $esperado = round(
        $caja_inicial_equiv_c + $totalNIOConSigno + $totalUSDenC - $totalAlig - $totalProv,
        2
    );

    return [
        'esperado' => $esperado,
        'detalle'  => [
            'caja_inicial_equiv_c' => $caja_inicial_equiv_c,
            'pagos_efectivo_nio'   => $totalNIOConSigno,
            'pagos_efectivo_usd'   => $totalUSD,
            'pagos_efectivo_usd_c' => $totalUSDenC,
            'aligeramientos_c'     => $totalAlig,
            'proveedores_c'        => $totalProv,
        ],
    ];
}

/**
 * Calcula esperados de cupones, transferencia, tarjeta y pedidosya.
 */
function cierre_calcularEsperadosOtros(
    PDO    $conn,
    string $cod_sucursal,
    string $corte_anterior,
    string $corte_actual
): array {
    $result = [];

    // Cupones: cantidad de líneas y monto total
    $stmtCup = $conn->prepare(
        "SELECT COUNT(*) AS cantidad, COALESCE(SUM(p.monto_cordoba), 0) AS monto
         FROM pos_ventas_factura_pagos p
         JOIN pos_ventas_facturas f ON f.id = p.id_factura
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND p.metodo = 'cupon'"
    );
    $stmtCup->execute([':suc' => $cod_sucursal, ':desde' => $corte_anterior, ':hasta' => $corte_actual]);
    $cup = $stmtCup->fetch(PDO::FETCH_ASSOC);
    $result['cupones'] = [
        'esperado'           => (float)$cup['monto'],
        'cantidad_esperada'  => (int)$cup['cantidad'],
    ];

    // Transferencia
    $stmtTrans = $conn->prepare(
        "SELECT COALESCE(SUM(p.monto_cordoba), 0) AS monto
         FROM pos_ventas_factura_pagos p
         JOIN pos_ventas_facturas f ON f.id = p.id_factura
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND p.metodo = 'transferencia'"
    );
    $stmtTrans->execute([':suc' => $cod_sucursal, ':desde' => $corte_anterior, ':hasta' => $corte_actual]);
    $result['transferencia'] = ['esperado' => (float)$stmtTrans->fetchColumn()];

    // Tarjeta (POS banco)
    $stmtTarj = $conn->prepare(
        "SELECT COALESCE(SUM(p.monto_cordoba), 0) AS monto
         FROM pos_ventas_factura_pagos p
         JOIN pos_ventas_facturas f ON f.id = p.id_factura
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND p.metodo = 'pos'"
    );
    $stmtTarj->execute([':suc' => $cod_sucursal, ':desde' => $corte_anterior, ':hasta' => $corte_actual]);
    $result['tarjeta'] = ['esperado' => (float)$stmtTarj->fetchColumn()];

    // PedidosYa: suma de total de facturas con al menos un pago pedidosya
    $stmtPY = $conn->prepare(
        "SELECT COALESCE(SUM(f.total), 0) AS monto
         FROM pos_ventas_facturas f
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND EXISTS (
               SELECT 1 FROM pos_ventas_factura_pagos p2
               WHERE p2.id_factura = f.id AND p2.metodo = 'pedidosya'
           )"
    );
    $stmtPY->execute([':suc' => $cod_sucursal, ':desde' => $corte_anterior, ':hasta' => $corte_actual]);
    $result['pedidosya'] = ['esperado' => (float)$stmtPY->fetchColumn()];

    return $result;
}

/**
 * Verifica si un concepto está dentro de tolerancia.
 * Para cupones evalúa también la cantidad.
 */
function cierre_dentroTolerancia(
    string $concepto,
    float  $diferencia,
    ?int   $diff_cantidad = null
): bool {
    $tolMonto = abs($diferencia) <= CIERRE_TOLERANCIA_CONCEPTO;
    if ($concepto === 'cupones') {
        $tolCant = ($diff_cantidad !== null)
            ? abs($diff_cantidad) <= CIERRE_TOLERANCIA_CANTIDAD_CUPONES
            : true;
        return $tolMonto && $tolCant;
    }
    return $tolMonto;
}

/**
 * Genera el número de turno del día para la sucursal.
 * Cuenta cierres (cualquier estado) del mismo día.
 */
function cierre_numeroTurnoDia(PDO $conn, string $cod_sucursal, string $fecha): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM pos_cierres_turno
         WHERE cod_sucursal = ? AND fecha = ?"
    );
    $stmt->execute([$cod_sucursal, $fecha]);
    return (int)$stmt->fetchColumn() + 1;
}

/**
 * Verifica si ya existe un cierre en_curso para la sucursal.
 * Retorna la fila o null.
 */
function cierre_obtenerEnCurso(PDO $conn, string $cod_sucursal): ?array
{
    $stmt = $conn->prepare(
        "SELECT * FROM pos_cierres_turno
         WHERE cod_sucursal = ? AND estado = 'en_curso'
         LIMIT 1"
    );
    $stmt->execute([$cod_sucursal]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Calcula el contado de efectivo desde las filas de conteo guardadas.
 * Suma NIO directo + USD * TC.
 */
function cierre_calcularContadoEfectivo(array $filas_conteo, float $tc): float
{
    $totalNIO = 0.0;
    $totalUSD = 0.0;
    foreach ($filas_conteo as $f) {
        if ($f['moneda'] === 'NIO') {
            $totalNIO += (float)$f['monto'];
        } elseif ($f['moneda'] === 'USD') {
            $totalUSD += (float)$f['monto'];
        }
    }
    return round($totalNIO + ($totalUSD * $tc), 2);
}

/**
 * Obtiene el último conteo final de efectivo (por denominación) del cierre más reciente
 * de la sucursal, para mostrarlo como referencia al registrar caja inicial.
 *
 * Retorna array de filas ['moneda','denominacion','cantidad','monto'] o [].
 */
function cierre_obtenerConteoFinalUltimoCierre(PDO $conn, string $cod_sucursal): array
{
    // Buscar el último cierre cerrado
    $stmtC = $conn->prepare(
        "SELECT id FROM pos_cierres_turno
         WHERE cod_sucursal = ? AND estado = 'cerrado'
         ORDER BY cerrado_en DESC LIMIT 1"
    );
    $stmtC->execute([$cod_sucursal]);
    $cierre = $stmtC->fetch(PDO::FETCH_ASSOC);
    if (!$cierre) return [];

    $cierreId = (int)$cierre['id'];

    // Obtener el último intento del concepto efectivo
    $stmtI = $conn->prepare(
        "SELECT MAX(intento) as max_intento
         FROM pos_cierres_conteos
         WHERE cierre_id = ? AND concepto = 'efectivo'"
    );
    $stmtI->execute([$cierreId]);
    $maxIntento = (int)$stmtI->fetchColumn();

    $stmtF = $conn->prepare(
        "SELECT moneda, denominacion, cantidad, monto
         FROM pos_cierres_conteos
         WHERE cierre_id = ? AND concepto = 'efectivo' AND intento = ?
           AND moneda IS NOT NULL
         ORDER BY moneda, denominacion"
    );
    $stmtF->execute([$cierreId, $maxIntento]);
    return $stmtF->fetchAll(PDO::FETCH_ASSOC);
}

