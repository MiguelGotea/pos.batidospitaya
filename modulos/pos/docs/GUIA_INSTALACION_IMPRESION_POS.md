# Guía de Configuración: Impresión Silenciosa POS — Batidos Pitaya
**Versión:** 1.0 | **Fecha:** Nov 2026 | **Aplica a:** todos los puntos de venta

---

## ¿Qué es Kiosk Printing y por qué se usa?

En el POS de Batidos Pitaya, al guardar una factura el sistema genera e imprime automáticamente **hasta 3 tickets** (1 factura del cliente + 1 comanda por estación de trabajo involucrada). Sin Kiosk Printing, el navegador mostraría un diálogo de confirmación por cada ticket, lo que interrumpe el flujo en caja.

Con **Kiosk Printing** activado, `window.print()` envía directamente a la impresora predeterminada **sin mostrar ningún diálogo**, agilizando el proceso de venta.

> **Nota:** Si el navegador NO está en modo Kiosk, el sistema sigue funcionando normalmente. El cajero verá el diálogo estándar del navegador donde puede seleccionar la impresora manualmente. No es un error.

---

## PARTE 1: Configurar la Impresora Térmica 80mm en Windows

### 1.1 Instalar el driver de la impresora
1. Conectar la impresora térmica 80mm (USB o red).
2. Instalar el driver específico del fabricante (Epson, Star, Bixolon, etc.).
3. Verificar en **Panel de Control → Dispositivos e Impresoras** que aparece la impresora.

### 1.2 Configurar papel 80mm
1. Hacer clic derecho sobre la impresora → **Preferencias de impresión**.
2. En la pestaña **Papel** (o Paper/Media):
   - Seleccionar tamaño: `Receipt 80mm` o crear tamaño personalizado: **Ancho: 80mm, Alto: 0mm** (continuo).
3. En la pestaña **Opciones** (o Finishing/Cutting):
   - Activar: **Auto Cut** ✅ (o "Corte automático", "Document Cut", "Cut at end").
4. Clic en **Aceptar**.

### 1.3 Establecer como impresora predeterminada
1. Clic derecho sobre la impresora → **Establecer como impresora predeterminada**.
2. Verificar que aparece con la marca ✓ en verde.

### 1.4 Desactivar la asignación automática de Windows (recomendado)
Windows 10/11 puede cambiar la impresora predeterminada automáticamente al conectar otras impresoras:
1. **Inicio → Configuración → Dispositivos (o Bluetooth y dispositivos) → Impresoras y escáneres**.
2. Desactivar: **"Permitir que Windows administre mi impresora predeterminada"**.

---

## PARTE 2: Configurar Kiosk Printing en Google Chrome

### 2.1 Encontrar el ejecutable de Chrome
La ruta más común es una de estas:
```
C:\Program Files\Google\Chrome\Application\chrome.exe
C:\Program Files (x86)\Google\Chrome\Application\chrome.exe
```
Si no está en ninguna, abrir Chrome → barra de direcciones → escribir `chrome://version/` → copiar la ruta de "Executable Path".

### 2.2 Crear el acceso directo del POS
1. Hacer clic derecho en el **Escritorio** → **Nuevo → Acceso directo**.
2. En "¿A qué elemento desea crear un acceso directo?" escribir:
```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing --app="https://pos.batidospitaya.com/modulos/pos/facturas_nueva.php"
```
3. Clic en **Siguiente** → Nombre: `POS Batidos Pitaya` → **Finalizar**.
4. Cambiar ícono (opcional): clic derecho → Propiedades → Cambiar ícono → buscar `chrome.exe`.

> ⚠️ **IMPORTANTE:** El POS debe abrirse SIEMPRE usando este acceso directo, nunca abriendo Chrome normalmente y navegando a la URL, porque el parámetro `--kiosk-printing` solo aplica cuando se inicia con él.

### 2.3 Verificar
1. Abrir el POS usando el acceso directo.
2. Navegar a **Nueva Factura**, agregar un producto de prueba y guardar.
3. La impresión debe iniciarse **sin mostrar ningún diálogo**.

---

## PARTE 3: Configurar Kiosk Printing en Microsoft Edge

### 3.1 Encontrar el ejecutable de Edge
```
C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe
C:\Program Files\Microsoft\Edge\Application\msedge.exe
```

### 3.2 Crear el acceso directo del POS
1. Clic derecho en **Escritorio** → **Nuevo → Acceso directo**.
2. Ruta del acceso directo:
```
"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --kiosk-printing --app="https://pos.batidospitaya.com/modulos/pos/facturas_nueva.php"
```
3. Nombre: `POS Batidos Pitaya` → **Finalizar**.

---

## PARTE 4: Comportamiento del Sistema de Impresión

