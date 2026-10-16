<?php
// cliente_historial_productos.php — Historial de productos y puntos para el Modal de Clientes Club
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    $membresia = isset($_POST['membresia']) ? trim($_POST['membresia']) : (isset($_GET['membresia']) ? trim($_GET['membresia']) : '');
    $pagina = isset($_POST['pagina']) ? max(1, (int)$_POST['pagina']) : (isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1);
    $registrosPorPagina = isset($_POST['limite']) ? max(5, (int)$_POST['limite']) : (isset($_GET['limite']) ? max(5, (int)$_GET['limite']) : 15);

    if (empty($membresia)) {
        throw new Exception('Membresía no especificada.');
    }

    $offset = ($pagina - 1) * $registrosPorPagina;

    // 1. Obtener datos del cliente
    $sqlCliente = "SELECT id_clienteclub, nombre, apellido, nombre_sucursal, puntos_iniciales, fecha_registro
                   FROM clientesclub 
                   WHERE membresia = :membresia 
                   LIMIT 1";
    $stmtCliente = $conn->prepare($sqlCliente);
    $stmtCliente->execute([':membresia' => $membresia]);
    $cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        throw new Exception("Cliente no encontrado.");
    }

    $puntosIniciales = floatval($cliente['puntos_iniciales'] ?? 0);

    // 2. Contar total de registros (excluyendo grupos 25 y 11)
    $sqlCount = "SELECT COUNT(*) as total 
                 FROM VentasGlobalesAccessCSV
                 LEFT JOIN DBBatidos ON VentasGlobalesAccessCSV.CodProducto = DBBatidos.CodBatido
                 WHERE VentasGlobalesAccessCSV.CodCliente = :membresia
                 AND (DBBatidos.CodGrupo IS NULL OR (DBBatidos.CodGrupo != 25 AND DBBatidos.CodGrupo != 11))";
    $stmtCount = $conn->prepare($sqlCount);
    $stmtCount->execute([':membresia' => $membresia]);
    $totalRegistros = (int)($stmtCount->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    // 3. Calcular el total histórico de puntos para el cliente (Access)
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
    $totalPuntosHistorial = floatval($stmtTotalPuntos->fetch(PDO::FETCH_ASSOC)['total_puntos'] ?? 0) + $puntosIniciales;

    // Sumar puntos del nuevo POS (pos_ventas_factura_puntos_club) si existen
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
            $totalPuntosHistorial += floatval($resNuevo['saldo_nuevo'] ?? 0);
        }
    } catch (Exception $e) {
        // Tabla de puntos nuevos aún no creada o vacía
    }

    // 4. Calcular los puntos de las páginas anteriores (para el orden descendente)
    $puntosAnteriores = 0;
    if ($offset > 0) {
        $sqlPuntosAnteriores = "SELECT COALESCE(SUM(
                                    CASE 
                                        WHEN Anulado = 0 THEN (Cantidad * Puntos)
                                        ELSE 0 
                                    END
                                ), 0) as puntos_anteriores
                                FROM (
                                    SELECT VentasGlobalesAccessCSV.Cantidad, VentasGlobalesAccessCSV.Puntos, VentasGlobalesAccessCSV.Anulado
                                    FROM VentasGlobalesAccessCSV
                                    LEFT JOIN DBBatidos ON VentasGlobalesAccessCSV.CodProducto = DBBatidos.CodBatido
                                    WHERE VentasGlobalesAccessCSV.CodCliente = :membresia
                                    AND (DBBatidos.CodGrupo IS NULL OR (DBBatidos.CodGrupo != 25 AND DBBatidos.CodGrupo != 11))
                                    ORDER BY VentasGlobalesAccessCSV.Fecha DESC, VentasGlobalesAccessCSV.Hora DESC
                                    LIMIT :offset
                                ) as subquery";
        $stmtPuntosAnteriores = $conn->prepare($sqlPuntosAnteriores);
        $stmtPuntosAnteriores->bindValue(':membresia', $membresia);
        $stmtPuntosAnteriores->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtPuntosAnteriores->execute();
        $puntosAnteriores = floatval($stmtPuntosAnteriores->fetch(PDO::FETCH_ASSOC)['puntos_anteriores'] ?? 0);
    }

    // 5. Obtener los registros de la página actual
    $sql = "SELECT 
                VentasGlobalesAccessCSV.Sucursal_Nombre,
                VentasGlobalesAccessCSV.Fecha,
                VentasGlobalesAccessCSV.Hora,
                VentasGlobalesAccessCSV.DBBatidos_Nombre,
                VentasGlobalesAccessCSV.Medida,
                VentasGlobalesAccessCSV.Cantidad,
                VentasGlobalesAccessCSV.Puntos,
                VentasGlobalesAccessCSV.Anulado,
                promociones_access_csv.Nombre as NombrePromocion,
                CASE 
                    WHEN VentasGlobalesAccessCSV.Anulado = 0 THEN (VentasGlobalesAccessCSV.Cantidad * VentasGlobalesAccessCSV.Puntos)
                    ELSE 0 
                END as PuntosTotales
            FROM VentasGlobalesAccessCSV
            LEFT JOIN DBBatidos ON VentasGlobalesAccessCSV.CodProducto = DBBatidos.CodBatido
            LEFT JOIN promociones_access_csv ON VentasGlobalesAccessCSV.CodigoPromocion = promociones_access_csv.CodPromocion
            WHERE VentasGlobalesAccessCSV.CodCliente = :membresia
            AND (DBBatidos.CodGrupo IS NULL OR (DBBatidos.CodGrupo != 25 AND DBBatidos.CodGrupo != 11))
            ORDER BY VentasGlobalesAccessCSV.Fecha DESC, VentasGlobalesAccessCSV.Hora DESC
            LIMIT :offset, :limit";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':membresia', $membresia);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $registrosPorPagina, PDO::PARAM_INT);
    $stmt->execute();
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Formatear las 6 columnas solicitadas y calcular saldo cronológico
    $puntosAcumulados = $totalPuntosHistorial - $puntosAnteriores;
    $datosFormateados = [];

    foreach ($filas as $row) {
        // A) Sucursal
        $sucursal = !empty($row['Sucursal_Nombre']) ? $row['Sucursal_Nombre'] : 'Central';

        // B) Fecha / Hora unificado
        $fechaFmt = !empty($row['Fecha']) ? date('d/m/Y', strtotime($row['Fecha'])) : '-';
        $horaFmt = !empty($row['Hora']) ? date('h:i A', strtotime($row['Hora'])) : '';
        $fechaHora = trim($fechaFmt . ($horaFmt ? ' · ' . $horaFmt : ''));

        // C) Producto / Medida unificado
        $prodNombre = !empty($row['DBBatidos_Nombre']) ? trim($row['DBBatidos_Nombre']) : 'Producto';
        $medida = !empty($row['Medida']) ? trim($row['Medida']) : '';
        $productoMedida = $medida ? ($prodNombre . ' · ' . $medida) : $prodNombre;

        // D) Cantidad
        $cantidad = (float)($row['Cantidad'] ?? 0);

        // E) Promoción
        $promocion = !empty($row['NombrePromocion']) ? trim($row['NombrePromocion']) : '—';

        // F) Puntos Acumulados
        $puntosFila = round($puntosAcumulados, 2);
        $puntosAcumulados -= floatval($row['PuntosTotales']);

        $datosFormateados[] = [
            'sucursal'          => $sucursal,
            'fecha_hora'        => $fechaHora,
            'producto_medida'   => $productoMedida,
            'cantidad'          => $cantidad,
            'promocion'         => $promocion,
            'puntos_acumulados' => $puntosFila,
            'es_anulado'        => (int)($row['Anulado'] ?? 0) !== 0
        ];
    }

    $nombreCompleto = trim(($cliente['nombre'] ?? '') . ' ' . ($cliente['apellido'] ?? ''));

    echo json_encode([
        'success'         => true,
        'cliente'         => [
            'nombre_completo'   => $nombreCompleto,
            'membresia'         => $cliente['membresia'],
            'sucursal_registro' => $cliente['nombre_sucursal'] ?? '',
            'puntos_totales'    => round($totalPuntosHistorial, 2)
        ],
        'total_registros' => $totalRegistros,
        'pagina_actual'   => $pagina,
        'total_paginas'   => ceil($totalRegistros / $registrosPorPagina),
        'datos'           => $datosFormateados
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
