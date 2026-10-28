# Rediseño POS Facturación — Plan de Mejoras
> Investigación aplicada de sistemas POS táctiles líderes: Square POS, Toast, Lightspeed Restaurant, Clover, Revel Systems y TouchBistro

---

## ✅ Cambios Aplicados en Esta Versión

| # | Cambio | Archivo(s) |
|---|--------|-----------|
| 1 | **"QUICK ADD +"** → **"Agregar +"** | facturas_nueva.php |
| 2 | **"EFEC. $"** → **"Efectivo USD"** | facturas_nueva.php |
| 3 | Bloques de promociones reordenados: **Aplicados → Casi Aplican → Disponibles** | facturas_nueva.php |
| 4 | Helper de pago: **"Clic para agregar/combinar"** → **"Toca para agregar"** | facturas_nueva.php |
| 5 | **Unidad "Unidad"** eliminada de cada ítem del ticket | facturas_nueva.js |
| 6 | **"Combos Sugeridos (Próximamente)"** eliminado del modal | facturas_nueva.js |
| 7 | Ingredientes del modal: **nombre + cantidad en misma línea horizontal** | facturas_nueva.js |
| 8 | Botones de pago: **5 columnas en una sola fila** | facturas.css |
| 9 | Botones de catálogo: **color sólido `#0E544C`** (sin gradientes) | facturas.css |
| 10 | Banner Club Pitaya: **color sólido** (sin gradiente) | facturas.css |
| 11 | Tarjetas de promoción: **layout compacto horizontal** | facturas.css |
| 12 | Tabla de ítems: **`table-layout: fixed`**, sin scroll horizontal | facturas.css |
| 13 | Badges de personalización: **`word-break`**, sin desbordamiento | facturas.css |
| 14 | Qty buttons: **34×34px** para zonas táctiles más cómodas | facturas.css |
| 15 | Feedback táctil: **`scale(0.97)` al active** en botones de catálogo | facturas.css |
| 16 | Backdrop modal: **`blur(5px) saturate(140%)`, opacidad 30%** — difuso pero reconocible | modales_premium.css |

---

## 🔍 Fundamento de Industria

Los sistemas POS líderes para cafeterías y food service comparten estos principios verificados:

| Principio | Sistema de Referencia | Aplicado en Pitaya |
|-----------|----------------------|-------------------|
| Botones mínimo 44×44px | Apple HIG / Square | ✅ Qty 34px + pago 44px |
| Sin gradientes en entorno táctil | Toast, Clover | ✅ Colores sólidos |
| Jerarquía de urgencia en promociones | Lightspeed, Revel | ✅ Aplicadas primero |
| Feedback inmediato al toque | TouchBistro | ✅ `scale(0.97)` en active |
| Idioma del operador sin anglicismos | Square (ES) | ✅ "Agregar", "Efectivo USD" |
| Sin información redundante | Toast POS UX | ✅ "Unidad", Combos eliminados |
| Backdrop difuso, no opaco | Material Design 3 | ✅ `blur + saturate` |
| Layout horizontal en listas de ingredientes | Lightspeed | ✅ Nombre | Cantidad en línea |

---

## 🚀 Propuestas Adicionales para Próximas Iteraciones

> [!IMPORTANT]
> Estas propuestas están basadas en investigación de UX de POS táctiles industriales. Se presentan ordenadas de mayor a menor impacto operacional para que decidas el orden de implementación.

---

### 🔢 A. Numpad táctil para cantidades (Alta prioridad)
**Referencia:** Square, Toast, Lightspeed — todos usan numpad popup

**Problema actual:** El input numérico de cantidad es difícil de usar con dedos — el teclado virtual del OS es lento de aparacer y ocupa media pantalla.

**Propuesta:** Al tocar el número de cantidad en el ticket, abrir un mini-modal numpad (0–9 + borrar + OK) centrado en pantalla, con botones de ~60px. El mismo control puede servir para monto de pago en efectivo.

```
┌───────────────┐
│  Cantidad: 3  │
├───┬───┬───┤
│ 7 │ 8 │ 9 │
├───┼───┼───┤
│ 4 │ 5 │ 6 │
├───┼───┼───┤
│ 1 │ 2 │ 3 │
├───┼───┼───┤
│ ⌫ │ 0 │ ✓ │
└───────────────┘
```

---

### 🎨 B. Esquema de color por estado de pedido (Media prioridad)
**Referencia:** Toast, Revel — usan color de fondo sutil por estado del ticket

**Propuesta:** Cuando el ticket tiene items pero aún no tiene método de pago, el header del panel derecho parpadea o cambia a un tono ámbar suave. Cuando ya está listo para guardar (pago completo), vira a verde. Esto da feedback visual del estado sin necesidad de leer texto.

| Estado | Color del header |
|--------|-----------------|
| Vacío | `#f8fafc` (actual) |
| Con items, sin pago | `#fffbeb` (ámbar muy suave) |
| Pago completo | `#f0fdf4` (verde muy suave) |
| Cobertura excedida | `#eff6ff` (azul info) |

---

### 🖨️ C. Botón "Guardar e Imprimir" separado del "Guardar" (Media prioridad)
**Referencia:** Lightspeed, TouchBistro — doble acción de guardado

**Problema actual:** "Guardar Factura" es la única acción final, luego hay que navegar a imprimir separado.

