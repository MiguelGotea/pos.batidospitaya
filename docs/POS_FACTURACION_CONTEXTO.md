# Contexto Técnico: Módulo de Facturación / POS
## Batidos Pitaya ERP — `u839374897_erp`
**Versión del documento:** 1.2 | **Fecha:** 2026-05-05

---

## 1. Visión General del Módulo

El módulo POS/Facturación es el núcleo operacional de cada tienda. Permite al cajero construir un ticket seleccionando productos por grupos/subgrupos, personalizarlos (cambiar/quitar ingredientes, agregar extras, elegir endulzante), aplicar promociones, identificar al cliente (Club Pitaya o nombre libre), y cerrar la venta con uno o múltiples métodos de pago. Soporta modo offline (PWA + SQLite local) con sincronización en cola al recuperar conexión.

### Tecnología del POS
- **Frontend**: PWA (Progressive Web App) con Service Worker
- **Offline**: SQLite local. Switch automático al detectar `navigator.onLine === false`
- **Sincronización**: Cola ordenada por `hora_apertura` al recuperar conexión
- **Idempotencia**: `codigo_offline = crypto.randomUUID()` generado en el dispositivo; el servidor hace `INSERT IGNORE` con `UNIQUE KEY`

---

## 2. Archivos Clave del Proyecto

| Archivo | Descripción |
|---|---|
| `sql/06_tablas_facturacion_pos.sql` | Script SQL definitivo v4.2 — 14 tablas con prefijo `pos_ventas_` |
| `modulos/POS/promociones.php` | Listado de promociones |
| `modulos/POS/promocion_form.php` | Formulario de creación/edición de promociones (Rule Builder) |
| `modulos/POS/js/promociones.js` | Lógica del Rule Builder en frontend |
| `modulos/productos/registro_producto_global.php` | Registro de productos (grupos, subgrupos, recetas, extras, endulzantes) |
| `modulos/productos/pedido_sugerido.php` | Módulo de inventario sugerido semanal |
| `GitHub Pitaya/SQL/u839374897_erp (solo script de tablas).sql` | Script completo de tablas existentes en la BD (Maestro) |

---

## 3. Tablas Existentes Reutilizadas (sin modificar)

| Tabla | Uso en Facturación |
|---|---|
| `sucursales` | FK para identificar la tienda que emite la factura |
| `Operarios` | El cajero activo en la sesión POS |
| `clientesclub` | Miembros del Club Pitaya. Clave: `id_clienteclub`, `membresia` (número de tarjeta) |
| `producto_presentacion` | El ítem vendible final. Incluye batidos 16oz, batidos 20oz (recetas distintas), extras, endulzantes |
| `componentes_receta_producto` | Ingredientes de cada receta. Cada ingrediente tiene `id_presentacion_producto` → FK a `producto_presentacion` |
| `receta_producto_global` | Receta maestra de un producto |
| `grupo_presentacion_producto` | Grupos de productos: Batidos, Waffles, Bowls, Extras, Endulzantes, etc. |
| `subgrupo_presentacion_producto` | Subgrupos: Clásicos, Premium, Proteicos, etc. |
| `cupones_sucursales` | Cupones físicos con monto de descuento. Al usarse, se marca `aplicado = 1` |
| `promo_promociones` | **Catálogo maestro de promociones** (nombre, prioridad, estado, objetivo, resultado, fechas) |
| `promo_condiciones` | Reglas del Rule Builder por promoción (tipo A=Contexto, B=Carrito, valor en JSON) |
| `promo_condiciones_opciones` | Catálogo de tipos de condición disponibles |
| `pos_caja_inicial` | Apertura de caja por sucursal y día (Existente para conteo de efectivo) |
| `pos_ventas_tipo_cambio` | Tabla existente de tipos de cambio (revisar si se usa o se crea la nueva) |

### Nota sobre Extras y Endulzantes
- **No usan tablas separadas.** Ambos son registros en `producto_presentacion` dentro de sus grupos respectivos.
- Esto permite mapear el consumo de insumos igual que cualquier otro producto.

---

## 4. Tablas Nuevas Creadas (14) — Prefijo `pos_ventas_`
### 4.1 Infraestructura

