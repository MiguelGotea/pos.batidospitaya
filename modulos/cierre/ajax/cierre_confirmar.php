<?php
/**
 * cierre_confirmar.php — Confirma y cierra el turno definitivamente.
 *
 * POST JSON:
 * {
 *   cierre_id: int,
 *   comentarios: { efectivo: "...", cupones: "...", ... }  // solo los que apliquen
 * }
 *
 * Pasos:
 * 1. Valida que el cierre existe y está en_curso.
 * 2. Verifica que todos los conceptos fuera de tolerancia tienen comentario.
 * 3. Guarda comentarios.
 * 4. Registra incidencias (faltantes/sobrantes).
 * 5. Genera snapshot JSON para reimpresión.
 * 6. Marca el cierre como 'cerrado'.
 *
 * Responde: { ok: true, snapshot: {...} }
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

$cierreId   = isset($data['cierre_id']) ? (int)$data['cierre_id'] : 0;
$comentarios = $data['comentarios'] ?? [];

if ($cierreId <= 0) {
    echo json_encode(['ok' => false, 'mensaje' => 'ID de cierre inválido.']);
    exit;
}

date_default_timezone_set(CIERRE_TIMEZONE);

try {
    // 1. Cargar cierre
    $stmtC = $conn->prepare(
        "SELECT ct.*, s.nombre AS nombre_sucursal
         FROM pos_cierres_turno ct
         JOIN sucursales s ON s.codigo = ct.cod_sucursal
         WHERE ct.id = ? AND ct.cod_sucursal = ? AND ct.estado = 'en_curso'
         LIMIT 1"
    );
    $stmtC->execute([$cierreId, $codSucursal]);
    $cierre = $stmtC->fetch(PDO::FETCH_ASSOC);
    if (!$cierre) {
        echo json_encode(['ok' => false, 'mensaje' => 'Cierre no encontrado o ya cerrado.']);
        exit;
    }

    // 2. Cargar conceptos
    $stmtConc = $conn->prepare(
        "SELECT * FROM pos_cierres_concepto WHERE cierre_id = ? ORDER BY concepto"
    );
    $stmtConc->execute([$cierreId]);
    $conceptosDB = $stmtConc->fetchAll(PDO::FETCH_ASSOC);

    // Verificar que el conteo inicial existe
    $stmtI0 = $conn->prepare(
        "SELECT COUNT(*) FROM pos_cierres_conteos WHERE cierre_id = ? AND intento = 0"
    );
    $stmtI0->execute([$cierreId]);
    if ((int)$stmtI0->fetchColumn() === 0) {
        echo json_encode(['ok' => false, 'mensaje' => 'Debe completar el conteo inicial antes de confirmar.']);
        exit;
    }

    // 3. Verificar comentarios obligatorios para conceptos fuera de tolerancia
    foreach ($conceptosDB as $conc) {
        if (!$conc['dentro_tolerancia'] && empty($comentarios[$conc['concepto']])) {
            echo json_encode([
                'ok'      => false,
                'mensaje' => 'Concepto "' . $conc['concepto'] . '" está fuera de tolerancia y requiere comentario.',
                'concepto_sin_comentario' => $conc['concepto'],
            ]);
            exit;
        }
    }

    $ahora = date('Y-m-d H:i:s');
    $conn->beginTransaction();

    // 4. Guardar comentarios
    foreach ($comentarios as $conc => $coment) {
        if (empty(trim($coment))) continue;
        $stmtCom = $conn->prepare(
            "UPDATE pos_cierres_concepto
             SET comentario = ?
             WHERE cierre_id = ? AND concepto = ?"
        );
        $stmtCom->execute([trim($coment), $cierreId, $conc]);
    }

    // 5. Registrar incidencias
    foreach ($conceptosDB as $conc) {
        $diferencia = (float)$conc['diferencia'];
        if (abs($diferencia) > CIERRE_TOLERANCIA_CONCEPTO || !$conc['dentro_tolerancia']) {
            if ($diferencia < 0) {
                $tipo = 'faltante';
            } elseif ($diferencia > 0) {
                $tipo = 'sobrante';
            } else {
                continue; // exacto, sin incidencia
            }
            $stmtInc = $conn->prepare(
                "INSERT INTO pos_cierres_incidencias
                    (cierre_id, concepto, tipo, monto_c, cod_cajero, registrado_en)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmtInc->execute([
                $cierreId, $conc['concepto'], $tipo, abs($diferencia), $codCajero, $ahora,
            ]);
        }
    }

    // 6. Construir snapshot completo
    $tc            = (float)$cierre['tipo_cambio_dia'];
    $corteAnterior = $cierre['corte_anterior'];
    $corteActual   = $cierre['corte_actual'];
    $sucursalId    = cierre_idSucursalDesdeCodigo($conn, $codSucursal);

    // Conteos finales por denominación (efectivo, último intento)
    $stmtMaxInt = $conn->prepare(
        "SELECT MAX(intento) FROM pos_cierres_conteos WHERE cierre_id = ? AND concepto = 'efectivo'"
    );
    $stmtMaxInt->execute([$cierreId]);
    $maxIntEfectivo = (int)$stmtMaxInt->fetchColumn();

    $stmtDenoms = $conn->prepare(
        "SELECT moneda, denominacion, cantidad, monto
         FROM pos_cierres_conteos
         WHERE cierre_id = ? AND concepto = 'efectivo' AND intento = ?
           AND moneda IS NOT NULL
         ORDER BY moneda, denominacion DESC"
    );
    $stmtDenoms->execute([$cierreId, $maxIntEfectivo]);
    $conteoFinalDenoms = $stmtDenoms->fetchAll(PDO::FETCH_ASSOC);

    // Aligeramientos del rango
    $stmtAlig = $conn->prepare(
        "SELECT a.creado_en, a.total_global, a.total_cordobas, a.total_dolares,
                a.total_dolares_en_cordobas, a.tipo_cambio_usado, a.observacion
         FROM pos_aligeramientos a
         WHERE a.sucursal_id = :sid
           AND a.creado_en > :desde
           AND a.creado_en <= :hasta
         ORDER BY a.creado_en"
    );
    $stmtAlig->execute([':sid' => $sucursalId, ':desde' => $corteAnterior, ':hasta' => $corteActual]);
    $aligeramientos = $stmtAlig->fetchAll(PDO::FETCH_ASSOC);

    // Pagos a proveedores del rango
    $stmtProv = $conn->prepare(
        "SELECT f.numero_factura, f.fecha, f.total_factura, p.nombre AS proveedor, f.notas
         FROM pos_facturas f
         JOIN proveedores p ON p.id = f.id_proveedor
         WHERE f.registrado_por IN (
             SELECT DISTINCT cod_cajero FROM pos_ventas_facturas WHERE cod_sucursal = :suc LIMIT 100
         )
         AND f.estado = 'activa'
         AND f.fecha_hora_regsys > :desde
         AND f.fecha_hora_regsys <= :hasta
         ORDER BY f.fecha_hora_regsys"
    );
    $stmtProv->execute([':suc' => $codSucursal, ':desde' => $corteAnterior, ':hasta' => $corteActual]);
    $proveedores = $stmtProv->fetchAll(PDO::FETCH_ASSOC);

    // Resumen de ventas del rango
    $stmtVentas = $conn->prepare(
        "SELECT COUNT(*) AS total_facturas, COALESCE(SUM(f.total),0) AS suma_total
         FROM pos_ventas_facturas f
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta"
    );
    $stmtVentas->execute([':suc' => $codSucursal, ':desde' => $corteAnterior, ':hasta' => $corteActual]);
    $resumenVentas = $stmtVentas->fetch(PDO::FETCH_ASSOC);

    // Pagos PedidosYa del rango (listado de facturas)
    $stmtPY = $conn->prepare(
        "SELECT f.id, f.fecha_hora, f.total
         FROM pos_ventas_facturas f
         WHERE f.cod_sucursal = :suc
           AND f.estado IN ('completada','bloqueada')
           AND f.fecha_hora > :desde
           AND f.fecha_hora <= :hasta
           AND EXISTS (
               SELECT 1 FROM pos_ventas_factura_pagos p2
               WHERE p2.id_factura = f.id AND p2.metodo = 'pedidosya'
           )
         ORDER BY f.fecha_hora"
    );
    $stmtPY->execute([':suc' => $codSucursal, ':desde' => $corteAnterior, ':hasta' => $corteActual]);
    $facturasPY = $stmtPY->fetchAll(PDO::FETCH_ASSOC);

    // Reload conceptos con comentarios actualizados
    $stmtConc2 = $conn->prepare(
        "SELECT * FROM pos_cierres_concepto WHERE cierre_id = ? ORDER BY concepto"
    );
    $stmtConc2->execute([$cierreId]);
    $conceptosFinal = $stmtConc2->fetchAll(PDO::FETCH_ASSOC);

    $snapshot = [
        'cierre'            => [
            'id'              => (int)$cierre['id'],
            'numero_turno'    => (int)$cierre['numero_turno_dia'],
            'fecha'           => $cierre['fecha'],
            'corte_anterior'  => $corteAnterior,
            'corte_actual'    => $corteActual,
            'tipo_cambio_dia' => $tc,
            'cajero'          => $cierre['nombre_cajero_snap'],
            'sucursal'        => $cierre['nombre_sucursal'],
            'caja_inicial_c'  => (float)$cierre['caja_inicial_equiv_c'],
            'cerrado_en'      => $ahora,
        ],
        'resumen_ventas'    => $resumenVentas,
        'conceptos'         => $conceptosFinal,
        'conteo_final_denoms' => $conteoFinalDenoms,
        'aligeramientos'    => $aligeramientos,
        'pagos_proveedores' => $proveedores,
        'facturas_pedidosya' => $facturasPY,
        'generado_en'       => $ahora,
    ];

    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);

    // Guardar snapshot
    $stmtSnap = $conn->prepare(
        "INSERT INTO pos_cierres_snapshot (cierre_id, snapshot_json, generado_en)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE snapshot_json = VALUES(snapshot_json), generado_en = VALUES(generado_en)"
    );
    $stmtSnap->execute([$cierreId, $snapshotJson, $ahora]);

    // 7. Marcar cierre como cerrado
    $stmtClose = $conn->prepare(
        "UPDATE pos_cierres_turno SET estado = 'cerrado', cerrado_en = ? WHERE id = ?"
    );
    $stmtClose->execute([$ahora, $cierreId]);

    $conn->commit();

    echo json_encode([
        'ok'       => true,
        'mensaje'  => 'Cierre confirmado exitosamente.',
        'cierre_id' => $cierreId,
        'snapshot' => $snapshot,
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('[cierre_confirmar] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error al confirmar el cierre. Intente nuevamente.']);
}
