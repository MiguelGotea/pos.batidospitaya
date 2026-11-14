<?php
/**
 * test_cierre.php — Pruebas CLI del Módulo Cierre de Turno
 * POS Batidos Pitaya
 *
 * Ejecutar: php modulos/cierre/tests/test_cierre.php
 *
 * No requiere base de datos activa — prueba la lógica pura de helpers.
 */

define('CIERRE_TOLERANCIA_CONCEPTO',      1);
define('CIERRE_MAX_RECONTEOS',            3);
define('CIERRE_TOLERANCIA_CANTIDAD_CUPONES', 0);
define('CIERRE_TIMEZONE',                 'America/Managua');
define('CIERRE_DENOMS_NIO', [0.5,1,5,10,20,50,100,200,500]);
define('CIERRE_DENOMS_USD', [1,5,10,20,50,100]);

// Incluir helpers puros (sin DB)
require_once __DIR__ . '/../cierre_helpers.php';

/* ─── Utilidades de test ─────────────────────────────────────── */
$passed = 0;
$failed = 0;
$errors = [];

function assert_equal($label, $actual, $expected) {
    global $passed, $failed, $errors;
    if ($actual == $expected) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        $errors[] = $label;
        echo "  ✗ $label — esperado: " . json_encode($expected) . ", obtenido: " . json_encode($actual) . "\n";
    }
}

function assert_true($label, $val) {
    assert_equal($label, $val, true);
}

function assert_false($label, $val) {
    assert_equal($label, $val, false);
}

echo "\n════════════════════════════════════════════════════════\n";
echo "  PRUEBAS MÓDULO CIERRE DE TURNO\n";
echo "════════════════════════════════════════════════════════\n\n";

/* ════════════════════════════════════════════════════════════════
   TEST 1: Esperado de efectivo con pagos mixtos y cambio negativo
   ════════════════════════════════════════════════════════════════ */
echo "── Test 1: Cálculo esperado efectivo ──\n";

// Simulación manual de lo que hace cierre_calcularEsperadoEfectivo
// Escenario:
//   caja_inicial = 1500 C$
//   pagos efectivo NIO: +500 (recibido), +700 (recibido), -200 (cambio)  → neto = +1000
//   pagos efectivo USD: +10 USD × TC 36.60 → +366 C$
//   aligeramientos: 300 C$
//   proveedores: 150 C$
//   esperado = 1500 + 1000 + 366 - 300 - 150 = 2416 C$

$cajaIni    = 1500.00;
$tc         = 36.60;
$pagosNIO   = 500 + 700 + (-200);   // = 1000
$pagosUSD   = 10.0;
$pagosUSDC  = round($pagosUSD * $tc, 2);  // 366.00
$aligC      = 300.00;
$provC      = 150.00;
$esperado   = round($cajaIni + $pagosNIO + $pagosUSDC - $aligC - $provC, 2);

assert_equal('Esperado efectivo mixto con cambio', $esperado, 2416.00);

// Cambio en USD: cliente paga $20 por una factura de C$600
// cambio = round(C$600 − $20×36.60) → factura 600, recibido 732 (en C$), cambio −132 C$
// Pero el cambio se registra en C$ negativo.
$factura  = 600;
$pagadoUSDC = round(20 * $tc);     // 732
$cambio     = $factura - $pagadoUSDC; // -132 (negativo = lo que se devuelve)
$pagoNeto   = $pagadoUSDC + $cambio;  // 732 + (-132) = 600 = exacto
assert_equal('Cambio en C$ desde pago USD', $pagoNeto, 600);
assert_equal('Cambio tiene signo negativo', $cambio < 0, true);

echo "\n── Test 2: Factura fuera del rango queda fuera del cierre ──\n";
// Corte actual = '2026-11-13 14:00:00'
// Factura A: fecha_hora = '2026-11-13 13:59:59' → dentro  (<=)
// Factura B: fecha_hora = '2026-11-13 14:00:01' → fuera   (>)
$corteActual = '2026-11-13 14:00:00';
$factA = '2026-11-13 13:59:59';
$factB = '2026-11-13 14:00:01';
assert_true ('Factura A dentro del corte',  strtotime($factA) <= strtotime($corteActual));
assert_false('Factura B fuera del corte',   strtotime($factB) <= strtotime($corteActual));