#### `pos_ventas_tipo_cambio`
Historial de tasa USD/NIO. Global (aplica a todas las sucursales). Se registra por fecha.
- **Cuándo se usa**: Cuando un pago es en USD (`pos_ventas_factura_pagos.moneda = 'USD'`). Solo Efectivo puede ser USD.
- **Campos clave**: `fecha`, `tasa` (córdobas por 1 dólar)

#### `pos_ventas_precios_producto`
Historial de precios por producto. Vigencia definida por `fecha_desde` / `fecha_hasta`.
- `cod_sucursal = NULL` → precio global (aplica a todas las tiendas)
- `cod_sucursal != NULL` → override de sucursal específica (tiene precedencia sobre el global)
- **Lógica de Tamaños**: Un producto de **16oz** debe tener un precio menor a uno de **20oz**.
- El POS busca: precio de sucursal vigente en fecha → si no, precio global vigente en fecha
- **Campos clave**: `id_producto_presentacion`, `cod_sucursal`, `precio`, `fecha_desde`, `fecha_hasta`

### 4.2 Club Pitaya — Puntos

#### `pos_ventas_puntos_reglas`
**Tabla unificada** para reglas de acumulacion Y canje de puntos. Modelo jerarquia (catalogo menu POS + override por presentacion) con historial cronologico.

**Tipos de regla** (`tipo_regla`): `'acumulacion'` | `'canje'`

**Jerarquia de busqueda** (motor en `facturas_guardar.php`):
1. Busca regla vigente con `tipo_regla + id_catalogo_menu_pos + id_producto` especifico
2. Si no hay, busca regla del catalogo base (`id_producto IS NULL`)
3. Si tampoco hay, usa fallback de 1 punto por unidad (solo acumulacion)

**Campos clave**: `tipo_regla`, `id_catalogo_menu_pos` (FK -> `catalogo_menu_pos`), `id_producto` (NULL = aplica al catalogo completo), `puntos`, `fecha_desde`, `fecha_hasta`

**Historial**: Al cambiar la politica, se cierra la regla anterior (`fecha_hasta`) y se crea una nueva con nueva `fecha_desde`. Las facturas historicas calculan puntos con la regla vigente en su fecha.

> **Nota**: La tabla `pos_ventas_puntos_catalogo_canje` fue eliminada. Todo el catalogo de canje ahora vive en `pos_ventas_puntos_reglas` con `tipo_regla = 'canje'`.

### 4.3 Factura (Núcleo)

#### `pos_ventas_facturas`
Cabecera del ticket de venta. Una fila = una transacción completa.

**Ciclo de vida (`estado`)**:
```
en_proceso → completada → bloqueada → anulada_post_impresion
```
- `en_proceso`: Cajero agregando productos
- `completada`: Pagos registrados, lista para imprimir
- `bloqueada`: **Después de la 1ª impresión. INMUTABLE.** El servidor rechaza cualquier INSERT/UPDATE/DELETE en tablas relacionadas.
- `anulada_post_impresion`: Factura anulada después de haber sido impresa

**Campos clave**:
- `id_correlativo`: Número secuencial por día y sucursal. Se asigna al sincronizar.
- `codigo_offline`: UUID v4 generado en el dispositivo. Garantiza idempotencia.
- `tipo_cliente`: `'club'` (con membresía) | `'general'` (solo nombre libre)
- `id_cliente_club`: FK → `clientesclub.id_clienteclub` (solo si `tipo_cliente = 'club'`)
- `nombre_cliente_general`: VARCHAR libre, sin FK (solo si `tipo_cliente = 'general'`)
- `notas_globales`: Instrucciones que aplican a toda la orden
- `con_propina`: Toggle. Propina hardcodeada en 2% (o 0% si no aplica)
- `para_llevar`: 0 = para aquí / 1 = para llevar (nivel global)
- `hora_apertura`, `hora_ultimo_cambio`, `hora_primera_impresion`, `total_impresiones`: Ciclo de vida completo

**Propina**: Hardcodeada. El POS ofrece dos opciones: 0% o 2%. No configurable por BD.

#### `pos_ventas_factura_lineas`
Un ítem por fila. Snapshot de precio al momento de la venta.

