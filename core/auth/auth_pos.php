<?php
/**
 * POS Authentication Framework - auth_pos.php
 * Batidos Pitaya | Punto de Venta
 *
 * Maneja la autenticación en dos etapas:
 *   Etapa 1: Usuario de Sucursal (CodNivelesCargos = 27) desbloquea la terminal
 *   Etapa 2: Colaborador ingresa su clave para operar, validando marcación activa
 *
 * Seguridad adicional: Solo dispositivos autorizados mediante erp_device_token
 */

// ---------------------------------------------------------------------------
// ⚠️  MODO DESARROLLO — Cambiar a false en producción
// ---------------------------------------------------------------------------
define('POS_DEV_MODE', true);   // true = bypass de cookie activo, false para producción
define('POS_DEV_SUCURSAL_COD', 5);      // Código de sucursal a simular
define('POS_DEV_SUCURSAL_NOMBRE', 'Sucursal Test (DEV)'); // Nombre descriptivo
// ---------------------------------------------------------------------------

// Gestor centralizado de sesiones (8 horas y corte a Medianoche UTC-6)
require_once __DIR__ . '/session_manager.php';
iniciarSesionSegura();

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/helpers/funciones.php';

// ---------------------------------------------------------------------------
// HELPERS DE VERIFICACIÓN DE ESTADO
// ---------------------------------------------------------------------------

/**
 * Verifica si el dispositivo está autorizado para el POS.
 * Reutiliza la lógica del ERP: cookie erp_device_token vs sucursales.cookie_token.
 * 
 * Retorna: ['status' => bool, 'msg' => string, 'sucursal_codigo' => int|null]
 */
function posVerificarDispositivo()
{
    global $conn;

    // ===========================================================
    // 🛠️  BYPASS DE DESARROLLO — Se activa con POS_DEV_MODE=true
    // ===========================================================
    if (defined('POS_DEV_MODE') && POS_DEV_MODE === true) {
        // Poblar sesión automáticamente como si el dispositivo fuera válido
        if (!isset($_SESSION['pos_store_sucursal']) || $_SESSION['pos_store_sucursal'] != POS_DEV_SUCURSAL_COD) {
            $_SESSION['pos_device_sucursal'] = POS_DEV_SUCURSAL_COD;
            $_SESSION['pos_store_sucursal'] = POS_DEV_SUCURSAL_COD;
            $_SESSION['pos_store_sucursal_nombre'] = POS_DEV_SUCURSAL_NOMBRE;
            $_SESSION['pos_store_usuario'] = 'DEV-Terminal';
            $_SESSION['pos_store_id'] = 0;
            $_SESSION['usuario_id'] = 0;
            $_SESSION['usuario_rol'] = 'sucursal';
            $_SESSION['cargo_cod'] = 27;
        }
        return [
            'status' => true,
            'msg' => '',
            'sucursal_codigo' => POS_DEV_SUCURSAL_COD,
            'sucursal_nombre' => POS_DEV_SUCURSAL_NOMBRE,
        ];
    }
    // ===========================================================

    // 1. Verificar Navegador (Chrome/Edge)
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $esChromium = (strpos($ua, 'Chrome') !== false || strpos($ua, 'Edg') !== false);
    if (!$esChromium) {
        return [
            'status' => false,
            'msg' => 'Navegador no permitido. Usa Google Chrome o Microsoft Edge.',
            'sucursal_codigo' => null,
        ];
    }

    // 2. Verificar cookie exclusiva del POS (pos_device_token)
    $tokenCookie = $_COOKIE['pos_device_token'] ?? null;
    if (empty($tokenCookie)) {
        return [
            'status' => false,
            'msg' => 'Este dispositivo no cuenta con una autorización activa. Por favor, contacta al área de TI para configurar este dispositivo.',
            'sucursal_codigo' => null,
        ];
    }

    // 3. Buscar el token y el nombre de la sucursal
    try {
        $stmt = $conn->prepare("SELECT codigo, nombre FROM sucursales WHERE pos_cookie_token = ? AND activa = 1 LIMIT 1");
        $stmt->execute([$tokenCookie]);
        $sucursal = $stmt->fetch();

        if (!$sucursal) {
            return [
                'status' => false,
                'msg' => 'La autorización de este dispositivo ya no es válida o la sucursal ha sido desactivada. Por favor, contacta al área de TI.',
                'sucursal_codigo' => null,
            ];
        }

        // --- AUTO-LOGIN DE SUCURSAL ---
        // Si el dispositivo es válido, poblamos la sesión de tienda automáticamente
        // para obviar el login manual de Nivel 1.
        if (!isset($_SESSION['pos_store_sucursal']) || $_SESSION['pos_store_sucursal'] != $sucursal['codigo']) {
            $_SESSION['pos_device_sucursal'] = $sucursal['codigo'];
            $_SESSION['pos_store_sucursal'] = $sucursal['codigo'];
            $_SESSION['pos_store_sucursal_nombre'] = $sucursal['nombre'];
            $_SESSION['pos_store_usuario'] = 'Terminal Autorizada';
            $_SESSION['pos_store_id'] = 0; // ID virtual para auto-login

            // Compatibilidad legacy para módulos ERP
            $_SESSION['usuario_id'] = 0;
            $_SESSION['usuario_rol'] = 'sucursal';
            $_SESSION['cargo_cod'] = 27;
        }

        return [
            'status' => true,
            'msg' => '',
            'sucursal_codigo' => $sucursal['codigo'],
            'sucursal_nombre' => $sucursal['nombre']
        ];

    } catch (Exception $e) {
        error_log("POS - Error validando dispositivo: " . $e->getMessage());
        return [
            'status' => false,
            'msg' => 'Error de sistema al validar el dispositivo POS.',
            'sucursal_codigo' => null,
        ];
    }
}

