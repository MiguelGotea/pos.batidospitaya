/**
 * pos_combos.js
 * Modulo de gestion de combos en el POS.
 *
 * Depende de:
 *  - jQuery (ya cargado en facturas_nueva.php)
 *  - Bootstrap 5 (ya cargado)
 *  - SweetAlert2 (ya cargado)
 *  - Variables globales de facturas_nueva.js:
 *      detalleFactura, _rowKeyCounter, actualizarTablaDetalle,
 *      abrirModalSeleccionProducto, escHtml, escAttr, alertError
 *
 * Flujo:
 *  1. renderizarCatalogoPorGrupos guarda es_combo en el Map de grupos.
 *  2. cambiarGrupo detecta si un catalogo pertenece a un grupo combo
 *     y lo renderiza con onclick="abrirModalCombo(id, nombre)".
 *  3. abrirModalCombo() llama a get_combo_config.php y renderiza slots.
 *  4. El cajero elige productos para cada slot (array, soporta multi-seleccion);
 *     si es personalizable se abre el modal de personalizacion en modo sub-flujo.
 *  5. btnAplicarCombo valida y agrega el combo a detalleFactura.
 *  6. actualizarTablaDetalle (patcheada aqui) renderiza filas padre + hijos.
 */

'use strict';

/* ============================================================
   ESTADO GLOBAL DEL COMBO
   ============================================================ */
let _comboState = null;
/*
  _comboState.selecciones es un ARRAY de arrays:
  selecciones[slotIdx] = [ { slotIdx, id_slot_item, ... }, ... ]
  Soporta min_seleccion..max_seleccion items por slot.
*/

let _comboModalBS = null;         // instancia Bootstrap del modal
let _comboSubflujoPendiente = null; // {slotIdx, selIdx, _lenAntes} durante sub-flujo

/* ============================================================
   PATCH a renderizarCatalogoPorGrupos
   Agrega es_combo al Map de grupos para que cambiarGrupo lo use
   ============================================================ */
(function () {
    const _origRender = typeof renderizarCatalogoPorGrupos === 'function'
        ? renderizarCatalogoPorGrupos : null;

    if (!_origRender) {
        console.warn('[pos_combos] renderizarCatalogoPorGrupos no encontrada. El patch no aplico.');
        return;
    }

    window.renderizarCatalogoPorGrupos = function (lista) {
        _origRender(lista);

        if (typeof _grupos !== 'undefined' && _grupos instanceof Map) {
            lista.forEach(p => {
                const gId = parseInt(p.id_grupo) || 0;
                if (_grupos.has(gId)) {
                    _grupos.get(gId).es_combo = parseInt(p.es_combo) === 1;
                }
            });
        }
    };
})();

/* ============================================================
   PATCH a cambiarGrupo
   Si el grupo activo es de tipo combo, renderiza las cards
   con onclick="abrirModalCombo()" en lugar de _abrirCatalogoDesdeCard()
   ============================================================ */
(function () {
    const _origCambiarGrupo = typeof cambiarGrupo === 'function' ? cambiarGrupo : null;

    if (!_origCambiarGrupo) {
        console.warn('[pos_combos] cambiarGrupo no encontrada. El patch no aplico.');
        return;
    }

    window.cambiarGrupo = function (grupoId) {
        _origCambiarGrupo(grupoId);

        if (typeof _grupos === 'undefined' || !_grupos.has(grupoId)) return;
        const grupo = _grupos.get(grupoId);
        if (!grupo.es_combo) return;

        let html = '<div class="subgrupos-columnas">';
        grupo.subgrupos.forEach(sg => {
            html += `<div class="subgrupo-col">`;
            html += `<div class="subgrupo-header">${escHtml(sg.nombre)}</div>`;
            sg.catalogos.forEach(cat => {
                const idCatalogo = cat.ids[0];
                html += `<div class="producto-card-pos producto-combo" tabindex="0" role="button"
                              data-ids="${escAttr(JSON.stringify(cat.ids))}"
                              data-nombre="${escAttr(cat.nombre)}"
                              onclick="abrirModalCombo(${idCatalogo}, '${escAttr(cat.nombre)}')"
                              onkeypress="if(event.key==='Enter') abrirModalCombo(${idCatalogo}, '${escAttr(cat.nombre)}')">
                    <i class="fas fa-layer-group combo-card-icon me-1"></i>${escHtml(cat.nombre)}
                </div>`;
            });
            html += `</div>`;
        });
        html += '</div>';
        $('#catalogoPanel').html(html);
    };
})();