**Campos clave**:
- `id_producto_presentacion`: FK al producto (ya incluye el tamaño — 16oz y 20oz son productos distintos con recetas distintas)
- `cantidad`: Unidades de ese producto
- `precio_unitario_snap`: Precio tomado de `pos_precios_producto` vigente en la fecha
- `precio_extras_total`: Suma de todos los extras de esta línea
- `precio_unitario_final`: `precio_unitario_snap + extras - descuento_unitario`
- `precio_linea_total`: `precio_unitario_final × cantidad`
- `id_endulzante_producto`: FK → `producto_presentacion` (endulzante es un producto del grupo Endulzantes)
- `nivel_endulzante`: `'sin'` | `'bajo'` | `'normal'` | `'alto'`
- `para_llevar_item`: Puede diferir del nivel de factura
- `es_canje_puntos`: 1 = ítem canjeado, `precio_unitario_snap = 0.00`
- `bloquea_puntos`: 1 = esta línea NO acumula puntos (tiene descuento aplicado, o es canje)

**`bloquea_puntos = 1` cuando**:
- La línea tiene al menos una fila en `pos_linea_descuentos_promo`, O
- La factura completa tiene un descuento global (promo tipo `'factura'`), O
- `es_canje_puntos = 1`

#### `pos_ventas_factura_lineas_eliminadas`
Auditoría de líneas quitadas de la factura antes de su cierre. Snapshot completo del ítem eliminado.

### 4.4 Detalle de Línea

#### `pos_ventas_linea_extras`
Extras agregados a una línea. `id_extra_producto` → FK → `producto_presentacion` (grupo "Extras").

#### `pos_ventas_linea_ingredientes`
Modificaciones a ingredientes base de la receta. Impacta el cálculo de consumo de insumos.

| Acción | Significado | Consumo |
|---|---|---|
| `'quitar'` | El ingrediente no se usa | El `id_presentacion_producto` del componente NO se descuenta |
| `'cambiar'` | Se reemplaza por otro ingrediente | El original NO se descuenta; `id_ingrediente_reemplazo` (FK → `producto_presentacion`) SÍ se descuenta |

**Campos clave**: `id_componente_receta` (FK → `componentes_receta_producto`), `accion`, `id_ingrediente_reemplazo`, `cantidad_reemplazo`

#### `pos_ventas_linea_consumida_promo`
⚠️ **Tabla crítica del motor de promociones.**

Registra qué líneas (y cuántas unidades) **cumplieron las condiciones** de una promo. Independiente de `pos_linea_descuentos_promo`. Evita el "doble-dipping" de productos en múltiples promos.

**Propósito**: Evitar que los mismos productos sean usados para cumplir condiciones de dos promociones distintas.

**Campo clave**: `unidades_consumidas` — permite consumo parcial. Ej: línea con cantidad=3, promo requiere 2 → `unidades_consumidas=2`, el 3er ítem queda libre para otra promo.

**Query para verificar disponibilidad**:
```sql
SELECT pfl.cantidad - COALESCE(SUM(plcp.unidades_consumidas), 0) AS disponibles
FROM pos_factura_lineas pfl
LEFT JOIN pos_linea_consumida_promo plcp ON plcp.id_linea = pfl.id
WHERE pfl.id_factura = :id AND pfl.id_producto_presentacion = :prod_id
GROUP BY pfl.id HAVING disponibles > 0
```

#### `pos_ventas_linea_descuentos_promo`
Descuentos **efectivamente aplicados** por una promo a una línea o factura completa. Independiente de `pos_linea_consumida_promo`.

- Una línea puede estar en `consumida_promo` pero NO en `descuentos_promo` (cumplió condición, no recibió descuento)
- Una línea puede estar en `descuentos_promo` pero NO en `consumida_promo` (recibió el descuento del Get Y)
- `id_linea = NULL` → el descuento aplica a toda la factura (promo tipo `'factura'`)
- `tipo_aplicacion`: `'automatica'` | `'manual'` | `'canje_puntos'`
- FK a `promo_promociones.id` (tabla existente, NO a `pos_promociones` que fue eliminada)

### 4.5 Pagos y Puntos

