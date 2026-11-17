# Guia Rapida — POS Pitaya

> Documento vivo. Se ira alimentando con cada herramienta habilitada.
> **Última actualización: 2026-11-17**

---

## Nueva Venta (`/modulos/pos/facturas_nueva.php`)

### 1. Tipo de cliente
Al ingresar a Nueva Venta, lo primero es seleccionar el tipo de cliente en el header del panel derecho:
- **Cliente General:** Se escribe el nombre del cliente (placeholder: "Escriba nombre del cliente general..."). No requiere número de membresía.
- **Club Pitaya:** Se ingresa el número de membresía del cliente y se busca su perfil. El sistema mostrará su saldo de puntos acumulados, los puntos que generará esta compra y el total proyectado.

### 2. Selección de productos (Menú)
En el panel izquierdo se encuentra el catálogo de productos organizado por grupos. Se puede navegar por las pestanas de categorías o usar el buscador para encontrar un producto por nombre. Al seleccionar un producto, se abrirá una ventana para elegir la presentación (tamaño) y personalizar ingredientes si aplica. Al confirmar, el producto se agrega a la factura.

### 3. Venta de Membresía Club Pitaya

El botón **+ Nueva Membresía** está en el header del panel "Tipo de Cliente" (esquina superior derecha del panel de cliente). Al pulsarlo:

1. Se abre un modal de registro rápido con dos campos:
   - **N° MEMBRESÍA** — número de la tarjeta física (se verifica que no esté registrado)
   - **CELULAR** — 8 dígitos exactos (sin código de país)
2. Al registrar:
   - El socio queda en BD (`clientesclub`) con datos básicos
   - Se agrega automáticamente al carrito una línea **Membresía Club Pitaya** con badge verde `#NUMERO`
   - El campo N° MEMBRESÍA se autocompleta y el modo de cliente cambia a Club Pitaya
   - El cliente recibe un WhatsApp con el link para completar su perfil (foto de cédula)
3. La línea de membresía en el carrito:
   - Cantidad fija = 1 (no editable)
   - Sin botones de editar ni copiar — solo eliminar (🗑️)
   - Eliminar activa confirmación SweetAlert y hace rollback del socio en BD (solo si aún no completó el preregistro)
   - No acumula puntos

### 4. Promociones
El sistema evalúa automáticamente si el pedido actual cumple con alguna promoción activa. Las promociones se muestran en dos bloques:
- **Aplicables:** el carrito ya cumple las condiciones.
- **Activos:** promociones disponibles que aún no se han activado con el pedido actual.

### 5. Detalle de la factura
En el panel derecho se muestra la lista de productos agregados con cantidad y precio. Desde ahí se puede ajustar la cantidad de cada item.

### 6. Método de pago
Se selecciona uno o varios métodos de pago disponibles:
- Efectivo córdobas (C$)
- Efectivo dólares (USD) — el sistema aplica el tipo de cambio vigente
- POS (tarjeta)
- Transferencia bancaria
- Cupón de descuento

También se puede activar la **propina del 2%** mediante un interruptor en el desglose de totales.

### 7. Guardar e Imprimir
Una vez que los pagos cubren el total de la factura, se habilita el botón **Guardar e Imprimir**, que registra la venta y genera el ticket.

### 8. Editar un producto ya agregado (icono ✏️)

Cada linea del carrito tiene un icono de **lapiz** que permite editar el producto **despues de haberlo agregado**.

#### Que puedes hacer desde el modo edicion:
- **Cambiar la variante** (ej. de Pina Colada 20oz → 16oz): las tabs superiores muestran todas las presentaciones del mismo catalogo.
- **Ajustar ingredientes**: quitar o sustituir ingredientes de la nueva variante.
- **Cambiar endulzante** o cantidad de sobres.
- **Agregar o quitar extras**.

#### Como funciona la fusion de personalizacion:
Al cambiar de variante, el sistema transfiere automaticamente tu personalizacion anterior a la nueva variante:
- **Notas, endulzante y extras**: siempre se conservan.
- **Ingredientes quitados/sustituidos**: se conservan si el mismo ingrediente existe en la nueva variante (identificado por id_ingrediente). Si un ingrediente no existe en la nueva variante, se descarta silenciosamente.

#### Cancelar vs Aplicar:
- **Cancelar**: el carrito no cambia, se descarta cualquier modificacion.
- **Aplicar**: el producto en el carrito se actualiza con la nueva variante y/o personalizacion. El precio se recalcula automaticamente (precio base de la nueva variante + extras).

---

## Portal de Preregistro Club Pitaya (`club.batidospitaya.com/preregistro/`)

El socio recibe por WhatsApp un enlace único (`?t=TOKEN`) para completar su perfil.

### Qué hace el portal:
1. Valida el token HMAC firmado (sin consultar BD) — si es inválido muestra error 403
2. Si el socio ya completó el perfil → muestra pantalla "ya completado"
3. Si no: muestra formulario con subida de foto de cédula
4. Al pulsar **"Subir Cédula"**, Gemini Vision analiza la imagen y extrae automáticamente:
   - Nombre completo (todos los nombres)
   - Apellido completo (todos los apellidos)
   - Número de cédula (formato `NNN-DDMMAA-NNNNL`)
   - Fecha de nacimiento (YYYY-MM-DD desde los dígitos de la cédula)
5. Los campos extraídos son de **solo lectura** — solo el correo es editable manualmente
6. Al guardar: `UPDATE clientesclub SET nombre, apellido, cedula, fecha_nacimiento, correo`

### Token de acceso (seguridad):
- Firmado con HMAC-SHA256 sobre el número de membresía
- Stateless: no requiere columna adicional en `clientesclub`
- El mismo link es permanente (misma membresía = mismo token siempre)
- Archivo de secreto: `core/config/club_token_secret.php` (replicado por PitayaCore)
