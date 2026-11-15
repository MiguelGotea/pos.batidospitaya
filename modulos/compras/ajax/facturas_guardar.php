<?php
// facturas_guardar.php
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['pos_colaborador_id'])) {
        throw new Exception('No autorizado');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Datos inválidos.');
    }

    $numeroFactura  = trim($input['numero_factura'] ?? '');
    $fecha          = trim($input['fecha'] ?? '');
    $idProveedor    = (int)($input['id_proveedor'] ?? 0);
    $notas          = trim($input['notas'] ?? '');
    $detalle        = $input['detalle'] ?? [];
    $registradoPor  = $_SESSION['pos_colaborador_id'];

    // Validaciones básicas
    if ($numeroFactura === '') throw new Exception('El número de factura es requerido.');
    if ($fecha === '')         throw new Exception('La fecha es requerida.');
    if ($idProveedor <= 0)    throw new Exception('Debe seleccionar un proveedor.');
    if (empty($detalle))      throw new Exception('Debe agregar al menos un producto.');

    // Verificar que el número de factura no esté duplicado
    $stmtDup = $conn->prepare("SELECT id FROM pos_facturas WHERE numero_factura = :nf LIMIT 1");
    $stmtDup->execute([':nf' => $numeroFactura]);
    if ($stmtDup->fetch()) {
        throw new Exception("El número de factura \"$numeroFactura\" ya está registrado.");
    }

    // Calcular total
    $totalFactura = 0;
    foreach ($detalle as $item) {
        $cantidad     = floatval($item['cantidad']     ?? 0);
        $costoConIva  = floatval($item['costo_con_iva'] ?? 0);
        if ($cantidad <= 0)   throw new Exception('Todas las cantidades deben ser mayores a 0.');
        if ($costoConIva < 0) throw new Exception('El costo con IVA no puede ser negativo.');
        $totalFactura += $cantidad * $costoConIva;
    }

    $conn->beginTransaction();

    // Insertar cabecera
    $sqlCab = "INSERT INTO pos_facturas
                   (numero_factura, fecha, id_proveedor, total_factura, estado, notas, registrado_por, fecha_hora_regsys)
               VALUES
                   (:nf, :fecha, :prov, :total, 'activa', :notas, :reg, NOW())";
    $stmtCab = $conn->prepare($sqlCab);
    $stmtCab->execute([
        ':nf'    => $numeroFactura,
        ':fecha' => $fecha,
        ':prov'  => $idProveedor,
        ':total' => round($totalFactura, 2),
        ':notas' => $notas !== '' ? $notas : null,
        ':reg'   => $registradoPor,
    ]);
    $idFactura = (int)$conn->lastInsertId();

    // Insertar líneas de detalle
    $sqlDet = "INSERT INTO pos_facturas_detalle
                   (id_factura, id_presentacion, cantidad, costo_total_iva, costo_unitario)
               VALUES
                   (:fac, :pres, :qty, :civa, :cunit)";
    $stmtDet = $conn->prepare($sqlDet);

    foreach ($detalle as $item) {
        $cantidad    = floatval($item['cantidad']     ?? 0);
        $costoConIva = floatval($item['costo_con_iva'] ?? 0);
        $idPres      = (int)($item['id_presentacion'] ?? 0);
        if ($idPres <= 0) throw new Exception('Presentación de producto inválida.');

        // costo_unitario = costo sin IVA = costo_con_iva / 1.15  (IVA 15%)
        $costoUnitario = $costoConIva > 0 ? round($costoConIva / 1.15, 4) : 0;

        $stmtDet->execute([
            ':fac'   => $idFactura,
            ':pres'  => $idPres,
            ':qty'   => $cantidad,
            ':civa'  => round($costoConIva, 4),
            ':cunit' => $costoUnitario,
        ]);
    }

    $conn->commit();

    echo json_encode([
        'success'    => true,
        'id_factura' => $idFactura,
        'message'    => 'Factura guardada correctamente.',
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
?>
