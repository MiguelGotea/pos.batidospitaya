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
let _promoDescuentoGlobal = 0; // descuento a nivel de factura aplicado por promociones (pct_factura/monto_factura)
let _rowKeyCounter = 0;       // contador incremental para clave única de fila en el DOM

// Estado de Pagos y Propina
let pagos = [];
let tipoCambioUSD = (typeof TIPO_CAMBIO_USD_INICIAL !== 'undefined' && TIPO_CAMBIO_USD_INICIAL > 0)
    ? TIPO_CAMBIO_USD_INICIAL
    : 0; // Tasa USD/NIO inyectada por PHP desde pos_ventas_tipo_cambio           // array de { metodo: 'efectivo'|'pos'|'transferencia', monto: float, recibido: float, cambio: float, referencia: string }
let conPropina = false;   // estado del switch de propina
const PORCENTAJE_PROPINA = 2; // 2% corporativo

$(document).ready(function () {
    cargarProductosElegibles();
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
        $('#txtPuntosEstaFactura').text('+0').removeClass('text-danger text-success');
        $('#txtTotalPuntos').text('0').removeClass('text-danger');
        $('#btnGuardar').prop('disabled', false).removeClass('btn-bloqueado-puntos');
        $('#alertaPuntosSaldo').hide();
        return;
    }

    // Calcular puntos ganados y canjeados con las reglas reales de BD
    let puntosGanados   = 0;
    let puntosCanjeados = 0;

    detalleFactura.forEach(function (d) {
        const cant = parseFloat(d.cantidad) || 0;

        if (d.es_canjeado) {
            // Ítems canjeados no acumulan; solo restan puntos según regla
            const ptsCanje = (d.puntos_canje != null) ? parseFloat(d.puntos_canje) : 0;
            puntosCanjeados += ptsCanje * cant;
        } else {
            // Unidades libres (excluyendo las usadas como condición de promo)
            const qtyCond  = parseFloat(d.qty_condicion || 0);
            const cantAcum = Math.max(0, cant - qtyCond); // 0 si todas son condición
            if (cantAcum > 0 && d.puntos_acumulacion != null) {
                puntosGanados += parseFloat(d.puntos_acumulacion) * cantAcum;
            }
        }
    });

    const puntosEstaFactura = Math.round((puntosGanados - puntosCanjeados) * 100) / 100;
    const puntosAnteriores  = parseFloat(clienteActual.puntos_anteriores) || 0;
    const totalPuntos       = Math.round((puntosAnteriores + puntosEstaFactura) * 100) / 100;

    // Formato: +3.00 ó -7.00
    const signo = puntosEstaFactura >= 0 ? '+' : '';
    $('#txtPuntosEstaFactura')
        .text(`${signo}${puntosEstaFactura.toFixed(2)}`)
        .toggleClass('text-danger', puntosEstaFactura < 0)
        .toggleClass('text-success', puntosEstaFactura >= 0);

    $('#txtTotalPuntos')
        .text(totalPuntos.toFixed(2))
        .toggleClass('text-danger', totalPuntos < 0);

    // Bloquear Guardar si el saldo total resultante es negativo
    const saldoInsuficiente = totalPuntos < 0;
    $('#btnGuardar')
        .prop('disabled', saldoInsuficiente)
        .toggleClass('btn-bloqueado-puntos', saldoInsuficiente);

    if (saldoInsuficiente) {
        $('#alertaPuntosSaldo')
            .show()
            .text(`Puntos insuficientes: el socio tiene ${puntosAnteriores.toFixed(2)} pts y esta factura requiere ${puntosCanjeados.toFixed(2)} pts de canje.`);
    } else {
        $('#alertaPuntosSaldo').hide();
    }
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
/*
 * Nueva estructura:
 *  - Pestañas (tabs)    = grupo_presentacion_producto.nombre
 *  - Encabezados        = subgrupo_presentacion_producto.nombre
 *  - Botones            = catalogo_menu_pos.nombre  (clasificación; puede tener varios producto_presentacion)
 *
 * Al clic en un botón se abre el modal de selección/personalización.
 * El cajero elige el producto concreto, configura opciones y da "Aplicar".
 * El ítem se agrega solo al presionar "Aplicar".
 */
function renderizarCatalogoPorGrupos(lista) {
    _grupos = new Map();

    lista.forEach(p => {
        const gId     = parseInt(p.id_grupo)    || 0;
        const gNombre = p.grupo    || 'Otros';

        // Exclusión puntual: no mostrar la pestaña de Membresías en el catálogo (tiene acceso directo Quick Add abajo)
        const esMembresia = gId === 7 || gNombre.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").includes('membresia');
        if (esMembresia) {
            return;
        }

        if (!_grupos.has(gId)) {
            _grupos.set(gId, { id: gId, nombre: gNombre, subgrupos: new Map() });
        }
        const grupo   = _grupos.get(gId);
        const sId     = parseInt(p.id_subgrupo) || 0;
        const sNombre = p.subgrupo || 'General';
        if (!grupo.subgrupos.has(sId)) {
            grupo.subgrupos.set(sId, { id: sId, nombre: sNombre, catalogos: new Map() });
        }
        const subgrupo = grupo.subgrupos.get(sId);

        // Agrupar por catalogo_menu_pos
        const cId     = parseInt(p.id_catalogo_menu_pos) || 0;
        const cNombre = p.nombre_catalogo || p.nombre_maestro || p.Nombre || '—';
        if (!subgrupo.catalogos.has(cId)) {
            subgrupo.catalogos.set(cId, { id: cId, nombre: cNombre, productos: [] });
        }
        subgrupo.catalogos.get(cId).productos.push(p);
    });

    // Render tabs (grupos)
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
        // Cada botón = una clasificación (catalogo_menu_pos)
        sg.catalogos.forEach(cat => {
            const tienePersonalizable = cat.productos.some(p => parseInt(p.es_personalizable) === 1);
            html += `<div class="producto-card-pos${tienePersonalizable ? ' producto-personalizable' : ''}" tabindex="0" role="button"
                          onclick="abrirModalSeleccionProducto(${cat.id}, '${escAttr(cat.nombre)}')"
                          onkeypress="if(event.key==='Enter')abrirModalSeleccionProducto(${cat.id}, '${escAttr(cat.nombre)}')"
                     >${escHtml(cat.nombre)}${tienePersonalizable ? '<i class="bi bi-sliders2 ms-1" style="font-size:.7rem;opacity:.6;" title="Personalizable"></i>' : ''}</div>`;
        });
        html += `</div>`;
    });
    html += '</div>';
    $('#catalogoPanel').html(html);
}
/* ---- Filtrar localmente (overlay de búsqueda) ---- */
/*
 * La búsqueda ahora muestra botones de clasificación (catalogo_menu_pos),
 * igual que el catálogo por grupos, no productos directos.
 */
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
        (p.subgrupo       || '').toLowerCase().includes(ql) ||
        (p.nombre_catalogo|| '').toLowerCase().includes(ql)
    );

    if (!filtrados.length) {
        $('#catalogoPanel').html(`<p class="no-productos-msg"><i class="bi bi-search me-1"></i> Sin resultados para "<strong>${escHtml(q)}</strong>".</p>`);
        return;
    }

    // Agrupar resultados por catalogo_menu_pos (igual que la vista normal)
    const catMap = new Map();
    filtrados.forEach(p => {
        const cId     = parseInt(p.id_catalogo_menu_pos) || 0;
        const cNombre = p.nombre_catalogo || p.nombre_maestro || p.Nombre || '—';
        if (!catMap.has(cId)) {
            catMap.set(cId, { id: cId, nombre: cNombre, productos: [] });
        }
        catMap.get(cId).productos.push(p);
    });

    let html = '<div class="busqueda-resultados-grid">';
    catMap.forEach(cat => {
        const tienePersonalizable = cat.productos.some(p => parseInt(p.es_personalizable) === 1);
        html += `<div class="producto-card-pos${tienePersonalizable ? ' producto-personalizable' : ''}" tabindex="0" role="button"
                      onclick="abrirModalSeleccionProducto(${cat.id}, '${escAttr(cat.nombre)}')"
                 >${escHtml(cat.nombre)}${tienePersonalizable ? '<i class="bi bi-sliders2 ms-1" style="font-size:.7rem;opacity:.6;" title="Personalizable"></i>' : ''}</div>`;
    });
    html += '</div>';
    $('#catalogoPanel').html(html);
}