/**
 * Retorna true si hay una sesión de tienda activa (Etapa 1 completada).
 */
function posTiendaAutenticada()
{
    if (isset($_SESSION['pos_store_id']) && isset($_SESSION['pos_store_sucursal'])) {
        return true;
    }

    // Si no hay sesión, intentamos auto-login mediante el token del dispositivo
    $check = posVerificarDispositivo();
    return $check['status'];
}

/**
 * Retorna true si hay un colaborador activo en sesión (Etapa 2 completada).
 */
function posColaboradorAutenticado()
{
    return isset($_SESSION['pos_colaborador_id']);
}

/**
 * Middleware: redirige si la tienda no está autenticada.
 */
function posRequiereAutenticacionTienda()
{
    if (!posTiendaAutenticada()) {
        redirigirAlLogin();
    }
}

/**
 * Middleware: redirige si el colaborador no está autenticado.
 */
function posRequiereColaborador()
{
    posRequiereAutenticacionTienda();
    if (!posColaboradorAutenticado()) {
        header('Location: /index.php');
        exit();
    }
    $_SESSION['last_activity'] = time();
}

// ---------------------------------------------------------------------------
// ETAPA 1: INICIO DE SESIÓN DE TIENDA (Usuario CodNivelesCargos = 27)
// ---------------------------------------------------------------------------

/**
 * Valida las credenciales del usuario de sucursal.
 *
 * @param string $usuario
 * @param string $clave   Contraseña en texto plano
 * @return array ['status' => bool, 'msg' => string]
 */