#### `pos_ventas_factura_pagos`
Métodos de pago. Una factura puede tener N filas (pago mixto).

| Método | Moneda posible | Notas |
|---|---|---|
| `efectivo` | NIO o USD | Si USD, se usa `tipo_cambio_aplicado` y `monto_cordoba` |
| `pos` | NIO | `referencia` = número de voucher |
| `transferencia` | NIO | `referencia` = número de transferencia |
| `cupon` | NIO | `id_cupon` → FK → `cupones_sucursales`; marcar `aplicado=1` |

`monto_cordoba` siempre en córdobas para cuadre de caja, independientemente de la moneda del pago.

#### `pos_ventas_factura_puntos_club`
**Event Sourcing**: solo se guardan deltas (`puntos_ganados`, `puntos_canjeados`). El saldo se calcula sumando/restando sobre facturas en estado `bloqueada`.**NO hay snapshots** de saldo anterior ni resultante.

**Por qué**: Si se anula una factura pasada, al usar snapshot las facturas posteriores quedan corruptas. Con Event Sourcing, al anular una factura su `estado` pasa a `'anulada_post_impresion'` y automáticamente queda excluida del SUM sin tocar ningún otro registro.

**Saldo disponible del cliente**:
```sql
SELECT SUM(puntos_ganados) - SUM(puntos_canjeados) AS saldo
FROM pos_factura_puntos_club pfpc
JOIN pos_facturas pf ON pf.id = pfpc.id_factura
WHERE pfpc.id_cliente_club = :id AND pf.estado = 'bloqueada'
```

Los puntos disponibles = solo de facturas `estado = 'bloqueada'` (las anuladas quedan excluidas).

### 4.6 Impresiones

#### `pos_ventas_factura_impresiones`
Log completo de cada impresión.

| Impresión # | Tipo | Requiere |
|---|---|---|
| 1ª | `comanda_factura` | Nada. Cambia `estado → 'bloqueada'`. |
| 2ª | `solo_factura` | Nada extra. |
| 3ª+ | `solo_factura` | Clave de administrador (a implementar). `desbloqueado_por` queda logueado. |

---

## 5. Reglas de Negocio Clave

### Bloqueo de factura
- Una vez `estado = 'bloqueada'`: el servidor rechaza cualquier escritura en todas las tablas relacionadas
- No se puede agregar/quitar líneas, cambiar pagos, ni aplicar descuentos
- Solo se puede reimprimir (con restricciones) o anular (requiere permisos)

### Motor de Promociones
- Las promos se definen en `promo_promociones` + `promo_condiciones` (Rule Builder)
- El motor evalúa promos por `prioridad` (ASC — menor número = mayor prioridad)
- Si `combinable = 0`: al aplicar esa promo, no se pueden aplicar otras
- Si `ejecucion_automatica = 1`: el POS la detecta y aplica sola
- Si `requiere_autorizacion = 1`: necesita PIN de supervisor → queda en `autorizado_por`

### Puntos — Condiciones de no acumulación
Una línea tiene `bloquea_puntos = 1` y no acumula puntos si:
1. Tiene descuento de promo (`pos_linea_descuentos_promo`)
2. La factura completa tiene descuento global (promo tipo `'factura'`)
3. Es un canje de puntos (`es_canje_puntos = 1`)

### Precios
- Se leen de `pos_precios_producto` filtrando por producto + sucursal (o global) + fecha vigente
- El snapshot del precio va en `pos_factura_lineas.precio_unitario_snap` para conservar el precio histórico

### Tamaños de productos
- **No hay tabla de tamaños separada**
- El "Batido Dinamita 16oz" y el "Batido Dinamita 20oz" son dos `producto_presentacion` distintos con recetas distintas
- El cajero elige directamente el producto correcto al facturar

Una línea tiene `bloquea_puntos = 1` si:
1. Tiene descuento de promo.
2. La factura tiene descuento global.
3. Es un canje de puntos.

### Precios por Tamaño
- Se debe asegurar que: `Precio(16oz) < Precio(20oz)`.

---

## 6. Módulo de Promociones (ya existente)