/* ============================================================
   PATCH a actualizarTablaDetalle
   Detecta items con es_combo=true y agrega filas hijas
   ============================================================ */
(function () {
    $(document).ready(function () {
        const _origActualizar = typeof actualizarTablaDetalle === 'function'
            ? actualizarTablaDetalle : null;
        if (!_origActualizar) {
            console.warn('[pos_combos] actualizarTablaDetalle no encontrada. El patch no aplico.');
            return;
        }

        window.actualizarTablaDetalle = function () {
            _origActualizar();

            detalleFactura.forEach((item, idx) => {
                if (!item.es_combo || !item.combo_items || !item.combo_items.length) return;

                const $filaPadre = $(`#fila-${item._rowKey}`);
                if ($filaPadre.length) {
                    $filaPadre.find('.item-nombre').prepend(
                        `<span class="badge-combo-padre me-1"><i class="fas fa-layer-group"></i> Combo</span>`
                    );
                }

                const numCols = $('th#thCanjeado:visible').length ? 4 : 3;
                let $ultimaFila = $(`tr[data-idx="${idx}"]`).last();

                item.combo_items.forEach((hijo, hijoIdx) => {
                    const pers = hijo.personalizacion || {};
                    let tagsParts = [];
                    if (pers.endulzante_nombre) {
                        tagsParts.push(`<span class="badge-pers-item"><i class="bi bi-water"></i> ${escHtml(pers.endulzante_nombre)}</span>`);
                    }
                    if (pers.extras_agregados && pers.extras_agregados.length) {
                        const extrasStr = pers.extras_agregados.map(e => escHtml(e.nombre)).join(', ');
                        tagsParts.push(`<span class="badge-pers-item badge-pers-extra"><i class="bi bi-plus-circle"></i> ${extrasStr}</span>`);
                    }
                    if (pers.ingredientes_quitados && pers.ingredientes_quitados.length) {
                        tagsParts.push(`<span class="badge-pers-item badge-pers-quitar"><i class="bi bi-x"></i> ${pers.ingredientes_quitados.map(i => escHtml(i.nombre_componente)).join(', ')}</span>`);
                    }
                    if (pers.notas && pers.notas.trim()) {
                        tagsParts.push(`<span class="badge-pers-item"><i class="bi bi-chat-left-text"></i> ${escHtml(pers.notas.trim())}</span>`);
                    }

                    const upchargeStr = hijo.upcharge > 0
                        ? ` <span class="combo-hijo-upcharge">(+C$ ${parseFloat(hijo.upcharge).toFixed(2)})</span>` : '';

                    const btnEditar = hijo.es_personalizable
                        ? `<button type="button" class="btn-personalizar-fila ms-1" onclick="editarItemCombo(${idx}, ${hijoIdx})" title="Personalizar"><i class="bi bi-pencil"></i></button>`
                        : '';

                    const filaHija = `
                    <tr class="fila-combo-hijo" data-combo-idx="${idx}" data-hijo-idx="${hijoIdx}">
                        <td colspan="${numCols}">
                            <div class="combo-hijo-row">
                                <i class="bi bi-arrow-return-right combo-hijo-icon text-muted"></i>
                                <span class="combo-hijo-nombre">${escHtml(hijo.nombre_catalogo)}${hijo.tamano_producto ? ' <small class="text-muted">'+escHtml(hijo.tamano_producto)+'</small>' : ''}${upchargeStr}</span>
                                ${btnEditar}
                            </div>
                            ${tagsParts.length ? `<div class="combo-hijo-tags ps-4">${tagsParts.join('')}</div>` : ''}
                        </td>
                    </tr>`;

                    $ultimaFila.after(filaHija);
                    $ultimaFila = $(`tr.fila-combo-hijo[data-combo-idx="${idx}"][data-hijo-idx="${hijoIdx}"]`);
                });
            });
        };
    });
})();

