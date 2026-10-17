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

    // ---- Validar montos y calcular totales ----
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
        $totalPuntosGanados += $cantidad; // 1 punto por unidad en este prototipo
    }

    $conn->beginTransaction();

    // ---- 1. Insertar en pos_ventas_facturas ----
    $sqlHeader = "INSERT INTO pos_ventas_facturas
                  (cod_sucursal, cod_cajero, tipo_cliente, id_cliente_club, nombre_cliente_general, subtotal, total, estado, fecha_hora)
                  VALUES (:sucursal, :cajero, :tipo, :id_cliente, :nombre_gen, :subtotal, :total, 'bloqueada', NOW())";
    
    $stmtHeader = $conn->prepare($sqlHeader);
    $stmtHeader->execute([
        ':sucursal'   => (string)$sucursalCodigo,
        ':cajero'     => $usuarioId,
        ':tipo'       => $tipoCliente,
        ':id_cliente' => ($tipoCliente === 'club') ? $idClienteClub : null,
        ':nombre_gen' => ($tipoCliente === 'general') ? $nombreCliente : null,
        ':subtotal'   => round($subtotal, 2),
        ':total'      => round($subtotal, 2)
    ]);
    $idFactura = (int)$conn->lastInsertId();

    // ---- 2. Insertar en pos_ventas_factura_lineas ----
    $sqlLinea = "INSERT INTO pos_ventas_factura_lineas
                 (id_factura, id_producto_presentacion, nombre_producto_snapshot, cantidad, precio_unitario_snap, precio_unitario_final, precio_linea_total, notas)
                 VALUES (:id_fac, :id_pres, :nombre_snap, :cant, :precio_snap, :precio_final, :precio_total, :notas)";
    $stmtLinea = $conn->prepare($sqlLinea);

    foreach ($detalle as $item) {
        $cantidad       = (float)$item['cantidad'];
        $costoTotalIva  = (float)$item['costo_total_iva'];
        $precioUnitario = ($cantidad > 0) ? round($costoTotalIva / $cantidad, 2) : 0;
        $nombreSnap     = isset($item['nombre']) ? trim($item['nombre']) : 'Producto';
        $notasItem      = !empty($item['notas']) ? trim($item['notas']) : null;

        $stmtLinea->execute([
            ':id_fac'        => $idFactura,
            ':id_pres'       => (int)$item['id_presentacion'],
            ':nombre_snap'   => $nombreSnap,
            ':cant'          => $cantidad,
            ':precio_snap'   => $precioUnitario,
            ':precio_final'  => $precioUnitario,
            ':precio_total'  => $costoTotalIva,
            ':notas'         => $notasItem
        ]);
    }

    // ---- 3. Registrar puntos si es socio Club Pitaya ----
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
