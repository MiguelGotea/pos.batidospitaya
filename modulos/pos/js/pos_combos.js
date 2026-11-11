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
 *  4. Click en card siempre AGREGA al array del slot (hasta max_seleccion).
 *     El usuario puede agregar el mismo item varias veces. El boton X
 *     de la lista enlistada es la unica forma de quitar.
 *     selecciones es un ARRAY DE ARRAYS: selecciones[slotIdx] = [item, ...].
 *  5. Si un item es personalizable, el modal de pers se abre ENCIMA del
 *     combo modal (z-index: 1075). El combo modal NO se cierra.
 *     Al aplicar, solo guarda la config en memoria; no agrega nada al ticket.
 *  6. btnAplicarCombo valida minimos y agrega el combo completo a detalleFactura.
 *  7. actualizarTablaDetalle (patcheada aqui) renderiza filas padre + hijos.
 */

'use strict';

/* ============================================================
   ESTADO GLOBAL
   ============================================================ */
let _comboState = null;
/*
  _comboState.selecciones es un ARRAY de arrays:
    selecciones[slotIdx] = [ { slotIdx, id_slot_item, ... }, ... ]
  Cada slot soporta 1..max_seleccion items.
*/

let _comboModalBS = null;           // instancia Bootstrap del #modalCombo
/*
  _comboSubflujoPendiente toma dos formas segun el origen:
    - Sub-flujo de configuracion de combo (desde _abrirPersComboItem):
        { slotIdx, selIdx, _lenAntes, _procesandoGuardar? }
    - Edicion de hijo ya en el ticket (desde editarItemCombo):
        { slotIdx:0, _modoEdicionTicket:true, itemIdx, hijoIdx, _lenAntes }
*/
let _comboSubflujoPendiente = null;

/* ============================================================
   PATCH a renderizarCatalogoPorGrupos
   Agrega es_combo al Map de grupos para que cambiarGrupo lo use
   ============================================================ */
