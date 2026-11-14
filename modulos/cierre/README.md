# Módulo: Cierre de Turno — POS Batidos Pitaya

> **Ubicación:** `pos.batidospitaya.com/modulos/cierre/`  
> **Stack:** PHP + jQuery + Bootstrap 5 + SweetAlert2  
> **BD:** `u839374897_erp` (MariaDB, Hostinger compartido)  
> **Zona horaria:** `America/Managua` — toda hora es del servidor, nunca del cliente

---

## Tabla de Contenidos

1. [Estructura de archivos](#1-estructura-de-archivos)
2. [Base de datos](#2-base-de-datos)
3. [Lógica de negocio](#3-lógica-de-negocio)
4. [Flujo completo del cierre](#4-flujo-completo-del-cierre)
5. [Endpoints AJAX](#5-endpoints-ajax)
6. [Frontend — cierre.js](#6-frontend--cierrejs)
7. [Impresión térmica](#7-impresión-térmica)
8. [Configuración y constantes](#8-configuración-y-constantes)
9. [Variables de sesión requeridas](#9-variables-de-sesión-requeridas)
10. [Tablas externas usadas (solo lectura)](#10-tablas-externas-usadas-solo-lectura)
11. [Restricciones de seguridad críticas](#11-restricciones-de-seguridad-críticas)
12. [Supuestos y decisiones de diseño](#12-supuestos-y-decisiones-de-diseño)
13. [Extensiones futuras sugeridas](#13-extensiones-futuras-sugeridas)

---

## 1. Estructura de archivos

```
modulos/cierre/
├── README.md                          ← este archivo
├── index.php                          ← página principal del módulo
├── cierre_config.php                  ← constantes de negocio (tolerancia, max reconteos, TZ)
├── cierre_helpers.php                 ← funciones PHP reutilizables del módulo
├── sql_setup_cierre.sql               ← migraciones DDL (ejecutar en phpMyAdmin)
│
├── ajax/
│   ├── cierre_iniciar.php             ← POST: inicia o retoma un cierre en_curso
│   ├── cierre_guardar_conteo.php      ← POST: guarda conteo ciego (intento 0) o reconteo
│   ├── cierre_confirmar.php           ← POST: cierra el turno, genera snapshot
│   ├── cierre_get_snapshot.php        ← GET: recupera snapshot de un cierre cerrado
│   ├── cierre_get_pedidosya_facturas.php ← GET: facturas PedidosYa del turno (modal)
│   ├── cierre_get_referencia_cajaini.php ← GET: conteo final del cierre anterior
│   └── cierre_get_ventas_concepto.php ← GET: detalle de ventas por concepto (lupa)
│
├── css/
│   └── cierre.css                     ← estilos propios del módulo
│
├── js/
│   ├── cierre.js                      ← lógica principal del frontend
│   └── cierre_impresion.js            ← renderizador del ticket térmico
│
└── tests/
    └── test_cierre.php                ← pruebas de integración básicas
```

---

## 2. Base de datos

### Tablas del módulo (`pos_cierres_*`)

#### `pos_cierres_turno` — Cabecera del cierre
| Columna | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | Autoincremental |
| `cod_sucursal` | VARCHAR(10) | Código de sucursal (= `$_SESSION['pos_store_sucursal']`) |
| `numero_turno_dia` | INT | Turno 1, 2, 3… del día |
| `fecha` | DATE | Fecha del turno (America/Managua) |
| `cod_cajero` | INT | ID del cajero que inició el cierre |
| `nombre_cajero_snap` | VARCHAR(150) | Nombre del cajero al momento de iniciar |
| `estado` | ENUM | `en_curso` \| `cerrado` |
| `tipo_cambio_dia` | DECIMAL(10,4) | TC del día fijado al iniciar |
| `corte_anterior` | DATETIME | Inicio del rango del turno |
| `corte_actual` | DATETIME | Fin del rango (fijado en servidor al iniciar, **nunca recalcular**) |
| `caja_inicial_id` | INT NULL | FK → `pos_caja_inicial.id` |
| `caja_inicial_equiv_c` | DECIMAL(12,2) | Total en C$ de la caja inicial del día |
| `iniciado_en` | DATETIME | Timestamp de creación |
| `cerrado_en` | DATETIME NULL | Timestamp de cierre definitivo |

> **Unicidad de `en_curso`:** El PHP en `cierre_iniciar.php` llama a `cierre_obtenerEnCurso()` antes de insertar. Solo puede existir **un** registro `en_curso` por `cod_sucursal`. El trigger DB (opcional) refuerza esto a nivel de BD, pero Hostinger compartido puede no permitirlo.

---

#### `pos_cierres_concepto` — Resultado por concepto
| Columna | Tipo | Descripción |
|---|---|---|
| `cierre_id` | INT UNSIGNED | FK → `pos_cierres_turno.id` |
| `concepto` | ENUM | `efectivo` \| `cupones` \| `transferencia` \| `tarjeta` \| `pedidosya` |
| `esperado` | DECIMAL(12,2) | Calculado en servidor |
| `contado_final` | DECIMAL(12,2) | Último conteo del cajero |
| `diferencia` | DECIMAL(12,2) | `contado − esperado` (negativo = faltante) |
| `dentro_tolerancia` | TINYINT(1) | 1 si `\|diferencia\| ≤ CIERRE_TOLERANCIA_CONCEPTO` |
| `cantidad_esperada` | INT NULL | Solo cupones |
| `cantidad_contada` | INT NULL | Solo cupones |
| `comentario` | TEXT NULL | Obligatorio si fuera de tolerancia |
| `intentos_usados` | TINYINT | Incrementa en cada reconteo |
| UNIQUE | `(cierre_id, concepto)` | — |

---

#### `pos_cierres_conteos` — Conteos por denominación e intento
| Columna | Tipo | Descripción |
|---|---|---|
| `cierre_id` | INT UNSIGNED | FK → `pos_cierres_turno` |
| `concepto` | ENUM | Mismo ENUM que anterior |
| `intento` | TINYINT | 0 = ciego inicial, 1-3 = reconteos |
| `moneda` | ENUM('NIO','USD') NULL | Solo para efectivo |
| `denominacion` | DECIMAL(10,2) NULL | Solo para efectivo |
| `cantidad` | INT | Cantidad de billetes/monedas |
| `monto` | DECIMAL(12,2) | `denominacion × cantidad` |
| `cod_usuario` | INT | Cajero que hizo el conteo |
| `registrado_en` | DATETIME | Timestamp |

> Para conceptos que no son efectivo (cupones, transferencia, etc.) se guarda **una sola fila** con `moneda=NULL`, `denominacion=NULL`, `cantidad=` la cantidad digitada, `monto=` el monto total.

---

#### `pos_cierres_snapshot` — Snapshot inmutable para reimpresión
| Columna | Tipo | Descripción |
|---|---|---|
| `cierre_id` | INT UNSIGNED UNIQUE | FK |
| `snapshot_json` | MEDIUMTEXT | JSON completo del cierre (ver sección 7) |
| `generado_en` | DATETIME | — |

---

#### `pos_cierres_incidencias` — Faltantes y sobrantes
| Columna | Tipo | Descripción |
|---|---|---|
| `cierre_id` | INT UNSIGNED | FK |
| `concepto` | ENUM | Concepto con incidencia |
| `tipo` | ENUM('faltante','sobrante') | — |
| `monto_c` | DECIMAL(12,2) | Valor absoluto de la diferencia en C$ |
| `cod_cajero` | INT | Cajero responsable |
| `estado_revision` | ENUM('pendiente','revisado') | Para futura pantalla del líder |

---

#### `pos_cierres_traspaso_flags` — Discrepancias de traspaso entre turnos
Registra si el conteo de caja inicial del turno entrante no coincide con el conteo final del turno anterior. Referencia futura para auditoría.

---

### Migraciones

El archivo `sql_setup_cierre.sql` contiene las DDL en dos partes:

- **Parte 1** (delimitador `;`): `ALTER TABLE` para agregar `pedidosya` al ENUM de pagos + 6 `CREATE TABLE IF NOT EXISTS`.
- **Parte 2** (delimitador `$$`, opcional): Trigger de unicidad `en_curso`. **No ejecutar en Hostinger compartido** (sin permisos SUPER). El PHP ya lo valida.

---

## 3. Lógica de negocio

### 3.1 Métodos de pago y su tratamiento en el cierre

| Método | ENUM en BD | Cierre |
|---|---|---|
| Efectivo C$ | `efectivo` + `moneda=NIO` | Se cuenta por denominación; contado vs esperado en C$ |
| Efectivo US$ | `efectivo` + `moneda=USD` | Se convierte con TC del día para cuadrar en C$ |
| Cupones | `cupon` | Se valida **cantidad** (exacta) **y monto** (±1 C$) |
| Transferencia bancaria | `transferencia` | El cajero digita el total de su app de banco |
| POS banco (tarjeta) | `pos` | El cajero digita el total del voucher del datáfono |
| PedidosYa | `pedidosya` | El cajero digita el total del sistema PedidosYa; el sistema lista las facturas para revisar |

> **PedidosYa no es un pago de efectivo.** No entra en la caja física. Se registra como método de pago pero sin movimiento de dinero en caja. PedidosYa liquida por separado cada semana.

---

### 3.2 Tipo de cambio (TC)

- Uno por día, en `pos_ventas_tipo_cambio` (tabla existente del POS).
- Se obtiene con `cierre_obtenerTC($conn, $hoy)` → última fila con `fecha_desde ≤ hoy`.
- **Si no existe TC del día, el cierre no puede iniciarse.** El sistema muestra mensaje `'sin_tc'`.
- El TC se fija en `pos_cierres_turno.tipo_cambio_dia` al crear el cierre y no cambia aunque el TC se actualice después.

---

### 3.3 Redondeo y conversión USD → C$

```php
// cierre_helpers.php
function cierre_usdACordoba(float $monto_usd, float $tc): int {
    return (int) round($monto_usd * $tc, 0);
}
```

Idéntico al módulo de facturación: `round(monto * tc, 0)`. Siempre entero en C$. No se usa ninguna otra fórmula de redondeo en el módulo.

---

### 3.4 Rango horario del turno

```
corte_anterior  = corte_actual del cierre anterior del día
                  (o fecha_hora_regsys de la caja inicial del día si es el primer turno)
                  (o medianoche si no hay caja inicial)

corte_actual    = timestamp del servidor al INICIAR el cierre (fijado, nunca recalculado)
```

Las facturas y pagos se filtran con:
```sql
f.fecha_hora > :corte_anterior AND f.fecha_hora <= :corte_actual
```

> **La facturación NO se bloquea durante el cierre.** Todo lo registrado después de `corte_actual` cae en el siguiente turno por su timestamp.

---

### 3.5 Fórmula del esperado de efectivo

```
esperado_efectivo =
    caja_inicial_equiv_C$
  + SUM(pagos efectivo NIO, con signo)       ← monto_cordoba puede ser negativo (cambio)
  + SUM(pagos efectivo USD × TC)             ← monto_original en USD
  − SUM(aligeramientos.total_global)         ← ya en C$ con TC del momento
  − SUM(facturas_proveedor.total_factura)    ← pagos de caja a proveedores
```

- El **cambio** entregado al cliente es una línea de pago efectivo NIO con `monto_cordoba < 0`. Se suma con signo y reduce el esperado automáticamente.
- **Aligeramientos** usan `sucursal_id INT` (no varchar). Se resuelve con `cierre_idSucursalDesdeCodigo()`.
- **Facturas a proveedores** (`pos_facturas`) no tienen `cod_sucursal`; se filtran por `registrado_por IN (cajeros que han operado en la sucursal)`.

---

### 3.6 Tolerancia

| Concepto | Tolerancia monto | Tolerancia cantidad |
|---|---|---|
| Efectivo | ±1 C$ | N/A |
| Cupones | ±1 C$ | Exacto (0) |
| Transferencia | ±1 C$ | N/A |
| Tarjeta | ±1 C$ | N/A |
| PedidosYa | ±1 C$ | N/A |

```
diferencia = contado − esperado
  positivo → sobrante
  negativo → faltante
```

---

### 3.7 Conteo ciego (seguridad)

El **intento 0** (conteo ciego inicial) es el único conteo que el cajero hace sin ver los esperados.

**El servidor NUNCA devuelve esperados ni diferencias antes de que el intento 0 esté guardado en BD.**

Verificación en `cierre_guardar_conteo.php` al retomar:
```php
$ya_tiene_conteo_inicial = ((int)$stmtI0Check->fetchColumn() > 0);
```
Si ya existe → no vuelve a guardarlo, solo calcula y revela.

---

### 3.8 Reconteos

- Máximo `CIERRE_MAX_RECONTEOS` (= 3) por concepto.
- Solo sobre conceptos fuera de tolerancia.
- Cada reconteo se guarda completo (por denominación para efectivo, fila única para los demás).
- `pos_cierres_concepto.intentos_usados` incrementa en cada reconteo.
- Si se agotan o el cajero elige no recontar → el cierre avanza con la diferencia.
- Si un concepto queda fuera de tolerancia → **comentario obligatorio** antes de confirmar.
- **El cierre nunca se bloquea por diferencias.** Siempre puede confirmarse.

---

### 3.9 Confirmación del cierre

Al confirmar:
1. Se verifican comentarios obligatorios (server-side).
2. Se guardan los comentarios en `pos_cierres_concepto`.
3. Se registran incidencias en `pos_cierres_incidencias` (faltantes/sobrantes, a nombre del cajero).
4. Se genera el `snapshot` JSON completo (inmutable) y se guarda en `pos_cierres_snapshot`.
5. Se marca `pos_cierres_turno.estado = 'cerrado'` y `cerrado_en = NOW()`.

Todo en una sola transacción. El rollback es automático si falla algún paso.

---

### 3.10 Cierre interrumpido / retomado

Si el cajero abandona a mitad del proceso:
- El cierre queda en estado `en_curso`.
- Al volver a pulsar "Iniciar Cierre", `cierre_iniciar.php` detecta el `en_curso` existente y lo retoma (`estado: 'retomado'`).
- Si el conteo ciego ya estaba guardado (`tiene_conteo_inicial: true`), el JS llama automáticamente a `solicitarResultadosExistentes()` para pasar directo a la pantalla de resultados.

---

## 4. Flujo completo del cierre

```
CAJERO                                    SERVIDOR
  │                                           │
  ├─ Pulsa "Iniciar Cierre" ─────────────────►│ cierre_iniciar.php
  │                                           │  · Verifica TC del día
  │                                           │  · Determina corte_anterior y corte_actual
  │◄──────────── cierre (id, rangos, TC) ─────┤
  │                                           │
  ├─ Llena conteo ciego ────────────────────► │ cierre_guardar_conteo.php (intento=0)
  │                                           │  · Guarda pos_cierres_conteos
  │◄──────── conceptos {esperado, contado,    │  · Calcula esperados (server-side)
  │            diferencia, dentro_tol} ───────┤  · Guarda pos_cierres_concepto
  │                                           │
  ├─ [Si hay fuera de tol] Recontar ────────► │ cierre_guardar_conteo.php (intento=1,2,3)
  │◄──────── conceptos actualizados ──────────┤
  │                                           │
  ├─ [Fuera de tol] Escribe comentario        │
  │                                           │
  ├─ Pulsa "Confirmar y Cerrar" ─────────────►│ cierre_confirmar.php
  │                                           │  · Valida comentarios
  │                                           │  · Registra incidencias
  │◄──────── snapshot completo ───────────────┤  · Guarda snapshot
  │                                           │  · Cierra turno
  │                                           │
  ├─ Pantalla "Turno Cerrado"                 │
  │                                           │
  └─ Ticket impreso automáticamente           │
```

---

## 5. Endpoints AJAX

### `POST ajax/cierre_iniciar.php`

**Entrada:** `{ accion: "iniciar" }` (JSON)

**Salida exitosa:**
```json
{
  "ok": true,
  "estado": "iniciado" | "retomado",
  "tiene_conteo_inicial": false,
  "cierre": {
    "id": 42,
    "numero_turno": 1,
    "fecha": "2026-11-14",
    "corte_anterior": "2026-11-14 08:00:00",
    "corte_actual": "2026-11-14 14:03:21",
    "tipo_cambio_dia": 36.80,
    "cajero": "María López",
    "sucursal": "Sucursal Centro"
  }
}
```

**Error sin TC:**
```json
{ "ok": false, "error": "sin_tc", "mensaje": "No hay tipo de cambio..." }
```

---

### `POST ajax/cierre_guardar_conteo.php`

**Entrada (intento 0 — ciego inicial):**
```json
{
  "cierre_id": 42,
  "intento": 0,
  "efectivo_nio": [ {"denominacion": 100, "cantidad": 5}, ... ],
  "efectivo_usd": [ {"denominacion": 1, "cantidad": 3}, ... ],
  "cupones_cantidad": 2,
  "cupones_monto": 150,
  "transferencia_monto": 500,
  "tarjeta_monto": 300,
  "pedidosya_monto": 200,
  "conceptos_a_recontar": ["efectivo","cupones","transferencia","tarjeta","pedidosya"]
}
```

**Entrada (reconteo — intento 1-3):**
```json
{
  "cierre_id": 42,
  "intento": 1,
  "efectivo_nio": [...],
  "efectivo_usd": [...],
  "conceptos_a_recontar": ["efectivo"]
}
```

**Salida:**
```json
{
  "ok": true,
  "revelado": true,
  "cierre_id": 42,
  "conceptos": {
    "efectivo": {
      "esperado": 4500.00,
      "contado": 4499.00,
      "diferencia": -1.00,
      "dentro_tolerancia": true,
      "intento_actual": 0,
      "detalle_calculo": { ... },
      "filas_conteo": [ ... ]
    },
    "cupones": {
      "esperado": 300.00,
      "contado": 150.00,
      "diferencia": -150.00,
      "cantidad_esperada": 2,
      "cantidad_contada": 1,
      "diferencia_cant": -1,
      "dentro_tolerancia": false,
      "intento_actual": 0
    },
    ...
  }
}
```

---

### `POST ajax/cierre_confirmar.php`

**Entrada:**
```json
{
  "cierre_id": 42,
  "comentarios": {
    "cupones": "Se perdió un cupón durante el turno."
  }
}
```

**Salida:** `{ "ok": true, "cierre_id": 42, "snapshot": { ... } }`  
El snapshot se detalla en la sección 7.

---

### `GET ajax/cierre_get_ventas_concepto.php`

**Parámetros:** `?cierre_id=42&concepto=efectivo`

Conceptos válidos: `efectivo` | `cupones` | `transferencia` | `tarjeta` | `pedidosya`

**Salida efectivo:**
```json
{
  "ok": true,
  "concepto": "efectivo",
  "filas": [
    { "factura_id": 101, "fecha_hora": "2026-11-14 10:23:00",
      "moneda": "USD", "monto_original": 5.00, "monto_cordoba": 184.00 },
    { "factura_id": 102, "fecha_hora": "2026-11-14 10:30:00",
      "moneda": "NIO", "monto_original": 150.00, "monto_cordoba": -50.00 }
  ],
  "total": 134.00
}
```
> Filas con `monto_cordoba < 0` son **cambio entregado** (se muestran en amarillo en la UI).

---

### `GET ajax/cierre_get_pedidosya_facturas.php`

**Parámetros:** `?cierre_id=42`

Devuelve las facturas PedidosYa del rango para revisión manual en modal.

---

### `GET ajax/cierre_get_referencia_cajaini.php`

Devuelve el conteo final por denominación del último cierre cerrado de la sucursal, para mostrarlo como referencia al registrar la caja inicial del turno entrante.

---

### `GET ajax/cierre_get_snapshot.php`

**Parámetros:** `?cierre_id=42`

Recupera el snapshot guardado de un cierre ya `cerrado`. Útil para reimprimir el ticket.

---

## 6. Frontend — cierre.js

### Estado global

```js
let cierreId          = null;   // ID del cierre en_curso
let conceptosData     = {};     // resultado del servidor tras revelar
let conceptoEnReconteo= null;   // concepto que está en modal de reconteo
```

### Funciones principales

| Función | Descripción |
|---|---|
| `mostrarPanelCierre(cierre)` | Oculta botón "Iniciar", muestra info del turno |
| `construirPayloadConteo(intento, conceptos)` | Reúne los valores del formulario de conteo |
| `enviarConteo(payload)` | POST a `cierre_guardar_conteo.php`; al éxito llama `mostrarResultados()` |
| `solicitarResultadosExistentes()` | Para retomar cierre con conteo 0 ya guardado |
| `mostrarResultados(conceptos)` | Renderiza tabla de resultados con botones Recontar y Lupa |
| `actualizarPanelComentarios(conceptos)` | Muestra textareas para conceptos fuera de tol |
| `verificarPuedeConfirmar()` | Habilita/deshabilita el botón confirmar |
| `mostrarCierreFinalizado(snapshot)` | Muestra pantalla final y dispara impresión |
| `recalcularEfectivo(prefijo)` | Recalcula totales de denominaciones en tiempo real |
| `construirTablasDenoms(prefijo, cuerpoNIO, cuerpoUSD)` | Genera filas de denominaciones |
| `buildRowDenom(denom, moneda, prefijo)` | Genera HTML de una fila de denominación |

### Denominaciones

```js
// Desde window.CIERRE_CONFIG (PHP → JS)
DENOMS_NIO = [0.5, 1, 5, 10, 20, 50, 100, 200, 500]
DENOMS_USD = [1, 5, 10, 20, 50, 100]
```

Los IDs de los inputs siguen el patrón `{prefijo}_{MONEDA}_{denom_con_guión_bajo}`:
- Conteo inicial: `ci_NIO_100`, `ci_USD_5`
- Reconteo: `rc_NIO_100`, `rc_USD_5`

### Modales

| ID | Uso |
|---|---|
| `#modalReconteo` | Formulario de reconteo (backdrop static — no cierra con clic fuera) |
| `#modalVentasConcepto` | Detalle de ventas por concepto (lupa 🔍) |
| `#modalPedidosYa` | Listado de facturas PedidosYa del turno |

### CIERRE_CONFIG (PHP → JS)

```php
$jsConfig = json_encode([
    'TC'           => $tc,
    'TOLERANCIA'   => CIERRE_TOLERANCIA_CONCEPTO,
    'MAX_RECONTEOS'=> CIERRE_MAX_RECONTEOS,
    'DENOMS_NIO'   => CIERRE_DENOMS_NIO,
    'DENOMS_USD'   => CIERRE_DENOMS_USD,
]);
```

---

## 7. Impresión térmica

### Motor de impresión

El módulo usa el mismo mecanismo probado que el módulo de facturación:

```
cierre_impresion.js
  └── renderizarTicketCierre(snapshot) → HTML string
        └── plantillaBase(body)   ← de pos_impresion_termica.js
              └── _imprimirHtml(html, cb)
                    └── _imprimirEnPaginaPrincipal(estilos, cuerpo, cb)
                          └── window.print()  (400ms delay)
```

**No se usa iframe** (roto en Chrome moderno). Todo CSS es inline → `_extraerEstilos()` lo extrae correctamente.

### Secciones del ticket

1. **Encabezado:** nombre sucursal, número de turno, fecha, cajero, corte_anterior → corte_actual, TC del día.
2. **Resumen de ventas:** total de facturas y monto total del turno.
3. **Cuadre por concepto:** tabla con esperado, contado, diferencia y estado (✓ Cuadra / ✗ Dif.) para los 5 conceptos.
4. **Conteo final de efectivo:** breakdown por denominación NIO y USD del **último intento** de efectivo.
5. **Aligeramientos:** lista con hora, monto original (C$ o US$), TC y equivalente C$.
6. **Pagos a proveedores:** lista con proveedor, número de factura y monto.
7. **Facturas PedidosYa:** lista con ID y total.
8. **Firma:** línea para firma del cajero y timestamp de generación.

### Estructura del snapshot

```json
{
  "cierre": {
    "id", "numero_turno", "fecha",
    "corte_anterior", "corte_actual",
    "tipo_cambio_dia", "cajero", "sucursal",
    "caja_inicial_c", "cerrado_en"
  },
  "resumen_ventas": { "total_facturas", "suma_total" },
  "conceptos": [ { "concepto", "esperado", "contado_final", "diferencia",
                   "dentro_tolerancia", "cantidad_esperada", "cantidad_contada",
                   "comentario", "intentos_usados" } ],
  "conteo_final_denoms": [ { "moneda", "denominacion", "cantidad", "monto" } ],
  "aligeramientos": [ { "creado_en", "total_global", "total_cordobas",
                        "total_dolares", "tipo_cambio_usado", "observacion" } ],
  "pagos_proveedores": [ { "numero_factura", "fecha", "total_factura",
                           "proveedor", "notas" } ],
  "facturas_pedidosya": [ { "id", "fecha_hora", "total" } ],
  "generado_en": "..."
}
```

El snapshot se guarda en `pos_cierres_snapshot` y también en `window.CIERRE_SNAPSHOT` en el cliente para impresión inmediata.

---

## 8. Configuración y constantes

Todas en `cierre_config.php`. Cambiar aquí, nunca hardcodear en el código.

| Constante | Valor actual | Descripción |
|---|---|---|
| `CIERRE_TOLERANCIA_CONCEPTO` | `1` | ±1 C$ tolerable por concepto |
| `CIERRE_MAX_RECONTEOS` | `3` | Máx. reconteos por concepto (sin contar el ciego) |
| `CIERRE_TOLERANCIA_CANTIDAD_CUPONES` | `0` | La cantidad de cupones debe cuadrar exacto |
| `CIERRE_TIMEZONE` | `'America/Managua'` | Timezone del servidor |
| `CIERRE_DENOMS_NIO` | `[0.5,1,5,10,20,50,100,200,500]` | Denominaciones C$ |
| `CIERRE_DENOMS_USD` | `[1,5,10,20,50,100]` | Denominaciones US$ |

---

## 9. Variables de sesión requeridas

| Variable | Tipo | Uso |
|---|---|---|
| `$_SESSION['pos_store_sucursal']` | VARCHAR(10) | Código de la sucursal — siempre del servidor |
| `$_SESSION['pos_store_sucursal_nombre']` | string | Nombre para display y snapshot |
| `$_SESSION['pos_colaborador_id']` | int | ID del cajero |
| `$_SESSION['pos_colaborador_nombre']` | string | Nombre del cajero para snapshot |

Protección de páginas: `posRequiereColaborador()` en `index.php`  
Protección de endpoints: `posRequiereColaboradorAjax()` en cada `ajax/*.php`

**La sucursal NUNCA se recibe del cliente.**

---

## 10. Tablas externas usadas (solo lectura)

| Tabla | Propósito | Columna clave |
|---|---|---|
| `pos_ventas_facturas` | Facturas del turno | `cod_sucursal` VARCHAR(10), `fecha_hora`, `estado` |
| `pos_ventas_factura_pagos` | Líneas de pago | `metodo`, `moneda`, `monto_original`, `monto_cordoba` |
| `pos_ventas_tipo_cambio` | TC del día | `fecha_desde`, `tasa` |
| `pos_caja_inicial` | Caja inicial del día | `sucursal_id` VARCHAR(10), `fecha`, `total_efectivo_global` |
| `pos_aligeramientos` | Retiros de efectivo | `sucursal_id` INT, `creado_en`, `total_global` |
| `pos_facturas` | Facturas a proveedores | `registrado_por`, `fecha_hora_regsys`, `total_factura`, `estado='activa'` |
| `proveedores` | Nombre del proveedor | `id`, `nombre` |
| `sucursales` | Nombre y ID entero | `codigo` VARCHAR(10), `id` INT, `nombre` |

> **Atención:** `pos_aligeramientos.sucursal_id` es **INT** (no varchar). Se convierte con `cierre_idSucursalDesdeCodigo()`.  
> **Atención:** `pos_facturas` no tiene `cod_sucursal`; se filtra por `registrado_por IN (cajeros de la sucursal)`.

### Estados de facturas válidos para el cierre

```sql
f.estado IN ('completada', 'bloqueada')
```

Las facturas `anulada_post_impresion` y `en_proceso` **no** se incluyen.

### Cambio (monto negativo)

Una línea de pago con `metodo='efectivo'` y `monto_cordoba < 0` representa el cambio entregado. Se suma con signo, reduciendo el efectivo esperado. No hay columna especial: se identifica solo por el signo negativo.

---

## 11. Restricciones de seguridad críticas

1. **Conteo ciego:** Antes de guardar el intento 0, el servidor no devuelve esperados al cliente. No en JSON, no en HTML, no en logs de respuesta.

2. **Sucursal de sesión:** `cod_sucursal` siempre de `$_SESSION`. Ningún endpoint acepta sucursal del cliente.

3. **Inmutabilidad:** Una vez `cerrado`, el cierre no puede editarse ni borrarse desde la app. No hay endpoint de DELETE ni UPDATE de cierre.

4. **Verificación de propiedad:** Todos los endpoints verifican que `cierre_id` pertenezca a `cod_sucursal` de sesión antes de operar.

5. **Tope de reconteos:** El servidor verifica `intentos_usados >= CIERRE_MAX_RECONTEOS` antes de aceptar un nuevo reconteo. El cliente no controla esto.

6. **TC solo del servidor:** El tipo de cambio se obtiene en `cierre_iniciar.php` y se guarda en BD. Nunca se acepta del cliente.

---

## 12. Supuestos y decisiones de diseño

| # | Supuesto / Decisión |
|---|---|
| 1 | **Sin triggers en producción** (Hostinger compartido sin permisos SUPER). La unicidad de `en_curso` la garantiza el PHP. |
| 2 | El módulo de facturación **nunca se modifica**. Solo se leen sus tablas. |
| 3 | **No hay devoluciones, propinas ni ingresos de efectivo** que no sean ventas en el POS. |
| 4 | El **cambio** se registra como línea negativa de efectivo NIO, aunque el cliente lo reciba en USD. |
| 5 | La **caja inicial** vale lo que cuenta el cajero entrante, aunque no coincida con el cierre anterior. La discrepancia se guarda en `pos_cierres_traspaso_flags`. |
| 6 | **PedidosYa** no mueve efectivo de caja. Su cuadre es una validación informativa. |
| 7 | Cada turno tiene su propio `corte_actual` fijado al momento de **iniciar** (no al confirmar). |
| 8 | El datáfono (POS banco) se cierra físicamente al hacer el cierre del sistema, así el voucher siempre corresponde al rango del turno. |
| 9 | Los aligeramientos usan `total_global` que ya incluye la conversión USD→C$ hecha al momento del aligera miento. |
| 10 | `pos_caja_inicial.sucursal_id` es VARCHAR(10) (mismo valor que `cod_sucursal` de sesión). La consulta es directa, sin JOIN. |

---

## 13. Extensiones futuras sugeridas

### A. Pantalla de incidencias para el líder

Leer `pos_cierres_incidencias` donde `estado_revision = 'pendiente'`. Mostrar por cajero, concepto, tipo y monto. Botón "Marcar como revisado" → `UPDATE estado_revision = 'revisado'`.

### B. Reimpresión de cierre anterior

Usar `cierre_get_snapshot.php` para recuperar el `snapshot_json` de cualquier cierre cerrado y pasarlo a `renderizarTicketCierre()`.

### C. Dashboard de cierres del día

```sql
SELECT numero_turno_dia, nombre_cajero_snap, corte_anterior, corte_actual,
       estado, cerrado_en
FROM pos_cierres_turno
WHERE cod_sucursal = ? AND fecha = ?
ORDER BY numero_turno_dia
```

### D. Alerta de discrepancia de traspaso

Al registrar la caja inicial, comparar con `cierre_obtenerConteoFinalUltimoCierre()` y si difiere en más de un umbral, registrar en `pos_cierres_traspaso_flags` y mostrar aviso.

### E. Exportar cierre a PDF / Excel

El snapshot ya tiene toda la estructura. Se puede generar PDF server-side con la misma lógica del ticket, o exportar a CSV con los datos de `pos_cierres_concepto`.

### F. Ampliación de denominaciones

Modificar `CIERRE_DENOMS_NIO` / `CIERRE_DENOMS_USD` en `cierre_config.php`. Los PHP y JS los leen desde ahí. No hay hardcode de denominaciones en el resto del código.

---

*Documento generado: noviembre 2026 — versión inicial del módulo*
