/**
 * aligeramiento.js
 * Módulo Aligeramientos POS — Historial + Registro + Vouchers IA
 */

/* ================================================================
   CONFIGURACIÓN GLOBAL
   ================================================================ */
const DENOMS_NIO = [0.5, 1, 5, 10, 20, 50, 100, 200, 500];
const DENOMS_USD = [1, 5, 10, 20, 50, 100];

const fmtNIO = n => 'C$ ' + parseFloat(n || 0).toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtUSD = n => '$ '  + parseFloat(n || 0).toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/* ================================================================
   HISTORIAL — paginación y filtros
   ================================================================ */
let paginaActual        = 1;
let registrosPorPagina  = 25;
let filtrosActivos      = {};
let ordenActivo         = { columna: 'fecha', direccion: 'desc' };
let panelFiltroAbierto  = null;
let totalRegistros      = 0;
let scrollTopInicial    = 0;

$(document).ready(function () {
    cargarHistorial();

    // Cerrar panel de filtros al hacer clic fuera
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.filter-panel, .filter-icon').length) {
            cerrarTodosFiltros();
        }
    });

    $(window).on('scroll', function () {
        if (panelFiltroAbierto && Math.abs($(window).scrollTop() - scrollTopInicial) > 50) {
            cerrarTodosFiltros();
        }
    });

    $(window).on('resize', function () {
        if (panelFiltroAbierto) cerrarTodosFiltros();
    });

    // Búsqueda global con debounce
    let debounceTimer;
    $('#buscarGlobal').on('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            paginaActual = 1;
            cargarHistorial();
        }, 300);
    });
});

/* ----------------------------------------------------------------
   CARGAR HISTORIAL
   ---------------------------------------------------------------- */
function cargarHistorial() {
    const tbody = $('#tablaAligBody');
    if (paginaActual === 1 && totalRegistros === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Cargando...</td></tr>');
    }
    $('#buscarGlobalSpinner').show();

    $.ajax({
        url:    'ajax/aligeramiento_get_historial.php',
        method: 'POST',
        data: {
            pagina:               paginaActual,
            registros_por_pagina: registrosPorPagina,
            filtros:              JSON.stringify(filtrosActivos),
            orden:                JSON.stringify(ordenActivo),
            busqueda_global:      $('#buscarGlobal').val() || ''
        },
        dataType: 'json',
        success: function (res) {
            $('#buscarGlobalSpinner').hide();
            if (res.success) {
                totalRegistros = res.total_registros;
                renderizarTabla(res.datos);
                renderizarPaginacion(res.total_registros);
                actualizarIndicadoresFiltros();
            } else {
                tbody.html(`<tr><td colspan="8" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>${res.message || 'Error al cargar datos.'}</td></tr>`);
            }
        },
        error: function () {
            $('#buscarGlobalSpinner').hide();
            $('#tablaAligBody').html('<tr><td colspan="8" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Error de conexión.</td></tr>');
        }
    });
}

/* ----------------------------------------------------------------
   RENDERIZAR TABLA
   ---------------------------------------------------------------- */
function renderizarTabla(datos) {
    const tbody = $('#tablaAligBody');
    tbody.empty();

    if (!datos || datos.length === 0) {
        tbody.html('<tr><td colspan="8" class="text-center py-4 text-muted">No se encontraron aligeramientos.</td></tr>');
        return;
    }

    let html = '';
    datos.forEach(function (item) {
        const totalNIO    = item.total_cordobas > 0
            ? `<span class="badge-nio">C$ ${parseFloat(item.total_cordobas).toLocaleString('en-US',{minimumFractionDigits:2})}</span>`
            : '<span class="text-muted small">—</span>';

        const totalUSD    = item.total_dolares > 0
            ? `<span class="badge-usd">$ ${parseFloat(item.total_dolares).toLocaleString('en-US',{minimumFractionDigits:2})}</span>`
            : '<span class="text-muted small">—</span>';

        const totalGlobal = `<span class="badge-global">C$ ${parseFloat(item.total_global).toLocaleString('en-US',{minimumFractionDigits:2})}</span>`;

        // Columna vouchers
        let voucherCell = '';
        const vc = item.voucher_count;
        if (vc === 0) {
            voucherCell = `<button class="voucher-btn voucher-btn-empty" onclick="abrirModalVouchers(${item.id}, ${item.total_global})">
                <i class="bi bi-camera"></i> Agregar
            </button>`;
        } else if (item.tiene_alerta_voucher) {
            voucherCell = `<button class="voucher-btn voucher-btn-alert" onclick="abrirModalVouchers(${item.id}, ${item.total_global})">
                <i class="bi bi-exclamation-triangle-fill"></i> ${vc}
            </button>`;
        } else {
            voucherCell = `<button class="voucher-btn voucher-btn-ok" onclick="abrirModalVouchers(${item.id}, ${item.total_global})">
                <i class="bi bi-check-circle-fill"></i> ${vc}
            </button>`;
        }

        const obs = item.observacion ? `<small class="text-muted">${item.observacion}</small>` : '<span class="text-muted small">—</span>';

        html += `
        <tr>
            <td class="text-muted small">${item.fecha_formateada}</td>
            <td class="text-muted small">${item.hora_formateada}</td>
            <td>${totalNIO}</td>
            <td>${totalUSD}</td>
            <td>${totalGlobal}</td>
            <td>${obs}</td>
            <td><small class="text-muted">${item.usuario_nombre}</small></td>
            <td class="text-center">${voucherCell}</td>
        </tr>`;
    });

    tbody.html(html);
}

