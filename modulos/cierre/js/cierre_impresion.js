/* ============================================================
   cierre_impresion.js — Ticket térmico 80mm del Cierre de Turno
   POS Batidos Pitaya

   Expone: window.renderizarTicketCierre(snapshot) → string HTML
   ============================================================ */

'use strict';

(function(window){

    const CSS_URL  = '/modulos/pos/css/ticket_termico_80mm.css';
    const LOGO_URL = '/assets/img/logo_pitaya.png';

    /* ── Helpers ──────────────────────────────────────────────── */
    const fmtNIO  = n => 'C$ ' + parseFloat(n||0).toLocaleString('es-NI', {minimumFractionDigits:2,maximumFractionDigits:2});
    const fmtUSD  = n => '$ '  + parseFloat(n||0).toLocaleString('es-NI', {minimumFractionDigits:2,maximumFractionDigits:2});
    const sep     = () => '<div class="separador" style="border-top:1px dashed #000;margin:3px 0;"></div>';
    const sepDoble= () => '<div class="separador" style="border-top:2px solid #000;margin:4px 0;"></div>';

    function fmtDT(dt) {
        if (!dt) return '—';
        const d = new Date(dt.replace(' ','T'));
        return d.toLocaleDateString('es-NI') + ' ' + d.toLocaleTimeString('es-NI',{hour:'2-digit',minute:'2-digit'});
    }
    function fmtFecha(dt) {
        if (!dt) return '—';
        return new Date(dt.replace(' ','T')).toLocaleDateString('es-NI');
    }

    /* ── Render principal ─────────────────────────────────────── */
    function renderizarTicketCierre(snapshot) {
        const c         = snapshot.cierre        || {};
        const conceptos = snapshot.conceptos     || [];
        const denoms    = snapshot.conteo_final_denoms || [];
        const aligList  = snapshot.aligeramientos       || [];
        const provList  = snapshot.pagos_proveedores    || [];
        const ventas    = snapshot.resumen_ventas       || {};
        const pyList    = snapshot.facturas_pedidosya   || [];

        const tc = parseFloat(c.tipo_cambio_dia || 36.6);

        const nombresConc = {
            efectivo:      'Efectivo',
            cupones:       'Cupones',
            transferencia: 'Transferencia',
            tarjeta:       'Tarjeta/POS Banco',
            pedidosya:     'PedidosYa',
        };

        /* ── Cabecera ── */
        let body = `
        <div class="centro">
            <img src="${LOGO_URL}" class="ticket-logo" style="max-width:50mm;display:block;margin:0 auto 4px;" alt="Logo Pitaya">
            <div class="negrita" style="font-size:11pt;">CIERRE DE TURNO</div>
            <div style="font-size:9pt;">${c.sucursal || ''}</div>
        </div>
        ${sepDoble()}
        <table style="width:100%;font-size:8.5pt;">
            <tr><td><b>Turno</b></td><td class="text-end">#${c.numero_turno || 1}</td></tr>
            <tr><td><b>Fecha</b></td><td class="text-end">${fmtFecha(c.fecha)}</td></tr>
            <tr><td><b>Cajero</b></td><td class="text-end">${c.cajero || ''}</td></tr>
            <tr><td><b>TC del día</b></td><td class="text-end">C$ ${parseFloat(tc).toFixed(2)} / USD</td></tr>
            <tr><td><b>Inicio del corte</b></td><td class="text-end">${fmtDT(c.corte_anterior)}</td></tr>
            <tr><td><b>Fin del corte</b></td><td class="text-end">${fmtDT(c.corte_actual)}</td></tr>
        </table>
        ${sepDoble()}`;

        /* ── Resumen ventas ── */
        body += `
        <div class="negrita" style="font-size:9pt;margin-bottom:2px;">VENTAS DEL TURNO</div>
        <table style="width:100%;font-size:8.5pt;">
            <tr><td>Total facturas</td><td class="text-end">${ventas.total_facturas || 0}</td></tr>
            <tr><td>Total C$</td><td class="text-end">${fmtNIO(ventas.suma_total)}</td></tr>
            <tr><td>Caja inicial</td><td class="text-end">${fmtNIO(c.caja_inicial_c)}</td></tr>
        </table>
        ${sep()}`;

        /* ── Resultados por concepto ── */
        body += `<div class="negrita" style="font-size:9pt;margin-bottom:2px;">CUADRE POR CONCEPTO</div>`;
        body += `<table style="width:100%;font-size:8pt;border-collapse:collapse;">
            <tr>
                <th style="text-align:left;border-bottom:1px solid #000;">Concepto</th>
                <th style="text-align:right;border-bottom:1px solid #000;">Esperado</th>
                <th style="text-align:right;border-bottom:1px solid #000;">Contado</th>
                <th style="text-align:right;border-bottom:1px solid #000;">Dif.</th>
                <th style="text-align:center;border-bottom:1px solid #000;">OK</th>
            </tr>`;

        conceptos.forEach(cc => {
            const ok  = cc.dentro_tolerancia ? '✓' : '✗';
            const dif = parseFloat(cc.diferencia || 0);
            const difStr = (dif > 0 ? '+' : '') + fmtNIO(dif);
            const bold = !cc.dentro_tolerancia ? ' font-weight:bold;' : '';
            body += `<tr style="${bold}">
                <td>${nombresConc[cc.concepto] || cc.concepto}</td>
                <td style="text-align:right;">${fmtNIO(cc.esperado)}</td>
                <td style="text-align:right;">${fmtNIO(cc.contado_final)}</td>
                <td style="text-align:right;">${difStr}</td>
                <td style="text-align:center;">${ok}</td>
            </tr>`;
            if (cc.comentario) {
                body += `<tr><td colspan="5" style="font-style:italic;font-size:7.5pt;padding-left:4px;">↳ ${cc.comentario}</td></tr>`;
            }
            if (cc.concepto === 'cupones' && cc.cantidad_esperada !== null) {
                body += `<tr><td colspan="5" style="font-size:7.5pt;padding-left:4px;">
                    Cant. esperada: ${cc.cantidad_esperada} | Contada: ${cc.cantidad_contada}
                </td></tr>`;
            }
        });
        body += '</table>';
        body += sep();

        /* ── Conteo final por denominación ── */
        if (denoms.length > 0) {
            body += `<div class="negrita" style="font-size:9pt;margin-bottom:2px;">CONTEO FINAL EFECTIVO</div>`;
            const nio = denoms.filter(r => r.moneda === 'NIO');
            const usd = denoms.filter(r => r.moneda === 'USD');
            if (nio.length) {
                body += `<div style="font-size:8pt;font-weight:bold;">Córdobas (NIO):</div>`;
                body += `<table style="width:100%;font-size:7.5pt;">`;
                nio.forEach(r => {
                    if (parseInt(r.cantidad) > 0) {
                        body += `<tr><td>C$ ${parseFloat(r.denominacion).toFixed(2)}</td><td style="text-align:center;">×${r.cantidad}</td><td style="text-align:right;">${fmtNIO(r.monto)}</td></tr>`;
                    }
                });
                const totalNIO = nio.reduce((s,r)=>s+parseFloat(r.monto),0);
                body += `<tr style="border-top:1px solid #000;font-weight:bold;"><td colspan="2">Total NIO</td><td style="text-align:right;">${fmtNIO(totalNIO)}</td></tr>`;
                body += '</table>';
            }
            if (usd.length) {
                body += `<div style="font-size:8pt;font-weight:bold;margin-top:3px;">Dólares (USD):</div>`;
                body += `<table style="width:100%;font-size:7.5pt;">`;
                usd.forEach(r => {
                    if (parseInt(r.cantidad) > 0) {
                        body += `<tr><td>$ ${parseFloat(r.denominacion).toFixed(2)}</td><td style="text-align:center;">×${r.cantidad}</td><td style="text-align:right;">${fmtUSD(r.monto)}</td></tr>`;
                    }
                });
                const totalUSD = usd.reduce((s,r)=>s+parseFloat(r.monto),0);
                const totalUSDenC = totalUSD * tc;
                body += `<tr style="border-top:1px solid #000;font-weight:bold;"><td>Total USD</td><td style="text-align:right;">${fmtUSD(totalUSD)}</td><td style="text-align:right;">${fmtNIO(totalUSDenC)}</td></tr>`;
                body += '</table>';
            }
            body += sep();
        }

        /* ── Aligeramientos ── */
        if (aligList.length > 0) {
            body += `<div class="negrita" style="font-size:9pt;margin-bottom:2px;">ALIGERAMIENTOS</div>`;
            body += `<table style="width:100%;font-size:7.5pt;">`;
            aligList.forEach(a => {
                body += `<tr><td>${fmtDT(a.creado_en)}</td><td style="text-align:right;">${fmtNIO(a.total_global)}</td></tr>`;
                if (a.observacion) {
                    body += `<tr><td colspan="2" style="font-style:italic;padding-left:4px;">↳ ${a.observacion}</td></tr>`;
                }
            });
            const totalAlig = aligList.reduce((s,a)=>s+parseFloat(a.total_global),0);
            body += `<tr style="border-top:1px solid #000;font-weight:bold;"><td>Total aligeramientos</td><td style="text-align:right;">${fmtNIO(totalAlig)}</td></tr>`;
            body += '</table>';
            body += sep();
        }

        /* ── Pagos a proveedores ── */
        if (provList.length > 0) {
            body += `<div class="negrita" style="font-size:9pt;margin-bottom:2px;">PAGOS A PROVEEDORES</div>`;
            body += `<table style="width:100%;font-size:7.5pt;">`;
            provList.forEach(p => {
                body += `<tr><td>${p.proveedor}</td><td style="text-align:right;">${fmtNIO(p.total_factura)}</td></tr>`;
            });
            const totalProv = provList.reduce((s,p)=>s+parseFloat(p.total_factura),0);
            body += `<tr style="border-top:1px solid #000;font-weight:bold;"><td>Total proveedores</td><td style="text-align:right;">${fmtNIO(totalProv)}</td></tr>`;
            body += '</table>';
            body += sep();
        }

        /* ── PedidosYa ── */
        if (pyList.length > 0) {
            body += `<div class="negrita" style="font-size:9pt;margin-bottom:2px;">PEDIDOSYA</div>`;
            body += `<table style="width:100%;font-size:7.5pt;">`;
            pyList.forEach(f => {
                body += `<tr><td>#${f.id}</td><td>${fmtDT(f.fecha_hora)}</td><td style="text-align:right;">${fmtNIO(f.total)}</td></tr>`;
            });
            const totalPY = pyList.reduce((s,f)=>s+parseFloat(f.total),0);
            body += `<tr style="border-top:1px solid #000;font-weight:bold;"><td colspan="2">Total PedidosYa</td><td style="text-align:right;">${fmtNIO(totalPY)}</td></tr>`;
            body += '</table>';
            body += sep();
        }

        /* ── Intentos usados ── */
        body += `<div class="negrita" style="font-size:8.5pt;margin-bottom:2px;">INTENTOS DE CONTEO</div>`;
        body += `<table style="width:100%;font-size:7.5pt;">`;
        conceptos.forEach(cc => {
            body += `<tr><td>${nombresConc[cc.concepto]||cc.concepto}</td><td style="text-align:right;">${cc.intentos_usados} de ${3}</td></tr>`;
        });
        body += '</table>';
        body += sep();

        /* ── Firma ── */
        body += `
        ${sepDoble()}
        <div style="margin-top:12px;font-size:8pt;">
            <div>Generado: ${fmtDT(c.cerrado_en || snapshot.generado_en)}</div>
        </div>
        <div style="margin-top:28px;font-size:8pt;">
            Firma del cajero: ____________________
        </div>
        <div style="margin-top:8px;font-size:8pt;">
            Nombre: ${c.cajero || ''}
        </div>`;

        /* ── Documento completo ── */
        return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<link rel="stylesheet" href="${CSS_URL}">
<style>
@page { size: 80mm auto; margin: 2mm 3mm; }
body { font-family: 'Courier New', Courier, monospace; font-size: 9pt; width: 72mm; margin: 0; padding: 0; }
.centro { text-align: center; }
.negrita { font-weight: bold; }
.separador { border-top: 1px dashed #000; margin: 3px 0; }
table { width: 100%; border-collapse: collapse; }
.text-end { text-align: right; }
@media print { body { width: 72mm; } }
</style>
</head>
<body>${body}</body>
</html>`;
    }

    /* ── Exportar ─────────────────────────────────────────────── */
    window.renderizarTicketCierre = renderizarTicketCierre;

})(window);