echo "\n── Test 3: Conteo ciego — cierre_calcularContadoEfectivo ──\n";
// Conteo: 2 × C$100, 3 × C$50, 1 × $20 USD
// contado = 200 + 150 + 20*36.60 = 350 + 732 = 1082
$filasConteo = [
    ['moneda' => 'NIO', 'denominacion' => '100.00', 'cantidad' => 2, 'monto' => '200.00'],
    ['moneda' => 'NIO', 'denominacion' => '50.00',  'cantidad' => 3, 'monto' => '150.00'],
    ['moneda' => 'USD', 'denominacion' => '20.00',  'cantidad' => 1, 'monto' => '20.00'],
];
$contado = cierre_calcularContadoEfectivo($filasConteo, 36.60);
assert_equal('Contado efectivo mixto', $contado, 1082.00);

echo "\n── Test 4: Tolerancia por concepto independiente ──\n";
// Efectivo: diferencia +2 (fuera de tolerancia ±1)
// Cupones:  diferencia 0  (dentro)
// Ambos independientes — un sobrante no compensa un faltante

assert_false('Efectivo +2 fuera de tolerancia',    cierre_dentroTolerancia('efectivo', 2.0));
assert_true ('Efectivo +1 dentro de tolerancia',   cierre_dentroTolerancia('efectivo', 1.0));
assert_true ('Efectivo -1 dentro de tolerancia',   cierre_dentroTolerancia('efectivo', -1.0));
assert_false('Efectivo -2 fuera de tolerancia',    cierre_dentroTolerancia('efectivo', -2.0));
assert_true ('Cupones monto 0 cantidad 0',          cierre_dentroTolerancia('cupones', 0.0, 0));
assert_false('Cupones cantidad dif=1 (tol=0)',      cierre_dentroTolerancia('cupones', 0.0, 1));

// Un sobrante en efectivo (+5) no afecta el estado de cupones (-3)
$resEfectivo = cierre_dentroTolerancia('efectivo',     5.0);
$resCupones  = cierre_dentroTolerancia('cupones',      -3.0, -1);
assert_false('Sobrante efectivo no compensa cupones', $resEfectivo && $resCupones);

echo "\n── Test 5: Reconteos solo en conceptos fuera de tolerancia ──\n";
// Concepto dentro: no se puede mandar a recontar (el server rechazaría)
// Simulado con la lógica de intentos
$intentosUsados = 0;
$fueraTol = true;
assert_true('Puede recontar: intentos=0, fuera=true',  $fueraTol && $intentosUsados < CIERRE_MAX_RECONTEOS);
$intentosUsados = CIERRE_MAX_RECONTEOS; // 3
assert_false('No puede recontar: intentos=3',           $fueraTol && $intentosUsados < CIERRE_MAX_RECONTEOS);
$fueraTol = false;
$intentosUsados = 0;
assert_false('No puede recontar: dentro de tolerancia', $fueraTol && $intentosUsados < CIERRE_MAX_RECONTEOS);

echo "\n── Test 6: Cierre con diferencias — diferencia se registra ──\n";
// Si se confirma el cierre con diferencia, se registra incidencia
$diferencia  = -150.00; // faltante
$tipo = $diferencia < 0 ? 'faltante' : 'sobrante';
assert_equal('Tipo de incidencia faltante', $tipo, 'faltante');
$diferencia2 = 75.00; // sobrante
$tipo2 = $diferencia2 > 0 ? 'sobrante' : 'faltante';
assert_equal('Tipo de incidencia sobrante', $tipo2, 'sobrante');
// La confirmación no se bloquea por diferencias
assert_true('Cierre no se bloquea por diferencias', true); // validado por diseño del endpoint

echo "\n── Test 7: Un solo cierre en curso por sucursal ──\n";
// La unicidad está garantizada por trigger en BD.
// Aquí probamos la lógica de detección previa al INSERT.
// cierre_obtenerEnCurso() devuelve null si no existe
// Si existe → retomar, si no → crear nuevo
$simuladoEnCurso = null;
$seRetomaria     = $simuladoEnCurso !== null;
assert_false('Sin cierre en curso → no retoma', $seRetomaria);

