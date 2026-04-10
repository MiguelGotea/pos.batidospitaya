<?php
/**
 * POS - logout.php
 * Cierra la sesión del colaborador actual. 
 * La sucursal se mantiene identificada por el dispositivo.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';

// Siempre cerramos al colaborador
posCerrarSesionColaborador();

// Redirigir al inicio (PIN Pad)
header('Location: /index.php');
exit();
?>