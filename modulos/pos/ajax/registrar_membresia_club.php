<?php
/**
 * registrar_membresia_club.php
 * Acciones: verificar | registrar | eliminar | notificar_wsp
 *
 * Token de preregistro: HMAC-SHA256 firmado — no requiere columna adicional en BD.
 * El token se genera on-the-fly a partir del número de membresía + clave secreta compartida.
 *
 * POST /modulos/pos/ajax/registrar_membresia_club.php
 */
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
require_once '../../../core/config/club_token_secret.php';   // clubTokenGenerar()

header('Content-Type: application/json');

$accion    = isset($_POST['accion']) ? trim($_POST['accion']) : '';
$membresia = isset($_POST['membresia']) ? (int)$_POST['membresia'] : 0;
$sucursal  = $_SESSION['pos_store_sucursal'] ?? ($_SESSION['pos_device_sucursal'] ?? null);

if (!$membresia || $membresia <= 0) {
    echo json_encode(['success' => false, 'mensaje' => 'Número de membresía inválido.']);
    exit;
}

// ─── ACCIÓN: verificar ────────────────────────────────────────────────────────
if ($accion === 'verificar') {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM clientesclub WHERE membresia = :m");
        $stmt->execute([':m' => $membresia]);
        $existe = (int)$stmt->fetchColumn() > 0;
        echo json_encode([
            'disponible' => !$existe,
            'mensaje'    => $existe ? "La membresía #{$membresia} ya está registrada." : null
        ]);
    } catch (Exception $e) {
        echo json_encode(['disponible' => false, 'mensaje' => 'Error al verificar: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ACCIÓN: registrar ────────────────────────────────────────────────────────
if ($accion === 'registrar') {
    $celular = isset($_POST['celular']) ? preg_replace('/\D/', '', $_POST['celular']) : '';
    $celular = substr($celular, 0, 8); // solo 8 dígitos Nicaragua

    if (!$sucursal) {
        echo json_encode(['success' => false, 'mensaje' => 'No se pudo determinar la sucursal activa.']);
        exit;
    }
    if (strlen($celular) < 8) {
        echo json_encode(['success' => false, 'mensaje' => 'El celular debe tener 8 dígitos.']);
        exit;
    }

    try {
        // Doble check de unicidad antes de insertar
        $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM clientesclub WHERE membresia = :m");
        $stmtCheck->execute([':m' => $membresia]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'mensaje' => "La membresía #{$membresia} ya fue registrada."]);
            exit;
        }

        // Nombre de sucursal desde BD
        $stmtSuc = $conn->prepare("SELECT nombre FROM sucursales WHERE codigo = :cod LIMIT 1");
        $stmtSuc->execute([':cod' => (string)$sucursal]);
        $rowSuc         = $stmtSuc->fetch(PDO::FETCH_ASSOC);
        $nombreSucursal = $rowSuc ? $rowSuc['nombre'] : 'Sucursal ' . $sucursal;

        // Precio de membresía desde pos_menu_precios (catálogo 13 = Membresía)
        $precioMembresia = 99.00;
        try {
            $stmtPrecio = $conn->prepare(
                "SELECT precio FROM pos_menu_precios
                 WHERE id_catalogo_menu_pos = 13
                   AND (cod_sucursal = :suc OR cod_sucursal IS NULL)
                   AND fecha_desde <= CURDATE()
                   AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
                 ORDER BY cod_sucursal DESC LIMIT 1"
            );
            $stmtPrecio->execute([':suc' => (string)$sucursal]);
            $rowPrecio = $stmtPrecio->fetch(PDO::FETCH_ASSOC);
            if ($rowPrecio) {
                $precioMembresia = (float)$rowPrecio['precio'];
            }
        } catch (Exception $ePrecio) {
            // Fallback al precio C$99 si falla la consulta
        }

        // Insertar nuevo socio — SIN columna token_preregistro
        $stmt = $conn->prepare(
            "INSERT INTO clientesclub
             (sucursal, membresia, celular, fecha_registro, puntos_iniciales, nombre_sucursal)
             VALUES (:suc, :mem, :cel, CURDATE(), 0, :nom_suc)"
        );
        $stmt->execute([
            ':suc'     => (int)$sucursal,
            ':mem'     => $membresia,
            ':cel'     => $celular,
            ':nom_suc' => $nombreSucursal,
        ]);
        $idNuevo = (int)$conn->lastInsertId();

        // Token HMAC firmado — generado on-the-fly, sin guardar en BD
        $token = clubTokenGenerar($membresia);

        echo json_encode([
            'success'          => true,
            'id_clienteclub'   => $idNuevo,
            'membresia'        => $membresia,
            'precio_membresia' => $precioMembresia,
            'token'            => $token        // solo para debug; el WSP lo recalcula
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'mensaje' => 'Error al registrar: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ACCIÓN: eliminar ─────────────────────────────────────────────────────────
// Solo permite eliminar socios sin nombre (recién creados, sin preregistro completado)
if ($accion === 'eliminar') {
    try {
        $stmt = $conn->prepare(
            "DELETE FROM clientesclub
             WHERE membresia = :m
               AND (nombre IS NULL OR nombre = '')
             LIMIT 1"
        );
        $stmt->execute([':m' => $membresia]);
        $eliminados = $stmt->rowCount();
        echo json_encode(['success' => true, 'eliminados' => $eliminados]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'mensaje' => $e->getMessage()]);
    }
    exit;
}

// ─── ACCIÓN: notificar_wsp ────────────────────────────────────────────────────
if ($accion === 'notificar_wsp') {
    $celular = isset($_POST['celular']) ? preg_replace('/\D/', '', $_POST['celular']) : '';
    $celular = substr($celular, 0, 8);

    if (strlen($celular) < 8) {
        echo json_encode(['success' => false, 'mensaje' => 'Celular inválido para notificación WSP.']);
        exit;
    }

    try {
        // Nombre de sucursal (para personalizar el mensaje)
        $stmtSuc = $conn->prepare("SELECT nombre_sucursal FROM clientesclub WHERE membresia = :m LIMIT 1");
        $stmtSuc->execute([':m' => $membresia]);
        $rowSuc      = $stmtSuc->fetch(PDO::FETCH_ASSOC);
        $nomSucursal = $rowSuc ? ($rowSuc['nombre_sucursal'] ?? '') : '';

        // Token HMAC on-the-fly — mismo resultado cada vez que se llame con la misma membresía
        $token = clubTokenGenerar($membresia);

        // Formato internacional Nicaragua
        $celularWsp = '505' . $celular;

        // URL segura del preregistro (sin columna en BD)
        $urlPreregistro = 'https://club.batidospitaya.com/preregistro/?t=' . urlencode($token);

        $mensaje  = "🌿 *Club Pitaya*\n\n";
        $mensaje .= "¡Bienvenido! 🎉\n\n";
        $mensaje .= "Tu membresía *#{$membresia}* ha sido activada.\n\n";
        $mensaje .= "📝 Completa tu perfil para acceder a todos los beneficios:\n";
        $mensaje .= $urlPreregistro . "\n\n";
        if ($nomSucursal) {
            $mensaje .= "📍 {$nomSucursal}\n";
        }
        $mensaje .= "📅 " . date('d/m/Y') . "\n\n";
        $mensaje .= "¡Seguimos recargando tu día! 💚";

        $stmtIns = $conn->prepare(
            "INSERT INTO wsp_notificaciones_clientesclub_pendientes_
             (celular, mensaje, estado, instancia)
             VALUES (:cel, :msg, 'pendiente', 'wsp-clientes')"
        );
        $stmtIns->execute([':cel' => $celularWsp, ':msg' => $mensaje]);

        echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'mensaje' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'mensaje' => 'Acción no reconocida.']);
