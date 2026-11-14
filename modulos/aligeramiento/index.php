<?php
/**
 * index.php — Módulo Aligeramientos POS
 * Historial + Registro por denominación + Vouchers IA
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
posRequiereColaborador();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';

$hoy = date('Y-m-d');

// Sucursal del dispositivo
$sucursalId     = $_SESSION['pos_store_sucursal']        ?? null;
$sucursalNombre = $_SESSION['pos_store_sucursal_nombre'] ?? 'Sin Sucursal';

// Colaborador activo
$colaboradorNombre = $_SESSION['pos_colaborador_nombre'] ?? 'Usuario';

// Tipo de cambio
$tipoCambio = 36.6;
try {
    $tcRow = $conn->query("SELECT tasa FROM tipo_cambio ORDER BY id DESC LIMIT 1")->fetch();
    if ($tcRow && isset($tcRow['tasa'])) {
        $tipoCambio = (float)$tcRow['tasa'];
    }
} catch (Exception $e) { /* valor por defecto */ }
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aligeramientos — POS</title>
    <meta name="description" content="Registro de aligeramientos de caja con validación de vouchers por IA">
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/aligeramiento.css?v=<?php echo time(); ?>">
</head>

<body>
    <?= renderPOSSidebar('aligeramiento') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Aligeramientos') ?>

        <div class="pos-content">
            <div class="container-fluid p-3">

                <!-- ====== TOP BAR ====== -->
                <div class="alig-top-bar">
                    <div class="alig-title">
                        <i class="bi bi-cash-stack"></i>
                        Historial de Aligeramientos
                        <span class="badge ms-2" style="background:#E6F4F1; color:#0E544C; font-size:.75rem; font-weight:700;">
                            <i class="bi bi-geo-alt-fill me-1"></i><?php echo htmlspecialchars($sucursalNombre); ?>
                        </span>
                    </div>
                    <button class="btn-nuevo-alig" onclick="abrirModalRegistro()">
                        <i class="bi bi-plus-circle-fill"></i> Nuevo Aligeramiento
                    </button>
                </div>

                <!-- ====== BUSCADOR ====== -->
                <div class="alig-search-container">
                    <div class="position-relative">
                        <i class="bi bi-search position-absolute" style="left:16px; top:50%; transform:translateY(-50%); z-index:10; color:#6c757d; font-size:16px;"></i>
                        <input type="text" id="buscarGlobal" class="form-control w-100"
                            placeholder="Buscar por fecha, observación, usuario..."
                            style="padding-left:44px; padding-right:44px; height:46px !important; font-size:14px !important; border-radius:12px !important;">
                        <div id="buscarGlobalSpinner" class="position-absolute" style="right:16px; top:50%; transform:translateY(-50%); z-index:10; display:none; color:#6c757d;">
                            <i class="fas fa-spinner fa-spin" style="font-size:16px;"></i>
                        </div>
                    </div>
                </div>

                <!-- ====== TABLA HISTORIAL ====== -->
                <div class="alig-table-container">
                    <table class="alig-table" id="tablaAlig">
                        <thead>
                            <tr>
                                <th data-column="fecha" data-type="daterange">
                                    Fecha <i class="bi bi-funnel-fill filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="hora" data-type="none">Hora</th>
                                <th data-column="total_nio" data-type="number">
                                    Total NIO <i class="bi bi-funnel-fill filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="total_usd" data-type="number">
                                    Total USD <i class="bi bi-funnel-fill filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="total_global" data-type="number">
                                    Total Global <i class="bi bi-funnel-fill filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="observacion" data-type="text">
                                    Observación <i class="bi bi-funnel-fill filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="usuario" data-type="none">Registrado por</th>
                                <th data-column="vouchers" data-type="none" style="width:110px; text-align:center;">
                                    <i class="bi bi-camera me-1"></i>Vouchers
                                </th>
                            </tr>
                        </thead>
                        <tbody id="tablaAligBody">
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted">
                                    <i class="fas fa-spinner fa-spin me-2"></i>Cargando registros...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ====== PAGINACIÓN ====== -->
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="d-flex align-items-center gap-2">
                        <label class="mb-0">Mostrar:</label>
                        <select class="form-select form-select-sm" id="registrosPorPagina" style="width:auto;" onchange="cambiarRegistrosPorPagina()">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span class="mb-0">registros</span>
                    </div>
                    <div id="paginacion"></div>
                </div>

            </div><!-- /container-fluid -->
        </div><!-- /pos-content -->
    </div><!-- /pos-main-container -->

    <!-- ═══════════════════════════════════════════════════════════
         MODAL NUEVO ALIGERAMIENTO
    ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="modalNuevoAlig" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden;">

                <div class="modal-header border-0 py-3 px-4" style="background:#0E544C; color:#fff;">
                    <div class="d-flex align-items-center">
                        <div class="bg-white bg-opacity-25 rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                            <i class="bi bi-cash-stack fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold mb-0">Nuevo Aligeramiento</h5>
                            <div class="small opacity-75 mt-1">
                                <i class="bi bi-geo-alt-fill me-1"></i><?php echo htmlspecialchars($sucursalNombre); ?>
                                &nbsp;·&nbsp;
                                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($colaboradorNombre); ?>
                                &nbsp;·&nbsp;
                                <i class="bi bi-calendar3 me-1"></i><?php echo formatoFecha(date('Y-m-d')); ?>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <div class="ci-tc-badge" style="background:rgba(255,255,255,.15); border-color:rgba(255,255,255,.3); color:#fff; font-size:.82rem;">
                            <i class="bi bi-arrow-left-right"></i>
                            C$ <span id="tcDisplayModal"><?php echo number_format($tipoCambio, 2); ?></span> / USD
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>

                <div class="modal-body p-4" style="background:#f6f6f6;">

                    <!-- Observación -->
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-muted" style="letter-spacing:.05em;">
                            <i class="bi bi-chat-left-text me-1"></i>Observación (opcional)
                        </label>
                        <input type="text" id="inputObservacion" class="form-control"
                               placeholder="Ej: Aligeramiento de turno tarde..."
                               maxlength="500"
                               style="border-radius:8px;">
                    </div>

                    <!-- Campos ocultos para totales -->
                    <input type="hidden" id="hidModalTotalNIO" value="0">
                    <input type="hidden" id="hidModalTotalUSD" value="0">
                    <input type="hidden" id="hidModalTotalUSDenNIO" value="0">
                    <input type="hidden" id="hidModalTotalGlobal" value="0">

                    <!-- Tablas de denominaciones -->
                    <div class="alig-modal-layout">

                        <!-- CÓRDOBAS -->
                        <div class="ci-card">
                            <div class="ci-card-header">
                                <i class="bi bi-cash-coin"></i> Córdobas (NIO)
                            </div>
                            <div class="table-responsive">
                                <table class="ci-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40%">Moneda</th>
                                            <th class="text-center" style="width:30%">Cantidad</th>
                                            <th class="text-end" style="width:30%">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody id="modalNIOBody"><!-- JS --></tbody>
                                    <tfoot>
                                        <tr class="ci-subtotal-row">
                                            <td colspan="2" class="subtotal-label"><i class="bi bi-sigma me-1"></i>Total Córdobas</td>
                                            <td class="subtotal-value" id="modalSubtotalNIO">C$ 0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- DÓLARES -->
                        <div class="ci-card">
                            <div class="ci-card-header usd">
                                <i class="bi bi-currency-dollar"></i> Dólares (USD)
                            </div>
                            <div class="table-responsive">
                                <table class="ci-table">
                                    <thead>
                                        <tr>
                                            <th style="width:40%">Moneda</th>
                                            <th class="text-center" style="width:30%">Cantidad</th>
                                            <th class="text-end" style="width:30%">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody id="modalUSDBody"><!-- JS --></tbody>
                                    <tfoot>
                                        <tr class="ci-subtotal-row">
                                            <td colspan="2" class="subtotal-label"><i class="bi bi-sigma me-1"></i>Total Dólares</td>
                                            <td class="subtotal-value usd-val" id="modalSubtotalUSD">$ 0.00</td>
                                        </tr>
                                        <tr class="ci-subtotal-row">
                                            <td colspan="2" class="subtotal-label">
                                                <i class="bi bi-arrow-left-right me-1"></i>Dólares en Córdobas
                                                <small style="opacity:.7;">(TC: <?php echo number_format($tipoCambio, 2); ?>)</small>
                                            </td>
                                            <td class="subtotal-value" id="modalSubtotalUSDenNIO">C$ 0.00</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                    </div><!-- /alig-modal-layout -->

                    <!-- TOTAL GLOBAL -->
                    <div class="alig-total-bar">
                        <div class="global-label">
                            <i class="bi bi-wallet2"></i>
                            Total Aligeramiento
                        </div>
                        <div class="global-amount" id="modalTotalGlobal">C$ 0.00</div>
                    </div>

                </div><!-- /modal-body -->

                <div class="modal-footer border-0 px-4 py-3" style="background:#f6f6f6; gap:.75rem;">
                    <button type="button" class="ci-btn-limpiar" onclick="limpiarRegistro()">
                        <i class="bi bi-arrow-counterclockwise"></i> Limpiar
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancelar
                    </button>
                    <button type="button" class="ci-btn-guardar" id="btnGuardarAlig" onclick="guardarAligeramiento()">
                        <i class="bi bi-save2-fill"></i> Guardar
                    </button>
                </div>

            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         MODAL VOUCHERS IA
    ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="modalVouchers" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius:16px; overflow:hidden; position:relative;">

                <!-- Loader IA -->
                <div id="loaderVoucher" style="display:none; position:absolute; inset:0; background:rgba(255,255,255,.92); z-index:200; flex-direction:column; align-items:center; justify-content:center; border-radius:18px;">
                    <div class="loader-spinner"></div>
                    <h6 class="mt-3">Inteligencia Artificial Procesando...</h6>
                    <p>Analizando el voucher, por favor espera.</p>
                </div>

                <!-- Header -->
                <div class="modal-header border-0 py-3 px-4" style="background:#0E544C; color:#fff;">
                    <div class="d-flex align-items-center">
                        <div class="bg-white bg-opacity-25 rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                            <i class="fas fa-camera fs-4"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold mb-0">Vouchers del Aligeramiento</h5>
                            <div id="voucherAligInfo" class="small mb-0 opacity-75 mt-1"></div>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="cerrarModalVouchers()"></button>
                </div>

                <!-- Body -->
                <div class="modal-body p-4 bg-light">

                    <!-- Zona de subida -->
                    <div class="voucher-upload-zone mb-3">
                        <div class="d-flex align-items-center justify-content-center gap-3 flex-wrap">
                            <button type="button" class="btn-modern btn-modern-primary" onclick="abrirCamaraVoucher()">
                                <i class="fas fa-camera me-2"></i> Tomar Foto
                            </button>
                            <label class="btn-modern btn-modern-secondary mb-0" style="cursor:pointer;">
                                <i class="fas fa-upload me-2"></i> Subir Archivo
                                <input type="file" id="inputVoucherFile" accept="image/*" style="display:none;" onchange="procesarArchivoVoucher(this)">
                            </label>
                        </div>
                        <div class="upload-label">JPG, PNG o WEBP · Múltiples fotos permitidas</div>
                        <div class="voucher-nota-aclaratoria">
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Un voucher por foto.</strong> Si tienes varios recibos, fotografíalos por separado. El sistema los suma automáticamente.
                        </div>
                    </div>

                    <!-- Galería -->
                    <div class="mb-1" style="font-size:.78rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.06em;">
                        <i class="bi bi-images me-1"></i>Fotos Subidas
                    </div>
                    <div id="vouchersGallery"></div>

                    <!-- Panel de validación -->
                    <div id="validacionPanel" class="validacion-panel neutral mt-3" style="display:none;">
                        <div class="vp-title"><i class="fas fa-balance-scale me-1"></i>Validación de Monto</div>
                        <div id="validacionContent"></div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         MODAL CÁMARA PREMIUM
    ═══════════════════════════════════════════════════════════ -->
    <div class="modal fade" id="modalCamaraVoucher" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius:20px !important; overflow:hidden;">

                <div class="modal-header bg-dark text-white border-0 py-2 px-3">
                    <h6 class="modal-title mb-0"><i class="fas fa-camera me-2"></i>Capturar Voucher</h6>
                    <div class="d-flex align-items-center gap-2">
                        <span id="vcamFocusStatus" class="badge bg-secondary" style="font-size:.65rem;">AUTO</span>
                        <button type="button" class="btn-close btn-close-white" onclick="cerrarCamaraVoucher()"></button>
                    </div>
                </div>

                <div id="voucherCameraViewport" style="min-height:320px;">
                    <video id="voucherVideo" autoplay playsinline muted></video>
                    <div id="voucherCamGrid"></div>
                    <div id="voucherFocusRing"></div>
                    <div id="voucherFocusToast">Toca para enfocar</div>
                    <canvas id="voucherCanvas" style="display:none;"></canvas>
                </div>

                <div class="vcam-controls">
                    <div class="d-flex align-items-center justify-content-between mt-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 text-white border-secondary" onclick="cerrarCamaraVoucher()">
                            <i class="fas fa-times me-1"></i>Cancelar
                        </button>
                        <button type="button" class="btn-vcam-capture" onclick="capturarFotoVoucher()" title="Tomar foto">
                            <i class="fas fa-circle" style="color:#e74c3c;"></i>
                        </button>
                        <button type="button" id="btnVcamTorch" class="btn-vcam-torch" onclick="toggleLinternaVoucher()" title="Linterna" style="display:none;">
                            <i class="fas fa-bolt"></i>
                        </button>
                        <div id="btnVcamTorchPlaceholder" style="width:42px;"></div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Variables globales PHP → JS -->
    <script>
        window.TIPO_CAMBIO  = <?php echo json_encode($tipoCambio); ?>;
        window.SUCURSAL_ID  = <?php echo json_encode($sucursalId); ?>;
    </script>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/aligeramiento.js?v=<?php echo time(); ?>"></script>
</body>

</html>

