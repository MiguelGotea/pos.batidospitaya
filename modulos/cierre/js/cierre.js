/* ============================================================
   cierre.js — Lógica del Módulo Cierre de Turno POS
   ============================================================ */

'use strict';

$(function () {

    /* ── Config ───────────────────────────────────────────────── */
    const CFG        = window.CIERRE_CONFIG || {};
    const TC         = parseFloat(CFG.TC || 36.6);
    const TOLERANCIA = CFG.TOLERANCIA || 1;
    const MAX_RECONTEOS = CFG.MAX_RECONTEOS || 3;
    const DENOMS_NIO = CFG.DENOMS_NIO || [0.5,1,5,10,20,50,100,200,500];
    const DENOMS_USD = CFG.DENOMS_USD || [1,5,10,20,50,100];

    /* ── Estado ───────────────────────────────────────────────── */
    let cierreId     = null;
    let conceptosData = {};     // resultados del servidor
    let conceptoEnReconteo = null;

    /* ── Helpers formato ──────────────────────────────────────── */
    const fmtNIO = n => 'C$ ' + parseFloat(n||0).toLocaleString('es-NI', {minimumFractionDigits:2, maximumFractionDigits:2});
    const fmtUSD = n => '$ '  + parseFloat(n||0).toLocaleString('es-NI', {minimumFractionDigits:2, maximumFractionDigits:2});

    /* ─────────────────────────────────────────────────────────────
       CONSTRUCCIÓN TABLAS DE DENOMINACIÓN
       ───────────────────────────────────────────────────────────── */
    function buildRowDenom(denom, moneda, prefijo) {
        const cls    = moneda === 'NIO' ? '' : 'usd';
        const labelF = moneda === 'NIO'
            ? (denom < 1 ? denom.toFixed(2) : denom.toFixed(2).replace('.00',''))
            : denom;
        const idKey = String(denom).replace('.','_');
        return `
        <tr data-moneda="${moneda}" data-denom="${denom}">
            <td><span class="denom-label ${cls}"><span class="denom-tag">${labelF}</span></span></td>
            <td class="text-center">
                <input type="number" class="ci-input-qty qty-input"
                    min="0" step="1" value="0"
                    data-moneda="${moneda}" data-denom="${denom}"
                    id="${prefijo}_${moneda}_${idKey}" autocomplete="off">
            </td>
            <td class="text-end ci-row-total" id="${prefijo}_total_${moneda}_${idKey}">
                ${moneda === 'NIO' ? fmtNIO(0) : fmtUSD(0)}
            </td>
        </tr>`;
    }

    function construirTablasDenoms(prefijo, cuerpoNIO, cuerpoUSD) {
        DENOMS_NIO.forEach(d => $(cuerpoNIO).append(buildRowDenom(d, 'NIO', prefijo)));
        DENOMS_USD.forEach(d => $(cuerpoUSD).append(buildRowDenom(d, 'USD', prefijo)));
    }

    function recalcularEfectivo(prefijo) {
        let totalNIO = 0, totalUSD = 0;
        $(`[id^="${prefijo}_NIO"].qty-input, .qty-input[data-moneda="NIO"]`).filter(function(){
            return $(this).attr('id').startsWith(prefijo);
        }).each(function(){
            const d = parseFloat($(this).data('denom'));
            const q = parseInt($(this).val()) || 0;
            const sub = d * q;
            totalNIO += sub;
            const key = String(d).replace('.','_');
            $(`#${prefijo}_total_NIO_${key}`).text(fmtNIO(sub));
        });
        $(`[id^="${prefijo}_USD"].qty-input, .qty-input[data-moneda="USD"]`).filter(function(){
            return $(this).attr('id').startsWith(prefijo);
        }).each(function(){
            const d = parseFloat($(this).data('denom'));
            const q = parseInt($(this).val()) || 0;
            const sub = d * q;
            totalUSD += sub;
            const key = String(d).replace('.','_');
            $(`#${prefijo}_total_USD_${key}`).text(fmtUSD(sub));
        });
        return {totalNIO, totalUSD, totalGlobal: totalNIO + totalUSD * TC};
    }

    // Tabla inicial (conteo ciego)
    construirTablasDenoms('ci', '#tablaNIOBody', '#tablaUSDBody');

    $(document).on('input change', '#tablaNIOBody .qty-input, #tablaUSDBody .qty-input', function(){
        let v = parseInt($(this).val());
        if (isNaN(v) || v < 0) $(this).val(0);
        const totales = recalcularEfectivo('ci');
        $('#subtotalNIO').text(fmtNIO(totales.totalNIO));
        $('#subtotalUSD').text(fmtUSD(totales.totalUSD));
        $('#subtotalUSDenNIO').text(fmtNIO(totales.totalUSD * TC));
        $('#totalEfectivoContado').text(fmtNIO(totales.totalGlobal));
    });

    $(document).on('focus', '.qty-input', function(){
        if ($(this).val() == '0') $(this).val('');
    });
    $(document).on('blur', '.qty-input', function(){
        if ($(this).val() === '') $(this).val(0);
    });

    /* ─────────────────────────────────────────────────────────────
       INICIAR CIERRE
       ───────────────────────────────────────────────────────────── */
    $('#btnIniciarCierre').on('click', function(){
        const btn = $(this).prop('disabled', true).text('Iniciando…');
        $.ajax({
            url:         'ajax/cierre_iniciar.php',
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify({accion: 'iniciar'}),
            dataType:    'json'
        }).done(function(res){
            if (!res.ok) {
                if (res.error === 'sin_tc') {
                    Swal.fire('Sin Tipo de Cambio', res.mensaje, 'warning');
                } else {
                    Swal.fire('Error', res.mensaje || 'Error al iniciar.', 'error');
                }
                btn.prop('disabled', false).html('<i class="bi bi-lock-fill"></i> Iniciar Cierre de Turno');
                return;
            }
            cierreId = res.cierre.id;
            mostrarPanelCierre(res.cierre);
            if (res.estado === 'retomado') {
                Swal.fire({
                    title: 'Cierre retomado',
                    text: 'Se retomó el cierre en curso para este turno.',
                    icon: 'info',
                    confirmButtonColor: '#51B8AC',
                    timer: 2500,
                    showConfirmButton: false
                });
                if (res.tiene_conteo_inicial) {
                    // Ya existe conteo → pedir resultados directamente
                    solicitarResultadosExistentes();
                }
            }
        }).fail(function(){
            Swal.fire('Error de red', 'No se pudo conectar.', 'error');
            btn.prop('disabled', false).html('<i class="bi bi-lock-fill"></i> Iniciar Cierre de Turno');
        });
    });

    function mostrarPanelCierre(cierre) {
        $('#btnIniciarCierre').parent().hide();
        $('#panelCierre').show();
        const fmtDT = dt => {
            if (!dt) return '—';
            const d = new Date(dt.replace(' ', 'T'));
            return d.toLocaleDateString('es-NI') + ' ' + d.toLocaleTimeString('es-NI', {hour:'2-digit',minute:'2-digit'});
        };
        $('#infoTurno').html(`
            <strong>Turno #${cierre.numero_turno}</strong>
            &nbsp;|&nbsp;
            <span>Inicio del rango: <strong>${fmtDT(cierre.corte_anterior)}</strong></span>
            &nbsp;|&nbsp;
            <span>Fin del rango (corte): <strong>${fmtDT(cierre.corte_actual)}</strong></span>
            &nbsp;|&nbsp;
            <span>TC del día: <strong>C$ ${parseFloat(cierre.tipo_cambio_dia).toFixed(2)}/USD</strong></span>
        `);
    }

    /* ─────────────────────────────────────────────────────────────
       BOTÓN VER FACTURAS PEDIDOSYA
       ───────────────────────────────────────────────────────────── */
    $('#btnVerFacturasPY').on('click', function(){
        if (!cierreId) return;
        $('#modalPedidosYaBody').html('<div class="text-center py-4"><div class="spinner-border"></div></div>');
        $('#modalPedidosYa').modal('show');
        $.get('ajax/cierre_get_pedidosya_facturas.php', {cierre_id: cierreId}, function(res){
            if (!res.ok) {
                $('#modalPedidosYaBody').html('<div class="alert alert-danger">' + res.mensaje + '</div>');
                return;
            }
            const rows = res.facturas.map(f => {
                const dt = new Date(f.fecha_hora.replace(' ','T'));
                return `<tr>
                    <td>#${f.id}</td>
                    <td>${dt.toLocaleTimeString('es-NI',{hour:'2-digit',minute:'2-digit'})}</td>
                    <td>${f.cliente || 'General'}</td>
                    <td class="text-end">${fmtNIO(f.total)}</td>
                </tr>`;
            }).join('');
            $('#modalPedidosYaBody').html(rows
                ? `<table class="table table-sm"><thead><tr><th>#</th><th>Hora</th><th>Cliente</th><th class="text-end">Total</th></tr></thead><tbody>${rows}</tbody></table>`
                : '<p class="text-center text-muted py-3">No hay facturas PedidosYa en este turno.</p>'
            );
            $('#totalEsperadoPY').text(fmtNIO(res.total_esperado));
        }, 'json').fail(function(){
            $('#modalPedidosYaBody').html('<div class="alert alert-danger">Error de red.</div>');
        });
    });

    /* ─────────────────────────────────────────────────────────────
       GUARDAR CONTEO INICIAL (CIEGO)
       ───────────────────────────────────────────────────────────── */
    $('#btnGuardarConteo').on('click', function(){
        if (!cierreId) return;

        const payload = construirPayloadConteo(0, ['efectivo','cupones','transferencia','tarjeta','pedidosya']);

        Swal.fire({
            title: '¿Guardar conteo?',
            html: 'Una vez guardado, se revelarán los esperados.<br>Este paso no se puede deshacer.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#51B8AC',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar'
        }).then(result => {
            if (!result.isConfirmed) return;
            enviarConteo(payload);
        });
    });

    function construirPayloadConteo(intento, conceptos) {
        const efectivoNIO = [];
        const efectivoUSD = [];

        if (conceptos.includes('efectivo')) {
            const pref = intento === 0 ? 'ci' : 'rc';
            DENOMS_NIO.forEach(d => {
                const key = String(d).replace('.','_');
                const qty = parseInt($(`#${pref}_NIO_${key}`).val()) || 0;
                efectivoNIO.push({denominacion: d, cantidad: qty});
            });
            DENOMS_USD.forEach(d => {
                const key = String(d).replace('.','_');
                const qty = parseInt($(`#${pref}_USD_${key}`).val()) || 0;
                efectivoUSD.push({denominacion: d, cantidad: qty});
            });
        }

        return {
            cierre_id:            cierreId,
            intento:              intento,
            efectivo_nio:         efectivoNIO,
            efectivo_usd:         efectivoUSD,
            cupones_cantidad:     parseInt($('#cuponesQty').val())          || 0,
            cupones_monto:        parseFloat($('#cuponesMonto').val())       || 0,
            transferencia_monto:  parseFloat($('#transferenciaMonto').val()) || 0,
            tarjeta_monto:        parseFloat($('#tarjetaMonto').val())       || 0,
            pedidosya_monto:      parseFloat($('#pedidosyaMonto').val())     || 0,
            conceptos_a_recontar: conceptos,
        };
    }

    function enviarConteo(payload) {
        const btn = $('#btnGuardarConteo').prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando…');
        $.ajax({
            url:         'ajax/cierre_guardar_conteo.php',
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify(payload),
            dataType:    'json'
        }).done(function(res){
            if (!res.ok) {
                Swal.fire('Error', res.mensaje || 'Error al guardar.', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Conteo');
                return;
            }
            conceptosData = res.conceptos;
            mostrarResultados(conceptosData);
        }).fail(function(){
            Swal.fire('Error de red', 'No se pudo conectar.', 'error');
            btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Conteo');
        });
    }

    function solicitarResultadosExistentes() {
        // Para retomar un cierre que ya tiene conteo 0 → enviar payload vacío con intento 0
        // El servidor detectará que ya existe y sólo revelará
        enviarConteo({
            cierre_id: cierreId,
            intento: 0,
            efectivo_nio: [],
            efectivo_usd: [],
            cupones_cantidad: 0, cupones_monto: 0,
            transferencia_monto: 0, tarjeta_monto: 0, pedidosya_monto: 0,
            conceptos_a_recontar: ['efectivo','cupones','transferencia','tarjeta','pedidosya'],
        });
    }

    /* ─────────────────────────────────────────────────────────────
       MOSTRAR RESULTADOS
       ───────────────────────────────────────────────────────────── */
    function mostrarResultados(conceptos) {
        $('#pasoConteoInicial').hide();
        $('#pasoResultados').show();

        const nombres = {
            efectivo: 'Efectivo (C$ + USD)',
            cupones:  'Cupones',
            transferencia: 'Transferencia Bancaria',
            tarjeta:  'Tarjeta / POS Banco',
            pedidosya: 'PedidosYa',
        };

        let hayFueraTolerancia = false;

        let html = `<table class="tabla-resultados">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th class="text-end">Esperado</th>
                    <th class="text-end">Contado</th>
                    <th class="text-end">Diferencia</th>
                    <th class="text-center">Estado</th>
                    <th class="text-center">Acciones</th>
                </tr>
            </thead><tbody>`;

        Object.entries(conceptos).forEach(([conc, d]) => {
            const ok     = d.dentro_tolerancia;
            const cls    = ok ? 'res-ok' : 'res-fuera';
            const badge  = ok
                ? '<span class="badge-cuadra">✓ Cuadra</span>'
                : '<span class="badge-fuera-tol">✗ Fuera de tolerancia</span>';
            const dif    = parseFloat(d.diferencia);
            const difCls = dif < 0 ? 'text-faltante' : (dif > 0 ? 'text-sobrante' : '');
            const difStr = (dif > 0 ? '+' : '') + fmtNIO(dif);
            const intentosUsados = parseInt(d.intento_actual) || 0;
            const intentosRestantes = MAX_RECONTEOS - intentosUsados;
            const puedeRecontar = !ok && intentosRestantes > 0;

            let extraCupones = '';
            if (conc === 'cupones') {
                const difCant = parseInt(d.diferencia_cant) || 0;
                extraCupones = `<br><small class="text-muted">Cant. esperada: ${d.cantidad_esperada} | Contada: ${d.cantidad_contada} | Dif: ${difCant > 0 ? '+' : ''}${difCant}</small>`;
            }

            if (!ok) hayFueraTolerancia = true;

            html += `<tr class="${cls}">
                <td>${nombres[conc] || conc}</td>
                <td class="text-end">${fmtNIO(d.esperado)}${extraCupones}</td>
                <td class="text-end">${fmtNIO(d.contado)}</td>
                <td class="text-end ${difCls}">${difStr}</td>
                <td class="text-center">${badge}</td>
                <td class="text-center">
                    ${puedeRecontar
                        ? `<button class="btn-recontar" data-concepto="${conc}" data-intento="${intentosUsados + 1}"
                            title="${intentosRestantes} reconteo(s) restantes">
                            <i class="bi bi-arrow-repeat"></i> Recontar (${intentosRestantes})
                           </button>`
                        : (!ok
                            ? '<span class="text-muted small">Sin reconteos</span>'
                            : '—')
                    }
                </td>
            </tr>`;
        });

        html += '</tbody></table>';
        $('#tablaResultados').html(html);

        // Comentarios para conceptos fuera de tolerancia
        actualizarPanelComentarios(conceptos);

        // Habilitar botón confirmar si no hay fuera de tolerancia sin comentario
        verificarPuedeConfirmar();
    }

    function actualizarPanelComentarios(conceptos) {
        const fueraTol = Object.entries(conceptos).filter(([,d]) => !d.dentro_tolerancia);
        if (fueraTol.length === 0) {
            $('#panelComentarios').hide();
            return;
        }
        $('#panelComentarios').show();
        const nombres = {
            efectivo: 'Efectivo', cupones: 'Cupones',
            transferencia: 'Transferencia', tarjeta: 'Tarjeta/POS', pedidosya: 'PedidosYa',
        };
        let html = '';
        fueraTol.forEach(([conc]) => {
            html += `
            <div class="mb-3">
                <label class="form-label fw-bold text-danger small">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    Comentario para ${nombres[conc] || conc} (obligatorio):
                </label>
                <textarea class="form-control comentario-concepto" data-concepto="${conc}"
                    rows="2" placeholder="Describe la razón de la diferencia…"></textarea>
            </div>`;
        });
        $('#listaComentarios').html(html);
        $('#listaComentarios').off('input.cierreChk').on('input.cierreChk', '.comentario-concepto', verificarPuedeConfirmar);
    }

    function verificarPuedeConfirmar() {
        const fueraTol = Object.entries(conceptosData).filter(([,d]) => !d.dentro_tolerancia);
        const todosComentados = fueraTol.every(([conc]) => {
            const val = $(`.comentario-concepto[data-concepto="${conc}"]`).val()?.trim();
            return val && val.length > 0;
        });
        $('#btnConfirmarCierre').prop('disabled', !todosComentados && fueraTol.length > 0);
        if (fueraTol.length === 0) $('#btnConfirmarCierre').prop('disabled', false);
    }

    /* ─────────────────────────────────────────────────────────────
       RECONTEO
       ───────────────────────────────────────────────────────────── */
    $(document).on('click', '.btn-recontar', function(){
        const conc    = $(this).data('concepto');
        const intento = parseInt($(this).data('intento'));
        conceptoEnReconteo = conc;

        const nombres = {
            efectivo: 'Efectivo', cupones: 'Cupones',
            transferencia: 'Transferencia', tarjeta: 'Tarjeta/POS', pedidosya: 'PedidosYa',
        };
        $('#lblReconteoConcepto').text(nombres[conc] || conc);
        $('#lblIntentosRestantes').text(`Intento ${intento} de ${MAX_RECONTEOS}`);

        // Construir contenido de reconteo
        let contenido = '';
        if (conc === 'efectivo') {
            contenido = `
            <div class="ci-layout-tablas">
                <div class="ci-card">
                    <table class="ci-table"><thead>
                        <tr><th style="width:40%">Denominación</th><th class="text-center" style="width:30%">Cantidad</th><th class="text-end" style="width:30%">Total</th></tr>
                    </thead><tbody id="rcNIOBody"></tbody>
                    <tfoot><tr class="ci-subtotal-row"><td colspan="2" class="subtotal-label">Total NIO</td><td class="subtotal-value" id="rcSubNIO">C$ 0.00</td></tr></tfoot>
                    </table>
                </div>
                <div class="ci-card">
                    <table class="ci-table"><thead>
                        <tr><th style="width:40%">Denominación</th><th class="text-center" style="width:30%">Cantidad</th><th class="text-end" style="width:30%">Total</th></tr>
                    </thead><tbody id="rcUSDBody"></tbody>
                    <tfoot>
                        <tr class="ci-subtotal-row"><td colspan="2" class="subtotal-label">Total USD</td><td class="subtotal-value usd-val" id="rcSubUSD">$ 0.00</td></tr>
                        <tr class="ci-subtotal-row"><td colspan="2" class="subtotal-label">USD en C$</td><td class="subtotal-value" id="rcSubUSDenNIO">C$ 0.00</td></tr>
                    </tfoot></table>
                </div>
            </div>
            <div class="ci-total-global-bar mt-2">
                <span>Total Efectivo Contado</span>
                <span id="rcTotalEfectivo" class="global-amount">C$ 0.00</span>
            </div>`;
        } else if (conc === 'cupones') {
            contenido = `
            <div class="d-flex gap-3">
                <div>
                    <label class="form-label small fw-bold">Cantidad de cupones</label>
                    <input type="number" id="rcCuponesQty" class="form-control cierre-input-num" min="0" step="1" value="0">
                </div>
                <div>
                    <label class="form-label small fw-bold">Monto total C$</label>
                    <input type="number" id="rcCuponesMonto" class="form-control cierre-input-num" min="0" step="1" value="0">
                </div>
            </div>`;
        } else {
            const labels = {
                transferencia: 'Total Transferencia C$',
                tarjeta: 'Total Voucher Datáfono C$',
                pedidosya: 'Total PedidosYa C$',
            };
            contenido = `
            <div>
                <label class="form-label fw-bold">${labels[conc] || 'Monto C$'}</label>
                <input type="number" id="rcMontoSimple" class="form-control cierre-input-num" min="0" step="1" value="0">
            </div>`;
        }

        $('#panelReconteoContenido').html(contenido);
        $('#panelReconteo').show();

        if (conc === 'efectivo') {
            construirTablasDenoms('rc', '#rcNIOBody', '#rcUSDBody');
            $(document).on('input change.rc', '#rcNIOBody .qty-input, #rcUSDBody .qty-input', function(){
                let v = parseInt($(this).val()); if(isNaN(v)||v<0) $(this).val(0);
                const t = recalcularEfectivo('rc');
                $('#rcSubNIO').text(fmtNIO(t.totalNIO));
                $('#rcSubUSD').text(fmtUSD(t.totalUSD));
                $('#rcSubUSDenNIO').text(fmtNIO(t.totalUSD * TC));
                $('#rcTotalEfectivo').text(fmtNIO(t.totalGlobal));
            });
        }

        $('#panelReconteo')[0].scrollIntoView({behavior:'smooth'});

        // Actualizar el intento en el botón
        $(this).data('intento', intento);
    });

    $('#btnCancelarReconteo').on('click', function(){
        $('#panelReconteo').hide();
        $(document).off('input.rc change.rc');
        conceptoEnReconteo = null;
    });

    $('#btnGuardarReconteo').on('click', function(){
        if (!conceptoEnReconteo || !cierreId) return;

        const conc    = conceptoEnReconteo;
        const intento = parseInt($(`.btn-recontar[data-concepto="${conc}"]`).data('intento')) || 1;

        const payload = {
            cierre_id:            cierreId,
            intento:              intento,
            efectivo_nio:         [],
            efectivo_usd:         [],
            cupones_cantidad:     0,
            cupones_monto:        0,
            transferencia_monto:  0,
            tarjeta_monto:        0,
            pedidosya_monto:      0,
            conceptos_a_recontar: [conc],
        };

        if (conc === 'efectivo') {
            DENOMS_NIO.forEach(d => {
                const key = String(d).replace('.','_');
                payload.efectivo_nio.push({denominacion: d, cantidad: parseInt($(`#rc_NIO_${key}`).val())||0});
            });
            DENOMS_USD.forEach(d => {
                const key = String(d).replace('.','_');
                payload.efectivo_usd.push({denominacion: d, cantidad: parseInt($(`#rc_USD_${key}`).val())||0});
            });
        } else if (conc === 'cupones') {
            payload.cupones_cantidad = parseInt($('#rcCuponesQty').val()) || 0;
            payload.cupones_monto    = parseFloat($('#rcCuponesMonto').val()) || 0;
        } else {
            const val = parseFloat($('#rcMontoSimple').val()) || 0;
            payload[`${conc}_monto`] = val;
        }

        const btn = $(this).prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Guardando…');
        $.ajax({
            url:         'ajax/cierre_guardar_conteo.php',
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify(payload),
            dataType:    'json'
        }).done(function(res){
            if (!res.ok) {
                Swal.fire('Error', res.mensaje || 'Error al guardar reconteo.', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Reconteo');
                return;
            }
            conceptosData = res.conceptos;
            $('#panelReconteo').hide();
            $(document).off('input.rc change.rc');
            conceptoEnReconteo = null;
            mostrarResultados(conceptosData);
            btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Reconteo');
        }).fail(function(){
            Swal.fire('Error de red', 'No se pudo conectar.', 'error');
            btn.prop('disabled', false).html('<i class="bi bi-save2-fill"></i> Guardar Reconteo');
        });
    });

    /* ─────────────────────────────────────────────────────────────
       CONFIRMAR CIERRE
       ───────────────────────────────────────────────────────────── */
    $('#btnConfirmarCierre').on('click', function(){
        const comentarios = {};
        $('.comentario-concepto').each(function(){
            const conc = $(this).data('concepto');
            const val  = $(this).val().trim();
            if (val) comentarios[conc] = val;
        });

        // Verificar comentarios obligatorios
        const fueraTol = Object.entries(conceptosData).filter(([,d]) => !d.dentro_tolerancia);
        for (const [conc] of fueraTol) {
            if (!comentarios[conc]) {
                Swal.fire('Comentario requerido', `El concepto "${conc}" requiere un comentario.`, 'warning');
                return;
            }
        }

        Swal.fire({
            title: '¿Confirmar cierre de turno?',
            html: 'Esta acción es <strong>irreversible</strong>. El turno quedará cerrado y no podrá modificarse.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0E544C',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Sí, cerrar turno',
            cancelButtonText: 'Cancelar'
        }).then(result => {
            if (!result.isConfirmed) return;

            const btn = $('#btnConfirmarCierre').prop('disabled', true)
                .html('<i class="bi bi-hourglass-split"></i> Cerrando…');

            $.ajax({
                url:         'ajax/cierre_confirmar.php',
                method:      'POST',
                contentType: 'application/json',
                data:        JSON.stringify({cierre_id: cierreId, comentarios}),
                dataType:    'json'
            }).done(function(res){
                if (!res.ok) {
                    Swal.fire('Error', res.mensaje || 'Error al confirmar.', 'error');
                    btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i> Confirmar y Cerrar Turno');
                    return;
                }
                // Guardar snapshot en window para impresión
                window.CIERRE_SNAPSHOT = res.snapshot;
                mostrarCierreFinalizado(res.snapshot);
            }).fail(function(){
                Swal.fire('Error de red', 'No se pudo conectar.', 'error');
                btn.prop('disabled', false).html('<i class="bi bi-check2-circle"></i> Confirmar y Cerrar Turno');
            });
        });
    });

    function mostrarCierreFinalizado(snapshot) {
        $('#pasoResultados').hide();
        $('#pasoCerrado').show();
        const c = snapshot.cierre;
        $('#txtResumenCierre').html(
            `Turno #${c.numero_turno} · ${c.sucursal} · ${c.cajero}<br>` +
            `Cerrado a las ${new Date().toLocaleTimeString('es-NI',{hour:'2-digit',minute:'2-digit'})}`
        );
        $('#btnImprimirCierre').trigger('click');
    }

    /* ─────────────────────────────────────────────────────────────
       BOTONES FINALES
       ───────────────────────────────────────────────────────────── */
    $('#btnImprimirCierre').on('click', function(){
        const snap = window.CIERRE_SNAPSHOT;
        if (!snap) return;
        const html = window.renderizarTicketCierre ? window.renderizarTicketCierre(snap) : null;
        if (!html) {
            Swal.fire('Aviso', 'Módulo de impresión no disponible.', 'info');
            return;
        }
        // Usar función de impresión del pos_impresion_termica.js adaptada
        if (typeof _imprimirHtml === 'function') {
            _imprimirHtml(html, () => {});
        } else {
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:80mm;height:200mm;border:none;';
            document.body.appendChild(iframe);
            iframe.onload = () => { iframe.contentWindow.print(); setTimeout(() => document.body.removeChild(iframe), 2000); };
            iframe.contentDocument.open();
            iframe.contentDocument.write(html);
            iframe.contentDocument.close();
        }
    });

    $('#btnNuevoCierre').on('click', function(){
        // Recargar para iniciar nuevo turno
        window.location.reload();
    });

});