/* ============================================================
   ABRIR MODAL COMBO
   ============================================================ */
function abrirModalCombo(idCatalogo, nombreCombo) {
    _comboState = null;
    _comboSubflujoPendiente = null;

    $('#modalComboLabel').text(nombreCombo || 'Configurar Combo');
    $('#modalComboSubtitulo').text('Cargando...');
    $('#comboSlotNav').hide().empty();
    $('#comboCuerpo').html(`
        <div class="d-flex align-items-center justify-content-center py-5">
            <span class="spinner-border spinner-border-sm me-2 text-secondary"></span>
            <span class="text-muted">Cargando combo...</span>
        </div>`);
    $('#comboTotalDisplay').text('C$ 0.00');
    $('#btnAplicarCombo').prop('disabled', true);

    if (!_comboModalBS) {
        _comboModalBS = new bootstrap.Modal(document.getElementById('modalCombo'));
    }
    _comboModalBS.show();

    $.getJSON('/modulos/pos/ajax/get_combo_config.php', { id_catalogo_menu_pos: idCatalogo })
        .done(function (res) {
            if (!res.success) {
                $('#comboCuerpo').html(`<div class="alert alert-danger m-3">${escHtml(res.message || 'Error al cargar el combo.')}</div>`);
                return;
            }
            const combo = res.combo;
            _comboState = {
                id_catalogo:   combo.id_catalogo,
                nombre:        combo.nombre,
                observaciones: combo.observaciones,
                precio_base:   parseFloat(combo.precio_venta) || 0,
                slots:         combo.slots || [],
                selecciones:   (combo.slots || []).map(() => []),  // array de arrays
                slotActivoIdx: 0
            };

            // Pre-seleccionar defaults: push al array del slot
            _comboState.slots.forEach((slot, sIdx) => {
                const defItem = slot.items.find(it => it.es_default);
                if (defItem) {
                    _comboState.selecciones[sIdx].push({
                        slotIdx:              sIdx,
                        id_slot_item:         defItem.id_slot_item,
                        id_slot:              slot.id,
                        id_presentacion:      defItem.id_presentacion,
                        id_catalogo_menu_pos: defItem.id_catalogo_menu_pos,
                        nombre_catalogo:      defItem.nombre_catalogo,
                        tamano_producto:      defItem.tamano_producto,
                        es_personalizable:    defItem.es_personalizable,
                        precio_venta:         defItem.precio_venta,
                        upcharge:             defItem.upcharge,
                        personalizacion:      _persVacia()
                    });
                }
            });

            $('#modalComboLabel').text(combo.nombre);
            $('#modalComboSubtitulo').text(combo.observaciones || 'Elige los productos de cada slot');

            _renderizarNavSlots();
            _renderizarSlotActivo();
            _actualizarTotalCombo();
        })
        .fail(function () {
            $('#comboCuerpo').html(`<div class="alert alert-danger m-3">Error de conexion al cargar el combo.</div>`);
        });
}

/* ============================================================
   RENDERIZAR NAVEGACION DE SLOTS (tabs superiores)
   ============================================================ */
function _renderizarNavSlots() {
    if (!_comboState || !_comboState.slots.length) return;

    let html = '';
    _comboState.slots.forEach((slot, idx) => {
        const sels = _comboState.selecciones[idx];
        const completo = slot.min_seleccion > 0 && sels.length >= slot.min_seleccion;
        const activo = idx === _comboState.slotActivoIdx;
        const cls = `combo-slot-tab ${activo ? 'active' : ''} ${completo ? 'completo' : ''}`;
        html += `<button type="button" class="${cls}" onclick="_cambiarSlotActivo(${idx})">
            ${completo ? '<i class="bi bi-check-circle-fill me-1 text-success"></i>' : `<span class="slot-num">${idx + 1}</span>`}
            ${escHtml(slot.nombre)}
        </button>`;
    });

    $('#comboSlotNav').html(html).show();
}