/* ----------------------------------------------------------------
   PAGINACIÓN
   ---------------------------------------------------------------- */
function renderizarPaginacion(total) {
    const totalPaginas = Math.ceil(total / registrosPorPagina);
    const pag = $('#paginacion');
    pag.empty();

    pag.append(`<button class="pagination-btn" onclick="cambiarPagina(${paginaActual - 1})" ${paginaActual === 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button>`);

    let inicio = Math.max(1, paginaActual - 2);
    let fin    = Math.min(totalPaginas, paginaActual + 2);

    if (inicio > 1) {
        pag.append(`<button class="pagination-btn" onclick="cambiarPagina(1)">1</button>`);
        if (inicio > 2) pag.append(`<span class="pagination-btn" disabled>...</span>`);
    }
    for (let i = inicio; i <= fin; i++) {
        pag.append(`<button class="pagination-btn ${i === paginaActual ? 'active' : ''}" onclick="cambiarPagina(${i})">${i}</button>`);
    }
    if (fin < totalPaginas) {
        if (fin < totalPaginas - 1) pag.append(`<span class="pagination-btn" disabled>...</span>`);
        pag.append(`<button class="pagination-btn" onclick="cambiarPagina(${totalPaginas})">${totalPaginas}</button>`);
    }

    pag.append(`<button class="pagination-btn" onclick="cambiarPagina(${paginaActual + 1})" ${paginaActual === totalPaginas || totalPaginas === 0 ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button>`);
}

function cambiarPagina(pagina) {
    const max = Math.ceil(totalRegistros / registrosPorPagina);
    if (pagina < 1 || pagina > max) return;
    paginaActual = pagina;
    cargarHistorial();
}

function cambiarRegistrosPorPagina() {
    registrosPorPagina = parseInt($('#registrosPorPagina').val());
    paginaActual = 1;
    cargarHistorial();
}

/* ================================================================
   FILTROS
   ================================================================ */
function toggleFilter(icon) {
    const th     = $(icon).closest('th');
    const col    = th.data('column');
    const tipo   = th.data('type');

    if (panelFiltroAbierto === col) { cerrarTodosFiltros(); return; }

    cerrarTodosFiltros();
    scrollTopInicial = $(window).scrollTop();
    crearPanelFiltro(th, col, tipo, icon);
    panelFiltroAbierto = col;
    $(icon).addClass('active');
    actualizarIndicadoresFiltros();
}

function crearPanelFiltro(th, col, tipo, icon) {
    const panel = $('<div class="filter-panel show"></div>');
    if (tipo === 'daterange') panel.addClass('has-daterange');

    panel.append(`
        <div class="filter-section">
            <span class="filter-section-title">Ordenar:</span>
            <div class="filter-sort-buttons">
                <button class="filter-sort-btn ${ordenActivo.columna === col && ordenActivo.direccion === 'asc' ? 'active' : ''}"
                        onclick="aplicarOrden('${col}', 'asc')">
                    <i class="bi bi-sort-alpha-down"></i> A→Z
                </button>
                <button class="filter-sort-btn ${ordenActivo.columna === col && ordenActivo.direccion === 'desc' ? 'active' : ''}"
                        onclick="aplicarOrden('${col}', 'desc')">
                    <i class="bi bi-sort-alpha-up"></i> Z→A
                </button>
            </div>
        </div>
        <div class="filter-actions">
            <button class="filter-action-btn clear" onclick="limpiarFiltro('${col}')">
                <i class="bi bi-x-circle"></i> Limpiar
            </button>
        </div>
    `);

    $('body').append(panel);

    if (tipo === 'text') {
        const val = filtrosActivos[col] || '';
        panel.append(`<div class="filter-section" style="margin-top:12px;">
            <span class="filter-section-title">Buscar:</span>
            <input type="text" class="filter-search" placeholder="Escribir..." value="${val}"
                   oninput="filtrarBusqueda('${col}', this.value)">
        </div>`);
        posicionarPanel(panel, icon);
    } else if (tipo === 'number') {
        crearFiltroNumerico(panel, col);
        posicionarPanel(panel, icon);
    } else if (tipo === 'daterange') {
        crearCalendarioDoble(panel, col);
        posicionarPanel(panel, icon);
    }
}

function crearFiltroNumerico(panel, col) {
    const mn = filtrosActivos[col]?.min || '';
    const mx = filtrosActivos[col]?.max || '';
    panel.append(`<div class="filter-section" style="margin-top:12px;">
        <span class="filter-section-title">Rango:</span>
        <div style="display:flex;gap:6px;">
            <input type="number" class="filter-search" placeholder="Mínimo" value="${mn}"
                   onchange="filtrarNumerico('${col}','min',this.value)" style="width:50%">
            <input type="number" class="filter-search" placeholder="Máximo" value="${mx}"
                   onchange="filtrarNumerico('${col}','max',this.value)" style="width:50%">
        </div>
    </div>`);
}

function filtrarNumerico(col, tipo, valor) {
    if (!filtrosActivos[col]) filtrosActivos[col] = {};
    if (valor === '') {
        delete filtrosActivos[col][tipo];
        if (!Object.keys(filtrosActivos[col]).length) delete filtrosActivos[col];
    } else {
        filtrosActivos[col][tipo] = valor;
    }
    paginaActual = 1; cargarHistorial();
}

