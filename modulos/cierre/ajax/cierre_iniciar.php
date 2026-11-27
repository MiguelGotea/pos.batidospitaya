<?php
/**
 * cierre_iniciar.php — Endpoint: Iniciar o retomar cierre de turno
 *
 * POST JSON → { accion: 'iniciar' }
 * Responde:
 *   Si ya existe en_curso → { ok:true, estado:'retomado', cierre: {...} }
 *   Si se crea nuevo      → { ok:true, estado:'iniciado', cierre: {...} }
 *   Sin TC del día        → { ok:false, error:'sin_tc' }
 *
 * NUNCA devuelve esperados ni diferencias en esta respuesta.
 */

require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
require_once __DIR__ . '/../cierre_config.php';
require_once __DIR__ . '/../cierre_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'mensaje' => 'Método no permitido.']);
    exit;
}

// Sesión
$codSucursal = $_SESSION['pos_store_sucursal'] ?? null;
$sucursalNombre = $_SESSION['pos_store_sucursal_nombre'] ?? '';
$codCajero = $_SESSION['pos_colaborador_id'] ?? null;
$nombreCajero = $_SESSION['pos_colaborador_nombre'] ?? 'Cajero';

if (!$codSucursal || !$codCajero) {
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión inválida.']);
    exit;
}

// Zona horaria
date_default_timezone_set(CIERRE_TIMEZONE);
$ahora = date('Y-m-d H:i:s');
$hoy = date('Y-m-d');