/* ============================================================
   CAMBIAR SLOT ACTIVO
   ============================================================ */
function _cambiarSlotActivo(idx) {
    if (!_comboState) return;
    _comboState.slotActivoIdx = idx;
    _renderizarNavSlots();
    _renderizarSlotActivo();
}

/* ============================================================
   RENDERIZAR CONTENIDO DEL SLOT ACTIVO
   ============================================================ */
function _renderizarSlotActivo() {
    if (!_comboState) return;
    const slot = _comboState.slots[_comboState.slotActivoIdx];
    if (!slot) return;

    const selsActuales = _comboState.selecciones[_comboState.slotActivoIdx]; // array
    const descripcion = slot.descripcion ? `<p class="text-muted small mb-2 px-3">${escHtml(slot.descripcion)}</p>` : '';

    // Texto del badge: muestra progreso cuando max > 1
    let minMaxLabel;
    if (slot.min_seleccion === slot.max_seleccion) {
        minMaxLabel = 'Elige ' + slot.min_seleccion;
    } else {
        minMaxLabel = 'Elige entre ' + slot.min_seleccion + ' y ' + slot.max_seleccion;
    }
    const progresoLabel = slot.max_seleccion > 1
        ? ' (' + selsActuales.length + '/' + slot.max_seleccion + ')' : '';

    let html = `<div class="combo-slot-cuerpo p-3">`;
    html += `<div class="d-flex align-items-center mb-2">
        <strong class="me-2">${escHtml(slot.nombre)}</strong>
        <span class="badge bg-secondary combo-minmax-badge">${escHtml(minMaxLabel)}${progresoLabel}</span>
    </div>`;
    html += descripcion;

    if (!slot.items.length) {
        html += `<div class="alert alert-warning">Este slot no tiene productos configurados.</div>`;
    } else {
        html += `<div class="combo-items-grid">`;
        slot.items.forEach(item => {
            const esSel = selsActuales.some(s => s.id_slot_item === item.id_slot_item);
            const upchargeStr = item.upcharge > 0
                ? `<span class="combo-item-upcharge">+C$ ${parseFloat(item.upcharge).toFixed(2)}</span>` : '';
            const tamanoStr = item.tamano_producto
                ? `<small class="combo-item-tamano">${escHtml(item.tamano_producto)}</small>` : '';
            const persIcon = item.es_personalizable
                ? `<span class="combo-item-pers-icon" title="Personalizable"><i class="bi bi-pencil"></i></span>` : '';

            html += `<div class="combo-item-card ${esSel ? 'seleccionado' : ''}"
                          onclick="_seleccionarItemCombo(${_comboState.slotActivoIdx}, ${JSON.stringify(item).replace(/"/g, '&quot;')})"
                          role="button" tabindex="0"
                          onkeypress="if(event.key==='Enter') _seleccionarItemCombo(${_comboState.slotActivoIdx}, ${JSON.stringify(item).replace(/"/g, '&quot;')})">
                <div class="combo-item-nombre">${escHtml(item.nombre_catalogo)} ${tamanoStr}</div>
                <div class="combo-item-meta">${upchargeStr} ${persIcon}</div>
            </div>`;
        });
        html += `</div>`;

        // Lista de seleccionados con boton Personalizar por item
        if (selsActuales.length > 0) {
            html += `<div class="combo-sel-lista mt-2 px-1">`;
            selsActuales.forEach((sel, selIdx) => {
                html += `<div class="combo-sel-item d-flex align-items-center justify-content-between py-1 border-bottom">
                    <span class="small"><i class="bi bi-check-circle-fill text-success me-1"></i>${escHtml(sel.nombre_catalogo)}${sel.tamano_producto ? ' <small class="text-muted">'+escHtml(sel.tamano_producto)+'</small>' : ''}</span>
                    ${sel.es_personalizable ? `<button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="event.stopPropagation();_abrirPersComboItem(${_comboState.slotActivoIdx},${selIdx})">
                        <i class="bi bi-pencil me-1"></i>Personalizar</button>` : ''}
                </div>`;
            });
            html += `</div>`;
        }
    }

    html += `</div>`;

    // Botones de navegacion
    const hayAnterior = _comboState.slotActivoIdx > 0;
    const haySiguiente = _comboState.slotActivoIdx < _comboState.slots.length - 1;
    html += `<div class="combo-slot-nav-botones px-3 pb-3 d-flex justify-content-between">
        <button type="button" class="btn btn-outline-secondary btn-sm" ${hayAnterior ? '' : 'disabled'}
            onclick="_cambiarSlotActivo(${_comboState.slotActivoIdx - 1})">
            <i class="bi bi-chevron-left me-1"></i>Anterior
        </button>
        ${haySiguiente ? `<button type="button" class="btn btn-primary btn-sm"
            onclick="_cambiarSlotActivo(${_comboState.slotActivoIdx + 1})">
            Siguiente<i class="bi bi-chevron-right ms-1"></i>
        </button>` : `<button type="button" class="btn btn-success btn-sm" onclick="_validarYAplicarCombo()">
            <i class="bi bi-check2-circle me-1"></i>Listo
        </button>`}
    </div>`;

    $('#comboCuerpo').html(html);
    _actualizarBtnAplicar();
}