(function () {
    var _origRender = typeof renderizarCatalogoPorGrupos === 'function'
        ? renderizarCatalogoPorGrupos : null;

    if (!_origRender) {
        console.warn('[pos_combos] renderizarCatalogoPorGrupos no encontrada.');
        return;
    }

    window.renderizarCatalogoPorGrupos = function (lista) {
        _origRender(lista);
        if (typeof _grupos !== 'undefined' && _grupos instanceof Map) {
            lista.forEach(function (p) {
                var gId = parseInt(p.id_grupo) || 0;
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
    var _origCambiarGrupo = typeof cambiarGrupo === 'function' ? cambiarGrupo : null;

    if (!_origCambiarGrupo) {
        console.warn('[pos_combos] cambiarGrupo no encontrada.');
        return;
    }

    window.cambiarGrupo = function (grupoId) {
        _origCambiarGrupo(grupoId);

        if (typeof _grupos === 'undefined' || !_grupos.has(grupoId)) return;
        var grupo = _grupos.get(grupoId);
        if (!grupo.es_combo) return;

        var html = '<div class="subgrupos-columnas">';
        grupo.subgrupos.forEach(function (sg) {
            html += '<div class="subgrupo-col">';
            html += '<div class="subgrupo-header">' + escHtml(sg.nombre) + '</div>';
            sg.catalogos.forEach(function (cat) {
                var idCatalogo = cat.ids[0];
                html += '<div class="producto-card-pos producto-combo" tabindex="0" role="button"' +
                    ' data-ids="' + escAttr(JSON.stringify(cat.ids)) + '"' +
                    ' data-nombre="' + escAttr(cat.nombre) + '"' +
                    ' onclick="abrirModalCombo(' + idCatalogo + ', \'' + escAttr(cat.nombre) + '\')"' +
                    ' onkeypress="if(event.key===\'Enter\') abrirModalCombo(' + idCatalogo + ', \'' + escAttr(cat.nombre) + '\')">' +
                    '<i class="fas fa-layer-group combo-card-icon me-1"></i>' + escHtml(cat.nombre) +
                    '</div>';
            });
            html += '</div>';
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
        var _origActualizar = typeof actualizarTablaDetalle === 'function'
            ? actualizarTablaDetalle : null;
        if (!_origActualizar) {
            console.warn('[pos_combos] actualizarTablaDetalle no encontrada.');
            return;
        }

        window.actualizarTablaDetalle = function () {
            _origActualizar();

            detalleFactura.forEach(function (item, idx) {
                if (!item.es_combo || !item.combo_items || !item.combo_items.length) return;

                var $filaPadre = $('#fila-' + item._rowKey);
                if ($filaPadre.length) {
                    $filaPadre.find('.item-nombre').prepend(
                        '<span class="badge-combo-padre me-1"><i class="fas fa-layer-group"></i> Combo</span>'
                    );
                }

                var numCols = $('th#thCanjeado:visible').length ? 4 : 3;
                var $ultimaFila = $('tr[data-idx="' + idx + '"]').last();

                item.combo_items.forEach(function (hijo, hijoIdx) {
                    var pers = hijo.personalizacion || {};
                    var tagsParts = [];
                    if (pers.endulzante_nombre) {
                        tagsParts.push('<span class="badge-pers-item"><i class="bi bi-water"></i> ' + escHtml(pers.endulzante_nombre) + '</span>');
                    }
                    if (pers.extras_agregados && pers.extras_agregados.length) {
                        var extrasStr = pers.extras_agregados.map(function (e) { return escHtml(e.nombre); }).join(', ');
                        tagsParts.push('<span class="badge-pers-item badge-pers-extra"><i class="bi bi-plus-circle"></i> ' + extrasStr + '</span>');
                    }
                    if (pers.ingredientes_quitados && pers.ingredientes_quitados.length) {
                        tagsParts.push('<span class="badge-pers-item badge-pers-quitar"><i class="bi bi-x"></i> ' +
                            pers.ingredientes_quitados.map(function (i) { return escHtml(i.nombre_componente); }).join(', ') + '</span>');
                    }
                    if (pers.notas && pers.notas.trim()) {
                        tagsParts.push('<span class="badge-pers-item"><i class="bi bi-chat-left-text"></i> ' + escHtml(pers.notas.trim()) + '</span>');
                    }

                    var upchargeStr = hijo.upcharge > 0
                        ? ' <span class="combo-hijo-upcharge">(+C$ ' + parseFloat(hijo.upcharge).toFixed(2) + ')</span>' : '';

                    var btnEditar = hijo.es_personalizable
                        ? '<button type="button" class="btn-personalizar-fila ms-1" onclick="editarItemCombo(' + idx + ', ' + hijoIdx + ')" title="Personalizar"><i class="bi bi-pencil"></i></button>'
                        : '';

                    var filaHija = '<tr class="fila-combo-hijo" data-combo-idx="' + idx + '" data-hijo-idx="' + hijoIdx + '">' +
                        '<td colspan="' + numCols + '">' +
                        '<div class="combo-hijo-row">' +
                        '<i class="bi bi-arrow-return-right combo-hijo-icon text-muted"></i>' +
                        '<span class="combo-hijo-nombre">' + escHtml(hijo.nombre_catalogo) +
                        (hijo.tamano_producto ? ' <small class="text-muted">' + escHtml(hijo.tamano_producto) + '</small>' : '') +
                        upchargeStr + '</span>' +
                        btnEditar +
                        '</div>' +
                        (tagsParts.length ? '<div class="combo-hijo-tags ps-4">' + tagsParts.join('') + '</div>' : '') +
                        '</td></tr>';

                    $ultimaFila.after(filaHija);
                    $ultimaFila = $('tr.fila-combo-hijo[data-combo-idx="' + idx + '"][data-hijo-idx="' + hijoIdx + '"]');
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
    $('#comboCuerpo').html(
        '<div class="d-flex align-items-center justify-content-center py-5">' +
        '<span class="spinner-border spinner-border-sm me-2 text-secondary"></span>' +
        '<span class="text-muted">Cargando combo...</span></div>'
    );
    $('#comboTotalDisplay').text('C$ 0.00');
    $('#btnAplicarCombo').prop('disabled', true);

    if (!_comboModalBS) {
        _comboModalBS = new bootstrap.Modal(document.getElementById('modalCombo'));
    }
    _comboModalBS.show();

    $.getJSON('/modulos/pos/ajax/get_combo_config.php', { id_catalogo_menu_pos: idCatalogo })
        .done(function (res) {
            if (!res.success) {
                $('#comboCuerpo').html('<div class="alert alert-danger m-3">' + escHtml(res.message || 'Error al cargar el combo.') + '</div>');
                return;
            }
            var combo = res.combo;
            _comboState = {
                id_catalogo:   combo.id_catalogo,
                nombre:        combo.nombre,
                observaciones: combo.observaciones,
                precio_base:   parseFloat(combo.precio_venta) || 0,
                slots:         combo.slots || [],
                selecciones:   (combo.slots || []).map(function () { return []; }),
                slotActivoIdx: 0
            };

            // Pre-seleccionar defaults: push al array del slot
            _comboState.slots.forEach(function (slot, sIdx) {
                var defItem = slot.items.find(function (it) { return it.es_default; });
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
            $('#comboCuerpo').html('<div class="alert alert-danger m-3">Error de conexion al cargar el combo.</div>');
        });
}

/* ============================================================
   RENDERIZAR NAVEGACION DE SLOTS (tabs superiores)
   ============================================================ */
function _renderizarNavSlots() {
    if (!_comboState || !_comboState.slots.length) return;

    var html = '';
    _comboState.slots.forEach(function (slot, idx) {
        var sels    = _comboState.selecciones[idx];
        var completo = slot.min_seleccion > 0 && sels.length >= slot.min_seleccion;
        var activo   = idx === _comboState.slotActivoIdx;
        var cls      = 'combo-slot-tab' + (activo ? ' active' : '') + (completo ? ' completo' : '');
        html += '<button type="button" class="' + cls + '" onclick="_cambiarSlotActivo(' + idx + ')">' +
            (completo
                ? '<i class="bi bi-check-circle-fill me-1 text-success"></i>'
                : '<span class="slot-num">' + (idx + 1) + '</span>') +
            ' ' + escHtml(slot.nombre) +
            '</button>';
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
    var slotIdx = _comboState.slotActivoIdx;
    var slot    = _comboState.slots[slotIdx];
    if (!slot) return;

    var selsActuales = _comboState.selecciones[slotIdx]; // array

    // Badge de progreso
    var minMaxLabel = (slot.min_seleccion === slot.max_seleccion)
        ? 'Elige ' + slot.min_seleccion
        : 'Elige entre ' + slot.min_seleccion + ' y ' + slot.max_seleccion;
    var progresoLabel = slot.max_seleccion > 1
        ? ' (' + selsActuales.length + '/' + slot.max_seleccion + ')' : '';

    var html = '<div class="combo-slot-cuerpo p-3">';
    html += '<div class="d-flex align-items-center mb-2">' +
        '<strong class="me-2">' + escHtml(slot.nombre) + '</strong>' +
        '<span class="badge bg-secondary combo-minmax-badge">' + escHtml(minMaxLabel) + progresoLabel + '</span>' +
        '</div>';

    if (slot.descripcion) {
        html += '<p class="text-muted small mb-2">' + escHtml(slot.descripcion) + '</p>';
    }

    if (!slot.items.length) {
        html += '<div class="alert alert-warning">Este slot no tiene productos configurados.</div>';
    } else {
        // Grid de opciones: sin icono de lapiz, solo nombre + tamaño + upcharge
        // Click SIEMPRE agrega (hasta max_seleccion). Quitar desde la lista enlistada.
        var yaLleno = selsActuales.length >= slot.max_seleccion;

        html += '<div class="combo-items-grid">';
        slot.items.forEach(function (item) {
            // Resaltar si ya fue elegido al menos una vez
            var veces = selsActuales.filter(function (s) { return s.id_slot_item === item.id_slot_item; }).length;
            var esSel = veces > 0;

            var upchargeStr = item.upcharge > 0
                ? '<span class="combo-item-upcharge">+C$ ' + parseFloat(item.upcharge).toFixed(2) + '</span>' : '';
            var tamanoStr = item.tamano_producto
                ? '<small class="combo-item-tamano">' + escHtml(item.tamano_producto) + '</small>' : '';

            // Cursor de "no-drop" si ya esta lleno y no es el mismo item que podria reemplazarse
            var cardCls = 'combo-item-card' + (esSel ? ' seleccionado' : '') + (yaLleno && !esSel ? ' agotado' : '');

            // Serializar item para pasar al onclick (evitar problemas con comillas)
            var itemJson = JSON.stringify(item).replace(/"/g, '&quot;');

            html += '<div class="' + cardCls + '"' +
                ' onclick="_agregarItemCombo(' + slotIdx + ', ' + itemJson + ')"' +
                ' role="button" tabindex="0"' +
                ' onkeypress="if(event.key===\'Enter\') _agregarItemCombo(' + slotIdx + ', ' + itemJson + ')">' +
                '<div class="combo-item-nombre">' + escHtml(item.nombre_catalogo) + ' ' + tamanoStr + '</div>' +
                '<div class="combo-item-meta">' + upchargeStr + '</div>' +
                '</div>';
        });
        html += '</div>';

        // Lista de items ya seleccionados con boton X (quitar) y Personalizar si aplica
        if (selsActuales.length > 0) {
            html += '<div class="combo-sel-lista mt-2">';
            selsActuales.forEach(function (sel, selIdx) {
                var persBtn = sel.es_personalizable
                    ? '<button type="button" class="btn btn-sm btn-outline-secondary combo-sel-pers-btn"' +
                      ' onclick="event.stopPropagation();_abrirPersComboItem(' + slotIdx + ',' + selIdx + ')">' +
                      '<i class="bi bi-pencil me-1"></i>Personalizar</button>'
                    : '';
                // Indicador de que tiene personalizacion guardada
                var persBadge = '';
                if (sel.personalizacion) {
                    var p = sel.personalizacion;
                    var tieneConfig = (p.endulzante_id || (p.extras_agregados && p.extras_agregados.length) ||
                        (p.ingredientes_quitados && p.ingredientes_quitados.length) || (p.notas && p.notas.trim()));
                    if (tieneConfig) {
                        persBadge = '<span class="badge bg-success ms-1" style="font-size:.65rem">personalizado</span>';
                    }
                }
                html += '<div class="combo-sel-item d-flex align-items-center justify-content-between py-1 border-bottom">' +
                    '<span class="small"><i class="bi bi-check-circle-fill text-success me-1"></i>' +
                    escHtml(sel.nombre_catalogo) +
                    (sel.tamano_producto ? ' <small class="text-muted">' + escHtml(sel.tamano_producto) + '</small>' : '') +
                    persBadge +
                    '</span>' +
                    '<div class="d-flex gap-1 align-items-center">' +
                    persBtn +
                    '<button type="button" class="btn btn-sm btn-outline-danger combo-sel-quitar-btn"' +
                    ' onclick="event.stopPropagation();_quitarItemCombo(' + slotIdx + ',' + selIdx + ')"' +
                    ' title="Quitar"><i class="bi bi-x-lg"></i></button>' +
                    '</div>' +
                    '</div>';
            });
            html += '</div>';
        }
    }

    html += '</div>'; // combo-slot-cuerpo

    // Botones de navegacion (solo si hay mas de 1 slot)
    if (_comboState.slots.length > 1) {
        var hayAnterior  = slotIdx > 0;
        var haySiguiente = slotIdx < _comboState.slots.length - 1;
        html += '<div class="combo-slot-nav-botones px-3 pb-3 d-flex justify-content-between">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm"' +
            (hayAnterior ? '' : ' disabled') +
            ' onclick="_cambiarSlotActivo(' + (slotIdx - 1) + ')">' +
            '<i class="bi bi-chevron-left me-1"></i>Anterior</button>';

        if (haySiguiente) {
            html += '<button type="button" class="btn btn-primary btn-sm"' +
                ' onclick="_cambiarSlotActivo(' + (slotIdx + 1) + ')">' +
                'Siguiente<i class="bi bi-chevron-right ms-1"></i></button>';
        } else {
            html += '<span></span>';
        }
        html += '</div>';
    }

    $('#comboCuerpo').html(html);
    _actualizarBtnAplicar();
}

/* ============================================================
   AGREGAR ITEM AL SLOT (siempre agrega, hasta max_seleccion)
   El usuario puede agregar el mismo item varias veces.
   Para quitar usa el boton X de la lista enlistada.
   ============================================================ */
function _agregarItemCombo(slotIdx, item) {
    if (!_comboState) return;
    var slot = _comboState.slots[slotIdx];
    var sels = _comboState.selecciones[slotIdx];

    if (sels.length >= slot.max_seleccion) {
        alertError('Ya elegiste el maximo de ' + slot.max_seleccion + ' producto(s) para este slot. Usa el boton X para quitar uno.');
        return;
    }

    sels.push({
        slotIdx:              slotIdx,
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

    _actualizarTotalCombo();
    _renderizarNavSlots();
    _renderizarSlotActivo();
}

/* ============================================================
   QUITAR ITEM DE LA LISTA DEL SLOT (boton X)
   ============================================================ */
function _quitarItemCombo(slotIdx, selIdx) {
    if (!_comboState) return;
    _comboState.selecciones[slotIdx].splice(selIdx, 1);
    _actualizarTotalCombo();
    _renderizarNavSlots();
    _renderizarSlotActivo();
}

/* ============================================================
   ABRIR MODAL DE PERSONALIZACION PARA UN ITEM YA SELECCIONADO
   El combo modal NO se cierra. El modal de pers se abre con
   z-index mayor para quedar encima. Al aplicar, solo guarda la
   config en memoria (NO agrega nada al ticket todavia).
   ============================================================ */
function _abrirPersComboItem(slotIdx, selIdx) {
    if (!_comboState) return;
    var sels = _comboState.selecciones[slotIdx];
    var sel  = sels ? sels[selIdx] : null;
    if (!sel || !sel.es_personalizable) return;

    // Marcar el subflujo antes de abrir el modal de pers
    _comboSubflujoPendiente = {
        slotIdx:   slotIdx,
        selIdx:    selIdx,
        _lenAntes: detalleFactura.length   // para revertir si el handler original agrega algo
    };

    // Elevar z-index del modal de personalización inmediatamente para que quede delante del combo
    var $mp = $('#modalPersonalizacion');
    $mp.addClass('modal-sobre-combo').css('z-index', 1075);

    // Abrir el modal de personalizacion (el combo modal NO se cierra)
    abrirModalSeleccionProducto(
        [sel.id_catalogo_menu_pos],
        sel.nombre_catalogo,
        sel.id_presentacion,
        sel.personalizacion
    );

    // Reforzar z-index del modal y su backdrop para garantizar que quede delante
    setTimeout(function () {
        var $mp = $('#modalPersonalizacion');
        if ($mp.length) {
            $mp.addClass('modal-sobre-combo').css('z-index', 1075);
            $('.modal-backdrop').last().addClass('backdrop-sobre-combo').css('z-index', 1070);
        }
    }, 50);

    setTimeout(function () {
        var $mp = $('#modalPersonalizacion');
        if ($mp.length) {
            $mp.addClass('modal-sobre-combo').css('z-index', 1075);
            $('.modal-backdrop').last().addClass('backdrop-sobre-combo').css('z-index', 1070);
        }
    }, 200);
}

/* ============================================================
   HOOK: Capturar "Aplicar" del modal de personalizacion
   cuando viene de un sub-flujo de combo.
   - Revierte cualquier item que el handler original haya metido
     al ticket (el combo modal sigue abierto, no queremos facturas
     individuales del combo todavia).
   - Guarda la personalizacion en la seleccion del slot.
   - Restaura z-indices y re-renderiza el slot.
   ============================================================ */
$(document).ready(function () {
    var $mpModal = $('#modalPersonalizacion');
    var $btnGuardarMSP = $('#mpBtnGuardar');

    // Eventos Bootstrap en modalPersonalizacion para apilamiento limpio sobre modalCombo
    if ($mpModal.length) {
        $mpModal.on('show.bs.modal shown.bs.modal', function () {
            if ($('#modalCombo').hasClass('show') || _comboSubflujoPendiente) {
                $(this).addClass('modal-sobre-combo').css('z-index', 1075);
                $('.modal-backdrop').last().addClass('backdrop-sobre-combo').css('z-index', 1070);
            }
        });

        $mpModal.on('hidden.bs.modal', function () {
            $(this).removeClass('modal-sobre-combo').css('z-index', '');
            $('.modal-backdrop').removeClass('backdrop-sobre-combo').css('z-index', '');

            // Si el modal de combo sigue abierto, asegurar scroll y clase modal-open en body
            if ($('#modalCombo').hasClass('show')) {
                $('body').addClass('modal-open');
                $('#modalCombo').css('overflow-y', 'auto');
            }

            // Si se cerró sin guardar (ej. Cancelar o botón X), liberar subflujo pendiente
            setTimeout(function () {
                if (_comboSubflujoPendiente && !_comboSubflujoPendiente._procesandoGuardar) {
                    _comboSubflujoPendiente = null;
                }
            }, 200);
        });
    }

    if (!$btnGuardarMSP.length) return;

    $btnGuardarMSP.on('click.combo_subflujo', function () {
        if (!_comboSubflujoPendiente) return; // no es sub-flujo de combo

        _comboSubflujoPendiente._procesandoGuardar = true;

        // Esperar brevemente a que el handler original termine
        setTimeout(function () {
            var pend = _comboSubflujoPendiente;
            _comboSubflujoPendiente = null;

            // 1. Revertir SIEMPRE cualquier item agregado incorrectamente al ticket
            while (detalleFactura.length > pend._lenAntes) {
                detalleFactura.pop();
            }
            // Re-renderizar sin los items revertidos
            actualizarTablaDetalle();

            // Obtener personalización configurada (en facturas_nueva.js es _mpDraft)
            var borrador = (typeof _mpDraft !== 'undefined' && _mpDraft)
                ? _mpDraft
                : ((typeof _mspBorrador !== 'undefined' && _mspBorrador) ? _mspBorrador : _persVacia());

            if (pend._modoEdicionTicket) {
                // Edicion de hijo ya guardado en el ticket (desde actualizarTablaDetalle)
                var item = detalleFactura[pend.itemIdx];
                if (item && item.combo_items && item.combo_items[pend.hijoIdx]) {
                    item.combo_items[pend.hijoIdx].personalizacion = JSON.parse(JSON.stringify(borrador));
                    actualizarTablaDetalle();
                }
            } else {
                // Sub-flujo de configuracion de combo: guardar pers en el slot
                if (_comboState && _comboState.selecciones) {
                    var sels = _comboState.selecciones[pend.slotIdx];
                    if (sels && typeof sels[pend.selIdx] !== 'undefined') {
                        sels[pend.selIdx].personalizacion = JSON.parse(JSON.stringify(borrador));
                    }
                }
            }

            // 2. Restaurar z-indices al estado normal
            $('#modalPersonalizacion').removeClass('modal-sobre-combo').css('z-index', '');
            $('.modal-backdrop').removeClass('backdrop-sobre-combo').css('z-index', '');

            // Si el combo sigue visible, conservar modal-open en body
            if ($('#modalCombo').hasClass('show')) {
                $('body').addClass('modal-open');
                $('#modalCombo').css('overflow-y', 'auto');
            }

            // 3. Re-renderizar el slot para mostrar el badge "personalizado"
            _renderizarSlotActivo();

        }, 150);
    });
});

/* ============================================================
   Edicion de item de combo ya agregado al TICKET (desde la tabla)
   ============================================================ */
function editarItemCombo(itemIdx, hijoIdx) {
    var item = detalleFactura[itemIdx];
    if (!item || !item.combo_items) return;
    var hijo = item.combo_items[hijoIdx];
    if (!hijo || !hijo.es_personalizable) return;

    _comboState = { _modoEdicionTicket: true };
    _comboSubflujoPendiente = {
        slotIdx:            0,
        _modoEdicionTicket: true,
        itemIdx:            itemIdx,
        hijoIdx:            hijoIdx,
        _lenAntes:          detalleFactura.length
    };

    abrirModalSeleccionProducto(
        [hijo.id_catalogo_menu_pos],
        hijo.nombre_catalogo,
        hijo.id_presentacion,
        hijo.personalizacion
    );
}

/* ============================================================
   VALIDAR Y APLICAR COMBO AL TICKET
   ============================================================ */
function _validarYAplicarCombo() {
    if (!_comboState) return;

    var incompletos = [];
    _comboState.slots.forEach(function (slot, idx) {
        var sels = _comboState.selecciones[idx];
        if (slot.min_seleccion > 0 && sels.length < slot.min_seleccion) {
            incompletos.push(slot.nombre + ' (necesitas ' + slot.min_seleccion + ', elegiste ' + sels.length + ')');
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
   AGREGAR COMBO COMPLETO A detalleFactura
   ============================================================ */
function _aplicarCombo() {
    if (!_comboState) return;

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
   HABILITAR / DESHABILITAR BOTON "Agregar al Pedido"
   ============================================================ */
function _actualizarBtnAplicar() {
    if (!_comboState) return;
    var esUltimoSlot = _comboState.slotActivoIdx === (_comboState.slots.length - 1);
    var todoCompleto = _comboState.slots.every(function (slot, idx) {
        return slot.min_seleccion === 0 || _comboState.selecciones[idx].length >= slot.min_seleccion;
    });
    $('#btnAplicarCombo').prop('disabled', !(esUltimoSlot && todoCompleto));
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
