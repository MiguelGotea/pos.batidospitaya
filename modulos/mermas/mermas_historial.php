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
    <title>Historial de Mermas — POS</title>
    <meta name="description" content="Historial de registros de mermas de tienda">
    <link rel="icon" href="/core/assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/mermas.css?v=<?php echo time(); ?>">
</head>
<body>
    <?= renderPOSSidebar('mermas') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Historial de Mermas') ?>

        <div class="pos-content">
            <div class="container-fluid p-3">

                <!-- Barra superior -->
                <div class="d-flex justify-content-end align-items-center mb-3 flex-wrap gap-2">
                    <a href="mermas_nueva.php" class="btn-merma-nueva">
                        <i class="bi bi-plus-circle-fill"></i> Nueva Merma
                    </a>
                </div>

                <!-- Tabla -->
                <div class="table-responsive">
                    <table class="table table-hover mermas-table" id="tablaMermas">
                        <thead>
                            <tr>
                                <th data-column="fecha_hora" data-type="daterange">
                                    Fecha / Hora
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="tipo_incidencia" data-type="list">
                                    Tipo de Incidencia
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="categoria_merma" data-type="list">
                                    Categoría
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="producto" data-type="text">
                                    Producto
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th class="text-end" data-column="cantidad" data-type="number">
                                    Cantidad
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="registrado_por" data-type="text">
                                    Registrado por
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th style="width:80px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaMermasBody">
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
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

    <!-- Modal detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1" aria-labelledby="modalDetalleLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content border-0" style="border-radius:10px; overflow:hidden;">
                <div class="modal-detalle-header">
                    <h5 class="modal-title" id="modalDetalleLabel">
                        <i class="fas fa-box-open me-2"></i>Detalle de Merma
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
                    <!-- Detalle producto -->
                    <div class="p-3">
                        <table class="table table-sm mb-0 tabla-modal-detalle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Producto</th>
                                    <th class="text-end">Cantidad</th>
                                    <th>Observación</th>
                                </tr>
                            </thead>
                            <tbody id="tablaDetalleModal">
                                <tr><td colspan="4" class="text-center text-muted">Cargando…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer border-top">
                    <span class="text-muted" id="notasModal" style="font-size:.85rem;"></span>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/mermas_historial.js?v=<?php echo time(); ?>"></script>
</body>
</html>
