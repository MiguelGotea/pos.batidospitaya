<?php
// promocion_form.php â€” Formulario de PromociÃ³n (crear / editar)

require_once '../../core/auth/auth_pos.php';
posRequiereColaborador();
require_once '../../core/layout/menu_lateral.php';
require_once '../../core/layout/header_universal.php';
require_once '../../core/permissions/permissions.php';

$usuario       = obtenerUsuarioActual();
$cargoOperario = $usuario['CodNivelesCargos'];

if (!tienePermiso('promociones', 'vista', $cargoOperario)) {
    header('Location: /login.php');
    exit();
}

$idPromo  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$esNuevo  = ($idPromo === 0);

if (!$esNuevo && !tienePermiso('promociones', 'edicion', $cargoOperario)) {
    header('Location: promociones.php');
    exit();
}
if ($esNuevo && !tienePermiso('promociones', 'nuevo', $cargoOperario)) {
    header('Location: promociones.php');
    exit();
}

$tituloPage = $esNuevo ? 'Nueva PromociÃ³n' : 'Editar PromociÃ³n';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $tituloPage; ?> â€” Pitaya ERP</title>
    <meta name="description" content="Configurador de Promociones â€” <?php echo $tituloPage; ?>">
    <link rel="icon" href="../../assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/global_tools.css?v=<?php echo mt_rand(1, 9999); ?>">
    <link rel="stylesheet" href="css/promociones.css?v=<?php echo mt_rand(1, 9999); ?>">
</head>