function crearCalendarioDoble(panel, col) {
    const hoy = new Date();
    const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    panel.append(`<div class="filter-section" style="margin-top:8px;">
        <span class="filter-section-title">Rango de fechas:</span>
        <div class="daterange-calendar-container">
            <div class="daterange-month-selector">
                <select id="mesCalAlig" onchange="actualizarCalAlig('${col}')"></select>
                <select id="anioCalAlig" onchange="actualizarCalAlig('${col}')"></select>
            </div>
            <div class="daterange-calendar" id="calAlig"></div>
        </div>
        <div style="font-size:.75rem;color:#666;margin-top:6px;">
            <i class="bi bi-info-circle"></i> Clic en dos fechas para definir rango.
        </div>
    </div>`);

    setTimeout(function () {
        const selMes  = $('#mesCalAlig');
        const selAnio = $('#anioCalAlig');
        const anioAct = hoy.getFullYear();
        const mesAct  = hoy.getMonth();

        meses.forEach((m, i) => selMes.append(`<option value="${i}" ${i === mesAct ? 'selected' : ''}>${m}</option>`));
        for (let a = anioAct - 5; a <= anioAct + 1; a++) {
            selAnio.append(`<option value="${a}" ${a === anioAct ? 'selected' : ''}>${a}</option>`);
        }
        actualizarCalAlig(col);
    }, 50);
}

