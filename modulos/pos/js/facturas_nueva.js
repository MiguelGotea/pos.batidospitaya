/* ===================================================
   facturas_nueva.js — Nueva Factura POS
   =================================================== */

// Estado local
let detalleFactura = []; // array de {id_presentacion, nombre, unidad, cantidad, costo_total_iva}
let todosProductos = []; // cache de productos elegibles
let clienteActual = null; // objeto del socio Club Pitaya seleccionado
let paginaHistorialActual = 1;
let membresiaHistorialActual = '';

$(document).ready(function () {
    cargarProductosElegibles();
    setupClienteEvents();

    // Búsqueda en panel derecho con debounce
    let timerBuscar;
    $('#buscarProducto').on('input', function () {
        clearTimeout(timerBuscar);
        timerBuscar = setTimeout(() => filtrarProductos($(this).val().trim()), 280);
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

    // Buscar membresía al dar Enter
    $('#membresiaCliente').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscarClienteClub(false);
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

    // Botón Leer por QR
    $('#btnLeerQR').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            title: 'Lector QR / Código',
            text: 'Escanee la tarjeta del socio o ingrese el número de membresía:',
            input: 'text',
            inputPlaceholder: 'Número de membresía...',
            showCancelButton: true,
            confirmButtonText: 'Buscar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#0E544C'
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                $('#membresiaCliente').val(result.value.trim());
                buscarClienteClub(false);
            }
        });
    });
}

function cambiarTipoCliente(tipo) {
    if (tipo === 'general') {
        $('#colMembresia').hide();
        $('#colIndicadoresPuntos').hide();
        $('#colNombreCliente').removeClass('col-md-5').addClass('col-md-12');
        $('#nombreCliente').prop('readonly', false).val('').attr('placeholder', 'Escriba nombre del cliente casual...').css('background-color', '#fff');
        $('#idClienteClub').val('');
        $('#clienteFeedback').html('');
        clienteActual = null;
        actualizarPuntosFactura();
    } else {
        $('#colMembresia').show();
        $('#colIndicadoresPuntos').show();
        $('#colNombreCliente').removeClass('col-md-12').addClass('col-md-5');
        $('#nombreCliente').prop('readonly', true).val(clienteActual ? clienteActual.nombre_completo : '').attr('placeholder', 'Nombre del cliente...').css('background-color', '#f8fafc');
        if (clienteActual) {
            $('#idClienteClub').val(clienteActual.id_clienteclub);
            $('#txtPuntosAnteriores').text(clienteActual.puntos_anteriores);
        } else {
            $('#idClienteClub').val('');
            $('#txtPuntosAnteriores').text('0');
        }
        actualizarPuntosFactura();
    }
}

