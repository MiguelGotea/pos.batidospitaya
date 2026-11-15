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
    <title>Nueva Factura de Compra — POS</title>
    <meta name="description" content="Registro de nueva factura de compra">
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/facturas.css?v=<?php echo time(); ?>">
</head>
<body>
    <?= renderPOSSidebar('compras') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Nueva Factura de Compra') ?>

        <div class="pos-content pos-content-fluid">

            <!-- Barra superior -->
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <a href="facturas_historial.php" class="btn-cancelar-factura">
                    <i class="bi bi-arrow-left"></i> Volver al Historial
                </a>
                <button id="btnGuardar" class="btn-guardar-factura" onclick="guardarFactura()">
                    <i class="bi bi-floppy me-1"></i>Guardar Factura
                </button>
            </div>

            <!-- Layout split -->
            <div class="facturas-layout">

                <!-- ======== Panel izquierdo: Formulario + Detalle ======== -->
                <div>
                    <!-- Cabecera de la factura -->
                    <div class="card-factura mb-3">
                        <div class="card-header-factura">
                            <i class="bi bi-receipt"></i> Datos de la Factura
                        </div>
                        <div class="card-body-factura">
                            <form class="form-cabecera" onsubmit="return false;">
                                <div class="row g-3">

                                    <div class="col-sm-6">
                                        <label class="form-label" for="numeroFactura">N° de Factura</label>
                                        <input type="text" id="numeroFactura" class="form-control"
                                               placeholder="Ej: FAC-001234" maxlength="80" autocomplete="off">
                                    </div>

                                    <div class="col-sm-6">
                                        <label class="form-label" for="fecha">Fecha de Factura</label>
                                        <input type="date" id="fecha" class="form-control">
                                    </div>

                                    <div class="col-sm-12">
                                        <label class="form-label" for="idProveedor">Proveedor</label>
                                        <select id="idProveedor" class="form-select">
                                            <option value="">— Seleccionar proveedor —</option>
                                        </select>
                                    </div>

                                    <div class="col-sm-12">
                                        <label class="form-label" for="notas">Notas (opcional)</label>
                                        <textarea id="notas" class="form-control" rows="2"
                                                  placeholder="Observaciones de la factura…" maxlength="500"></textarea>
                                    </div>

                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Tabla de detalle -->
                    <div class="card-factura">
                        <div class="card-header-factura">
                            <i class="bi bi-list-ul"></i> Detalle de Productos
                        </div>
                        <div class="card-body-factura p-0">
                            <div class="table-responsive">
                                <table class="tabla-detalle-factura w-100">
                                    <thead>
                                        <tr>
                                            <th style="width:36px;">#</th>
                                            <th>Producto</th>
                                            <th style="width:110px;" class="text-center">Cantidad</th>
                                            <th style="width:130px;" class="text-center">Costo c/IVA (C$)</th>
                                            <th style="width:120px;" class="text-end">Subtotal</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tablaDetalleBody">
                                        <tr>
                                            <td colspan="6" class="empty-detalle">
                                                <i class="bi bi-cart-x me-1"></i>Agregue productos desde el panel derecho.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Total -->
                        <div class="total-factura-bar">
                            <span class="total-factura-label">Total Factura:</span>
                            <span class="total-factura-monto" id="totalFactura">C$ 0.00</span>
                        </div>
                    </div>
                </div>

                <!-- ======== Panel derecho: Buscador de productos ======== -->
                <div class="panel-factura-derecho">
                    <div class="card-factura">
                        <div class="card-header-factura">
                            <i class="bi bi-box-seam"></i> Agregar Productos
                        </div>
                        <div class="card-body-factura">

                            <div class="buscador-productos">
                                <i class="bi bi-search icon-search"></i>
                                <input type="text" id="buscadorProducto"
                                       placeholder="Buscar producto…" autocomplete="off">
                            </div>

                            <div class="lista-productos-scroll" id="listaProductos">
                                <p class="no-productos-msg">
                                    <span class="spinner-border spinner-border-sm me-1"></span>Cargando…
                                </p>
                            </div>

                        </div>
                    </div>
                </div>

            </div><!-- /facturas-layout -->

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/facturas_nueva.js?v=<?php echo time(); ?>"></script>
</body>
</html>
