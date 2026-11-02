/* ================================================================
   pos_impresion_termica.js
   Módulo de impresión térmica 80mm — POS Batidos Pitaya
   Orquesta la generación y disparo de tickets de factura y comandas
   ================================================================ */

'use strict';

// ── Constantes configurables ─────────────────────────────────────────────────
const IMPRESION_WIFI_PASSWORD   = 'tubatidofavorito';
const IMPRESION_DELAY_MS        = 550;  // ms entre documentos para el spooler
const IMPRESION_CSS_URL         = '/modulos/pos/css/ticket_termico_80mm.css';
const IMPRESION_LOGO_URL        = '/assets/img/logo_pitaya.png';

/* ══════════════════════════════════════════════════════════════════════════════
   FUNCIÓN PRINCIPAL: lanzarImpresionCompleta
   Recibe el payload del servidor y orquesta la impresión secuencial.
   ══════════════════════════════════════════════════════════════════════════════ */
function lanzarImpresionCompleta(payload) {
    const { factura, comandas } = payload;

    // Construir lista de documentos HTML a imprimir en orden:
    // 1ro la factura del cliente, luego cada comanda de estación
    const documentos = [];

    documentos.push({
        tipo: 'factura',
        html: renderizarFacturaCliente(factura),
    });

    (comandas || []).forEach(comanda => {
        documentos.push({
            tipo: 'comanda',
            nombre: comanda.nombre_grupo,
            html: renderizarComandaEstacion(comanda, factura),
        });
    });

    // Imprimir secuencialmente con retardo entre cada uno
    imprimirSecuencial(documentos, 0);
}

/* ── Impresión secuencial vía iframes ───────────────────────────────────────── */
function imprimirSecuencial(documentos, indice) {
    if (indice >= documentos.length) return;

    const doc = documentos[indice];
    const iframe = document.createElement('iframe');

    iframe.style.position = 'fixed';
    iframe.style.top      = '-9999px';
    iframe.style.left     = '-9999px';
    iframe.style.width    = '80mm';
    iframe.style.height   = '1px';
    iframe.style.border   = 'none';
    iframe.style.opacity  = '0';

    document.body.appendChild(iframe);

    // Escribir HTML del documento en el iframe
    const iDoc = iframe.contentWindow.document;
    iDoc.open();
    iDoc.write(doc.html);
    iDoc.close();

    // Esperar a que cargue el CSS/imágenes antes de imprimir
    iframe.contentWindow.addEventListener('load', function () {
        setTimeout(() => {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                console.warn('Error al imprimir documento ' + indice, e);
            }

            // Siguiente documento después del retardo
            setTimeout(() => {
                document.body.removeChild(iframe);
                imprimirSecuencial(documentos, indice + 1);
            }, IMPRESION_DELAY_MS);
        }, 300); // pequeño delay para que el CSS renderice
    });
}

/* ══════════════════════════════════════════════════════════════════════════════
   RENDERIZADORES HTML
   ══════════════════════════════════════════════════════════════════════════════ */

/* ── Plantilla base HTML para cada ticket ───────────────────────────────────── */
function plantillaBase(contenido) {
    return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ticket</title>
<link rel="stylesheet" href="${IMPRESION_CSS_URL}">
<style>
  @media print {
    @page { size: 80mm auto; margin: 2mm; }
    body  { margin: 0; padding: 0; }
  }
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
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;');
}