function posValidarLoginTienda($usuario, $clave)
{
    if (empty($usuario) || empty($clave)) {
        return ['status' => false, 'msg' => 'Completa usuario y contraseña.'];
    }

    $stmt = ejecutarConsulta("
        SELECT
            o.CodOperario,
            o.usuario,
            o.clave,
            o.clave_hash,
            o.Operativo,
            anc.Sucursal,
            s.nombre as sucursal_nombre
        FROM Operarios o
        JOIN AsignacionNivelesCargos anc ON o.CodOperario = anc.CodOperario
        JOIN sucursales s ON anc.Sucursal = s.codigo
        WHERE o.usuario = ?
          AND anc.CodNivelesCargos = 27
          AND (anc.Fin IS NULL OR anc.Fin >= CURDATE())
        LIMIT 1
    ", [$usuario]);

    if (!$stmt) {
        return ['status' => false, 'msg' => 'Error al consultar base de datos.'];
    }

    $user = $stmt->fetch();

    if (!$user) {
        return ['status' => false, 'msg' => 'Usuario no encontrado o sin permisos de sucursal.'];
    }

    if (!$user['Operativo']) {
        return ['status' => false, 'msg' => 'Cuenta desactivada. Contacta a Recursos Humanos.'];
    }

    // Verificar que la sucursal del usuario coincida con la del dispositivo
    $dispositivoSucursal = $_SESSION['pos_device_sucursal'] ?? null;
    if ($dispositivoSucursal && $user['Sucursal'] != $dispositivoSucursal) {
        return [
            'status' => false,
            'msg' => 'Tu usuario no corresponde a la sucursal de este dispositivo.',
        ];
    }

    // Validación de contraseña: prioridad bcrypt, respaldo texto plano
    $claveValida = false;
    if (!empty($user['clave_hash']) && password_verify($clave, $user['clave_hash'])) {
        $claveValida = true;
    } elseif ($clave === $user['clave']) {
        $claveValida = true;
    }

    if (!$claveValida) {
        return ['status' => false, 'msg' => 'Contraseña incorrecta.'];
    }

    // Guardar sesión de tienda
    $_SESSION['pos_store_id'] = $user['CodOperario'];
    $_SESSION['pos_store_usuario'] = $user['usuario'];
    $_SESSION['pos_store_sucursal'] = $user['Sucursal'];
    $_SESSION['pos_store_sucursal_nombre'] = $user['sucursal_nombre'];

    // --- COMPATIBILIDAD LEGACY ---
    // Mapeo inicial para módulos que esperen estas variables desde la Etapa 1
    $_SESSION['usuario_id'] = $user['CodOperario'];
    $_SESSION['usuario_rol'] = 'sucursal'; // Rol genérico para POS
    $_SESSION['cargo_cod'] = 27;

    return ['status' => true];
}

// ---------------------------------------------------------------------------
// ETAPA 2: ACCESO DE COLABORADOR (solo clave + validación de marcación)
// ---------------------------------------------------------------------------

/**
 * Valida el PIN/clave de un colaborador y verifica su marcación activa en la sucursal.
 *
 * @param string $clave  Contraseña/PIN del colaborador
 * @return array ['status' => bool, 'msg' => string]
 */
function posValidarColaborador($clave)
{
    if (!posTiendaAutenticada()) {
        return ['status' => false, 'msg' => 'Sesión de tienda no activa.'];
    }

    if (empty($clave)) {
        return ['status' => false, 'msg' => 'Ingresa tu clave.'];
    }

    $sucursal = $_SESSION['pos_store_sucursal'];
    $hoy = date('Y-m-d');

    // Buscar colaboradores activos (excluir cargo 27 = encargados de sucursal, excepto cargos de administración/TI/pruebas)
    $stmt = ejecutarConsulta("
        SELECT
            o.CodOperario,
            o.Nombre,
            o.Nombre2,
            o.Apellido,
            o.Apellido2,
            o.clave,
            o.clave_hash
        FROM Operarios o
        WHERE o.Operativo = 1
          AND (
              o.CodOperario NOT IN (
                  SELECT DISTINCT anc.CodOperario
                  FROM AsignacionNivelesCargos anc
                  WHERE anc.CodNivelesCargos = 27
                    AND (anc.Fin IS NULL OR anc.Fin >= CURDATE())
              )
              OR o.CodOperario IN (
                  SELECT DISTINCT anc2.CodOperario
                  FROM AsignacionNivelesCargos anc2
                  WHERE anc2.CodNivelesCargos IN (11, 16, 49)
                    AND (anc2.Fin IS NULL OR anc2.Fin >= CURDATE())
              )
          )
    ", []);

    if (!$stmt) {
        return ['status' => false, 'msg' => 'Error al consultar base de datos.'];
    }

    $operarios = $stmt->fetchAll();
    $encontrado = null;

    foreach ($operarios as $op) {
        // Prioridad: clave_hash (bcrypt); respaldo: clave (texto plano)
        if (!empty($op['clave_hash']) && password_verify($clave, $op['clave_hash'])) {
            $encontrado = $op;
            break;
        } elseif ($clave === $op['clave']) {
            $encontrado = $op;
            break;
        }
    }

    if (!$encontrado) {
        return ['status' => false, 'msg' => 'Clave incorrecta.'];
    }

    // Buscamos sus cargos activos para verificar excepciones de marcación y permisos
    $stmtCargo = ejecutarConsulta("
        SELECT CodNivelesCargos 
        FROM AsignacionNivelesCargos 
        WHERE CodOperario = ? 
          AND (Fin IS NULL OR Fin >= CURDATE()) 
        ORDER BY Fecha DESC
    ", [$encontrado['CodOperario']]);

    $cargosColaborador = [];
    $cargoPrincipal = 2; // 2 = operario por defecto
    if ($stmtCargo) {
        $filasCargo = $stmtCargo->fetchAll();
        foreach ($filasCargo as $fc) {
            $cargosColaborador[] = (int) $fc['CodNivelesCargos'];
        }
        if (!empty($filasCargo)) {
            $cargoPrincipal = (int) $filasCargo[0]['CodNivelesCargos'];
        }
    }

    // Cargos de prueba exentos de restricción de marcación (11: Gerencia General, 16: TI/Sistemas, 49: Desarrollador)
    $cargosExentosMarcacion = [11, 16, 49];
    $esExentoMarcacion = !empty(array_intersect($cargosExentosMarcacion, $cargosColaborador));

    if (!$esExentoMarcacion) {
        // Verificar marcación activa HOY en esta sucursal
        $stmtMarc = ejecutarConsulta("
            SELECT id
            FROM marcaciones
            WHERE CodOperario    = ?
              AND fecha           = ?
              AND sucursal_codigo = ?
              AND hora_ingreso    IS NOT NULL
              AND (hora_salida IS NULL OR hora_salida = '')
            LIMIT 1
        ", [$encontrado['CodOperario'], $hoy, $sucursal]);

        if (!$stmtMarc || !$stmtMarc->fetch()) {
            return [
                'status' => false,
                'msg' => 'Acceso denegado: No tienes una marcación de entrada activa hoy en esta sucursal.',
            ];
        }
    }

    // Sesión de colaborador y metadatos de sesión (8h ventana deslizante)
    $ahora = time();
    $fechaActual = date('Y-m-d');

    $_SESSION['pos_colaborador_id'] = $encontrado['CodOperario'];
    $_SESSION['pos_colaborador_nombre'] = obtenerNombreCompletoOperario($encontrado);

    // --- COMPATIBILIDAD LEGACY ---
    $_SESSION['usuario_id'] = $encontrado['CodOperario'];
    $_SESSION['usuario_nombre'] = $_SESSION['pos_colaborador_nombre'];
    $_SESSION['login_time'] = $ahora;
    $_SESSION['login_date'] = $fechaActual;
    $_SESSION['last_activity'] = $ahora;
    $_SESSION['cargo_cod'] = $cargoPrincipal;

    return ['status' => true];
}

// ---------------------------------------------------------------------------
// CIERRE DE SESIÓN
// ---------------------------------------------------------------------------

/**
 * Cierra solo la sesión del colaborador actual.
 */
function posCerrarSesionColaborador()
{
    unset(
        $_SESSION['pos_colaborador_id'],
        $_SESSION['pos_colaborador_nombre'],
        $_SESSION['pos_usuario_completo']   // limpiar datos del colaborador anterior
    );
}

/**
 * Cierra la sesión completa (colaborador + tienda).
 */
function posCerrarSesionCompleta()
{
    session_unset();
    session_destroy();
}

/**
 * Middleware: verifica colaborador en endpoints AJAX.
 */
function posRequiereColaboradorAjax()
{
    if (!posColaboradorAutenticado()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'mensaje' => 'Sesión expirada o no autorizada.', 'relogin' => true]);
        exit();
    }
    // Renovar ventana de actividad activa
    $_SESSION['last_activity'] = time();
}

/**
 * COMPATIBILIDAD ERP: Replicamos funciones de auth.php para que los módulos
 * importados funcionen sin cambios estructurales.
 */
function obtenerUsuarioActual()
{
    // Prioridad: Colaborador actual (Stage 2)
    $id = $_SESSION['pos_colaborador_id'] ?? ($_SESSION['pos_store_id'] ?? null);

    if (!$id)
        return null;

    global $conn;
    try {
        $stmt = $conn->prepare("
            SELECT o.*, nc.Nombre as cargo_nombre, nc.CodNivelesCargos, anc.Sucursal as sucursal_codigo
            FROM Operarios o
            JOIN AsignacionNivelesCargos anc ON o.CodOperario = anc.CodOperario
            JOIN NivelesCargos nc ON anc.CodNivelesCargos = nc.CodNivelesCargos
            WHERE o.CodOperario = ? 
              AND (anc.Fin IS NULL OR anc.Fin >= CURDATE())
            ORDER BY anc.Fecha DESC
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("POS - Error en obtenerUsuarioActual: " . $e->getMessage());
        return null;
    }
}
?>