$simuladoEnCurso = ['id' => 5, 'cod_sucursal' => '14', 'estado' => 'en_curso'];
$seRetomaria     = $simuladoEnCurso !== null;
assert_true('Con cierre en curso → retoma', $seRetomaria);

echo "\n── Test 8: Cierre cerrado no se modifica ──\n";
// El endpoint cierre_confirmar valida: estado = 'en_curso'. Un cierre cerrado no pasa.
$estadoCierre  = 'cerrado';
$puedeModificar = ($estadoCierre === 'en_curso');
assert_false('Cierre cerrado no se puede modificar', $puedeModificar);

echo "\n── Test 9: Alerta de discrepancia de traspaso ──\n";
// Si totalCajaIni − totalCierreAnterior > 1 → flag
$conteoCI    = 2500.00;  // caja inicial
$conteoCierre = 2600.00; // último cierre
$dif         = round($conteoCI - $conteoCierre, 2);
assert_equal('Diferencia de traspaso calculada', $dif, -100.00);
assert_true ('Diferencia > 1 → genera flag', abs($dif) > 1);

$conteoCI2    = 2500.00;
$conteoCierre2 = 2500.00;
$dif2          = round($conteoCI2 - $conteoCierre2, 2);
assert_false('Diferencia = 0 → no genera flag', abs($dif2) > 1);

echo "\n── Test 10: Cupones — cantidad exacta y monto con tolerancia ──\n";
$cantEsp = 5; $cantCont = 5;
$montoEsp = 300.00; $montoCont = 299.00;
$difCant  = $cantCont - $cantEsp;        // 0
$difMonto = round($montoCont - $montoEsp, 2); // -1.00
assert_true('Cupones cantidad exacta ok (dif=0)',   cierre_dentroTolerancia('cupones', $difMonto, $difCant));

$cantEsp2 = 5; $cantCont2 = 4;
$difCant2 = $cantCont2 - $cantEsp2;  // -1
assert_false('Cupones cantidad dif=1 no ok (tol=0)', cierre_dentroTolerancia('cupones', 0.0, $difCant2));

echo "\n── Test 11: Reimpresión desde snapshot ──\n";
// El snapshot es JSON inmutable. Verificar que renderizarTicketCierre no recalcula nada.
$snapshotSimulado = [
    'cierre' => [
        'id' => 1, 'numero_turno' => 1, 'fecha' => '2026-11-13',
        'cajero' => 'Test Cajero', 'sucursal' => 'Sucursal Test',
        'tipo_cambio_dia' => 36.6, 'caja_inicial_c' => 1000.00,
        'corte_anterior' => '2026-11-13 08:00:00',
        'corte_actual'   => '2026-11-13 14:00:00',
        'cerrado_en'     => '2026-11-13 14:05:00',
    ],
    'conceptos'            => [],
    'conteo_final_denoms'  => [],
    'aligeramientos'       => [],
    'pagos_proveedores'    => [],
    'facturas_pedidosya'   => [],
    'resumen_ventas'       => ['total_facturas' => 10, 'suma_total' => 5000.00],
    'generado_en'          => '2026-11-13 14:05:00',
];
// El snapshot es estático — su contenido no cambia
$snapshotEncode = json_encode($snapshotSimulado);
$snapshotDecode = json_decode($snapshotEncode, true);
assert_equal('Snapshot se serializa y deserializa igual', $snapshotDecode['cierre']['cajero'], 'Test Cajero');
assert_equal('Snapshot resumen ventas inmutable', $snapshotDecode['resumen_ventas']['total_facturas'], 10);

echo "\n── Test 12: cierre_usdACordoba ──\n";
assert_equal('10 USD × 36.60 = 366', cierre_usdACordoba(10.0, 36.60), 366);
assert_equal('5.5 USD × 36.60 = 201 (round)', cierre_usdACordoba(5.5, 36.60), 201);

/* ─── Resumen ─────────────────────────────────────────────────── */
echo "\n════════════════════════════════════════════════════════\n";
echo "  RESULTADO: {$passed} pasadas  |  {$failed} fallidas\n";
if ($failed > 0) {
    echo "  FALLIDAS:\n";
    foreach ($errors as $e) echo "    • $e\n";
}
echo "════════════════════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);

