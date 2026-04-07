<?php
/**
 * caja_inicial.php â€” Conteo de Caja Inicial
 * MÃ³dulo POS / modulos/POS/caja_inicial.php
 */
require_once '../../core/auth/auth_pos.php';
posRequiereColaborador();

require_once '../../core/layout/menu_lateral.php';
require_once '../../core/layout/header_universal.php';
require_once '../../core/database/conexion.php';

// Obtener sucursal y colaborador de la sesion POS
$sucursalId     = $_SESSION['pos_store_sucursal'] ?? null;
$sucursalNombre = $_SESSION['pos_store_sucursal_nombre'] ?? 'Sin Sucursal';
$codOperario    = $_SESSION['pos_colaborador_id'] ?? null;


// Obtener Ãºltimo tipo de cambio
$tipoCambio = 36.6; // valor por defecto
try {
    $tcRow = $conn->query("SELECT tasa FROM tipo_cambio ORDER BY id DESC LIMIT 1")->fetch();
    if ($tcRow && isset($tcRow['tasa'])) {
        $tipoCambio = (float)$tcRow['tasa'];
    }
} catch (Exception $e) {
    // Usamos el valor por defecto
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caja Inicial â€” POS</title>
    <meta name="description" content="Conteo de caja inicial: denominaciones en cÃ³rdobas y dÃ³lares con cÃ¡lculo automÃ¡tico de totales">
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/caja_inicial.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php // echo renderMenuLateral($cargoOperario); // Opcional: ajustar menu para POS si es necesario ?>


    <div class="main-container">
        <div class="sub-container">
            <?php echo renderHeader($usuario, false, 'Caja Inicial'); ?>

            <div class="container-fluid p-3">

                <!-- ====== CABECERA: Fecha + Tipo de Cambio ====== -->
                <form id="formCajaInicial">

                    <div class="ci-header-card">
                        <div class="ci-header-field">
                            <label for="inputFecha"><i class="bi bi-calendar3 me-1"></i> Fecha del Conteo</label>
                            <input type="date"
                                   class="form-control"
                                   id="inputFecha"
                                   name="fecha"
                                   value="<?php echo $hoy; ?>"
                                   readonly
                                   style="background-color: #f8fafc; cursor: default;">
                        </div>

                        <div class="ci-header-field">
                            <label><i class="bi bi-currency-exchange me-1"></i> Tipo de Cambio Actual</label>
                            <div class="ci-tc-badge">
                                <i class="bi bi-arrow-left-right"></i>
                                C$ <span id="tcDisplay"><?php echo number_format($tipoCambio, 2); ?></span>
                                / USD
                            </div>
                        </div>

                        <div class="ci-header-field">
                            <label><i class="bi bi-shop me-1"></i> Sucursal</label>
                            <div class="ci-tc-badge" style="background:#fef9c3; border-color:#fef08a; color:#854d0e;">
                                <i class="bi bi-geo-alt-fill"></i>
                                <span id="sucursalDisplay"><?php echo $sucursalNombre; ?></span>
                            </div>
                            <input type="hidden" id="inputSucursalId" name="sucursal_id" value="<?php echo $sucursalId; ?>">
                        </div>

                        <div class="ci-header-field ms-auto">
                            <label>&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="ci-btn-guardar" id="btnGuardar">
                                    <i class="bi bi-save2-fill"></i> Guardar Caja Inicial
                                </button>
                                <button type="button" class="ci-btn-limpiar" id="btnLimpiar">
                                    <i class="bi bi-arrow-counterclockwise"></i> Limpiar
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Campos ocultos para totales calculados en JS -->
                    <input type="hidden" id="hidTotalNIO" value="0">
                    <input type="hidden" id="hidTotalUSD" value="0">
                    <input type="hidden" id="hidTotalUSDenNIO" value="0">
                    <input type="hidden" id="hidTotalGlobal" value="0">

                    <!-- ====== TABLAS LADO A LADO ====== -->
                    <div class="caja-inicial-layout">

                        <!-- ====== CÃ“RDOBAS ====== -->
                        <div class="ci-card">
                            <div class="ci-card-header">
                                <i class="bi bi-cash-coin"></i>
                                CÃ³rdobas (NIO)
                            </div>
                            <div class="table-responsive">
                                <table class="ci-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40%">Moneda</th>
                                            <th class="text-center" style="width:30%">Cantidad</th>
                                            <th class="text-end"   style="width:30%">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tablaNIOBody">
                                        <!-- Filas generadas por JS -->
                                    </tbody>
                                    <tfoot>
                                        <tr class="ci-subtotal-row">
                                            <td colspan="2" class="subtotal-label">
                                                <i class="bi bi-sigma me-1"></i>
                                                Total CÃ³rdobas
                                            </td>
                                            <td class="subtotal-value" id="subtotalNIO">C$ 0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- ====== DÃ“LARES ====== -->
                        <div class="ci-card">
                            <div class="ci-card-header usd">
                                <i class="bi bi-currency-dollar"></i>
                                DÃ³lares (USD)
                            </div>
                            <div class="table-responsive">
                                <table class="ci-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40%">Moneda</th>
                                            <th class="text-center" style="width:30%">Cantidad</th>
                                            <th class="text-end"   style="width:30%">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tablaUSDBody">
                                        <!-- Filas generadas por JS -->
                                    </tbody>
                                    <tfoot>
                                        <tr class="ci-subtotal-row">
                                            <td colspan="2" class="subtotal-label">
                                                <i class="bi bi-sigma me-1"></i>
                                                Total DÃ³lares
                                            </td>
                                            <td class="subtotal-value usd-val" id="subtotalUSD">$ 0.00</td>
                                        </tr>
                                        <tr class="ci-subtotal-row">
                                            <td colspan="2" class="subtotal-label">
                                                <i class="bi bi-arrow-left-right me-1"></i>
                                                DÃ³lares en CÃ³rdobas
                                                <small style="opacity:.7;">(TC: <?php echo number_format($tipoCambio, 2); ?>)</small>
                                            </td>
                                            <td class="subtotal-value cordobizado-val" id="subtotalUSDenNIO">C$ 0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                    </div><!-- /caja-inicial-layout -->

                    <!-- ====== TOTAL EFECTIVO GLOBAL ====== -->
                    <div class="ci-total-global-bar">
                        <div class="global-label">
                            <i class="bi bi-wallet2"></i>
                            Total Efectivo en Caja Inicial
                        </div>
                        <div class="global-amount" id="totalEfectivoGlobal">
                            C$ 0.00
                        </div>
                    </div>

                </form><!-- /formCajaInicial -->

            </div><!-- /container-fluid -->
        </div>
    </div>

    <!-- Tipo de cambio disponible para JS -->
    <script>
        window.TIPO_CAMBIO = <?php echo json_encode($tipoCambio); ?>;
    </script>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/caja_inicial.js?v=<?php echo time(); ?>"></script>
</body>
</html>