function actualizarCalAlig(col) {
    const mes  = parseInt($('#mesCalAlig').val());
    const anio = parseInt($('#anioCalAlig').val());
    const primer = new Date(anio, mes, 1).getDay();
    const dias   = new Date(anio, mes + 1, 0).getDate();
    const dias_s = ['D','L','M','M','J','V','S'];

    let html = '<div class="daterange-calendar-header">';
    dias_s.forEach(d => html += `<div class="daterange-calendar-day-name">${d}</div>`);
    html += '</div><div class="daterange-calendar-days">';
    for (let i = 0; i < primer; i++) html += '<div class="daterange-calendar-day empty"></div>';
    for (let d = 1; d <= dias; d++) {
        const f = `${anio}-${String(mes+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const cls = obtenerClasesCal(f, col);
        html += `<div class="daterange-calendar-day ${cls}" onclick="event.stopPropagation();seleccionarFechaCal('${f}','${col}')">${d}</div>`;
    }
    html += '</div>';
    $('#calAlig').html(html);
}

function obtenerClasesCal(fecha, col) {
    const desde = filtrosActivos[col]?.desde;
    const hasta = filtrosActivos[col]?.hasta;
    let c = [];
    if (desde && fecha === desde) c.push('selected');
    if (hasta && fecha === hasta) c.push('selected');
    if (desde && hasta && fecha > desde && fecha < hasta) c.push('in-range');
    return c.join(' ');
}

function seleccionarFechaCal(fecha, col) {
    if (!filtrosActivos[col]) filtrosActivos[col] = { desde: null, hasta: null };
    const f = filtrosActivos[col];
    if (!f.desde) {
        f.desde = fecha;
    } else if (!f.hasta) {
        if (fecha < f.desde) { f.hasta = f.desde; f.desde = fecha; }
        else { f.hasta = fecha; }
    } else {
        if (fecha < f.desde) f.desde = fecha;
        else f.hasta = fecha;
    }
    actualizarCalAlig(col);
    if (f.desde && f.hasta) { paginaActual = 1; cargarHistorial(); }
}

function posicionarPanel(panel, icon) {
    const off  = $(icon).offset();
    const pw   = panel.outerWidth();
    const ph   = panel.outerHeight();
    const ww   = $(window).width();
    const wh   = $(window).height();
    const st   = $(window).scrollTop();

    let top  = off.top + $(icon).outerHeight() + 5;
    let left = off.left - pw + $(icon).outerWidth();

    if (left + pw > ww) left = ww - pw - 10;
    if (left < 10) left = 10;
    if (top + ph > wh + st) top = Math.max(st + 10, off.top - ph - 5);

    panel.css({ top: top + 'px', left: left + 'px' });
}

function actualizarIndicadoresFiltros() {
    $('.filter-icon').removeClass('has-filter');
    Object.keys(filtrosActivos).forEach(col => {
        const v = filtrosActivos[col];
        const tiene = Array.isArray(v) ? v.length > 0 :
            typeof v === 'object' ? Object.keys(v).length > 0 : v !== '';
        if (tiene) $(`th[data-column="${col}"] .filter-icon`).addClass('has-filter');
    });
}

function limpiarFiltro(col) {
    delete filtrosActivos[col];
    cerrarTodosFiltros();
    paginaActual = 1;
    cargarHistorial();
}

function cerrarTodosFiltros() {
    $('.filter-panel').remove();
    $('.filter-icon').removeClass('active');
    panelFiltroAbierto = null;
}

function aplicarOrden(col, dir) {
    ordenActivo = { columna: col, direccion: dir };
    cerrarTodosFiltros();
    paginaActual = 1;
    cargarHistorial();
}

function filtrarBusqueda(col, valor) {
    if (!valor.trim()) delete filtrosActivos[col];
    else filtrosActivos[col] = valor;
    paginaActual = 1;
    cargarHistorial();
}

/* ================================================================
   MODAL REGISTRO — Denominaciones
   ================================================================ */
let _modalRegistro = null;

function abrirModalRegistro() {
    if (!_modalRegistro) {
        _modalRegistro = new bootstrap.Modal(document.getElementById('modalNuevoAlig'));
    }
    // Resetear formulario
    $('.qty-input').val(0);
    $('#inputObservacion').val('');
    recalcular();
    _modalRegistro.show();
}

/* ---- Construir filas de denominaciones ---- */
function buildRow(denom, moneda) {
    const cls    = moneda === 'NIO' ? '' : 'usd';
    const labelF = moneda === 'NIO'
        ? (denom < 1 ? denom.toFixed(2) : denom.toFixed(2).replace('.00', ''))
        : denom;

    return `
    <tr data-moneda="${moneda}" data-denom="${denom}">
        <td>
            <span class="denom-label ${cls}">
                <span class="denom-tag">${labelF}</span>
            </span>
        </td>
        <td class="text-center">
            <input type="number" class="ci-input-qty qty-input"
                   min="0" step="1" value="0"
                   inputmode="numeric" pattern="[0-9]*"
                   data-moneda="${moneda}" data-denom="${denom}"
                   id="qty_${moneda}_${String(denom).replace('.','_')}"
                   autocomplete="off">
        </td>
        <td class="text-end ci-row-total" id="total_${moneda}_${String(denom).replace('.','_')}">
            ${moneda === 'NIO' ? fmtNIO(0) : fmtUSD(0)}
        </td>
    </tr>`;
}

$(function () {
    // Poblar tablas de denominaciones
    const bodyNIO = $('#modalNIOBody');
    const bodyUSD = $('#modalUSDBody');
    DENOMS_NIO.forEach(d => bodyNIO.append(buildRow(d, 'NIO')));
    DENOMS_USD.forEach(d => bodyUSD.append(buildRow(d, 'USD')));

    // Recalcular al cambiar cantidades
    $(document).on('input change', '.qty-input', function () {
        let v = $(this).val().replace(/[^0-9]/g, '');
        v = v === '' ? 0 : parseInt(v, 10);
        if (isNaN(v) || v < 0) v = 0;
        $(this).val(v);
        recalcular();
    });

    // UX: limpiar 0 al enfocar
    $(document).on('focus', '.qty-input', function () {
        if ($(this).val() == '0') $(this).val('');
    });
    $(document).on('blur', '.qty-input', function () {
        if ($(this).val() === '') { $(this).val(0); recalcular(); }
    });

    recalcular();
});

/* ---- Recalcular totales ---- */
function recalcular() {
    const TC = parseFloat(window.TIPO_CAMBIO || 36.6);
    let totalNIO = 0;
    let totalUSD = 0;

    $('[data-moneda="NIO"].qty-input').each(function () {
        const denom = parseFloat($(this).data('denom'));
        const qty   = parseInt($(this).val()) || 0;
        const sub   = denom * qty;
        totalNIO += sub;
        const key = String(denom).replace('.', '_');
        $(`#total_NIO_${key}`).text(fmtNIO(sub));
    });

    $('[data-moneda="USD"].qty-input').each(function () {
        const denom = parseFloat($(this).data('denom'));
        const qty   = parseInt($(this).val()) || 0;
        const sub   = denom * qty;
        totalUSD += sub;
        const key = String(denom).replace('.', '_');
        $(`#total_USD_${key}`).text(fmtUSD(sub));
    });

    const totalUSDenNIO = totalUSD * TC;
    const totalGlobal   = totalNIO + totalUSDenNIO;

    $('#modalSubtotalNIO').text(fmtNIO(totalNIO));
    $('#modalSubtotalUSD').text(fmtUSD(totalUSD));
    $('#modalSubtotalUSDenNIO').text(fmtNIO(totalUSDenNIO));
    $('#modalTotalGlobal').text(fmtNIO(totalGlobal));

    $('#hidModalTotalNIO').val(totalNIO.toFixed(2));
    $('#hidModalTotalUSD').val(totalUSD.toFixed(2));
    $('#hidModalTotalUSDenNIO').val(totalUSDenNIO.toFixed(2));
    $('#hidModalTotalGlobal').val(totalGlobal.toFixed(2));
}

/* ---- Limpiar cantidades ---- */
function limpiarRegistro() {
    Swal.fire({
        title: '¿Limpiar conteo?',
        text:  'Se restablecerán todas las cantidades a cero.',
        icon:  'question',
        showCancelButton:    true,
        confirmButtonColor:  '#51B8AC',
        cancelButtonColor:   '#94a3b8',
        confirmButtonText:   'Sí, limpiar',
        cancelButtonText:    'Cancelar'
    }).then(r => {
        if (r.isConfirmed) { $('.qty-input').val(0); recalcular(); }
    });
}