/* ============================================================
   MODAL DE SELECCIÓN DE PRODUCTO + PERSONALIZACIÓN
   ============================================================
   Flujo:
   1. El cajero hace clic en un botón del catálogo (catalogo_menu_pos).
   2. Se abre el modalPersonalizacion con un selector de producto en la parte
      superior (una línea horizontal de opciones, solo una seleccionable).
   3. Al elegir un producto se cargan su receta, endulzantes y extras.
   4. El cajero configura y da "Aplicar" → recién entonces se agrega al ticket.
   ============================================================ */

// Estado del modal de selección
let _mspIdCatalogo   = null;   // id del catalogo_menu_pos abierto
let _mspNombreCat    = '';     // nombre de la clasificación
let _mspProductos    = [];     // lista de productos de esa clasificación
let _mspIdxSelec     = -1;     // índice dentro de _mspProductos del producto seleccionado
let _mspModoEdicion  = false;  // true si estamos editando un ítem ya existente en el ticket
let _mspIdxTicket    = -1;     // índice en detalleFactura (si es edición)

/* ---- Abrir el modal de selección de producto de una clasificación ---- */
function abrirModalSeleccionProducto(idCatalogo, nombreCatalogo) {
    _mspIdCatalogo  = idCatalogo;
    _mspNombreCat   = nombreCatalogo;
    _mspProductos   = [];
    _mspIdxSelec    = -1;
    _mspModoEdicion = false;
    _mspIdxTicket   = -1;

    // Resetear draft de personalización
    _mpIdx   = -1;
    _mpDraft = {
        ingredientes_quitados:    [],
        ingredientes_sustituidos: [],
        endulzante_id:            null,
        endulzante_nombre:        '',
        extras_agregados:         [],
        monto_extras:             0
    };

    // Configurar encabezado del modal
    $('#modalPersonalizacionLabel').text(escHtml(nombreCatalogo));
    $('#mpSubtitulo').text('Selecciona un producto y configura tus opciones');
    $('#mpAjustePrecio').hide();

    // Mostrar spinner mientras carga los productos de la clasificación
    $('#mpSelectorProductos').html(
        '<div class="d-flex align-items-center gap-2 py-2"><span class="spinner-border spinner-border-sm text-secondary"></span><span class="text-muted small">Cargando opciones…</span></div>'
    );
    $('#mpCuerpo').html(
        '<div class="d-flex align-items-center justify-content-center py-5"><span class="spinner-border spinner-border-sm me-2 text-secondary"></span><span class="text-muted">Elige un producto arriba para ver sus opciones…</span></div>'
    );

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPersonalizacion'));
    modal.show();

    // Cargar productos de este catalogo_menu_pos
    $.ajax({
        url: 'ajax/get_productos_catalogo.php',
        type: 'GET',
        data: { id_catalogo: idCatalogo },
        dataType: 'json',
        success: function (res) {
            if (!res.success || !res.data || res.data.length === 0) {
                $('#mpSelectorProductos').html('<span class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>No hay productos disponibles en esta clasificación.</span>');
                return;
            }
            _mspProductos = res.data;
            _renderSelectorProductos();

            // Si solo hay un producto, seleccionarlo automáticamente
            if (_mspProductos.length === 1) {
                _mspSeleccionarProducto(0);
            }
        },
        error: function () {
            $('#mpSelectorProductos').html('<span class="text-danger small"><i class="bi bi-cloud-slash me-1"></i>Error al cargar los productos.</span>');
        }
    });
}

