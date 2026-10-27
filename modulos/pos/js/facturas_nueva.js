/* ===================================================
   facturas_nueva.js — Nueva Factura POS
   =================================================== */

// Estado local
let detalleFactura = []; // array de {id_presentacion, nombre, unidad, cantidad, precio_unitario_base, precio_unitario, costo_total_iva, personalizacion: {...}}
let todosProductos = []; // cache de productos elegibles
let clienteActual = null; // objeto del miembro Club Pitaya seleccionado
let paginaHistorialActual = 1;
let membresiaHistorialActual = '';
let grupoActivo = null;   // id del grupo activo en tabs del catálogo
let _grupos = null;       // Map: id_grupo → { nombre, subgrupos: Map }

// Estado de Pagos y Propina
let pagos = [];
let tipoCambioUSD = 0;     // Tasa USD/NIO cargada desde pos_ventas_tipo_cambio (solo lectura)           // array de { metodo: 'efectivo'|'pos'|'transferencia', monto: float, recibido: float, cambio: float, referencia: string }
let conPropina = false;   // estado del switch de propina
const PORCENTAJE_PROPINA = 2; // 2% corporativo

$(document).ready(function () {
    cargarProductosElegibles();
    cargarTipoCambioUSD();
    setupClienteEvents();
    cambiarTipoCliente('general');

    // Switch de Propina (2%)
    $('#switchPropina').on('change', function () {
        conPropina = this.checked;
        actualizarTotal(true);
    });

    // Búsqueda en catálogo con debounce + botón limpiar
    let timerBuscar;
    $('#buscarProducto').on('input', function () {
        clearTimeout(timerBuscar);
        const q = $(this).val().trim();
        $('#btnLimpiarBusqueda').toggle(q.length > 0);
        timerBuscar = setTimeout(() => filtrarProductos(q), 280);
    });
    $('#btnLimpiarBusqueda').on('click', function () {
        $('#buscarProducto').val('').trigger('input').focus();
    });

    // Guardar factura
    $('#btnGuardar').on('click', function (e) {
        e.preventDefault();
        guardarFactura();
    });

    $('#formFactura').on('submit', function (e) {
        e.preventDefault();
        guardarFactura();
    });
});

/* ============================================================
   LÓGICA DE CLIENTES (CLUB PITAYA vs CLIENTE GENERAL)
   ============================================================ */

function setupClienteEvents() {
    // Alternar tipo de cliente
    $('input[name="tipo_cliente"]').on('change', function () {
        const tipo = $(this).val();
        cambiarTipoCliente(tipo);
    });

    // Prevenir submit nativo del formulario
    $('#formFactura').on('submit', function (e) {
        e.preventDefault();
    });

    // Buscar membresía al dar Enter
    $('#membresiaCliente').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscarClienteClub(false);
        }
    }).on('input', function () {
        const num = $(this).val().trim();
        if (!clienteActual || String(clienteActual.membresia) !== num) {
            $('#clienteCheckVerificado').hide();
            $('#idClienteClub').val('');
            $('#nombreCliente').val('');
            $('#txtPuntosAnteriores').text('0');
            actualizarPuntosFactura();
        } else {
            $('#clienteCheckVerificado').show();
            $('#idClienteClub').val(clienteActual.id_clienteclub);
            $('#nombreCliente').val(clienteActual.nombre_completo);
            $('#txtPuntosAnteriores').text(clienteActual.puntos_anteriores);
            actualizarPuntosFactura();
        }
    });

    // Botón de búsqueda / lupa en membresía
    $('#btnBuscarMembresia').on('click', function (e) {
        e.preventDefault();
        const num = $('#membresiaCliente').val().trim();
        // Si ya está cargado este cliente, abre directamente el modal
        if (clienteActual && String(clienteActual.membresia) === num && num !== '') {
            abrirModalHistorial(num, 1);
        } else {
            // Si no está cargado o cambió el número, busca y abre el modal
            buscarClienteClub(true);
        }
    });
}

function cambiarTipoCliente(tipo) {
    if (tipo === 'general') {
        $('#colMembresia').hide();
        $('#membresiaCliente').val('');
        $('#colIndicadoresPuntos').hide();
        $('#clienteCheckVerificado').hide();
        $('#colNombreCliente').removeClass('col-sm-7 col-7').addClass('col-12');
        $('#nombreCliente').prop('readonly', false).val('').attr('placeholder', 'Escriba nombre del cliente casual...').css('background-color', '#fff');
        $('#idClienteClub').val('');
        clienteActual = null;
        // Revertir canjes activos si se pasa a Cliente General
        let habiaCanjeados = false;
        detalleFactura.forEach(item => {
            if (item.es_canjeado) {
                item.es_canjeado = false;
                item.es_canje_puntos = 0;
                item.bloquea_puntos = 0;
                item.costo_total_iva = Math.round(item.cantidad * (item.precio_unitario || 0) * 100) / 100;
                habiaCanjeados = true;
            }
        });
        actualizarTablaDetalle();
        actualizarPuntosFactura();
    } else {
        $('#colMembresia').show();
        $('#colIndicadoresPuntos').show();
        $('#colNombreCliente').removeClass('col-12').addClass('col-sm-7 col-12');
        $('#nombreCliente').prop('readonly', true).val(clienteActual ? clienteActual.nombre_completo : '').attr('placeholder', 'Nombre del cliente...').css('background-color', '#f8fafc');
        if (clienteActual) {
            $('#clienteCheckVerificado').show();
            $('#idClienteClub').val(clienteActual.id_clienteclub);
            $('#txtPuntosAnteriores').text(clienteActual.puntos_anteriores);
        } else {
            $('#clienteCheckVerificado').hide();
            $('#idClienteClub').val('');
            $('#txtPuntosAnteriores').text('0');
        }
        actualizarTablaDetalle();
        actualizarPuntosFactura();
        setTimeout(() => $('#membresiaCliente').focus(), 50);
    }
}

