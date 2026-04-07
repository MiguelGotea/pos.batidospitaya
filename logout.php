<?php
/**
 * POS - logout.php
 * ?type=colaborador  => cierra solo al colaborador actual, la tienda sigue activa
 * ?type=store        => cierra todo (colaborador + tienda)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';

$type = $_GET['type'] ?? 'colaborador';

if ($type === 'store') {
    posCerrarSesionCompleta();
    header('Location: /login.php');
} else {
    posCerrarSesionColaborador();
    header('Location: /index.php');
}
exit();
?>