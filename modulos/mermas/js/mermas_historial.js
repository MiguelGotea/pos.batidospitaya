/* ============================================================
   mermas_historial.js — Módulo Mermas POS
   ============================================================ */

let paginaActual = 1;
let registrosPorPagina = 25;
let filtrosActivos = {};
let ordenActivo = { columna: null, direccion: 'asc' };
let panelFiltroAbierto = null;
let totalRegistros = 0;
let scrollTopInicial = 0;
let windowWidthInicial = $(window).width();
let modalDetalle = null;

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
        const currentWidth = $(window).width();
        if (panelFiltroAbierto && currentWidth !== windowWidthInicial) {
            cerrarTodosFiltros();
            windowWidthInicial = currentWidth;
        }
    });
});

function cargarDatos() {
    $('#tablaMermasBody').html(`
        <tr>
            <td colspan="7" class="text-center text-muted py-4">
                <div class="spinner-border spinner-border-sm me-2"></div> Cargando...
            </td>
        </tr>`);

    $.ajax({
        url: 'ajax/mermas_get_historial.php',
        method: 'POST',
        dataType: 'json',
        data: {
            pagina: paginaActual,
            registros_por_pagina: registrosPorPagina,
            filtros: JSON.stringify(filtrosActivos),
            orden: JSON.stringify(ordenActivo)
        },
        success: function (response) {
            if (response.success) {
                totalRegistros = response.total_registros;
                renderizarTabla(response.datos || []);
                renderizarPaginacion(totalRegistros);
                actualizarIndicadoresFiltros();
            } else {
                $('#tablaMermasBody').html(`<tr><td colspan="7" class="text-center text-danger">${escHtml(response.message || 'Error al cargar')}</td></tr>`);
            }
        },
        error: function () {
            $('#tablaMermasBody').html('<tr><td colspan="7" class="text-center text-danger">Error al cargar los datos.</td></tr>');
        }
    });
}

function renderizarTabla(mermas) {
    if (!mermas || mermas.length === 0) {
        $('#tablaMermasBody').html(`
            <tr><td colspan="7" class="text-center text-muted py-4">
                <i class="bi bi-inbox me-2"></i>No se encontraron registros.
            </td></tr>`);
        return;
    }

    let html = '';
    mermas.forEach(m => {
        const badgeCat  = badgeCategoria(m.categoria_merma);
        const badgeCobro = m.es_cobro == 1 ? '<span class="badge-cobro">Cobro</span>' : '';

        html += `
        <tr>
            <td style="font-size:.82rem; color:#64748b;">${formatearFechaHoraMerma(m.fecha_hora)}</td>
            <td><strong>${escHtml(m.tipo_incidencia)}</strong>${badgeCobro}</td>
            <td>${badgeCat}</td>
            <td>
                <strong>${escHtml(m.producto)}</strong>
                <small class="d-block text-muted">${escHtml(m.SKU || '')}</small>
            </td>
            <td class="text-end fw-bold" style="font-variant-numeric:tabular-nums;">${parseFloat(m.cantidad).toFixed(2)}</td>
            <td style="font-size:.82rem;">${escHtml(m.registrado_por || '-')}</td>
            <td class="text-center">
                <button class="btn-ver-detalle" onclick="verDetalle(${m.id})">
                    <i class="bi bi-eye"></i> Ver
                </button>
            </td>
        </tr>`;
    });

    $('#tablaMermasBody').html(html);
}

function badgeCategoria(cat) {
    if (!cat) return '<span class="badge-merma default">—</span>';
    const c = cat.toLowerCase();
    let cls = 'default';
    if (c.includes('operativa'))  cls = 'operativa';
    else if (c.includes('natural') || c.includes('vida')) cls = 'natural';
    else if (c.includes('administrativa')) cls = 'administrativa';
    else if (c.includes('falla') || c.includes('equipo')) cls = 'equipo';
    else if (c.includes('calidad') || c.includes('proveedor')) cls = 'calidad';
    return `<span class="badge-merma ${cls}">${escHtml(cat)}</span>`;
}