/* ---- Búsqueda de miembro Club Pitaya ---- */
function buscarClienteClub(abrirModalTrasBuscar = false) {
    const membresia = $('#membresiaCliente').val().trim();
    if (!membresia) {
        Swal.fire({
            title: 'Membresía requerida',
            text: 'Por favor ingrese el número de membresía del cliente.',
            icon: 'warning',
            confirmButtonColor: '#0E544C'
        });
        $('#membresiaCliente').focus();
        return;
    }

    const $btn = $('#btnBuscarMembresia');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    $.post('/modulos/clientes/ajax/cliente_club_buscar.php', { membresia: membresia }, function (res) {
        $btn.prop('disabled', false).html('<i class="bi bi-search"></i>');

        if (res && res.relogin) {
            Swal.fire({
                title: 'Sesión expirada',
                text: res.mensaje || 'Tu sesión en el POS ha expirado. Por favor recarga para ingresar tu clave.',
                icon: 'warning',
                confirmButtonColor: '#0E544C'
            }).then(() => {
                window.location.reload();
            });
            return;
        }

        if (res && res.success && res.cliente) {
            clienteActual = res.cliente;
            $('#nombreCliente').val(clienteActual.nombre_completo);
            $('#idClienteClub').val(clienteActual.id_clienteclub);
            $('#txtPuntosAnteriores').text(clienteActual.puntos_anteriores);
            $('#clienteCheckVerificado').fadeIn(200);
            actualizarPuntosFactura();

            if (abrirModalTrasBuscar) {
                abrirModalHistorial(clienteActual.membresia, 1);
            }
        } else {
            const msgError = (res && (res.message || res.mensaje)) ? (res.message || res.mensaje) : 'La membresía ingresada no existe.';
            clienteActual = null;
            $('#nombreCliente').val('');
            $('#idClienteClub').val('');
            $('#txtPuntosAnteriores').text('0');
            $('#clienteCheckVerificado').hide();
            actualizarPuntosFactura();
            Swal.fire({
                title: 'No encontrado',
                text: msgError,
                icon: 'error',
                confirmButtonColor: '#0E544C'
            });
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-search"></i>');
        $('#clienteCheckVerificado').hide();
        Swal.fire({
            title: 'Error de conexión',
            text: 'No se pudo conectar con el servidor para buscar la membresía.',
            icon: 'error',
            confirmButtonColor: '#0E544C'
        });
    });
}

/* ============================================================
   MODAL DE HISTORIAL DE PRODUCTOS (6 COLUMNAS)
   ============================================================ */

function abrirModalHistorial(membresia, pagina = 1) {
    if (!membresia) return;
    membresiaHistorialActual = membresia;
    paginaHistorialActual = pagina;

    const modalEl = document.getElementById('modalHistorialCliente');
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
    modalInstance.show();

    $('#modalSubtituloCliente').text(`Membresía: ${membresia}`);
    $('#tablaHistorialBody').html(`
        <tr>
            <td colspan="6" class="text-center py-4 text-muted">
                <span class="spinner-border spinner-border-sm me-2"></span> Cargando historial de compras…
            </td>
        </tr>
    `);

    $.post('/modulos/clientes/ajax/cliente_historial_productos.php', {
        membresia: membresia,
        pagina: pagina,
        limite: 12
    }, function (res) {
        if (!res.success) {
            $('#tablaHistorialBody').html(`
                <tr>
                    <td colspan="6" class="text-center py-4 text-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i> ${escHtml(res.message || 'Error al obtener historial')}
                    </td>
                </tr>
            `);
            return;
        }

        // Resumen superior
        if (res.cliente) {
            $('#modalNombreCliente').text(res.cliente.nombre_completo || '—');
            $('#modalMembresiaCliente').text(res.cliente.membresia || membresia);
            $('#modalSucursalCliente').text(res.cliente.sucursal_registro || 'Central');
            $('#modalPuntosCliente').text(`${res.cliente.puntos_totales} pts`);
        }

        // Tabla con las 6 columnas
        if (!res.datos || res.datos.length === 0) {
            $('#tablaHistorialBody').html(`
                <tr>
                    <td colspan="6" class="text-center py-4 text-muted">
                        <i class="bi bi-inbox me-1"></i> Este miembro no tiene compras registradas en el historial.
                    </td>
                </tr>
            `);
            $('#modalPaginacionInfo').text('Mostrando 0 registros');
            $('#modalPaginacionList').empty();
            return;
        }

        let html = '';
        res.datos.forEach(row => {
            const trClass = row.es_anulado ? 'class="fila-anulada"' : '';
            const promoRaw = (row.promocion || '').trim();
            const promoHtml = (promoRaw !== '' && promoRaw !== '.')
                ? `<small class="text-muted">${escHtml(promoRaw)}</small>`
                : `<span class="text-muted opacity-50">—</span>`;

            html += `
            <tr ${trClass}>
                <td><strong>${escHtml(row.sucursal)}</strong></td>
                <td><span class="text-muted">${escHtml(row.fecha_hora)}</span></td>
                <td><span class="fw-semibold text-dark">${escHtml(row.producto_medida)}</span></td>
                <td class="text-center"><span class="badge bg-light text-dark border">${row.cantidad}</span></td>
                <td>${promoHtml}</td>
                <td class="text-end fw-bold text-success font-monospace">${row.puntos_acumulados.toFixed(2)}</td>
            </tr>`;
        });
        $('#tablaHistorialBody').html(html);

        // Paginación
        renderizarPaginacionModal(res.total_registros, res.pagina_actual, res.total_paginas);

    }, 'json').fail(function () {
        $('#tablaHistorialBody').html(`
            <tr>
                <td colspan="6" class="text-center py-4 text-danger">
                    <i class="bi bi-cloud-slash me-1"></i> Error de conexión al cargar el historial.
                </td>
            </tr>
        `);
    });
}

function renderizarPaginacionModal(totalRegistros, pagActual, totalPaginas) {
    $('#modalPaginacionInfo').text(`Mostrando página ${pagActual} de ${totalPaginas} (${totalRegistros} ítems totales)`);
    const $list = $('#modalPaginacionList');
    $list.empty();

    if (totalPaginas <= 1) return;

    // Botón Anterior
    $list.append(`
        <li class="page-item ${pagActual === 1 ? 'disabled' : ''}">
            <button class="page-link" type="button" onclick="abrirModalHistorial('${membresiaHistorialActual}', ${pagActual - 1})">
                <i class="bi bi-chevron-left"></i>
            </button>
        </li>
    `);

    let start = Math.max(1, pagActual - 2);
    let end = Math.min(totalPaginas, pagActual + 2);

    for (let p = start; p <= end; p++) {
        $list.append(`
            <li class="page-item ${p === pagActual ? 'active' : ''}">
                <button class="page-link" type="button" onclick="abrirModalHistorial('${membresiaHistorialActual}', ${p})">${p}</button>
            </li>
        `);
    }

    // Botón Siguiente
    $list.append(`
        <li class="page-item ${pagActual === totalPaginas ? 'disabled' : ''}">
            <button class="page-link" type="button" onclick="abrirModalHistorial('${membresiaHistorialActual}', ${pagActual + 1})">
                <i class="bi bi-chevron-right"></i>
            </button>
        </li>
    `);
}

function actualizarPuntosFactura() {
    const isClub = $('input[name="tipo_cliente"]:checked').val() === 'club';
    if (!isClub || !clienteActual) {
        $('#txtPuntosEstaFactura').text('+0');
        $('#txtTotalPuntos').text('0');
        return;
    }

    // Puntos estimados: 1 punto por cada unidad de producto vendible (excluye canjeados)
    const puntosEstaFactura = detalleFactura.reduce((s, d) => s + (!d.es_canjeado ? (parseFloat(d.cantidad) || 0) : 0), 0);
    const puntosAnteriores = parseFloat(clienteActual.puntos_anteriores) || 0;
    const totalPuntos = Math.round((puntosAnteriores + puntosEstaFactura) * 100) / 100;

    $('#txtPuntosEstaFactura').text(`+${puntosEstaFactura}`);
    $('#txtTotalPuntos').text(totalPuntos.toFixed(2));
}


/* ---- Cargar tipo de cambio USD/NIO desde la BD (solo lectura) ---- */
function cargarTipoCambioUSD() {
    $.getJSON('/modulos/pos/ajax/get_tipo_cambio.php', function (res) {
        if (res.success && res.tasa > 0) {
            tipoCambioUSD = res.tasa;
            // Actualizar todas las filas de efectivo_dolar que ya estén en pantalla
            renderizarFilasPago();
        } else {
            console.warn('T/C USD no disponible:', res.message);
        }
    }).fail(function () {
        console.warn('Error al cargar tipo de cambio USD.');
    });
}

/* ---- Cargar productos elegibles (catálogo izquierdo) ---- */
function cargarProductosElegibles() {
    $('#catalogoPanel').html(`
        <p class="no-productos-msg">
            <span class="spinner-border spinner-border-sm me-1"></span> Cargando…
        </p>`);

    $.get('/modulos/pos/ajax/facturas_get_productos_elegibles.php', function (res) {
        if (!res.success || res.data.length === 0) {
            $('#catalogoPanel').html(`
                <p class="no-productos-msg">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    No hay productos vendibles activos en este momento.
                </p>`);
            return;
        }
        todosProductos = res.data;
        renderizarCatalogoPorGrupos(todosProductos);
    }, 'json').fail(function () {
        $('#catalogoPanel').html(`<p class="no-productos-msg text-danger">Error al cargar productos.</p>`);
    });
}

/* ---- Renderizar catálogo por grupos (tabs + subgrupos + cards) ---- */
function renderizarCatalogoPorGrupos(lista) {
    _grupos = new Map();
    lista.forEach(p => {
        const gId     = parseInt(p.id_grupo)    || 0;
        const gNombre = p.grupo    || 'Otros';
        if (!_grupos.has(gId)) {
            _grupos.set(gId, { id: gId, nombre: gNombre, subgrupos: new Map() });
        }
        const grupo   = _grupos.get(gId);
        const sId     = parseInt(p.id_subgrupo) || 0;
        const sNombre = p.subgrupo || 'General';
        if (!grupo.subgrupos.has(sId)) {
            grupo.subgrupos.set(sId, { id: sId, nombre: sNombre, productos: [] });
        }
        grupo.subgrupos.get(sId).productos.push(p);
    });

    // Render tabs
    let tabsHtml = '';
    let firstGrupoId = null;
    _grupos.forEach(g => {
        if (firstGrupoId === null) firstGrupoId = g.id;
        tabsHtml += `<button class="tab-grupo" data-grupo="${g.id}" type="button">${escHtml(g.nombre)}</button>`;
    });
    $('#gruposTabs').html(tabsHtml);

    // Delegated tab click (re-bind limpio)
    $('#gruposTabs').off('click', '.tab-grupo').on('click', '.tab-grupo', function () {
        cambiarGrupo(parseInt($(this).data('grupo')));
    });

    if (firstGrupoId !== null) cambiarGrupo(firstGrupoId);
}

/* ---- Cambiar grupo activo en el catálogo ---- */
function cambiarGrupo(grupoId) {
    grupoActivo = grupoId;
    $('#gruposTabs .tab-grupo').removeClass('active');
    $(`#gruposTabs .tab-grupo[data-grupo="${grupoId}"]`).addClass('active');

    const grupo = _grupos ? _grupos.get(grupoId) : null;
    if (!grupo) return;

    let html = '<div class="subgrupos-columnas">';
    grupo.subgrupos.forEach(sg => {
        html += `<div class="subgrupo-col">`;
        html += `<div class="subgrupo-header">${escHtml(sg.nombre)}</div>`;
        sg.productos.forEach(p => {
            const precio = parseFloat(p.precio_venta || 0);
            html += `<div class="producto-card-pos ${parseInt(p.es_personalizable) ? 'producto-personalizable' : ''}" tabindex="0" role="button"
                          onclick="agregarProducto(${p.id},'${escAttr(p.Nombre)}','${escAttr(p.nombre_unidad||'')}',${precio},${p.es_personalizable || 0})"
                          onkeypress="if(event.key==='Enter')agregarProducto(${p.id},'${escAttr(p.Nombre)}','${escAttr(p.nombre_unidad||'')}',${precio},${p.es_personalizable || 0})"
                     >${escHtml(p.Nombre)}${parseInt(p.es_personalizable) ? '<i class="bi bi-sliders2 ms-1" style="font-size:.7rem;opacity:.6;" title="Personalizable"></i>' : ''}</div>`;
        });
        html += `</div>`;
    });
    html += '</div>';
    $('#catalogoPanel').html(html);
}

/* ---- Filtrar localmente (overlay de búsqueda) ---- */
function filtrarProductos(q) {
    if (q === '') {
        if (grupoActivo !== null && _grupos) {
            cambiarGrupo(grupoActivo);
        } else if (todosProductos.length > 0) {
            renderizarCatalogoPorGrupos(todosProductos);
        }
        return;
    }

    const ql = q.toLowerCase();
    const filtrados = todosProductos.filter(p =>
        (p.Nombre         || '').toLowerCase().includes(ql) ||
        (p.nombre_maestro || '').toLowerCase().includes(ql) ||
        (p.SKU            || '').toLowerCase().includes(ql) ||
        (p.grupo          || '').toLowerCase().includes(ql) ||
        (p.subgrupo       || '').toLowerCase().includes(ql)
    );

    if (!filtrados.length) {
        $('#catalogoPanel').html(`<p class="no-productos-msg"><i class="bi bi-search me-1"></i> Sin resultados para "<strong>${escHtml(q)}</strong>".</p>`);
        return;
    }

    let html = '<div class="busqueda-resultados-grid">';
    filtrados.forEach(p => {
        const precio = parseFloat(p.precio_venta || 0);
        html += `<div class="producto-card-pos ${parseInt(p.es_personalizable) ? 'producto-personalizable' : ''}" tabindex="0" role="button"
                      onclick="agregarProducto(${p.id},'${escAttr(p.Nombre)}','${escAttr(p.nombre_unidad||'')}',${precio},${p.es_personalizable || 0})"
                 >${escHtml(p.Nombre)}${parseInt(p.es_personalizable) ? '<i class="bi bi-sliders2 ms-1" style="font-size:.7rem;opacity:.6;" title="Personalizable"></i>' : ''}</div>`;
    });
    html += '</div>';
    $('#catalogoPanel').html(html);
}

/* ---- Agregar producto al detalle ---- */
function agregarProducto(id, nombre, unidad, precio = 0, esPersonalizable = 0) {
    const precioNum = parseFloat(precio) || 0;
    const existe = detalleFactura.find(d => d.id_presentacion === id);

    if (existe) {
        // Si ya existe, solo incrementa cantidad (no vuelve a abrir modal)
        existe.cantidad += 1;
        const precioUnit = existe.precio_unitario || 0;
        existe.costo_total_iva = Math.round(existe.cantidad * precioUnit * 100) / 100;
        actualizarTablaDetalle();

        const fila = $(`#fila-${id}`);
        fila.addClass('table-warning');
        setTimeout(() => fila.removeClass('table-warning'), 600);
        return;
    }

    const nuevoItem = {
        id_presentacion:      id,
        nombre:               nombre,
        unidad:               unidad,
        precio_unitario_base: precioNum, // precio original sin extras
        precio_unitario:      precioNum, // precio efectivo (base + extras)
        cantidad:             1,
        costo_total_iva:      precioNum,
        notas:                '',
        es_personalizable:    parseInt(esPersonalizable) === 1,
        es_canjeado:          false,
        personalizacion: {
            ingredientes_quitados:   [],  // [{ id_componente, nombre_componente }]
            ingredientes_sustituidos:[],  // [{ id_componente, nombre_original, id_nuevo, nombre_nuevo }]
            endulzante_id:           null,
            endulzante_nombre:       '',
            extras_agregados:        [],  // [{ id, nombre, precio }]
            monto_extras:            0
        }
    };

    detalleFactura.push(nuevoItem);
    actualizarTablaDetalle();

    /* Al agregar por primera vez, si tiene receta, abrir modal de personalización */
    if (parseInt(esPersonalizable) === 1) {
        const idx = detalleFactura.length - 1;
        setTimeout(() => abrirModalPersonalizacion(idx), 150);
    }
}

/* ---- Renderizar tabla de detalle (columna derecha) ---- */
function actualizarTablaDetalle() {
    $('#contadorProductos').text(`${detalleFactura.length} ítem(s)`);

    const isClub = $('input[name="tipo_cliente"]:checked').val() === 'club';
    const idClienteClub = $('#idClienteClub').val();
    const esClubValido = isClub && Boolean(idClienteClub) && Boolean(clienteActual);

    if (detalleFactura.length === 0) {
        $('#tablaDetalleBody').html(`
            <tr id="filaVacia">
                <td colspan="5" class="empty-detalle">
                    <i class="bi bi-box-seam me-2"></i>
                    Selecciona productos desde el catálogo
                </td>
            </tr>`);
        actualizarTotal();
        return;
    }

    let html = '';
    detalleFactura.forEach((item, idx) => {
        const precioUnit = (item.precio_unitario || 0);
        const precioNormalTotal = Math.round(item.cantidad * precioUnit * 100) / 100;
        const precioTotalLinea = item.es_canjeado ? 0 : (item.costo_total_iva || 0);

        /* Resumen de personalización visible en la fila */
        const pers = item.personalizacion || {};
        let resumenPers = '';
        if (pers.endulzante_nombre) resumenPers += `<span class="badge-pers-item"><i class="bi bi-water"></i> ${escHtml(pers.endulzante_nombre)}</span>`;
        if (pers.ingredientes_quitados && pers.ingredientes_quitados.length)
            resumenPers += `<span class="badge-pers-item badge-pers-quitar"><i class="bi bi-x"></i> Sin: ${pers.ingredientes_quitados.map(i=>escHtml(i.nombre_componente)).join(', ')}</span>`;
        if (pers.ingredientes_sustituidos && pers.ingredientes_sustituidos.length)
            resumenPers += `<span class="badge-pers-item badge-pers-sustituir"><i class="bi bi-arrow-left-right"></i> ${pers.ingredientes_sustituidos.map(i=>`${escHtml(i.nombre_original)} → ${escHtml(i.nombre_nuevo)}`).join(', ')}</span>`;
        if (pers.extras_agregados && pers.extras_agregados.length)
            resumenPers += `<span class="badge-pers-item badge-pers-extra"><i class="bi bi-plus-circle"></i> Extra: ${pers.extras_agregados.map(e=>escHtml(e.nombre)).join(', ')}</span>`;

        html += `
        <tr id="fila-${item.id_presentacion}" class="fila-producto-principal ${item.es_canjeado ? 'fila-canjeada' : ''}" data-idx="${idx}">
            <td>
                <div class="d-flex align-items-start gap-1">
                    <div class="flex-grow-1">
                        <strong style="font-size:.88rem; color:#1e293b;">${escHtml(item.nombre)}</strong>
                        ${item.unidad ? `<small class="d-block text-muted" style="font-size:.76rem;">${escHtml(item.unidad)}</small>` : ''}
                        ${resumenPers ? `<div class="mt-1 d-flex flex-wrap gap-1">${resumenPers}</div>` : ''}
                    </div>
                    ${item.es_personalizable ? `<button type="button" class="btn-personalizar-fila" onclick="abrirModalPersonalizacion(${idx})" title="Personalizar receta">
                        <i class="bi bi-sliders2"></i>
                    </button>` : ''}
                </div>
            </td>
            <td class="text-center" style="width:115px;">
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="modificarCantidad(${idx}, -1)" title="Disminuir">
                        <i class="bi bi-dash-lg"></i>
                    </button>
                    <input type="number" class="qty-input input-cantidad"
                           value="${item.cantidad}" min="1" step="1"
                           data-idx="${idx}"
                           onchange="actualizarItem(${idx}, 'cantidad', this.value)">
                    <button type="button" class="qty-btn" onclick="modificarCantidad(${idx}, 1)" title="Aumentar">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </td>
            <td class="text-end" style="white-space: nowrap;">
                ${item.es_canjeado ? `
                    <del class="d-block precio-tachado-canje" style="font-variant-numeric: tabular-nums;">
                        C$ ${precioNormalTotal.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </del>
                    <strong class="precio-cero-canje" style="font-variant-numeric: tabular-nums;">
                        C$ 0.00
                    </strong>
                ` : `
                    <strong class="text-dark" style="font-size:.92rem; font-variant-numeric: tabular-nums;">
                        C$ ${precioTotalLinea.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </strong>
                    ${item.cantidad > 1 ? `<small class="d-block text-muted" style="font-size:.72rem;">(C$ ${precioUnit.toFixed(2)} c/u)</small>` : ''}
                    ${(pers.monto_extras || 0) > 0 ? `<small class="d-block text-success" style="font-size:.72rem;"><i class="bi bi-plus-circle"></i> +C$ ${(pers.monto_extras||0).toFixed(2)} extras</small>` : ''}
                `}
            </td>
            <td class="text-center" style="width:65px;">
                <label class="switch-canje-pos ${!esClubValido ? 'switch-canje-deshabilitado' : ''}"
                       title="${!esClubValido ? 'Canje disponible únicamente para miembros verificados del Club Pitaya' : (item.es_canjeado ? 'Desmarcar canje' : 'Marcar como producto canjeado (C$ 0.00)')}">
                    <input type="checkbox" ${item.es_canjeado ? 'checked' : ''} ${!esClubValido ? 'disabled' : ''} onchange="toggleCanjeadoItem(${idx}, this.checked)">
                    <span class="slider-canje-pos"></span>
                </label>
            </td>
            <td class="text-center" style="width:40px;">
                <button class="btn-eliminar-fila" onclick="eliminarItem(${idx})" title="Quitar">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </td>
        </tr>
        <tr class="fila-producto-nota" data-idx="${idx}">
            <td colspan="5">
                <div class="item-nota-row">
                    <span class="item-nota-lbl">Notas:</span>
                    <input type="text" class="item-nota-input"
                           placeholder="Escribir nota del producto (ej. sin pajilla, poco hielo, mensaje en vaso)..."
                           value="${escAttr(item.notas || '')}"
                           data-idx="${idx}"
                           oninput="actualizarNotaItem(${idx}, this.value)">
                </div>
            </td>
        </tr>`;
    });

    $('#tablaDetalleBody').html(html);
    actualizarTotal();
}

/* ---- Toggle Canjeado: Pone el producto a C$ 0.00 con precio original tachado ---- */
function toggleCanjeadoItem(idx, checked) {
    if (!detalleFactura[idx]) return;

    const isClub = $('input[name="tipo_cliente"]:checked').val() === 'club';
    const idClienteClub = $('#idClienteClub').val();
    const esClubValido = isClub && Boolean(idClienteClub) && Boolean(clienteActual);

    // Si intenta canjear sin ser cliente club verificado
    if (checked && !esClubValido) {
        actualizarTablaDetalle();
        Swal.fire({
            icon: 'warning',
            title: 'Cliente Club Requerido',
            text: 'La opción de canjear productos con puntos aplica únicamente para miembros verificados del Club Pitaya.',
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#0E544C'
        });
        return;
    }

    const item = detalleFactura[idx];
    item.es_canjeado = checked;

    if (checked) {
        item.costo_total_iva = 0;
        item.es_canje_puntos = 1;
        item.bloquea_puntos = 1;
    } else {
        item.costo_total_iva = Math.round(item.cantidad * (item.precio_unitario || 0) * 100) / 100;
        item.es_canje_puntos = 0;
        item.bloquea_puntos = 0;
    }

    actualizarTablaDetalle();
}

/* ---- Actualizar nota individual sin re-renderizar para no perder foco ---- */
function actualizarNotaItem(idx, valor) {
    if (detalleFactura[idx]) {
        detalleFactura[idx].notas = valor;
    }
}

/* ---- Actualizar valor de un campo en el detalle ---- */
function actualizarItem(idx, campo, valor) {
    if (!detalleFactura[idx]) return;
    if (campo === 'cantidad') {
        const cant = Math.max(1, parseInt(valor) || 1);
        detalleFactura[idx].cantidad = cant;
        if (detalleFactura[idx].es_canjeado) {
            detalleFactura[idx].costo_total_iva = 0;
        } else {
            detalleFactura[idx].costo_total_iva = Math.round(cant * (detalleFactura[idx].precio_unitario || 0) * 100) / 100;
        }
    }
    actualizarTablaDetalle();
}

/* ---- Eliminar ítem del detalle ---- */
function eliminarItem(idx) {
    detalleFactura.splice(idx, 1);
    actualizarTablaDetalle();
}

/* ---- Modificar cantidad con botones +/- ---- */
function modificarCantidad(idx, delta) {
    if (!detalleFactura[idx]) return;
    const item = detalleFactura[idx];
    let nueva = (parseInt(item.cantidad) || 1) + delta;
    if (nueva < 1) nueva = 1;

    item.cantidad = nueva;
    if (item.es_canjeado) {
        item.costo_total_iva = 0;
    } else {
        item.costo_total_iva = Math.round(nueva * (item.precio_unitario || 0) * 100) / 100;
    }
    actualizarTablaDetalle();
}

/* ============================================================
   LÓGICA DE MÉTODOS DE PAGO Y DESGLOSE FINANCIERO
   ============================================================ */

function calcularSubtotalFactura() {
    return detalleFactura.reduce((s, d) => s + (parseFloat(d.costo_total_iva) || 0), 0);
}

function calcularMontoPropina(subtotal) {
    return conPropina ? Math.round(subtotal * (PORCENTAJE_PROPINA / 100) * 100) / 100 : 0;
}

function calcularTotalAPagar() {
    const subtotal = calcularSubtotalFactura();
    const propina = calcularMontoPropina(subtotal);
    return Math.round((subtotal + propina) * 100) / 100;
}

function sumarTotalPagos() {
    return Math.round(pagos.reduce((s, p) => s + (parseFloat(p.monto) || 0), 0) * 100) / 100;
}

function agregarMetodoPago(metodo) {
    if (detalleFactura.length === 0) {
        alertError('Primero debe agregar productos al pedido antes de seleccionar métodos de pago.');
        return;
    }

    const existeIdx = pagos.findIndex(p => p.metodo === metodo);
    if (existeIdx !== -1) {
        if (metodo === 'cupon') {
            $(`#inputCodigoCupon-${existeIdx}`).focus().select();
        } else {
            $(`#inputMontoPago-${existeIdx}`).focus().select();
        }
        return;
    }

    const totalFactura = calcularTotalAPagar();
    const pagado = sumarTotalPagos();
    const pendiente = Math.max(0, Math.round((totalFactura - pagado) * 100) / 100);
    const montoInicial = pendiente > 0 ? pendiente : 0;

    /* ============================================================
       REGISTRO DEL MÉTODO DE PAGO
       Para 'cupon':
       - Inicialmente monto = 0 hasta que sea validado contra la BD.
       - validado = false
       - id_cupon = null
       ============================================================ */
    if (metodo === 'cupon') {
        pagos.push({
            metodo:        'cupon',
            monto:         0,
            recibido:      0,
            cambio:        0,
            referencia:    '',    // Almacena el número/código del cupón
            id_cupon:      null,  // ID de la tabla cupones_sucursales
            validado:      false, // Bandera de validación en tiempo real
            monto_cupon:   0,     // Monto nominal de descuento del cupón
            observaciones: ''
        });
    } else {
        const nuevoPago = {
            metodo:     metodo,
            monto:      0,
            recibido:   0,
            cambio:     0,
            referencia: ''
        };
        pagos.push(nuevoPago);
    }

    renderizarFilasPago();
    actualizarTotal(false);

    const nuevoIdx = pagos.length - 1;
    setTimeout(() => {
        if (metodo === 'cupon') {
            $(`#inputCodigoCupon-${nuevoIdx}`).focus().select();
        } else {
            $(`#inputMontoPago-${nuevoIdx}`).focus().select();
        }
    }, 50);
}

function quitarMetodoPago(idx) {
    if (!pagos[idx]) return;
    pagos.splice(idx, 1);
    renderizarFilasPago();
    actualizarTotal(false);
}

function actualizarMontoPago(idx, val) {
    if (!pagos[idx]) return;
    const m = Math.max(0, parseFloat(val) || 0);
    pagos[idx].monto = Math.round(m * 100) / 100;

    if (pagos[idx].metodo === 'efectivo') {
        // Cambio = lo que entrega el cliente (monto) menos lo que aplica a la factura (recibido)
        const aplicado = pagos[idx].recibido || 0;
        const cambio = Math.max(0, pagos[idx].monto - aplicado);
        pagos[idx].cambio = Math.round(cambio * 100) / 100;
        $(`#badgeCambio-${idx}`).text(`Cambio: C$ ${pagos[idx].cambio.toFixed(2)}`);
    }

    actualizarTotal(false);
}

function actualizarRecibidoEfectivo(idx, val) {
    if (!pagos[idx]) return;
    const rec = Math.max(0, parseFloat(val) || 0);
    pagos[idx].recibido = Math.round(rec * 100) / 100;
    const cambio = Math.max(0, (pagos[idx].monto || 0) - pagos[idx].recibido);
    pagos[idx].cambio = Math.round(cambio * 100) / 100;
    $(`#badgeCambio-${idx}`).text(`Cambio: C$ ${pagos[idx].cambio.toFixed(2)}`);
}

function actualizarReferenciaPago(idx, val) {
    if (!pagos[idx]) return;
    pagos[idx].referencia = val.trim();
}


/* ============================================================
   VALIDACIÓN DE CUPONES (AJAX A validar_cupon.php)
   ============================================================
   NOTA TÉCNICA Y PASE A PRODUCCIÓN:
   - Consulta ajax/validar_cupon.php en Modo Pruebas (solo lectura).
   - Aplica el monto nominal del cupón hasta cubrir el total de la orden.
   - Reajusta automáticamente el saldo de métodos combinados (Efectivo/POS).
   ============================================================ */
function validarCupon(idx) {
    if (!pagos[idx] || pagos[idx].metodo !== 'cupon') return;
    const p = pagos[idx];

    // Si ya estaba validado y hace clic de nuevo, permite editar el código
    if (p.validado) {
        p.validado = false;
        p.monto = 0;
        p.monto_cupon = 0;
        p.id_cupon = null;
        renderizarFilasPago();
        actualizarTotal(false);
        $(`#inputCodigoCupon-${idx}`).focus().select();
        return;
    }

    const input = document.getElementById(`inputCodigoCupon-${idx}`);
    const codigo = input ? input.value.trim() : (p.referencia || '');
    if (!codigo) {
        alertWarning('Por favor ingresa el código del cupón antes de validar.');
        if (input) input.focus();
        return;
    }

    p.referencia = codigo;
    const $btn = $(`#btnValidarCupon-${idx}`);
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Validando...');

    $.ajax({
        url: 'ajax/validar_cupon.php',
        type: 'POST',
        data: { codigo: codigo },
        dataType: 'json',
        success: function (res) {
            $btn.prop('disabled', false);
            if (res.success && res.data) {
                const cup = res.data;
                p.validado = true;
                p.id_cupon = cup.id_cupon;
                p.referencia = cup.numero_cupon;
                p.monto_cupon = parseFloat(cup.monto) || 0;
                p.observaciones = cup.observaciones || '';

                // El cupón descuenta su monto nominal o el total de la factura
                const totalFactura = calcularTotalAPagar();
                p.monto = Math.min(p.monto_cupon, totalFactura);

                // Reajustar métodos de pago mixtos (por ejemplo, reducir el efectivo pendiente)
                reajustarPagosConCupon(idx);

                renderizarFilasPago();
                actualizarTotal(false);

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Cupón Aplicado!',
                        html: `Monto de <strong>C$ ${p.monto.toFixed(2)}</strong> aplicado con cupón <code>${escHtml(p.referencia)}</code>.<br><small class="text-muted">(Fase Pruebas: el estado real del cupón en BD permanece activo)</small>`,
                        timer: 2800,
                        showConfirmButton: false,
                        toast: true,
                        position: 'top-end'
                    });
                }
            } else {
                p.validado = false;
                p.monto = 0;
                p.id_cupon = null;
                renderizarFilasPago();
                actualizarTotal(false);
                alertError(res.message || 'Cupón inválido o caducado.');
            }
        },
        error: function () {
            $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> VALIDAR');
            alertError('Error de conexión al validar el cupón.');
        }
    });
}