/* ---- Guardar aligeramiento ---- */
function guardarAligeramiento() {
    const totalGlobal = parseFloat($('#hidModalTotalGlobal').val()) || 0;
    const sucursalId  = window.SUCURSAL_ID || 0;

    if (!sucursalId) {
        Swal.fire('Error', 'No se detectó sucursal en sesión. Reinicia sesión.', 'error');
        return;
    }

    if (totalGlobal <= 0) {
        Swal.fire('Monto requerido', 'Ingresa al menos una denominación.', 'warning');
        return;
    }

    Swal.fire({
        title: '¿Confirmar Aligeramiento?',
        html:  `¿Registrar este aligeramiento?<br><br><strong>Total Global: ${fmtNIO(totalGlobal)}</strong>`,
        icon:  'question',
        showCancelButton:   true,
        confirmButtonColor: '#51B8AC',
        cancelButtonColor:  '#94a3b8',
        confirmButtonText:  'Sí, guardar',
        cancelButtonText:   'Cancelar'
    }).then(r => {
        if (r.isConfirmed) ejecutarGuardado();
    });

    function ejecutarGuardado() {
        const TC = parseFloat(window.TIPO_CAMBIO || 36.6);
        const detalles = [];
        $('.qty-input').each(function () {
            const qty = parseInt($(this).val()) || 0;
            detalles.push({
                moneda:       $(this).data('moneda'),
                denominacion: parseFloat($(this).data('denom')),
                cantidad:     qty,
                total:        parseFloat(($(this).data('denom') * qty).toFixed(2))
            });
        });

        const payload = {
            fecha:                     new Date().toISOString().slice(0, 10),
            sucursal_id:               sucursalId,
            tipo_cambio_usado:         TC,
            total_cordobas:            parseFloat($('#hidModalTotalNIO').val()),
            total_dolares:             parseFloat($('#hidModalTotalUSD').val()),
            total_dolares_en_cordobas: parseFloat($('#hidModalTotalUSDenNIO').val()),
            total_global:              totalGlobal,
            observacion:               $('#inputObservacion').val().trim(),
            detalles:                  detalles
        };

        const btn = $('#btnGuardarAlig');
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando…');

        $.ajax({
            url:         'ajax/guardar_aligeramiento.php',
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify(payload),
            dataType:    'json'
        })
        .done(function (res) {
            if (res.ok) {
                Swal.fire({
                    title: '¡Registrado!',
                    html:  `Aligeramiento guardado correctamente.<br><strong>Total: ${fmtNIO(totalGlobal)}</strong>`,
                    icon:  'success',
                    confirmButtonColor: '#51B8AC',
                    confirmButtonText:  'OK'
                }).then(function () {
                    if (_modalRegistro) _modalRegistro.hide();
                    // Resetear
                    $('.qty-input').val(0);
                    $('#inputObservacion').val('');
                    recalcular();
                    // Recargar historial
                    totalRegistros = 0;
                    paginaActual   = 1;
                    cargarHistorial();
                });
            } else {
                Swal.fire('Error', res.mensaje || 'Ocurrió un error.', 'error');
            }
        })
        .fail(function () {
            Swal.fire('Error de red', 'No se pudo conectar con el servidor.', 'error');
        })
        .always(function () {
            btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar');
        });
    }
}

/* ================================================================
   VOUCHERS IA — Estado global del modal
   ================================================================ */
let _vAligId   = 0;
let _vMonto    = 0;
let _modalVouchers  = null;
let _modalCamaraV   = null;

// Cámara
let _vcamStream     = null;
let _vcamTrack      = null;
let _vcamTorchOn    = false;
let _vcamToastTimer = null;

/* ----------------------------------------------------------------
   ABRIR / CERRAR MODAL VOUCHERS
   ---------------------------------------------------------------- */
function abrirModalVouchers(aligId, monto) {
    _vAligId = aligId;
    _vMonto  = parseFloat(monto) || 0;

    const montoFmt = _vMonto.toLocaleString('en-US', { minimumFractionDigits: 2 });
    $('#voucherAligInfo').text('Aligeramiento #' + aligId + '  ·  C$ ' + montoFmt);

    if (!_modalVouchers) {
        _modalVouchers = new bootstrap.Modal(document.getElementById('modalVouchers'));
    }
    _modalVouchers.show();
    cargarVouchers();
}

function cerrarModalVouchers() {
    cerrarCamaraVoucher();
    if (_modalVouchers) _modalVouchers.hide();
}

/* ----------------------------------------------------------------
   CARGAR / RENDERIZAR VOUCHERS
   ---------------------------------------------------------------- */
function cargarVouchers() {
    $('#vouchersGallery').html('<div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Cargando...</div>');
    $('#validacionPanel').hide();

    $.ajax({
        url:    'ajax/aligeramiento_get_vouchers.php',
        method: 'POST',
        data: {
            aligeramiento_id: _vAligId,
            monto_registro:   _vMonto
        },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                renderVouchers(res.vouchers, res.resumen);
            } else {
                $('#vouchersGallery').html(`<div class="vouchers-empty"><i class="bi bi-exclamation-circle"></i><p>${res.message || 'Error'}</p></div>`);
            }
        },
        error: function () {
            $('#vouchersGallery').html('<div class="vouchers-empty"><i class="bi bi-wifi-slash"></i><p>Error de conexión.</p></div>');
        }
    });
}

