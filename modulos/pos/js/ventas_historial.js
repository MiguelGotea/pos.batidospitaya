/* ================================================================
 * ventas_historial.js
 * Historial de ventas del POS — Por Pedido y Por Producto
 * ================================================================ */

// ── Estado global ─────────────────────────────────────────────────────────────
let paginaActual       = 1;
let registrosPorPagina = 25;
let filtrosActivos     = {};
let ordenActivo        = { columna: null, direccion: 'asc' };
let panelFiltroAbierto = null;
let totalRegistros     = 0;
let scrollTopInicial   = 0;
let windowWidthInicial = $(window).width();
let modalDetalle       = null;

let vistaActiva          = 'pedido';     // 'pedido' | 'producto'
let productosCargados    = false;        // si ya se trajo datos de productos al menos una vez
let debounceTimerBuscar  = null;

$(document).ready(function () {
    modalDetalle = new bootstrap.Modal(document.getElementById('modalDetalle'));
    cargarDatos();

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.filter-panel, .filter-icon').length) {
            cerrarTodosFiltros();
        }
    });

    $('.table-responsive').on('scroll', function (e) { e.stopPropagation(); });

    $(window).on('scroll', function () {
        if (panelFiltroAbierto && Math.abs($(window).scrollTop() - scrollTopInicial) > 100) {
            cerrarTodosFiltros();
        }
    });

    $(window).on('resize', function () {
        const w = $(window).width();
        if (panelFiltroAbierto && w !== windowWidthInicial) {
            cerrarTodosFiltros();
            windowWidthInicial = w;
        }
    });

    // Buscador global (vista producto)
    $('#buscarGlobal').on('input', function () {
        $('#buscarGlobalSpinner').show();
        clearTimeout(debounceTimerBuscar);
        debounceTimerBuscar = setTimeout(function () {
            paginaActual = 1;
            cargarDatos();
            $('#buscarGlobalSpinner').hide();
        }, 350);
    });
});

/* ============================================================
 * CAMBIO DE VISTA
 * ============================================================ */
function cambiarVista(vista) {
    if (vistaActiva === vista) return;
    vistaActiva = vista;

    // UI de píldoras
    $('.vista-pill').removeClass('active');
    if (vista === 'pedido') {
        $('#btnVistaPedido').addClass('active');
        $('#container-pedido').show();
        $('#container-producto').hide();
        $('#contenedorBuscadorGlobal').hide();
    } else {
        $('#btnVistaProducto').addClass('active');
        $('#container-pedido').hide();
        $('#container-producto').show();
        $('#contenedorBuscadorGlobal').show();
    }

    // Limpiar estado al cambiar de pestaña
    filtrosActivos = {};
    ordenActivo    = { columna: null, direccion: 'asc' };
    paginaActual   = 1;
    $('#buscarGlobal').val('');
    cerrarTodosFiltros();
    actualizarIndicadoresFiltros();
    cargarDatos();
}

/* ============================================================
 * CARGA DE DATOS (dispatch por vista)
 * ============================================================ */
function cargarDatos() {
    if (vistaActiva === 'pedido') {
        cargarPedidos();
    } else {
        cargarProductos();
    }
}

/* ---- Vista Por Pedido ---- */
function cargarPedidos() {
    $('#tablaVentasBody').html(`
        <tr><td colspan="10" class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm me-2"></div> Cargando...
        </td></tr>`);

    $.ajax({
        url: 'ajax/ventas_get_historial.php',
        method: 'POST',
        dataType: 'json',
        data: {
            pagina: paginaActual,
            registros_por_pagina: registrosPorPagina,
            filtros: JSON.stringify(filtrosActivos),
            orden:   JSON.stringify(ordenActivo)
        },
        success: function (res) {
            if (res.success) {
                totalRegistros = res.total_registros;
                renderizarTablaPedidos(res.datos || []);
                renderizarPaginacion(totalRegistros);
                actualizarIndicadoresFiltros();
            } else {
                $('#tablaVentasBody').html(`<tr><td colspan="10" class="text-center text-danger">${escHtml(res.message || 'Error al cargar')}</td></tr>`);
            }
        },
        error: function () {
            $('#tablaVentasBody').html('<tr><td colspan="10" class="text-center text-danger">Error de conexión al cargar los datos.</td></tr>');
        }
    });
}

