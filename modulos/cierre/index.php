<?php
/**
 * index.php — Módulo Cierre de Turno POS
 * C:\...\modulos\cierre\index.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
posRequiereColaborador();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
require_once __DIR__ . '/cierre_config.php';
require_once __DIR__ . '/cierre_helpers.php';

date_default_timezone_set(CIERRE_TIMEZONE);

$sucursalId     = $_SESSION['pos_store_sucursal']        ?? null;
$sucursalNombre = $_SESSION['pos_store_sucursal_nombre'] ?? 'Sin Sucursal';
$codCajero      = $_SESSION['pos_colaborador_id']        ?? null;
$nombreCajero   = $_SESSION['pos_colaborador_nombre']    ?? 'Cajero';

$hoy = date('Y-m-d');

// TC del día
$tc = cierre_obtenerTC($conn, $hoy);
$tcFormateado = $tc ? number_format($tc, 2) : null;

// Denominaciones como JSON para JS
$denomsNIO = json_encode(CIERRE_DENOMS_NIO);
$denomsUSD = json_encode(CIERRE_DENOMS_USD);

// Constantes para JS
$jsConfig = json_encode([
    'TOLERANCIA'     => CIERRE_TOLERANCIA_CONCEPTO,
    'MAX_RECONTEOS'  => CIERRE_MAX_RECONTEOS,
    'DENOMS_NIO'     => CIERRE_DENOMS_NIO,
    'DENOMS_USD'     => CIERRE_DENOMS_USD,
    'TC'             => $tc,
    'SUCURSAL_NOMBRE'=> $sucursalNombre,
]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre de Turno — POS</title>
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/cierre.css?v=<?php echo time(); ?>">
</head>
<body>
    <?= renderPOSSidebar('cierre') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Cierre de Turno') ?>

        <div class="pos-content">
            <div class="container-fluid p-3">

                <?php if (!$tc): ?>
                <!-- ── SIN TC: bloqueado ── -->
                <div class="cierre-sin-tc">
                    <div class="cierre-sin-tc-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <h4>Tipo de cambio no cargado</h4>
                    <p>No se puede iniciar el cierre porque no hay tipo de cambio registrado para hoy (<strong><?= htmlspecialchars($hoy) ?></strong>).</p>
                    <p class="text-muted">Por favor, carga el tipo de cambio del día antes de continuar.</p>
                </div>
                <?php else: ?>

                <!-- ── HEADER INFORMATIVO ── -->
                <div class="cierre-header-bar">
                    <div class="cierre-header-info">
                        <span class="cierre-badge-sucursal">
                            <i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($sucursalNombre) ?>
                        </span>
                        <span class="cierre-badge-tc">
                            <i class="bi bi-currency-exchange"></i> TC: C$ <?= $tcFormateado ?>/USD
                        </span>
                        <span class="cierre-badge-cajero">
                            <i class="bi bi-person-fill"></i> <?= htmlspecialchars($nombreCajero) ?>
                        </span>
                    </div>
                    <div class="cierre-header-actions">
                        <button id="btnIniciarCierre" class="cierre-btn-primary">
                            <i class="bi bi-lock-fill"></i> Iniciar Cierre de Turno
                        </button>
                    </div>
                </div>

                <!-- ── PANEL PRINCIPAL (oculto hasta iniciar) ── -->
                <div id="panelCierre" style="display:none;">

                    <!-- Info del turno -->
                    <div class="cierre-turno-info mb-3" id="infoTurno"></div>

                    <!-- ═══════════════════════════════════════════════════
                         PASO 1: CONTEO INICIAL (CIEGO)
                         ═══════════════════════════════════════════════════ -->
                    <div id="pasoConteoInicial" class="cierre-paso-card">
                        <div class="cierre-paso-titulo">
                            <span class="cierre-paso-num">1</span>
                            <div>
                                <h5 class="mb-0">Conteo de Caja <span class="badge bg-warning text-dark ms-2" style="font-size:.7rem;">CIEGO</span></h5>
                                <small class="text-muted">Ingresa los valores sin ver los esperados</small>
                            </div>
                        </div>

                        <!-- Efectivo NIO -->
                        <div class="cierre-seccion">
                            <div class="cierre-seccion-titulo">
                                <i class="bi bi-cash-coin"></i> Efectivo Córdobas (NIO)
                            </div>
                            <div class="ci-layout-tablas">
                                <div class="ci-card">
                                    <table class="ci-table">
                                        <thead>
                                            <tr>
                                                <th style="width:40%">Denominación</th>
                                                <th class="text-center" style="width:30%">Cantidad</th>
                                                <th class="text-end" style="width:30%">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tablaNIOBody"></tbody>
                                        <tfoot>
                                            <tr class="ci-subtotal-row">
                                                <td colspan="2" class="subtotal-label">Total Córdobas</td>
                                                <td class="subtotal-value" id="subtotalNIO">C$ 0.00</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                                <!-- Efectivo USD -->
                                <div class="ci-card">
                                    <table class="ci-table">
                                        <thead>
                                            <tr>
                                                <th style="width:40%">Denominación</th>
                                                <th class="text-center" style="width:30%">Cantidad</th>
                                                <th class="text-end" style="width:30%">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tablaUSDBody"></tbody>
                                        <tfoot>
                                            <tr class="ci-subtotal-row">
                                                <td colspan="2" class="subtotal-label">Total Dólares</td>
                                                <td class="subtotal-value usd-val" id="subtotalUSD">$ 0.00</td>
                                            </tr>
                                            <tr class="ci-subtotal-row">
                                                <td colspan="2" class="subtotal-label">USD en C$ <small class="opacity-50">(TC: <?= $tcFormateado ?>)</small></td>
                                                <td class="subtotal-value" id="subtotalUSDenNIO">C$ 0.00</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <div class="ci-total-global-bar mt-2">
                                <span><i class="bi bi-wallet2"></i> Total Efectivo Contado</span>
                                <span id="totalEfectivoContado" class="global-amount">C$ 0.00</span>
                            </div>
                        </div>

                        <!-- Otros conceptos -->
                        <div class="cierre-seccion mt-3">
                            <div class="cierre-seccion-titulo">
                                <i class="bi bi-credit-card-2-front"></i> Otros Métodos de Pago
                            </div>
                            <div class="row g-3 mt-1">
                                <!-- Cupones -->
                                <div class="col-md-6 col-lg-4">
                                    <div class="cierre-input-card" id="cardCupones">
                                        <label class="cierre-input-label">
                                            <i class="bi bi-ticket-perforated-fill text-warning"></i> Cupones
                                        </label>
                                        <div class="d-flex gap-2">
                                            <div>
                                                <small class="text-muted">Cantidad</small>
                                                <input type="number" id="cuponesQty" class="form-control cierre-input-num"
                                                    min="0" step="1" value="0" placeholder="0">
                                            </div>
                                            <div class="flex-grow-1">
                                                <small class="text-muted">Monto C$</small>
                                                <input type="number" id="cuponesMonto" class="form-control cierre-input-num"
                                                    min="0" step="1" value="0" placeholder="0">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- Transferencia -->
                                <div class="col-md-6 col-lg-4">
                                    <div class="cierre-input-card" id="cardTransferencia">
                                        <label class="cierre-input-label">
                                            <i class="bi bi-phone-fill text-info"></i> Transferencia C$
                                        </label>
                                        <input type="number" id="transferenciaMonto" class="form-control cierre-input-num"
                                            min="0" step="1" value="0" placeholder="0">
                                    </div>
                                </div>
                                <!-- Tarjeta POS -->
                                <div class="col-md-6 col-lg-4">
                                    <div class="cierre-input-card" id="cardTarjeta">
                                        <label class="cierre-input-label">
                                            <i class="bi bi-credit-card-fill text-primary"></i> Tarjeta / POS Banco C$
                                        </label>
                                        <input type="number" id="tarjetaMonto" class="form-control cierre-input-num"
                                            min="0" step="1" value="0" placeholder="0">
                                        <small class="text-muted">Ingresa el total del voucher del datáfono</small>
                                    </div>
                                </div>
                                <!-- PedidosYa -->
                                <div class="col-md-6 col-lg-4">
                                    <div class="cierre-input-card" id="cardPedidosYa">
                                        <label class="cierre-input-label">
                                            <i class="bi bi-bag-fill" style="color:#ff6900;"></i> PedidosYa C$
                                        </label>
                                        <input type="number" id="pedidosyaMonto" class="form-control cierre-input-num"
                                            min="0" step="1" value="0" placeholder="0">
                                        <small class="text-muted">Total que muestra la app/equipo PedidosYa</small>
                                        <button type="button" class="btn btn-link btn-sm p-0 mt-1" id="btnVerFacturasPY">
                                            <i class="bi bi-list-ul"></i> Ver facturas del turno
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 d-flex justify-content-end">
                            <button id="btnGuardarConteo" class="cierre-btn-primary">
                                <i class="bi bi-save2-fill"></i> Guardar Conteo
                            </button>
                        </div>
                    </div><!-- /pasoConteoInicial -->

                    <!-- ═══════════════════════════════════════════════════
                         PASO 2: RESULTADOS + RECONTEOS
                         ═══════════════════════════════════════════════════ -->
                    <div id="pasoResultados" style="display:none;">
                        <div class="cierre-paso-card">
                            <div class="cierre-paso-titulo">
                                <span class="cierre-paso-num">2</span>
                                <div>
                                    <h5 class="mb-0">Resultados del Conteo</h5>
                                    <small class="text-muted">Revisa diferencias y reconta si es necesario</small>
                                </div>
                            </div>

                            <!-- Tabla de resultados por concepto -->
                            <div id="tablaResultados" class="mt-3"></div>

                            <!-- Panel de reconteo (oculto hasta seleccionar) -->
                            <div id="panelReconteo" style="display:none;" class="mt-4">
                                <div class="cierre-seccion">
                                    <div class="cierre-seccion-titulo">
                                        <i class="bi bi-arrow-repeat"></i> Reconteo
                                        <span id="lblReconteoConcepto" class="ms-2 badge bg-secondary"></span>
                                        <span id="lblIntentosRestantes" class="ms-2 small text-muted"></span>
                                    </div>
                                    <div id="panelReconteoContenido"></div>
                                    <div class="mt-3 d-flex gap-2 justify-content-end">
                                        <button id="btnCancelarReconteo" class="btn btn-outline-secondary">
                                            Cancelar
                                        </button>
                                        <button id="btnGuardarReconteo" class="cierre-btn-primary">
                                            <i class="bi bi-save2-fill"></i> Guardar Reconteo
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Comentarios para fuera de tolerancia -->
                            <div id="panelComentarios" class="mt-4" style="display:none;">
                                <div class="cierre-seccion">
                                    <div class="cierre-seccion-titulo">
                                        <i class="bi bi-chat-left-text-fill text-danger"></i>
                                        Comentario requerido (concepto fuera de tolerancia)
                                    </div>
                                    <div id="listaComentarios" class="mt-2"></div>
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-end gap-3">
                                <button id="btnConfirmarCierre" class="cierre-btn-cerrar" disabled>
                                    <i class="bi bi-check2-circle"></i> Confirmar y Cerrar Turno
                                </button>
                            </div>
                        </div>
                    </div><!-- /pasoResultados -->

                    <!-- ═══════════════════════════════════════════════════
                         PASO 3: CIERRE CONFIRMADO
                         ═══════════════════════════════════════════════════ -->
                    <div id="pasoCerrado" style="display:none;">
                        <div class="cierre-exito-card">
                            <div class="cierre-exito-icon"><i class="bi bi-check-circle-fill"></i></div>
                            <h4>Turno Cerrado</h4>
                            <p class="text-muted" id="txtResumenCierre"></p>
                            <div class="d-flex gap-3 justify-content-center mt-3">
                                <button id="btnImprimirCierre" class="cierre-btn-primary">
                                    <i class="bi bi-printer-fill"></i> Imprimir Cierre
                                </button>
                                <button id="btnNuevoCierre" class="btn btn-outline-secondary">
                                    <i class="bi bi-plus-circle"></i> Nuevo Turno
                                </button>
                            </div>
                        </div>
                    </div>

                </div><!-- /panelCierre -->

                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- Modal PedidosYa Facturas -->
    <div class="modal fade" id="modalPedidosYa" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background:#ff6900; color:#fff;">
                    <h5 class="modal-title"><i class="bi bi-bag-fill me-2"></i>Facturas PedidosYa del Turno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalPedidosYaBody">
                    <div class="text-center py-4"><div class="spinner-border"></div></div>
                </div>
                <div class="modal-footer">
                    <strong>Total esperado PedidosYa: <span id="totalEsperadoPY">C$ 0.00</span></strong>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.CIERRE_CONFIG = <?= $jsConfig ?>;
    </script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../../modulos/pos/css/../../../modulos/pos/js/pos_impresion_termica.js?v=<?php echo time(); ?>"></script>
    <script src="js/cierre.js?v=<?php echo time(); ?>"></script>
    <script src="js/cierre_impresion.js?v=<?php echo time(); ?>"></script>
</body>
</html>