function renderVouchers(vouchers, resumen) {
    const gallery = $('#vouchersGallery');
    gallery.empty();

    if (!vouchers || vouchers.length === 0) {
        gallery.html(`<div class="vouchers-empty">
            <i class="bi bi-camera"></i>
            <p>No hay fotos subidas aún.<br>Usa los botones de arriba para agregar vouchers.</p>
        </div>`);
    } else {
        let html = '<div class="vouchers-gallery">';
        vouchers.forEach(function (v) {
            const sym       = v.ia_denominacion === 'Dolares' ? 'US$' : 'C$';
            const montoStr  = v.ia_monto !== null
                ? sym + ' ' + parseFloat(v.ia_monto).toLocaleString('en-US', { minimumFractionDigits: 2 })
                : '<span class="text-muted">—</span>';
            const fechaStr  = v.ia_fecha_hora ? (typeof window.formatoFechaHoraPOS === 'function' ? window.formatoFechaHoraPOS(v.ia_fecha_hora) : v.ia_fecha_hora.substring(0, 16).replace('T', ' ')) : '';
            const alertCls  = v.alerta ? 'has-alert' : '';
            const provBadge = v.ia_proveedor ? `<span class="voucher-card-ia-badge">IA: ${v.ia_proveedor}</span>` : '';
            const lugarHtml = v.ia_lugar ? `<div class="voucher-card-lugar" title="${v.ia_lugar}">${v.ia_lugar}</div>` : '';
            const fechaHtml = fechaStr   ? `<div class="voucher-card-fecha">${fechaStr}</div>` : '';
            const btnElim   = `<button class="btn-delete-voucher" onclick="confirmarEliminarVoucher(${v.id})" title="Eliminar foto">
                <i class="fas fa-trash-alt"></i> Eliminar
            </button>`;

            html += `
            <div class="voucher-card ${alertCls}" id="voucher-card-${v.id}">
                <img src="${v.foto_url}"
                     class="voucher-card-img"
                     onclick="window.open('${v.foto_url}','_blank')"
                     title="Ver imagen completa"
                     onerror="this.src='/core/assets/img/no-image.png'">
                <div class="voucher-card-body">
                    <div class="voucher-card-monto">${montoStr}</div>
                    <div class="voucher-card-denom">${v.ia_denominacion || '—'}</div>
                    ${lugarHtml}${fechaHtml}
                </div>
                <div class="voucher-card-footer">
                    ${provBadge}
                    ${btnElim}
                </div>
            </div>`;
        });
        html += '</div>';
        gallery.html(html);
    }

    renderResumenValidacion(resumen);
}

function renderResumenValidacion(resumen) {
    const panel   = $('#validacionPanel');
    const content = $('#validacionContent');

    if (!resumen || resumen.total_vouchers === 0) { panel.hide(); return; }

    panel.show();
    const tieneAlerta = resumen.tiene_alerta;
    panel.removeClass('ok alert neutral').addClass(tieneAlerta ? 'alert' : 'ok');

    const montoRegFmt = resumen.monto_registro !== null
        ? 'C$ ' + parseFloat(resumen.monto_registro).toLocaleString('en-US', { minimumFractionDigits: 2 })
        : '—';

    let html = `<div class="vp-row">
        <span class="vp-label">Monto registrado:</span>
        <span class="vp-value">${montoRegFmt}</span>
    </div>`;

    if (resumen.totales_por_moneda) {
        Object.keys(resumen.totales_por_moneda).forEach(function (den) {
            const tot = resumen.totales_por_moneda[den];
            const s   = den === 'Dolares' ? 'US$' : 'C$';
            html += `<div class="vp-row">
                <span class="vp-label">Suma vouchers (${den}):</span>
                <span class="vp-value">${s} ${parseFloat(tot).toLocaleString('en-US',{minimumFractionDigits:2})}</span>
            </div>`;
        });
    }

    if (tieneAlerta && resumen.alerta_msg) {
        html += `<div class="vp-msg"><i class="fas fa-exclamation-triangle me-1"></i>${resumen.alerta_msg}</div>`;
    } else if (!tieneAlerta) {
        html += `<div class="vp-msg"><i class="fas fa-check-circle me-1"></i>Los montos coinciden correctamente.</div>`;
    }

    content.html(html);
}

/* ----------------------------------------------------------------
   SUBIR VOUCHER — Archivo o Cámara
   ---------------------------------------------------------------- */
function redimensionarYComprimirImagen(file, maxDimension = 1600, calidad = 0.80) {
    return new Promise(function (resolve) {
        if (!file || !file.type || !file.type.startsWith('image/')) return resolve(file);

        const reader = new FileReader();
        reader.onload = function (e) {
            const img = new Image();
            img.onload = function () {
                try {
                    let w = img.naturalWidth || img.width;
                    let h = img.naturalHeight || img.height;

                    if (w <= maxDimension && h <= maxDimension && file.size < 800 * 1024) return resolve(file);

                    if (w > maxDimension || h > maxDimension) {
                        if (w > h) { h = Math.round(h * maxDimension / w); w = maxDimension; }
                        else       { w = Math.round(w * maxDimension / h); h = maxDimension; }
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = w; canvas.height = h;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillRect(0, 0, w, h);
                    ctx.drawImage(img, 0, 0, w, h);

                    canvas.toBlob(function (blob) {
                        if (!blob) return resolve(file);
                        const name = (file.name ? file.name.replace(/\.[^/.]+$/, '') : 'voucher') + '.jpg';
                        resolve(new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() }));
                    }, 'image/jpeg', calidad);
                } catch (err) { resolve(file); }
            };
            img.onerror = function () { resolve(file); };
            img.src = e.target.result;
        };
        reader.onerror = function () { resolve(file); };
        reader.readAsDataURL(file);
    });
}

function procesarArchivoVoucher(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    input.value = '';
    subirVoucher(file);
}