/* ---- Vista Por Producto ---- */
function cargarProductos() {
    $('#tablaProductosBody').html(`
        <tr><td colspan="11" class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm me-2"></div> Cargando...
        </td></tr>`);

    $.ajax({
        url: 'ajax/ventas_get_historial_productos.php',
        method: 'POST',
        dataType: 'json',
        data: {
            pagina: paginaActual,
            registros_por_pagina: registrosPorPagina,
            filtros: JSON.stringify(filtrosActivos),
            orden:   JSON.stringify(ordenActivo),
            buscar:  $('#buscarGlobal').val().trim()
        },
        success: function (res) {
            if (res.success) {
                totalRegistros = res.total_registros;
                renderizarTablaProductos(res.datos || []);
                renderizarPaginacion(totalRegistros);
                actualizarIndicadoresFiltros();
            } else {
                $('#tablaProductosBody').html(`<tr><td colspan="11" class="text-center text-danger">${escHtml(res.message || 'Error al cargar')}</td></tr>`);
            }
        },
        error: function () {
            $('#tablaProductosBody').html('<tr><td colspan="11" class="text-center text-danger">Error de conexión al cargar los datos.</td></tr>');
        }
    });
}

/* ============================================================
 * RENDERIZADO DE TABLAS
 * ============================================================ */

/* ---- Por Pedido ---- */
function renderizarTablaPedidos(ventas) {
    if (!ventas || ventas.length === 0) {
        $('#tablaVentasBody').html(`
            <tr><td colspan="10" class="text-center text-muted py-4">
                <i class="bi bi-inbox me-2"></i>No se encontraron registros.
            </td></tr>`);
        return;
    }

    let html = '';
    ventas.forEach(v => {
        const badgeEstado = v.estado === 'bloqueada'
            ? '<span class="badge-bloqueada">Bloqueada</span>'
            : '<span class="badge-anulada">Anulada</span>';

        const metodos = (v.metodos_pago || '').split(',')
            .filter(m => m.trim() !== '')
            .map(m => `<span class="metodo-tag">${escHtml(m.trim())}</span>`)
            .join('');

        const { fecha, hora } = separarFechaHora(v.fecha_hora);

        html += `
        <tr>
            <td><strong>${escHtml(String(v.id))}</strong></td>
            <td style="font-size:.82rem; white-space:nowrap;">${fecha}</td>
            <td style="font-size:.82rem; white-space:nowrap;">${hora}</td>
            <td>${escHtml(v.cliente || '—')}</td>
            <td>${escHtml(v.tipo_nombre || '—')}</td>
            <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">${formatearMonto(v.total)}</td>
            <td>${metodos || '—'}</td>
            <td>${badgeEstado}</td>
            <td style="font-size:.82rem;">${escHtml(v.cajero || '—')}</td>
            <td class="text-center" style="white-space:nowrap;">
                <button class="btn-ver-detalle btn-accion-hist" onclick="verDetalle(${v.id})" title="Ver detalle">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn-reimprimir-hist btn-accion-hist" onclick="reimprimir(${v.id})" title="Reimprimir factura y comanda">
                    <i class="bi bi-printer-fill"></i>
                </button>
            </td>
        </tr>`;
    });

    $('#tablaVentasBody').html(html);
}