/* ---- Reajustar pagos combinados al aplicar un cupón ---- */
function reajustarPagosConCupon(cuponIdx) {
    const totalFactura = calcularTotalAPagar();
    const cuponMonto = pagos[cuponIdx] ? pagos[cuponIdx].monto : 0;
    const restante = Math.max(0, Math.round((totalFactura - cuponMonto) * 100) / 100);

    // Buscar si hay otro método de pago (como efectivo o POS)
    const otrosPagos = pagos.filter((p, i) => i !== cuponIdx && p.metodo !== 'cupon');
    if (otrosPagos.length === 1) {
        otrosPagos[0].monto = restante;
        if (otrosPagos[0].metodo === 'efectivo') {
            if ((otrosPagos[0].recibido || 0) < restante || otrosPagos[0].recibido === totalFactura) {
                otrosPagos[0].recibido = restante;
            }
            otrosPagos[0].cambio = Math.max(0, (otrosPagos[0].recibido || restante) - restante);
        }
    }
}

function renderizarFilasPago() {
    $('#btnPagoEfectivo').toggleClass('activo', pagos.some(p => p.metodo === 'efectivo'));
    $('#btnPagoPOS').toggleClass('activo', pagos.some(p => p.metodo === 'pos'));
    $('#btnPagoTransferencia').toggleClass('activo', pagos.some(p => p.metodo === 'transferencia'));
    $('#btnPagoCupon').toggleClass('activo', pagos.some(p => p.metodo === 'cupon'));
    $('#btnPagoEfectivoDolar').toggleClass('activo', pagos.some(p => p.metodo === 'efectivo_dolar'));

    if (pagos.length === 0) {
        $('#contenedorFilasPago').html('');
        return;
    }

    let html = '';
    pagos.forEach((p, idx) => {
        /* ============================================================
           FILA DE PAGO: CUPÓN (Diseño idéntico al boceto)
           ============================================================ */
        if (p.metodo === 'cupon') {
            html += `
            <div class="fila-pago-card fila-pago-cupon ${p.validado ? 'cupon-validado' : ''}" id="cardPago-${idx}">
                <div class="fila-pago-header align-items-center">
                    <div class="pago-metodo-info">
                        <i class="bi bi-ticket-perforated"></i>
                        <span>CÓDIGO DE CUPÓN</span>
                    </div>
                    <div class="pago-cupon-inline-actions">
                        <div class="input-codigo-cupon-wrap">
                            <input type="text" class="input-codigo-cupon"
                                   id="inputCodigoCupon-${idx}"
                                   placeholder="Ej: PROMO2024"
                                   value="${escAttr(p.referencia || '')}"
                                   ${p.validado ? 'readonly' : ''}
                                   onkeydown="if(event.key==='Enter'){ event.preventDefault(); validarCupon(${idx}); }">
                        </div>
                        <button type="button" class="btn-validar-cupon ${p.validado ? 'btn-cupon-exito' : ''}"
                                id="btnValidarCupon-${idx}"
                                onclick="validarCupon(${idx})"
                                title="${p.validado ? 'Clic para editar código' : 'Validar cupón en línea'}">
                            ${p.validado ? '<i class="bi bi-check2-circle me-1"></i> VALIDADO' : '<i class="bi bi-check-lg me-1"></i> VALIDAR'}
                        </button>
                        <div class="cupon-monto-display">
                            <span class="monto-valor">C$ ${p.monto.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                        </div>
                        <button type="button" class="btn-quitar-pago" onclick="quitarMetodoPago(${idx})" title="Quitar cupón">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>
                ${p.observaciones ? `<div class="cupon-subnota text-muted small px-3 pb-2"><i class="bi bi-info-circle me-1"></i>${escHtml(p.observaciones)}</div>` : ''}
            </div>`;
            return;
        }

        /* ============================================================
           FILAS DE PAGO: EFECTIVO / EFECTIVO DOLAR / POS / TRANSFERENCIA
           ============================================================ */
        let icono = 'bi-cash-stack';
        let nombre = 'EFECTIVO';
        let prefijo = 'C$';
        if (p.metodo === 'efectivo_dolar') {
            icono = 'bi-cash-coin';
            nombre = 'EFECTIVO USD';
            prefijo = 'US$';
        } else if (p.metodo === 'pos') {
            icono = 'bi-credit-card-2-front';
            nombre = 'TARJETA (POS)';
        } else if (p.metodo === 'transferencia') {
            icono = 'bi-bank';
            nombre = 'TRANSFERENCIA';
        }

        html += `
        <div class="fila-pago-card" id="cardPago-${idx}">
            <div class="fila-pago-header">
                <div class="pago-metodo-info">
                    <i class="bi ${icono}"></i>
                    <span>${nombre}</span>
                </div>
                <div class="pago-campos-inline">
                    <div class="input-monto-pago-wrap">
                        <span class="prefijo-monto">${prefijo}</span>
                        <input type="number" step="0.01" min="0" class="input-monto-pago"
                               id="inputMontoPago-${idx}"
                               value="${p.monto}"
                               oninput="actualizarMontoPago(${idx}, this.value)">
                    </div>
                    <button type="button" class="btn-quitar-pago" onclick="quitarMetodoPago(${idx})" title="Quitar método">
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </div>
            </div>`;

        if (p.metodo === 'efectivo_dolar') {
            const tcUSD = tipoCambioUSD || 0;
            const equivCor = tcUSD > 0 ? (p.monto * tcUSD).toFixed(2) : '—';
            html += `
            <div class="subdetalle-referencia subdetalle-dolar" style="display:flex;align-items:center;gap:8px;padding:4px 12px;">
                <i class="bi bi-info-circle" style="color:#64748b;font-size:.8rem;"></i>
                <span style="font-size:.78rem;color:#64748b;">
                    T/C del día: <strong>C$ ${tcUSD > 0 ? tcUSD.toFixed(4) : 'No disponible'}</strong>
                    ${tcUSD > 0 && p.monto > 0 ? ' &nbsp;·&nbsp; Equivale a <strong>C$ ' + equivCor + '</strong>' : ''}
                </span>
            </div>`;
        } else if (p.metodo !== 'efectivo') {
            const placeholderRef = p.metodo === 'pos' ? 'N° Voucher / Autorización (opcional)...' : 'N° Referencia bancaria (opcional)...';
            html += `
            <div class="subdetalle-referencia">
                <input type="text"
                       placeholder="${placeholderRef}"
                       value="${escAttr(p.referencia || '')}"
                       oninput="actualizarReferenciaPago(${idx}, this.value)">
            </div>`;
        }

        html += `</div>`;
    });

    $('#contenedorFilasPago').html(html);
}

