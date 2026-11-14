<?php
require_once '../../core/auth/auth_pos.php';
posRequiereColaborador();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';

// ── Cargar menús disponibles para esta tienda ──────────────────────────
$menus_disponibles = [];
$sucursal_hist = $_SESSION['pos_store_sucursal'] ?? null;
try {
    if (!isset($conn)) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
    }
    $_mStmt = $conn->prepare(
        "SELECT m.id, m.nombre,
                GROUP_CONCAT(DISTINCT tc.nombre ORDER BY tc.nombre SEPARATOR ', ') AS tipos_cliente
         FROM pos_menu m
         JOIN pos_menu_precios p ON p.id_menu = m.id
         LEFT JOIN pos_tipos_cliente tc ON tc.id = p.id_tipo_cliente
         WHERE (p.cod_sucursal = :suc OR p.cod_sucursal IS NULL)
           AND p.fecha_desde <= CURDATE()
           AND (p.fecha_hasta IS NULL OR p.fecha_hasta >= CURDATE())
         GROUP BY m.id, m.nombre
         ORDER BY m.nombre ASC"
    );
    $_mStmt->execute([':suc' => $sucursal_hist]);
    $menus_disponibles = $_mStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $_mEx) {
    $menus_disponibles = [];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Ventas — POS</title>
    <meta name="description" content="Historial de ventas registradas en el POS">
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/facturas.css?v=<?php echo time(); ?>">
    <style>
        .badge-bloqueada {
            background: #e2e8f0;
            color: #475569;
            font-size: .75rem;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge-anulada {
            background: #fee2e2;
            color: #b91c1c;
            font-size: .75rem;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge-club {
            background: #d1fae5;
            color: #065f46;
            font-size: .72rem;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge-general {
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .72rem;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .metodo-tag {
            display: inline-block;
            background: #f1f5f9;
            color: #475569;
            border-radius: 4px;
            padding: 1px 7px;
            font-size: .75rem;
            margin: 1px;
        }

        .pos-actions {
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            flex-wrap: wrap;
        }

        .pos-action-btn {
            padding: 14px 28px;
            border-radius: 13px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            font-family: inherit;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            transition: .2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .pos-action-btn:hover {
            background: var(--surface-hover);
            border-color: #51B8AC;
        }

        .pos-action-btn.primary {
            background: #51B8AC;
            color: #06110f;
            border-color: #51B8AC;
        }

        .pos-action-btn.primary:hover {
            background: var(--pitaya-dark-teal);
            color: #fff;
        }

        /* Botones de acción en la tabla del historial */
        .btn-accion-hist {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            font-size: .95rem;
            cursor: pointer;
            transition: .15s;
            padding: 0;
            margin: 0 2px;
        }

        .btn-accion-hist:hover {
            background: #e2e8f0;
            border-color: #cbd5e1;
            color: #1e293b;
        }

        .btn-reimprimir-hist:hover {
            background: #d1fae5;
            border-color: #51B8AC;
            color: #0E544C;
        }

        /* Ajuste del btn-ver-detalle en historial: solo ícono, mismo tamaño */
        #tablaVentas .btn-ver-detalle {
            width: 32px;
            height: 32px;
            padding: 0;
            font-size: .95rem;
            border-radius: 7px;
            margin: 0 2px;
            gap: 0;
        }

        /* ── Vista switch (Por Pedido / Por Producto) ── */
        .vista-switch {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 10px;
            padding: 4px;
            gap: 4px;
        }

        .vista-pill {
            padding: 7px 20px;
            border-radius: 7px;
            border: none;
            background: transparent;
            color: #64748b;
            font-size: .875rem;
            font-weight: 600;
            cursor: pointer;
            transition: .18s;
            font-family: inherit;
        }

        .vista-pill.active {
            background: #fff;
            color: #0E544C;
            box-shadow: 0 1px 4px rgba(0,0,0,.12);
        }

        .vista-pill:hover:not(.active) {
            background: #e2e8f0;
            color: #1e293b;
        }

        /* ── Buscador global ── */
        .sticky-search-container {
            margin-bottom: 12px;
        }

        /* ── Badge combo ── */
        .badge-combo {
            background: #fef9c3;
            color: #854d0e;
            font-size: .68rem;
            padding: 2px 7px;
            border-radius: 20px;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <?= renderPOSSidebar('ventas') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Historial de Ventas') ?>

        <div class="pos-content">
            <div class="container-fluid p-3">

                <!-- Barra superior: botones de menú dinámicos + switch de vistas -->
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <!-- Botones de menú y cierre -->
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($menus_disponibles as $menu): ?>
                            <a href="facturas_nueva.php?id_menu=<?= (int) $menu['id'] ?>" class="pos-action-btn primary"
                                title="<?= htmlspecialchars($menu['tipos_cliente'] ?? '') ?>">
                                <i class="fa fa-cash-register"></i>
                                <?= htmlspecialchars($menu['nombre']) ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($menus_disponibles)): ?>
                            <span class="text-muted small" style="align-self:center;">
                                <i class="fa fa-triangle-exclamation me-1"></i>Sin menús activos
                            </span>
                        <?php endif; ?>
                        <!-- Botón Cierre de Turno -->
                        <a href="../cierre/" class="pos-action-btn" style="border-color:#0E544C; color:#0E544C;"
                           title="Iniciar cierre de turno">
                            <i class="fas fa-lock"></i> Cierre de Turno
                        </a>
                    </div>

                    <!-- Vista switch -->
                    <div class="vista-switch" id="vistaSwitchHist">
                        <button class="vista-pill active" id="btnVistaPedido" onclick="cambiarVista('pedido')">
                            Por Pedido
                        </button>
                        <button class="vista-pill" id="btnVistaProducto" onclick="cambiarVista('producto')">
                            Por Producto
                        </button>
                    </div>
                </div>

                <!-- Buscador Global (solo visible en vista Por Producto) -->
                <div class="sticky-search-container" id="contenedorBuscadorGlobal" style="display:none;">
                    <div class="position-relative">
                        <i class="bi bi-search position-absolute"
                           style="left:16px; top:50%; transform:translateY(-50%); z-index:10; color:#6c757d; font-size:16px;"></i>
                        <input type="text" id="buscarGlobal" class="form-control w-100"
                               placeholder="Buscar por producto, ticket, membresía, nombre de cliente, cajero o método de pago (comas para varios filtros)"
                               style="padding-left:44px; padding-right:44px; height:46px !important; font-size:14px !important; border-radius:12px !important;">
                        <div id="buscarGlobalSpinner" class="position-absolute"
                             style="right:16px; top:50%; transform:translateY(-50%); z-index:10; display:none; color:#6c757d;">
                            <i class="bi bi-arrow-repeat" style="font-size:16px;"></i>
                        </div>
                    </div>
                </div>

                <!-- Tabla Por Pedido -->
                <div id="container-pedido">
                <div class="table-responsive">
                    <table class="table table-hover facturas-table" id="tablaVentas">
                        <thead>
                            <tr>
                                <th data-column="id" data-type="text">
                                    Ticket
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="fecha" data-type="daterange">
                                    Fecha
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="hora" data-type="text">
                                    Hora
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
                                    Total
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
                                <th style="width:110px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaVentasBody">
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <div class="spinner-border spinner-border-sm me-2"></div>
                                    Cargando…
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </div><!-- /container-pedido -->

                <!-- Tabla Por Producto -->
                <div id="container-producto" style="display:none;">
                <div class="table-responsive">
                    <table class="table table-hover facturas-table" id="tablaProductos">
                        <thead>
                            <tr>
                                <th data-column="nombre_producto" data-type="text" data-vista="producto">
                                    Producto
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="id" data-type="text" data-vista="producto">
                                    Ticket
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="fecha" data-type="daterange" data-vista="producto">
                                    Fecha
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="hora" data-type="text" data-vista="producto">
                                    Hora
                                </th>
                                <th data-column="cliente" data-type="text" data-vista="producto">
                                    Cliente
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="tipo_cliente" data-type="list" data-vista="producto">
                                    Tipo
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th class="text-end" data-column="total_linea" data-type="number" data-vista="producto">
                                    Total Línea
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="metodo_pago" data-type="list" data-vista="producto">
                                    Método de Pago
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="estado" data-type="list" data-vista="producto">
                                    Estado
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th data-column="cajero" data-type="text" data-vista="producto">
                                    Cajero
                                    <i class="bi bi-funnel filter-icon" onclick="toggleFilter(this)"></i>
                                </th>
                                <th style="width:110px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaProductosBody">
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    <div class="spinner-border spinner-border-sm me-2"></div>
                                    Cargando…
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </div><!-- /container-producto -->

                <!-- Paginación + registros por página -->
                <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <label class="mb-0" style="font-size:.85rem;">Mostrar:</label>
                        <select class="form-select form-select-sm" id="registrosPorPagina" style="width:auto;"
                            onchange="cambiarRegistrosPorPagina()">
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
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        style="margin-left:auto;"></button>
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
                                <tr>
                                    <td colspan="5" class="text-center text-muted">Cargando…</td>
                                </tr>
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
    <script src="js/pos_impresion_termica.js?v=<?php echo time(); ?>"></script>
    <script src="js/ventas_historial.js?v=<?php echo time(); ?>"></script>
</body>

</html>