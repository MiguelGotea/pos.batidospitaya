/* ================================================================
   pos_impresion_termica.js
   Módulo de impresión térmica 80mm — POS Batidos Pitaya
   Orquesta la generación y disparo de tickets de factura y comandas
   ================================================================ */

'use strict';

// ── Constantes configurables ─────────────────────────────────────────────────
const IMPRESION_WIFI_PASSWORD = 'tubatidofavorito';
const IMPRESION_DELAY_MS = 600;  // ms tras afterprint antes de redirigir
const IMPRESION_CSS_URL = '/modulos/pos/css/ticket_termico_80mm.css';
const IMPRESION_LOGO_URL = '/assets/img/logo_pitaya.png';

/* ══════════════════════════════════════════════════════════════════════════════
   FUNCIÓN PRINCIPAL: lanzarImpresionCompleta
   Recibe el payload del servidor, genera el HTML de cada ticket y los imprime.

   ESTRATEGIA HÍBRIDA según plataforma:
   • Android/tablet: documento consolidado en UN solo diálogo de impresión.
     Necesario porque en Android Chrome window.print() es no-bloqueante y
     afterprint dispara antes de que el usuario interactúe con el diálogo.
   • Desktop (Windows/Mac Chrome, Edge, Firefox): impresión secuencial —
     un iframe por ticket, un diálogo de impresión por ticket. En escritorio
     window.print() ES bloqueante, por lo que el flujo secuencial funciona
     perfectamente y el iframe de tamaño real evita la página en blanco que
     ocurre con el iframe de 1×1 px del modo consolidado.
   ══════════════════════════════════════════════════════════════════════════════ */

/* ── Detecta si el navegador actual es Android ───────────────────────────────
   Se usa para elegir la estrategia de impresión adecuada.              */
function _esAndroid() {
    return /android/i.test(navigator.userAgent);
}

function lanzarImpresionCompleta(payload, onTerminado) {
    const { factura, comandas } = payload;

    // Generar HTML individual de cada ticket
    const tickets = [];
    tickets.push(renderizarFacturaCliente(factura));
    (comandas || []).forEach(comanda => {
        tickets.push(renderizarComandaEstacion(comanda, factura));
    });

    if (tickets.length === 0) {
        if (typeof onTerminado === 'function') onTerminado();
        return;
    }

    if (_esAndroid()) {
        // ── Modo Android: un único documento consolidado, un solo diálogo ──
        const htmlConsolidado = _consolidarTickets(tickets);
        _imprimirHtml(htmlConsolidado, onTerminado);
    } else {
        // ── Modo Desktop: un iframe por ticket, diálogos secuenciales ──────
        _imprimirSecuencialDesktop(tickets, 0, onTerminado);
    }
}

/* ── Consolida un array de documentos HTML en uno solo ───────────────────────
   Extrae el CSS del primer documento (todos comparten los mismos estilos) y
   concatena el <body> de cada ticket separado por page-break-after: always.  */
function _consolidarTickets(htmlDocs) {
    // Extraer el bloque <head> del primer ticket (CSS idéntico en todos)
    const headMatch = htmlDocs[0].match(/<head[^>]*>([\s\S]*?)<\/head>/i);
    const headContent = headMatch ? headMatch[1] : '';

    // Construir las secciones del cuerpo con saltos de página entre tickets
    const secciones = htmlDocs.map((html, idx) => {
        const bodyMatch = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
        const contenido = bodyMatch ? bodyMatch[1].trim() : '';
        const esUltimo = (idx === htmlDocs.length - 1);

        // El último ticket no lleva salto de página después
        const estiloSalto = esUltimo
            ? ''
            : ' style="page-break-after:always;break-after:page;margin-bottom:0;"';

        return `<section class="ticket-seccion"${estiloSalto}>${contenido}</section>`;
    });

    return `<!DOCTYPE html>
<html lang="es">
<head>
${headContent}
<style>
/* Estilos de consolidación — no afectan el contenido individual de cada ticket */
.ticket-seccion { display: block; }
@media print {
  .ticket-seccion { page-break-inside: avoid; }
}
</style>
</head>
<body style="margin:0;padding:0;">
${secciones.join('\n')}
</body>
</html>`;
}

/* ── Inyecta el HTML en un iframe oculto y llama window.print() ──────────────
   Funciona en kiosk-printing (desktop, sin diálogo) y en modo normal
   (tablet/móvil, con diálogo del navegador). El callback onTerminado se
   invoca cuando el diálogo se cierra (afterprint) o tras el fallback de 60 s. */
