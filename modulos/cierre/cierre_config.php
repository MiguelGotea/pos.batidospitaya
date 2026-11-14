<?php
/**
 * cierre_config.php — Configuración del módulo Cierre de Turno
 * POS Batidos Pitaya
 *
 * Constantes de negocio. Cambiar aquí, no en el código.
 */

// Tolerancia por concepto (en C$). ±1 cuenta como "cuadra".
define('CIERRE_TOLERANCIA_CONCEPTO', 1);

// Máximo de reconteos permitidos por concepto (sin contar el conteo ciego inicial).
define('CIERRE_MAX_RECONTEOS', 3);

// Para cupones: la cantidad debe coincidir exacto (tolerancia = 0 unidades).
define('CIERRE_TOLERANCIA_CANTIDAD_CUPONES', 0);

// Zona horaria del servidor POS.
define('CIERRE_TIMEZONE', 'America/Managua');

// Denominaciones NIO y USD (igual que caja_inicial.js)
define('CIERRE_DENOMS_NIO', [0.5, 1, 5, 10, 20, 50, 100, 200, 500]);
define('CIERRE_DENOMS_USD', [1, 5, 10, 20, 50, 100]);
