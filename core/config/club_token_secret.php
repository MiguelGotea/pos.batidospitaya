<?php
/**
 * club_token_secret.php
 * Clave secreta compartida para firmar/verificar tokens HMAC de preregistro Club Pitaya.
 *
 * IMPORTANTE:
 *  - Este archivo debe existir en AMBOS servidores con el MISMO valor de CLUB_TOKEN_SECRET.
 *  - Nunca exponer este valor en logs, respuestas JSON ni JS del cliente.
 *  - Cambiar la clave invalida todos los tokens activos (los socios tendrían que reenviar WSP).
 *
 * Uso (generar token):
 *   require_once 'club_token_secret.php';
 *   $token = clubTokenGenerar($membresia);    // → string URL-safe
 *
 * Uso (verificar token):
 *   $membresia = clubTokenVerificar($token);  // → int|null
 */

// ⚠️ Cambiar este valor en producción por una cadena aleatoria de ≥ 48 caracteres
define('CLUB_TOKEN_SECRET', 'pitaya_club_hmac_2026_secreto_x9z!kW3_preregistro');

/**
 * Genera un token URL-safe firmado con HMAC-SHA256 para un número de membresía.
 * Formato interno: base64url( "MEMBRESIA:HMAC" )
 * No requiere BD ni columna adicional.
 *
 * @param  int    $membresia  Número de membresía Club Pitaya
 * @return string             Token seguro listo para usar en URL (?t=TOKEN)
 */
function clubTokenGenerar(int $membresia): string
{
    $sig     = hash_hmac('sha256', (string)$membresia, CLUB_TOKEN_SECRET);
    $payload = $membresia . ':' . $sig;
    // base64url (RFC 4648 §5): reemplaza + → - , / → _ , quita =
    return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
}

/**
 * Verifica un token y devuelve el número de membresía si es válido, o null si es inválido/falso.
 *
 * @param  string   $token  Token recibido desde la URL (?t=...)
 * @return int|null          Número de membresía verificado, o null si el token es inválido
 */
function clubTokenVerificar(string $token): ?int
{
    // Decodificar base64url → base64 estándar
    $b64     = strtr($token, '-_', '+/');
    $decoded = base64_decode($b64, true);
    if ($decoded === false) return null;

    $partes = explode(':', $decoded, 2);
    if (count($partes) !== 2) return null;

    [$memStr, $sigRecibida] = $partes;
    $membresia = (int)$memStr;
    if ($membresia <= 0) return null;

    $sigEsperada = hash_hmac('sha256', (string)$membresia, CLUB_TOKEN_SECRET);

    // Comparación segura ante timing attacks
    if (!hash_equals($sigEsperada, $sigRecibida)) return null;

    return $membresia;
}
