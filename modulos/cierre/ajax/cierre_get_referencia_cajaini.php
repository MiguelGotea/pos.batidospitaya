<?php
/**
 * cierre_get_referencia_cajaini.php
 * Devuelve el conteo final por denominación del último cierre cerrado
 * de la sucursal, para mostrarlo como referencia al registrar caja inicial.
 *
 * GET (sin parámetros — toma sucursal de sesión)
 * Responde: { ok: true, tiene_cierre: bool, conteo: [...], total_c: N, tc_cierre: N }
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
require_once __DIR__ . '/../cierre_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$codSucursal = $_SESSION['pos_store_sucursal'] ?? null;
if (!$codSucursal) {
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión inválida.']);
    exit;
}

try {
    $conteo = cierre_obtenerConteoFinalUltimoCierre($conn, $codSucursal);

    if (empty($conteo)) {
        echo json_encode(['ok' => true, 'tiene_cierre' => false, 'conteo' => [], 'total_c' => 0, 'tc_cierre' => null]);
        exit;
    }

    // Obtener TC del último cierre y fecha
    $stmtMeta = $conn->prepare(
        "SELECT tipo_cambio_dia, fecha, nombre_cajero_snap, cerrado_en
         FROM pos_cierres_turno
         WHERE cod_sucursal = ? AND estado = 'cerrado'
         ORDER BY cerrado_en DESC LIMIT 1"
    );
    $stmtMeta->execute([$codSucursal]);
    $meta = $stmtMeta->fetch(PDO::FETCH_ASSOC);
    $tc = $meta ? (float)$meta['tipo_cambio_dia'] : 36.6;

    // Calcular equivalente C$ con el TC del cierre (cada denominación en su moneda original)
    $totalC = 0.0;
    foreach ($conteo as $f) {
        if ($f['moneda'] === 'NIO') {
            $totalC += (float)$f['monto'];
        } elseif ($f['moneda'] === 'USD') {
            $totalC += (float)$f['monto'] * $tc;
        }
    }

    echo json_encode([
        'ok'          => true,
        'tiene_cierre'=> true,
        'conteo'      => $conteo,
        'total_c'     => round($totalC, 2),
        'tc_cierre'   => $tc,
        'cajero'      => $meta['nombre_cajero_snap'] ?? '',
        'cerrado_en'  => $meta['cerrado_en']          ?? '',
    ]);
} catch (PDOException $e) {
    error_log('[cierre_get_referencia_cajaini] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error en base de datos.']);
}