/* ---- Actualizar total y cobertura ---- */
function actualizarTotal(reajustarPagosAuto = true) {
    const subtotal = calcularSubtotalFactura();
    const propina = calcularMontoPropina(subtotal);
    const total = Math.round((subtotal + propina) * 100) / 100;

    $('#txtSubtotalFactura').text('C$ ' + subtotal.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    $('#txtMontoPropina').text('C$ ' + propina.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    $('#totalFacturaMonto').text('C$ ' + total.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));


    const $alerta = $('#alertaCoberturaPagos');
    if (detalleFactura.length === 0) {
        $alerta.hide();
        actualizarPuntosFactura();
        return;
    }

    if (pagos.length === 0) {
        $alerta.removeClass('cobertura-completa cobertura-exceso').addClass('cobertura-pendiente').show()
               .html('<i class="bi bi-info-circle me-1"></i> Seleccione un método de pago.');
        actualizarPuntosFactura();
        return;
    }

    const totalPagado = sumarTotalPagos();
    const diff = Math.round((totalPagado - total) * 100) / 100;

    if (diff === 0) {
        $alerta.removeClass('cobertura-pendiente cobertura-exceso').addClass('cobertura-completa').show()
               .html(`<i class="bi bi-check-circle-fill me-1"></i> Pagos completos (C$ ${total.toFixed(2)})`);
    } else if (diff < 0) {
        $alerta.removeClass('cobertura-completa cobertura-exceso').addClass('cobertura-pendiente').show()
               .html(`<i class="bi bi-exclamation-triangle-fill me-1"></i> Falta por cubrir: C$ ${Math.abs(diff).toFixed(2)}`);
    } else {
        $alerta.removeClass('cobertura-pendiente cobertura-exceso').addClass('cobertura-completa').show()
               .html(`<i class="bi bi-arrow-left-right me-1"></i> <strong>Cambio a devolver: C$ ${diff.toFixed(2)}</strong>`);
    }

    actualizarPuntosFactura();
}

/* ---- Guardar factura / orden ---- */
function guardarFactura() {
    const isClub        = $('input[name="tipo_cliente"]:checked').val() === 'club';
    const idClienteClub = $('#idClienteClub').val();
    const nombreCliente = $('#nombreCliente').val().trim();
    const numero        = $('#numeroFactura').val().trim();
    const fecha         = $('#fechaFactura').val();

    if (isClub && !idClienteClub) {
        alertError('Debe ingresar un miembro válido del Club Pitaya, o cambiar a Cliente General.');
        $('#membresiaCliente').focus();
        return;
    }
    if (!isClub && !nombreCliente) {
        alertError('Por favor ingrese el nombre del cliente.');
        $('#nombreCliente').focus();
        return;
    }
    if (detalleFactura.length === 0) {
        alertError('Debe agregar al menos un producto a la factura.');
        return;
    }

    // Validar montos de productos
    for (let i = 0; i < detalleFactura.length; i++) {
        const d = detalleFactura[i];
        if (d.cantidad <= 0) {
            alertError(`La cantidad debe ser mayor a 0 en "${d.nombre}".`); return;
        }
    }

    // Validar métodos de pago
    if (pagos.length === 0) {
        alertError('Debe seleccionar al menos un método de pago.');
        return;
    }

    // Validar que cualquier cupón agregado esté verificado con éxito
    const cuponSinValidar = pagos.find(p => p.metodo === 'cupon' && !p.validado);
    if (cuponSinValidar) {
        alertError('Tiene un cupón pendiente de validar. Ingrese el código y presione VALIDAR o elimínelo.');
        return;
    }

    const totalFactura = calcularTotalAPagar();
    const totalPagos = sumarTotalPagos();
    const diff = Math.round((totalPagos - totalFactura) * 100) / 100;

    if (diff < 0) {
        alertError(`Los pagos no cubren el total de la factura. Falta cubrir C$ ${Math.abs(diff).toFixed(2)}.`);
        return;
    }
    if (diff > 0) {
        alertError(`Los pagos ingresados (C$ ${totalPagos.toFixed(2)}) superan el total a pagar (C$ ${totalFactura.toFixed(2)}). Ajuste los montos.`);
        return;
    }

    const $btn = $('#btnGuardar');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Guardando…');

    $.post('/modulos/pos/ajax/facturas_guardar.php', {
        tipo_cliente:    isClub ? 'club' : 'general',
        id_cliente_club: isClub ? idClienteClub : null,
        nombre_cliente:  isClub ? (clienteActual ? clienteActual.nombre_completo : '') : nombreCliente,
        numero_factura:  numero,
        fecha:           fecha,
        detalle:         JSON.stringify(detalleFactura),
        pagos:           JSON.stringify(pagos),
        subtotal:        calcularSubtotalFactura(),
        propina:         calcularMontoPropina(calcularSubtotalFactura()),
        con_propina:     conPropina ? 1 : 0,
        total:           totalFactura
    }, function (res) {
        if (res.success) {
            Swal.fire({
                title:             '¡Venta guardada!',
                text:              res.message || 'La venta ha sido registrada con éxito.',
                icon:              'success',
                confirmButtonText: 'Nueva Venta',
                confirmButtonColor:'#51B8AC'
            }).then(() => {
                // Reset completo para la siguiente venta
                detalleFactura = [];
                actualizarTablaDetalle();
                pagos = [];
                renderizarFilasPago();
                conPropina = false;
                $('#switchPropina').prop('checked', false);
                $('#tipoClienteGeneral').prop('checked', true);
                cambiarTipoCliente('general');
                $('#buscarProducto').val('');
                $('#btnLimpiarBusqueda').hide();
                if (grupoActivo !== null && _grupos) {
                    cambiarGrupo(grupoActivo);
                } else if (todosProductos.length > 0) {
                    renderizarCatalogoPorGrupos(todosProductos);
                }
                $btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Factura');
            });
        } else {
            alertError(res.message);
            $btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Factura');
        }
    }, 'json').fail(function () {
        alertError('Error de conexión al guardar la factura.');
        $btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Factura');
    });
}

/* ---- Helpers ---- */
function alertError(msg) {
    Swal.fire({ title: 'Error', text: msg, icon: 'error', confirmButtonColor: '#0E544C' });
}

function alertWarning(msg) {
    Swal.fire({ title: 'Atención', text: msg, icon: 'warning', confirmButtonColor: '#0E544C' });
}

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function escAttr(str) {
    return String(str || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
}


/* ============================================================
   MODAL DE PERSONALIZACIÓN DE RECETA (Paso 2 POS)
   ============================================================

   Estado local del modal — se trabaja sobre una COPIA del item
   hasta que el usuario presione "Aplicar".

   NOTAS PARA DESARROLLO FUTURO / PASE A PRODUCCIÓN:
   - Los ingredientes quitados/sustituidos no alteran el precio
     (sin costo adicional por sustitución).
   - Solo los extras tienen precio propio y suman al precio_unitario.
   - Cuando se integre el sistema de órdenes en producción, la
     personalización debe enviarse al backend como JSON dentro del
     detalle de la factura y registrarse en una tabla de
     personalizaciones (aún por diseñar).
   - La sección de "Combos Sugeridos" está como placeholder visual
     (TODO: funcionalidad cuando se defina la lógica de combos).
   ============================================================ */

// Índice del item actualmente en edición en el modal
let _mpIdx = -1;

// Copia de trabajo de la personalización (se confirma solo al dar "Aplicar")
let _mpDraft = null;

// Cache de datos de receta por id_presentacion
const _mpCache = {};

/* ---- Abrir modal y cargar datos de receta ---- */
function abrirModalPersonalizacion(idx) {
    const item = detalleFactura[idx];
    if (!item) return;

    _mpIdx = idx;

    // Clonar personalización actual como borrador de trabajo
    _mpDraft = JSON.parse(JSON.stringify(item.personalizacion || {
        ingredientes_quitados:    [],
        ingredientes_sustituidos: [],
        endulzante_id:            null,
        endulzante_nombre:        '',
        extras_agregados:         [],
        monto_extras:             0
    }));

    // Actualizar título del modal
    $('#modalPersonalizacionLabel').text('Personalizar: ' + item.nombre);
    $('#mpSubtitulo').text('Ajusta ingredientes, endulzante y extras');
    $('#mpCuerpo').html('<div class="d-flex align-items-center justify-content-center py-5"><span class="spinner-border spinner-border-sm me-2 text-secondary"></span><span class="text-muted">Cargando receta…</span></div>');
    $('#mpAjustePrecio').hide();

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPersonalizacion'));
    modal.show();

    // Si ya está en caché, no volver a pedir
    if (_mpCache[item.id_presentacion]) {
        _mpRenderContenido(_mpCache[item.id_presentacion]);
        return;
    }

    $.ajax({
        url: 'ajax/get_receta_producto.php',
        type: 'GET',
        data: { id_presentacion: item.id_presentacion },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                _mpCache[item.id_presentacion] = res;
                _mpRenderContenido(res);
            } else {
                $('#mpCuerpo').html(`<div class="alert alert-warning m-3"><i class="bi bi-exclamation-triangle me-1"></i> ${escHtml(res.message || 'No se pudieron cargar los datos de receta.')}</div>`);
            }
        },
        error: function () {
            $('#mpCuerpo').html('<div class="alert alert-danger m-3"><i class="bi bi-cloud-slash me-1"></i> Error de conexión al cargar la receta.</div>');
        }
    });
}