/* ---- Búsqueda de socio Club Pitaya ---- */
function buscarClienteClub(abrirModalTrasBuscar = false) {
    const membresia = $('#membresiaCliente').val().trim();
    if (!membresia) {
        Swal.fire({
            title: 'Membresía requerida',
            text: 'Por favor ingrese el número de membresía del socio.',
            icon: 'warning',
            confirmButtonColor: '#0E544C'
        });
        $('#membresiaCliente').focus();
        return;
    }

    const $btn = $('#btnBuscarMembresia');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $('#clienteFeedback').html('<span class="text-muted"><i class="bi bi-hourglass-split"></i> Buscando socio…</span>');

    $.post('../../clientes/ajax/cliente_club_buscar.php', { membresia: membresia }, function (res) {
        $btn.prop('disabled', false).html('<i class="bi bi-search"></i>');
        if (res.success && res.cliente) {
            clienteActual = res.cliente;
            $('#nombreCliente').val(clienteActual.nombre_completo);
            $('#idClienteClub').val(clienteActual.id_clienteclub);
            $('#txtPuntosAnteriores').text(clienteActual.puntos_anteriores);
            $('#clienteFeedback').html(`<span class="text-success fw-semibold"><i class="bi bi-check-circle"></i> Socio verificado (${clienteActual.nombre_sucursal || 'Central'})</span>`);
            actualizarPuntosFactura();

            if (abrirModalTrasBuscar) {
                abrirModalHistorial(clienteActual.membresia, 1);
            }
        } else {
            clienteActual = null;
            $('#nombreCliente').val('');
            $('#idClienteClub').val('');
            $('#txtPuntosAnteriores').text('0');
            $('#clienteFeedback').html(`<span class="text-danger"><i class="bi bi-x-circle"></i> ${res.message || 'No encontrado'}</span>`);
            actualizarPuntosFactura();
            Swal.fire({
                title: 'No encontrado',
                text: res.message || 'La membresía ingresada no existe.',
                icon: 'error',
                confirmButtonColor: '#0E544C'
            });
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-search"></i>');
        $('#clienteFeedback').html('<span class="text-danger">Error de conexión al buscar socio.</span>');
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

    $.post('../../clientes/ajax/cliente_historial_productos.php', {
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
                        <i class="bi bi-inbox me-1"></i> Este socio no tiene compras registradas en el historial.
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
            html += `
            <tr ${trClass}>
                <td><strong>${escHtml(row.sucursal)}</strong></td>
                <td><span class="text-muted">${escHtml(row.fecha_hora)}</span></td>
                <td><span class="fw-semibold text-dark">${escHtml(row.producto_medida)}</span></td>
                <td class="text-center"><span class="badge bg-light text-dark border">${row.cantidad}</span></td>
                <td><small class="text-muted">${escHtml(row.promocion)}</small></td>
                <td class="text-end fw-bold text-success">${row.puntos_acumulados.toFixed(2)}</td>
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


/* ---- Cargar productos elegibles (panel derecho) ---- */
function cargarProductosElegibles() {
    $('#listaProductosElegibles').html(`
        <p class="no-productos-msg">
            <span class="spinner-border spinner-border-sm me-1"></span> Cargando…
        </p>`);

    $.get('ajax/facturas_get_productos_elegibles.php', function (res) {
        if (!res.success || res.data.length === 0) {
            $('#listaProductosElegibles').html(`
                <p class="no-productos-msg">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    No hay productos vendibles activos en este momento.
                </p>`);
            return;
        }
        todosProductos = res.data;
        renderizarListaProductos(todosProductos);
    }, 'json').fail(function () {
        $('#listaProductosElegibles').html(`<p class="no-productos-msg text-danger">Error al cargar productos.</p>`);
    });
}

/* ---- Renderizar lista de productos elegibles ---- */
function renderizarListaProductos(lista) {
    if (!lista || lista.length === 0) {
        $('#listaProductosElegibles').html(`<p class="no-productos-msg">Sin resultados.</p>`);
        return;
    }

    let html = '';
    lista.forEach(p => {
        const precioUnit = parseFloat(p.precio_venta || 0);
        const precioFmt = precioUnit > 0 ? `C$ ${precioUnit.toFixed(2)}` : 'Sin precio';
        const categoriaTxt = `${escHtml(p.grupo || 'Otros')}${p.subgrupo ? ' · ' + escHtml(p.subgrupo) : ''}`;

        html += `
        <div class="producto-card-elegible" data-id="${p.id}">
            <div class="prod-info">
                <span class="prod-nombre">${escHtml(p.Nombre)}</span>
                <span class="prod-presentacion">${categoriaTxt} · <strong style="color:#0E544C;">${precioFmt}</strong></span>
            </div>
            <button class="btn-agregar-prod" onclick="agregarProducto(${p.id}, '${escAttr(p.Nombre)}', '${escAttr(p.nombre_unidad || '')}', ${precioUnit})">
                <i class="bi bi-plus-lg"></i> Agregar
            </button>
        </div>`;
    });
    $('#listaProductosElegibles').html(html);
}

/* ---- Filtrar localmente ---- */
function filtrarProductos(q) {
    if (q === '') {
        renderizarListaProductos(todosProductos);
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
    renderizarListaProductos(filtrados);
}

/* ---- Agregar producto al detalle ---- */
function agregarProducto(id, nombre, unidad, precio = 0) {
    // Verificar si ya está en el detalle
    const existe = detalleFactura.find(d => d.id_presentacion === id);
    if (existe) {
        // Animar la fila existente para indicar que ya está
        const fila = $(`#fila-${id}`);
        fila.addClass('table-warning');
        setTimeout(() => fila.removeClass('table-warning'), 800);
        fila.find('.input-cantidad').focus();
        return;
    }

    const precioNum = parseFloat(precio) || 0;
    detalleFactura.push({
        id_presentacion: id,
        nombre:          nombre,
        unidad:          unidad,
        cantidad:        1,
        costo_total_iva: precioNum
    });

    actualizarTablaDetalle();
}

/* ---- Renderizar tabla de detalle (izquierda) ---- */
function actualizarTablaDetalle() {
    $('#contadorProductos').text(`${detalleFactura.length} ítem(s)`);

    if (detalleFactura.length === 0) {
        $('#tablaDetalleBody').html(`
            <tr id="filaVacia">
                <td colspan="5" class="empty-detalle">
                    <i class="bi bi-box-seam me-2"></i>
                    Selecciona productos desde el panel derecho
                </td>
            </tr>`);
        actualizarTotal();
        return;
    }

    let html = '';
    detalleFactura.forEach((item, idx) => {
        const costoUnit = (item.cantidad > 0)
            ? (item.costo_total_iva / item.cantidad).toFixed(4)
            : '0.0000';

        html += `
        <tr id="fila-${item.id_presentacion}" data-idx="${idx}">
            <td>
                <strong style="font-size:.875rem;">${escHtml(item.nombre)}</strong>
                <small class="d-block text-muted">${escHtml(item.unidad)}</small>
            </td>
            <td class="text-end" style="width:140px;">
                <div class="qty-control">
                    <button type="button" class="qty-btn" onclick="modificarCantidad(${idx}, -1)" title="Disminuir">
                        <i class="bi bi-dash-lg"></i>
                    </button>
                    <input type="number" class="qty-input input-cantidad"
                           value="${item.cantidad}" min="0.01" step="0.01"
                           data-idx="${idx}"
                           oninput="actualizarItem(${idx}, 'cantidad', this.value)">
                    <button type="button" class="qty-btn" onclick="modificarCantidad(${idx}, 1)" title="Aumentar">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
            </td>
            <td class="text-end" style="min-width:130px;">
                <input type="number" class="input-tabla input-costo"
                       value="${item.costo_total_iva}" min="0" step="0.01"
                       data-idx="${idx}"
                       onchange="actualizarItem(${idx}, 'costo_total_iva', this.value)">
            </td>
            <td class="costo-unitario-cell" id="unitario-${item.id_presentacion}">
                C$ ${parseFloat(costoUnit).toLocaleString('es-NI', {minimumFractionDigits: 4})}
            </td>
            <td class="text-center">
                <button class="btn-eliminar-fila" onclick="eliminarItem(${idx})" title="Quitar">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </td>
        </tr>`;
    });

    $('#tablaDetalleBody').html(html);
    actualizarTotal();
}

/* ---- Actualizar valor de un campo en el detalle ---- */
function actualizarItem(idx, campo, valor) {
    detalleFactura[idx][campo] = parseFloat(valor) || 0;

    // Recalcular costo unitario en vivo
    const item = detalleFactura[idx];
    const costoUnit = (item.cantidad > 0)
        ? (item.costo_total_iva / item.cantidad)
        : 0;

    $(`#unitario-${item.id_presentacion}`).text(
        'C$ ' + costoUnit.toLocaleString('es-NI', { minimumFractionDigits: 4, maximumFractionDigits: 4 })
    );

    actualizarTotal();
}

/* ---- Eliminar ítem del detalle ---- */
function eliminarItem(idx) {
    detalleFactura.splice(idx, 1);
    actualizarTablaDetalle();
}

/* ---- Modificar cantidad con botones +/- ---- */
function modificarCantidad(idx, delta) {
    const item = detalleFactura[idx];
    let nuevaCant = (parseFloat(item.cantidad) || 0) + delta;
    
    if (nuevaCant < 0) nuevaCant = 0;
    
    // Si queremos que el mínimo sea 1 si se intenta bajar de 1, se puede ajustar. 
    // Pero permitiremos 0 si el usuario lo desea bajar todo.
    
    actualizarItem(idx, 'cantidad', nuevaCant);
    
    // Forzamos actualización de la tabla para refrescar el input visualmente
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
        alertError('Debe buscar un socio válido con membresía Club Pitaya, o cambiar a Cliente General.');
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

    $.post('ajax/facturas_guardar.php', {
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
                // Limpiar detalle para la siguiente venta
                detalleFactura = [];
                actualizarTablaDetalle();
                if (isClub && clienteActual) {
                    // Refrescar datos y puntos del socio en pantalla
                    buscarClienteClub(false);
                } else {
                    $('#nombreCliente').val('');
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