async function subirVoucher(file) {
    $('#loaderVoucher').css('display', 'flex');

    let archivoParaSubir = file;
    try { archivoParaSubir = await redimensionarYComprimirImagen(file, 1600, 0.80); }
    catch (e) { console.warn('No se pudo optimizar la imagen:', e); }

    const formData = new FormData();
    formData.append('foto',              archivoParaSubir);
    formData.append('aligeramiento_id',  _vAligId);
    formData.append('monto_registro',    _vMonto);

    $.ajax({
        url:         'ajax/aligeramiento_subir_voucher.php',
        type:        'POST',
        data:        formData,
        processData: false,
        contentType: false,
        dataType:    'json',
        timeout:     90000,
        success: function (res) {
            $('#loaderVoucher').hide();
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: '¡Voucher procesado!',
                    html:  res.ia_monto
                        ? `<b>${res.ia_denominacion === 'Dolares' ? 'US$' : 'C$'} ${parseFloat(res.ia_monto).toLocaleString('en-US',{minimumFractionDigits:2})}</b>`
                        : 'Imagen guardada.',
                    timer: 2000,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });

                if (res.alerta && res.alerta_detalle) {
                    setTimeout(function () {
                        Swal.fire({
                            icon: 'warning',
                            title: '⚠ Discrepancia detectada',
                            text:  res.alerta_detalle,
                            confirmButtonText: 'Entendido',
                            confirmButtonColor: '#f59e0b'
                        });
                    }, 400);
                }

                cargarVouchers();
                actualizarBadgeFila(_vAligId);
            } else {
                const tipoError = res.ia_error_tipo || '';
                let titulo = 'Error al procesar', icono = 'error';
                if (tipoError === 'MULTIPLES_VOUCHERS')  { titulo = '📸 Múltiples vouchers detectados'; icono = 'warning'; }
                if (tipoError === 'LECTURA_FALLIDA' || tipoError === 'BAJA_RESOLUCION') { titulo = '🔍 No se pudo leer el voucher'; icono = 'warning'; }
                Swal.fire({
                    icon: icono, title: titulo,
                    html: (res.message || 'No se pudo procesar el voucher.')
                        + '<br><br><small style="color:#888">💡 <b>Consejos:</b> Un voucher por foto, buena iluminación y enfoque.</small>',
                    confirmButtonText: 'Entendido', confirmButtonColor: '#6c757d'
                });
            }
        },
        error: function (xhr, status) {
            $('#loaderVoucher').hide();
            let titulo = 'Error de Conexión', msg = 'No se pudo conectar con el servidor.', icono = 'error';
            if (xhr.status === 413)                      { titulo = '📁 Foto demasiado grande';   msg = 'La foto supera el límite del servidor (413).'; }
            else if (xhr.status === 504 || status === 'timeout') { titulo = '⏱ Tiempo agotado';  msg = 'El servidor tardó demasiado. Reintenta.'; }
            else if (xhr.status === 500)                 { titulo = '⚙ Error interno';           msg = 'Error 500. Intenta con otra foto.'; }
            Swal.fire({ icon: icono, title: titulo, html: msg, confirmButtonText: 'Entendido', confirmButtonColor: '#6c757d' });
        }
    });
}

/* ---- ELIMINAR VOUCHER ---- */
function confirmarEliminarVoucher(idVoucher) {
    Swal.fire({
        title: '¿Eliminar foto?',
        text:  'Se eliminará la imagen y sus datos de IA. Esta acción no se puede deshacer.',
        icon:  'warning',
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-trash me-1"></i>Sí, eliminar',
        cancelButtonText:  'Cancelar',
        confirmButtonColor: '#ef4444',
        cancelButtonColor:  '#6c757d'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({
            url:  'ajax/aligeramiento_eliminar_voucher.php',
            type: 'POST',
            data: {
                id:               idVoucher,
                aligeramiento_id: _vAligId,
                monto_registro:   _vMonto
            },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Eliminado', timer: 1200, showConfirmButton: false, toast: true, position: 'top-end' });
                    cargarVouchers();
                    actualizarBadgeFila(_vAligId);
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            error: function () { Swal.fire('Error', 'No se pudo conectar.', 'error'); }
        });
    });
}

/* ---- Actualizar badge en fila sin recargar toda la tabla ---- */
function actualizarBadgeFila(aligId) {
    $.post('ajax/aligeramiento_get_vouchers.php',
        { aligeramiento_id: aligId, monto_registro: _vMonto },
        function (res) {
            if (!res.success) return;
            const count      = res.vouchers ? res.vouchers.length : 0;
            const tieneAlerta = res.resumen && res.resumen.tiene_alerta;
            const selector = `button.voucher-btn[onclick*="abrirModalVouchers(${aligId},"]`;
            const btn = $(selector);
            if (!btn.length) return;

            btn.removeClass('voucher-btn-empty voucher-btn-ok voucher-btn-alert');
            if (count === 0) {
                btn.addClass('voucher-btn-empty').html('<i class="bi bi-camera"></i> Agregar');
            } else if (tieneAlerta) {
                btn.addClass('voucher-btn-alert').html(`<i class="bi bi-exclamation-triangle-fill"></i> ${count}`);
            } else {
                btn.addClass('voucher-btn-ok').html(`<i class="bi bi-check-circle-fill"></i> ${count}`);
            }
        }, 'json');
}

/* ================================================================
   CÁMARA PREMIUM — Voucher
   ================================================================ */