try {
    // ¿Existe cierre en_curso?
    $enCurso = cierre_obtenerEnCurso($conn, $codSucursal);
    if ($enCurso) {
        // Retomar — verificar si ya se guardó conteo inicial (intento 0)
        $stmtConteoExiste = $conn->prepare(
            "SELECT COUNT(*) FROM pos_cierres_conteos
             WHERE cierre_id = ? AND intento = 0"
        );
        $stmtConteoExiste->execute([$enCurso['id']]);
        $tieneConteoInicial = (int) $stmtConteoExiste->fetchColumn() > 0;

        echo json_encode([
            'ok' => true,
            'estado' => 'retomado',
            'tiene_conteo_inicial' => $tieneConteoInicial,
            'cierre' => [
                'id' => (int) $enCurso['id'],
                'numero_turno' => (int) $enCurso['numero_turno_dia'],
                'fecha' => $enCurso['fecha'],
                'corte_anterior' => $enCurso['corte_anterior'],
                'corte_actual' => $enCurso['corte_actual'],
                'tipo_cambio_dia' => (float) $enCurso['tipo_cambio_dia'],
                'cajero' => $enCurso['nombre_cajero_snap'],
                'sucursal' => $sucursalNombre,
            ],
        ]);
        exit;
    }

    // Verificar TC del día
    $tc = cierre_obtenerTC($conn, $hoy);
    if ($tc === null) {
        echo json_encode([
            'ok' => false,
            'error' => 'sin_tc',
            'mensaje' => 'No hay tipo de cambio cargado para hoy. Cárgalo antes de iniciar el cierre.',
        ]);
        exit;
    }

    // Determinar corte_anterior y caja_inicial_equiv_c:
    // Si ya hubo un cierre 'cerrado' hoy (Turno 2+), el balance inicial es el efectivo
    // contado al final del cierre anterior del mismo día.
    $stmtUltimo = $conn->prepare(
        "SELECT id, corte_actual FROM pos_cierres_turno
         WHERE cod_sucursal = ? AND fecha = ? AND estado = 'cerrado'
         ORDER BY cerrado_en DESC, id DESC LIMIT 1"
    );
    $stmtUltimo->execute([$codSucursal, $hoy]);
    $ultimoCierre = $stmtUltimo->fetch(PDO::FETCH_ASSOC);

    if ($ultimoCierre) {
        $corteAnterior = $ultimoCierre['corte_actual'];
        $cajaIniId = null;
        $cajaIniEquivC = posObtenerEfectivoCierreAnterior((int) $ultimoCierre['id']);
    } else {
        // Primer turno del día: desde la caja inicial del día
        $stmtCajaIni = $conn->prepare(
            "SELECT id, total_efectivo_global, fecha_hora_regsys FROM pos_caja_inicial
             WHERE sucursal_id = ? AND fecha = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmtCajaIni->execute([$codSucursal, $hoy]);
        $cajaIni = $stmtCajaIni->fetch(PDO::FETCH_ASSOC);
        $cajaIniId = $cajaIni ? (int) $cajaIni['id'] : null;
        $cajaIniEquivC = $cajaIni ? (float) $cajaIni['total_efectivo_global'] : 0.0;
        $corteAnterior = ($cajaIni && !empty($cajaIni['fecha_hora_regsys']))
            ? $cajaIni['fecha_hora_regsys']
            : ($hoy . ' 00:00:00');
    }

    $corteActual = $ahora;  // fijado en servidor al iniciar

    // ¿Existe turno 'abierto' para esta sucursal?
    $stmtAbierto = $conn->prepare(
        "SELECT * FROM pos_cierres_turno
         WHERE cod_sucursal = ? AND estado = 'abierto'
         ORDER BY id DESC LIMIT 1"
    );
    $stmtAbierto->execute([$codSucursal]);
    $turnoAbierto = $stmtAbierto->fetch(PDO::FETCH_ASSOC);

    $conn->beginTransaction();
    if ($turnoAbierto) {
        $cierreId = (int) $turnoAbierto['id'];
        $numeroTurno = (int) $turnoAbierto['numero_turno_dia'];
        $corteAnterior = $turnoAbierto['corte_anterior'] ?: $corteAnterior;
        // Para Turno 1 preservamos su caja_inicial_equiv_c; para Turno 2+ usamos el efectivo del cierre anterior
        $equivC = $ultimoCierre ? $cajaIniEquivC : (float) $turnoAbierto['caja_inicial_equiv_c'];

        $stmtUpd = $conn->prepare(
            "UPDATE pos_cierres_turno
             SET estado = 'en_curso',
                 tipo_cambio_dia = :tc,
                 corte_anterior = :anterior,
                 corte_actual = :actual,
                 caja_inicial_equiv_c = :ci_equiv
             WHERE id = :id"
        );
        $stmtUpd->execute([
            ':tc' => $tc,
            ':anterior' => $corteAnterior,
            ':actual' => $corteActual,
            ':ci_equiv' => $equivC,
            ':id' => $cierreId,
        ]);
    } else {
        $numeroTurno = cierre_numeroTurnoDia($conn, $codSucursal, $hoy);
        $stmtIns = $conn->prepare(
            "INSERT INTO pos_cierres_turno
                (cod_sucursal, numero_turno_dia, fecha, cod_cajero, nombre_cajero_snap,
                 estado, tipo_cambio_dia, corte_anterior, corte_actual,
                 caja_inicial_id, caja_inicial_equiv_c, iniciado_en)
             VALUES
                (:suc, :turno, :fecha, :cajero, :nombre_cajero,
                 'en_curso', :tc, :anterior, :actual,
                 :ci_id, :ci_equiv, :iniciado_en)"
        );
        $stmtIns->execute([
            ':suc' => $codSucursal,
            ':turno' => $numeroTurno,
            ':fecha' => $hoy,
            ':cajero' => $codCajero,
            ':nombre_cajero' => $nombreCajero,
            ':tc' => $tc,
            ':anterior' => $corteAnterior,
            ':actual' => $corteActual,
            ':ci_id' => $cajaIniId,
            ':ci_equiv' => $cajaIniEquivC,
            ':iniciado_en' => $ahora,
        ]);
        $cierreId = (int) $conn->lastInsertId();
    }
    $conn->commit();

    echo json_encode([
        'ok' => true,
        'estado' => 'iniciado',
        'tiene_conteo_inicial' => false,
        'cierre' => [
            'id' => $cierreId,
            'numero_turno' => $numeroTurno,
            'fecha' => $hoy,
            'corte_anterior' => $corteAnterior,
            'corte_actual' => $corteActual,
            'tipo_cambio_dia' => $tc,
            'cajero' => $nombreCajero,
            'sucursal' => $sucursalNombre,
        ],
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction())
        $conn->rollBack();
    // No exponer detalles de BD al cliente
    error_log('[cierre_iniciar] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'mensaje' => 'Error al iniciar el cierre. Intente nuevamente.']);
}