/* ---- Renderizar los botones de selección de producto (línea horizontal) ---- */
function _renderSelectorProductos() {
    if (!_mspProductos.length) return;

    let html = '<div class="msp-productos-selector">';
    _mspProductos.forEach((p, i) => {
        const precio = parseFloat(p.precio_venta || 0);
        const activo = i === _mspIdxSelec ? ' activo' : '';
        html += `<button type="button" class="msp-btn-producto${activo}" data-idx="${i}"
                         onclick="_mspSeleccionarProducto(${i})">
                    <span class="msp-btn-nombre">${escHtml(p.Nombre)}</span>
                    <span class="msp-btn-precio">C$ ${precio.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</span>
                 </button>`;
    });
    html += '</div>';
    $('#mpSelectorProductos').html(html);
}

/* ---- Seleccionar un producto del selector horizontal ---- */
function _mspSeleccionarProducto(idx) {
    if (!_mspProductos[idx]) return;
    _mspIdxSelec = idx;

    const p = _mspProductos[idx];

    // Actualizar botones activos
    $('#mpSelectorProductos .msp-btn-producto').removeClass('activo');
    $(`#mpSelectorProductos .msp-btn-producto[data-idx="${idx}"]`).addClass('activo');

    // Subtítulo con nombre del producto seleccionado
    $('#mpSubtitulo').text(`${escHtml(p.Nombre)} — Ajusta opciones`);
    $('#mpAjustePrecio').hide();

    // Resetear draft de personalización al cambiar producto
    _mpDraft = {
        ingredientes_quitados:    [],
        ingredientes_sustituidos: [],
        endulzante_id:            null,
        endulzante_nombre:        '',
        extras_agregados:         [],
        monto_extras:             0
    };

    // Solo si estamos en modo edición de un ítem del ticket, precargar su personalización
    if (_mspModoEdicion && _mspIdxTicket >= 0 && detalleFactura[_mspIdxTicket] && detalleFactura[_mspIdxTicket].personalizacion) {
        _mpDraft = JSON.parse(JSON.stringify(detalleFactura[_mspIdxTicket].personalizacion));
    }

    // Mostrar spinner mientras carga receta
    $('#mpCuerpo').html('<div class="d-flex align-items-center justify-content-center py-5"><span class="spinner-border spinner-border-sm me-2 text-secondary"></span><span class="text-muted">Cargando receta…</span></div>');

    // Cargar receta (usa caché igual que antes)
    const idPres = parseInt(p.id);
    if (_mpCache[idPres]) {
        _mpRenderContenido(_mpCache[idPres]);
        return;
    }

    $.ajax({
        url: 'ajax/get_receta_producto.php',
        type: 'GET',
        data: { id_presentacion: idPres },
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                _mpCache[idPres] = res;
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

/* ---- Agregar producto al detalle (siempre crea línea nueva) ---- */
/*
 * REGLA: Cada llamada desde el modal crea un ítem independiente.
 * La cantidad SOLO se modifica con los botones +/- de la tabla.
 * Para identificar filas en el DOM se usa _rowKey (contador único),
 * ya que el mismo id_presentacion puede aparecer varias veces.
 */
function agregarProducto(id, nombre, unidad, precio, esPersonalizable, productoObj, personalizacion) {
    const precioNum    = parseFloat(precio) || 0;
    const prodCatalogo = productoObj || todosProductos.find(p => parseInt(p.id) === parseInt(id)) || {};

    // Calcular precio final con extras del borrador
    const montoExtras = (personalizacion && personalizacion.monto_extras) ? parseFloat(personalizacion.monto_extras) : 0;
    const precioFinal = Math.round((precioNum + montoExtras) * 100) / 100;

    // Clave de fila única para el DOM (independiente de id_presentacion)
    const rowKey = ++_rowKeyCounter;

    const nuevoItem = {
        _rowKey:              rowKey,                // identificador único de esta fila
        id_presentacion:      parseInt(id),
        nombre:               nombre,
        unidad:               unidad,
        precio_unitario_base: precioNum,
        precio_unitario:      precioFinal,
        cantidad:             1,
        costo_total_iva:      precioFinal,
        notas:                '',
        es_personalizable:    parseInt(esPersonalizable) === 1,
        es_canjeado:          false,
        descuento_promo:      0,
        promos_en_item:       [],
        es_condicion_promo:   false,
        qty_condicion:        0,
        puntos_acumulacion:   prodCatalogo.puntos_acumulacion != null ? parseFloat(prodCatalogo.puntos_acumulacion) : null,
        puntos_canje:         prodCatalogo.puntos_canje        != null ? parseFloat(prodCatalogo.puntos_canje)        : null,
        id_catalogo_menu_pos: prodCatalogo.id_catalogo_menu_pos ? parseInt(prodCatalogo.id_catalogo_menu_pos) : null,
        personalizacion:      personalizacion ? JSON.parse(JSON.stringify(personalizacion)) : {
            ingredientes_quitados:    [],
            ingredientes_sustituidos: [],
            endulzante_id:            null,
            endulzante_nombre:        '',
            extras_agregados:         [],
            monto_extras:             0
        }
    };

    detalleFactura.push(nuevoItem);
    actualizarTablaDetalle();
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

        // Badges de puntos Club Pitaya (solo visibles con cliente club activo)
        // Cálculo de puntos efectivos considerando exclusión parcial por condición de promo
        const qtyCond   = parseInt(item.qty_condicion || 0); // unidades que son condición
        const cantLibre = Math.max(0, item.cantidad - qtyCond); // unidades que acumulan
        const ptsEfectivos = cantLibre * (item.puntos_acumulacion || 0); // puntos reales

        // Badge condición de promo: siempre que alguna unidad sea condición
        const badgeCondicion = esClubValido && item.es_condicion_promo
            ? `<span class="badge-condicion-promo ms-1" title="${qtyCond} unidad(es) son condición de promoción activa — no acumulan puntos ni son canjeables"><i class="bi bi-tag-fill me-1"></i>COND. ×${qtyCond}</span>`
            : '';

        // Badge acumulación: solo cuando hay unidades libres (no condición) con puntos configurados
        const badgeAcum = esClubValido && item.puntos_acumulacion != null && ptsEfectivos > 0 && !item.es_canjeado
            ? `<span class="badge-puntos-acum ms-1" title="Acumula ${item.puntos_acumulacion} pts × ${cantLibre} unidad(es) libre(s)">⭐ +${ptsEfectivos.toFixed(2)} pts</span>`
            : '';

        // Badge de costo de canje: visible SOLO cuando el toggle de canjeado está activo
        const badgePtsCanje = esClubValido && item.es_canjeado && item.puntos_canje != null && !item.es_condicion_promo
            ? `<small class="badge-puntos-canje d-block mt-1" title="Canjeando por ${item.puntos_canje} pts">🔴 -${item.puntos_canje} pts</small>`
            : '';

        // El toggle es canjeable si: cliente club válido + tiene regla de canje + NO es condición de promo (ni parcial)
        const esCanjeable = esClubValido && item.puntos_canje != null && !item.es_condicion_promo;

        html += `
        <tr id="fila-${item._rowKey}" class="fila-producto-principal ${item.es_canjeado ? 'fila-canjeada' : ''}" data-idx="${idx}">
            <td>
                <div class="d-flex align-items-start gap-1">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center flex-wrap gap-1">
                            <strong style="font-size:.88rem; color:#1e293b;">${escHtml(item.nombre)}</strong>
                            ${badgeCondicion}${badgeAcum}
                        </div>

                        ${badgePtsCanje}
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
                    ${(() => {
                        var _dto   = parseFloat(item.descuento_promo) || 0;
                        var _pFin  = Math.max(0, Math.round((precioTotalLinea - _dto) * 100) / 100);
                        var _pFmtOrig = precioTotalLinea.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        var _pFmtFin  = _pFin.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        var _promoNom = (item.promos_en_item && item.promos_en_item.length > 0) ? item.promos_en_item.map(function(pp){ return escHtml(pp.nombre); }).join(', ') : '';
                        if (_dto > 0) {
                            return `<del class="precio-con-promo-tachado">C$ ${_pFmtOrig}</del>`
                                 + `<strong class="precio-con-promo-final d-block">C$ ${_pFmtFin}</strong>`
                                 + (_promoNom ? `<small class="badge-promo-linea d-block mt-1">${_promoNom}</small>` : '');
                        }
                        return `<strong class="text-dark" style="font-size:.92rem; font-variant-numeric: tabular-nums;">C$ ${_pFmtOrig}</strong>`
                             + (item.cantidad > 1 ? `<small class="d-block text-muted" style="font-size:.72rem;">(C$ ${precioUnit.toFixed(2)} c/u)</small>` : '')
                             + ((pers.monto_extras || 0) > 0 ? `<small class="d-block text-success" style="font-size:.72rem;"><i class="bi bi-plus-circle"></i> +C$ ${(pers.monto_extras||0).toFixed(2)} extras</small>` : '');
                    })()}
                `}
            </td>
            <td class="text-center" style="width:65px;">
                ${esCanjeable || item.es_canjeado ? `
                <label class="switch-canje-pos"
                       title="${item.es_canjeado ? 'Desmarcar canje' : `Canjear por ${item.puntos_canje} pts`}">
                    <input type="checkbox" ${item.es_canjeado ? 'checked' : ''} onchange="toggleCanjeadoItem(${idx}, this.checked)">
                    <span class="slider-canje-pos"></span>
                </label>` : (esClubValido ? '' : `
                <label class="switch-canje-pos switch-canje-deshabilitado"
                       title="Canje disponible únicamente para miembros verificados del Club Pitaya">
                    <input type="checkbox" disabled>
                    <span class="slider-canje-pos"></span>
                </label>`)}
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

    // Validación: el producto debe tener regla de canje configurada
    if (checked && item.puntos_canje == null) {
        actualizarTablaDetalle(); // revertir visualmente
        return;
    }

    // Validación: no puede canjearse si es condición de una promo activa
    if (checked && item.es_condicion_promo) {
        actualizarTablaDetalle(); // revertir visualmente
        Swal.fire({
            icon: 'warning',
            title: 'No disponible para canje',
            text: 'Este producto es condición de una promoción activa y no puede ser canjeado con puntos.',
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#0E544C'
        });
        return;
    }

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
    return detalleFactura.reduce(function (s, d) {
        if (d.es_canjeado) return s; // ya vale 0, el costo_total_iva es 0
        var base = parseFloat(d.costo_total_iva) || 0;
        var dto  = parseFloat(d.descuento_promo)  || 0;
        return s + Math.max(0, Math.round((base - dto) * 100) / 100);
    }, 0);
}

function calcularMontoPropina(subtotal) {
    return conPropina ? Math.round(subtotal * (PORCENTAJE_PROPINA / 100) * 100) / 100 : 0;
}

function calcularTotalAPagar() {
    var subtotal = calcularSubtotalFactura();
    var propina  = calcularMontoPropina(subtotal);
    var total    = Math.round((subtotal + propina) * 100) / 100;
    // _promoDescuentoGlobal: descuento a nivel de factura (pct_factura / monto_factura)
    return Math.max(0, Math.round((total - (_promoDescuentoGlobal || 0)) * 100) / 100);
}

function sumarTotalPagos() {
    return Math.round(pagos.reduce((s, p) => {
        const monto = parseFloat(p.monto) || 0;
        // Efectivo dólar: convertir a córdobas con el T/C del día
        if (p.metodo === 'efectivo_dolar' && tipoCambioUSD > 0) {
            return s + Math.round(monto * tipoCambioUSD * 100) / 100;
        }
        return s + monto;
    }, 0) * 100) / 100;
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
            let equivCor;
            if (tcUSD <= 0) {
                equivCor = `<span style="font-size:.78rem;color:#e53e3e;">⚠ Sin T/C configurado — contacte al administrador</span>`;
            } else if (p.monto > 0) {
                equivCor = `<span style="font-size:.78rem;color:#0e544c;font-weight:600;">≈ C$ ${(p.monto * tcUSD).toFixed(2)}</span>`;
            } else {
                equivCor = `<span style="font-size:.78rem;color:#64748b;">T/C del día: C$ ${tcUSD.toFixed(4)} por US$1</span>`;
            }
            html += `
            <div class="subdetalle-referencia" style="padding:3px 12px;">${equivCor}</div>`;
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

    // Mostrar/ocultar fila de descuento de promociones
    var _sumItemDtos = detalleFactura.reduce(function(s,d){ return s + (parseFloat(d.descuento_promo)||0); }, 0);
    var _totalDescPromo = Math.round((_sumItemDtos + (_promoDescuentoGlobal||0)) * 100) / 100;
    if (_totalDescPromo > 0) {
        var $filaDesc = $("#filaDescuentoPromo");
        if ($filaDesc.length === 0) {
            $("#txtSubtotalFactura").closest("tr").after(
                "<tr id=\"filaDescuentoPromo\"><td class=\"text-muted\" style=\"font-size:.85rem;\"><i class=\"bi bi-tag-fill text-success me-1\"></i>Desc. Promo</td>" +
                "<td class=\"text-end text-success fw-semibold\" id=\"txtDescuentoPromo\"></td></tr>"
            );
        }
        $("#txtDescuentoPromo").text("-C$ " + _totalDescPromo.toLocaleString("es-NI", { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        $("#filaDescuentoPromo").show();
    } else {
        $("#filaDescuentoPromo").hide();
    }


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
                _rowKeyCounter        = 0;
                _promoIdsAprobados    = [];
                _promoDescuentoGlobal = 0;
                $("#filaDescuentoPromo").remove();
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

/* ---- Abrir modal para EDITAR personalización de ítem existente en el ticket ---- */
/*
 * Este modo se activa desde el botón de personalización en la fila del ticket.
 * A diferencia del flujo de selección de catálogo, aquí ya hay un producto
 * seleccionado, así que mostramos directamente su receta sin el selector.
 */
function abrirModalPersonalizacion(idx) {
    const item = detalleFactura[idx];
    if (!item) return;

    _mpIdx          = idx;
    _mspModoEdicion = true;
    _mspIdxTicket   = idx;
    _mspProductos   = [];
    _mspIdxSelec    = -1;

    // Clonar personalización actual como borrador de trabajo
    _mpDraft = JSON.parse(JSON.stringify(item.personalizacion || {
        ingredientes_quitados:    [],
        ingredientes_sustituidos: [],
        endulzante_id:            null,
        endulzante_nombre:        '',
        extras_agregados:         [],
        monto_extras:             0
    }));

    // Actualizar título del modal — sin selector de productos (ya está elegido)
    $('#modalPersonalizacionLabel').text('Personalizar: ' + item.nombre);
    $('#mpSubtitulo').text('Ajusta ingredientes, endulzante y extras');
    // Ocultar el selector de productos (no aplica en edición directa)
    $('#mpSelectorProductos').html('').hide();
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
                    <div class="d-flex align-items-center gap-2">
                        <strong>${escHtml(ing.nombre_ingrediente)}</strong>
                        <small class="text-muted ms-auto">${ing.cantidad} ${escHtml(ing.unidad_ingrediente)}</small>
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

/* ---- Aplicar personalización / Confirmar selección de producto al ticket ---- */
/*
 * Hay dos modos:
 * A) Modo nuevo (desde catálogo): _mspModoEdicion = false
 *    - _mspProductos[_mspIdxSelec] es el producto elegido
 *    - Se llama a agregarProducto() con la personalización del borrador
 *
 * B) Modo edición (desde tabla, ícono de lápiz): _mspModoEdicion = true
 *    - _mpIdx apunta al ítem en detalleFactura
 *    - Se actualiza su personalización y precio sin crear un nuevo ítem
 */
$('#mpBtnGuardar').on('click', function () {

    /* ── MODO EDICIÓN (ítem ya existente en el ticket) ── */
    if (_mspModoEdicion && _mpIdx >= 0 && detalleFactura[_mpIdx]) {
        const item = detalleFactura[_mpIdx];
        item.personalizacion = JSON.parse(JSON.stringify(_mpDraft));

        // Ajustar precio_unitario: precio base + monto de extras
        item.precio_unitario = Math.round(((item.precio_unitario_base || 0) + (_mpDraft.monto_extras || 0)) * 100) / 100;
        item.costo_total_iva = Math.round(item.cantidad * item.precio_unitario * 100) / 100;

        actualizarTablaDetalle();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPersonalizacion')).hide();

        Swal.fire({
            icon: 'success',
            title: 'Personalización actualizada',
            toast: true,
            position: 'top-end',
            timer: 1800,
            showConfirmButton: false
        });
        return;
    }

    /* ── MODO NUEVO (desde catálogo, producto seleccionado del modal) ── */
    if (_mspIdxSelec < 0 || !_mspProductos[_mspIdxSelec]) {
        alertWarning('Selecciona un producto antes de aplicar.');
        return;
    }

    const p       = _mspProductos[_mspIdxSelec];
    const precio  = parseFloat(p.precio_venta || 0);
    const draft   = JSON.parse(JSON.stringify(_mpDraft));

    agregarProducto(
        parseInt(p.id),
        p.Nombre,
        p.nombre_unidad || 'Unidad',
        precio,
        p.es_personalizable,
        p,
        draft
    );

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPersonalizacion')).hide();

    Swal.fire({
        icon: 'success',
        title: 'Producto agregado',
        toast: true,
        position: 'top-end',
        timer: 1500,
        showConfirmButton: false
    });
});
/* ============================================================
   BANNER CLUB PITAYA (QUICK ADD)
   ============================================================ */

/* ---- Quick Add Membresía Club Pitaya ---- */
$('#btnQuickAddMembresia').on('click', function () {
    // Si el tipo de cliente no es club, activar Club Pitaya
    if ($('#tipoClienteGeneral').is(':checked')) {
        $('#tipoClienteClub').prop('checked', true);
        cambiarTipoCliente('club');
    }

    // Verificar si ya existe una membresía en el ticket (por id_catalogo_menu_pos = 13)
    const existe = detalleFactura.find(d => d.id_catalogo_menu_pos == 13);
    if (existe) {
        Swal.fire({
            icon: 'warning',
            title: 'Membresía ya agregada',
            text: 'La membresía ya se encuentra en el ticket actual.',
            toast: true,
            position: 'top-end',
            timer: 2000,
            showConfirmButton: false
        });
        return;
    }

    // Buscar el producto real de membresía desde BD (id_catalogo_menu_pos = 13, activo)
    $.get('ajax/get_producto_membresia.php', function (res) {
        if (!res.success || !res.data) {
            Swal.fire({
                icon: 'error',
                title: 'Producto no encontrado',
                text: res.message || 'No se encontró un producto de membresía activo.',
                toast: true,
                position: 'top-end',
                timer: 3000,
                showConfirmButton: false
            });
            return;
        }

        const p = res.data;
        const precio = parseFloat(p.precio_venta) || 0;

        detalleFactura.unshift({
            _rowKey:              ++_rowKeyCounter,
            id_presentacion:      parseInt(p.id),
            id_catalogo_menu_pos: parseInt(p.id_catalogo_menu_pos),
            nombre:               p.nombre,
            unidad:               p.nombre_unidad || 'Unidad',
            precio_unitario_base: precio,
            precio_unitario:      precio,
            cantidad:             1,
            costo_total_iva:      precio,
            notas:                'Ingresar número de membresía',
            es_personalizable:    false,
            personalizacion:      {}
        });
        actualizarTablaDetalle();

        Swal.fire({
            icon: 'info',
            title: 'Membresía agregada a la orden',
            text: p.nombre + ' (C$ ' + precio.toFixed(2) + ') agregada al ticket.',
            toast: true,
            position: 'top-end',
            timer: 2500,
            showConfirmButton: false
        });
    }).fail(function () {
        Swal.fire({
            icon: 'error',
            title: 'Error de conexión',
            text: 'No se pudo obtener el producto de membresía.',
            toast: true,
            position: 'top-end',
            timer: 3000,
            showConfirmButton: false
        });
    });
});



/* ============================================================
   MOTOR DE PROMOCIONES DINÁMICAS — POS Nueva Factura
   Conecta con ajax/get_promociones_activas.php y
   ajax/evaluar_promociones.php para mostrar promos reales de BD.
   ============================================================ */

// Estado global de promociones
let _promosActivas     = [];   // Todas las promos activas (catálogo)
let _promoIdsAprobados = [];   // IDs aprobados/aplicados en este ticket por el cajero
let _promoEvalTimer    = null; // Timer debounce para re-evaluar al cambiar carrito

/* ─── Carga inicial: todas las promos activas vigentes ──────── */
function cargarPromosActivas() {
    $('#promosLoadingState').show();
    $('#promosEmptyState, #bloquePromosTodas, #bloquePromosAplican, #bloquePromosPotenciales').hide();

    $.get('ajax/get_promociones_activas.php', function (res) {
        $('#promosLoadingState').hide();
        if (!res.success || !res.data || res.data.length === 0) {
            $('#promosEmptyState').show();
            return;
        }
        _promosActivas = res.data;
        renderPromosTodas(_promosActivas);
        evaluarPromocionesConCarrito();
    }, 'json').fail(function () {
        $('#promosLoadingState').hide();
        $('#promosEmptyState').show();
    });
}

/* ─── Render: bloque "DISPONIBLES" (catálogo estático) ─────── */
function renderPromosTodas(promos) {
    if (!promos || promos.length === 0) {
        $('#bloquePromosTodas').hide();
        return;
    }
    let html = '';
    promos.forEach(function (p) {
        const tipo = _labelResultado(p.resultado_tipo, p.resultado_valor);
        const auto = parseInt(p.ejecucion_automatica) === 1;
        html += `
        <div class="promo-card promo-card-teal" data-promo-id="${p.id}" role="button" tabindex="0"
             title="${escHtml(p.nombre)} — ${escHtml(tipo)}">
            ${auto ? '<div class="promo-badge promo-badge-teal">AUTO</div>' : '<div class="promo-badge promo-badge-neutral">MANUAL</div>'}
            <div class="promo-title">${escHtml(p.nombre)}</div>
            <div class="promo-sub">${escHtml(tipo)}${p.fecha_fin ? ' &middot; hasta ' + escHtml(p.fecha_fin) : ''}</div>
        </div>`;
    });
    $('#gridPromosTodas').html(html);
    $('#bloquePromosTodas').show();
}

/* ─── Evaluar con el carrito actual (debounce 600ms) ────────── */
function scheduleEvaluarPromos() {
    clearTimeout(_promoEvalTimer);
    _promoEvalTimer = setTimeout(evaluarPromocionesConCarrito, 600);
}

function evaluarPromocionesConCarrito() {
    if (!_promosActivas.length) return; // Promos aún no cargadas

    // Construir carrito para el motor de promociones.
    // todosProductos viene de facturas_get_productos_elegibles.php: tiene id, id_grupo, id_subgrupo.
    // Motor condicion 'producto': compara item.id (id_presentacion) vs valor.id_producto
    // Motor condicion 'grupo_producto': compara item.id_grupo vs valor.id_grupo
    const cart = detalleFactura
        .filter(function (d) { return d.id_presentacion > 0; })
        .map(function (d) {
            const prod = todosProductos.find(function (p) { return p.id === d.id_presentacion; }) || {};
            return {
                id:          d.id_presentacion,
                id_grupo:             parseInt(prod.id_grupo    || 0),
                id_subgrupo:          parseInt(prod.id_subgrupo || 0),
                id_catalogo_menu_pos: parseInt(prod.id_catalogo_menu_pos || 0),
                id_producto_maestro:  parseInt(prod.id_producto_maestro || 0),
                precio:               parseFloat(d.precio_unitario || 0),
                cantidad:             parseInt(d.cantidad || 1),
                nombre:               d.nombre || prod.Nombre || '',
            };
        });

    // Contexto actual
    const ahora   = new Date();
    const dSem    = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    const horaStr = String(ahora.getHours()).padStart(2,'0') + ':' + String(ahora.getMinutes()).padStart(2,'0');
    const tipoCli = $('input[name="tipo_cliente"]:checked').val() === 'club' ? 'Club' : 'General';

    const context = {
        dia:          dSem[ahora.getDay()],
        hora:         horaStr,
        sucursal:     (typeof POS_SUCURSAL_ID !== 'undefined') ? String(POS_SUCURSAL_ID) : '',
        canal:        'general',
        tipo_cliente: tipoCli
    };

    $.ajax({
        url:         'ajax/evaluar_promociones.php',
        type:        'POST',
        contentType: 'application/json',
        data:        JSON.stringify({ context: context, cart: cart, approved_ids: _promoIdsAprobados }),
        dataType:    'json',
        success: function (res) {
            if (!res.success) return;
            renderPromosEvaluadas(res);
        }
    });
}

/* ─── Render: resultados de la evaluación del motor ─────────── */
function renderPromosEvaluadas(res) {
    // ── Auto-limpiar aprobaciones que ya no califican ─────────────────────
    // El backend solo incluye en promos_aplicadas las que están en approved_ids
    // Y además cumplen TODAS las condiciones con el carrito actual.
    // Si una promo estaba aprobada pero ya no califica, la quitamos automáticamente.
    var idsConfirmados = (res.promos_aplicadas || []).map(function (p) { return parseInt(p.id); });
    var idsRevocados   = _promoIdsAprobados.filter(function (id) { return !idsConfirmados.includes(id); });
    if (idsRevocados.length > 0) {
        _promoIdsAprobados = idsConfirmados.slice(); // dejar solo las confirmadas
        // No necesitamos re-evaluar: el backend ya evaluó con approved_ids y devolvió la verdad
    }

    // ── Bloque "APLICAN AHORA" (califican + ya aprobadas) ──
    const aplican   = res.promos_aplican   || [];
    const aplicadas = res.promos_aplicadas || [];
    const todas     = [...aplican, ...aplicadas];

    if (todas.length > 0) {
        let html = '';
        todas.forEach(function (p) {
            const yaAprobada = _promoIdsAprobados.includes(parseInt(p.id));
            html += `
            <div class="${yaAprobada ? 'promo-card-dark promo-card-aplicada-activa' : 'promo-card-dark'}" data-promo-id="${p.id}">
                <div class="promo-dark-title">${escHtml(p.nombre)}</div>
                <div class="promo-dark-sub text-muted small mb-1">${escHtml(p.resumen || '')}</div>
                ${yaAprobada
                    ? `<button type="button" class="btn-quitar-promo-aplicada" onclick="quitarPromoAprobada(${p.id})">
                           <i class="bi bi-x-circle me-1"></i>QUITAR
                       </button>`
                    : `<button type="button" class="btn-aplicar-promo btn-aplicar-oro" onclick="aprobarPromocion(${p.id})">
                           APLICAR <i class="bi bi-check2 ms-1"></i>
                       </button>`
                }
            </div>`;
        });
        $('#gridPromosAplican').html(html);
        $('#bloquePromosAplican').show();
    } else {
        $('#bloquePromosAplican').hide();
    }

    // ── Bloque "CASI CALIFICAN" (potenciales) ──
    const potenciales = res.promos_potenciales || [];
    if (potenciales.length > 0) {
        let html = '';
        potenciales.forEach(function (p) {
            html += `
            <div class="promo-card promo-card-neutral" data-promo-id="${p.id}">
                <div class="promo-badge promo-badge-neutral">${p.condiciones_met}/${p.total_condiciones} cond.</div>
                <div class="promo-title">${escHtml(p.nombre)}</div>
                <div class="promo-sub">${escHtml(p.faltante)}</div>
            </div>`;
        });
        $('#gridPromosPotenciales').html(html);
        $('#bloquePromosPotenciales').show();
    } else {
        $('#bloquePromosPotenciales').hide();
    }

    // ══ APLICAR DESCUENTOS AL CARRITO ════════════════════════════
    // 1. Reset todos los descuentos de promo y flags de condición en detalleFactura
    detalleFactura.forEach(function (d) {
        d.descuento_promo    = 0;
        d.promos_en_item     = [];
        d.es_condicion_promo = false;
        d.qty_condicion      = 0; // unidades usadas como condición de promo (0 = ninguna)
    });

    // Marcar ítems que son condición de alguna promo APLICADA (aprobada)
    // items_condicion ahora es {id_presentacion: qty_usada_como_condicion}
    var condicionMap = res.items_condicion || {};
    Object.keys(condicionMap).forEach(function (idStr) {
        var qtyCond = parseInt(condicionMap[idStr] || 0);
        if (qtyCond <= 0) return;
        for (var k = 0; k < detalleFactura.length; k++) {
            if (String(detalleFactura[k].id_presentacion) === idStr) {
                var d = detalleFactura[k];
                d.qty_condicion      = qtyCond;
                d.es_condicion_promo = true; // cualquier involucración = excluido de canje
                break;
            }
        }
    });
    _promoDescuentoGlobal = 0;

    // 2. Aplicar descuentos por ítem (promos_aplicadas devuelve items con descuento_total)
    var resItems = res.items || [];
    resItems.forEach(function (ri) {
        var dtoItem = Math.round((parseFloat(ri.descuento_total) || 0) * 100) / 100;
        if (dtoItem <= 0) return;
        // Buscar el item en detalleFactura por id_presentacion
        var item = null;
        for (var k = 0; k < detalleFactura.length; k++) {
            if (parseInt(detalleFactura[k].id_presentacion) === parseInt(ri.id)) {
                item = detalleFactura[k];
                break;
            }
        }
        if (item && !item.es_canjeado) {
            item.descuento_promo = dtoItem;
            item.promos_en_item  = ri.promos || [];
        }
    });

    // 3. Si hay descuento global de factura (pct_factura/monto_factura),
    //    es la diferencia entre total_descuento y la suma de descuentos por item
    var sumItemDtos = detalleFactura.reduce(function (s, d) { return s + (d.descuento_promo || 0); }, 0);
    var totalDto    = Math.round((parseFloat(res.total_descuento) || 0) * 100) / 100;
    _promoDescuentoGlobal = Math.max(0, Math.round((totalDto - sumItemDtos) * 100) / 100);

    // 4. Actualizar totales y tabla SIN volver a disparar evaluarPromos
    _evaluandoPromos = true;
    actualizarTablaDetalle();
    _evaluandoPromos = false;
}

/* ─── Aprobar / quitar una promo ────────────────────────────── */
function aprobarPromocion(promoId) {
    const id = parseInt(promoId);
    if (_promoIdsAprobados.includes(id)) return;
    _promoIdsAprobados.push(id);
    evaluarPromocionesConCarrito();
    Swal.fire({
        icon: 'success', title: 'Promoción aplicada',
        toast: true, position: 'top-end',
        timer: 1800, showConfirmButton: false
    });
}

function quitarPromoAprobada(promoId) {
    const id = parseInt(promoId);
    _promoIdsAprobados = _promoIdsAprobados.filter(function (x) { return x !== id; });
    evaluarPromocionesConCarrito();
}

/* ─── Helper: etiqueta legible del tipo de resultado ────────── */
function _labelResultado(tipo, valor) {
    const v = parseFloat(valor) || 0;
    switch (tipo) {
        case 'pct_producto':   return v + '% dto. producto';
        case 'pct_factura':    return v + '% dto. factura';
        case 'monto_producto': return 'C$ ' + v.toFixed(2) + ' dto./unidad';
        case 'monto_factura':  return 'C$ ' + v.toFixed(2) + ' dto. factura';
        default:               return tipo + ' · ' + v;
    }
}

/* ─── Inicialización y monkey-patch (dentro de ready para garantizar orden) ─── */
$(document).ready(function () {
    // Cargar catálogo de promos reales desde BD
    cargarPromosActivas();

    // Botón refrescar manual
    $('#btnRefrescarPromos').on('click', function () {
        $(this).addClass('girando');
        const self = this;
        evaluarPromocionesConCarrito();
        setTimeout(function () { $(self).removeClass('girando'); }, 700);
    });

    // Re-evaluar promos cada vez que el carrito cambia
    const _origActualizar = actualizarTablaDetalle;
    actualizarTablaDetalle = function () {
        _origActualizar.apply(this, arguments);
        if (!_evaluandoPromos) scheduleEvaluarPromos();
    };
});


/* ── Reset estado del modal al cerrarlo ────────────────────── */
document.getElementById('modalPersonalizacion').addEventListener('hidden.bs.modal', function () {
    // Limpiar selector de productos para que no quede visible en el próximo apertura
    $('#mpSelectorProductos').html('').show(); // show() porque en modo edicion lo ocultamos
    _mspModoEdicion = false;
    _mspIdxTicket   = -1;
    _mspIdxSelec    = -1;
    _mspProductos   = [];
    _mpIdx          = -1;
});