El módulo de promociones está completamente funcional en:
- **Lista**: `/modulos/POS/promociones.php`
- **Form**: `/modulos/POS/promocion_form.php`
- **JS**: `/modulos/POS/js/promociones.js`

### Tablas existentes del Rule Builder
```
promo_promociones           ← Datos generales de la promo
promo_condiciones           ← Reglas del Rule Builder (JSON)
promo_condiciones_opciones  ← Catálogo de tipos de condición
```

### Secciones del formulario de promo
1. **Encabezado**: Nombre, código, estado, fechas, prioridad, toggles (automático, combinable, uso único, requiere autorización)
2. **Condiciones (Rule Builder)**: Tipo A (Contexto: día, horario, sucursal, tipo cliente, canal) + Tipo B (Carrito: producto, grupo, tamaño, cantidad mínima, monto mínimo, combo)
3. **Objetivo del descuento**: todos | más barato | Get Y | factura | upgrade
4. **Resultado**: % producto | % factura | monto fijo producto | monto fijo factura

---

## 7. Diagrama de Relaciones (Resumen)

```
promo_promociones ←───────────────────────────────────────────┐
                                                              │
pos_facturas                                                  │
  ├── pos_factura_lineas                                       │
  │     ├── pos_linea_extras          (extras como productos)  │
  │     ├── pos_linea_ingredientes    (cambios de receta)      │
  │     ├── pos_linea_consumida_promo ─────────────────────────┤
  │     └── pos_linea_descuentos_promo ────────────────────────┘
  ├── pos_factura_lineas_eliminadas   (auditoría)
  ├── pos_factura_pagos               (N métodos de pago)
  ├── pos_factura_puntos_club         (event sourcing)
  └── pos_factura_impresiones         (log de impresiones)

pos_precios_producto    → producto_presentacion
pos_tipo_cambio         → (global, sin FK de sucursal)
pos_puntos_reglas       → grupo_presentacion_producto + producto_presentacion
pos_puntos_catalogo_canje → producto_presentacion
```

- **Maestro**: `promo_promociones`
- **Reglas**: `promo_condiciones` (JSON Rule Builder)
- **Opciones**: `promo_condiciones_opciones`

---

## 8. Próximas Páginas a Desarrollar

### Página A: Interfaz del POS (Facturación)
El punto de venta en sí. La pantalla que usa el cajero en tienda.

**Flujo esperado**:
1. Cajero identifica al cliente (número de membresía Club o nombre libre)
2. Selecciona productos por grupos/subgrupos
3. Por cada producto: elige cantidad, tamaño (16oz/20oz = producto diferente), endulzante, extras, modifica ingredientes
4. El motor evalúa y aplica promociones automáticas; el cajero puede agregar promos manuales
5. Revisa totales, propina (toggle 0% o 2%), método(s) de pago
6. Imprime (1ª vez: comanda + factura → estado bloqueada)

**Referencia visual**: Bocetos/mockups compartidos en la conversación original (guardados como artefactos `media__1776440666306.png` y `media__1776440666350.png` en el directorio de la conversación).

**Tablas que escribe esta página**:
- `pos_facturas` (INSERT al abrir, UPDATE al cerrar)
- `pos_factura_lineas` (INSERT por cada producto agregado)
- `pos_factura_lineas_eliminadas` (INSERT al quitar un producto)
- `pos_linea_extras` (INSERT por extras)
- `pos_linea_ingredientes` (INSERT por cambios de ingredientes)
- `pos_linea_consumida_promo` (INSERT al aplicar promo)
- `pos_linea_descuentos_promo` (INSERT al aplicar promo)
- `pos_factura_pagos` (INSERT por cada método de pago)
- `pos_factura_puntos_club` (INSERT al cerrar si cliente es Club)
- `pos_factura_impresiones` (INSERT al imprimir)

**Tablas que lee esta página**:
- `producto_presentacion` + `grupo_presentacion_producto` + `subgrupo_presentacion_producto` (catálogo de productos)
- `componentes_receta_producto` (para mostrar ingredientes base y permitir modificaciones)
- `pos_precios_producto` (precio vigente del día)
- `promo_promociones` + `promo_condiciones` (motor de promos)
- `clientesclub` (lookup de membresía)
- `pos_factura_puntos_club` + `pos_facturas` (saldo de puntos del cliente)
- `cupones_sucursales` (validar cupón)
- `pos_tipo_cambio` (tasa del día si pago USD)

