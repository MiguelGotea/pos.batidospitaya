/* ============================================================
   cierre_impresion.js — Ticket térmico 80mm del Cierre de Turno
   POS Batidos Pitaya

   Usa plantillaBase() de pos_impresion_termica.js (cargado antes).
   Todo CSS es inline → funciona con _imprimirHtml/_imprimirEnPaginaPrincipal.

   Expone: window.renderizarTicketCierre(snapshot) → string HTML
   ============================================================ */

'use strict';

(function(window) {

    /* ── Helpers ──────────────────────────────────────────────── */
    const fmtNIO = n => 'C$ ' + parseFloat(n||0).toLocaleString('es-NI', {minimumFractionDigits:2, maximumFractionDigits:2});
    const fmtUSD = n => '$ '  + parseFloat(n||0).toLocaleString('es-NI', {minimumFractionDigits:2, maximumFractionDigits:2});

    function fmtDT(dt) {
        if (!dt) return '—';
        const d = new Date(dt.replace(' ','T'));
        return d.toLocaleDateString('es-NI') + ' ' + d.toLocaleTimeString('es-NI',{hour:'2-digit',minute:'2-digit'});
    }
    function fmtFecha(dt) {
        if (!dt) return '—';
        return new Date(dt.replace(' ','T')).toLocaleDateString('es-NI');
    }

    const sep    = () => '<hr style="border:none;border-top:1px dashed #000;margin:3px 0;">';
    const sep2   = () => '<hr style="border:none;border-top:2px solid #000;margin:5px 0;">';
    const titulo = t  => `<div style="font-weight:bold;font-size:9pt;margin:4px 0 2px;">${t}</div>`;

    /* ── Render principal ─────────────────────────────────────── */
    function renderizarTicketCierre(snapshot) {
        const c         = snapshot.cierre              || {};
        const conceptos = snapshot.conceptos           || [];
        const denoms    = snapshot.conteo_final_denoms || [];
        const aligList  = snapshot.aligeramientos      || [];
        const provList  = snapshot.pagos_proveedores   || [];
        const ventas    = snapshot.resumen_ventas      || {};
        const pyList    = snapshot.facturas_pedidosya  || [];
        const tc        = parseFloat(c.tipo_cambio_dia || 36.6);

        const nombresConc = {
            efectivo:      'Efectivo',
            cupones:       'Cupones',
            transferencia: 'Transferencia',
            tarjeta:       'Tarjeta/POS Banco',
            pedidosya:     'PedidosYa',
        };

        let body = '';

        /* ── Cabecera ── */
        body += `
<div style="text-align:center;margin-bottom:4px;">
  <img src="/assets/img/logo_pitaya.png" style="max-width:50mm;display:block;margin:0 auto 3px;" alt="Pitaya">
  <div style="font-size:11pt;font-weight:bold;letter-spacing:0.5mm;">CIERRE DE TURNO</div>
  <div style="font-size:9pt;">${c.sucursal || ''}</div>
</div>
${sep2()}
<table style="width:100%;font-size:8.5pt;border-collapse:collapse;">
  <tr><td><b>Turno</b></td><td style="text-align:right;">#${c.numero_turno || 1}</td></tr>
  <tr><td><b>Fecha</b></td><td style="text-align:right;">${fmtFecha(c.fecha || c.corte_actual)}</td></tr>
  <tr><td><b>Cajero</b></td><td style="text-align:right;">${c.cajero || ''}</td></tr>
  <tr><td><b>TC del día</b></td><td style="text-align:right;">C$ ${parseFloat(tc).toFixed(2)} / USD</td></tr>
  <tr><td><b>Inicio del corte</b></td><td style="text-align:right;">${fmtDT(c.corte_anterior)}</td></tr>
  <tr><td><b>Fin del corte</b></td><td style="text-align:right;">${fmtDT(c.corte_actual)}</td></tr>
</table>
${sep2()}`;

        /* ── Resumen ventas ── */
        body += titulo('VENTAS DEL TURNO');
        body += `<table style="width:100%;font-size:8.5pt;border-collapse:collapse;">
  <tr><td>Total facturas</td><td style="text-align:right;">${ventas.total_facturas || 0}</td></tr>
  <tr><td>Total C$</td><td style="text-align:right;">${fmtNIO(ventas.suma_total)}</td></tr>
  <tr><td>Caja inicial</td><td style="text-align:right;">${fmtNIO(c.caja_inicial_c)}</td></tr>
</table>${sep()}`;

        /* ── Cuadre por concepto ── */
        body += titulo('CUADRE POR CONCEPTO');
        body += `<table style="width:100%;font-size:7.8pt;border-collapse:collapse;">
<tr>
  <th style="text-align:left;border-bottom:1px solid #000;padding-bottom:1mm;">Concepto</th>
  <th style="text-align:right;border-bottom:1px solid #000;padding-bottom:1mm;">Esperado</th>
  <th style="text-align:right;border-bottom:1px solid #000;padding-bottom:1mm;">Contado</th>
  <th style="text-align:right;border-bottom:1px solid #000;padding-bottom:1mm;">Dif.</th>
  <th style="text-align:center;border-bottom:1px solid #000;padding-bottom:1mm;">OK</th>
</tr>`;
        conceptos.forEach(cc => {
            const ok  = cc.dentro_tolerancia ? '✓' : '✗';
            const dif = parseFloat(cc.diferencia || 0);
            const difStr = (dif > 0 ? '+' : '') + fmtNIO(dif);
            const bold = !cc.dentro_tolerancia ? 'font-weight:bold;' : '';
            body += `<tr style="${bold}">
  <td>${nombresConc[cc.concepto] || cc.concepto}</td>
  <td style="text-align:right;">${fmtNIO(cc.esperado)}</td>
  <td style="text-align:right;">${fmtNIO(cc.contado_final)}</td>
  <td style="text-align:right;">${difStr}</td>
  <td style="text-align:center;">${ok}</td>
</tr>`;
            if (cc.comentario) {
                body += `<tr><td colspan="5" style="font-style:italic;font-size:7pt;padding-left:3mm;">↳ ${cc.comentario}</td></tr>`;
            }
            if (cc.concepto === 'cupones' && cc.cantidad_esperada !== null) {
                body += `<tr><td colspan="5" style="font-size:7pt;padding-left:3mm;">Cant. esp.: ${cc.cantidad_esperada} | Contada: ${cc.cantidad_contada}</td></tr>`;
            }
        });
        body += `</table>${sep()}`;

        /* ── Conteo final por denominación (último intento guardado) ── */
        if (denoms.length > 0) {
            body += titulo('CONTEO FINAL EFECTIVO');
            const nioRows = denoms.filter(r => r.moneda === 'NIO');
            const usdRows = denoms.filter(r => r.moneda === 'USD');

            if (nioRows.length) {
                body += `<div style="font-size:8pt;font-weight:bold;margin:2px 0;">Córdobas (NIO):</div>`;
                body += `<table style="width:100%;font-size:7.5pt;border-collapse:collapse;">`;
                nioRows.forEach(r => {
                    if (parseInt(r.cantidad) > 0) {
                        body += `<tr>
  <td>C$ ${parseFloat(r.denominacion).toFixed(2)}</td>
  <td style="text-align:center;">×${r.cantidad}</td>
  <td style="text-align:right;">${fmtNIO(r.monto)}</td>
</tr>`;
                    }
                });
                const totNIO = nioRows.reduce((s,r) => s + parseFloat(r.monto), 0);
                body += `<tr style="border-top:1px solid #000;font-weight:bold;">
  <td colspan="2">Total NIO</td><td style="text-align:right;">${fmtNIO(totNIO)}</td>
</tr></table>`;
            }

            if (usdRows.length) {
                body += `<div style="font-size:8pt;font-weight:bold;margin:3px 0 2px;">Dólares (USD):</div>`;
                body += `<table style="width:100%;font-size:7.5pt;border-collapse:collapse;">`;
                usdRows.forEach(r => {
                    if (parseInt(r.cantidad) > 0) {
                        body += `<tr>
  <td>$ ${parseFloat(r.denominacion).toFixed(2)}</td>
  <td style="text-align:center;">×${r.cantidad}</td>
  <td style="text-align:right;">${fmtUSD(r.monto)}</td>
</tr>`;
                    }
                });
                const totUSD = usdRows.reduce((s,r) => s + parseFloat(r.monto), 0);
                body += `<tr style="border-top:1px solid #000;font-weight:bold;">
  <td>Total USD</td>
  <td style="text-align:right;">${fmtUSD(totUSD)}</td>
  <td style="text-align:right;">${fmtNIO(totUSD * tc)}</td>
</tr></table>`;
            }
            body += sep();
        }

        /* ── Aligeramientos ── */
        if (aligList.length > 0) {
            body += titulo('ALIGERAMIENTOS');
            body += `<table style="width:100%;font-size:7.5pt;border-collapse:collapse;">`;
            aligList.forEach(a => {
                body += `<tr><td>${fmtDT(a.creado_en)}</td><td style="text-align:right;">${fmtNIO(a.total_global)}</td></tr>`;
                if (a.observacion) {
                    body += `<tr><td colspan="2" style="font-style:italic;padding-left:3mm;">↳ ${a.observacion}</td></tr>`;
                }
            });
            const totAlig = aligList.reduce((s,a) => s + parseFloat(a.total_global), 0);
            body += `<tr style="border-top:1px solid #000;font-weight:bold;">
  <td>Total aligeramientos</td><td style="text-align:right;">${fmtNIO(totAlig)}</td>
</tr></table>${sep()}`;
        }

        /* ── Pagos a proveedores ── */
        if (provList.length > 0) {
            body += titulo('PAGOS A PROVEEDORES');
            body += `<table style="width:100%;font-size:7.5pt;border-collapse:collapse;">`;
            provList.forEach(p => {
                body += `<tr><td>${p.proveedor}</td><td style="text-align:right;">${fmtNIO(p.total_factura)}</td></tr>`;
            });
            const totProv = provList.reduce((s,p) => s + parseFloat(p.total_factura), 0);
            body += `<tr style="border-top:1px solid #000;font-weight:bold;">
  <td>Total proveedores</td><td style="text-align:right;">${fmtNIO(totProv)}</td>
</tr></table>${sep()}`;
        }

        /* ── PedidosYa ── */
        if (pyList.length > 0) {
            body += titulo('PEDIDOSYA');
            body += `<table style="width:100%;font-size:7.5pt;border-collapse:collapse;">`;
            pyList.forEach(f => {
                body += `<tr><td>#${f.id}</td><td>${fmtDT(f.fecha_hora)}</td><td style="text-align:right;">${fmtNIO(f.total)}</td></tr>`;
            });
            const totPY = pyList.reduce((s,f) => s + parseFloat(f.total), 0);
            body += `<tr style="border-top:1px solid #000;font-weight:bold;">
  <td colspan="2">Total PedidosYa</td><td style="text-align:right;">${fmtNIO(totPY)}</td>
</tr></table>${sep()}`;
        }

        /* ── Intentos de conteo ── */
        body += titulo('INTENTOS DE CONTEO');
        body += `<table style="width:100%;font-size:7.5pt;border-collapse:collapse;">`;
        conceptos.forEach(cc => {
            body += `<tr><td>${nombresConc[cc.concepto]||cc.concepto}</td><td style="text-align:right;">${cc.intentos_usados} de 3</td></tr>`;
        });
        body += `</table>${sep()}`;

        /* ── Firma ── */
        body += `
${sep2()}
<div style="font-size:7.5pt;margin-top:3mm;">Generado: ${fmtDT(c.cerrado_en || snapshot.generado_en)}</div>
<div style="margin-top:10mm;font-size:8pt;">Firma cajero: ____________________</div>
<div style="margin-top:2mm;font-size:8pt;">Nombre: ${c.cajero || ''}</div>
<div style="margin-bottom:5mm;"></div>`;

        /* ── Usar plantillaBase de pos_impresion_termica.js ─────────
           Garantiza el mismo CSS inline y motor de impresión que facturas. */
        return (typeof plantillaBase === 'function')
            ? plantillaBase(body)
            : _fallbackHtml(body);
    }

    /* Fallback por si plantillaBase no cargó (no debería ocurrir) */
    function _fallbackHtml(body) {
        return `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
@media print { @page { size:80mm auto; margin:0mm 1mm; } html,body { width:78mm; } }
body { font-family: Arial, sans-serif; font-size: 9pt; margin:0; padding:0; }
.ticket-wrapper { width:78mm; margin:0 auto; padding:0 1mm; }
</style></head><body><div class="ticket-wrapper">${body}</div></body></html>`;
    }

    /* ── Exportar ─────────────────────────────────────────────── */
    window.renderizarTicketCierre = renderizarTicketCierre;

})(window);