/* ---- Por Producto ---- */
function renderizarTablaProductos(lineas) {
    if (!lineas || lineas.length === 0) {
        $('#tablaProductosBody').html(`
            <tr><td colspan="11" class="text-center text-muted py-4">
                <i class="bi bi-inbox me-2"></i>No se encontraron registros.
            </td></tr>`);
        return;
    }

    let html = '';
    lineas.forEach(l => {
        const badgeEstado = l.estado === 'bloqueada'
            ? '<span class="badge-bloqueada">Bloqueada</span>'
            : '<span class="badge-anulada">Anulada</span>';

        const metodos = (l.metodos_pago || '').split(',')
            .filter(m => m.trim() !== '')
            .map(m => `<span class="metodo-tag">${escHtml(m.trim())}</span>`)
            .join('');

        const { fecha, hora } = separarFechaHora(l.fecha_hora);

        // Es combo si id_producto_presentacion es null (padre de combo)
        const esCombo  = !l.id_producto_presentacion || l.id_producto_presentacion === '0' || l.id_producto_presentacion === null;
        const esCanjeTag = parseInt(l.es_canje_puntos) ? ' <span class="badge-club" style="font-size:.68rem;">Canje</span>' : '';
        const comboTag   = esCombo ? ' <span class="badge-combo">Combo</span>' : '';

        html += `
        <tr>
            <td>
                <strong>${escHtml(l.nombre_producto_snapshot)}${comboTag}${esCanjeTag}</strong>
            </td>
            <td><strong>${escHtml(String(l.id_factura))}</strong></td>
            <td style="font-size:.82rem; white-space:nowrap;">${fecha}</td>
            <td style="font-size:.82rem; white-space:nowrap;">${hora}</td>
            <td>${escHtml(l.cliente || '—')}</td>
            <td>${escHtml(l.tipo_nombre || '—')}</td>
            <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">${formatearMonto(l.precio_linea_total)}</td>
            <td>${metodos || '—'}</td>
            <td>${badgeEstado}</td>
            <td style="font-size:.82rem;">${escHtml(l.cajero || '—')}</td>
            <td class="text-center" style="white-space:nowrap;">
                <button class="btn-accion-hist btn-ver-detalle" onclick="verDetalle(${l.id_factura})" title="Ver detalle del pedido">
                    <i class="bi bi-eye"></i>
                </button>
                <button class="btn-reimprimir-hist btn-accion-hist" onclick="reimprimir(${l.id_factura})" title="Reimprimir factura y comanda">
                    <i class="bi bi-printer-fill"></i>
                </button>
            </td>
        </tr>`;
    });

    $('#tablaProductosBody').html(html);
}

/* ============================================================
 * VER DETALLE EN MODAL
 * ============================================================ */
function verDetalle(id) {
    $('#infoCabeceraModal').html('<div class="col-12 text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>');
    $('#tablaDetalleModal').html('<tr><td colspan="5" class="text-center text-muted">Cargando…</td></tr>');
    $('#totalModal').text('');
    $('#notasModal').text('');
    $('#seccionPagos').hide();
    modalDetalle.show();

    $.get('ajax/ventas_get_detalle.php', { id: id }, function (res) {
        if (!res.success) {
            Swal.fire('Error', res.message, 'error');
            modalDetalle.hide();
            return;
        }

        const v = res.venta;

        // Estado badge
        const badgeEstado = v.estado === 'bloqueada'
            ? '<span class="badge-bloqueada">Bloqueada</span>'
            : '<span class="badge-anulada">Anulada</span>';

        const { fecha, hora } = separarFechaHora(v.fecha_hora);

        $('#infoCabeceraModal').html(`
            <div class="col-sm-4">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Ticket</small>
                <span class="fw-bold">${escHtml(String(v.id))}</span>
            </div>
            <div class="col-sm-2">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Fecha</small>
                <span style="font-size:.875rem;">${fecha}</span>
            </div>
            <div class="col-sm-2">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Hora</small>
                <span style="font-size:.875rem;">${hora}</span>
            </div>
            <div class="col-sm-4">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Cliente</small>
                <span>${escHtml(v.cliente || '—')}</span>
            </div>
            <div class="col-sm-4 mt-1">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Tipo</small>
                <span>${escHtml(v.tipo_nombre || '—')}</span>
            </div>
            <div class="col-sm-4 mt-1">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Cajero</small>
                <span style="font-size:.875rem;">${escHtml(v.cajero || '—')}</span>
            </div>
            <div class="col-sm-4 mt-1">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Estado</small>
                ${badgeEstado}
            </div>
            ${v.con_propina ? `
            <div class="col-sm-4 mt-1">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Propina (${v.porcentaje_propina}%)</small>
                <span style="font-size:.875rem;">${formatearMonto(v.propina)}</span>
            </div>` : ''}`);


        // Detalle líneas
        if (!res.lineas || res.lineas.length === 0) {
            $('#tablaDetalleModal').html('<tr><td colspan="5" class="text-center text-muted">Sin detalle.</td></tr>');
        } else {
            let rows = '';
            res.lineas.forEach((l, i) => {
                const esCanjeTag = parseInt(l.es_canje_puntos) ? ' <span class="badge-club" style="font-size:.68rem;">Canje</span>' : '';
                rows += `
                <tr>
                    <td>${i + 1}</td>
                    <td>
                        <strong>${escHtml(l.nombre_producto_snapshot)}${esCanjeTag}</strong>
                        ${l.notas ? `<small class="d-block text-muted">${escHtml(l.notas)}</small>` : ''}
                    </td>
                    <td class="text-end">${parseFloat(l.cantidad).toFixed(2)}</td>
                    <td class="text-end">${formatearMonto(l.precio_unitario_final)}</td>
                    <td class="text-end">${formatearMonto(l.precio_linea_total)}</td>
                </tr>`;
            });
            $('#tablaDetalleModal').html(rows);
        }

        // Pagos
        if (res.pagos && res.pagos.length > 0) {
            let pagosHtml = '';
            res.pagos.forEach(p => {
                const montoStr = p.moneda === 'USD'
                    ? `US$ ${parseFloat(p.monto_original).toFixed(2)} (≈ ${formatearMonto(p.monto_cordoba)})`
                    : formatearMonto(p.monto_cordoba);
                const metodo = escHtml((p.metodo || '').replace('_', ' ').toUpperCase());
                const esNegativo = parseFloat(p.monto_cordoba) < 0;
                pagosHtml += `<div style="font-size:.85rem; ${esNegativo ? 'color:#64748b;' : ''}">
                    <span class="metodo-tag">${metodo}</span> ${montoStr}
                    ${p.referencia && p.referencia !== 'cambio' ? `<small class="text-muted">Ref: ${escHtml(p.referencia)}</small>` : ''}
                    ${p.referencia === 'cambio' ? '<small class="text-muted">(cambio devuelto)</small>' : ''}
                </div>`;
            });
            $('#listaPagos').html(pagosHtml);
            $('#seccionPagos').show();
        }

        $('#totalModal').html(`<strong>Total:</strong> ${formatearMonto(v.total)}`);
        $('#modalDetalleLabel').html(`<i class="bi bi-receipt me-2"></i>Ticket ${escHtml(String(v.id))}`);

    }, 'json').fail(function () {
        Swal.fire('Error', 'No se pudo cargar el detalle.', 'error');
        modalDetalle.hide();
    });
}