### Página B: Control de Precios de Venta (`precios_venta.php`)
Interfaz para administrar los precios de venta base (globales) y por sucursal (overrides) de los productos del sistema.

**Flujo Implementado:**
1. Tabla interactiva que lista todos los productos vendibles (`producto_presentacion.es_vendible = 'SI'`).
2. Muestra visualmente el precio global vigente y una serie de "badges" que indican las sucursales con precios específicos (override).
3. **Modal de Fijar Precio**: Permite definir un nuevo precio con una `fecha_desde`. La lógica transaccional del backend detecta el precio vigente anterior y lo cierra automáticamente ajustando su `fecha_hasta` a un día antes.
4. **Modal de Historial**: Permite visualizar la cascada completa de cambios de precio (globales y de sucursal) en el tiempo para un producto dado.
5. Permisos de interfaz integrados con la tabla `tools_erp` (`vista`, `nuevo_registro`, `vista_historial`).

**Archivos del Módulo:**
- Vista y UI: `modulos/ventas/precios_venta.php`, `css/precios_venta.css`, `js/precios_venta.js`
- Backend: `modulos/ventas/ajax/precios_get_datos.php`, `precios_guardar.php`, `precios_get_sucursales.php`, `precios_get_historial.php`
- BD de Soporte: `pos_ventas_precios_producto` (historial cronológico de precios).

### Página C: Control del Club Pitaya (`puntos_reglas.php`)
Interfaz unificada en una sola pagina para administrar el ecosistema completo del programa de lealtad: acumulacion y canje de puntos.

**Flujo Implementado:**
1. **Tab Acumulacion (`Como ganar puntos`)**:
   - Tabla que muestra como se ganan los puntos, cruzando datos con `catalogo_menu_pos`, `subgrupo_presentacion_producto` y `grupo_presentacion_producto`.
   - Soporta jerarquia: Regla del Catalogo Menu POS vs Excepcion (Override) de Producto/Presentacion especifico.
   - Al editar una regla, bloquea grupo/catalogo/producto y solo permite modificar los puntos o la `fecha_hasta` (baja).
2. **Tab Canje (`Catalogo de Canje`)**:
   - Tabla que muestra cuantos puntos cuesta canjear cada item del catalogo Menu POS.
   - Misma logica de jerarquia y override que acumulacion.
   - Usa el mismo modal y la misma tabla `pos_ventas_puntos_reglas`, con `tipo_regla = 'canje'`.
3. **Un solo modal**: El modal adapta su titulo, subtitulo y etiqueta del campo `puntos` segun el tab activo (`tipo_regla` como campo oculto).
4. **Permisos**: Control de acceso por operario con `tools_erp` (`puntos_reglas`).

**Archivos del Modulo:**
- Vista y UI: `modulos/marketing/puntos_reglas.php`, `css/puntos_reglas.css`, `js/puntos_reglas.js`
- Backend: `ajax/puntos_get_reglas.php?tipo=acumulacion|canje`, `ajax/puntos_guardar_regla.php`, `ajax/puntos_get_catalogos_cascada.php`
- BD de Soporte: `pos_ventas_puntos_reglas` (tabla unificada acumulacion + canje).

> **Eliminados**: `puntos_catalogo.php`, `js/puntos_catalogo.js`, `ajax/puntos_get_catalogo.php`, `ajax/puntos_guardar_catalogo.php`, `ajax/puntos_get_grupos_productos.php`, tabla `pos_ventas_puntos_catalogo_canje`.

## 9. Comandos Útiles

### Ejecutar el script SQL en local
```bash
mysql -u root -p u839374897_erp < sql/06_tablas_facturacion_pos.sql
```

### Antes de ejecutar — descomentar y ajustar los INSERTs al final del script
- `pos_tipo_cambio`: Insertar la tasa USD del día actual
- `pos_puntos_reglas`: Insertar las reglas de acumulación con los `id_grupo` reales de tu BD