/* ---- Renderizar contenido del modal con los datos de receta ---- */
function _mpRenderContenido(datos) {
    let html = '<div class="mp-body-inner p-3">';

    /* ---- SECCIÓN: Endulzante (primero, diseño agrupado por nombre) ---- */
    if (datos.endulzantes && datos.endulzantes.length > 0) {
        // Preseleccionar endulzante sugerido si no hay selección previa
        if (!_mpDraft.endulzante_id && datos.endulzante_default_id) {
            const sugerido = datos.endulzantes.find(e => e.id === datos.endulzante_default_id);
            if (sugerido) {
                _mpDraft.endulzante_id = sugerido.id;
                _mpDraft.endulzante_nombre = sugerido.nombre_opcion || sugerido.nombre;
                _mpDraft.endulzante_nombre_grupo = sugerido.nombre;
            }
        }

        // Agrupar por campo "nombre" (nombre del endulzante base)
        const grupos = new Map();
        datos.endulzantes.forEach(end => {
            const grupo = end.nombre || 'Sin nombre';
            if (!grupos.has(grupo)) grupos.set(grupo, []);
            grupos.get(grupo).push(end);
        });

        html += `
        <div class="mp-seccion">
            <div class="mp-seccion-header"><i class="bi bi-droplet-half"></i> Endulzante
                <small class="text-muted ms-2" style="font-weight:400; font-size:.8rem;">Selecciona uno</small>
            </div>
            <div class="mp-endulzante-grupos">`;

        grupos.forEach((opciones, nombreGrupo) => {
            // Determinar si alguna opción de este grupo está seleccionada
            const opcionSeleccionada = opciones.find(o => o.id === _mpDraft.endulzante_id);
            const grupoActivo = Boolean(opcionSeleccionada);
            const tieneMultiples = opciones.length > 1;

            // IDs del grupo para el select
            const grupoKey = nombreGrupo.replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_]/g, '');

            html += `
            <div class="mp-endulzante-grupo-row ${grupoActivo ? 'activo' : ''}" data-grupo="${escAttr(nombreGrupo)}">
                <button type="button" class="mp-endulzante-grupo-btn ${grupoActivo ? 'activo' : ''}"
                        data-grupo="${escAttr(nombreGrupo)}"
                        data-primera-id="${opciones[0].id}"
                        data-primera-nombre="${escAttr(opciones[0].nombre_opcion || opciones[0].nombre)}"
                        onclick="_mpSeleccionarGrupoEndulzante(this)">
                    <span class="mp-end-grupo-icono"><i class="bi bi-water"></i></span>
                    <span class="mp-end-grupo-nombre">${escHtml(nombreGrupo)}</span>
                </button>`;

            if (tieneMultiples) {
                // Dropdown de cantidad/variante
                html += `
                <div class="mp-endulzante-variante-wrap ${grupoActivo ? '' : 'mp-end-variante-oculto'}" id="varianteWrap_${grupoKey}">
                    <select class="mp-endulzante-variante-select form-select form-select-sm"
                            id="varianteSelect_${grupoKey}"
                            data-grupo="${escAttr(nombreGrupo)}"
                            onchange="_mpSeleccionarVarianteEndulzante(this)">`;

                opciones.forEach(op => {
                    const opNombre = op.nombre_opcion || op.nombre;
                    const sel = op.id === _mpDraft.endulzante_id ? 'selected' : '';
                    html += `<option value="${op.id}" data-nombre="${escAttr(opNombre)}" ${sel}>${escHtml(opNombre)}</option>`;
                });

                html += `
                    </select>
                </div>`;
            } else {
                // Opción única: guardamos id directamente en el botón
                html += `<input type="hidden" data-grupo="${escAttr(nombreGrupo)}" value="${opciones[0].id}">`;
            }

            html += `</div>`; // cierre mp-endulzante-grupo-row
        });

        html += '</div></div>'; // cierre mp-endulzante-grupos y mp-seccion
    }

    /* ---- SECCIÓN: Ingredientes de la Receta ---- */
    if (datos.tiene_receta && datos.ingredientes && datos.ingredientes.length > 0) {
        html += `
        <div class="mp-seccion">
            <div class="mp-seccion-header">
                <i class="bi bi-list-check"></i> Ingredientes de la Receta
                <small class="text-muted ms-2" style="font-weight:400; font-size:.8rem;">Puedes quitar o sustituir ingredientes (sin costo adicional)</small>
            </div>
            <div class="mp-ingredientes-lista">`;

        datos.ingredientes.forEach(ing => {
            const idComp     = ing.id_componente;
            const quitado    = _mpDraft.ingredientes_quitados.some(q => q.id_componente === idComp);
            const sustituido = _mpDraft.ingredientes_sustituidos.find(s => s.id_componente === idComp);

            const nombreActual = sustituido ? sustituido.nombre_nuevo : '';
            const valorActual  = sustituido ? sustituido.id_nuevo     : '';

            // Construir items del combo buscable usando datos.extras
            let dropdownHtml = `<div class="mp-sust-item mp-sust-sin-cambio" data-id="" data-nombre="" onclick="_mpSeleccionarSustituto(this, ${idComp})"><i class="bi bi-dash me-1"></i>Sin cambio</div>`;
            (datos.extras || []).forEach(ext => {
                dropdownHtml += `<div class="mp-sust-item" data-id="${ext.id}" data-nombre="${escAttr(ext.nombre)}" data-precio="${ext.precio}" onclick="_mpSeleccionarSustituto(this, ${idComp})">
                    <span class="mp-sust-item-nombre">${escHtml(ext.nombre)}</span>
                    <span class="mp-sust-item-precio">+C$ ${parseFloat(ext.precio).toFixed(2)}</span>
                </div>`;
            });

            html += `
            <div class="mp-ingrediente-row ${quitado ? 'mp-ingrediente-quitado' : ''}" data-comp="${idComp}">
                <div class="mp-ing-nombre">
                    <strong>${escHtml(ing.nombre_ingrediente)}</strong>
                    <small class="text-muted">${ing.cantidad} ${escHtml(ing.unidad_ingrediente)}</small>
                </div>
                <div class="mp-ing-controles">
                    <!-- Combo buscable de sustitución (fuente: tabla extras) -->
                    <div class="mp-sustituir-combo ${quitado ? 'mp-combo-disabled' : ''}" data-comp="${idComp}">
                        <input type="text"
                               class="mp-sustituir-input"
                               data-comp="${idComp}"
                               data-nombre-orig="${escAttr(ing.nombre_ingrediente)}"
                               value="${escAttr(nombreActual)}"
                               placeholder="Sin cambio"
                               autocomplete="off"
                               ${quitado ? 'disabled' : ''}
                               oninput="_mpFiltrarSustitutos(this)"
                               onfocus="_mpAbrirDropdownSust(this)"
                               onblur="_mpBlurSustituto(this)">
                        <input type="hidden" class="mp-sustituir-valor" data-comp="${idComp}" value="${valorActual || ''}">
                        <div class="mp-sustituir-dropdown">
                            ${dropdownHtml}
                        </div>
                    </div>
                    <!-- Toggle quitar -->
                    <button type="button" class="btn-mp-quitar ${quitado ? 'activo' : ''}" data-comp="${idComp}"
                            onclick="_mpToggleQuitarIngrediente(this, '${escAttr(ing.nombre_ingrediente)}')"
                            title="${quitado ? 'Restaurar' : 'Quitar este ingrediente'}">
                        ${quitado ? '<i class="bi bi-plus-circle"></i>' : '<i class="bi bi-x-circle"></i>'}
                    </button>
                </div>
            </div>`;
        });

        html += '</div></div>';
    } else if (!datos.tiene_receta) {
        html += '<div class="alert alert-light m-0 mb-3 border"><i class="bi bi-info-circle me-1 text-muted"></i> Este producto no tiene receta registrada. Solo puedes agregar extras o dejar una nota.</div>';
    }

    /* ---- SECCIÓN: Extras (agrupados por grupo, ordenados por precio, acordeón) ---- */
    if (datos.extras && datos.extras.length > 0) {

        // 1) Agrupar por campo "grupo"
        const extrasGrupos = new Map();
        datos.extras.forEach(ext => {
            const g = ext.grupo || 'Otros';
            if (!extrasGrupos.has(g)) extrasGrupos.set(g, []);
            extrasGrupos.get(g).push(ext);
        });

        // 2) Ordenar cada grupo por precio ascendente
        extrasGrupos.forEach(lista => lista.sort((a, b) => parseFloat(a.precio) - parseFloat(b.precio)));

        html += `
        <div class="mp-seccion">
            <div class="mp-seccion-header">
                <i class="bi bi-plus-square"></i> Extras
                <small class="text-muted ms-2" style="font-weight:400; font-size:.8rem;">Los extras sí alteran el precio</small>
            </div>
            <div class="mp-extras-acordeon">`;

        let grupoIdx = 0;
        extrasGrupos.forEach((lista, nombreGrupo) => {
            const grupoKey = 'mpeg_' + grupoIdx++;
            // ¿Hay algún extra de este grupo ya seleccionado? → abrir por defecto
            const tieneActivo = lista.some(ext => _mpDraft.extras_agregados.some(e => e.id === ext.id));
            const cantSelec   = lista.filter(ext => _mpDraft.extras_agregados.some(e => e.id === ext.id)).length;

            html += `
            <div class="mp-extras-grupo ${tieneActivo ? 'abierto' : ''}">
                <button type="button" class="mp-extras-grupo-header" onclick="_mpToggleGrupoExtras(this)" aria-expanded="${tieneActivo}">
                    <span class="mp-extras-grupo-icono"><i class="bi bi-grid-3x3-gap"></i></span>
                    <span class="mp-extras-grupo-nombre">${escHtml(nombreGrupo)}</span>
                    ${cantSelec > 0 ? `<span class="mp-extras-grupo-badge">${cantSelec}</span>` : `<span class="mp-extras-grupo-badge" style="display:none;">0</span>`}
                    <span class="mp-extras-grupo-flecha"><i class="bi bi-chevron-down"></i></span>
                </button>
                <div class="mp-extras-grupo-body">`;

            lista.forEach(ext => {
                const agregado = _mpDraft.extras_agregados.some(e => e.id === ext.id);
                html += `
                <button type="button" class="btn-mp-extra ${agregado ? 'activo' : ''}"
                        data-id="${ext.id}" data-nombre="${escAttr(ext.nombre)}" data-precio="${ext.precio}"
                        onclick="_mpToggleExtra(this)">
                    <span class="mp-extra-nombre">${escHtml(ext.nombre)}</span>
                    <span class="mp-extra-precio">+C$ ${parseFloat(ext.precio).toFixed(2)}</span>
                </button>`;
            });

            html += `</div></div>`; // cierre mp-extras-grupo-body y mp-extras-grupo
        });

        html += '</div></div>'; // cierre mp-extras-acordeon y mp-seccion
    }

    /* ---- SECCIÓN: Combos Sugeridos (placeholder — funcionalidad pendiente) ---- */
    /* TODO: Cuando se definan los combos del menú, mostrar sugerencias relacionadas aquí.
       Pendiente: diseñar tabla de combos y lógica de sugerencias por producto. */
    html += `
    <div class="mp-seccion mp-seccion-placeholder">
        <div class="mp-seccion-header"><i class="bi bi-box-seam"></i> Combos Sugeridos <small class="text-muted ms-2" style="font-weight:400; font-size:.8rem;">(Próximamente)</small></div>
        <div class="mp-placeholder-msg"><i class="bi bi-clock-history me-1"></i> Función disponible cuando se configuren los combos del menú.</div>
    </div>`;

    html += '</div>'; // cierre mp-body-inner
    $('#mpCuerpo').html(html);
    _mpActualizarResumenExtras();
}

