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
    <title>Nueva Merma — POS</title>
    <meta name="description" content="Registro de nueva merma de tienda">
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
        <?= renderPOSHeader('Nueva Merma') ?>

        <div class="pos-content pos-content-fluid">

            <!-- Barra superior -->
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <a href="mermas_historial.php" class="btn-cancelar-merma">
                    <i class="bi bi-arrow-left"></i> Volver al Historial
                </a>
                <button id="btnGuardar" class="btn-guardar-merma" onclick="guardarMerma()">
                    <i class="bi bi-floppy me-1"></i>Guardar Merma
                </button>
            </div>

            <!-- Layout split -->
            <div class="mermas-layout">

                <!-- Panel izquierdo: Cabecera + Detalle -->
                <div>
                    <!-- Card cabecera -->
                    <div class="card-merma mb-3">
                        <div class="card-header-merma">
                            <i class="fas fa-box-open"></i> Datos de la Merma
                        </div>
                        <div class="card-body-merma">
                            <form class="form-cabecera" onsubmit="return false;">
                                <div class="row g-3">

                                    <div class="col-sm-12">
                                        <label class="form-label" for="idTipoIncidencia">Tipo de Incidencia</label>
                                        <select id="idTipoIncidencia" class="form-select">
                                            <option value="">— Seleccionar tipo —</option>
                                        </select>
                                        <div id="infoTipo" class="merma-tipo-info mt-1" style="display:none;"></div>
                                    </div>

                                    <div class="col-sm-12">
                                        <label class="form-label" for="observacion">Observación <span style="font-weight:400;color:#94a3b8;">(opcional)</span></label>
                                        <textarea id="observacion" class="form-control" rows="2"
                                                  placeholder="Descripción de la merma…" maxlength="500"></textarea>
                                    </div>

                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Card detalle (líneas de productos) -->
                    <div class="card-merma">
                        <div class="card-header-merma">
                            <i class="bi bi-list-ul"></i> Productos con Merma
                        </div>
                        <div class="card-body-merma p-0">
                            <table class="tabla-detalle-merma w-100">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Producto</th>
                                        <th style="width:120px;">Cantidad</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="tablaDetalleBody">
                                    <tr>
                                        <td colspan="4" class="empty-detalle">
                                            <i class="bi bi-box-seam me-1"></i>Busque y agregue productos desde el panel derecho.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Panel derecho: Buscador de productos -->
                <div class="panel-merma-derecho">
                    <div class="card-merma">
                        <div class="card-header-merma">
                            <i class="bi bi-search"></i> Buscar Producto
                        </div>
                        <div class="card-body-merma">
                            <div class="buscador-productos mb-2">
                                <i class="bi bi-search icon-search"></i>
                                <input type="text" id="buscadorProducto" placeholder="Nombre o SKU del producto…" autocomplete="off">
                            </div>
                            <div class="lista-productos-scroll" id="listaProductos">
                                <p class="no-productos-msg"><span class="spinner-border spinner-border-sm"></span> Cargando…</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /mermas-layout -->

        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/mermas_nueva.js?v=<?php echo time(); ?>"></script>
</body>
</html>