### Query: Saldo de puntos de un cliente
```sql
SELECT SUM(pfpc.puntos_ganados) - SUM(pfpc.puntos_canjeados) AS saldo_disponible
FROM pos_ventas_factura_puntos_club pfpc
INNER JOIN pos_ventas_facturas pf ON pf.id = pfpc.id_factura
WHERE pfpc.id_cliente_club = [ID_CLIENTE]
  AND pf.estado = 'bloqueada';
```

### Query: Precio vigente de un producto
```sql
SELECT precio FROM pos_precios_producto
WHERE id_producto_presentacion = [ID_PROD]
  AND (cod_sucursal = [COD_SUC] OR cod_sucursal IS NULL)
  AND fecha_desde <= CURDATE()
  AND (fecha_hasta IS NULL OR fecha_hasta >= CURDATE())
ORDER BY cod_sucursal DESC  -- Prioriza override de sucursal sobre global
LIMIT 1;
```

### Query: Unidades disponibles de un producto para nueva condición de promo
```sql
SELECT pfl.id, pfl.cantidad - COALESCE(SUM(plcp.unidades_consumidas), 0) AS disponibles
FROM pos_factura_lineas pfl
LEFT JOIN pos_linea_consumida_promo plcp ON plcp.id_linea = pfl.id
WHERE pfl.id_factura = [ID_FACTURA]
  AND pfl.id_producto_presentacion = [ID_PROD]
GROUP BY pfl.id
HAVING disponibles > 0;
```

---

## 10. Decisiones de Diseño Tomadas

| Decisión | Alternativa descartada | Razón |
|---|---|---|
| Tamaños = productos distintos (no tabla de tamaños) | Tabla `pos_tamanos_producto` | Cada tamaño tiene receta diferente; el producto ya es la unidad correcta |
| Event Sourcing para puntos (solo deltas) | Snapshots (puntos_anteriores, puntos_resultantes) | Las anulaciones de facturas pasadas corrompen snapshots; con event sourcing la anulación es automática |
| Extras y endulzantes en `producto_presentacion` | Tablas `extras` y `endulzantes` separadas | Permite mapear consumo de insumos igual que cualquier producto |
| `pos_linea_consumida_promo` y `pos_linea_descuentos_promo` separadas | Una sola tabla de "promos aplicadas" | Un ítem puede cumplir condición sin recibir descuento (y viceversa) |
| Eliminar `pos_configuracion_sucursal` | Mantenerla para propina configurable | Propina hardcodeada (0% o 2%); todo lo demás ya está en `sucursales` |
| Eliminar `pos_promociones` | Crearla nueva | Ya existe como `promo_promociones` con toda la estructura requerida |
| PWA + SQLite para offline | App nativa | Ya definido por el equipo de desarrollo |

---

---

## 11. Acuerdos de Implementación y UI (Mayo 2026)

Tras la fase de definición final, se han establecido los siguientes acuerdos para el desarrollo del módulo de Facturación e Historial:

### 11.1 Arquitectura de Interfaz
- **Componentes Base**: Se utilizarán estrictamente `pos_header.php`, `pos_sidebar.php` y el sistema de autenticación de dos etapas de `auth_pos.php`.
- **Identificadores**: El número correlativo se mostrará como un entero simple (ej: `9821`), sin el prefijo `#PIT-`.
- **Navegación de Productos**: 
    - **Categorías**: Tabs horizontales superiores basados en `grupo_presentacion_producto`.
    - **Visualización**: Cards de productos organizadas en columnas por subgrupos.
- **Personalización (Modal)**: Pantalla dedicada para ajustar ingredientes base, elegir tamaño, endulzante y agregar extras sugeridos.

### 11.2 Lógica de Negocio y Flujos
- **Gestión de Clientes**:
    - **Club Pitaya**: Ingreso de membresía/código → recuperación automática de datos del cliente.
    - **General**: Campo de texto libre para el nombre.
