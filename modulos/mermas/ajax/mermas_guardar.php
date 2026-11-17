<?php
// mermas_guardar.php
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['pos_colaborador_id'])) throw new Exception('No autorizado.');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) throw new Exception('Datos inválidos.');

    $idTipo      = (int)($input['id_tipo_incidencia'] ?? 0);
    $observacion = trim($input['observacion'] ?? '');
    $lineas      = $input['lineas'] ?? [];
    $codSucursal = $_SESSION['pos_store_sucursal'] ?? '';
    $idOperario  = (int)$_SESSION['pos_colaborador_id'];

    // Validaciones
    if ($idTipo <= 0)      throw new Exception('Debe seleccionar un tipo de incidencia.');
    if (empty($lineas))    throw new Exception('Debe agregar al menos un producto.');
    if ($codSucursal === '') throw new Exception('No se pudo determinar la sucursal de la sesión.');

    // Verificar que el tipo existe y está activo
    $stmtTipo = $conn->prepare("SELECT id FROM mermas_tipos_incidencia WHERE id = :id AND activo = 1 LIMIT 1");
    $stmtTipo->execute([':id' => $idTipo]);
    if (!$stmtTipo->fetch()) throw new Exception('El tipo de incidencia seleccionado no es válido.');

    // Validar líneas
    foreach ($lineas as $i => $linea) {
        $idPres   = (int)($linea['id_producto_presentacion'] ?? 0);
        $cantidad = floatval($linea['cantidad'] ?? 0);
        if ($idPres <= 0)   throw new Exception("Línea " . ($i+1) . ": presentación de producto inválida.");
        if ($cantidad <= 0) throw new Exception("Línea " . ($i+1) . ": la cantidad debe ser mayor a 0.");
    }

    // INSERT una fila por cada producto (tabla flat — sin cabecera/detalle)
    $sql = "INSERT INTO mermas_productos
                (id_producto_presentacion, cantidad, id_tipo_incidencia,
                 cod_sucursal, fotos_evidencia, observacion,
                 estado, id_operario_registro)
            VALUES
                (:pres, :qty, :tipo,
                 :suc, '', :obs,
                 'creado', :op)";

    $stmt = $conn->prepare($sql);
    $insertados = 0;

    $conn->beginTransaction();

    foreach ($lineas as $linea) {
        $stmt->execute([
            ':pres' => (int)$linea['id_producto_presentacion'],
            ':qty'  => floatval($linea['cantidad']),
            ':tipo' => $idTipo,
            ':suc'  => $codSucursal,
            ':obs'  => $observacion !== '' ? $observacion : null,
            ':op'   => $idOperario,
        ]);
        $insertados++;
    }

    $conn->commit();

    $msg = $insertados === 1
        ? '1 producto registrado como merma correctamente.'
        : "$insertados productos registrados como merma correctamente.";

    echo json_encode(['success' => true, 'message' => $msg, 'insertados' => $insertados]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