function abrirCamaraVoucher() {
    if (!_modalCamaraV) {
        _modalCamaraV = new bootstrap.Modal(document.getElementById('modalCamaraVoucher'));
    }

    navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 3840 }, height: { ideal: 2160 }, focusMode: { ideal: 'continuous' } }
    }).then(function (s) {
        _vcamStream = s;
        _vcamTrack  = s.getVideoTracks()[0];
        document.getElementById('voucherVideo').srcObject = _vcamStream;
        _modalCamaraV.show();

        document.getElementById('voucherVideo').onloadedmetadata = function () { inicializarCamaraVoucher(); };
        document.getElementById('voucherCameraViewport').addEventListener('click', enfocarVoucher);
    }).catch(function () {
        Swal.fire('Cámara', 'No se pudo acceder a la cámara. Verifica los permisos del navegador.', 'error');
    });
}

function inicializarCamaraVoucher() {
    if (!_vcamTrack) return;
    const caps = _vcamTrack.getCapabilities ? _vcamTrack.getCapabilities() : {};
    if (caps.torch) {
        document.getElementById('btnVcamTorch').style.display = 'flex';
        document.getElementById('btnVcamTorchPlaceholder').style.display = 'none';
    }
    if (caps.focusMode && caps.focusMode.includes('continuous')) {
        _vcamTrack.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function(){});
        document.getElementById('vcamFocusStatus').textContent = 'CONTINUO';
        document.getElementById('vcamFocusStatus').className   = 'badge bg-success';
    }
    mostrarFocusToastVoucher('Toca la pantalla para enfocar', 2000);
}

function enfocarVoucher(e) {
    const viewport = document.getElementById('voucherCameraViewport');
    const ring     = document.getElementById('voucherFocusRing');
    const rect     = viewport.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    ring.style.left = x + 'px'; ring.style.top = y + 'px';
    ring.classList.remove('active', 'locked');
    void ring.offsetWidth;
    ring.classList.add('active');

    document.getElementById('vcamFocusStatus').textContent = 'ENFOCANDO...';
    document.getElementById('vcamFocusStatus').className   = 'badge bg-warning text-dark';

    if (_vcamTrack) {
        const xR = x / rect.width;
        const yR = y / rect.height;
        _vcamTrack.applyConstraints({ advanced: [{ pointsOfInterest: [{ x: xR, y: yR }], focusMode: 'single-shot' }] })
            .then(function () {
                ring.classList.add('locked');
                document.getElementById('vcamFocusStatus').textContent = 'ENFOCADO';
                document.getElementById('vcamFocusStatus').className   = 'badge bg-success';
                mostrarFocusToastVoucher('✓ Enfocado', 1500);
                setTimeout(function () {
                    _vcamTrack.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function(){});
                    document.getElementById('vcamFocusStatus').textContent = 'CONTINUO';
                }, 2000);
            }).catch(function () {
                ring.classList.add('locked');
                mostrarFocusToastVoucher('Enfoque ajustado', 1200);
                document.getElementById('vcamFocusStatus').textContent = 'AUTO';
                document.getElementById('vcamFocusStatus').className   = 'badge bg-secondary';
            });
    }
    setTimeout(function () { ring.classList.remove('active', 'locked'); }, 2500);
}

function mostrarFocusToastVoucher(msg, dur) {
    const toast = document.getElementById('voucherFocusToast');
    if (_vcamToastTimer) clearTimeout(_vcamToastTimer);
    toast.textContent = msg; toast.style.opacity = '1';
    _vcamToastTimer = setTimeout(function () { toast.style.opacity = '0'; }, dur || 1500);
}

function toggleLinternaVoucher() {
    if (!_vcamTrack) return;
    _vcamTorchOn = !_vcamTorchOn;
    _vcamTrack.applyConstraints({ advanced: [{ torch: _vcamTorchOn }] })
        .then(function () { document.getElementById('btnVcamTorch').classList.toggle('on', _vcamTorchOn); })
        .catch(function () { _vcamTorchOn = false; Swal.fire('Linterna', 'Este dispositivo no soporta linterna.', 'info'); });
}

function cerrarCamaraVoucher() {
    if (_vcamTorchOn && _vcamTrack) {
        _vcamTrack.applyConstraints({ advanced: [{ torch: false }] }).catch(function(){});
        _vcamTorchOn = false;
    }
    if (_vcamStream) {
        _vcamStream.getTracks().forEach(t => t.stop());
        _vcamStream = null; _vcamTrack = null;
    }
    const vp = document.getElementById('voucherCameraViewport');
    if (vp) { const vp2 = vp.cloneNode(true); vp.parentNode.replaceChild(vp2, vp); }
    if (_modalCamaraV) _modalCamaraV.hide();

    const torch = document.getElementById('btnVcamTorch');
    if (torch) torch.style.display = 'none';
    const ph = document.getElementById('btnVcamTorchPlaceholder');
    if (ph) ph.style.display = 'block';
    const st = document.getElementById('vcamFocusStatus');
    if (st) { st.textContent = 'AUTO'; st.className = 'badge bg-secondary'; }
    const ring = document.getElementById('voucherFocusRing');
    if (ring) ring.className = '';
}

function capturarFotoVoucher() {
    const video  = document.getElementById('voucherVideo');
    const canvas = document.getElementById('voucherCanvas');
    const ctx    = canvas.getContext('2d');

    canvas.width  = video.videoWidth  || video.clientWidth;
    canvas.height = video.videoHeight || video.clientHeight;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(function (blob) {
        const file = new File([blob], `voucher_alig${_vAligId}_${Date.now()}.jpg`, { type: 'image/jpeg' });
        cerrarCamaraVoucher();
        subirVoucher(file);
    }, 'image/jpeg', 0.85);
}