### 4.1 Documentos que se generan por cada venta
| # | Documento | Cuándo se genera |
|---|-----------|-----------------|
| 1 | **Factura del Cliente** | Siempre |
| 2 | **Comanda: Batidos y Limonadas** | Solo si la orden tiene productos de ese grupo |
| 3 | **Comanda: Bowls** | Solo si la orden tiene productos de ese grupo |
| 4 | **Comanda: Waffles** | Solo si la orden tiene productos de ese grupo |
| N | **Comanda: [otro grupo]** | Cualquier grupo futuro con `estacion_trabajo = 1` |

Cada documento se imprime como un **trabajo independiente** en el spooler de Windows, lo que hace que el driver de la impresora ejecute el **corte automático** entre cada uno.

### 4.2 Flujo completo
```
Cajero hace clic en "Guardar e Imprimir"
  ↓
Modal de confirmación: "¿Guardar e Imprimir? La factura quedará BLOQUEADA..."
  ↓
  [Cancelar] → vuelve al formulario sin cambios
  [Sí, Guardar e Imprimir]
  ↓
POST a facturas_guardar.php → guarda en BD (estado: bloqueada)
  ↓
GET a facturas_get_imprimibles.php → datos estructurados
  ↓
Iframe 1: Factura del cliente → print() → [corte de papel]
Iframe 2: Comanda estación 1  → print() → [corte de papel]
Iframe 3: Comanda estación N  → print() → [corte de papel]
  ↓
Reset del formulario → listo para la siguiente venta
```

### 4.3 Sin configuración Kiosk (modo normal)
Si el navegador no tiene `--kiosk-printing`:
- Al llegar al paso de impresión, aparece el **diálogo estándar del navegador**.
- El cajero debe seleccionar la impresora y confirmar.
- Esto ocurre una vez por cada ticket (factura + cada comanda).
- El sistema sigue funcionando correctamente, solo requiere más pasos manuales.

---

## PARTE 5: Solución de Problemas Comunes

### La impresora no corta el papel entre tickets
- Verificar que **Auto Cut** está activado en las Preferencias de la impresora.
- Asegurarse de que solo hay UNA impresora predeterminada en Windows.
- El corte ocurre entre trabajos de impresión. Si la impresora combina los trabajos en uno, revisar la configuración de **cola de impresión** del driver.

### Chrome muestra igualmente el diálogo de impresión
- Verificar que el acceso directo tiene exactamente `--kiosk-printing` (con doble guión).
- Cerrar todas las ventanas de Chrome y abrir desde el acceso directo.
- Verificar en Chrome: `chrome://version/` → "Command Line" debe incluir `--kiosk-printing`.

### La factura sale cortada o con márgenes incorrectos
- Revisar el tamaño de papel en las Preferencias: debe ser 80mm x continuo (no Letter/A4).
- En las Preferencias de impresión de Windows desactivar "Ajustar a página" o "Escalar".
- Verificar que el driver usa 72mm de área imprimible (2mm de margen a cada lado).

### Los tickets salen todos juntos sin corte
- Esto indica que el driver combina los trabajos. Aumentar el `IMPRESION_DELAY_MS` en `pos_impresion_termica.js` (por defecto 550ms). Prueba con 800ms o 1200ms.
- Ruta del archivo: `modulos/pos/js/pos_impresion_termica.js`, línea:
  ```javascript
  const IMPRESION_DELAY_MS = 550;
  ```

### Error "Factura guardada pero no se pudieron preparar los tickets"
- La venta SÍ quedó registrada en BD. No hay pérdida de datos.
- Puede ser un error de conexión momentáneo.
- Ir a **Historial de Facturas** para reimprimir si es necesario.

---

## PARTE 6: Lista de Verificación — Instalación en Nuevo Punto de Venta

**Hardware:**
- [ ] Impresora térmica 80mm conectada (USB o red)
- [ ] Driver instalado y funcionando
- [ ] Papel de 80mm cargado correctamente

**Configuración de Windows:**
- [ ] Impresora configurada como predeterminada
- [ ] Tamaño de papel: 80mm continuo
- [ ] Auto Cut activado en las Preferencias
- [ ] "Permitir que Windows administre la impresora predeterminada" DESACTIVADO

**Configuración del navegador:**
- [ ] Acceso directo creado con `--kiosk-printing --app="https://..."`
- [ ] El POS se abre SIEMPRE desde el acceso directo
- [ ] Prueba de impresión realizada y verificada con corte

**Sistema:**
- [ ] Login al POS funcionando correctamente
- [ ] Factura de prueba guardada e impresa exitosamente
- [ ] Los 3 tickets (factura + comandas) se imprimieron y cortaron correctamente