/* ============================================================
   SELECCIONAR ITEM DE SLOT (soporta multi-seleccion)
   ============================================================ */
function _seleccionarItemCombo(slotIdx, item) {
    if (!_comboState) return;
    const slot = _comboState.slots[slotIdx];
    const sels = _comboState.selecciones[slotIdx]; // array

    const existingIdx = sels.findIndex(s => s.id_slot_item === item.id_slot_item);

    if (existingIdx >= 0) {
        // Deseleccionar si ya estaba elegido
        sels.splice(existingIdx, 1);
    } else if (sels.length < slot.max_seleccion) {
        // Agregar al array si hay espacio
        sels.push({
            slotIdx,
            id_slot_item:         item.id_slot_item,
            id_slot:              slot.id,
            id_presentacion:      item.id_presentacion,
            id_catalogo_menu_pos: item.id_catalogo_menu_pos,
            nombre_catalogo:      item.nombre_catalogo,
            tamano_producto:      item.tamano_producto || null,
            es_personalizable:    item.es_personalizable,
            precio_venta:         parseFloat(item.precio_venta) || 0,
            upcharge:             parseFloat(item.upcharge) || 0,
            personalizacion:      _persVacia()
        });
    } else if (slot.max_seleccion === 1) {
        // Reemplazar si el slot solo admite 1
        sels[0] = {
            slotIdx,
            id_slot_item:         item.id_slot_item,
            id_slot:              slot.id,
            id_presentacion:      item.id_presentacion,
            id_catalogo_menu_pos: item.id_catalogo_menu_pos,
            nombre_catalogo:      item.nombre_catalogo,
            tamano_producto:      item.tamano_producto || null,
            es_personalizable:    item.es_personalizable,
            precio_venta:         parseFloat(item.precio_venta) || 0,
            upcharge:             parseFloat(item.upcharge) || 0,
            personalizacion:      _persVacia()
        };
    } else {
        // Limite alcanzado para multi-seleccion
        alertError('Maximo ' + slot.max_seleccion + ' productos para este slot');
        return;
    }

    _actualizarTotalCombo();
    _renderizarNavSlots();
    _renderizarSlotActivo();
}

/* ============================================================
   ABRIR MODAL DE PERSONALIZACION PARA UN ITEM DE COMBO (sub-flujo)
   Recibe slotIdx y selIdx (indice dentro del array de selecciones del slot)
   ============================================================ */
