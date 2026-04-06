-- ============================================================
-- Tablas para el módulo Caja Inicial (POS)
-- Ejecutar en la base de datos u839374897_erp
-- ============================================================

-- Tabla maestra de conteo de caja inicial
CREATE TABLE IF NOT EXISTS `pos_caja_inicial` (
  `id`                        int(11)        NOT NULL AUTO_INCREMENT,
  `fecha`                     date           NOT NULL                         COMMENT 'Fecha del conteo',
  `sucursal_id`               varchar(10)    NOT NULL                         COMMENT 'Código de sucursal',
  `tipo_cambio_usado`         decimal(10,4)  NOT NULL                         COMMENT 'Tipo de cambio NIO/USD al momento del conteo',
  `total_cordobas`            decimal(12,2)  NOT NULL DEFAULT 0.00            COMMENT 'Suma de denominaciones en córdobas',
  `total_dolares`             decimal(12,2)  NOT NULL DEFAULT 0.00            COMMENT 'Suma de denominaciones en dólares',
  `total_dolares_en_cordobas` decimal(12,2)  NOT NULL DEFAULT 0.00            COMMENT 'total_dolares * tipo_cambio_usado',
  `total_efectivo_global`     decimal(12,2)  NOT NULL DEFAULT 0.00            COMMENT 'total_cordobas + total_dolares_en_cordobas',
  `cod_usuario`               int(11)        DEFAULT NULL                     COMMENT 'FK a Operarios/usuarios – quién hizo el conteo',
  `fecha_hora_regsys`         timestamp      NOT NULL DEFAULT current_timestamp() COMMENT 'Fecha y hora de inserción automática del sistema',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fecha_sucursal` (`fecha`, `sucursal_id`),
  KEY `idx_caja_inicial_fecha` (`fecha`),
  KEY `idx_caja_inicial_sucursal` (`sucursal_id`),
  KEY `idx_caja_inicial_usuario` (`cod_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de detalle por denominación
CREATE TABLE IF NOT EXISTS `pos_caja_inicial_detalle` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `caja_inicial_id`  int(11)       NOT NULL                        COMMENT 'FK a pos_caja_inicial',
  `moneda`           enum('NIO','USD') NOT NULL                    COMMENT 'Tipo de moneda',
  `denominacion`     decimal(10,2) NOT NULL                        COMMENT 'Valor facial del billete/moneda',
  `cantidad`         int(11)       NOT NULL DEFAULT 0              COMMENT 'Cantidad de billetes/monedas',
  `total`            decimal(12,2) NOT NULL DEFAULT 0.00           COMMENT 'denominacion * cantidad',
  PRIMARY KEY (`id`),
  KEY `idx_detalle_caja` (`caja_inicial_id`),
  CONSTRAINT `fk_caja_inicial_detalle`
    FOREIGN KEY (`caja_inicial_id`)
    REFERENCES `pos_caja_inicial` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Detalle de denominaciones por conteo de caja inicial';

-- Seed inicial de tipo_cambio si la tabla está vacía
-- (tabla ya existe en la BD con columnas: id, tasa, fecha)
INSERT INTO `tipo_cambio` (`tasa`, `fecha`)
SELECT 36.6, CURDATE()
WHERE NOT EXISTS (SELECT 1 FROM `tipo_cambio` LIMIT 1);
