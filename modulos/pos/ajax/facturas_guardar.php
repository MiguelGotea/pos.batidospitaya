<?php
// facturas_guardar.php — Guardado de ventas del POS Web
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    // ---- Datos de cabecera ----
    $tipoCliente    = isset($_POST['tipo_cliente']) && $_POST['tipo_cliente'] === 'club' ? 'club' : 'general';
    $idClienteClub  = !empty($_POST['id_cliente_club']) ? (int)$_POST['id_cliente_club'] : null;
    $nombreCliente  = isset($_POST['nombre_cliente']) ? trim($_POST['nombre_cliente']) : null;
    $detalle        = isset($_POST['detalle']) ? json_decode($_POST['detalle'], true) : [];
    $pagos          = isset($_POST['pagos']) ? json_decode($_POST['pagos'], true) : [];
    $conPropina     = isset($_POST['con_propina']) && (int)$_POST['con_propina'] === 1 ? 1 : 0;
    $propina        = isset($_POST['propina']) ? round((float)$_POST['propina'], 2) : 0.00;
    $porcentajeProp = $conPropina ? 2.00 : 0.00;
    if (!$conPropina) $propina = 0.00;

    $usuarioId      = $_SESSION['pos_colaborador_id'] ?? 1;
    $sucursalCodigo = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? '1');

    // ---- Validaciones ----
    if ($tipoCliente === 'club' && !$idClienteClub) {
        throw new Exception('Debe seleccionar un socio válido del Club Pitaya.');
    }
    if ($tipoCliente === 'general' && empty($nombreCliente)) {
        throw new Exception('El nombre del cliente general es obligatorio.');
    }
    if (empty($detalle) || !is_array($detalle) || count($detalle) === 0) {
        throw new Exception('Debe agregar al menos un producto a la factura.');
    }
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Error procesando el detalle de productos.');
    }

    // ---- Validar montos de productos y calcular subtotales ----
    $subtotal = 0;
    $totalPuntosGanados = 0;

    foreach ($detalle as $i => $item) {
        $cantidad       = isset($item['cantidad']) ? (float)$item['cantidad'] : 0;
        $costoTotalIva  = isset($item['costo_total_iva']) ? (float)$item['costo_total_iva'] : 0;
        $idPresentacion = isset($item['id_presentacion']) ? (int)$item['id_presentacion'] : 0;

        if ($idPresentacion <= 0) throw new Exception("Producto inválido en la línea " . ($i + 1) . ".");
        if ($cantidad <= 0)       throw new Exception("La cantidad debe ser mayor a 0 en la línea " . ($i + 1) . ".");
        if ($costoTotalIva < 0)   throw new Exception("El costo no puede ser negativo en la línea " . ($i + 1) . ".");

        $subtotal += $costoTotalIva;
        $totalPuntosGanados += $cantidad;
    }

    $subtotalCalculado     = round($subtotal, 2);
    $totalFacturaCalculado = round($subtotalCalculado + $propina, 2);

    // ---- Validar métodos de pago ----
    if (empty($pagos) || !is_array($pagos) || count($pagos) === 0) {
        throw new Exception('Debe registrar al menos un método de pago.');
    }

    $totalPagos = 0;
    foreach ($pagos as $i => $p) {
        $monto = isset($p['monto']) ? (float)$p['monto'] : 0;
        if ($monto <= 0) {
            throw new Exception("El monto del método de pago #" . ($i + 1) . " debe ser mayor a 0.");
        }
        // Si es efectivo dólar, convertir a córdobas usando la tasa de BD
        if (($p['metodo'] ?? '') === 'efectivo_dolar' && $tasaUSD) {
            $totalPagos += round($monto * $tasaUSD, 2);
        } else {
            $totalPagos += $monto;
        }
    }

    if (round($totalPagos, 2) < $totalFacturaCalculado) {
        throw new Exception("Los pagos registrados (C$ " . number_format($totalPagos, 2) . ") no cubren el total a pagar (C$ " . number_format($totalFacturaCalculado, 2) . ").");
    }

    // ---- Obtener tipo de cambio USD/NIO desde BD (solo lectura, no del frontend) ----
    $stmtTC = $conn->prepare(
        "SELECT tasa FROM pos_ventas_tipo_cambio WHERE fecha <= CURDATE() ORDER BY fecha DESC LIMIT 1"
    );
    $stmtTC->execute();
    $tasaUSD = $stmtTC->fetchColumn();
    $tasaUSD = $tasaUSD ? round((float)$tasaUSD, 4) : null;

    $conn->beginTransaction();

    // ---- 1. Insertar en pos_ventas_facturas ----
    $sqlHeader = "INSERT INTO pos_ventas_facturas
                  (cod_sucursal, cod_cajero, tipo_cliente, id_cliente_club, nombre_cliente_general, subtotal, propina, porcentaje_propina, con_propina, total, estado, fecha_hora)
                  VALUES (:sucursal, :cajero, :tipo, :id_cliente, :nombre_gen, :subtotal, :propina, :porc_prop, :con_prop, :total, 'bloqueada', NOW())";
    
    $stmtHeader = $conn->prepare($sqlHeader);
    $stmtHeader->execute([
        ':sucursal'   => (string)$sucursalCodigo,
        ':cajero'     => $usuarioId,
        ':tipo'       => $tipoCliente,
        ':id_cliente' => ($tipoCliente === 'club') ? $idClienteClub : null,
        ':nombre_gen' => ($tipoCliente === 'general') ? $nombreCliente : null,
        ':subtotal'   => $subtotalCalculado,
        ':propina'    => $propina,
        ':porc_prop'  => $porcentajeProp,
        ':con_prop'   => $conPropina,
        ':total'      => $totalFacturaCalculado
    ]);
    $idFactura = (int)$conn->lastInsertId();

    // ---- 2. Insertar en pos_ventas_factura_lineas ----
    $sqlLinea = "INSERT INTO pos_ventas_factura_lineas
                 (id_factura, id_producto_presentacion, nombre_producto_snapshot, cantidad, precio_unitario_snap, precio_extras_total, precio_unitario_final, precio_linea_total, es_canje_puntos, notas)
                 VALUES (:id_fac, :id_pres, :nombre_snap, :cant, :precio_snap, :precio_extras, :precio_final, :precio_total, :es_canje, :notas)";
    $stmtLinea = $conn->prepare($sqlLinea);

    foreach ($detalle as $item) {
        $isClubCliente  = ($tipoCliente === 'club' && !empty($idClienteClub));
        $esCanjeado     = ($isClubCliente && !empty($item['es_canjeado'])) ? 1 : 0;
        $cantidad       = (float)$item['cantidad'];
        $costoTotalIva  = $esCanjeado ? 0 : (float)$item['costo_total_iva'];
        $precioUnitario = ($cantidad > 0) ? round($costoTotalIva / $cantidad, 2) : 0;
        $precioBase     = isset($item['precio_unitario_base']) ? (float)$item['precio_unitario_base'] : $precioUnitario;
        $nombreSnap     = isset($item['nombre']) ? trim($item['nombre']) : 'Producto';
        $notasItem      = !empty($item['notas']) ? trim($item['notas']) : '';

        // Procesar resumen de personalización para auditoría y cocina
        $montoExtras = 0;
        $resumenPers = [];
        if ($esCanjeado) {
            $resumenPers[] = "PRODUCTO CANJEADO (Puntos)";
        }
        if (!empty($item['personalizacion']) && is_array($item['personalizacion'])) {
            $p = $item['personalizacion'];
            if (!empty($p['endulzante_nombre'])) {
                $resumenPers[] = "Endulzante: " . trim($p['endulzante_nombre']);
            }
            if (!empty($p['ingredientes_quitados']) && is_array($p['ingredientes_quitados'])) {
                $quits = array_column($p['ingredientes_quitados'], 'nombre_componente');
                if (!empty($quits)) $resumenPers[] = "Sin: " . implode(', ', $quits);
            }
            if (!empty($p['ingredientes_sustituidos']) && is_array($p['ingredientes_sustituidos'])) {
                $susts = [];
                foreach ($p['ingredientes_sustituidos'] as $s) {
                    $orig  = isset($s['nombre_original']) ? trim($s['nombre_original']) : '';
                    $nuevo = isset($s['nombre_nuevo'])    ? trim($s['nombre_nuevo'])    : 'Otro';
                    $susts[] = $orig ? "{$orig} → {$nuevo}" : $nuevo;
                }
                if (!empty($susts)) $resumenPers[] = "Cambio: " . implode(', ', $susts);
            }
            if (!empty($p['extras_agregados']) && is_array($p['extras_agregados'])) {
                $exts = array_column($p['extras_agregados'], 'nombre');
                if (!empty($exts)) $resumenPers[] = "Extra: " . implode(', ', $exts);
            }
            $montoExtras = isset($p['monto_extras']) ? (float)$p['monto_extras'] : 0;
        }

        $textoPers = !empty($resumenPers) ? "[" . implode(" | ", $resumenPers) . "]" : "";
        $notasFinal = trim(($notasItem ? $notasItem . " " : "") . $textoPers);
        if ($notasFinal === "") $notasFinal = null;

        $stmtLinea->execute([
            ':id_fac'        => $idFactura,
            ':id_pres'       => (int)$item['id_presentacion'],
            ':nombre_snap'   => $nombreSnap,
            ':cant'          => $cantidad,
            ':precio_snap'   => $precioBase,
            ':precio_extras' => $montoExtras,
            ':precio_final'  => $precioUnitario,
            ':precio_total'  => $costoTotalIva,
            ':es_canje'      => $esCanjeado,
            ':notas'         => $notasFinal
        ]);
    }

    // ---- 3. Insertar en pos_ventas_factura_pagos ----
    /* =====================================================================================
     * GESTIÓN DE MÉTODOS DE PAGO Y CUPONES:
     * 
     * [MODO PRUEBAS ACTUAL]:
     * - Se registra el pago de tipo 'cupon' con su id_cupon, codigo_cupon y monto en pos_ventas_factura_pagos.
     * - NO se ejecuta UPDATE a cupones_sucursales (NO se marca aplicado = 1).
     * - Esto permite a administración y supervisores probar libremente los cupones reales sin quemar datos.
     * 
     * [CUANDO SE PASE OFICIALMENTE A PRODUCCIÓN]:
     * - Descomentar la siguiente instrucción dentro de la transacción para marcarlo como usado:
     * 
     *   if ($metodo === 'cupon' && !empty($idCupon)) {
     *       $stmtBurn = $conn->prepare("UPDATE cupones_sucursales SET aplicado = 1, cod_pedido = :id_fac, hora_activacion = NOW() WHERE id = :id_cup");
     *       $stmtBurn->execute([':id_fac' => $idFactura, ':id_cup' => $idCupon]);
     *   }
     * ===================================================================================== */
    $sqlPago = "INSERT INTO pos_ventas_factura_pagos
                (id_factura, metodo, moneda, monto_original, monto_cordoba, tipo_cambio_aplicado, id_cupon, codigo_cupon, referencia)
                VALUES (:id_fac, :metodo, :moneda, :monto_orig, :monto_cor, :tipo_cambio, :id_cupon, :codigo_cupon, :ref)";
    $stmtPago = $conn->prepare($sqlPago);

    foreach ($pagos as $p) {
        $metodoRaw   = $p['metodo'] ?? 'efectivo';
        $esDolar     = ($metodoRaw === 'efectivo_dolar');
        $metodo      = $esDolar ? 'efectivo' : (in_array($metodoRaw, ['efectivo', 'pos', 'transferencia', 'cupon']) ? $metodoRaw : 'efectivo');
        $montoPago    = isset($p['monto']) ? round((float)$p['monto'], 2) : 0;
        $refPago      = !empty($p['referencia']) ? trim($p['referencia']) : null;
        // Efectivo en dólares: T/C siempre desde BD, nunca del frontend
        if ($esDolar) {
            if (!$tasaUSD) {
                throw new Exception('No hay tipo de cambio USD/NIO configurado para hoy. Contacte al administrador.');
            }
            $tipoCambio   = $tasaUSD;
            $moneda       = 'USD';
            $montoOrig    = $montoPago;                   // dólares ingresados
            $montoCor     = round($montoOrig * $tipoCambio, 2);
        } else {
            $tipoCambio   = null;
            $moneda       = 'NIO';
            $montoOrig    = $montoPago;
            $montoCor     = $montoPago;
        }
        $idCupon     = ($metodo === 'cupon' && !empty($p['id_cupon'])) ? (int)$p['id_cupon'] : null;
        $codigoCupon = ($metodo === 'cupon') ? $refPago : null;

        $stmtPago->execute([
            ':id_fac'       => $idFactura,
            ':metodo'       => $metodo,
            ':moneda'       => $moneda,
            ':monto_orig'   => $montoOrig,
            ':monto_cor'    => $montoCor,
            ':tipo_cambio'  => $tipoCambio,
            ':id_cupon'     => $idCupon,
            ':codigo_cupon' => $codigoCupon,
            ':ref'          => $refPago
        ]);

        // MODO PRUEBAS: Aquí se activará el UPDATE a cupones_sucursales cuando el sistema se lance oficialmente.
    }

    // ---- 4. Registrar puntos si es socio Club Pitaya ----
    if ($tipoCliente === 'club' && $idClienteClub && $totalPuntosGanados > 0) {
        $sqlPuntos = "INSERT INTO pos_ventas_factura_puntos_club
                      (id_factura, id_cliente_club, puntos_ganados, puntos_canjeados, observaciones)
                      VALUES (:id_fac, :id_cliente, :ganados, 0.00, 'Venta POS Web')";
        $stmtPuntos = $conn->prepare($sqlPuntos);
        $stmtPuntos->execute([
            ':id_fac'     => $idFactura,
            ':id_cliente' => $idClienteClub,
            ':ganados'    => round($totalPuntosGanados, 2)
        ]);
    }

    $conn->commit();

    echo json_encode([
        'success'    => true,
        'message'    => 'Venta guardada exitosamente.',
        'id_factura' => $idFactura
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
