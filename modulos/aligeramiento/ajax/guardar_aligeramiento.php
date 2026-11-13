<?php
/**
 * guardar_aligeramiento.php
 * AJAX endpoint — Guarda un nuevo registro de Aligeramiento en BD
 * POS / modulos/aligeramiento/ajax/guardar_aligeramiento.php
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

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

// Validar campos obligatorios
$camposReq = ['fecha', 'sucursal_id', 'tipo_cambio_usado', 'total_cordobas', 'total_dolares',
              'total_dolares_en_cordobas', 'total_global', 'detalles'];
foreach ($camposReq as $c) {
    if (!isset($data[$c]) && $data[$c] !== 0) {
        echo json_encode(['ok' => false, 'mensaje' => "Campo faltante: $c"]);
        exit;
    }
}

// Validar que sucursal coincida con la sesión (seguridad)
$sucursalSesion = $_SESSION['pos_store_sucursal'] ?? null;
if (!$sucursalSesion) {
    echo json_encode(['ok' => false, 'mensaje' => 'No se detectó sucursal en sesión. Reinicia sesión.']);
    exit;
}

$sucursalId = (int)$data['sucursal_id'];
if ($sucursalId !== (int)$sucursalSesion) {
    echo json_encode(['ok' => false, 'mensaje' => 'La sucursal del formulario no coincide con la sesión.']);
    exit;
}

// Obtener usuario de sesión
$codUsuario = $_SESSION['pos_colaborador_id'] ?? null;
if (!$codUsuario) {
    echo json_encode(['ok' => false, 'mensaje' => 'No se detectó colaborador en sesión.']);
    exit;
}

$fecha      = $data['fecha'];
$totalGlobal = (float)$data['total_global'];

if ($totalGlobal <= 0) {
    echo json_encode(['ok' => false, 'mensaje' => 'El total del aligeramiento debe ser mayor a cero.']);
    exit;
}

try {
    $conn->beginTransaction();

    // 1. Insertar registro maestro
    $stmtMaster = $conn->prepare("
        INSERT INTO pos_aligeramientos
            (sucursal_id, fecha, hora, tipo_cambio_usado,
             total_cordobas, total_dolares, total_dolares_en_cordobas,
             total_global, observacion, cod_usuario)
        VALUES
            (:sucursal, :fecha, :hora, :tc,
             :total_nio, :total_usd, :total_usd_nio,
             :total_global, :obs, :cod_usuario)
    ");
    $stmtMaster->execute([
        ':sucursal'     => $sucursalId,
        ':fecha'        => $fecha,
        ':hora'         => date('H:i:s'),
        ':tc'           => round((float)$data['tipo_cambio_usado'], 4),
        ':total_nio'    => round((float)$data['total_cordobas'], 2),
        ':total_usd'    => round((float)$data['total_dolares'], 2),
        ':total_usd_nio'=> round((float)$data['total_dolares_en_cordobas'], 2),
        ':total_global' => round($totalGlobal, 2),
        ':obs'          => isset($data['observacion']) ? trim($data['observacion']) : null,
        ':cod_usuario'  => $codUsuario,
    ]);

    $aligeramientoId = (int)$conn->lastInsertId();

    // 2. Insertar detalles por denominación
    $stmtDet = $conn->prepare("
        INSERT INTO pos_aligeramientos_detalle
            (aligeramiento_id, moneda, denominacion, cantidad, total)
        VALUES
            (:alid, :moneda, :denom, :cantidad, :total)
    ");

    foreach ($data['detalles'] as $det) {
        $qty = (int)($det['cantidad'] ?? 0);
        if ($qty <= 0) continue; // Omitir denominaciones vacías
        $stmtDet->execute([
            ':alid'    => $aligeramientoId,
            ':moneda'  => $det['moneda'],
            ':denom'   => round((float)$det['denominacion'], 2),
            ':cantidad'=> $qty,
            ':total'   => round((float)$det['total'], 2),
        ]);
    }

    $conn->commit();

    echo json_encode([
        'ok'               => true,
        'aligeramiento_id' => $aligeramientoId,
        'mensaje'          => 'Aligeramiento registrado correctamente.',
    ]);

} catch (PDOException $e) {
    $conn->rollBack();
    error_log('[guardar_aligeramiento] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error en base de datos. Intente nuevamente.']);
}

