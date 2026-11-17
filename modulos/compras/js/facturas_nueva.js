/* ============================================================
   facturas_nueva.js — Formulario Nueva Factura de Compra
   ============================================================ */

let lineasDetalle = [];   // { id_presentacion, nombre, presentacion, cantidad, costo_con_iva }
let busquedaTimer = null;

$(document).ready(function () {
    // Fecha de hoy por defecto
    const hoy = new Date();
    const yyyy = hoy.getFullYear();
    const mm   = String(hoy.getMonth() + 1).padStart(2, '0');
    const dd   = String(hoy.getDate()).padStart(2, '0');
    $('#fecha').val(`${yyyy}-${mm}-${dd}`);

    cargarProveedores();
    cargarProductos();

    $('#buscadorProducto').on('input', function () {
        clearTimeout(busquedaTimer);
        busquedaTimer = setTimeout(() => cargarProductos($(this).val()), 300);
    });
});

/* ---- Proveedores ---- */
function cargarProveedores() {
    $.get('ajax/facturas_get_proveedores.php', function (res) {
        if (!res.success) return;
        const sel = $('#idProveedor');
        sel.find('option:not(:first)').remove();
        res.data.forEach(p => {
            const ruc = p.ruc_nit ? ` (${escH(p.ruc_nit)})` : '';
            sel.append(`<option value="${p.id}">${escH(p.nombre)}${ruc}</option>`);
        });
    }, 'json');
}

/* ---- Productos ---- */
function cargarProductos(q) {
    q = (q || '').trim();
    $.get('ajax/facturas_get_productos.php', { q }, function (res) {
        const lista = $('#listaProductos');
        lista.empty();

        if (!res.success) {
            lista.html('<p class="no-productos-msg">Error al cargar productos.</p>');
            return;
        }

        if (!res.productos || res.productos.length === 0) {
            lista.html('<p class="no-productos-msg"><i class="bi bi-search me-1"></i>Sin resultados.</p>');
            return;
        }

        res.productos.forEach(p => {
            lista.append(`
                <div class="producto-card-elegible">
                    <div class="prod-info">
                        <span class="prod-nombre">${escH(p.nombre_presentacion)}</span>
                        ${p.presentacion ? `<span class="prod-presentacion">${escH(p.presentacion)}</span>` : ''}
                    </div>
                    <button class="btn-agregar-prod" onclick="agregarProducto(${p.id}, '${escHJs(p.nombre_presentacion)}', '${escHJs(p.presentacion || '')}', ${parseFloat(p.costo_unitario_ref || 0)})">
                        <i class="bi bi-plus"></i> Agregar
                    </button>
                </div>`);
        });
    }, 'json');
}

/* ---- Agregar línea al detalle ---- */
function agregarProducto(id, nombre, presentacion, costoRef) {
    // Si ya existe, solo enfoca la cantidad
    const existe = lineasDetalle.findIndex(l => l.id_presentacion === id);
    if (existe >= 0) {
        $(`#qty-${id}`).focus().select();
        Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'El producto ya fue agregado.', showConfirmButton: false, timer: 1800 });
        return;
    }

    // Costo con IVA = costo_ref * 1.15, redondeado a 2 decimales
    const costoIva = costoRef > 0 ? parseFloat((costoRef * 1.15).toFixed(2)) : 0;

    lineasDetalle.push({ id_presentacion: id, nombre, presentacion, cantidad: 1, costo_con_iva: costoIva });
    renderizarDetalle();
}