function _abrirPersComboItem(slotIdx, selIdx) {
    if (!_comboState) return;
    const sels = _comboState.selecciones[slotIdx];
    const sel = sels ? sels[selIdx] : null;
    if (!sel || !sel.es_personalizable) return;

    // Guardar estado del subflujo antes de cerrar el modal
    _comboSubflujoPendiente = { slotIdx, selIdx, _lenAntes: detalleFactura.length };

    // Ocultar modal de combo PRIMERO para que la personalizacion quede encima
    if (_comboModalBS) _comboModalBS.hide();

    // Esperar a que el modal de combo termine de cerrarse, luego abrir personalizacion
    setTimeout(function () {
        abrirModalSeleccionProducto(
            [sel.id_catalogo_menu_pos],
            sel.nombre_catalogo,
            sel.id_presentacion,
            sel.personalizacion
        );
    }, 350);
}

/* Edicion de item de combo ya agregado al ticket */
function editarItemCombo(itemIdx, hijoIdx) {
    const item = detalleFactura[itemIdx];
    if (!item || !item.combo_items) return;
    const hijo = item.combo_items[hijoIdx];
    if (!hijo || !hijo.es_personalizable) return;

    _comboState = {
        _modoEdicionTicket: true,
        _editItemIdx: itemIdx,
        _editHijoIdx: hijoIdx
    };
    _comboSubflujoPendiente = { slotIdx: 0, _modoEdicionTicket: true, itemIdx, hijoIdx };

    abrirModalSeleccionProducto(
        [hijo.id_catalogo_menu_pos],
        hijo.nombre_catalogo,
        hijo.id_presentacion,
        hijo.personalizacion
    );
}

/* ============================================================
   HOOK: Capturar el "Aplicar" del modal de personalizacion
   cuando viene de un sub-flujo de combo
   ============================================================ */
$(document).ready(function () {
    var $btnGuardarMSP = $('#mpBtnGuardar');
    if (!$btnGuardarMSP.length) return;

    $btnGuardarMSP.on('click.combo_subflujo', function () {
        if (!_comboSubflujoPendiente) return;

        setTimeout(function () {
            if (typeof _mspBorrador === 'undefined') {
                _comboSubflujoPendiente = null;
                if (_comboModalBS) _comboModalBS.show();
                return;
            }

            var pend = _comboSubflujoPendiente;
            _comboSubflujoPendiente = null;

            if (pend._modoEdicionTicket) {
                // Edicion de hijo ya en el ticket
                var item = detalleFactura[pend.itemIdx];
                if (item && item.combo_items && item.combo_items[pend.hijoIdx]) {
                    item.combo_items[pend.hijoIdx].personalizacion = JSON.parse(JSON.stringify(_mspBorrador));
                    actualizarTablaDetalle();
                }
            } else {
                // Sub-flujo de configuracion de combo:
                // Revertir cualquier item que el handler original haya anadido al ticket
                while (detalleFactura.length > pend._lenAntes) {
                    detalleFactura.pop();
                }

                // Guardar personalizacion en la seleccion del slot
                if (_comboState && _comboState.selecciones) {
                    var sels = _comboState.selecciones[pend.slotIdx];
                    if (sels && sels[pend.selIdx]) {
                        sels[pend.selIdx].personalizacion = JSON.parse(JSON.stringify(_mspBorrador));
                    }
                }

                // Reabrir el modal de combo encima
                if (_comboModalBS) _comboModalBS.show();
                _renderizarSlotActivo();
            }
        }, 200);
    });
});

/* ============================================================
   VALIDAR Y APLICAR COMBO AL TICKET
   ============================================================ */
function _validarYAplicarCombo() {
    if (!_comboState) return;

    var incompletos = [];
    _comboState.slots.forEach(function (slot, idx) {
        var sels = _comboState.selecciones[idx];
        if (slot.min_seleccion > 0 && sels.length < slot.min_seleccion) {
            incompletos.push(slot.nombre + ' (necesita ' + slot.min_seleccion + ', elegido ' + sels.length + ')');
        }
    });

    if (incompletos.length) {
        alertError('Faltan productos por elegir en: ' + incompletos.join(', '));
        return;
    }

    _aplicarCombo();
}

$('#btnAplicarCombo').on('click', function () {
    _validarYAplicarCombo();
});