/* ---- Toggle: quitar / restaurar ingrediente ---- */
function _mpToggleQuitarIngrediente(btn, nombreComponente) {
    const idComp   = parseInt(btn.dataset.comp);
    const row      = btn.closest('.mp-ingrediente-row');
    const combo    = row ? row.querySelector('.mp-sustituir-combo') : null;
    const inputSust  = combo ? combo.querySelector('.mp-sustituir-input')   : null;
    const hiddenSust = combo ? combo.querySelector('.mp-sustituir-valor')   : null;
    const dropdown   = combo ? combo.querySelector('.mp-sustituir-dropdown') : null;

    const yaQuitado = _mpDraft.ingredientes_quitados.some(q => q.id_componente === idComp);

    if (yaQuitado) {
        // Restaurar
        _mpDraft.ingredientes_quitados = _mpDraft.ingredientes_quitados.filter(q => q.id_componente !== idComp);
        row.classList.remove('mp-ingrediente-quitado');
        btn.classList.remove('activo');
        btn.innerHTML = '<i class="bi bi-x-circle"></i>';
        btn.title = 'Quitar este ingrediente';
        if (inputSust) { inputSust.disabled = false; }
        if (combo)     { combo.classList.remove('mp-combo-disabled'); }
    } else {
        // Quitar
        _mpDraft.ingredientes_quitados.push({ id_componente: idComp, nombre_componente: nombreComponente });
        // Cancelar sustitución previa si la había
        _mpDraft.ingredientes_sustituidos = _mpDraft.ingredientes_sustituidos.filter(s => s.id_componente !== idComp);
        if (inputSust)  { inputSust.value = ''; inputSust.disabled = true; }
        if (hiddenSust) { hiddenSust.value = ''; }
        if (dropdown)   { dropdown.classList.remove('mp-sust-abierto'); }
        if (combo)      { combo.classList.add('mp-combo-disabled'); }

        row.classList.add('mp-ingrediente-quitado');
        btn.classList.add('activo');
        btn.innerHTML = '<i class="bi bi-plus-circle"></i>';
        btn.title = 'Restaurar ingrediente';
    }
}

