<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
posRequiereColaborador();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';

$hoy = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Factura — POS</title>
    <meta name="description" content="Crear nuevo registro de factura de compra o abastecimiento de tienda">
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/facturas.css?v=<?php echo time(); ?>">
</head>
<body>
    <?= renderPOSSidebar('ventas') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Nueva Factura') ?>

        <div class="pos-content">
            <div class="container-fluid p-3">
                <div class="facturas-layout">

                    <!-- ====== COLUMNA IZQUIERDA ====== -->
                    <div class="d-flex flex-column gap-3">

                        <!-- Cabecera de Cliente (Club Pitaya vs Cliente General) -->
                        <div class="card-factura card-cliente-pos">
                            <div class="card-body-factura py-3">
                                <form id="formFactura" class="form-cabecera m-0">
                                    <!-- Selector de tipo de cliente -->
                                    <div class="tipo-cliente-selector mb-3 d-flex align-items-center gap-4">
                                        <label class="custom-radio-cliente">
                                            <input type="radio" name="tipo_cliente" value="club" checked id="tipoClienteClub">
                                            <span class="radio-mark"></span>
                                            <span class="radio-label">CLUB PITAYA</span>
                                        </label>
                                        <label class="custom-radio-cliente">
                                            <input type="radio" name="tipo_cliente" value="general" id="tipoClienteGeneral">
                                            <span class="radio-mark"></span>
                                            <span class="radio-label">CLIENTE GENERAL</span>
                                        </label>
                                    </div>

                                    <div class="row g-3 align-items-start">
                                        <!-- Campo N° Membresía (Solo Club Pitaya) -->
                                        <div class="col-md-3" id="colMembresia">
                                            <label for="membresiaCliente" class="form-label-pos">N° MEMBRESÍA</label>
                                            <div class="input-group input-group-pos">
                                                <input type="text" class="form-control form-control-pos" id="membresiaCliente"
                                                       name="membresia" placeholder="Ej: 9872" autocomplete="off">
                                                <button class="btn btn-pos-search" type="button" id="btnBuscarMembresia" title="Buscar / Ver Historial">
                                                    <i class="bi bi-search"></i>
                                                </button>
                                            </div>
                                            <button type="button" class="btn-pos-qr mt-2 w-100" id="btnLeerQR">
                                                <i class="bi bi-qr-code-scan me-1"></i> LEER POR QR
                                            </button>
                                        </div>

                                        <!-- Campo Nombre del Cliente -->
                                        <div class="col-md-5" id="colNombreCliente">
                                            <label for="nombreCliente" class="form-label-pos">NOMBRE CLIENTE</label>
                                            <input type="text" class="form-control form-control-pos" id="nombreCliente"
                                                   name="nombre_cliente" placeholder="Nombre del cliente..." readonly style="background-color: #f8fafc;">
                                            <input type="hidden" id="idClienteClub" name="id_cliente_club" value="">
                                            <input type="hidden" id="fechaFactura" name="fecha" value="<?php echo $hoy; ?>">
                                            <input type="hidden" id="numeroFactura" name="numero_factura" value="AUTO">

                                            <div id="clienteFeedback" class="small mt-1 text-muted"></div>
                                        </div>

                                        <!-- Indicadores de Puntos (Solo Club Pitaya) -->
                                        <div class="col-md-4" id="colIndicadoresPuntos">
                                            <div class="box-indicadores-puntos">
                                                <div class="d-flex justify-content-between row-puntos">
                                                    <span class="lbl-puntos">PUNTOS ANTERIORES</span>
                                                    <span class="val-puntos" id="txtPuntosAnteriores">0</span>
                                                </div>
                                                <div class="d-flex justify-content-between row-puntos">
                                                    <span class="lbl-puntos">PUNTOS ESTA FACTURA</span>
                                                    <span class="val-puntos text-success" id="txtPuntosEstaFactura">+0</span>
                                                </div>
                                                <div class="d-flex justify-content-between row-puntos pt-1 border-top mt-1">
                                                    <span class="lbl-puntos fw-bold text-dark">TOTAL PUNTOS</span>
                                                    <span class="val-puntos fw-bold text-dark" id="txtTotalPuntos">0</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Tabla de productos agregados -->
                        <div class="card-factura">
                            <div class="card-header-factura">
                                <i class="bi bi-list-ul"></i>
                                Productos en la Factura
                                <span id="contadorProductos" class="ms-auto badge"
                                      style="background:rgba(255,255,255,.2); font-size:.78rem;">0 ítem(s)</span>
                            </div>
                            <div class="card-body-factura p-0">
                                <div class="table-responsive">
                                    <table class="tabla-detalle-factura">
                                        <thead>
                                            <tr>
                                                <th style="width:35%;">Producto / Servicio</th>
                                                <th style="width:16%;" class="text-end">Cantidad</th>
                                                <th style="width:22%;" class="text-end">Costo Total c/IVA</th>
                                                <th style="width:20%;" class="text-end">Costo Unitario</th>
                                                <th style="width:7%;" class="text-center">Quitar</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tablaDetalleBody">
                                            <tr id="filaVacia">
                                                <td colspan="5" class="empty-detalle">
                                                    <i class="bi bi-box-seam me-2"></i>
                                                    Selecciona productos desde el panel derecho
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- Total bar -->
                                <div class="total-factura-bar">
                                    <span class="total-factura-label">Total Factura:</span>
                                    <span class="total-factura-monto" id="totalFacturaMonto">C$ 0.00</span>
                                </div>

                                <!-- Botones de acción -->
                                <div class="p-3 border-top bg-light d-flex gap-2 justify-content-end">
                                    <a href="facturas_historial.php" class="btn-cancelar-factura">
                                        <i class="bi bi-x-circle"></i> Cancelar
                                    </a>
                                    <button type="button" class="btn-guardar-factura" id="btnGuardar">
                                        <i class="bi bi-save2-fill"></i> Guardar Factura
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div><!-- /columna izquierda -->

                    <!-- ====== COLUMNA DERECHA: productos elegibles ====== -->
                    <div class="panel-productos-elegibles">
                        <div class="card-factura">
                            <div class="card-header-factura">
                                <i class="bi bi-grid"></i>
                                Productos Disponibles
                            </div>
                            <div class="card-body-factura">
                                <div class="buscador-productos">
                                    <i class="bi bi-search icon-search"></i>
                                    <input type="text" id="buscarProducto"
                                           placeholder="Buscar producto…"
                                           autocomplete="off">
                                </div>
                                <div class="lista-productos-scroll" id="listaProductosElegibles">
                                    <p class="no-productos-msg">
                                        <span class="spinner-border spinner-border-sm me-1"></span>
                                        Cargando productos…
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div><!-- /columna derecha -->

                </div><!-- /facturas-layout -->
            </div>
        </div>
    </div>

    <!-- Modal Historial de Productos — Clientes Club -->
    <div class="modal fade" id="modalHistorialCliente" tabindex="-1" aria-labelledby="modalHistorialClienteLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg modal-pos-custom">
                <div class="modal-header modal-header-pos">
                    <div class="d-flex align-items-center gap-3">
                        <div class="modal-icon-badge">
                            <i class="bi bi-person-vcard"></i>
                        </div>
                        <div>
                            <h5 class="modal-title mb-0" id="modalHistorialClienteLabel">Historial de Productos — Club Pitaya</h5>
                            <span class="modal-subtitle-pos" id="modalSubtituloCliente">Cargando datos...</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <!-- Resumen del Socio -->
                    <div class="cliente-resumen-banner mb-3 p-3 rounded-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="text-muted small d-block">CLIENTE SOCIO</span>
                            <strong class="fs-5 text-dark" id="modalNombreCliente">—</strong>
                        </div>
                        <div class="d-flex gap-4">
                            <div>
                                <span class="text-muted small d-block">N° MEMBRESÍA</span>
                                <strong class="fs-6 text-dark" id="modalMembresiaCliente">—</strong>
                            </div>
                            <div>
                                <span class="text-muted small d-block">SUCURSAL REGISTRO</span>
                                <strong class="fs-6 text-dark" id="modalSucursalCliente">—</strong>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small d-block">SALDO HISTÓRICO</span>
                                <span class="badge badge-puntos-total fs-6" id="modalPuntosCliente">0 pts</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tabla de las 6 columnas exactas -->
                    <div class="table-responsive rounded-3 border bg-white shadow-sm">
                        <table class="table table-hover align-middle mb-0 tabla-historial-pos">
                            <thead>
                                <tr>
                                    <th style="width: 18%;">Sucursal</th>
                                    <th style="width: 22%;">Fecha / Hora</th>
                                    <th style="width: 28%;">Producto / Medida</th>
                                    <th style="width: 10%;" class="text-center">Cantidad</th>
                                    <th style="width: 12%;">Promoción</th>
                                    <th style="width: 10%;" class="text-end">Puntos Acum.</th>
                                </tr>
                            </thead>
                            <tbody id="tablaHistorialBody">
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        <span class="spinner-border spinner-border-sm me-2"></span> Cargando historial...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación -->
                    <div class="d-flex justify-content-between align-items-center mt-3" id="modalPaginacionContainer">
                        <small class="text-muted" id="modalPaginacionInfo">Mostrando 0 registros</small>
                        <nav aria-label="Paginación del historial">
                            <ul class="pagination pagination-sm mb-0 gap-1" id="modalPaginacionList"></ul>
                        </nav>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0 py-3">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/facturas_nueva.js?v=<?php echo time(); ?>"></script>
</body>
</html>