function _imprimirHtml(html, onTerminado) {
    const iframe = document.createElement('iframe');
    // Dimensiones reales off-screen: evita página en blanco en Android Chrome y Desktop.
    // Un iframe de 1×1 px colapsa el layout y el motor de impresión no renderiza nada.
    iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:80mm;height:200mm;border:none;visibility:hidden;pointer-events:none;';
    document.body.appendChild(iframe);

    let lanzado = false; // evita doble disparo de print (onload + fallback)
    let finalizado = false; // evita doble llamada a onTerminado

    const finalizar = () => {
        if (finalizado) return;
        finalizado = true;
        try { document.body.removeChild(iframe); } catch (e) { /* ignorar */ }
        setTimeout(() => {
            if (typeof onTerminado === 'function') onTerminado();
        }, IMPRESION_DELAY_MS);
    };

    const lanzarPrint = () => {
        if (lanzado) return;
        lanzado = true;

        // afterprint dispara cuando el diálogo se cierra (o inmediatamente en
        // kiosk-printing). En Android Chrome puede disparar antes de que el
        // usuario interactúe, pero con un único documento consolidado eso no
        // importa — ya todo está en un solo trabajo de impresión.
        let afterprintFired = false;
        try {
            iframe.contentWindow.addEventListener('afterprint', () => {
                afterprintFired = true;
                finalizar();
            }, { once: true });
        } catch (eAP) {
            console.warn('[POS] No se pudo suscribir a afterprint:', eAP);
        }

        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch (e) {
            console.warn('[POS] Error al llamar print():', e);
            finalizar();
            return;
        }

        // Fallback: si afterprint nunca se dispara (caso raro), avanzar tras 60 s
        setTimeout(() => {
            if (!afterprintFired) {
                console.warn('[POS] afterprint no disparó; finalizando por fallback (60 s).');
                finalizar();
            }
        }, 60000);
    };

    // Disparar impresión al cargar el iframe
    iframe.onload = () => setTimeout(lanzarPrint, 400);

    try {
        iframe.contentDocument.open();
        iframe.contentDocument.write(html);
        iframe.contentDocument.close();
    } catch (e) {
        // Fallback same-origin: usar Blob URL
        const blob = new Blob([html], { type: 'text/html' });
        iframe.src = URL.createObjectURL(blob);
    }

    // Fallback: si onload no dispara (ocurre con document.write en algunos entornos)
    setTimeout(lanzarPrint, 1800);
}

/* ── Impresión secuencial para Desktop ───────────────────────────────────────
   Imprime los tickets uno a uno usando iframes independientes. En escritorio,
   window.print() es BLOQUEANTE: el JS se pausa hasta que el usuario cierra el
   diálogo, por lo que podemos encadenar los trabajos de forma segura.
   En Android NO se usa este modo porque print() no es bloqueante allí.

   @param {string[]} htmlTickets  Array de documentos HTML completos
   @param {number}   indice       Índice actual en htmlTickets
   @param {Function} onTerminado  Callback al finalizar todos los tickets    */
function _imprimirSecuencialDesktop(htmlTickets, indice, onTerminado) {
    if (indice >= htmlTickets.length) {
        // Todos los tickets fueron enviados al diálogo de impresión
        if (typeof onTerminado === 'function') onTerminado();
        return;
    }

    const html = htmlTickets[indice];

    // iframe a tamaño normal (no 1×1 px) para evitar página en blanco en
    // Chrome/Edge escritorio. Se posiciona fuera de pantalla pero con
    // dimensiones reales para que el motor de impresión pueda renderizarlo.
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:80mm;height:200mm;border:none;visibility:hidden;';
    document.body.appendChild(iframe);

    let yaImprimio = false;

    const ejecutarImpresion = () => {
        if (yaImprimio) return;
        yaImprimio = true;

        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print(); // Bloqueante en escritorio
        } catch (e) {
            console.warn('[POS] Error al llamar print() en ticket', indice, ':', e);
        }

        // Tras print() bloqueante, el usuario ya cerró el diálogo.
        // Eliminar el iframe y pasar al siguiente ticket.
        setTimeout(() => {
            try { document.body.removeChild(iframe); } catch (e2) { /* ignorar */ }
            _imprimirSecuencialDesktop(htmlTickets, indice + 1, onTerminado);
        }, IMPRESION_DELAY_MS);
    };

    // Disparar impresión cuando el iframe termine de cargar
    iframe.onload = () => setTimeout(ejecutarImpresion, 350);

    try {
        iframe.contentDocument.open();
        iframe.contentDocument.write(html);
        iframe.contentDocument.close();
    } catch (e) {
        // Fallback same-origin: usar Blob URL
        const blob = new Blob([html], { type: 'text/html' });
        iframe.src = URL.createObjectURL(blob);
    }

    // Fallback: si onload no dispara, forzar impresión tras 1500 ms
    setTimeout(ejecutarImpresion, 1500);
}