/* ---- Combo buscable: abrir dropdown ---- */
function _mpAbrirDropdownSust(input) {
    const combo = input.closest('.mp-sustituir-combo');
    if (!combo || combo.classList.contains('mp-combo-disabled')) return;
    const dropdown = combo.querySelector('.mp-sustituir-dropdown');
    // Mostrar todos los items antes de abrir
    combo.querySelectorAll('.mp-sust-item').forEach(item => item.style.display = '');
    if (dropdown) dropdown.classList.add('mp-sust-abierto');
}

/* ---- Combo buscable: cerrar con delay (permite el clic en item) ---- */
function _mpBlurSustituto(input) {
    setTimeout(() => {
        const combo = input ? input.closest('.mp-sustituir-combo') : null;
        if (!combo) return;
        const dropdown = combo.querySelector('.mp-sustituir-dropdown');
        if (dropdown) dropdown.classList.remove('mp-sust-abierto');
    }, 220);
}

/* ---- Combo buscable: filtrar mientras el usuario escribe ---- */
function _mpFiltrarSustitutos(input) {
    const combo = input.closest('.mp-sustituir-combo');
    if (!combo) return;
    const dropdown = combo.querySelector('.mp-sustituir-dropdown');
    const texto = input.value.toLowerCase().trim();
    if (dropdown) dropdown.classList.add('mp-sust-abierto');

    combo.querySelectorAll('.mp-sust-item').forEach(item => {
        if (item.classList.contains('mp-sust-sin-cambio')) {
            item.style.display = '';   // siempre visible
            return;
        }
        const nombre = (item.dataset.nombre || '').toLowerCase();
        item.style.display = (texto === '' || nombre.includes(texto)) ? '' : 'none';
    });
}