/* ============================================================
 * FILTROS / ORDEN / PAGINACIÓN
 * ============================================================ */
function toggleFilter(icon) {
    const th      = $(icon).closest('th');
    const columna = th.data('column');
    const tipo    = th.data('type');
    if (!columna || !tipo) return;
    if (panelFiltroAbierto === columna) { cerrarTodosFiltros(); return; }
    cerrarTodosFiltros();
    scrollTopInicial   = $(window).scrollTop();
    windowWidthInicial = $(window).width();
    crearPanelFiltro(th, columna, tipo, icon);
    panelFiltroAbierto = columna;
    $(icon).addClass('active');
    actualizarIndicadoresFiltros();
}

function crearPanelFiltro(th, columna, tipo, icon) {
    const panel = $('<div class="filter-panel show"></div>');
    if (tipo === 'daterange') panel.addClass('has-daterange');

    const textoAsc  = tipo === 'number' ? 'Menor a mayor' : 'A→Z';
    const textoDesc = tipo === 'number' ? 'Mayor a menor' : 'Z→A';

    panel.append(`
        <div class="filter-section">
            <span class="filter-section-title">Ordenar:</span>
            <div class="filter-sort-buttons">
                <button class="filter-sort-btn ${ordenActivo.columna === columna && ordenActivo.direccion === 'asc' ? 'active' : ''}"
                        onclick="aplicarOrden('${columna}', 'asc')">
                    <i class="bi bi-sort-alpha-down"></i> ${textoAsc}
                </button>
                <button class="filter-sort-btn ${ordenActivo.columna === columna && ordenActivo.direccion === 'desc' ? 'active' : ''}"
                        onclick="aplicarOrden('${columna}', 'desc')">
                    <i class="bi bi-sort-alpha-up"></i> ${textoDesc}
                </button>
            </div>
        </div>
        <div class="filter-actions">
            <button class="filter-action-btn clear" onclick="limpiarFiltro('${columna}')">
                <i class="bi bi-x-circle"></i> Limpiar
            </button>
        </div>`);

    $('body').append(panel);

    if (tipo === 'text' || tipo === 'number') {
        const valorActual = filtrosActivos[columna] || '';
        const placeholder = tipo === 'number' ? 'Ej: 500' : 'Escribir...';
        panel.append(`
            <div class="filter-section" style="margin-top:12px;">
                <span class="filter-section-title">${tipo === 'number' ? 'Contiene número:' : 'Buscar:'}</span>
                <input type="text" class="filter-search" placeholder="${placeholder}"
                       value="${escHtml(valorActual)}"
                       oninput="filtrarBusqueda('${columna}', this.value)">
            </div>`);
        posicionarPanelFiltro(panel, icon);
    } else if (tipo === 'list') {
        cargarOpcionesFiltro(panel, columna, icon);
    } else if (tipo === 'daterange') {
        crearCalendarioRango(panel, columna);
        posicionarPanelFiltro(panel, icon);
    }
}