/* ---- Filtros ---- */
function toggleFilter(icon) {
    const th = $(icon).closest('th');
    const columna = th.data('column');
    const tipo    = th.data('type');
    if (!columna || !tipo) return;
    if (panelFiltroAbierto === columna) { cerrarTodosFiltros(); return; }
    cerrarTodosFiltros();
    scrollTopInicial    = $(window).scrollTop();
    windowWidthInicial  = $(window).width();
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

    if (tipo === 'text') {
        const val = filtrosActivos[columna] || '';
        panel.append(`
            <div class="filter-section" style="margin-top:12px;">
                <span class="filter-section-title">Buscar:</span>
                <input type="text" class="filter-search" placeholder="Escribir..."
                       value="${escHtml(val)}"
                       oninput="filtrarBusqueda('${columna}', this.value)">
            </div>`);
        posicionarPanel(panel, icon);
    } else if (tipo === 'list') {
        cargarOpcionesLista(panel, columna, icon);
    } else if (tipo === 'daterange') {
        crearCalendario(panel, columna);
        posicionarPanel(panel, icon);
    } else if (tipo === 'number') {
        const val = filtrosActivos[columna] || '';
        panel.append(`
            <div class="filter-section" style="margin-top:12px;">
                <span class="filter-section-title">Contiene número:</span>
                <input type="text" class="filter-search" placeholder="Ej: 2.5"
                       value="${escHtml(val)}"
                       oninput="filtrarBusqueda('${columna}', this.value)">
            </div>`);
        posicionarPanel(panel, icon);
    }
}

function cargarOpcionesLista(panel, columna, icon) {
    $.ajax({
        url: 'ajax/mermas_get_opciones_filtro.php',
        method: 'POST',
        dataType: 'json',
        data: { columna },
        success: function (res) {
            if (!res.success) {
                panel.append('<div class="text-danger" style="margin-top:8px;">No se pudieron cargar opciones.</div>');
                posicionarPanel(panel, icon);
                return;
            }
            let html = '<div class="filter-section" style="margin-top:12px;">';
            html += '<span class="filter-section-title">Filtrar por:</span>';
            html += '<input type="text" class="filter-search" placeholder="Buscar..." onkeyup="buscarEnOpciones(this)">';
            html += '<div class="filter-options">';
            (res.opciones || []).forEach(op => {
                const val  = String(op.valor ?? '');
                const txt  = String(op.texto ?? '');
                const chk  = filtrosActivos[columna] && filtrosActivos[columna].includes(val) ? 'checked' : '';
                html += `<div class="filter-option">
                    <input type="checkbox" value="${escHtml(val)}" ${chk}
                           onchange="toggleOpcionFiltro('${columna}', '${escHtml(val)}', this.checked)">
                    <span>${escHtml(txt)}</span>
                </div>`;
            });
            html += '</div></div>';
            panel.append(html);
            posicionarPanel(panel, icon);
        }
    });
}

function crearCalendario(panel, columna) {
    const hoy = new Date();
    panel.append(`
        <div class="filter-section" style="margin-top:8px;">
            <span class="filter-section-title">Seleccionar rango:</span>
            <div class="daterange-calendar-container">
                <div class="daterange-month-selector">
                    <select id="mesCalendario" onchange="actualizarCalendarioMermas('${columna}')"></select>
                    <select id="anioCalendario" onchange="actualizarCalendarioMermas('${columna}')"></select>
                </div>
                <div class="daterange-calendar" id="calendarioUnico"></div>
            </div>
        </div>`);

    setTimeout(() => {
        const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        const selMes = $('#mesCalendario'), selAnio = $('#anioCalendario');
        meses.forEach((m, i) => selMes.append(`<option value="${i}" ${i === hoy.getMonth() ? 'selected' : ''}>${m}</option>`));
        for (let y = hoy.getFullYear() - 5; y <= hoy.getFullYear() + 1; y++) {
            selAnio.append(`<option value="${y}" ${y === hoy.getFullYear() ? 'selected' : ''}>${y}</option>`);
        }
        actualizarCalendarioMermas(columna);
    }, 30);
}