/* ---- Render tabla detalle ---- */
function renderizarDetalle() {
    const tbody = $('#tablaDetalleBody');
    tbody.empty();

    if (lineasDetalle.length === 0) {
        tbody.html('<tr><td colspan="6" class="empty-detalle"><i class="bi bi-cart-x me-1"></i>Agregue productos desde el panel derecho.</td></tr>');
        actualizarTotal();
        return;
    }

    lineasDetalle.forEach((l, idx) => {
        const subtotal = (l.cantidad * l.costo_con_iva).toFixed(2);
        tbody.append(`
            <tr id="fila-${l.id_presentacion}">
                <td>${idx + 1}</td>
                <td>
                    <strong>${escH(l.nombre)}</strong>
                    ${l.presentacion ? `<small class="d-block text-muted">${escH(l.presentacion)}</small>` : ''}
                </td>
                <td style="width:110px;">
                    <input type="number" id="qty-${l.id_presentacion}" class="input-tabla" min="0.01" step="0.01"
                           value="${l.cantidad}"
                           oninput="actualizarCantidad(${l.id_presentacion}, this.value)">
                </td>
                <td style="width:130px;">
                    <input type="number" id="civa-${l.id_presentacion}" class="input-tabla" min="0" step="0.01"
                           value="${l.costo_con_iva}"
                           oninput="actualizarCosto(${l.id_presentacion}, this.value)">
                </td>
                <td class="costo-unitario-cell" id="subtotal-${l.id_presentacion}">C$ ${parseFloat(subtotal).toLocaleString('es-NI', { minimumFractionDigits: 2 })}</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger" onclick="eliminarLinea(${l.id_presentacion})" title="Quitar">
                        <i class="bi bi-trash3"></i>
                    </button>
                </td>
            </tr>`);
    });

    actualizarTotal();
}

function actualizarCantidad(id, val) {
    const idx = lineasDetalle.findIndex(l => l.id_presentacion === id);
    if (idx < 0) return;
    lineasDetalle[idx].cantidad = parseFloat(val) || 0;
    const sub = (lineasDetalle[idx].cantidad * lineasDetalle[idx].costo_con_iva).toFixed(2);
    $(`#subtotal-${id}`).text('C$ ' + parseFloat(sub).toLocaleString('es-NI', { minimumFractionDigits: 2 }));
    actualizarTotal();
}

function actualizarCosto(id, val) {
    const idx = lineasDetalle.findIndex(l => l.id_presentacion === id);
    if (idx < 0) return;
    lineasDetalle[idx].costo_con_iva = parseFloat(val) || 0;
    const sub = (lineasDetalle[idx].cantidad * lineasDetalle[idx].costo_con_iva).toFixed(2);
    $(`#subtotal-${id}`).text('C$ ' + parseFloat(sub).toLocaleString('es-NI', { minimumFractionDigits: 2 }));
    actualizarTotal();
}

function eliminarLinea(id) {
    lineasDetalle = lineasDetalle.filter(l => l.id_presentacion !== id);
    renderizarDetalle();
}

function actualizarTotal() {
    const total = lineasDetalle.reduce((acc, l) => acc + (l.cantidad * l.costo_con_iva), 0);
    $('#totalFactura').text('C$ ' + total.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
}

/* ---- Guardar ---- */
function guardarFactura() {
    const nf   = $('#numeroFactura').val().trim();
    const fecha = $('#fecha').val().trim();
    const prov  = parseInt($('#idProveedor').val(), 10);
    const notas = $('#notas').val().trim();

    if (!nf)        { Swal.fire('Atención', 'El número de factura es requerido.', 'warning'); return; }
    if (!fecha)     { Swal.fire('Atención', 'La fecha es requerida.', 'warning'); return; }
    if (!prov)      { Swal.fire('Atención', 'Debe seleccionar un proveedor.', 'warning'); return; }
    if (lineasDetalle.length === 0) { Swal.fire('Atención', 'Debe agregar al menos un producto.', 'warning'); return; }

    const hayInvalido = lineasDetalle.some(l => l.cantidad <= 0 || l.costo_con_iva < 0);
    if (hayInvalido) { Swal.fire('Atención', 'Revise las cantidades y costos — no pueden ser negativos o cero.', 'warning'); return; }

    const payload = {
        numero_factura: nf,
        fecha,
        id_proveedor: prov,
        notas,
        detalle: lineasDetalle.map(l => ({
            id_presentacion: l.id_presentacion,
            cantidad: l.cantidad,
            costo_con_iva: l.costo_con_iva,
        })),
    };

    const btnGuardar = $('#btnGuardar');
    btnGuardar.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando…');

    $.ajax({
        url: 'ajax/facturas_guardar.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: '¡Factura guardada!',
                    text: res.message,
                    confirmButtonColor: '#0E544C',
                }).then(() => {
                    window.location.href = 'facturas_historial.php';
                });
            } else {
                Swal.fire('Error', res.message || 'No se pudo guardar.', 'error');
                btnGuardar.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Guardar Factura');
            }
        },
        error: function () {
            Swal.fire('Error', 'Error de conexión al guardar.', 'error');
            btnGuardar.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Guardar Factura');
        }
    });
}

/* ---- Helpers ---- */
function escH(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escHJs(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');
}
