<?php
// /core/auth/DbSessionHandler.php
// Handler de sesiones PHP respaldado por MySQL.
// Evita que el GC de archivos de Hostinger destruya sesiones activas.
//
// REQUISITO: La tabla `php_sessions` debe existir previamente en la BD.
// Script SQL: core/auth/sql/crear_tablas_sesiones.sql

class DbSessionHandler implements SessionHandlerInterface
{
    /** @var PDO */
    private PDO $pdo;

    /** @var int Tiempo maximo de vida de sesion inactiva (segundos) */
    private int $maxLifetime;

    public function __construct(PDO $pdo, int $maxLifetime = 57600)
    {
        $this->pdo         = $pdo;
        $this->maxLifetime = $maxLifetime;
    }

    // -------------------------------------------------------------------------
    // SessionHandlerInterface
    // -------------------------------------------------------------------------

    public function open(string $path, string $name): bool
    {
        return true; // La conexion PDO ya esta abierta
    }

    public function close(): bool
    {
        // GC propio: limpiar sesiones expiradas ~1% de las veces
        if (mt_rand(1, 100) === 1) {
            $limite = time() - $this->maxLifetime;
            $stmt   = $this->pdo->prepare('DELETE FROM `php_sessions` WHERE `last_activity` < ?');
            $stmt->execute([$limite]);
        }
        return true;
    }

    // Retorna string (datos serializados) o '' si no existe. PHP 7.4 compatible (sin union type).
    public function read(string $id)
    {
        $stmt = $this->pdo->prepare(
            'SELECT `data` FROM `php_sessions` WHERE `session_id` = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $ahora = time();
        $stmt  = $this->pdo->prepare('
            INSERT INTO `php_sessions` (`session_id`, `data`, `last_activity`, `created_at`)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                `data`          = VALUES(`data`),
                `last_activity` = VALUES(`last_activity`)
        ');
        return $stmt->execute([$id, $data, $ahora, $ahora]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM `php_sessions` WHERE `session_id` = ?');
        return $stmt->execute([$id]);
    }

    // Retorna int (filas eliminadas) o false en error. PHP 7.4 compatible (sin union type).
    public function gc(int $max_lifetime)
    {
        $limite = time() - $this->maxLifetime;
        $stmt   = $this->pdo->prepare('DELETE FROM `php_sessions` WHERE `last_activity` < ?');
        $stmt->execute([$limite]);
        return $stmt->rowCount();
    }
}
