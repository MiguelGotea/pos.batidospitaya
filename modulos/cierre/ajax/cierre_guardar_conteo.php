<?php
/**
 * cierre_guardar_conteo.php — Guarda el conteo inicial (ciego) o un reconteo.
 *
 * POST JSON:
 * {
 *   cierre_id: int,
 *   intento: 0 | 1 | 2 | 3,         // 0 = ciego inicial
 *   efectivo_nio: [ {denominacion, cantidad} ],
 *   efectivo_usd: [ {denominacion, cantidad} ],
 *   cupones_cantidad: int,
 *   cupones_monto:    number,
 *   transferencia_monto: number,
 *   tarjeta_monto:       number,
 *   pedidosya_monto:     number,
 *   // Para reconteos: solo los conceptos fuera de tolerancia (el resto se ignora)
 *   conceptos_a_recontar: ['efectivo','cupones',...] // solo para intento >= 1
 * }
 *
 * Responde (intento 0):
 *   { ok: true, revelado: true, conceptos: { efectivo: {...}, ... } }
 * Responde (reconteo):
 *   { ok: true, revelado: true, conceptos: { ... } }
 *
 * SEGURIDAD CIEGO: Antes de guardar el intento 0, NO se calcula ni devuelve
 * ningún esperado. El cálculo ocurre DESPUÉS de guardar.
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
require_once __DIR__ . '/../cierre_config.php';
require_once __DIR__ . '/../cierre_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'mensaje' => 'Método no permitido.']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    echo json_encode(['ok' => false, 'mensaje' => 'Datos inválidos.']);
    exit;
}

$codSucursal = $_SESSION['pos_store_sucursal'] ?? null;
$codCajero   = $_SESSION['pos_colaborador_id'] ?? null;
if (!$codSucursal || !$codCajero) {
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión inválida.']);
    exit;
}

$cierreId = isset($data['cierre_id']) ? (int)$data['cierre_id'] : 0;
$intento  = isset($data['intento'])   ? (int)$data['intento']   : 0;

if ($cierreId <= 0) {
    echo json_encode(['ok' => false, 'mensaje' => 'Cierre inválido.']);
    exit;
}
if ($intento < 0 || $intento > CIERRE_MAX_RECONTEOS) {
    echo json_encode(['ok' => false, 'mensaje' => 'Número de intento fuera de rango.']);
    exit;
}

date_default_timezone_set(CIERRE_TIMEZONE);

try {
    // Verificar que el cierre pertenece a esta sucursal y está en_curso
    $stmtC = $conn->prepare(
        "SELECT * FROM pos_cierres_turno
         WHERE id = ? AND cod_sucursal = ? AND estado = 'en_curso'
         LIMIT 1"
    );
    $stmtC->execute([$cierreId, $codSucursal]);
    $cierre = $stmtC->fetch(PDO::FETCH_ASSOC);
    if (!$cierre) {
        echo json_encode(['ok' => false, 'mensaje' => 'Cierre no encontrado o ya cerrado.']);
        exit;
    }

    // Para reconteos (intento >= 1): verificar que el intento 0 ya fue guardado
    if ($intento > 0) {
        $stmtI0 = $conn->prepare(
            "SELECT COUNT(*) FROM pos_cierres_conteos
             WHERE cierre_id = ? AND intento = 0"
        );
        $stmtI0->execute([$cierreId]);
        if ((int)$stmtI0->fetchColumn() === 0) {
            echo json_encode(['ok' => false, 'mensaje' => 'Debe guardar el conteo inicial primero.']);
            exit;
        }

        // Verificar que los conceptos a recontar existen y están fuera de tolerancia
        $conceptosARecontar = $data['conceptos_a_recontar'] ?? [];
        if (empty($conceptosARecontar)) {
            echo json_encode(['ok' => false, 'mensaje' => 'Debe indicar qué conceptos recontar.']);
            exit;
        }

        // Verificar tope de reconteos por concepto
        foreach ($conceptosARecontar as $conc) {
            $stmtTope = $conn->prepare(
                "SELECT intentos_usados FROM pos_cierres_concepto
                 WHERE cierre_id = ? AND concepto = ?"
            );
            $stmtTope->execute([$cierreId, $conc]);
            $row = $stmtTope->fetch(PDO::FETCH_ASSOC);
            if ($row && (int)$row['intentos_usados'] >= CIERRE_MAX_RECONTEOS) {
                echo json_encode([
                    'ok'      => false,
                    'mensaje' => "Se agotaron los reconteos para el concepto: $conc.",
                ]);
                exit;
            }
        }
    } else {
        // intento 0: verificar que NO exista ya un conteo 0 (no permitir duplicar conteo ciego)
        $stmtI0Check = $conn->prepare(
            "SELECT COUNT(*) FROM pos_cierres_conteos
             WHERE cierre_id = ? AND intento = 0"
        );
        $stmtI0Check->execute([$cierreId]);
        if ((int)$stmtI0Check->fetchColumn() > 0) {
            // Ya existe conteo inicial → solo revelar resultado
            // (usuario refrescó la página, no volver a guardar)
            $conceptosARecontar = [];
            goto revelar;
        }
        $conceptosARecontar = ['efectivo','cupones','transferencia','tarjeta','pedidosya'];
    }

    // ── Guardar conteo en transacción ────────────────────────────────────────
    $conn->beginTransaction();

    $ahora = date('Y-m-d H:i:s');

    // Función interna para insertar filas de conteo
    $stmtIns = $conn->prepare(
        "INSERT INTO pos_cierres_conteos
            (cierre_id, concepto, intento, moneda, denominacion, cantidad, monto, cod_usuario, registrado_en)
         VALUES
            (:cid, :conc, :int, :mon, :denom, :cant, :monto, :usr, :ts)"
    );

    // Efectivo NIO
    if (in_array('efectivo', $conceptosARecontar) && isset($data['efectivo_nio'])) {
        foreach ($data['efectivo_nio'] as $fila) {
            $denom = (float)$fila['denominacion'];
            $cant  = max(0, (int)$fila['cantidad']);
            $stmtIns->execute([
                ':cid'   => $cierreId, ':conc' => 'efectivo', ':int' => $intento,
                ':mon'   => 'NIO', ':denom' => $denom, ':cant' => $cant,
                ':monto' => round($denom * $cant, 2),
                ':usr'   => $codCajero, ':ts' => $ahora,
            ]);
        }
    }
    // Efectivo USD
    if (in_array('efectivo', $conceptosARecontar) && isset($data['efectivo_usd'])) {
        foreach ($data['efectivo_usd'] as $fila) {
            $denom = (float)$fila['denominacion'];
            $cant  = max(0, (int)$fila['cantidad']);
            $stmtIns->execute([
                ':cid'   => $cierreId, ':conc' => 'efectivo', ':int' => $intento,
                ':mon'   => 'USD', ':denom' => $denom, ':cant' => $cant,
                ':monto' => round($denom * $cant, 2),
                ':usr'   => $codCajero, ':ts' => $ahora,
            ]);
        }
    }

    // Cupones
    if (in_array('cupones', $conceptosARecontar)) {
        $stmtIns->execute([
            ':cid' => $cierreId, ':conc' => 'cupones', ':int' => $intento,
            ':mon' => null, ':denom' => null,
            ':cant' => max(0, (int)($data['cupones_cantidad'] ?? 0)),
            ':monto' => round((float)($data['cupones_monto'] ?? 0), 2),
            ':usr' => $codCajero, ':ts' => $ahora,
        ]);
    }

    // Transferencia
    if (in_array('transferencia', $conceptosARecontar)) {
        $stmtIns->execute([
            ':cid' => $cierreId, ':conc' => 'transferencia', ':int' => $intento,
            ':mon' => null, ':denom' => null, ':cant' => 0,
            ':monto' => round((float)($data['transferencia_monto'] ?? 0), 2),
            ':usr' => $codCajero, ':ts' => $ahora,
        ]);
    }

    // Tarjeta
    if (in_array('tarjeta', $conceptosARecontar)) {
        $stmtIns->execute([
            ':cid' => $cierreId, ':conc' => 'tarjeta', ':int' => $intento,
            ':mon' => null, ':denom' => null, ':cant' => 0,
            ':monto' => round((float)($data['tarjeta_monto'] ?? 0), 2),
            ':usr' => $codCajero, ':ts' => $ahora,
        ]);
    }

    // PedidosYa
    if (in_array('pedidosya', $conceptosARecontar)) {
        $stmtIns->execute([
            ':cid' => $cierreId, ':conc' => 'pedidosya', ':int' => $intento,
            ':mon' => null, ':denom' => null, ':cant' => 0,
            ':monto' => round((float)($data['pedidosya_monto'] ?? 0), 2),
            ':usr' => $codCajero, ':ts' => $ahora,
        ]);
    }

    // Actualizar intentos_usados en pos_cierres_concepto (si ya existe la fila)
    if ($intento > 0) {
        foreach ($conceptosARecontar as $conc) {
            $stmtUpd = $conn->prepare(
                "UPDATE pos_cierres_concepto
                 SET intentos_usados = intentos_usados + 1
                 WHERE cierre_id = ? AND concepto = ?"
            );
            $stmtUpd->execute([$cierreId, $conc]);
        }
    }

    $conn->commit();

    // ── Revelar: calcular esperado y diferencias ──────────────────────────────
    revelar:
    $tc            = (float)$cierre['tipo_cambio_dia'];
    $corteAnterior = $cierre['corte_anterior'];
    $corteActual   = $cierre['corte_actual'];
    $cajaEquivC    = (float)$cierre['caja_inicial_equiv_c'];

    // Obtener sucursal id entero
    $sucursalId = cierre_idSucursalDesdeCodigo($conn, $codSucursal);

    // Calcular esperados del servidor
    $espEfectivo = cierre_calcularEsperadoEfectivo(
        $conn, $codSucursal, $sucursalId, $tc, $cajaEquivC, $corteAnterior, $corteActual
    );
    $espOtros = cierre_calcularEsperadosOtros($conn, $codSucursal, $corteAnterior, $corteActual);

    // Obtener último conteo de cada concepto
    $conceptos = [];
    $listaConceptos = ['efectivo','cupones','transferencia','tarjeta','pedidosya'];

    foreach ($listaConceptos as $conc) {
        // Último intento de este concepto
        $stmtMaxInt = $conn->prepare(
            "SELECT MAX(intento) FROM pos_cierres_conteos WHERE cierre_id = ? AND concepto = ?"
        );
        $stmtMaxInt->execute([$cierreId, $conc]);
        $maxInt = (int)$stmtMaxInt->fetchColumn();

        $stmtFilas = $conn->prepare(
            "SELECT moneda, denominacion, cantidad, monto
             FROM pos_cierres_conteos
             WHERE cierre_id = ? AND concepto = ? AND intento = ?"
        );
        $stmtFilas->execute([$cierreId, $conc, $maxInt]);
        $filasConteo = $stmtFilas->fetchAll(PDO::FETCH_ASSOC);

        // Calcular contado
        if ($conc === 'efectivo') {
            $contado = cierre_calcularContadoEfectivo($filasConteo, $tc);
            $esperado = $espEfectivo['esperado'];
            $diferencia = round($contado - $esperado, 2);
            $dentroTol  = cierre_dentroTolerancia($conc, $diferencia);
            $conceptos[$conc] = [
                'esperado'           => $esperado,
                'contado'            => $contado,
                'diferencia'         => $diferencia,
                'dentro_tolerancia'  => $dentroTol,
                'intento_actual'     => $maxInt,
                'detalle_calculo'    => $espEfectivo['detalle'],
                'filas_conteo'       => $filasConteo,
            ];
        } elseif ($conc === 'cupones') {
            $filaCup = $filasConteo[0] ?? [];
            $cantContada  = (int)($filaCup['cantidad'] ?? 0);
            $montoContado = (float)($filaCup['monto']  ?? 0);
            $cantEsperada = (int)$espOtros['cupones']['cantidad_esperada'];
            $montoEsperado = (float)$espOtros['cupones']['esperado'];
            $difMonto     = round($montoContado - $montoEsperado, 2);
            $difCant      = $cantContada - $cantEsperada;
            $dentroTol    = cierre_dentroTolerancia($conc, $difMonto, $difCant);
            $conceptos[$conc] = [
                'esperado'          => $montoEsperado,
                'contado'           => $montoContado,
                'diferencia'        => $difMonto,
                'cantidad_esperada' => $cantEsperada,
                'cantidad_contada'  => $cantContada,
                'diferencia_cant'   => $difCant,
                'dentro_tolerancia' => $dentroTol,
                'intento_actual'    => $maxInt,
            ];
        } else {
            $fila = $filasConteo[0] ?? [];
            $contado  = (float)($fila['monto'] ?? 0);
            $esperado = (float)$espOtros[$conc]['esperado'];
            $diferencia = round($contado - $esperado, 2);
            $dentroTol  = cierre_dentroTolerancia($conc, $diferencia);
            $conceptos[$conc] = [
                'esperado'          => $esperado,
                'contado'           => $contado,
                'diferencia'        => $diferencia,
                'dentro_tolerancia' => $dentroTol,
                'intento_actual'    => $maxInt,
            ];
        }

        // Guardar/actualizar pos_cierres_concepto
        $stmtUpsert = $conn->prepare(
            "INSERT INTO pos_cierres_concepto
                (cierre_id, concepto, esperado, contado_final, diferencia, dentro_tolerancia,
                 cantidad_esperada, cantidad_contada, intentos_usados)
             VALUES
                (:cid, :conc, :esp, :cont, :dif, :tol, :ce, :cc, :intentos)
             ON DUPLICATE KEY UPDATE
                esperado         = VALUES(esperado),
                contado_final    = VALUES(contado_final),
                diferencia       = VALUES(diferencia),
                dentro_tolerancia= VALUES(dentro_tolerancia),
                cantidad_esperada= VALUES(cantidad_esperada),
                cantidad_contada = VALUES(cantidad_contada),
                actualizado_en   = NOW()"
        );
        $stmtUpsert->execute([
            ':cid'      => $cierreId,
            ':conc'     => $conc,
            ':esp'      => $conceptos[$conc]['esperado'],
            ':cont'     => $conceptos[$conc]['contado'],
            ':dif'      => $conceptos[$conc]['diferencia'],
            ':tol'      => $conceptos[$conc]['dentro_tolerancia'] ? 1 : 0,
            ':ce'       => $conceptos[$conc]['cantidad_esperada'] ?? null,
            ':cc'       => $conceptos[$conc]['cantidad_contada']  ?? null,
            ':intentos' => $conceptos[$conc]['intento_actual'],
        ]);
    }

    echo json_encode([
        'ok'        => true,
        'revelado'  => true,
        'cierre_id' => $cierreId,
        'conceptos' => $conceptos,
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[cierre_guardar_conteo] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error en base de datos. Intente nuevamente.']);
}
