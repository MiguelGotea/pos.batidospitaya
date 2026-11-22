-- ============================================================
--  SCRIPT: Eliminar un pedido completo del POS
--  Sistema: pos.batidospitaya.com
--  Tablas verificadas directamente desde la BD (SHOW TABLES LIKE 'pos_ventas%')
--  Uso: Reemplaza @id_pedido con el número de ticket/factura
-- ============================================================
--
--  MAPA COMPLETO DE TABLAS DEL PEDIDO (todas las pos_ventas_* con FK al pedido):
--
--  pos_ventas_facturas                  ← [RAÍZ] Cabecera del pedido
--   │
--   ├── pos_ventas_factura_pagos        ← Pagos (efectivo, POS, transferencia, cupón, cambio)
--   │                                     FK: id_factura
--   │
--   ├── pos_ventas_factura_puntos_club  ← Puntos Club Pitaya ganados/canjeados
--   │                                     FK: id_factura
--   │
--   ├── pos_ventas_factura_impresiones  ← Log de impresiones (factura y comanda)
--   │                                     FK: id_factura
--   │
--   ├── pos_ventas_factura_lineas_eliminadas  ← Productos quitados antes de cerrar
--   │                                           FK: id_factura
--   │
--   ├── pos_ventas_linea_consumida_promo ← Qué promoción se "quemó" por línea
--   │                                      FK: id_factura + id_linea
--   │
--   ├── pos_ventas_linea_descuentos_promo ← Descuentos de promo aplicados por línea
--   │                                       FK: id_factura + id_linea
--   │
--   └── pos_ventas_factura_lineas        ← Líneas de productos (auto-relación id_linea_padre)
--        │
--        ├── pos_ventas_linea_extras     ← Extras añadidos por línea (FK: id_linea)
--        └── pos_ventas_linea_ingredientes ← Ingredientes quitados/cambiados (FK: id_linea)
--
--  TABLAS QUE NO SE TOCAN (solo lectura / catálogos):
--    pos_ventas_puntos_reglas    — reglas de acumulación/canje (catálogo)
--    pos_ventas_tipo_cambio      — tasa USD/NIO (catálogo)
--    pos_ventas_precios_producto — histórico de precios (catálogo)
--    pos_cierres_snapshot        — JSON consolidado del cierre (sin FK directa)
--    cupones_sucursales          — en modo pruebas no se marca aplicado=1
-- ============================================================

-- ► CAMBIA ESTE VALOR POR EL NÚMERO DE PEDIDO QUE DESEAS ELIMINAR:
SET @id_pedido = 123;

-- ── Verificar que el pedido existe antes de eliminar ─────────
SELECT
    f.id,
    f.fecha_hora,
    f.estado,
    f.tipo_cliente,
    COALESCE(f.nombre_cliente_general, f.id_cliente_club) AS cliente,
    f.total,
    (SELECT COUNT(*) FROM pos_ventas_factura_lineas      WHERE id_factura = f.id) AS lineas,
    (SELECT COUNT(*) FROM pos_ventas_factura_pagos       WHERE id_factura = f.id) AS pagos,
    (SELECT COUNT(*) FROM pos_ventas_linea_extras        le
        JOIN pos_ventas_factura_lineas fl ON fl.id = le.id_linea
        WHERE fl.id_factura = f.id)                                               AS extras,
    (SELECT COUNT(*) FROM pos_ventas_linea_ingredientes  li
        JOIN pos_ventas_factura_lineas fl ON fl.id = li.id_linea
        WHERE fl.id_factura = f.id)                                               AS ingredientes,
    (SELECT COUNT(*) FROM pos_ventas_linea_consumida_promo  WHERE id_factura = f.id) AS promos_consumidas,
    (SELECT COUNT(*) FROM pos_ventas_linea_descuentos_promo WHERE id_factura = f.id) AS descuentos_promo,
    (SELECT COUNT(*) FROM pos_ventas_factura_impresiones    WHERE id_factura = f.id) AS impresiones,
    (SELECT COUNT(*) FROM pos_ventas_factura_lineas_eliminadas WHERE id_factura = f.id) AS lineas_eliminadas
FROM pos_ventas_facturas f
WHERE f.id = @id_pedido;

-- ── ELIMINACIÓN SEGURA DENTRO DE UNA TRANSACCIÓN ─────────────
START TRANSACTION;

-- 1. Extras de líneas (FK: id_linea → pos_ventas_factura_lineas)
DELETE le FROM pos_ventas_linea_extras le
JOIN pos_ventas_factura_lineas fl ON fl.id = le.id_linea
WHERE fl.id_factura = @id_pedido;

-- 2. Ingredientes modificados por línea (quitados/cambiados)
DELETE li FROM pos_ventas_linea_ingredientes li
JOIN pos_ventas_factura_lineas fl ON fl.id = li.id_linea
WHERE fl.id_factura = @id_pedido;

-- 3. Promociones consumidas por línea
DELETE FROM pos_ventas_linea_consumida_promo
WHERE id_factura = @id_pedido;

-- 4. Descuentos de promoción aplicados por línea
DELETE FROM pos_ventas_linea_descuentos_promo
WHERE id_factura = @id_pedido;

-- 5. Puntos Club Pitaya
DELETE FROM pos_ventas_factura_puntos_club
WHERE id_factura = @id_pedido;

-- 6. Pagos registrados (efectivo, POS, transferencia, cupón, cambio devuelto)
DELETE FROM pos_ventas_factura_pagos
WHERE id_factura = @id_pedido;

-- 7. Log de impresiones (factura y comanda)
DELETE FROM pos_ventas_factura_impresiones
WHERE id_factura = @id_pedido;

-- 8. Productos eliminados antes de cerrar el pedido
DELETE FROM pos_ventas_factura_lineas_eliminadas
WHERE id_factura = @id_pedido;

-- 9. Líneas hijas de combos (auto-relación: id_linea_padre NOT NULL)
DELETE FROM pos_ventas_factura_lineas
WHERE id_factura = @id_pedido
  AND id_linea_padre IS NOT NULL;

-- 10. Líneas padre (productos regulares y combos raíz)
DELETE FROM pos_ventas_factura_lineas
WHERE id_factura = @id_pedido;

-- 11. Cabecera del pedido (tabla raíz)
DELETE FROM pos_ventas_facturas
WHERE id = @id_pedido;

-- ── Confirmar (o revertir si algo salió mal) ─────────────────
-- Para confirmar:
COMMIT;

-- Para cancelar sin hacer cambios (descomenta y comenta el COMMIT de arriba):
-- ROLLBACK;