/* ---- Combo buscable: seleccionar una opción del dropdown ---- */
function _mpSeleccionarSustituto(itemDiv, idComp) {
    const combo = itemDiv.closest('.mp-sustituir-combo');
    if (!combo) return;
    const input    = combo.querySelector('.mp-sustituir-input');
    const hidden   = combo.querySelector('.mp-sustituir-valor');
    const dropdown = combo.querySelector('.mp-sustituir-dropdown');
    const nombreOrig = input ? input.dataset.nombreOrig : '';

    const id     = itemDiv.dataset.id;
    const nombre = itemDiv.dataset.nombre;

    if (input)    input.value  = nombre || '';
    if (hidden)   hidden.value = id     || '';
    if (dropdown) dropdown.classList.remove('mp-sust-abierto');

    // Actualizar draft
    _mpDraft.ingredientes_sustituidos = _mpDraft.ingredientes_sustituidos.filter(s => s.id_componente !== idComp);
    if (id) {
        _mpDraft.ingredientes_sustituidos.push({
            id_componente:   idComp,
            nombre_original: nombreOrig,
            id_nuevo:        parseInt(id),
            nombre_nuevo:    nombre
        });
    }
}

/* ---- Seleccionar grupo de endulzante (clic en la fila/botón principal) ---- */
function _mpSeleccionarGrupoEndulzante(btn) {
    const grupo = btn.dataset.grupo;
    const grupoKey = grupo.replace(/\s+/g, '_').replace(/[^a-zA-Z0-9_]/g, '');
    const row = btn.closest('.mp-endulzante-grupo-row');

    // Si ya está activo este grupo → deseleccionar (toggle off)
    if (row && row.classList.contains('activo')) {
        row.classList.remove('activo');
        btn.classList.remove('activo');
        _mpDraft.endulzante_id = null;
        _mpDraft.endulzante_nombre = '';
        _mpDraft.endulzante_nombre_grupo = '';
        const wrap = document.getElementById('varianteWrap_' + grupoKey);
        if (wrap) wrap.classList.add('mp-end-variante-oculto');
        return;
    }

    // Desactivar todos los grupos
    document.querySelectorAll('.mp-endulzante-grupo-row').forEach(r => r.classList.remove('activo'));
    document.querySelectorAll('.mp-endulzante-grupo-btn').forEach(b => b.classList.remove('activo'));
    document.querySelectorAll('[id^="varianteWrap_"]').forEach(w => w.classList.add('mp-end-variante-oculto'));

    // Activar este grupo
    if (row) row.classList.add('activo');
    btn.classList.add('activo');

    const wrap = document.getElementById('varianteWrap_' + grupoKey);
    const select = document.getElementById('varianteSelect_' + grupoKey);

    if (wrap && select) {
        // Tiene dropdown: mostrar y usar opción seleccionada en el select
        wrap.classList.remove('mp-end-variante-oculto');
        const selOpt = select.options[select.selectedIndex];
        const varNombre = selOpt.dataset.nombre || selOpt.text;
        _mpDraft.endulzante_id = parseInt(selOpt.value);
        _mpDraft.endulzante_nombre = grupo + ' — ' + varNombre;
    } else {
        // Opción única: solo el nombre del grupo
        _mpDraft.endulzante_id = parseInt(btn.dataset.primeraId);
        _mpDraft.endulzante_nombre = grupo;
    }
    _mpDraft.endulzante_nombre_grupo = grupo;
}

/* ---- Cambiar variante dentro de un grupo (dropdown de cantidad/tipo) ---- */
function _mpSeleccionarVarianteEndulzante(select) {
    const selOpt = select.options[select.selectedIndex];
    const varNombre = selOpt.dataset.nombre || selOpt.text;
    const grupo = select.dataset.grupo || '';
    _mpDraft.endulzante_id = parseInt(selOpt.value);
    _mpDraft.endulzante_nombre = grupo ? (grupo + ' — ' + varNombre) : varNombre;
}

/* ---- Toggle extra (agregar/quitar) ---- */
function _mpToggleExtra(btn) {
    const idExt    = parseInt(btn.dataset.id);
    const nombre   = btn.dataset.nombre;
    const precio   = parseFloat(btn.dataset.precio) || 0;
    const yaAgregado = _mpDraft.extras_agregados.some(e => e.id === idExt);

    if (yaAgregado) {
        _mpDraft.extras_agregados = _mpDraft.extras_agregados.filter(e => e.id !== idExt);
        btn.classList.remove('activo');
    } else {
        _mpDraft.extras_agregados.push({ id: idExt, nombre, precio });
        btn.classList.add('activo');
    }

    // Actualizar badge del grupo acordeón al que pertenece este botón
    const grupoEl = btn.closest('.mp-extras-grupo');
    if (grupoEl) {
        const cuerpo = grupoEl.querySelector('.mp-extras-grupo-body');
        const seleccionados = cuerpo ? cuerpo.querySelectorAll('.btn-mp-extra.activo').length : 0;
        const badge = grupoEl.querySelector('.mp-extras-grupo-badge');
        if (badge) {
            badge.textContent = seleccionados;
            badge.style.display = seleccionados > 0 ? '' : 'none';
        }
    }

    _mpActualizarResumenExtras();
}

/* ---- Expandir / colapsar un grupo de extras ---- */
function _mpToggleGrupoExtras(btn) {
    const grupo = btn.closest('.mp-extras-grupo');
    if (!grupo) return;
    const abierto = grupo.classList.toggle('abierto');
    btn.setAttribute('aria-expanded', abierto);
}


/* ---- Recalcular monto de extras en el footer del modal ---- */
function _mpActualizarResumenExtras() {
    const total = _mpDraft.extras_agregados.reduce((s, e) => s + (parseFloat(e.precio) || 0), 0);
    _mpDraft.monto_extras = Math.round(total * 100) / 100;

    if (total > 0) {
        $('#mpMontoExtras').text('C$ ' + total.toFixed(2));
        $('#mpAjustePrecio').show();
    } else {
        $('#mpAjustePrecio').hide();
    }
}

/* ---- Aplicar personalización al item del ticket ---- */
$('#mpBtnGuardar').on('click', function () {
    if (_mpIdx < 0 || !detalleFactura[_mpIdx]) return;

    const item = detalleFactura[_mpIdx];
    item.personalizacion = JSON.parse(JSON.stringify(_mpDraft));

    // Ajustar precio_unitario: precio base + monto de extras
    item.precio_unitario = Math.round(((item.precio_unitario_base || 0) + (_mpDraft.monto_extras || 0)) * 100) / 100;
    item.costo_total_iva = Math.round(item.cantidad * item.precio_unitario * 100) / 100;

    actualizarTablaDetalle();

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPersonalizacion')).hide();

    // Toast de confirmación
    Swal.fire({
        icon: 'success',
        title: 'Personalización aplicada',
        toast: true,
        position: 'top-end',
        timer: 1800,
        showConfirmButton: false
    });
});

/* ============================================================
   BANNER CLUB PITAYA (QUICK ADD) Y SECCIÓN DE PROMOCIONES
   (Fase de maquetación y prototipado visual - listo para reglas)
   ============================================================ */

/* ---- Quick Add Membresía Club Pitaya ---- */
$('#btnQuickAddMembresia').on('click', function () {
    // Si el tipo de cliente no es club, activar Club Pitaya
    if ($('#tipoClienteGeneral').is(':checked')) {
        $('#tipoClienteClub').prop('checked', true);
        cambiarTipoCliente('club');
    }

    // Agregar la línea de Membresía al ticket tal como en el mockup
    const idMembresiaVirtual = -1;
    const existe = detalleFactura.find(d => d.id_presentacion === idMembresiaVirtual);
    if (!existe) {
        detalleFactura.unshift({
            id_presentacion:      idMembresiaVirtual,
            nombre:               'Membresía Club Pitaya',
            unidad:               'N/A',
            precio_unitario_base: 150,
            precio_unitario:      150,
            cantidad:             1,
            costo_total_iva:      150,
            notas:                'Ingresar número de membresía',
            es_personalizable:    false,
            personalizacion:      {}
        });
        actualizarTablaDetalle();

        Swal.fire({
            icon: 'info',
            title: 'Membresía agregada a la orden',
            text: 'Membresía Club Pitaya (C$ 150.00) agregada al ticket para venta rápida.',
            toast: true,
            position: 'top-end',
            timer: 2500,
            showConfirmButton: false
        });
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Membresía ya agregada',
            text: 'La membresía ya se encuentra en el ticket actual.',
            toast: true,
            position: 'top-end',
            timer: 2000,
            showConfirmButton: false
        });
    }
});

/* ---- Demostración de Promociones Recomendadas / Aplicables ---- */
function demostrarPromocion(nombrePromo) {
    Swal.fire({
        icon: 'info',
        title: 'Promoción: ' + nombrePromo,
        text: 'Módulo de promociones en fase de prototipo visual. Las reglas automáticas de descuento y canje se aplicarán en la siguiente etapa.',
        confirmButtonText: 'Entendido',
        confirmButtonColor: '#0E544C'
    });
}

// Clic en tarjetas recomendadas interactivas
$(document).on('click', '.promo-card:not(.promo-card-neutral)', function () {
    const titulo = $(this).find('.promo-title').text();
    demostrarPromocion(titulo);
});