function crearCalendarioRango(panel, columna) {
    const hoy        = new Date();
    const mesActual  = hoy.getMonth();
    const anioActual = hoy.getFullYear();

    panel.append(`
        <div class="filter-section" style="margin-top:8px;">
            <span class="filter-section-title">Seleccionar rango:</span>
            <div class="daterange-calendar-container">
                <div class="daterange-month-selector">
                    <select id="mesCalendario" onchange="actualizarCalendarioUnico('${columna}')"></select>
                    <select id="anioCalendario" onchange="actualizarCalendarioUnico('${columna}')"></select>
                </div>
                <div class="daterange-calendar" id="calendarioUnico"></div>
            </div>
        </div>`);

    setTimeout(() => {
        const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                       'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        const $mes  = $('#mesCalendario');
        const $anio = $('#anioCalendario');
        meses.forEach((m, i) => $mes.append(`<option value="${i}" ${i === mesActual ? 'selected' : ''}>${m}</option>`));
        for (let a = anioActual - 8; a <= anioActual + 1; a++) {
            $anio.append(`<option value="${a}" ${a === anioActual ? 'selected' : ''}>${a}</option>`);
        }
        actualizarCalendarioUnico(columna);
    }, 30);
}

function actualizarCalendarioUnico(columna) {
    const mes        = parseInt($('#mesCalendario').val(), 10);
    const anio       = parseInt($('#anioCalendario').val(), 10);
    const primerDia  = new Date(anio, mes, 1).getDay();
    const diasEnMes  = new Date(anio, mes + 1, 0).getDate();
    const diasSemana = ['D','L','M','M','J','V','S'];
    let html = '<div class="daterange-calendar-header">';
    diasSemana.forEach(d => { html += `<div class="daterange-calendar-day-name">${d}</div>`; });
    html += '</div><div class="daterange-calendar-days">';
    for (let i = 0; i < primerDia; i++) html += '<div class="daterange-calendar-day empty"></div>';
    for (let d = 1; d <= diasEnMes; d++) {
        const f = `${anio}-${String(mes + 1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const c = obtenerClasesCalendario(f, columna);
        html += `<div class="daterange-calendar-day ${c}" onclick="event.stopPropagation();seleccionarFechaRango('${f}','${columna}')">${d}</div>`;
    }
    html += '</div>';
    $('#calendarioUnico').html(html);
}

function seleccionarFechaRango(fecha, columna) {
    if (!filtrosActivos[columna]) filtrosActivos[columna] = { desde: null, hasta: null };
    const { desde, hasta } = filtrosActivos[columna];
    if (!desde) {
        filtrosActivos[columna].desde = fecha;
    } else if (!hasta) {
        if (fecha < desde) { filtrosActivos[columna].desde = fecha; filtrosActivos[columna].hasta = desde; }
        else { filtrosActivos[columna].hasta = fecha; }
    } else {
        filtrosActivos[columna].desde = fecha;
        filtrosActivos[columna].hasta = null;
    }
    actualizarCalendarioUnico(columna);
    if (filtrosActivos[columna].desde && filtrosActivos[columna].hasta) {
        paginaActual = 1;
        cargarDatos();
    }
}

function obtenerClasesCalendario(fecha, columna) {
    const d = filtrosActivos[columna]?.desde;
    const h = filtrosActivos[columna]?.hasta;
    if (fecha === d || fecha === h) return 'selected';
    if (d && h && fecha > d && fecha < h) return 'in-range';
    return '';
}

function cargarOpcionesFiltro(panel, columna, icon) {
    $.ajax({
        url: 'ajax/ventas_get_opciones_filtro.php',
        method: 'POST',
        dataType: 'json',
        data: { columna },
        success: function (res) {
            if (!res.success) {
                panel.append('<div class="text-danger" style="margin-top:8px;">No se pudieron cargar opciones.</div>');
                posicionarPanelFiltro(panel, icon);
                return;
            }
            let html = '<div class="filter-section" style="margin-top:12px;"><span class="filter-section-title">Filtrar por:</span>';
            html += '<input type="text" class="filter-search" placeholder="Buscar..." onkeyup="buscarEnOpciones(this)">';
            html += '<div class="filter-options">';
            (res.opciones || []).forEach(op => {
                const val     = String(op.valor ?? '');
                const txt     = String(op.texto ?? '');
                const checked = filtrosActivos[columna] && filtrosActivos[columna].includes(val) ? 'checked' : '';
                html += `<div class="filter-option">
                    <input type="checkbox" value="${escHtml(val)}" ${checked}
                           onchange="toggleOpcionFiltro('${columna}','${escHtml(val)}',this.checked)">
                    <span>${escHtml(txt)}</span>
                </div>`;
            });
            html += '</div></div>';
            panel.append(html);
            posicionarPanelFiltro(panel, icon);
        }
    });
}

function posicionarPanelFiltro(panel, icon) {
    const off    = $(icon).offset();
    const w      = $(icon).outerWidth();
    const h      = $(icon).outerHeight();
    const pw     = panel.outerWidth();
    const ph     = panel.outerHeight();
    const winW   = $(window).width();
    const winH   = $(window).height();
    const sTop   = $(window).scrollTop();
    let top  = off.top + h + 5;
    let left = off.left - pw + w;
    if (left + pw > winW) left = winW - pw - 10;
    if (left < 10) left = 10;
    if (winH + sTop - top < ph && off.top - sTop > ph) top = off.top - ph - 5;
    if (top + ph > winH + sTop) top = Math.max(sTop + 10, winH + sTop - ph - 10);
    if (top < sTop + 10) top = sTop + 10;
    panel.css({ top: `${top}px`, left: `${left}px` });
}

function actualizarIndicadoresFiltros() {
    $('.filter-icon').removeClass('has-filter');
    Object.keys(filtrosActivos).forEach(col => {
        const val = filtrosActivos[col];
        const activo = (Array.isArray(val) && val.length > 0) ||
            (!Array.isArray(val) && typeof val === 'object' && (val.desde || val.hasta)) ||
            (!Array.isArray(val) && typeof val !== 'object' && String(val).trim() !== '');
        if (activo) $(`th[data-column="${col}"] .filter-icon`).addClass('has-filter');
    });
}

function limpiarFiltro(columna) {
    delete filtrosActivos[columna];
    cerrarTodosFiltros();
    paginaActual = 1;
    cargarDatos();
}

function cerrarTodosFiltros() {
    $('.filter-panel').remove();
    $('.filter-icon').removeClass('active');
    panelFiltroAbierto = null;
}

function aplicarOrden(columna, direccion) {
    ordenActivo = { columna, direccion };
    cerrarTodosFiltros();
    paginaActual = 1;
    cargarDatos();
}

function filtrarBusqueda(columna, valor) {
    if (valor.trim() === '') delete filtrosActivos[columna];
    else filtrosActivos[columna] = valor.trim();
    paginaActual = 1;
    cargarDatos();
}

function toggleOpcionFiltro(columna, valor, checked) {
    if (!filtrosActivos[columna]) filtrosActivos[columna] = [];
    if (checked) {
        if (!filtrosActivos[columna].includes(valor)) filtrosActivos[columna].push(valor);
    } else {
        filtrosActivos[columna] = filtrosActivos[columna].filter(v => v !== valor);
        if (filtrosActivos[columna].length === 0) delete filtrosActivos[columna];
    }
    paginaActual = 1;
    cargarDatos();
}

function buscarEnOpciones(input) {
    const b = input.value.toLowerCase();
    $(input).siblings('.filter-options').find('.filter-option').each(function () {
        $(this).toggle($(this).text().toLowerCase().includes(b));
    });
}

function cambiarRegistrosPorPagina() {
    registrosPorPagina = parseInt($('#registrosPorPagina').val(), 10) || 25;
    paginaActual = 1;
    cargarDatos();
}

function renderizarPaginacion(total) {
    const totalPaginas = Math.ceil(total / registrosPorPagina);
    const $pag = $('#paginacion').empty();

    $pag.append(`<button class="pagination-btn" onclick="cambiarPagina(${paginaActual - 1})" ${paginaActual === 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button>`);

    let ini = Math.max(1, paginaActual - 2);
    let fin = Math.min(totalPaginas, paginaActual + 2);

    if (ini > 1) {
        $pag.append('<button class="pagination-btn" onclick="cambiarPagina(1)">1</button>');
        if (ini > 2) $pag.append('<span class="pagination-btn" disabled>...</span>');
    }
    for (let i = ini; i <= fin; i++) {
        $pag.append(`<button class="pagination-btn ${i === paginaActual ? 'active' : ''}" onclick="cambiarPagina(${i})">${i}</button>`);
    }
    if (fin < totalPaginas) {
        if (fin < totalPaginas - 1) $pag.append('<span class="pagination-btn" disabled>...</span>');
        $pag.append(`<button class="pagination-btn" onclick="cambiarPagina(${totalPaginas})">${totalPaginas}</button>`);
    }
    $pag.append(`<button class="pagination-btn" onclick="cambiarPagina(${paginaActual + 1})" ${paginaActual >= totalPaginas ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button>`);
}

function cambiarPagina(pagina) {
    const total = Math.ceil(totalRegistros / registrosPorPagina);
    if (pagina < 1 || pagina > total) return;
    paginaActual = pagina;
    cargarDatos();
}

/* ============================================================
 * HELPERS
 * ============================================================ */
function formatearMonto(v) {
    const n = parseFloat(v) || 0;
    return n.toLocaleString('es-NI', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

const MESES_CORTOS = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

function separarFechaHora(str) {
    if (typeof window.separarFechaHoraPOS === 'function') {
        return window.separarFechaHoraPOS(str);
    }
    if (!str) return { fecha: '—', hora: '—' };
    const dt = (typeof window.parseFechaHoraLocalPOS === 'function') ? window.parseFechaHoraLocalPOS(str) : new Date(str.replace(' ', 'T'));
    if (isNaN(dt)) return { fecha: '—', hora: '—' };
    const dia  = String(dt.getDate()).padStart(2, '0');
    const mes  = MESES_CORTOS[dt.getMonth()];
    const anio = String(dt.getFullYear()).slice(-2);
    const fecha = `${dia}-${mes}-${anio}`;
    let horas = dt.getHours();
    const min = String(dt.getMinutes()).padStart(2, '0');
    const ampm = horas >= 12 ? 'pm' : 'am';
    horas = horas % 12 || 12;
    const hora = String(horas).padStart(2, '0') + ':' + min + ' ' + ampm;
    return { fecha, hora };
}

function formatearFechaHora(str) {
    const { fecha, hora } = separarFechaHora(str);
    if (fecha === '—') return '—';
    return `${fecha} ${hora}`;
}

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ============================================================
 * REIMPRESIÓN
 * ============================================================ */
function reimprimir(id) {
    const $btn = $(`button[onclick="reimprimir(${id})"]`).first();
    const iconOrig = '<i class="bi bi-printer-fill"></i>';
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    $.get('ajax/facturas_get_imprimibles.php', { id_factura: id }, function (payload) {
        $btn.prop('disabled', false).html(iconOrig);

        if (!payload.success) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: payload.message || 'No se pudieron obtener los datos de impresión.',
                confirmButtonColor: '#0E544C'
            });
            return;
        }

        lanzarImpresionCompleta(payload);

    }, 'json').fail(function () {
        $btn.prop('disabled', false).html(iconOrig);
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor para obtener los datos de impresión.',
            confirmButtonColor: '#0E544C'
        });
    });
}
