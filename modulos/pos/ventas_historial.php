<?php
require_once '../../core/auth/auth_pos.php';
posRequiereColaborador();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Ventas — POS</title>
    <meta name="description" content="Historial de ventas registradas en el POS">
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/facturas.css?v=<?php echo time(); ?>">
    <style>
        .badge-bloqueada { background:#e2e8f0; color:#475569; font-size:.75rem; padding:3px 10px; border-radius:20px; font-weight:600; }
        .badge-anulada   { background:#fee2e2; color:#b91c1c; font-size:.75rem; padding:3px 10px; border-radius:20px; font-weight:600; }
        .badge-club      { background:#d1fae5; color:#065f46; font-size:.72rem; padding:2px 8px;  border-radius:20px; font-weight:600; }
        .badge-general   { background:#eff6ff; color:#1d4ed8; font-size:.72rem; padding:2px 8px;  border-radius:20px; font-weight:600; }
        .metodo-tag      { display:inline-block; background:#f1f5f9; color:#475569; border-radius:4px; padding:1px 7px; font-size:.75rem; margin:1px; }
    </style>
</head>
<body>
    <?= renderPOSSidebar('ventas') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Historial de Ventas') ?>

        <div class="pos-content">
            <div class="container-fluid p-3">

                <!-- Barra superior -->
                <div class="d-flex justify-content-end align-items-center mb-3 flex-wrap gap-2">
                    <a href="facturas_nueva.php" class="btn btn-success d-inline-flex align-items-center gap-2">
                        <i class="bi bi-plus-circle"></i> Nueva Venta
                    </a>
                </div>

                <!-- Tabla -->
                <div class="table-responsive">
                    <table class="table table-hover facturas-table" id="tablaVentas">
                        <thead>
                            <tr>
                                <th data-column="id" data-type="text">
                                    # Venta
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="fecha_hora" data-type="daterange">
                                    Fecha / Hora
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="cliente" data-type="text">
                                    Cliente
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="tipo_cliente" data-type="list">
                                    Tipo
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th class="text-end" data-column="total" data-type="number">
                                    Total (C$)
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="metodos_pago" data-type="text">
                                    Método de Pago
                                </th>
                                <th data-column="estado" data-type="list">
                                    Estado
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="cajero" data-type="text">
                                    Cajero
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th style="width:80px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaVentasBody">
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <div class="spinner-border spinner-border-sm me-2"></div>
                                    Cargando…
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación + registros por página -->
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <label class="mb-0" style="font-size:.85rem;">Mostrar:</label>
                        <select class="form-select form-select-sm" id="registrosPorPagina" style="width:auto;" onchange="cambiarRegistrosPorPagina()">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span style="font-size:.85rem;">registros</span>
                    </div>
                    <div id="paginacion" class="paginacion-container"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Ver Detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1" aria-labelledby="modalDetalleLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0" style="border-radius:10px; overflow:hidden;">
                <div class="modal-detalle-header">
                    <h5 class="modal-title" id="modalDetalleLabel">
                        <i class="bi bi-receipt me-2"></i>Detalle de Venta
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="margin-left:auto;"></button>
                </div>

                <div class="modal-body p-0">
                    <!-- Info cabecera -->
                    <div class="px-4 py-3 border-bottom bg-light">
                        <div class="row g-2" id="infoCabeceraModal">
                            <!-- Llenado por JS -->
                        </div>
                    </div>

                    <!-- Detalle productos -->
                    <div class="p-3">
                        <table class="table table-sm mb-0 tabla-modal-detalle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Producto</th>
                                    <th class="text-end">Cant.</th>
                                    <th class="text-end">Precio Unit.</th>
                                    <th class="text-end">Total Línea</th>
                                </tr>
                            </thead>
                            <tbody id="tablaDetalleModal">
                                <tr><td colspan="5" class="text-center text-muted">Cargando…</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagos -->
                    <div class="px-3 pb-3" id="seccionPagos" style="display:none;">
                        <hr class="my-2">
                        <strong style="font-size:.85rem;">Pagos registrados:</strong>
                        <div id="listaPagos" class="mt-1"></div>
                    </div>
                </div>

                <div class="modal-footer border-top">
                    <div class="d-flex justify-content-between align-items-center w-100">
                        <span class="text-muted" id="notasModal" style="font-size:.85rem;"></span>
                        <span class="texto-total-modal" id="totalModal"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/ventas_historial.js?v=<?php echo time(); ?>"></script>
</body>
</html>