- **Tratamiento de Tamaños**: Los productos de diferente tamaño (16oz vs 20oz) son registros distintos en `producto_presentacion` pero comparten el mismo `id_producto_maestro`. El modal de personalización permitirá cambiar entre ellos actualizando la referencia en la línea de factura.
- **Canje de Puntos**: Se implementará un toggle manual por cada línea de producto para que el cajero marque si el ítem es un canje.
- **Combos**: Se insertan como un producto nuevo. Su consumo se gestiona a través de su propia receta (`componentes_receta_producto`) que incluye otros productos de venta.
- **Métodos de Pago**: Soporte para pago mixto (combinación de efectivo, POS, transferencia y cupones).
- **Cupones**: Validación en tiempo real contra `cupones_sucursales` (lógica integrada del módulo de marketing).

### 11.3 Alcance y Limitaciones (Fase 1)
- **Promociones**: Se incluirá la sección visual en el POS. Las promociones mostradas y aplicadas provienen de lo configurado en `/modulos/POS/promociones.php` y `/modulos/POS/promocion_form.php`. Sin embargo, la lógica de aplicación en el POS será manual o mediante placeholders en esta fase; el motor automático completo se integrará en una fase posterior.
- **Impresión**: No se realizará el envío físico a impresoras térmicas. El flujo de "Imprimir" validará la factura y cambiará su estado a `bloqueada`.
- **Interacción**: Los atajos de teclado (F2, F4, etc.) serán elementos visuales de guía; la funcionalidad operativa se mantendrá vía click/touch por ahora.
- **Exclusiones**: Las páginas de Apertura de Caja, Aligeramiento y Cierre de Caja no se modificarán, ya que son módulos funcionales existentes.

### 11.4 Base de Datos y Seguridad
- **Autorización de Dispositivo**: Uso de la cookie `pos_device_token` validada contra `sucursales.pos_cookie_token`.
- **Precios**: Se asume la existencia de datos vigentes en `pos_ventas_precios_producto` para la operación.

### 11.5 Estructura de Archivos y Ubicación
- **Proyecto**: Las nuevas páginas se desarrollarán en el proyecto ubicado en `\GitHub Pitaya\pos.batidospitaya\`.
- **Módulo**: Se creará una nueva carpeta de módulo llamada `ventas` (dentro de `modulos`), quedando la ruta como `modulos/ventas/`.
- **Archivos**: En esta carpeta se incluirán los archivos PHP (Facturación e Historial), así como sus respectivas carpetas `ajax`, `js` y `css` según sea necesario. Se integrarán con los layouts existentes `pos_header.php` y `pos_sidebar.php`.

### 11.6 Detalles de Funcionalidad Específicos (Mayo 2026)
- **Flujo de Cliente Club**: Al ingresar el código, el sistema mostrará el nombre y tres campos de puntos: **Puntos Anteriores**, **Puntos esta Factura** (suma dinámica según se agregan productos) y **Total Puntos** (proyección final).
- **Información en Línea de Carrito**: Cada producto en la factura mostrará debajo de su nombre:
    - Endulzante seleccionado.
    - Ingredientes quitados y agregados (o intercambios).
    - Promoción aplicada a ese producto específico.
    - Un campo de texto para **Notas** por producto.
- **Activación de Modal**: El modal de personalización se abrirá **automáticamente** apenas el cajero haga clic en un producto para agregarlo.
- **Visualización de Catálogo**: Los productos estarán desplegados mostrando el grupo global, los subgrupos y las cards de productos finales.
- **Línea de Producto - Acciones e Indicadores**:
    - **Tipo**: Indicador de "Para llevar" o "Para comer aquí" (por línea).
    - **Canjeado**: Toggle que aplica costo 0.00 al producto.
    - **Íconos de Acción**:
        - 🔄 *Dos flechas*: Cambiar tamaño (si aplica).
        - ✏️ *Lápiz*: Editar producto (reabre el modal de personalización).
        - 📋 *Copiar*: Duplica la línea con todas sus configuraciones.
- **Gestión de Pagos**: Permite agregar múltiples métodos de pago. Al hacer clic en un método, se genera una fila para ingresar el monto correspondiente. Se pueden eliminar métodos agregados con un ícono de 'X'.
- **Cupones**: Se ingresa el código y se valida en tiempo real con un botón "Validar" (reutilizando la lógica de `/modulos/marketing/cupones.php`).
- **Propina**: Se incluye un toggle de "Propina 2%" que aplica el porcentaje al total de la factura.

---
*Documento actualizado el 2026-05-18*