/* ══════════════════════════════════════════════════════════════════════════════
   RENDERIZADORES HTML
   ══════════════════════════════════════════════════════════════════════════════ */

/* ── Plantilla base HTML para cada ticket ───────────────────────────────────── */
function plantillaBase(contenido) {
    // Los estilos se incluyen INLINE para evitar dependencia de carga externa.
    // La hoja CSS externa también se enlaza como respaldo para modo screen (preview).
    return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket</title>
<link rel="stylesheet" href="${IMPRESION_CSS_URL}">
<style>
/* ── Reset ── */
* { box-sizing: border-box; }
@media print {
  @page { size: 80mm auto; margin: 0mm 1mm; }
  html, body { width: 78mm; margin: 0; padding: 0; background: #fff; }
}
/* ── Base del ticket ── */
/* Punto 10: fuente más legible desde lejos — Arial/sans-serif con Courier como fallback */
body { font-family: Arial, 'Helvetica Neue', Helvetica, sans-serif; font-size: 9pt; line-height: 1.4; color: #000; background: #fff; margin: 0; padding: 0; }
.ticket-wrapper { width: 78mm; margin: 0 auto; padding: 0 1mm; }
/* ── Cabecera factura ── */
/* Punto 1: logo reducido 10% → de 55mm a ~49.5mm ≈ 50mm */
.ticket-logo { display: block; max-width: 50mm; height: auto; margin: 0 auto 2mm auto; }
.ticket-header { text-align: center; }
.ticket-bienvenida { font-size: 10pt; font-weight: bold; text-align: center; margin: 1mm 0; }
.ticket-meta { font-size: 7.5pt; text-align: center; margin: 1mm 0; line-height: 1.4; }
.ticket-sep { border: none; border-top: 1px dashed #000; margin: 2mm 0; }
.ticket-sep-solid { border: none; border-top: 1.5px solid #000; margin: 2mm 0; }
/* ── Tabla factura ── */
.ticket-tabla { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
.ticket-tabla th { font-weight: bold; font-size: 8pt; text-transform: uppercase; border-bottom: 1px solid #000; padding-bottom: 1mm; text-align: left; }
.ticket-tabla td { padding: 0.5mm 0; vertical-align: top; }
.ticket-tabla th.col-num, .ticket-tabla td.col-num { text-align: center; width: 7mm; }
.ticket-tabla th.col-pu, .ticket-tabla td.col-pu,
.ticket-tabla th.col-pt, .ticket-tabla td.col-pt { text-align: right; width: 18mm; }
.ticket-prod-nombre { font-weight: bold; font-size: 8.5pt; }
.ticket-pers-lista { list-style: none; margin: 0.5mm 0 0 2mm; padding: 0; font-size: 7.5pt; color: #333; }
.ticket-pers-lista li::before { content: '- '; }
.ticket-canje-badge { font-size: 7pt; font-weight: bold; border: 1px solid #000; padding: 0 1mm; display: inline-block; margin-top: 0.5mm; }
/* ── Totales ── */
.ticket-totales { margin-top: 2mm; font-size: 8.5pt; }
.ticket-totales-fila { display: flex; justify-content: space-between; padding: 0.3mm 0; }
.ticket-totales-fila.total-grande { font-size: 10pt; font-weight: bold; border-top: 1.5px solid #000; margin-top: 1mm; padding-top: 1mm; }
/* ── Pie ── */
.ticket-footer { text-align: center; font-size: 7.5pt; margin-top: 3mm; line-height: 1.5; }
.ticket-wifi { font-weight: bold; font-size: 8pt; }
/* ─────────────────────────────────────────────
   COMANDA — estilos mejorados
   ───────────────────────────────────────────── */
/* Punto 10: fuente sans-serif también en comanda */
/* Cabecera estación */
.comanda-estacion-titulo { font-size: 14pt; font-weight: bold; text-align: center; text-transform: uppercase; letter-spacing: 0.5mm; border: 2px solid #000; padding: 1.5mm 2mm; margin-bottom: 2mm; line-height: 1.2; }
/* Puntos 2: cliente y pedido en líneas separadas, pedido grande y destacado */
.comanda-meta { font-size: 8.5pt; margin-bottom: 0; line-height: 1.5; }
.comanda-meta-cliente { font-size: 13pt; font-weight: bold; letter-spacing: 0.3mm; margin-bottom: 0.5mm; }
.comanda-meta-pedido  { font-size: 13pt; font-weight: bold; letter-spacing: 0.3mm; margin-bottom: 0.5mm; }
.comanda-meta-fechahora { font-size: 8pt; color: #333; }
/* Punto 4: espacio después del bloque de cabecera logeable */
.comanda-meta-wrapper { margin-bottom: 3mm; }
/* ── Comanda: bloque de producto ── */
/* Punto 5: espacio entre producto y producto */
.comanda-producto-bloque { margin-bottom: 4mm; border-bottom: 1px dashed #555; padding-bottom: 3mm; }
.comanda-prod-encabezado { display: flex; align-items: center; gap: 2mm; margin-bottom: 1.5mm; }
.comanda-prod-cantidad { font-size: 14pt; font-weight: bold; border: 1.5px solid #000; min-width: 8mm; height: 8mm; display: flex; align-items: center; justify-content: center; flex-shrink: 0; padding: 0 1mm; }
/* Punto 3: nombre de producto más grande */
.comanda-prod-nombre { font-size: 12pt; font-weight: bold; line-height: 1.2; }
/* ── Comanda: notas ── */
.comanda-notas { background: #eee; border: 1px solid #bbb; padding: 1mm 2mm; font-size: 8pt; font-style: italic; margin-bottom: 1.5mm; word-break: break-word; }
.comanda-notas-label { font-weight: bold; font-style: normal; font-size: 7.5pt; text-transform: uppercase; }
/* ── Comanda: tabla ingredientes ── */
.comanda-ingredientes { width: 100%; border-collapse: collapse; font-size: 9pt; }
.comanda-ingredientes th { font-size: 8pt; font-weight: bold; text-transform: uppercase; border-bottom: 1.5px solid #000; text-align: left; padding-bottom: 0.8mm; }
/* Punto 5: espacio entre filas de ingredientes */
.comanda-ingredientes td { padding: 0.4mm 0; vertical-align: middle; }
.comanda-ingredientes th.col-tipo, .comanda-ingredientes td.col-tipo { width: 6mm; font-weight: bold; text-align: center; }
.comanda-ingredientes th.col-cant, .comanda-ingredientes td.col-cant { width: 14mm; text-align: right; }
/* Punto 6: separador de grupo de tipo — fila vacía con altura visible para marcar cambio de tipo */
.comanda-grupo-sep td { padding: 0; height: 0; border: none; }
.comanda-grupo-sep-linea { border-top: 0.5px dashed #bbb; margin: 1.5mm 0 0.5mm 0; }
/* ── Tachado grueso ── */
.comanda-tachado, .comanda-fila-tachada td {
  text-decoration: line-through;
  text-decoration-thickness: 3px;
  text-decoration-color: #000;
  font-weight: bold;
  opacity: 0.65;
}
.comanda-fila-nuevo td { font-weight: bold; }
.comanda-tipo-e, .comanda-tipo-x { font-weight: bold; }
/* ── Completar pedido — punto 8: tabla con encabezados y checklist ── */
.comanda-completar-wrapper { margin-top: 4mm; padding-top: 3mm; border-top: 2px solid #000; }
.comanda-completar-titulo { font-size: 9pt; font-weight: bold; text-transform: uppercase; margin-bottom: 1.5mm; letter-spacing: 0.2mm; }
.comanda-completar-tabla { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
.comanda-completar-tabla th { font-weight: bold; font-size: 8pt; text-transform: uppercase; border-bottom: 1px solid #000; padding-bottom: 1mm; text-align: left; }
.comanda-completar-tabla th.col-check { width: 6mm; text-align: center; }
.comanda-completar-tabla th.col-cant-h { width: 10mm; text-align: right; }
.comanda-completar-tabla td { padding: 1mm 0; vertical-align: middle; border-bottom: 0.5px dashed #ccc; }
.comanda-completar-tabla td.col-check { text-align: center; font-size: 10pt; }
.comanda-completar-tabla td.col-cant { text-align: right; width: 10mm; font-weight: bold; }
</style>
</head>
<body>
<div class="ticket-wrapper">
${contenido}
</div>
</body>
</html>`;
}

/* ── Helpers de formato ─────────────────────────────────────────────────────── */
function esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatoMoneda(n) {
    const num = Math.round(parseFloat(n) || 0);
    return 'C$ ' + num.toLocaleString('es-NI', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

/* ── Conversión de decimales a fracciones (punto 9) ────────────────────────── */
// Fracciones soportadas: 1/2, 1/4, 1/8, 1/3, 1/6
// Tolerancia para detectar el residuo fraccionario
const FRACCIONES_SOPORTE = [
    { num: 1, den: 2, valor: 0.5 },
    { num: 1, den: 4, valor: 0.25 },
    { num: 1, den: 8, valor: 0.125 },
    { num: 1, den: 3, valor: 1 / 3 },
    { num: 1, den: 6, valor: 1 / 6 },
];
const FRACCION_TOLERANCIA = 0.015; // tolera hasta ±0.015 de diferencia

function decimalAFraccion(numero) {
    const num = parseFloat(numero);
    if (isNaN(num)) return String(numero);

    const entero = Math.floor(num);
    const residuo = num - entero;

    // Si el residuo es casi 0 → número entero
    if (residuo < FRACCION_TOLERANCIA) {
        return String(entero);
    }

    // Buscar qué fracción se acerca más al residuo
    let mejorMatch = null;
    let mejorDif = Infinity;
    for (const frac of FRACCIONES_SOPORTE) {
        const dif = Math.abs(residuo - frac.valor);
        if (dif < mejorDif) {
            mejorDif = dif;
            mejorMatch = frac;
        }
    }

    // Si la mejor coincidencia está dentro de la tolerancia → usar fracción
    if (mejorMatch && mejorDif <= FRACCION_TOLERANCIA) {
        const fracStr = mejorMatch.num + '/' + mejorMatch.den;
        return entero > 0 ? entero + '+' + fracStr : fracStr;
    }

    // Sin coincidencia → mostrar decimales
    return num % 1 === 0 ? String(num) : num.toFixed(3).replace(/\.?0+$/, '');
}

/* ── Formatea la cantidad de un ingrediente con fracciones cuando aplica ───── */
function formatearCantidad(cantidad, unidad) {
    const num = parseFloat(cantidad);
    if (isNaN(num)) return String(cantidad);
    const fracStr = decimalAFraccion(num);
    return unidad ? fracStr + ' ' + unidad : fracStr;
}

function formatoFechaHora(fechaHora) {
    // fechaHora viene del servidor en formato "YYYY-MM-DD HH:mm:ss" o ISO
    const d = new Date(fechaHora);
    if (isNaN(d.getTime())) return fechaHora;

    const dia = String(d.getDate()).padStart(2, '0');
    const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    const mes = meses[d.getMonth()];
    const anio = String(d.getFullYear()).slice(-2);

    let horas = d.getHours();
    const min = String(d.getMinutes()).padStart(2, '0');
    const ampm = horas >= 12 ? 'pm' : 'am';
    horas = horas % 12 || 12;
    const horaStr = String(horas).padStart(2, '0') + ':' + min + ' ' + ampm;

    return { fecha: `${dia}-${mes}-${anio}`, hora: horaStr };
}

/* ══════════════════════════════════════════════════════════════════════════════
   RENDERIZADOR: FACTURA DEL CLIENTE
   ══════════════════════════════════════════════════════════════════════════════ */
function renderizarFacturaCliente(factura) {
    const { fecha, hora } = formatoFechaHora(factura.fecha_hora);
    const nombreCliente = factura.nombre_cliente || 'Cliente';
    const tienda = factura.nombre_sucursal || '';

    // ── Cabecera ────────────────────────────────────────────────────────────
    let html = `
<div class="ticket-header">
  <img src="${IMPRESION_LOGO_URL}" class="ticket-logo" alt="Batidos Pitaya">
</div>
<div class="ticket-bienvenida">Bienvenid@ ${esc(nombreCliente)}</div>
<div class="ticket-meta">
  Fecha: ${esc(fecha)}&nbsp;&nbsp;Hora: ${esc(hora)}<br>
  Tienda: ${esc(tienda)}
</div>
<hr class="ticket-sep-solid">

<table class="ticket-tabla">
  <thead>
    <tr>
      <th>Producto</th>
      <th class="col-num">Can</th>
      <th class="col-pu">PU</th>
      <th class="col-pt">PT</th>
    </tr>
  </thead>
  <tbody>`;

    // ── Líneas del detalle ─────────────────────────────────────────────────
    (factura.lineas || []).forEach(linea => {
        const pers = linea.personalizacion_parseada || {};

        html += `
    <tr>
      <td>
        <div class="ticket-prod-nombre">${esc(linea.nombre_producto)}</div>`;

        // Personalizaciones enlistadas debajo del producto
        const items = [];
        if (pers.endulzante_texto) {
            items.push('Endulzante: ' + pers.endulzante_texto);
        }
        if (pers.ingredientes_quitados && pers.ingredientes_quitados.length) {
            items.push('Sin: ' + pers.ingredientes_quitados.join(', '));
        }
        if (pers.ingredientes_sustituidos && pers.ingredientes_sustituidos.length) {
            pers.ingredientes_sustituidos.forEach(s => {
                items.push('Cambio: ' + s.nombre_original + ' por ' + s.nombre_nuevo);
            });
        }
        if (pers.extras && pers.extras.length) {
            items.push('Extra: ' + pers.extras.join(', '));
        }
        if (pers.notas_libres) {
            items.push('Nota: ' + pers.notas_libres);
        }

        if (items.length) {
            html += `<ul class="ticket-pers-lista">`;
            items.forEach(it => { html += `<li>${esc(it)}</li>`; });
            html += `</ul>`;
        }

        if (parseInt(linea.es_canje_puntos)) {
            html += `<div class="ticket-canje-badge">CANJE PUNTOS</div>`;
        }

        html += `
      </td>
      <td class="col-num">${esc(linea.cantidad)}</td>
      <td class="col-pu">${formatoMoneda(linea.precio_unitario_final)}</td>
      <td class="col-pt">${parseInt(linea.es_canje_puntos) ? 'C$ 0.00' : formatoMoneda(linea.precio_linea_total)}</td>
    </tr>`;
    });

    html += `
  </tbody>
</table>
<hr class="ticket-sep-solid">`;

    // ── Totales ────────────────────────────────────────────────────────────
    html += `<div class="ticket-totales">`;
    html += `<div class="ticket-totales-fila"><span>Sub-Total</span><span>${formatoMoneda(factura.subtotal)}</span></div>`;
    if (factura.descuento_total > 0) {
        html += `<div class="ticket-totales-fila"><span>Descuento</span><span>- ${formatoMoneda(factura.descuento_total)}</span></div>`;
    }
    if (factura.con_propina) {
        html += `<div class="ticket-totales-fila"><span>Propina (${factura.porcentaje_propina}%)</span><span>${formatoMoneda(factura.propina)}</span></div>`;
    }
    html += `<div class="ticket-totales-fila total-grande"><span>TOTAL</span><span>${formatoMoneda(factura.total)}</span></div>`;
    html += `</div>`;

    // ── Pie ────────────────────────────────────────────────────────────────
    html += `
<hr class="ticket-sep">
<div class="ticket-footer">
  Agradecemos su Visita<br>
  <span class="ticket-wifi">WiFi: ${esc(IMPRESION_WIFI_PASSWORD)}</span>
</div>`;

    return plantillaBase(html);
}

/* ══════════════════════════════════════════════════════════════════════════════
   RENDERIZADOR: COMANDA DE ESTACIÓN DE TRABAJO
   ══════════════════════════════════════════════════════════════════════════════ */
function renderizarComandaEstacion(comanda, factura) {
    const { fecha, hora } = formatoFechaHora(factura.fecha_hora);
    const nombreCliente = factura.nombre_cliente || 'Cliente';

    // ── Cabecera: puntos 2, 4 ─────────────────────────────────────────────
    // Punto 2: Cliente en una línea, Pedido en otra (grande y resaltado), Fecha/Hora en tercera
    // Punto 4: wrapper con margen inferior para separar visualmente del primer producto
    let html = `
<div class="comanda-estacion-titulo">${esc(comanda.nombre_grupo)}</div>
<div class="comanda-meta-wrapper">
  <div class="comanda-meta">
    <div class="comanda-meta-cliente"><strong>Cliente:</strong> ${esc(nombreCliente)}</div>
    <div class="comanda-meta-pedido"><strong>Pedido:</strong> ${esc(factura.id)}</div>
    <div class="comanda-meta-fechahora"><strong>Fecha:</strong> ${esc(fecha)} &nbsp; <strong>Hora:</strong> ${esc(hora)}</div>
  </div>
</div>
<hr class="ticket-sep-solid">`;

    // ── Productos de esta estación ─────────────────────────────────────────
    (comanda.productos || []).forEach(linea => {
        const pers = linea.personalizacion_parseada || {};

        html += `<div class="comanda-producto-bloque">`;

        // Encabezado: cantidad + nombre (punto 3: nombre más grande via CSS)
        html += `
<div class="comanda-prod-encabezado">
  <div class="comanda-prod-cantidad">${esc(linea.cantidad)}</div>
  <div class="comanda-prod-nombre">${esc(linea.nombre_producto)}</div>
</div>`;

        // Notas del cajero (texto libre)
        if (pers.notas_libres) {
            html += `
<div class="comanda-notas">
  <span class="comanda-notas-label">Notas: </span>${esc(pers.notas_libres)}
</div>`;
        }

        // Tabla de ingredientes
        html += `
<table class="comanda-ingredientes">
  <thead>
    <tr>
      <th class="col-tipo">T</th>
      <th>Ingrediente</th>
      <th class="col-cant">Cant</th>
    </tr>
  </thead>
  <tbody>`;

        // ── Endulzante (primera fila si el grupo lleva endulzante) ────────
        if (comanda.lleva_endulzante && linea.endulzante_comanda) {
            const e = linea.endulzante_comanda;
            const cantE = decimalAFraccion(parseFloat(e.factor) || 0);
            html += `
    <tr>
      <td class="col-tipo comanda-tipo-e">E</td>
      <td>${esc(e.nombre)} ${esc(e.unidad_medida)}</td>
      <td class="col-cant">${esc(cantE)}</td>
    </tr>`;
        } else if (comanda.lleva_endulzante && pers.endulzante_texto) {
            html += `
    <tr>
      <td class="col-tipo comanda-tipo-e">E</td>
      <td>${esc(pers.endulzante_texto)}</td>
      <td class="col-cant">-</td>
    </tr>`;
        }

        // ── Ingredientes de la receta con separadores de grupo (punto 6) ──
        // Agrupamos las filas por tipo para insertar separador entre grupos
        const ingredientes = linea.ingredientes_receta || [];
        let tipoAnterior = comanda.lleva_endulzante ? 'E' : null; // tipo del último bloque renderizado

        ingredientes.forEach((ing, idx) => {
            const nombreComponente = ing.nombre_componente || ing.nombre_ingrediente;
            const tipo = ing.tipo_comanda || '-';
            const cantidadNum = parseFloat(ing.cantidad) || 0;
            // Punto 9: cantidad solo como número/fracción, SIN unidad (la unidad va en el nombre del ingrediente si aplica)
            const cantStr = decimalAFraccion(cantidadNum);

            // Punto 6: insertar separador de media línea cuando cambia el tipo
            // (excepto antes del primer ingrediente si ya hubo endulzante del mismo tipo)
            if (tipoAnterior !== null && tipo !== tipoAnterior) {
                html += `
    <tr class="comanda-grupo-sep">
      <td colspan="3"><div class="comanda-grupo-sep-linea"></div></td>
    </tr>`;
            }
            tipoAnterior = tipo;

            // ¿Este ingrediente fue quitado?
            const esQuitado = (pers.ingredientes_quitados || []).some(
                q => q.toLowerCase() === nombreComponente.toLowerCase()
            );

            // ¿Este ingrediente fue sustituido?
            const sustitucion = (pers.ingredientes_sustituidos || []).find(
                s => s.nombre_original.toLowerCase() === nombreComponente.toLowerCase()
            );

            if (esQuitado) {
                html += `
    <tr class="comanda-fila-tachada">
      <td class="col-tipo">${esc(tipo)}</td>
      <td>${esc(nombreComponente)}</td>
      <td class="col-cant">${esc(cantStr)}</td>
    </tr>`;
            } else if (sustitucion) {
                // Fila original tachada
                html += `
    <tr class="comanda-fila-tachada">
      <td class="col-tipo">${esc(tipo)}</td>
      <td>${esc(nombreComponente)}</td>
      <td class="col-cant">${esc(cantStr)}</td>
    </tr>`;
                // Fila nuevo ingrediente
                html += `
    <tr class="comanda-fila-nuevo">
      <td class="col-tipo">${esc(tipo)}</td>
      <td>${esc(sustitucion.nombre_nuevo)}</td>
      <td class="col-cant">${esc(cantStr)}</td>
    </tr>`;
            } else {
                html += `
    <tr>
      <td class="col-tipo">${esc(tipo)}</td>
      <td>${esc(nombreComponente)}</td>
      <td class="col-cant">${esc(cantStr)}</td>
    </tr>`;
            }
        });

        // ── Extras agregados ──────────────────────────────────────────────
        // Punto 6: separador antes del bloque X si hubo ingredientes previos
        if ((ingredientes.length > 0 || comanda.lleva_endulzante) && (pers.extras || []).length > 0) {
            html += `
    <tr class="comanda-grupo-sep">
      <td colspan="3"><div class="comanda-grupo-sep-linea"></div></td>
    </tr>`;
        }
        (pers.extras || []).forEach(extraNombre => {
            html += `
    <tr>
      <td class="col-tipo comanda-tipo-x">X</td>
      <td>${esc(extraNombre)}</td>
      <td class="col-cant">1</td>
    </tr>`;
        });

        html += `
  </tbody>
</table>
</div>`; // /comanda-producto-bloque
    });

    // ── Sección "Completar Pedido con..." (punto 7 + punto 8) ──────────────
    // Punto 7: espacio arriba del bloque (via .comanda-completar-wrapper con margin-top)
    // Punto 8: tabla con encabezados (☐ Producto | Cant) para uso como checklist
    if (comanda.completar_con && comanda.completar_con.length > 0) {
        html += `
<div class="comanda-completar-wrapper">
  <div class="comanda-completar-titulo">Completar Pedido con...</div>
  <table class="comanda-completar-tabla">
    <thead>
      <tr>
        <th class="col-check">&#9744;</th>
        <th>Producto</th>
        <th class="col-cant-h">Can</th>
      </tr>
    </thead>
    <tbody>`;
        comanda.completar_con.forEach(item => {
            html += `
      <tr>
        <td class="col-check">&#9744;</td>
        <td>${esc(item.nombre_producto)}</td>
        <td class="col-cant">${esc(item.cantidad)}</td>
      </tr>`;
        });
        html += `
    </tbody>
  </table>
</div>`;
    }

    return plantillaBase(html);
}

/* ══════════════════════════════════════════════════════════════════════════════
   FUNCIÓN PÚBLICA: iniciarGuardadoEImpresion
   Llamada desde facturas_nueva.js al hacer click en "Guardar e Imprimir"
   ══════════════════════════════════════════════════════════════════════════════ */
function iniciarGuardadoEImpresion(payloadGuardado, onSuccess, onError) {
    // payloadGuardado = { tipo_cliente, id_cliente_club, nombre_cliente, detalle, pagos, ... }

    // 1. Guardar la factura
    $.post('/modulos/pos/ajax/facturas_guardar.php', payloadGuardado, function (res) {
        if (!res.success) {
            onError(res.message || 'Error al guardar la factura.');
            return;
        }

        const idFactura = res.id_factura;

        // 2. Obtener datos estructurados para impresión
        // Incluir cart_data para datos de personalización ricos (endulzante con factor)
        const cartData = payloadGuardado.detalle; // ya es JSON string
        $.get('/modulos/pos/ajax/facturas_get_imprimibles.php', {
            id_factura: idFactura,
            cart_data: cartData,
        }, function (payload) {
            if (!payload.success) {
                onError('Factura guardada pero no se pudieron obtener los datos de impresión.');
                return;
            }

            // 3. Lanzar secuencia de impresión
            lanzarImpresionCompleta(payload);

            // 4. Callback de éxito para que facturas_nueva.js resetee el formulario
            onSuccess(idFactura);

        }, 'json').fail(function () {
            onError('Error de conexión al obtener datos de impresión.');
        });

    }, 'json').fail(function () {
        onError('Error de conexión al guardar la factura.');
    });
}