function formatoMoneda(n) {
    const num = parseFloat(n) || 0;
    return 'C$ ' + num.toLocaleString('es-NI', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatoFechaHora(fechaHora) {
    // fechaHora viene del servidor en formato "YYYY-MM-DD HH:mm:ss" o ISO
    const d = new Date(fechaHora);
    if (isNaN(d.getTime())) return fechaHora;

    const dia   = String(d.getDate()).padStart(2, '0');
    const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    const mes   = meses[d.getMonth()];
    const anio  = String(d.getFullYear()).slice(-2);

    let horas   = d.getHours();
    const min   = String(d.getMinutes()).padStart(2, '0');
    const ampm  = horas >= 12 ? 'pm' : 'am';
    horas = horas % 12 || 12;
    const horaStr = String(horas).padStart(2, '0') + ':' + min + ' ' + ampm;

    return { fecha: `${dia}-${mes}-${anio}`, hora: horaStr };
}

/* ══════════════════════════════════════════════════════════════════════════════
   RENDERIZADOR: FACTURA DEL CLIENTE
   ══════════════════════════════════════════════════════════════════════════════ */
function renderizarFacturaCliente(factura) {
    const { fecha, hora } = formatoFechaHora(factura.fecha_hora);
    const nombreCliente   = factura.nombre_cliente || 'Cliente';
    const tienda          = factura.nombre_sucursal || '';

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
    const nombreCliente   = factura.nombre_cliente || 'Cliente';

    let html = `
<div class="comanda-estacion-titulo">${esc(comanda.nombre_grupo)}</div>
<div class="comanda-meta">
  <div class="comanda-meta-fila">
    <span><strong>Cliente:</strong> ${esc(nombreCliente)}</span>
    <span><strong>Pedido:</strong> ${esc(factura.id)}</span>
  </div>
  <div class="comanda-meta-fila">
    <span>Fecha: ${esc(fecha)}</span>
    <span>Hora: ${esc(hora)}</span>
  </div>
</div>
<hr class="ticket-sep-solid">`;

    // ── Productos de esta estación ─────────────────────────────────────────
    (comanda.productos || []).forEach(linea => {
        const pers = linea.personalizacion_parseada || {};

        html += `<div class="comanda-producto-bloque">`;

        // Encabezado: cantidad + nombre
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
            html += `
    <tr>
      <td class="col-tipo comanda-tipo-e">E</td>
      <td>${esc(e.nombre)} ${esc(e.unidad_medida)}</td>
      <td class="col-cant">${esc(e.factor)}</td>
    </tr>`;
        } else if (comanda.lleva_endulzante && pers.endulzante_texto) {
            // Fallback: texto plano del resumen de notas
            html += `
    <tr>
      <td class="col-tipo comanda-tipo-e">E</td>
      <td>${esc(pers.endulzante_texto)}</td>
      <td class="col-cant">-</td>
    </tr>`;
        }

        // ── Ingredientes de la receta ─────────────────────────────────────
        (linea.ingredientes_receta || []).forEach(ing => {
            const nombreComponente = ing.nombre_componente || ing.nombre_ingrediente;
            const tipo             = ing.tipo_comanda || '-';
            const cantidad         = parseFloat(ing.cantidad) || 0;
            const cantStr          = cantidad === Math.floor(cantidad)
                                        ? String(Math.floor(cantidad))
                                        : cantidad.toFixed(2);

            // ¿Este ingrediente fue quitado?
            const esQuitado = (pers.ingredientes_quitados || []).some(
                q => q.toLowerCase() === nombreComponente.toLowerCase()
            );

            // ¿Este ingrediente fue sustituido?
            const sustitucion = (pers.ingredientes_sustituidos || []).find(
                s => s.nombre_original.toLowerCase() === nombreComponente.toLowerCase()
            );

            if (esQuitado) {
                // Fila tachada
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
                // Fila normal
                html += `
    <tr>
      <td class="col-tipo">${esc(tipo)}</td>
      <td>${esc(nombreComponente)}</td>
      <td class="col-cant">${esc(cantStr)}</td>
    </tr>`;
            }
        });

        // ── Extras agregados ──────────────────────────────────────────────
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

    // ── Sección "Completar Pedido con..." ──────────────────────────────────
    if (comanda.completar_con && comanda.completar_con.length > 0) {
        html += `
<div class="comanda-completar-titulo">Completar Pedido con...</div>
<table class="comanda-completar-tabla">
  <tbody>`;
        comanda.completar_con.forEach(item => {
            html += `
    <tr>
      <td>${esc(item.nombre_producto)}</td>
      <td class="col-cant">${esc(item.cantidad)}</td>
    </tr>`;
        });
        html += `
  </tbody>
</table>`;
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
            cart_data:  cartData,
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