/* ============================================================
   AGREGAR COMBO A detalleFactura
   ============================================================ */
function _aplicarCombo() {
    if (!_comboState) return;

    // Flatten: todas las selecciones de todos los slots
    var todasLasSels = _comboState.selecciones.flat();
    var totalUpcharges = todasLasSels.reduce(function (acc, sel) {
        return acc + (parseFloat(sel.upcharge) || 0);
    }, 0);
    var precioTotal = Math.round((_comboState.precio_base + totalUpcharges) * 100) / 100;

    var rowKey = ++_rowKeyCounter;

    var comboItems = todasLasSels.map(function (sel) {
        return {
            id_slot:              sel.id_slot,
            id_slot_item:         sel.id_slot_item,
            id_presentacion:      sel.id_presentacion,
            id_catalogo_menu_pos: sel.id_catalogo_menu_pos,
            nombre_catalogo:      sel.nombre_catalogo,
            tamano_producto:      sel.tamano_producto,
            es_personalizable:    sel.es_personalizable,
            precio_venta:         sel.precio_venta,
            upcharge:             sel.upcharge,
            personalizacion:      sel.personalizacion || _persVacia()
        };
    });

    var nuevoCombo = {
        _rowKey:              rowKey,
        id_presentacion:      null,
        id_catalogo_menu_pos: _comboState.id_catalogo,
        nombre:               _comboState.nombre,
        unidad:               'Combo',
        precio_unitario_base: _comboState.precio_base,
        precio_unitario:      precioTotal,
        cantidad:             1,
        costo_total_iva:      precioTotal,
        notas:                '',
        es_personalizable:    false,
        es_canjeado:          false,
        descuento_promo:      0,
        promos_en_item:       [],
        es_condicion_promo:   false,
        qty_condicion:        0,
        puntos_acumulacion:   null,
        puntos_canje:         null,
        personalizacion:      _persVacia(),
        // Campos exclusivos de combo
        es_combo:             true,
        combo_items:          comboItems
    };

    detalleFactura.push(nuevoCombo);
    actualizarTablaDetalle();

    if (_comboModalBS) _comboModalBS.hide();
    _comboState = null;
}

/* ============================================================
   ACTUALIZAR TOTAL DISPLAY DEL MODAL
   ============================================================ */
function _actualizarTotalCombo() {
    if (!_comboState) return;
    var totalUp = _comboState.selecciones.flat().reduce(function (a, s) {
        return a + (parseFloat(s.upcharge) || 0);
    }, 0);
    var total = _comboState.precio_base + totalUp;
    $('#comboTotalDisplay').text('C$ ' + total.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
}

/* ============================================================
   HABILITAR / DESHABILITAR BOTON APLICAR
   ============================================================ */
function _actualizarBtnAplicar() {
    if (!_comboState) return;
    var todoCompleto = _comboState.slots.every(function (slot, idx) {
        return slot.min_seleccion === 0 || _comboState.selecciones[idx].length >= slot.min_seleccion;
    });
    $('#btnAplicarCombo').prop('disabled', !todoCompleto);
}

/* ============================================================
   UTILIDADES
   ============================================================ */
function _persVacia() {
    return {
        ingredientes_quitados:    [],
        ingredientes_sustituidos: [],
        endulzante_id:            null,
        endulzante_nombre:        '',
        nivel_endulzante:         '',
        extras_agregados:         [],
        monto_extras:             0,
        notas:                    ''
    };
}

/* ============================================================
   PATCH a modificarCantidad
   Para combos, recalcular costo_total_iva al cambiar cantidad
   ============================================================ */
(function () {
    var _origModificar = typeof modificarCantidad === 'function' ? modificarCantidad : null;
    if (!_origModificar) return;

    window.modificarCantidad = function (idx, delta) {
        _origModificar(idx, delta);
        var item = detalleFactura[idx];
        if (item && item.es_combo) {
            item.costo_total_iva = Math.round(item.cantidad * item.precio_unitario * 100) / 100;
            actualizarTablaDetalle();
        }
    };
})();
