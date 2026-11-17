/* ============================================================
   mermas_nueva.js — Formulario Nueva Merma POS
   ============================================================ */

let lineasDetalle = [];   // { id_presentacion, nombre, presentacion, cantidad }
let busquedaTimer = null;

$(document).ready(function () {
    cargarTipos();
    cargarProductos();

    $('#buscadorProducto').on('input', function () {
        clearTimeout(busquedaTimer);
        busquedaTimer = setTimeout(() => cargarProductos($(this).val()), 300);
    });

    $('#idTipoIncidencia').on('change', function () {
        mostrarInfoTipo($(this).find(':selected'));
    });
});

/* ---- Tipos de incidencia ---- */
function cargarTipos() {
    $.get('ajax/mermas_get_tipos.php', function (res) {
        if (!res.success) return;
        const sel = $('#idTipoIncidencia');
        sel.find('option:not(:first)').remove();
        res.tipos.forEach(t => {
            const cobro = t.es_cobro == 1 ? ' ⚠ Cobro' : '';
            sel.append(`<option value="${t.id}"
                data-categoria="${escH(t.categoria_merma)}"
                data-descripcion="${escH(t.descripcion || '')}"
                data-cobro="${t.es_cobro}"
                data-aprobacion="${t.necesita_aprobacion}"
                >${escH(t.nombre)}${cobro}</option>`);
        });
    }, 'json');
}

function mostrarInfoTipo(opt) {
    const info = $('#infoTipo');
    if (!opt.val()) { info.hide(); return; }
    const desc = opt.data('descripcion') || '';
    const cat = opt.data('categoria') || '';
    const cobro = opt.data('cobro') == 1;
    const aprov = opt.data('aprobacion') == 1;

    let html = `<strong>${escH(cat)}</strong>`;
    if (desc) html += ` — ${escH(desc)}`;
    if (cobro) html += ' &nbsp;<span class="badge-cobro">Cobro</span>';
    if (aprov) html += ' &nbsp;<span style="font-size:.7rem;color:#6b21a8;">Requiere aprobación</span>';

    info.removeClass('es-cobro').toggleClass('es-cobro', cobro);
    info.html(html).show();
}

/* ---- Productos ---- */
function cargarProductos(q) {
    q = (q || '').trim();
    $.get('ajax/mermas_get_productos.php', { q }, function (res) {
        const lista = $('#listaProductos');
        lista.empty();
        if (!res.success) { lista.html('<p class="no-productos-msg">Error al cargar productos.</p>'); return; }
        if (!res.productos || !res.productos.length) {
            lista.html('<p class="no-productos-msg"><i class="bi bi-search me-1"></i>Sin resultados.</p>');
            return;
        }
        res.productos.forEach(p => {
            lista.append(`
                <div class="producto-card-elegible">
                    <div class="prod-info">
                        <span class="prod-nombre">${escH(p.Nombre)}</span>
                        ${p.presentacion ? `<span class="prod-presentacion">${escH(p.presentacion)}</span>` : ''}
                    </div>
                    <button class="btn-agregar-prod"
                            onclick="agregarProducto(${p.id}, '${escHJs(p.Nombre)}', '${escHJs(p.presentacion || '')}')">
                        <i class="bi bi-plus"></i> Agregar
                    </button>
                </div>`);
        });
    }, 'json');
}

/* ---- Agregar línea ---- */
function agregarProducto(id, nombre, presentacion) {
    const existe = lineasDetalle.findIndex(l => l.id_presentacion === id);
    if (existe >= 0) {
        $(`#qty-${id}`).focus().select();
        Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'El producto ya fue agregado.', showConfirmButton: false, timer: 1800 });
        return;
    }
    lineasDetalle.push({ id_presentacion: id, nombre, presentacion, cantidad: 1 });
    renderizarDetalle();
}

/* ---- Render tabla detalle ---- */
function renderizarDetalle() {
    const tbody = $('#tablaDetalleBody');
    tbody.empty();
    if (!lineasDetalle.length) {
        tbody.html('<tr><td colspan="4" class="empty-detalle"><i class="bi bi-box-seam me-1"></i>Busque y agregue productos desde el panel derecho.</td></tr>');
        return;
    }
    lineasDetalle.forEach((l, idx) => {
        tbody.append(`
            <tr id="fila-${l.id_presentacion}">
                <td>${idx + 1}</td>
                <td>
                    <strong>${escH(l.nombre)}</strong>
                    ${l.presentacion ? `<small class="d-block text-muted">${escH(l.presentacion)}</small>` : ''}
                </td>
                <td style="width:120px;">
                    <input type="number" id="qty-${l.id_presentacion}" class="input-tabla"
                           min="0.01" step="0.01" value="${l.cantidad}"
                           oninput="actualizarCantidad(${l.id_presentacion}, this.value)">
                </td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger" onclick="eliminarLinea(${l.id_presentacion})" title="Quitar">
                        <i class="bi bi-trash3"></i>
                    </button>
                </td>
            </tr>`);
    });
}

function actualizarCantidad(id, val) {
    const idx = lineasDetalle.findIndex(l => l.id_presentacion === id);
    if (idx < 0) return;
    lineasDetalle[idx].cantidad = parseFloat(val) || 0;
}

function eliminarLinea(id) {
    lineasDetalle = lineasDetalle.filter(l => l.id_presentacion !== id);
    renderizarDetalle();
}

/* ---- Guardar ---- */
function guardarMerma() {
    const idTipo = parseInt($('#idTipoIncidencia').val(), 10);
    const observacion = $('#observacion').val().trim();

    if (!idTipo) { Swal.fire('Atención', 'Debe seleccionar un tipo de incidencia.', 'warning'); return; }
    if (!lineasDetalle.length) { Swal.fire('Atención', 'Debe agregar al menos un producto.', 'warning'); return; }

    const hayInvalido = lineasDetalle.some(l => l.cantidad <= 0);
    if (hayInvalido) { Swal.fire('Atención', 'Todas las cantidades deben ser mayores a 0.', 'warning'); return; }

    const payload = {
        id_tipo_incidencia: idTipo,
        observacion,
        lineas: lineasDetalle.map(l => ({
            id_producto_presentacion: l.id_presentacion,
            cantidad: l.cantidad
        }))
    };

    const btn = $('#btnGuardar');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Guardando…');

    $.ajax({
        url: 'ajax/mermas_guardar.php',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(payload),
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: '¡Merma registrada!',
                    text: res.message,
                    confirmButtonColor: '#0E544C',
                }).then(() => { window.location.href = 'mermas_historial.php'; });
            } else {
                Swal.fire('Error', res.message || 'No se pudo guardar.', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Guardar Merma');
            }
        },
        error: function () {
            Swal.fire('Error', 'Error de conexión al guardar.', 'error');
            btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Guardar Merma');
        }
    });
}

/* ---- Helpers ---- */
function escH(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escHJs(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}
