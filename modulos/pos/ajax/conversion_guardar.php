<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
require_once '../../../core/permissions/permissions.php';
session_start();

header('Content-Type: application/json');

try {
    // Verificar sesiÃ³n
    if (!isset($_SESSION['pos_colaborador_id'])) {
        throw new Exception('SesiÃ³n no vÃ¡lida');
    }
    
    $codOperario = $_SESSION['pos_colaborador_id'];
    $cargoOperario = $_SESSION['cargo_cod'];
    
    // Verificar permiso de crear
    if (!tienePermiso('unidades_conversion_productos', 'nuevo_registro', $cargoOperario)) {
        throw new Exception('No tiene permiso para crear conversiones');
    }
    
    $id_unidad_producto_inicio = isset($_POST['id_unidad_producto_inicio']) ? (int)$_POST['id_unidad_producto_inicio'] : 0;
    $id_unidad_producto_final = isset($_POST['id_unidad_producto_final']) ? (int)$_POST['id_unidad_producto_final'] : 0;
    $cantidad = isset($_POST['cantidad']) ? (float)$_POST['cantidad'] : 0;
    
    // Validaciones
    if ($id_unidad_producto_inicio <= 0) {
        throw new Exception('La unidad de inicio es requerida');
    }
    
    if ($id_unidad_producto_final <= 0) {
        throw new Exception('La unidad de salida es requerida');
    }
    
    if ($cantidad <= 0) {
        throw new Exception('La conversiÃ³n tiene que ser positiva');
    }
    
    if ($id_unidad_producto_inicio === $id_unidad_producto_final) {
        throw new Exception('Las unidades de inicio y fin no pueden ser iguales');
    }
    
    // Verificar que ambas unidades existan
    $sqlCheck = "SELECT COUNT(*) as total FROM unidad_producto 
                 WHERE id IN (:id1, :id2)";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindValue(':id1', $id_unidad_producto_inicio, PDO::PARAM_INT);
    $stmtCheck->bindValue(':id2', $id_unidad_producto_final, PDO::PARAM_INT);
    $stmtCheck->execute();
    
    if ($stmtCheck->fetch()['total'] != 2) {
        throw new Exception('Una o ambas unidades no existen');
    }
    
    // Verificar que no exista ya esta conversiÃ³n
    $sqlCheckConversion = "SELECT COUNT(*) as total FROM conversion_unidad_producto 
                          WHERE id_unidad_producto_inicio = :id_inicio 
                          AND id_unidad_producto_final = :id_final";
    $stmtCheckConversion = $conn->prepare($sqlCheckConversion);
    $stmtCheckConversion->bindValue(':id_inicio', $id_unidad_producto_inicio, PDO::PARAM_INT);
    $stmtCheckConversion->bindValue(':id_final', $id_unidad_producto_final, PDO::PARAM_INT);
    $stmtCheckConversion->execute();
    
    if ($stmtCheckConversion->fetch()['total'] > 0) {
        throw new Exception('Ya existe una conversiÃ³n entre estas unidades');
    }
    
    // Insertar conversiÃ³n
    $sql = "INSERT INTO conversion_unidad_producto 
            (id_unidad_producto_inicio, id_unidad_producto_final, cantidad, usuario_creacion) 
            VALUES (:id_inicio, :id_final, :cantidad, :usuario_creacion)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_inicio', $id_unidad_producto_inicio, PDO::PARAM_INT);
    $stmt->bindValue(':id_final', $id_unidad_producto_final, PDO::PARAM_INT);
    $stmt->bindValue(':cantidad', $cantidad);
    $stmt->bindValue(':usuario_creacion', $codOperario, PDO::PARAM_INT);
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Nueva conversiÃ³n registrada exitosamente'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}