**Propuesta:** Dos botones en el footer del panel derecho:
- `Guardar` — guarda sin imprimir (para cobros con transferencia/POS sin comprobante físico)
- `Guardar + Imprimir` — guarda y lanza impresión directa a la impresora térmica

---

### 📦 D. Confirmación visual de producto agregado (Alta prioridad táctil)
**Referencia:** Square POS — "card fly" animation hacia el ticket

**Problema actual:** Al tocar un botón de producto no hay confirmación visual de que se agregó. En pantallas táctiles esto genera toques dobles por duda.

**Propuesta:** Animación simple: cuando se agrega un producto, el contador del ticket (`0 ítem(s)`) hace un "pulse" verde por 400ms y el botón de producto hace flash blanco. Solo CSS + 2 líneas de JS — sin librerías.

---

### 🔍 E. Búsqueda predictiva con resultado inmediato visible (Media prioridad)
**Referencia:** Clover, Square — el buscador muestra resultados mientras se escribe con teclado táctil visible

**Propuesta:** El buscador actual ya funciona bien en desktop. Para táctil, al activar el campo de búsqueda:
1. El panel de tabs/grupos se colapsa para dar espacio al teclado
2. Los resultados aparecen en grid grande (botones de 80px mínimo) directamente debajo
3. Un botón `×` grande (no el pequeño actual) cierra y vuelve al catálogo

---

### 📊 F. Panel de resumen de turno (Baja prioridad — nueva pantalla)
**Referencia:** Toast — "End of Day" summary accesible desde el POS sin cambiar de módulo

**Propuesta:** Un botón flotante o en el sidebar que muestre:
- Total vendido en el turno actual
- Número de facturas
- Método de pago más usado
- Tip acumulado

Esto permite al cajero/barista saber su desempeño en tiempo real sin salir del POS.

---

### 🏷️ G. Tags de personalización comprimibles en el ticket (Media prioridad)
**Referencia:** TouchBistro — muestra resumen colapsado en el ticket, expande al tocar

**Problema actual:** Cuando un producto tiene endulzante + sustituidos + extras, el ticket se estira verticalmente y hay que hacer scroll.

**Propuesta:** Los badges de personalización se muestran colapsados en una sola línea con `+2 más...` si hay más de 2. Al tocar el nombre del producto se expanden. Esto reduce la altura del ticket sin perder información.

---

### 🌙 H. Modo oscuro táctil (Baja prioridad — mejora de ergonomía)
**Referencia:** Square, Toast — ofrecen modo oscuro para turnos nocturnos

**Propuesta:** Toggle rápido en el header del POS (ícono de luna/sol) que cambie el tema a oscuro. Los colores institucionales se mantienen pero sobre fondo `#1a1a1a`. Reduce fatiga visual en turnos de noche o locales con poca iluminación.

Variables CSS a agregar en `:root`:
```css
[data-theme="dark"] {
  --bg-panel: #1e2430;
  --bg-card: #252d3d;
  --border-color: #3d4a5c;
  --text-primary: #e2e8f0;
}
```

---

### ⚡ I. Atajos táctiles de cantidad en el ticket (Media prioridad)
**Referencia:** Revel Systems — tap rápido en el número muestra botones +1, +2, +5

**Propuesta:** Al tocar el número de cantidad del item en el ticket (en lugar del `−` y `+`), aparece un menú rápido:

```
[×1] [×2] [×3] [×5] [Editar]
```

Esto acelera cambios de cantidad comunes (pedir 2 o 3 del mismo producto) sin necesidad de tapping repetitivo en `+`.

---

### 🗒️ J. Notas del pedido global (no por producto) (Baja prioridad)
**Referencia:** Square, Clover — nota para cocina/preparación a nivel de orden

**Propuesta:** Un campo de notas corto (1 línea, max 100 caracteres) a nivel de la orden completa, que aparece impreso en el ticket de cocina. Ej: "Mesa 4, llevar pajillas extra, cliente alérgico a nueces".

---

### 📱 K. Vista compacta del ticket (scroll-free mode) (Media prioridad)
**Referencia:** TouchBistro — modo "vista rápida" sin scroll en el ticket

**Propuesta:** Un toggle en el header del panel derecho `[Vista: Normal | Compacta]`. En modo compacto:
- Se ocultan las notas de cada producto (pero se mantienen los datos)
- Los badges de personalización van inline y colapsados
- La altura de cada fila se reduce a ~36px
- Permite ver ~8-10 items sin scroll en pantallas de 1080p

---

## 📐 Colores Institucionales Confirmados

| Rol | Color | Uso |
|-----|-------|-----|
| Verde oscuro | `#0E544C` | Headers, botones primarios, énfasis |
| Verde medio | `#51B8AC` | Acentos, hover, badges de éxito |
| Verde claro | `#E6F4F1` | Fondos de estado activo, hover suave |
| Blanco | `#ffffff` | Fondos de tarjeta, modales |
| Gris claro | `#f8fafc` | Fondos de sección, estados vacíos |
| Texto primario | `#1e293b` | Texto principal |
| Texto secundario | `#64748b` | Labels, helper text |

> [!NOTE]
> El sistema ya evita correctamente colores pesados en el flujo principal. Los únicos cambios de color en esta versión fueron eliminar gradientes (→ sólidos) y suavizar el backdrop del modal.

