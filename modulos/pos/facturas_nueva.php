<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
posRequiereColaborador();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';

$hoy = date('Y-m-d');

// Cargar tipo de cambio USD vigente desde pos_ventas_tipo_cambio
$tipoCambioUSD_php = 0;
try {
    if (!isset($conn)) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
    }
    $_tcStmt = $conn->prepare(
        "SELECT tasa FROM pos_ventas_tipo_cambio WHERE fecha_desde <= CURDATE() ORDER BY fecha_desde DESC LIMIT 1"
    );
    $_tcStmt->execute();
    $_tcRow = $_tcStmt->fetch(PDO::FETCH_ASSOC);
    $tipoCambioUSD_php = $_tcRow ? (float)$_tcRow['tasa'] : 0;
} catch (Exception $_tcEx) {
    $tipoCambioUSD_php = 0;
}
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
    <link rel="stylesheet" href="css/promociones.css?v=<?php echo time(); ?>">
</head>

<body>
    <?= renderPOSSidebar('ventas') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Nueva Factura') ?>

        <div class="pos-content pos-content-fluid">
            <div class="container-fluid px-0">
                <div class="facturas-layout">

                    <!-- ====== COLUMNA IZQUIERDA: Catálogo, Membresía y Promociones ====== -->
                    <div class="col-catalogo-promos d-flex flex-column gap-3">

                        <!-- Panel Catálogo de Productos -->
                        <div class="panel-catalogo-productos">

                            <!-- Buscador rápido -->
                            <div class="catalogo-header">
                                <div class="buscador-catalogo-wrap">
                                    <i class="bi bi-search buscador-catalogo-icon"></i>
                                    <input type="text" id="buscarProducto" class="buscador-catalogo-input"
                                        placeholder="Buscar producto por nombre, código..." autocomplete="off">
                                    <button class="buscador-catalogo-clear" id="btnLimpiarBusqueda" type="button" style="display:none;">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Tabs de Grupos -->
                            <div class="grupos-tabs-bar" id="gruposTabs">
                                <span class="spinner-border spinner-border-sm text-secondary m-2"></span>
                            </div>

                            <!-- Área de productos (subgrupos + cards o resultados de búsqueda) -->
                            <div class="catalogo-content-area" id="catalogoPanel">
                                <p class="no-productos-msg">
                                    <span class="spinner-border spinner-border-sm me-1"></span>
                                    Cargando catálogo…
                                </p>
                            </div>

                        </div><!-- /panel-catalogo-productos -->

                        <!-- Banner de Quick Add para Membresía Club Pitaya -->
                        <div class="banner-club-pitaya-pos" id="bannerClubPitaya">
                            <div class="banner-club-info">
                                <div class="banner-club-icon">
                                    <i class="bi bi-star-fill"></i>
                                </div>
                                <div class="banner-club-text">
                                    <h6 class="banner-club-titulo mb-0">Vender Membresía Club Pitaya</h6>
                                    <span class="banner-club-sub">¡Suma Puntos Ahora! Beneficios exclusivos para el cliente.</span>
                                </div>
                            </div>
                            <button type="button" class="btn-quick-add-membresia" id="btnQuickAddMembresia" title="Agregar membresía a la orden">
                                QUICK ADD <i class="bi bi-plus-lg ms-1"></i>
                            </button>
                        </div>

                        <!-- Sección de Promociones Dinámicas (carga desde BD) -->
                        <div class="seccion-promociones-pos" id="seccionPromociones">
                            <div class="promociones-header-pos mb-2 d-flex align-items-center justify-content-between">
                                <span class="promociones-main-title">PROMOCIONES</span>
                                <button type="button" class="btn-refrescar-promos" id="btnRefrescarPromos" title="Evaluar promociones con el pedido actual">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>

                            <!-- Estado de carga -->
                            <div id="promosLoadingState" class="promos-estado-carga">
                                <span class="spinner-border spinner-border-sm me-1 text-secondary"></span>
                                <span class="text-muted small">Cargando promociones…</span>
                            </div>

                            <!-- Estado vacío (sin promos activas) -->
                            <div id="promosEmptyState" class="promos-estado-vacio" style="display:none;">
                                <i class="bi bi-tag-x text-muted"></i>
                                <span class="text-muted small">No hay promociones activas en este momento.</span>
                            </div>

                            <!-- Bloque: Disponibles (todas las activas vigentes) -->
                            <div class="promociones-bloque mb-3" id="bloquePromosTodas" style="display:none;">
                                <div class="promociones-sub-label">DISPONIBLES</div>
                                <div class="promociones-grid-recomendados" id="gridPromosTodas">
                                    <!-- Generado dinámicamente por JS -->
                                </div>
                            </div>

                            <!-- Bloque: Aplican ahora (carrito cumple condiciones) -->
                            <div class="promociones-bloque mb-3" id="bloquePromosAplican" style="display:none;">
                                <div class="promociones-sub-label">APLICAN AHORA</div>
                                <div class="promociones-grid-aplicables" id="gridPromosAplican">
                                    <!-- Generado dinámicamente por JS -->
                                </div>
                            </div>

                            <!-- Bloque: Potenciales (el carrito está cerca de calificar) -->
                            <div class="promociones-bloque" id="bloquePromosPotenciales" style="display:none;">
                                <div class="promociones-sub-label">CASI CALIFICAN</div>
                                <div class="promociones-grid-recomendados" id="gridPromosPotenciales">
                                    <!-- Generado dinámicamente por JS -->
                                </div>
                            </div>

                        </div><!-- /seccion-promociones-pos -->

                    </div><!-- /col-catalogo-promos -->

                    <!-- ====== COLUMNA DERECHA: Factura ====== -->
                    <div class="panel-factura-derecho d-flex flex-column gap-2">

                        <!-- Cabecera de Cliente (Club Pitaya vs Cliente General) -->
                        <div class="card-factura card-cliente-pos">
                            <div class="card-body-factura py-2">
                                <form id="formFactura" class="form-cabecera m-0">
                                    <div class="tipo-cliente-selector mb-2 d-flex align-items-center gap-3">
                                        <label class="custom-radio-cliente">
                                            <input type="radio" name="tipo_cliente" value="club" id="tipoClienteClub">
                                            <span class="radio-mark"></span>
                                            <span class="radio-label">CLUB PITAYA</span>
                                        </label>
                                        <label class="custom-radio-cliente">
                                            <input type="radio" name="tipo_cliente" value="general" checked id="tipoClienteGeneral">
                                            <span class="radio-mark"></span>
                                            <span class="radio-label">CLIENTE GENERAL</span>
                                        </label>
                                    </div>

                                    <!-- Fila: Membresía + Nombre -->
                                    <div class="row g-2 align-items-start mb-2">
                                        <div class="col-sm-5 col-12" id="colMembresia" style="display: none;">
                                            <label for="membresiaCliente" class="form-label-pos">N° MEMBRESÍA</label>
                                            <div class="input-group input-group-pos">
                                                <input type="text" class="form-control form-control-pos" id="membresiaCliente"
                                                    name="membresia" placeholder="Ej: 9872" autocomplete="off">
                                                <button class="btn btn-pos-search" type="button" id="btnBuscarMembresia" title="Buscar / Ver Historial">
                                                    <i class="bi bi-search"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="col-12" id="colNombreCliente">
                                            <label for="nombreCliente" class="form-label-pos">
                                                NOMBRE CLIENTE
                                                <span id="clienteCheckVerificado" style="display:none;" class="check-verificado-badge" title="Miembro Club Pitaya verificado">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                </span>
                                            </label>
                                            <input type="text" class="form-control form-control-pos" id="nombreCliente"
                                                name="nombre_cliente" placeholder="Escriba nombre del cliente casual..." style="background-color: #fff;">
                                            <input type="hidden" id="idClienteClub" name="id_cliente_club" value="">
                                            <input type="hidden" id="fechaFactura" name="fecha" value="<?php echo $hoy; ?>">
                                            <input type="hidden" id="numeroFactura" name="numero_factura" value="AUTO">
                                        </div>
                                    </div>

                                    <!-- Barra compacta de Puntos (solo Club Pitaya) -->
                                    <div id="colIndicadoresPuntos" style="display: none;">
                                        <div class="puntos-compact-bar">
                                            <div class="puntos-stat-item">
                                                <span class="pstat-lbl">ANTERIORES</span>
                                                <span class="pstat-val" id="txtPuntosAnteriores">0</span>
                                            </div>
                                            <div class="puntos-stat-item">
                                                <span class="pstat-lbl">ESTA FACTURA</span>
                                                <span class="pstat-val text-success" id="txtPuntosEstaFactura">+0</span>
                                            </div>
                                            <div class="puntos-stat-item puntos-total">
                                                <span class="pstat-lbl">TOTAL PUNTOS</span>
                                                <span class="pstat-val fw-bold" id="txtTotalPuntos">0</span>
                                            </div>
                                        </div>
                                        <!-- Alerta de saldo insuficiente de puntos Club Pitaya -->
                                        <div id="alertaPuntosSaldo"></div>
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
                                                <th style="width:42%;">PRODUCTO</th>
                                                <th style="width:20%;" class="text-center">CANT.</th>
                                                <th style="width:20%;" class="text-end">PRECIO</th>
                                                <th style="width:12%;" class="text-center">CANJEADO</th>
                                                <th style="width:6%;" class="text-center"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="tablaDetalleBody">
                                            <tr id="filaVacia">
                                                <td colspan="5" class="empty-detalle">
                                                    <i class="bi bi-box-seam me-2"></i>
                                                    Selecciona productos desde el catálogo
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- Sección Métodos de Pago y Desglose -->
                                <div class="seccion-metodos-pago px-3 py-2 border-top">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="titulo-seccion-pago">MÉTODO DE PAGO</span>
                                        <small class="text-muted" style="font-size: .72rem;">Clic para agregar/combinar</small>
                                    </div>
                                    <div class="metodos-pago-grid mb-2">
                                        <button type="button" class="btn-metodo-pago" id="btnPagoEfectivo" onclick="agregarMetodoPago('efectivo')">
                                            <i class="bi bi-cash-stack"></i>
                                            <span>EFECTIVO</span>
                                        </button>
                                        <button type="button" class="btn-metodo-pago" id="btnPagoPOS" onclick="agregarMetodoPago('pos')">
                                            <i class="bi bi-credit-card-2-front"></i>
                                            <span>POS</span>
                                        </button>
                                        <button type="button" class="btn-metodo-pago" id="btnPagoTransferencia" onclick="agregarMetodoPago('transferencia')">
                                            <i class="bi bi-bank"></i>
                                            <span>TRANSF.</span>
                                        </button>
                                        <button type="button" class="btn-metodo-pago" id="btnPagoCupon" onclick="agregarMetodoPago('cupon')" title="Agregar pago con cupón de descuento">
                                            <i class="bi bi-ticket-perforated"></i>
                                            <span>CUPÓN</span>
                                        </button>
                                        <button type="button" class="btn-metodo-pago" id="btnPagoEfectivoDolar" onclick="agregarMetodoPago('efectivo_dolar')" title="Pago en efectivo dólares USD">
                                            <i class="bi bi-cash-coin"></i>
                                            <span>EFEC. $</span>
                                        </button>
                                    </div>

                                    <!-- Lista de pagos activos agregados -->
                                    <div id="contenedorFilasPago" class="contenedor-filas-pago mb-2">
                                        <!-- Se agregan dinámicamente -->
                                    </div>

                                    <!-- Desglose de totales y Propina -->
                                    <div class="desglose-totales-pago">
                                        <div class="fila-desglose">
                                            <span class="lbl-desglose">Subtotal</span>
                                            <span class="val-desglose" id="txtSubtotalFactura">C$ 0.00</span>
                                        </div>
                                        <div class="fila-desglose align-items-center">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="lbl-desglose mb-0">Propina (2%)</span>
                                                <div class="form-check form-switch m-0 p-0 d-flex align-items-center">
                                                    <input class="form-check-input switch-propina-pitaya" type="checkbox" id="switchPropina">
                                                </div>
                                            </div>
                                            <span class="val-desglose" id="txtMontoPropina">C$ 0.00</span>
                                        </div>
                                        <div class="fila-desglose fila-total-destacado border-top pt-2 mt-1">
                                            <span class="lbl-total-pagar">TOTAL A PAGAR</span>
                                            <span class="val-total-pagar" id="totalFacturaMonto">C$ 0.00</span>
                                        </div>
                                        <!-- Estado de cobertura de pagos -->
                                        <div id="alertaCoberturaPagos" class="alerta-cobertura mt-2" style="display: none;"></div>
                                    </div>
                                </div>

                                <div class="px-3 py-2 border-top bg-light d-flex gap-2 justify-content-end">
                                    <a href="facturas_historial.php" class="btn-cancelar-factura">
                                        <i class="bi bi-x-circle"></i> Cancelar
                                    </a>
                                    <button type="button" class="btn-guardar-factura" id="btnGuardar">
                                        <i class="bi bi-save2-fill"></i> Guardar Factura
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div><!-- /panel-factura-derecho -->

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
                    <!-- Resumen del Miembro Club -->
                    <div class="cliente-resumen-banner mb-3 p-3 rounded-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="text-muted small d-block">MIEMBRO CLUB PITAYA</span>
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
                    <button type="button" class="btn-cancelar-factura px-4" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== MODAL: Selección de Producto y Personalización de Receta ====== -->
    <div class="modal fade" id="modalPersonalizacion" tabindex="-1" aria-labelledby="modalPersonalizacionLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg modal-pos-custom">
                <div class="modal-header modal-header-pos">
                    <div class="d-flex align-items-center gap-3">
                        <div class="modal-icon-badge">
                            <i class="bi bi-sliders2"></i>
                        </div>
                        <div>
                            <h5 class="modal-title mb-0" id="modalPersonalizacionLabel">Seleccionar Producto</h5>
                            <span class="modal-subtitle-pos" id="mpSubtitulo">Elige una opción y ajusta tus preferencias</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <!-- Selector horizontal de producto de la clasificación (visible al venir del catálogo) -->
                <div id="mpSelectorProductos" class="mp-selector-productos-wrap px-3 pt-3">
                    <!-- Generado dinámicamente por JS -->
                </div>

                <div class="modal-body p-0 bg-light" id="mpCuerpo">
                    <!-- Contenido generado por JS -->
                    <div class="d-flex align-items-center justify-content-center py-5">
                        <span class="spinner-border spinner-border-sm me-2 text-secondary"></span>
                        <span class="text-muted">Elige un producto arriba para ver sus opciones…</span>
                    </div>
                </div>

                <div class="modal-footer bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <!-- Indicador de ajuste de precio por extras -->
                    <span class="text-muted small" id="mpAjustePrecio" style="display:none;">
                        <i class="bi bi-plus-circle text-success me-1"></i>
                        Extras: <strong id="mpMontoExtras">C$ 0.00</strong>
                    </span>
                    <div class="d-flex gap-2 ms-auto">
                        <button type="button" class="btn-cancelar-factura px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn-guardar-factura px-4" id="mpBtnGuardar">
                            <i class="bi bi-check2-circle me-1"></i> Aplicar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- DEBUG T/C: valor PHP = <?php echo $tipoCambioUSD_php; ?> -->
    <script>
        // Tipo de cambio USD/NIO vigente — inyectado desde PHP, no editable por el usuario
        const TIPO_CAMBIO_USD_INICIAL = <?php echo json_encode($tipoCambioUSD_php); ?>;
        // ID de sucursal del POS -- para evaluar condiciones de promociones
        const POS_SUCURSAL_ID = <?php echo json_encode($_SESSION['pos_store_sucursal'] ?? $_SESSION['pos_device_sucursal'] ?? ''); ?>;
    </script>
    <script src="js/facturas_nueva.js?v=<?php echo time(); ?>"></script>
</body>

</html>