<body>
    <?php echo renderMenuLateral($cargoOperario); ?>

    <div class="main-container">
        <div class="sub-container">
            <?php echo renderHeader($usuario, false, $tituloPage); ?>

            <div class="container-fluid p-3">
                <form id="formPromocion" novalidate>
                    <input type="hidden" id="promoId" value="<?php echo $idPromo; ?>">

                    <!-- ========================================================= -->
                    <!-- SECCIÃ“N 1 â€” ENCABEZADO                                     -->
                    <!-- ========================================================= -->
                    <div class="promo-section mb-4">
                        <div class="promo-section-header">
                            <i class="bi bi-info-circle"></i>
                            <span>1. Encabezado</span>
                        </div>
                        <div class="promo-section-body">
                            <div class="row g-3">
                                <!-- Nombre -->
                                <div class="col-md-6">
                                    <label class="form-label promo-label">Nombre de la promociÃ³n <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="promoNombre" name="nombre" required
                                        placeholder="Ej: Martes 40% en el segundo batido"
                                        oninput="actualizarPreview()">
                                </div>

                                <!-- CÃ³digo ID -->
                                <div class="col-md-3">
                                    <label class="form-label promo-label">CÃ³digo ID</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control bg-light" id="promoCodigo" name="codigo_interno"
                                            value="<?php echo !$esNuevo ? $idPromo : ''; ?>" readonly
                                            placeholder="â€” Autoincremental â€”">
                                        <span class="input-group-text" id="codigoStatus">
                                            <i class="bi bi-lock-fill" id="codigoIcon"></i>
                                        </span>
                                    </div>
                                    <div class="form-text" id="codigoMsg">Generado automÃ¡ticamente.</div>
                                </div>

                                <!-- Estado -->
                                <div class="col-md-3">
                                    <label class="form-label promo-label">Estado</label>
                                    <select class="form-select" id="promoEstado" name="estado" onchange="actualizarPreview()">
                                        <option value="borrador" selected>ðŸ“ Borrador</option>
                                        <option value="activa">âœ… Activa</option>
                                        <option value="inactiva">â¸ Inactiva</option>
                                    </select>
                                </div>

                                <!-- DescripciÃ³n interna -->
                                <div class="col-12">
                                    <label class="form-label promo-label">DescripciÃ³n interna</label>
                                    <textarea class="form-control" id="promoDesc" name="descripcion_interna" rows="2"
                                        placeholder="Notas internas sobre esta promociÃ³nâ€¦"></textarea>
                                    <div class="form-text text-muted"><i class="bi bi-info-circle"></i> No aparece en factura.</div>
                                </div>

                                <!-- Fechas -->
                                <div class="col-md-3">
                                    <label class="form-label promo-label">Fecha inicio</label>
                                    <input type="date" class="form-control" id="promoFechaInicio" name="fecha_inicio" onchange="actualizarPreview()">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label promo-label">Fecha fin</label>
                                    <input type="date" class="form-control" id="promoFechaFin" name="fecha_fin" onchange="actualizarPreview()">
                                </div>

                                <!-- Prioridad -->
                                <div class="col-md-3">
                                    <label class="form-label promo-label">Prioridad</label>
                                    <input type="number" class="form-control" id="promoPrioridad" name="prioridad"
                                        value="10" min="1" max="255">
                                    <div class="form-text text-muted">Menor nÃºmero = mayor prioridad.</div>
                                </div>

                                <!-- Toggles -->
                                <div class="col-md-3 d-flex align-items-end">
                                    <div class="d-flex flex-column gap-2">
                                        <div class="promo-toggle-row">
                                            <label class="promo-switch">
                                                <input type="checkbox" id="promoAutomatico" name="ejecucion_automatica" onchange="actualizarPreview()">
                                                <span class="promo-slider"></span>
                                            </label>
                                            <span class="promo-toggle-label"><strong>EjecuciÃ³n automÃ¡tica</strong></span>
                                        </div>
                                        <div class="promo-toggle-row">
                                            <label class="promo-switch">
                                                <input type="checkbox" id="promoCombinable" name="combinable" onchange="actualizarPreview()">
                                                <span class="promo-slider"></span>
                                            </label>
                                            <span class="promo-toggle-label">Combinable con otras</span>
                                        </div>
                                        <div class="promo-toggle-row">
                                            <label class="promo-switch">
                                                <input type="checkbox" id="promoUsoUnico" name="uso_unico_cliente">
                                                <span class="promo-slider"></span>
                                            </label>
                                            <span class="promo-toggle-label">Uso Ãºnico por cliente</span>
                                        </div>
                                        <div class="promo-toggle-row">
                                            <label class="promo-switch">
                                                <input type="checkbox" id="promoAutorizacion" name="requiere_autorizacion">
                                                <span class="promo-slider"></span>
                                            </label>
                                            <span class="promo-toggle-label">Requiere autorizaciÃ³n</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- SECCIÃ“N 2 â€” CONDICIONES (RULE BUILDER)                    -->
                    <!-- ========================================================= -->
                    <div class="promo-section mb-4">
                        <div class="promo-section-header">
                            <i class="bi bi-sliders"></i>
                            <span>2. Condiciones de activaciÃ³n</span>
                            <span class="promo-section-badge ms-2" id="contadorCondiciones">0</span>
                        </div>
                        <div class="promo-section-body">
                            <div class="alert alert-info promo-alert-info mb-3">
                                <i class="bi bi-info-circle-fill me-1"></i>
                                <strong>AND:</strong> Todas las condiciones deben cumplirse <u>simultÃ¡neamente</u> para que la promociÃ³n se active.
                            </div>

                            <!-- Dropdown "Agregar condiciÃ³n" -->
                            <div class="dropdown mb-3">
                                <button class="btn promo-btn-agregar dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-plus-circle"></i> Agregar condiciÃ³n
                                </button>
                                <ul class="dropdown-menu promo-cond-menu" style="min-width:280px;">
                                    <li>
                                        <h6 class="dropdown-header promo-cond-group-header">
                                            <span class="badge promo-badge-a me-1">A</span> Contexto (cuÃ¡ndo y dÃ³nde)
                                        </h6>
                                    </li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('A','dia_semana')">ðŸ“… DÃ­a de semana</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('A','horario')">ðŸ• Horario</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('A','sucursal')">ðŸª Sucursal</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('A','tipo_cliente')">ðŸ‘¤ Tipo de cliente</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('A','canal_venta')">ðŸ“± Canal de venta</a></li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                    <li>
                                        <h6 class="dropdown-header promo-cond-group-header">
                                            <span class="badge promo-badge-b me-1">B</span> Carrito (quÃ© debe contener)
                                        </h6>
                                    </li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('B','producto')">ðŸ§ƒ Producto especÃ­fico</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('B','grupo_producto')">ðŸ“¦ Grupo / Subgrupo</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('B','tamano')">ðŸ“ TamaÃ±o</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('B','cantidad_min')">ðŸ”¢ Cantidad mÃ­nima</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('B','monto_min')">ðŸ’° Monto mÃ­nimo de factura</a></li>
                                    <li><a class="dropdown-item promo-cond-item" href="javascript:void(0)" onclick="agregarCondicion('B','combo')">ðŸŽ¯ Combo X</a></li>
                                </ul>
                            </div>

                            <!-- Contenedor de condiciones -->
                            <div id="contenedorCondiciones">
                                <div id="sinCondiciones" class="promo-empty-conditions text-center text-muted py-4">
                                    <i class="bi bi-funnel fs-2 d-block mb-2 opacity-50"></i>
                                    <span>Sin condiciones â€” la promociÃ³n aplica siempre</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- SECCIÃ“N 3 â€” OBJETIVO DEL DESCUENTO                        -->
                    <!-- ========================================================= -->
                    <div class="promo-section mb-4">
                        <div class="promo-section-header">
                            <i class="bi bi-bullseye"></i>
                            <span>3. Â¿A quÃ© Ã­tem se aplica el descuento?</span>
                        </div>
                        <div class="promo-section-body">
                            <div class="row g-3" id="objetivoCards">

                                <div class="col-md-4 col-lg-2">
                                    <div class="promo-option-card promo-target-card selected" data-value="todos" onclick="seleccionarObjetivo('todos')">
                                        <div class="promo-option-icon">ðŸŽ¯</div>
                                        <div class="promo-option-title">Todos los que califican</div>
                                        <div class="promo-option-desc">A cada Ã­tem del grupo/filtro</div>
                                    </div>
                                </div>

                                <div class="col-md-4 col-lg-2">
                                    <div class="promo-option-card promo-target-card" data-value="mas_barato" onclick="seleccionarObjetivo('mas_barato')">
                                        <div class="promo-option-icon">ðŸ’²</div>
                                        <div class="promo-option-title">El mÃ¡s barato</div>
                                        <div class="promo-option-desc">Solo el Ã­tem de menor precio</div>
                                    </div>
                                </div>


                                <div class="col-md-4 col-lg-2">
                                    <div class="promo-option-card promo-target-card" data-value="get_y" onclick="seleccionarObjetivo('get_y')">
                                        <div class="promo-option-icon">ðŸŽ</div>
                                        <div class="promo-option-title">Producto distinto (Get Y)</div>
                                        <div class="promo-option-desc">Aplica a otro producto</div>
                                    </div>
                                </div>

                                <div class="col-md-4 col-lg-2">
                                    <div class="promo-option-card promo-target-card" data-value="factura" onclick="seleccionarObjetivo('factura')">
                                        <div class="promo-option-icon">ðŸ§¾</div>
                                        <div class="promo-option-title">Toda la factura</div>
                                        <div class="promo-option-desc">Descuento sobre el total</div>
                                    </div>
                                </div>

                                <div class="col-md-4 col-lg-2">
                                    <div class="promo-option-card promo-target-card" data-value="upgrade" onclick="seleccionarObjetivo('upgrade')">
                                        <div class="promo-option-icon">â¬†ï¸</div>
                                        <div class="promo-option-title">Upgrade de tamaÃ±o</div>
                                        <div class="promo-option-desc">Agrandar sin costo</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Campos extra segÃºn objetivo -->
                            <input type="hidden" id="objetivoDescuento" name="objetivo_descuento" value="todos">


                            <div id="extraGetY" class="promo-extra-fields mt-3" style="display:none;">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-5">
                                        <label class="form-label promo-label">Producto que se lleva (Get Y) <span class="text-danger">*</span></label>
                                        <select class="form-select promo-select2-product" id="objetivoGetYProd" name="objetivo_get_y_prod" style="width:100%;">
                                            <option value="">Buscar productoâ€¦</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label promo-label">Cantidad</label>
                                        <input type="number" class="form-control" id="objetivoGetYCant" name="objetivo_get_y_cant" value="1" min="1">
                                    </div>
                                </div>
                            </div>

                            <div id="extraUpgrade" class="promo-extra-fields mt-3" style="display:none;">
                                <div class="d-flex align-items-center gap-3">
                                    <div>
                                        <label class="form-label promo-label">De tamaÃ±o</label>
                                        <select class="form-select" id="objetivoUpgradeDe" name="objetivo_upgrade_de" style="max-width:150px;">
                                            <option value="">â€” seleccionar â€”</option>
                                            <option value="16oz">16oz</option>
                                            <option value="20oz">20oz</option>
                                        </select>
                                    </div>
                                    <div class="fs-4 pt-3">â†’</div>
                                    <div>
                                        <label class="form-label promo-label">A tamaÃ±o</label>
                                        <select class="form-select" id="objetivoUpgradeA" name="objetivo_upgrade_a" style="max-width:150px;">
                                            <option value="">â€” seleccionar â€”</option>
                                            <option value="16oz">16oz</option>
                                            <option value="20oz">20oz</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ========================================================= -->
                    <!-- SECCIÃ“N 4 â€” RESULTADO                                     -->
                    <!-- ========================================================= -->
                    <div class="promo-section mb-4">
                        <div class="promo-section-header">
                            <i class="bi bi-percent"></i>
                            <span>4. Resultado (tipo y valor del descuento)</span>
                        </div>
                        <div class="promo-section-body">

                            <!-- Tarjetas de tipo resultado -->
                            <div class="row g-3 mb-3" id="resultadoCards">
                                <div class="col-6 col-md-3">
                                    <div class="promo-option-card promo-result-card selected" data-value="pct_producto" onclick="seleccionarResultado('pct_producto')">
                                        <div class="promo-option-icon promo-result-icon promo-result-pct-prod">%</div>
                                        <div class="promo-option-title">% sobre producto</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="promo-option-card promo-result-card" data-value="pct_factura" onclick="seleccionarResultado('pct_factura')">
                                        <div class="promo-option-icon promo-result-icon promo-result-pct-fac">%</div>
                                        <div class="promo-option-title">% sobre factura</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="promo-option-card promo-result-card" data-value="monto_producto" onclick="seleccionarResultado('monto_producto')">
                                        <div class="promo-option-icon promo-result-icon promo-result-monto-prod">C$</div>
                                        <div class="promo-option-title">Monto fijo â€” producto</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="promo-option-card promo-result-card" data-value="monto_factura" onclick="seleccionarResultado('monto_factura')">
                                        <div class="promo-option-icon promo-result-icon promo-result-monto-fac">C$</div>
                                        <div class="promo-option-title">Monto fijo â€” factura</div>
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" id="resultadoTipo" name="resultado_tipo" value="pct_producto">

                            <!-- Valor del descuento -->
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label promo-label" id="resultadoValorLabel">
                                        Valor del descuento (%) <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="resultadoValor" name="resultado_valor"
                                            value="0" min="0" step="0.01" required oninput="actualizarPreview()">
                                        <span class="input-group-text" id="resultadoUnidad">%</span>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label promo-label">Descuento mÃ¡ximo (C$) <span class="text-muted small">opcional</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">C$</span>
                                        <input type="number" class="form-control" id="resultadoMaxCS" name="descuento_maximo_cs"
                                            placeholder="Sin lÃ­mite" min="0" step="0.01">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label promo-label">Usos mÃ¡ximos globales <span class="text-muted small">opcional</span></label>
                                    <input type="number" class="form-control" id="usosMaximos" name="usos_maximos"
                                        placeholder="Sin lÃ­mite" min="1">
                                </div>
                            </div>

                            <!-- Vista previa -->
                            <div class="promo-preview-box mt-4" id="vistaPrevia">
                                <div class="promo-preview-label"><i class="bi bi-eye"></i> Vista previa</div>
                                <div class="promo-preview-text" id="vistaPreviewTexto">â€”</div>
                            </div>

                        </div>
                    </div>

                    <!-- BOTONES DE ACCIÃ“N -->
                    <div class="d-flex gap-2 mb-5">
                        <button type="button" class="btn promo-btn-guardar" onclick="guardarPromo()">
                            <i class="bi bi-check-circle"></i> Guardar promociÃ³n
                        </button>
                        <a href="promociones.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Cancelar
                        </a>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <!-- =================== MODAL AYUDA =================== -->
    <div class="modal fade" id="pageHelpModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header promo-modal-header">
                    <h5 class="modal-title"><i class="bi bi-question-circle me-2"></i>Ayuda â€” Formulario de PromociÃ³n</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h5 class="fw-bold text-success border-bottom pb-2"><i class="bi bi-info-circle"></i> GuÃ­a Completa de Promociones</h5>
                    
                    <div class="mt-3">
                        <h6 class="fw-bold text-primary">1. Datos Generales (Encabezado)</h6>
                        <p class="small text-muted">AquÃ­ defines la identidad de la promociÃ³n. El <strong>CÃ³digo ID</strong> es asignado automÃ¡ticamente por el ERP para evitar duplicados.</p>
                        <ul class="small list-group list-group-flush mb-2">
                            <li class="list-group-item"><strong>EjecuciÃ³n automÃ¡tica:</strong> Si estÃ¡ activo, el sistema aplica la promo sola al detectar las condiciones en el carrito. Si estÃ¡ apagado, el cajero debe seleccionarla manualmente.</li>
                            <li class="list-group-item"><strong>Combinable con otras:</strong> Permite que esta promo coexista con otros descuentos en la misma factura.</li>
                            <li class="list-group-item"><strong>Uso Ãºnico por cliente:</strong> Restringe la promo a una sola vez por cada cliente identificado.</li>
                            <li class="list-group-item"><strong>Requiere autorizaciÃ³n:</strong> Solicita la clave de un supervisor para poder aplicarla.</li>
                        </ul>
                    </div>

                    <div class="mt-4">
                        <h6 class="fw-bold text-primary">2. Condiciones de ActivaciÃ³n (Rule Builder)</h6>
                        <p class="small">Este es el "motor" de la promo. Todas las condiciones agregadas deben cumplirse simultÃ¡neamente (lÃ³gica AND).</p>
                        <ul class="small list-group list-group-flush mb-3">
                            <li class="list-group-item"><strong>DÃ­a / Horario / Sucursal:</strong> Controla la vigencia temporal y geogrÃ¡fica.</li>
                            <li class="list-group-item"><strong>Tipo de cliente y Canal:</strong> Segmenta segÃºn el origen del pedido (ej. Solo para "pÃ¡gina web").</li>
                            <li class="list-group-item"><strong>Producto / Grupo:</strong> Especifica quÃ© artÃ­culos DEBEN estar en el carrito. Puedes definir una cantidad mÃ­nima requerida.</li>
                            <li class="list-group-item"><strong>Combo X:</strong> La condiciÃ³n mÃ¡s flexible. Permite exigir que existan N Ã­tems diferentes (ej. Un plato + Una bebida + Un postre) para que la promo se active.</li>
                        </ul>
                    </div>

                    <div class="mt-4">
                        <h6 class="fw-bold text-primary">3. Objetivo del Descuento</h6>
                        <p class="small">Â¿A quÃ© Ã­tem le quitaremos dinero? Tienes estas opciones:</p>
                        <ul class="small list-group list-group-flush mb-3">
                            <li class="list-group-item"><strong>A todos los Ã­tems:</strong> El descuento se aplica a cada producto que cumpliÃ³ la condiciÃ³n.</li>
                            <li class="list-group-item"><strong>Al mÃ¡s barato:</strong> Ideal para "2x1", donde el de menor precio es el que sale gratis.</li>
                            <li class="list-group-item"><strong>Producto distinto (Get Y):</strong> Cuando compras A pero el descuento es para el producto B.</li>
                            <li class="list-group-item"><strong>Upgrade:</strong> EspecÃ­fico para agrandar de 16oz a 20oz sin costo extra.</li>
                        </ul>
                    </div>

                    <div class="mt-4">
                        <h6 class="fw-bold text-primary">4. Resultado (Valor del Descuento)</h6>
                        <p class="small">AquÃ­ defines cuÃ¡nto se descuenta. Puede ser un <strong>porcentaje (50%)</strong> o un <strong>monto fijo (C$20)</strong>. Si elegiste <strong>Upgrade</strong> en la secciÃ³n anterior, los valores de aquÃ­ son opcionales ya que el "regalo" es el cambio de tamaÃ±o.</p>
                    </div>

                    <div class="alert alert-info py-2 mt-4 mb-0 small">
                        <i class="bi bi-lightbulb"></i> <strong>Tip:</strong> Revisa el cuadro de <strong>"Vista previa lÃ³gica"</strong> al final de la pÃ¡gina; se actualiza en tiempo real para explicarte cÃ³mo se comportarÃ¡ la promociÃ³n.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        window.PROMO_PAGE = 'formulario';
        window.PROMO_ID = <?php echo $idPromo; ?>;
    </script>
    <script src="js/promociones.js?v=<?php echo mt_rand(1, 9999); ?>"></script>
</body>

</html>