function actualizarCalendarioMermas(columna) {
    const mes = parseInt($('#mesCalendario').val(), 10);
    const anio = parseInt($('#anioCalendario').val(), 10);
    const primerDia = new Date(anio, mes, 1).getDay();
    const diasEnMes = new Date(anio, mes + 1, 0).getDate();
    const dias = ['D','L','M','M','J','V','S'];
    let html = '<div class="daterange-calendar-header">';
    dias.forEach(d => { html += `<div class="daterange-calendar-day-name">${d}</div>`; });
    html += '</div><div class="daterange-calendar-days">';
    for (let i = 0; i < primerDia; i++) html += '<div class="daterange-calendar-day empty"></div>';
    for (let d = 1; d <= diasEnMes; d++) {
        const fs = `${anio}-${String(mes+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const cls = clasesCalendario(fs, columna);
        html += `<div class="daterange-calendar-day ${cls}" onclick="event.stopPropagation(); seleccionarRango('${fs}', '${columna}')">${d}</div>`;
    }
    html += '</div>';
    $('#calendarioUnico').html(html);
}

function clasesCalendario(fecha, columna) {
    const desde = filtrosActivos[columna]?.desde;
    const hasta = filtrosActivos[columna]?.hasta;
    if (fecha === desde || fecha === hasta) return 'selected';
    if (desde && hasta && fecha > desde && fecha < hasta) return 'in-range';
    return '';
}

function seleccionarRango(fecha, columna) {
    if (!filtrosActivos[columna]) filtrosActivos[columna] = { desde: null, hasta: null };
    const { desde, hasta } = filtrosActivos[columna];
    if (!desde) { filtrosActivos[columna].desde = fecha; }
    else if (!hasta) {
        if (fecha < desde) { filtrosActivos[columna].desde = fecha; filtrosActivos[columna].hasta = desde; }
        else filtrosActivos[columna].hasta = fecha;
    } else { filtrosActivos[columna].desde = fecha; filtrosActivos[columna].hasta = null; }
    actualizarCalendarioMermas(columna);
    if (filtrosActivos[columna].desde && filtrosActivos[columna].hasta) { paginaActual = 1; cargarDatos(); }
}

function posicionarPanel(panel, icon) {
    const off = $(icon).offset();
    const pw  = panel.outerWidth();
    const ph  = panel.outerHeight();
    const ww  = $(window).width();
    const st  = $(window).scrollTop();
    let top  = off.top + $(icon).outerHeight() + 5;
    let left = off.left - pw + $(icon).outerWidth();
    if (left + pw > ww) left = ww - pw - 10;
    if (left < 10) left = 10;
    if (top + ph > $(window).height() + st) top = Math.max(st + 10, off.top - ph - 5);
    panel.css({ top: `${top}px`, left: `${left}px` });
}

function actualizarIndicadoresFiltros() {
    $('.filter-icon').removeClass('has-filter');
    Object.keys(filtrosActivos).forEach(col => {
        const v = filtrosActivos[col];
        const activo = (Array.isArray(v) && v.length > 0) ||
            (!Array.isArray(v) && typeof v === 'object' && (v.desde || v.hasta)) ||
            (!Array.isArray(v) && typeof v !== 'object' && String(v).trim() !== '');
        if (activo) $(`th[data-column="${col}"] .filter-icon`).addClass('has-filter');
    });
}

function limpiarFiltro(columna) { delete filtrosActivos[columna]; cerrarTodosFiltros(); paginaActual = 1; cargarDatos(); }
function cerrarTodosFiltros() { $('.filter-panel').remove(); $('.filter-icon').removeClass('active'); panelFiltroAbierto = null; }
function aplicarOrden(col, dir) { ordenActivo = { columna: col, direccion: dir }; cerrarTodosFiltros(); paginaActual = 1; cargarDatos(); }
function filtrarBusqueda(col, val) { if (val.trim() === '') delete filtrosActivos[col]; else filtrosActivos[col] = val.trim(); paginaActual = 1; cargarDatos(); }
function toggleOpcionFiltro(col, val, checked) {
    if (!filtrosActivos[col]) filtrosActivos[col] = [];
    if (checked) { if (!filtrosActivos[col].includes(val)) filtrosActivos[col].push(val); }
    else { filtrosActivos[col] = filtrosActivos[col].filter(v => v !== val); if (!filtrosActivos[col].length) delete filtrosActivos[col]; }
    paginaActual = 1; cargarDatos();
}
function buscarEnOpciones(input) {
    const q = input.value.toLowerCase();
    $(input).siblings('.filter-options').find('.filter-option').each(function () { $(this).toggle($(this).text().toLowerCase().includes(q)); });
}
function cambiarRegistrosPorPagina() { registrosPorPagina = parseInt($('#registrosPorPagina').val(), 10) || 25; paginaActual = 1; cargarDatos(); }

/* ---- Paginación ---- */
function renderizarPaginacion(total) {
    const totalPag = Math.ceil(total / registrosPorPagina);
    const p = $('#paginacion').empty();
    p.append(`<button class="pagination-btn" onclick="cambiarPagina(${paginaActual-1})" ${paginaActual===1?'disabled':''}><i class="bi bi-chevron-left"></i></button>`);
    let ini = Math.max(1, paginaActual-2), fin = Math.min(totalPag, paginaActual+2);
    if (ini > 1) { p.append('<button class="pagination-btn" onclick="cambiarPagina(1)">1</button>'); if (ini > 2) p.append('<span class="pagination-btn" disabled>...</span>'); }
    for (let i = ini; i <= fin; i++) p.append(`<button class="pagination-btn ${i===paginaActual?'active':''}" onclick="cambiarPagina(${i})">${i}</button>`);
    if (fin < totalPag) { if (fin < totalPag-1) p.append('<span class="pagination-btn" disabled>...</span>'); p.append(`<button class="pagination-btn" onclick="cambiarPagina(${totalPag})">${totalPag}</button>`); }
    p.append(`<button class="pagination-btn" onclick="cambiarPagina(${paginaActual+1})" ${paginaActual>=totalPag?'disabled':''}><i class="bi bi-chevron-right"></i></button>`);
}
function cambiarPagina(pg) { const tp = Math.ceil(totalRegistros/registrosPorPagina); if (pg<1||pg>tp) return; paginaActual=pg; cargarDatos(); }

/* ---- Modal detalle ---- */
function verDetalle(id) {
    $('#infoCabeceraModal').html('<div class="col-12 text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Cargando…</div>');
    $('#tablaDetalleModal').html('<tr><td colspan="4" class="text-center text-muted">Cargando…</td></tr>');
    $('#notasModal').text('');
    modalDetalle.show();

    $.get('ajax/mermas_get_detalle.php', { id }, function (res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); modalDetalle.hide(); return; }
        const m = res.merma;
        const badgeCobro  = m.es_cobro == 1  ? '<span class="badge-cobro ms-1">Cobro</span>' : '';
        const badgeAprov  = m.necesita_aprobacion == 1 ? '<span class="badge-merma administrativa ms-1" style="font-size:.68rem;">Requiere aprobación</span>' : '';

        $('#infoCabeceraModal').html(`
            <div class="col-sm-4">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Tipo de Incidencia</small>
                <span class="fw-bold">${escHtml(m.tipo_incidencia)}${badgeCobro}</span>
            </div>
            <div class="col-sm-4">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Categoría</small>
                <span>${badgeCategoria(m.categoria_merma)}${badgeAprov}</span>
            </div>
            <div class="col-sm-4">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Fecha / Hora</small>
                <span>${formatearFechaHoraMerma(m.fecha_hora)}</span>
            </div>
            <div class="col-sm-4 mt-1">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Sucursal</small>
                <span>${escHtml(m.cod_sucursal)}</span>
            </div>
            <div class="col-sm-8 mt-1">
                <small class="text-muted d-block fw-bold text-uppercase" style="font-size:.72rem;">Registrado por</small>
                <span style="font-size:.875rem;">${escHtml(m.registrado_por || '—')}</span>
            </div>`);

        if (!res.lineas || !res.lineas.length) {
            $('#tablaDetalleModal').html('<tr><td colspan="4" class="text-center text-muted">Sin líneas de producto.</td></tr>');
        } else {
            let rows = '';
            res.lineas.forEach((l, i) => {
                rows += `<tr>
                    <td>${i+1}</td>
                    <td><strong>${escHtml(l.producto)}</strong><small class="d-block text-muted">${escHtml(l.SKU||'')}</small></td>
                    <td class="text-end">${parseFloat(l.cantidad).toFixed(2)}</td>
                    <td>${escHtml(l.observacion||'—')}</td>
                </tr>`;
            });
            $('#tablaDetalleModal').html(rows);
        }
        if (m.observacion) $('#notasModal').text('Obs: ' + m.observacion);
        $('#modalDetalleLabel').html(`<i class="fas fa-box-open me-2"></i>Merma #${m.id} — ${escHtml(m.tipo_incidencia)}`);
    }, 'json').fail(() => { Swal.fire('Error', 'No se pudo cargar el detalle.', 'error'); modalDetalle.hide(); });
}

/* ---- Helpers ---- */
const MESES_M = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
function parseFechaM(str) {
    if (!str) return null;
    str = String(str).trim();
    const m = str.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T\s](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/);
    if (m) return new Date(+m[1], +m[2]-1, +m[3], m[4]?+m[4]:0, m[5]?+m[5]:0, m[6]?+m[6]:0);
    return null;
}
function formatearFechaHoraMerma(str) {
    const d = parseFechaM(str);
    if (!d) return str || '-';
    const dia = String(d.getDate()).padStart(2,'0');
    const mes = MESES_M[d.getMonth()];
    const anio = String(d.getFullYear()).slice(-2);
    let h = d.getHours(), min = String(d.getMinutes()).padStart(2,'0');
    const ap = h >= 12 ? 'pm' : 'am'; h = h%12||12;
    return `${dia}-${mes}-${anio} ${String(h).padStart(2,'0')}:${min}${ap}`;
}
function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
