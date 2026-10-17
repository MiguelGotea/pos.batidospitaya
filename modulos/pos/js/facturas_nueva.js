/* ===================================================
   facturas_nueva.js — Nueva Factura POS
   =================================================== */

// Estado local
let detalleFactura = []; // array de {id_presentacion, nombre, unidad, cantidad, costo_total_iva}
let todosProductos = []; // cache de productos elegibles
let clienteActual = null; // objeto del miembro Club Pitaya seleccionado
let paginaHistorialActual = 1;
let membresiaHistorialActual = '';
let grupoActivo = null;   // id del grupo activo en tabs del catálogo
let _grupos = null;       // Map: id_grupo → { nombre, subgrupos: Map }

$(document).ready(function () {
    cargarProductosElegibles();
    setupClienteEvents();
    cambiarTipoCliente('general');

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

    // Puntos estimados: 1 punto por cada unidad de producto vendible en el ticket
    const puntosEstaFactura = detalleFactura.reduce((s, d) => s + (parseFloat(d.cantidad) || 0), 0);
    const puntosAnteriores = parseFloat(clienteActual.puntos_anteriores) || 0;
    const totalPuntos = Math.round((puntosAnteriores + puntosEstaFactura) * 100) / 100;

    $('#txtPuntosEstaFactura').text(`+${puntosEstaFactura}`);
    $('#txtTotalPuntos').text(totalPuntos.toFixed(2));
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
            html += `<div class="producto-card-pos" tabindex="0" role="button"
                          onclick="agregarProducto(${p.id},'${escAttr(p.Nombre)}','${escAttr(p.nombre_unidad||'')}',${precio})"
                          onkeypress="if(event.key==='Enter')agregarProducto(${p.id},'${escAttr(p.Nombre)}','${escAttr(p.nombre_unidad||'')}',${precio})"
                     >${escHtml(p.Nombre)}</div>`;
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
        html += `<div class="producto-card-pos" tabindex="0" role="button"
                      onclick="agregarProducto(${p.id},'${escAttr(p.Nombre)}','${escAttr(p.nombre_unidad||'')}',${precio})"
                 >${escHtml(p.Nombre)}</div>`;
    });
    html += '</div>';
    $('#catalogoPanel').html(html);
}

/* ---- Agregar producto al detalle ---- */
function agregarProducto(id, nombre, unidad, precio = 0) {
    const precioNum = parseFloat(precio) || 0;
    const existe = detalleFactura.find(d => d.id_presentacion === id);

    if (existe) {
        existe.cantidad += 1;
        existe.costo_total_iva = Math.round(existe.cantidad * existe.precio_unitario * 100) / 100;
        actualizarTablaDetalle();

        const fila = $(`#fila-${id}`);
        fila.addClass('table-warning');
        setTimeout(() => fila.removeClass('table-warning'), 600);
        return;
    }

    detalleFactura.push({
        id_presentacion: id,
        nombre:          nombre,
        unidad:          unidad,
        precio_unitario: precioNum,
        cantidad:        1,
        costo_total_iva: precioNum,
        notas:           ''
    });

    actualizarTablaDetalle();
}

/* ---- Renderizar tabla de detalle (columna derecha) ---- */
function actualizarTablaDetalle() {
    $('#contadorProductos').text(`${detalleFactura.length} ítem(s)`);

    if (detalleFactura.length === 0) {
        $('#tablaDetalleBody').html(`
            <tr id="filaVacia">
                <td colspan="4" class="empty-detalle">
                    <i class="bi bi-box-seam me-2"></i>
                    Selecciona productos desde el catálogo
                </td>
            </tr>`);
        actualizarTotal();
        return;
    }

    let html = '';
    detalleFactura.forEach((item, idx) => {
        const precioTotalLinea = (item.costo_total_iva || 0);
        const precioUnit = (item.precio_unitario || 0);

        html += `
        <tr id="fila-${item.id_presentacion}" class="fila-producto-principal" data-idx="${idx}">
            <td>
                <strong style="font-size:.88rem; color:#1e293b;">${escHtml(item.nombre)}</strong>
                ${item.unidad ? `<small class="d-block text-muted" style="font-size:.76rem;">${escHtml(item.unidad)}</small>` : ''}
            </td>
            <td class="text-center" style="width:125px;">
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
                <strong class="text-dark" style="font-size:.92rem; font-variant-numeric: tabular-nums;">
                    C$ ${precioTotalLinea.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                </strong>
                ${item.cantidad > 1 ? `<small class="d-block text-muted" style="font-size:.72rem;">(C$ ${precioUnit.toFixed(2)} c/u)</small>` : ''}
            </td>
            <td class="text-center" style="width:40px;">
                <button class="btn-eliminar-fila" onclick="eliminarItem(${idx})" title="Quitar">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </td>
        </tr>
        <tr class="fila-producto-nota" data-idx="${idx}">
            <td colspan="4">
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
        detalleFactura[idx].costo_total_iva = Math.round(cant * (detalleFactura[idx].precio_unitario || 0) * 100) / 100;
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
    item.costo_total_iva = Math.round(nueva * (item.precio_unitario || 0) * 100) / 100;
    actualizarTablaDetalle();
}

/* ---- Actualizar total mostrado ---- */
function actualizarTotal() {
    const total = detalleFactura.reduce((s, d) => s + (parseFloat(d.costo_total_iva) || 0), 0);
    $('#totalFacturaMonto').text(
        'C$ ' + total.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    );
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

    // Validar montos
    for (let i = 0; i < detalleFactura.length; i++) {
        const d = detalleFactura[i];
        if (d.cantidad <= 0) {
            alertError(`La cantidad debe ser mayor a 0 en "${d.nombre}".`); return;
        }
    }

    const $btn = $('#btnGuardar');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Guardando…');

    $.post('/modulos/pos/ajax/facturas_guardar.php', {
        tipo_cliente:    isClub ? 'club' : 'general',
        id_cliente_club: isClub ? idClienteClub : null,
        nombre_cliente:  isClub ? (clienteActual ? clienteActual.nombre_completo : '') : nombreCliente,
        numero_factura:  numero,
        fecha:           fecha,
        detalle:         JSON.stringify(detalleFactura)
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
