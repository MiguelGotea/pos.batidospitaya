<?php
/**
 * aligeramiento_subir_voucher.php
 * Sube foto de voucher, la procesa con IA y la almacena en aligeramientos_vouchers.
 * Portado de erp/modulos/contabilidad/ajax/masivo_depositos_subir_voucher.php
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();

// Cargar AIService desde ERP (ruta absoluta compartida)
require_once $_SERVER['DOCUMENT_ROOT'] . '/../erp.batidospitaya.com/core/ai/AIService.php';

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(120);
@ini_set('memory_limit', '256M');

// ── Capturar errores fatales ─────────────────────────────────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success'      => false,
            'message'      => 'Error interno del servidor al procesar la imagen.',
            'ia_error_tipo'=> 'SERVER_FATAL'
        ]);
    }
});

try {
    // ── Validar archivo ──────────────────────────────────────────────────
    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['foto']['error'] ?? -1;
        $mensajesError = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo supera el tamaño máximo (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño máximo del formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo solo se subió parcialmente. Reintenta.',
            UPLOAD_ERR_NO_FILE    => 'No se recibió ningún archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal en el servidor.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión del servidor detuvo la subida.',
        ];
        $msg = $mensajesError[$errCode] ?? "Error al recibir la imagen (código: $errCode).";
        throw new Exception($msg);
    }

    $aligeramientoId = isset($_POST['aligeramiento_id']) ? (int)$_POST['aligeramiento_id'] : 0;
    $montoRegistro   = isset($_POST['monto_registro'])   ? (float)$_POST['monto_registro']  : null;

    if ($aligeramientoId < 1) {
        throw new Exception('aligeramiento_id inválido.');
    }

    // ── Verificar que el aligeramiento pertenece a la sucursal del dispositivo ──
    require_once '../../../core/database/conexion.php';
    $sucursalSesion = $_SESSION['pos_store_sucursal'] ?? null;

    $stmtCheck = $conn->prepare("
        SELECT sucursal_id, total_cordobas, total_dolares, total_global
        FROM pos_aligeramientos
        WHERE id = :id
    ");
    $stmtCheck->execute([':id' => $aligeramientoId]);
    $aRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$aRow) {
        throw new Exception('Aligeramiento no encontrado.');
    }
    if ($sucursalSesion && (int)$aRow['sucursal_id'] !== (int)$sucursalSesion) {
        throw new Exception('No tiene permisos para subir vouchers a este aligeramiento.');
    }

    // Usar el total_global del aligeramiento si no se pasó monto_registro
    if ($montoRegistro === null) {
        $montoRegistro = (float)$aRow['total_global'];
    }

    // ── Guardar imagen ───────────────────────────────────────────────────
    $mes       = date('Ym');
    $uploadDir = __DIR__ . "/../uploads/vouchers_aligeramientos/{$aRow['sucursal_id']}/{$mes}/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0775, true);
    }

    $ext     = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed)) {
        throw new Exception('Tipo de archivo no permitido. Use JPG, PNG o WEBP.');
    }

    $filename   = time() . '_alig' . $aligeramientoId . '_suc' . $aRow['sucursal_id'] . '.' . $ext;
    $targetPath = $uploadDir . $filename;

    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $targetPath)) {
        throw new Exception('No se pudo guardar la imagen en el servidor.');
    }

    $fotoPathRelativo = "pos.batidospitaya.com/modulos/aligeramiento/uploads/vouchers_aligeramientos/{$aRow['sucursal_id']}/{$mes}/{$filename}";

    // ── Procesar con IA ──────────────────────────────────────────────────
    $imageData = base64_encode(file_get_contents($targetPath));
    $mimeType  = mime_content_type($targetPath);

    $systemPrompt = "Eres un asistente experto en contabilidad nicaraguense. Analiza esta imagen de un voucher de deposito bancario (BAC, Banpro, Punto Express, FICOHSA, BDF, Avianca, etc.).
REGLA PRINCIPAL: Si en la imagen aparecen 2 o mas vouchers/recibos distintos con distintos montos y referencias, responde SOLO este JSON: {\"error\": \"MULTIPLES_VOUCHERS\", \"cantidad_detectada\": 2}
Si la imagen esta borrosa, ilegible o no es un voucher de deposito valido, responde SOLO: {\"error\": \"LECTURA_FALLIDA\", \"motivo\": \"descripcion\"}
Si la resolucion es tan baja que no se puede leer el texto, responde SOLO: {\"error\": \"BAJA_RESOLUCION\", \"motivo\": \"Texto no legible\"}
Si hay EXACTAMENTE 1 voucher, extrae los datos en este JSON: {\"monto\": 4800, \"denominacion\": \"Cordobas\", \"fecha_hora\": \"2026-11-04 13:59:13\", \"lugar\": \"Super Express Altamira\"}
Reglas: C\$, NIO, Cordobas → denominacion=Cordobas. USD, US\$, Dolar → denominacion=Dolares. Monto = campo Monto Aplicado o Monto Pagado. Convierte fechas DD/MM/YYYY a YYYY-MM-DD. Responde SOLO el JSON puro, sin markdown ni texto adicional.";

    $userPrompt = "Extrae los datos de este voucher de depósito bancario de aligeramiento de caja.";

    $extraParts = [[
        'inline_data' => [
            'mime_type' => $mimeType,
            'data'      => $imageData,
        ]
    ]];

    $proveedores    = ['google', 'openai', 'groq'];
    $iaData         = null;
    $proveedorUsado = '';
    $errores        = [];

    foreach ($proveedores as $prov) {
        try {
            $ai = new AIService($conn, $prov);
            if ($prov === 'openai') {
                $ai->setModel('gpt-4o-mini');
            } elseif ($prov === 'groq') {
                $ai->setModel('llama-3.2-90b-vision-preview');
            }
            $respuesta = $ai->procesarPrompt($systemPrompt, $userPrompt, 0.1, $extraParts);
            if ($respuesta) {
                $iaData = $ai->extraerJSON($respuesta);
                if (is_array($iaData) && isset($iaData[0]) && is_array($iaData[0])) {
                    $iaData = $iaData[0];
                }
                if ($iaData && isset($iaData['monto'])) {
                    $proveedorUsado = $prov;
                    break;
                }
                if ($iaData && isset($iaData['error'])) {
                    $proveedorUsado = $prov;
                    break;
                }
                $iaData = null;
            }
        } catch (Exception $e) {
            $errores[] = strtoupper($prov) . ': ' . $e->getMessage();
            continue;
        }
    }

    // ── Errores semánticos de la IA ──────────────────────────────────────
    if ($iaData && isset($iaData['error'])) {
        $tipoError  = $iaData['error'];
        $mensajesIA = [
            'MULTIPLES_VOUCHERS' => 'La imagen contiene ' . ($iaData['cantidad_detectada'] ?? 'varios') . ' vouchers en una sola foto. '
                . 'Por favor sube cada voucher por separado.',
            'LECTURA_FALLIDA'    => 'La IA no pudo leer el voucher: ' . ($iaData['motivo'] ?? 'imagen no reconocida como voucher válido.'),
            'BAJA_RESOLUCION'    => 'La foto tiene muy baja resolución. Toma la foto más cerca o con mejor iluminación.',
        ];
        $msgIA = $mensajesIA[$tipoError] ?? 'La IA no pudo procesar la imagen: ' . ($iaData['motivo'] ?? $tipoError);
        if (!empty($targetPath) && file_exists($targetPath)) @unlink($targetPath);
        echo json_encode(['success' => false, 'message' => $msgIA, 'ia_error_tipo' => $tipoError]);
        exit;
    }

    if (!$iaData || !isset($iaData['monto'])) {
        $detalle = !empty($errores) ? ' Detalles: ' . implode('; ', $errores) : '';
        $msgFallo = 'La IA no pudo leer el voucher. Posibles causas: foto borrosa o con poca luz, '
            . 'ángulo incorrecto, imagen cortada, o no es un voucher de depósito reconocible.' . $detalle;
        if (!empty($targetPath) && file_exists($targetPath)) @unlink($targetPath);
        echo json_encode(['success' => false, 'message' => $msgFallo]);
        exit;
    }

    // ── Normalizar datos IA ──────────────────────────────────────────────
    $iaMonto     = isset($iaData['monto'])       ? (float)$iaData['monto']        : null;
    $iaDenom     = isset($iaData['denominacion'])? trim($iaData['denominacion'])   : null;
    $iaFechaHora = isset($iaData['fecha_hora'])  ? trim((string)$iaData['fecha_hora']) : null;
    $iaLugar     = isset($iaData['lugar'])       ? trim($iaData['lugar'])          : null;
    $iaRaw       = json_encode($iaData, JSON_UNESCAPED_UNICODE);

    if ($iaFechaHora && !preg_match('/^\d{4}-\d{2}-\d{2}/', $iaFechaHora)) {
        $iaFechaHora = null;
    }

    // ── Calcular alerta comparando suma total de vouchers vs total_global ─
    $stmtSum = $conn->prepare("
        SELECT ia_denominacion, COALESCE(SUM(ia_monto), 0) as total
        FROM aligeramientos_vouchers
        WHERE aligeramiento_id = :id AND ia_monto IS NOT NULL
        GROUP BY ia_denominacion
    ");
    $stmtSum->execute([':id' => $aligeramientoId]);
    $sumasExistentes = $stmtSum->fetchAll(PDO::FETCH_ASSOC);

    $totales = [];
    foreach ($sumasExistentes as $s) {
        $totales[$s['ia_denominacion']] = (float)$s['total'];
    }
    if ($iaMonto !== null && $iaDenom) {
        $totales[$iaDenom] = ($totales[$iaDenom] ?? 0) + $iaMonto;
    }

    $alerta        = 0;
    $alertaDetalle = null;

    if (!empty($totales) && $iaMonto !== null) {
        $totalSumaVouchers = array_sum($totales);
        // Tolerancia: 1 unidad
        if (abs($totalSumaVouchers - $montoRegistro) > 1) {
            $alerta = 1;
            $diff   = $totalSumaVouchers - $montoRegistro;
            $signo  = $diff > 0 ? '+' : '';
            $alertaDetalle = "Suma vouchers: C$ " . number_format($totalSumaVouchers, 2)
                . " | Registrado: C$ " . number_format($montoRegistro, 2)
                . " | Diferencia: C$ " . $signo . number_format($diff, 2);
        }
    }

    // ── Insertar en BD ───────────────────────────────────────────────────
    $codOperario = $_SESSION['pos_colaborador_id'] ?? null;

    $stmt = $conn->prepare("
        INSERT INTO aligeramientos_vouchers
            (aligeramiento_id, sucursal_id, foto_path, ia_monto, ia_denominacion,
             ia_fecha_hora, ia_lugar, ia_raw_json, ia_proveedor,
             alerta, alerta_detalle, subido_por)
        VALUES
            (:alid, :suc, :path, :monto, :denom,
             :fecha, :lugar, :raw, :prov,
             :alerta, :alert_det, :operario)
    ");
    $stmt->execute([
        ':alid'      => $aligeramientoId,
        ':suc'       => $aRow['sucursal_id'],
        ':path'      => $fotoPathRelativo,
        ':monto'     => $iaMonto,
        ':denom'     => $iaDenom,
        ':fecha'     => $iaFechaHora ?: null,
        ':lugar'     => $iaLugar,
        ':raw'       => $iaRaw,
        ':prov'      => $proveedorUsado ?: null,
        ':alerta'    => $alerta,
        ':alert_det' => $alertaDetalle,
        ':operario'  => $codOperario,
    ]);
    $nuevoId = $conn->lastInsertId();

    // ── Resumen de validación ────────────────────────────────────────────
    $stmtResumen = $conn->prepare("
        SELECT ia_denominacion, SUM(ia_monto) as total
        FROM aligeramientos_vouchers
        WHERE aligeramiento_id = :id AND ia_monto IS NOT NULL
        GROUP BY ia_denominacion
    ");
    $stmtResumen->execute([':id' => $aligeramientoId]);
    $resumenRows = $stmtResumen->fetchAll(PDO::FETCH_ASSOC);

    $resumen = [
        'monto_registro'     => $montoRegistro,
        'totales_por_moneda' => [],
        'tiene_alerta'       => false,
        'alerta_msg'         => '',
    ];
    foreach ($resumenRows as $r) {
        $resumen['totales_por_moneda'][$r['ia_denominacion']] = (float)$r['total'];
    }
    if ($alertaDetalle) {
        $resumen['tiene_alerta'] = true;
        $resumen['alerta_msg']   = $alertaDetalle;
    }

    echo json_encode([
        'success'         => true,
        'id'              => (int)$nuevoId,
        'foto_path'       => $fotoPathRelativo,
        'ia_monto'        => $iaMonto,
        'ia_denominacion' => $iaDenom,
        'ia_fecha_hora'   => $iaFechaHora,
        'ia_lugar'        => $iaLugar,
        'ia_proveedor'    => $proveedorUsado,
        'alerta'          => (bool)$alerta,
        'alerta_detalle'  => $alertaDetalle,
        'resumen'         => $resumen,
        'errores_ia'      => $errores,
    ]);

} catch (Exception $e) {
    if (!empty($targetPath) && file_exists($targetPath)) @unlink($targetPath);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

