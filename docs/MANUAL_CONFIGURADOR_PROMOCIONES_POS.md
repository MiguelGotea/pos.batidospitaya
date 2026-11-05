# Guía Práctica y Manual de Usuario — Configurador de Promociones del POS

> **¿Para quién es esta guía?**  
> Para cualquier persona de Mercadeo, Operaciones, Supervisión o Administración. **No necesitas saber de programación ni bases de datos.** Si sabes usar Facebook o Excel, podrás crear y modificar promociones en minutos siguiendo este manual.

---

## 📌 Índice Rápido
1. [¿Para qué sirve este módulo?](#-1-para-qué-sirve-este-módulo)
2. [¿Cómo entrar a la pantalla?](#-2-cómo-entrar-a-la-pantalla)
3. [Pantalla 1: La lista de todas tus promociones](#-3-pantalla-1-la-lista-de-todas-tus-promociones)
4. [Pantalla 2: Crear o Editar una Promoción (Paso a Paso)](#-4-pantalla-2-crear-o-editar-una-promoción-paso-a-paso)
   - [Paso 1: Datos Generales (Nombre, Fechas y Reglas)](#paso-1-datos-generales)
   - [Paso 2: Condiciones (¿Cuándo se activa la promo?)](#paso-2-condiciones-rule-builder)
   - [Paso 3: Objetivo (¿A qué producto le bajamos el precio?)](#paso-3-objetivo-del-descuento)
   - [Paso 4: Resultado (¿Cuánto dinero o porcentaje se descuenta?)](#paso-4-resultado)
5. [Recetario Práctico: Cómo crear las 5 promociones más comunes](#-5-recetario-práctico-ejemplos-reales)
   - [Ejemplo A: 2 Batidos por un precio especial fijo](#ejemplo-a-2-batidos-clásicos-por-c188)
   - [Ejemplo B: 2x1 tradicional (el segundo sale gratis)](#ejemplo-b-2x1-en-batidos-el-segundo-sale-gratis)
   - [Ejemplo C: Happy Hour (solo ciertas horas y días)](#ejemplo-c-happy-hour-martes-y-jueves-de-2-pm-a-5-pm)
   - [Ejemplo D: Promo exclusiva para Miembros Club Pitaya](#ejemplo-d-descuento-exclusivo-para-miembros-club-pitaya)
   - [Ejemplo E: Agrandar batido (Upgrade 16oz a 20oz gratis)](#ejemplo-e-agranda-tu-batido-de-16oz-a-20oz-sin-costo)
6. [Pantalla 3: El Simulador (Probar sin tocar ventas reales)](#-6-el-simulador-probar-sin-miedo)
7. [Preguntas Frecuentes y Solución de Errores](#-7-preguntas-frecuentes-y-resolución-de-dudas)

---

## 💡 1. ¿Para qué sirve este módulo?

Cuando los cajeros cobran en las tiendas con la tablet o pantalla del punto de venta (**POS**), este módulo es el "cerebro" que le dice al sistema:
- *¿Cuándo aplica una oferta?* (ej. solo los martes, o solo si el cliente es del Club).
- *¿Qué productos la activan?* (ej. si compran 2 batidos o 1 waffle).
- *¿Cuánto se le cobra al cliente?* (ej. rebajarle C$16 córdobas, o darle el 50% de descuento).

Cualquier cambio que hagas aquí **se refleja inmediatamente en todas las cajas de las tiendas**.

---

## 🚪 2. ¿Cómo entrar a la pantalla?

1. Inicia sesión en el ERP con tu usuario y contraseña.
2. En el menú lateral izquierdo, busca la sección **Ventas** o **POS** y haz clic en **Promociones POS** (ícono de etiqueta `fa-tags`).
3. También puedes ingresar directamente a este enlace en tu navegador:
   `https://erp.batidospitaya.com/modulos/POS/promociones.php`

---

## 📋 3. Pantalla 1: La lista de todas tus promociones

Al entrar, verás una tabla con todas las promociones que existen en la empresa.

### ¿Qué significa cada columna?

| Columna | ¿Qué te muestra? |
|---|---|
| **Código** | El número de identificación de la promo (ej. `12` o `ACC-277`). |
| **Nombre** | El título que le diste a la promoción. |
| **Estado** | <br>🟢 **Activa:** La tienda la está aplicando hoy.<br>🟡 **Inactiva:** Pausada temporalmente (no se borra, pero no aplica).<br>⚪ **Borrador:** La estás armando y todavía no quieres que salga en caja. |
| **Condiciones** | Número de requisitos que pide para activarse (ej. 2 condiciones: ser del Club y llevar 2 batidos). |
| **Resultado** | La fórmula de descuento (ej. `Monto fijo en producto` o `% sobre factura`). |
| **Vigencia** | Las fechas entre las que funciona. Si dice "Sin límite", no caduca nunca. |
| **Prioridad** | Si dos promociones chocan en el mismo producto, **el número más pequeño gana**. |

### Los botones de la tabla:
* **Botón verde grande `+ Nueva Promoción` (arriba a la derecha):** Haz clic aquí para crear una oferta nueva.
* **Ícono de Lápiz ✏️ (Editar):** Entra a modificar el precio, nombre o reglas de esa promoción.
* **Ícono de Matraz / Tubo de ensayo 🧪 (Probar):** Te lleva al simulador para probarla como si fueras un cajero.
* **Ícono de Basurero 🗑️ (Eliminar):** Borra la promoción (te pedirá confirmación para evitar accidentes).
* **Los Embudos 🌪️ en los títulos:** Haz clic en cualquier embudo para buscar rápido (por ejemplo, buscar solo las que digan "Waffle" o solo las "Activas").

---

## 🛠️ 4. Pantalla 2: Crear o Editar una Promoción (Paso a Paso)

Al presionar `+ Nueva Promoción` o al editar una existente, verás un formulario limpio dividido en **4 pasos numerados**:

```
[ 1. Encabezado ] ──▶ [ 2. Condiciones ] ──▶ [ 3. Objetivo ] ──▶ [ 4. Resultado ]
```

---

### Paso 1: Datos Generales

Aquí le pones nombre y decides cómo se comportará a nivel general:

1. **Nombre de la promoción (Obligatorio):**  
   Pon un nombre claro que los supervisores y cajeros reconozcan de inmediato.  
   *Ejemplo: "Martes 2x1 en Batidos Clásicos 16oz"*
2. **Estado:**  
   - Elige **Activa** para que ya empiece a funcionar en las cajas.
   - Elige **Borrador** si estás diseñándola y la terminarás después.
   - Elige **Inactiva** si es una promo de temporada que quieres apagar por ahora.
3. **Fecha inicio y Fecha fin:**  
   - Si la promo es solo por Semana Santa o Navidad, ponle fecha de inicio y fin. El sistema la desactivará solo a la medianoche cuando termine el día.
   - Si es una promo que estará disponible siempre, **deja la fecha fin vacía**.
4. **Prioridad (Número de 1 a 100):**  
   - Por defecto ponle `10`.  
   - Si es una súper promoción de aniversario que NUNCA debe ser tapada por otra, ponle `1`.
5. **Interruptores (Switches de activación):**
   - **Ejecución automática (Recomendado ACTIVADO):**  
     Si el switch está verde, la caja aplica el descuento solita apenas el cajero marque los productos. Si lo apagas, el cajero tiene que tocar manualmente el botón de la promo en su pantalla.
   - **Combinable con otras:**  
     ¿Se puede usar al mismo tiempo que otro cupón o promoción? Si no estás seguro, déjalo **apagado** para proteger el margen de ganancia.
   - **Uso único por cliente:**  
     Enciéndelo si la promo es un regalo de bienvenida que solo se le puede dar una vez en la vida a cada cliente registrado.
   - **Requiere autorización:**  
     Enciéndelo si quieres que la caja pida el PIN o contraseña del líder de tienda antes de descontar.

---

### Paso 2: Condiciones (Rule Builder)

> 💡 **La regla de oro:** Todas las condiciones que agregues aquí se deben cumplir **al mismo tiempo** (lógica "Y"). Si agregas "Martes" y agregas "Batidos", solo aplicará los martes cuando lleven batidos.

Haz clic en el botón azul **`+ Agregar condición`** y elige lo que necesites:

#### 🕒 Condiciones de Contexto (¿Cuándo y Dónde?)
* **Día de semana:** Te muestra botones con los 7 días. Haz clic sobre los días en que es válida (ej. solo lunes y miércoles).
* **Horario:** Ponle hora `Desde` y `Hasta` (ej. de 14:00 a 17:00 para un Happy Hour de la tarde).
* **Sucursal:** Si la oferta es solo para liquidar inventario en *Granada* o *Masaya*, selecciónala aquí. Si aplica en todas las tiendas, no agregues esta condición.
* **Tipo de cliente:** Elige si aplica a clientes del `Club`, `General` (cualquier persona que llegue al mostrador), o `PedidosYa`.

#### 🛒 Condiciones de Carrito (¿Qué tiene que comprar el cliente?)
* **Grupo / Subgrupo:** Te permite exigir una categoría de productos:
  1. Eliges el Grupo: *Batidos y Limonadas* (o *Waffles*, *Bowls*, etc.).
  2. Eliges el Subgrupo: *Clásicos* (o *Especiales*, *Premium*, etc.).
  3. Indicas la cantidad: Pon `2` si la oferta exige comprar 2 batidos.
* **Tamaño:** Marca si exige tamaño *16oz* o *20oz*.
* **Producto específico:** Si la oferta no es para todo un grupo, sino para un artículo puntual (ej. solo la *Galleta de Avena* o solo el batido *León*), elígelo con el buscador.
* **Cantidad mínima:** Exige que en total lleven al menos $X$ cantidad de productos en la cuenta.
* **Monto mínimo de factura:** Exige que la cuenta alcance un subtotal (ej. "En compras mayores a C$ 300").

*(Si te equivocas o agregaste una de más, haz clic en la **[✕] roja** al lado de la tarjeta para quitarla).*

---

### Paso 3: Objetivo del Descuento

Aquí le indicas a la computadora: **¿A cuál producto de la cuenta le vamos a rebajar el dinero?**

Haz clic en una de las tarjetas:

1. **🎯 Todos los que califican:**  
   Le descuenta a cada uno de los productos que cumplieron la condición.  
   *(Ejemplo: si la promo descuenta C$ 8 por batido, le rebaja C$ 8 al primero y C$ 8 al segundo).*
2. **💲 El más barato:**  
   El sistema busca el producto de menor precio y le aplica el descuento solo a ese.  
   *(Ideal para "Compra 2 batidos y el segundo a mitad de precio" o "El segundo gratis").*
3. **🎁 Producto distinto (Get Y):**  
   El cliente compra el producto A, pero el regalo o descuento se lo gana en el producto B.  
   *(Ejemplo: "Compra un Waffle y llévate un Café por C$ 10").*
4. **🧾 Toda la factura:**  
   El descuento no se amarra a ningún producto en particular, sino que le resta dinero al subtotal de la cuenta completa.
5. **⬆️ Upgrade de tamaño:**  
   Especial para agrandar. El cliente pide 20oz pero se le cobra al precio de 16oz.

---

### Paso 4: Resultado (¿Cuánto se descuenta?)

1. **Elige la forma de calcular:**
   * **`% sobre producto`:** Si vas a descontar un porcentaje de la unidad (ej. pon `50` para mitad de precio, o `100` para que salga gratis).
   * **`Monto fijo — producto`:** Si vas a descontar una cantidad exacta de córdobas por producto (ej. pon `8` para rebajar C$ 8.00 córdobas a cada uno).
   * **`% sobre factura`:** Si vas a descontar un porcentaje de la cuenta total (ej. pon `10` para un 10% de descuento global).
   * **`Monto fijo — factura`:** Si vas a descontar una cantidad plana a la cuenta (ej. pon `50` para rebajar C$ 50 córdobas directos a la cuenta).

2. **Valor del descuento:** Escribe aquí el número (el porcentaje o los córdobas).

3. **Revisa la "Vista Previa en Vivo":**  
   Al fondo de la pantalla verás un cuadro azul con un ícono de ojo 👁️. **Lee lo que dice.**  
   El sistema escribe en español sencillo lo que acabas de configurar (ej: *"Aplica los Martes a clientes Club con 2 Batidos Clásicos 16oz. Descuenta C$ 8.00 a cada producto"*). Si el texto dice exactamente lo que tú querías, ¡lo hiciste perfecto!

4. **Haz clic en el botón verde `Guardar promoción`:**  
   ¡Listo! Verás un mensaje de confirmación y la promoción queda guardada.

---

## 📖 5. Recetario Práctico: Ejemplos Reales

Si tienes una idea comercial y no sabes cómo armarla, busca aquí la "receta" equivalente:

---

### Ejemplo A: 2 Batidos Clásicos por C$ 188 (Promoción Club)
*El precio normal de cada uno es C$ 102 (total C$ 204). La oferta los deja en C$ 188 (ahorro total de C$ 16, es decir, C$ 8 de descuento en cada uno).*

* **Paso 1 (Encabezado):** Nombre: `2 Batidos clásicos 16oz x C$188`. Estado: `Activa`.
* **Paso 2 (Condiciones):**
  - Condición 1: `Tipo de cliente` ➔ Marca solo **Club**.
  - Condición 2: `Grupo / Subgrupo` ➔ Grupo: **Batidos y Limonadas**, Subgrupo: **Clasicos**, Cantidad: **2**.
  - Condición 3: `Tamaño` ➔ Marca **16oz**.
* **Paso 3 (Objetivo):** Elige **🎯 Todos los que califican**.
* **Paso 4 (Resultado):** Elige **Monto fijo — producto** y en Valor escribe `8.00`.
* **Guardar:** ¡Listo!

---

### Ejemplo B: 2x1 en Batidos (El segundo sale gratis)
*El cliente lleva 2 batidos cualesquiera y no paga el de menor precio.*

* **Paso 1 (Encabezado):** Nombre: `2x1 en Batidos`.
* **Paso 2 (Condiciones):**
  - Condición 1: `Grupo / Subgrupo` ➔ Grupo: **Batidos y Limonadas**, Cantidad: **2**.
* **Paso 3 (Objetivo):** Elige **💲 El más barato**.
* **Paso 4 (Resultado):** Elige **% sobre producto** y en Valor escribe `100` (100% de descuento = gratis).
* **Guardar:** ¡Listo!

---

### Ejemplo C: Happy Hour (Martes y Jueves de 2 PM a 5 PM)
*Queremos dar 20% de descuento en Waffles en horario bajo.*

* **Paso 1 (Encabezado):** Nombre: `Happy Hour Waffles 20% OFF`.
* **Paso 2 (Condiciones):**
  - Condición 1: `Día de semana` ➔ Marca solo **Martes** y **Jueves**.
  - Condición 2: `Horario` ➔ Desde: `14:00`, Hasta: `17:00`.
  - Condición 3: `Grupo / Subgrupo` ➔ Grupo: **Waffles**, Cantidad: **1**.
* **Paso 3 (Objetivo):** Elige **🎯 Todos los que califican**.
* **Paso 4 (Resultado):** Elige **% sobre producto** y en Valor escribe `20`.
* **Guardar:** ¡Listo! A las 5:01 PM dejará de aplicar automáticamente.

---

### Ejemplo D: Descuento exclusivo para Miembros Club Pitaya
*10% de descuento en el total de la cuenta a cualquiera que presente su carnet o número de Club.*

* **Paso 1 (Encabezado):** Nombre: `10% Descuento Miembro Club`.
* **Paso 2 (Condiciones):**
  - Condición 1: `Tipo de cliente` ➔ Marca solo **Club**.
* **Paso 3 (Objetivo):** Elige **🧾 Toda la factura**.
* **Paso 4 (Resultado):** Elige **% sobre factura** y en Valor escribe `10`.
* **Guardar:** ¡Listo!

---

### Ejemplo E: Agranda tu batido de 16oz a 20oz sin costo
*Pide un batido de 20oz pero se le rebaja la diferencia de precio (C$ 10) para que pague como si fuera de 16oz.*

* **Paso 1 (Encabezado):** Nombre: `Agranda tu batido gratis (Upgrade a 20oz)`.
* **Paso 2 (Condiciones):**
  - Condición 1: `Grupo / Subgrupo` ➔ Grupo: **Batidos y Limonadas**, Cantidad: **1**.
  - Condición 2: `Tamaño` ➔ Marca **20oz**.
* **Paso 3 (Objetivo):** Elige **🎯 Todos los que califican** (o Upgrade).
* **Paso 4 (Resultado):** Elige **Monto fijo — producto** y en Valor escribe `10.00`.
* **Guardar:** ¡Listo!

---

## 🧪 6. El Simulador (Probar sin miedo)

Si tienes dudas de si tu promoción quedó bien configurada y no quieres probar cobrando en una tienda real:

1. Ve a la pantalla del simulador:  
   `https://erp.batidospitaya.com/modulos/POS/prueba_promociones.php`  
   *(o haz clic en el ícono del matraz 🧪 en la lista de promociones).*
2. En la columna izquierda selecciona el escenario:
   - ¿Qué día quieres simular? (ej. Martes).
   - ¿Qué hora? (ej. 15:30).
   - ¿Qué cliente? (ej. Club Pitaya).
3. Agrega productos al carrito simulado (ej. 2 Batidos Dinamita 16oz).
4. Haz clic en **Evaluar Promociones**.
5. Verás el resultado:
   - 🟩 **Caja Verde (Aplicada):** Te dice el nombre de la promo y cuánto descontó en dinero real.
   - 🟥 **Caja Roja (Rechazada):** Te dice exactamente por qué no pasó (ej: *"Rechazada: se requieren 2 productos del grupo y solo hay 1"*).

---

## ❓ 7. Preguntas Frecuentes y Resolución de Dudas

#### ¿Qué hago si una promoción ya no debe estar disponible?
No la borres con el basurero. Simplemente haz clic en **Editar** ✏️, cambia el Estado a **Inactiva** y guarda. De esa forma la oferta deja de aplicar en caja de inmediato, pero si el próximo mes la vuelven a necesitar, solo le das clic a "Activa" y no tienes que volver a escribir todo.

#### ¿Por qué no le aplicó el descuento al cliente en la caja?
Casi siempre se debe a una de estas 3 razones:
1. **El tipo de cliente:** La promo exige ser cliente *Club* y el cajero dejó la venta como cliente *General* en la pantalla.
2. **El tamaño:** La promo exige *16oz* y el cajero marcó batidos de *20oz*.
3. **Cantidad insuficiente:** La promo pide 2 batidos y en el carrito solo hay 1.

#### ¿El cajero puede alterar el descuento a mano?
No. El motor es automático. El cajero solo agrega los productos y el sistema aplica la rebaja matemática autorizada de acuerdo a las reglas que tú configuraste aquí.
