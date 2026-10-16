<?php
// cliente_club_buscar.php — Búsqueda de socio Club Pitaya y cálculo de puntos acumulados
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $membresia = isset($_POST['membresia']) ? trim($_POST['membresia']) : (isset($_GET['membresia']) ? trim($_GET['membresia']) : '');

    if (empty($membresia)) {
        throw new Exception('Debe ingresar un número de membresía.');
    }

    // 1. Buscar datos del cliente
    $sqlCliente = "SELECT id_clienteclub, membresia, nombre, apellido, nombre_sucursal, puntos_iniciales, fecha_registro
                   FROM clientesclub 
                   WHERE membresia = :membresia 
                   LIMIT 1";
    $stmtCliente = $conn->prepare($sqlCliente);
    $stmtCliente->execute([':membresia' => $membresia]);
    $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        throw new Exception("Membresía '{$membresia}' no encontrada en el sistema.");
    }

    $puntosIniciales = floatval($cliente['puntos_iniciales'] ?? 0);

    // 2. Calcular puntos del historial Access (excluyendo grupos 25 y 11 y anulados)
    $sqlTotalPuntos = "SELECT COALESCE(SUM(
                            CASE 
                                WHEN VentasGlobalesAccessCSV.Anulado = 0 THEN (VentasGlobalesAccessCSV.Cantidad * VentasGlobalesAccessCSV.Puntos)
                                ELSE 0 
                            END
                        ), 0) as total_puntos
                       FROM VentasGlobalesAccessCSV
                       LEFT JOIN DBBatidos ON VentasGlobalesAccessCSV.CodProducto = DBBatidos.CodBatido
                       WHERE VentasGlobalesAccessCSV.CodCliente = :membresia
                       AND (DBBatidos.CodGrupo IS NULL OR (DBBatidos.CodGrupo != 25 AND DBBatidos.CodGrupo != 11))";
    $stmtTotalPuntos = $conn->prepare($sqlTotalPuntos);
    $stmtTotalPuntos->execute([':membresia' => $membresia]);
    $puntosAccess = floatval($stmtTotalPuntos->fetch(PDO::FETCH_ASSOC)['total_puntos'] ?? 0);

    // 3. Calcular puntos del nuevo POS (pos_ventas_factura_puntos_club) si la tabla existe
    $puntosNuevos = 0;
    try {
        $stmtPuntosNuevos = $conn->prepare("
            SELECT COALESCE(SUM(pfpc.puntos_ganados) - SUM(pfpc.puntos_canjeados), 0) as saldo_nuevo
            FROM pos_ventas_factura_puntos_club pfpc
            JOIN pos_ventas_facturas pf ON pf.id = pfpc.id_factura
            WHERE pfpc.id_cliente_club = :id_cliente AND pf.estado = 'bloqueada'
        ");
        $stmtPuntosNuevos->execute([':id_cliente' => $cliente['id_clienteclub']]);
        $resNuevo = $stmtPuntosNuevos->fetch(PDO::FETCH_ASSOC);
        if ($resNuevo) {
            $puntosNuevos = floatval($resNuevo['saldo_nuevo'] ?? 0);
        }
    } catch (Exception $e) {
        // Degradación suave si la tabla aún no se ha creado en el entorno
        $puntosNuevos = 0;
    }

    $totalPuntos = round($puntosIniciales + $puntosAccess + $puntosNuevos, 2);
    $nombreCompleto = trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido'] ?? ''));

    echo json_encode([
        'success' => true,
        'cliente' => [
            'id_clienteclub'    => (int)$cliente['id_clienteclub'],
            'membresia'         => $cliente['membresia'],
            'nombre'            => $cliente['nombre'] ?? '',
            'apellido'          => $cliente['apellido'] ?? '',
            'nombre_completo'   => $nombreCompleto,
            'nombre_sucursal'   => $cliente['nombre_sucursal'] ?? '',
            'fecha_registro'    => $cliente['fecha_registro'] ?? null,
            'puntos_anteriores' => $totalPuntos
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
