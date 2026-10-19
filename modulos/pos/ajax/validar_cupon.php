<?php
/**
 * validar_cupon.php — Validación de Cupones en el POS (Modo Pruebas / Solo Lectura)
 *
 * Busca y valida un cupón en `cupones_sucursales` para su aplicación como método de pago.
 * 
 * =====================================================================================
 * NOTA PARA DESARROLLADORES / PASE A PRODUCCIÓN:
 * Actualmente este endpoint opera en MODO PRUEBAS:
 * - Solo consulta la existencia, vigencia y monto del cupón (SELECT puro).
 * - NO altera el campo `aplicado = 1` en la tabla `cupones_sucursales`.
 * - Cuando el sistema POS se lance oficialmente a producción, la actualización a `aplicado = 1`
 *   y el registro del `cod_pedido` / `hora_activacion` debe activarse dentro de la transacción
 *   de guardado final en `modulos/pos/ajax/facturas_guardar.php`.
 * =====================================================================================
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método de solicitud no permitido.');
    }

    $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : '';

    if (empty($codigo)) {
        throw new Exception('Por favor ingresa un código de cupón.');
    }

    $sucursalActual = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? null);
    $hoy = date('Y-m-d');

    // Consultar el cupón por su código
    $stmt = $conn->prepare("
        SELECT 
            id, 
            numero_cupon, 
            monto, 
            fecha_caducidad, 
            fecha_registro, 
            aplicado, 
            cod_sucursal, 
            observaciones
        FROM cupones_sucursales
        WHERE TRIM(numero_cupon) = :codigo
        LIMIT 1
    ");
    $stmt->execute([':codigo' => $codigo]);
    $cupon = $stmt->fetch();

    if (!$cupon) {
        throw new Exception("El cupón \"{$codigo}\" no fue encontrado en el sistema.");
    }

    // 1. Validar fecha de caducidad
    if (!empty($cupon['fecha_caducidad']) && $cupon['fecha_caducidad'] < $hoy) {
        $fechaVenc = date('d/m/Y', strtotime($cupon['fecha_caducidad']));
        throw new Exception("El cupón \"{$codigo}\" caducó el {$fechaVenc}.");
    }

    // 2. Validar sucursal si el cupón está restringido a una específica
    if (!empty($cupon['cod_sucursal']) && $sucursalActual !== null && (int)$cupon['cod_sucursal'] !== (int)$sucursalActual) {
        throw new Exception("Este cupón solo es válido en su sucursal de emisión asignada.");
    }

    // 3. Verificación de uso previo
    // NOTA DE PRUEBAS: Si ya fue aplicado en BD real, advertimos al usuario pero permitimos la prueba
    $yaAplicadoEnBD = (int)($cupon['aplicado'] ?? 0) === 1;

    $montoCupon = (float)($cupon['monto'] ?? 0);
    if ($montoCupon <= 0) {
        throw new Exception("El cupón no cuenta con un monto de descuento válido.");
    }

    echo json_encode([
        'success' => true,
        'message' => $yaAplicadoEnBD 
            ? 'Cupón válido para pruebas (Aviso: figura como aplicado en BD real).'
            : 'Cupón verificado con éxito.',
        'data' => [
            'id_cupon'        => (int)$cupon['id'],
            'numero_cupon'    => $cupon['numero_cupon'],
            'monto'           => $montoCupon,
            'fecha_caducidad' => $cupon['fecha_caducidad'],
            'observaciones'   => $cupon['observaciones'] ?? '',
            'modo_prueba'     => true,
            'ya_aplicado'     => $yaAplicadoEnBD
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
