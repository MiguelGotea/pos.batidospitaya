# Sesiones y Autenticacion - Referencia Rapida

> Documento MAESTRO completo: `docs/sesiones.md`
> Este archivo es una referencia rapida para trabajar directamente en el codigo.

---

## Inicio rapido

```php
// Toda pagina ERP:
require_once __DIR__ . '/core/auth/session_manager.php';
iniciarSesionSegura();

// Toda pagina protegida:
require_once __DIR__ . '/core/auth/auth.php';
verificarAutenticacion();

// Despues del login exitoso:
registrarInicioSesion($usuarioId, $nombre, ['cargo_cod' => X, 'cargo_nombre' => 'Y']);

// Logout:
cerrarSesion();
```

---

## Archivos de este directorio

| Archivo | Descripcion |
|---------|-------------|
| `session_manager.php` | NUCLEO: `iniciarSesionSegura()`, `verificarExpiracionSesion()`, `registrarInicioSesion()`, `cerrarSesion()`, `actualizarPingAuditoria()` |
| `DbSessionHandler.php` | Handler MySQL para SessionHandlerInterface. Almacena sesiones en tabla `php_sessions` |
| `auth.php` | Guard ERP: llama a session_manager + verifica autenticacion + valida dispositivo cargo 27 |
| `auth_pos.php` | Guard POS: autenticacion en 2 etapas (dispositivo -> colaborador). DEV_MODE=false en produccion |
| `ping.php` | Heartbeat endpoint. Renueva sesion y retorna 200/401. Llamado cada 5 min por header_universal.php |
| `ajax/sesiones_ajax.php` | Monitor de sesiones: endpoints kpis / activas / historial |

---

## Reglas de negocio (resumen)

| Regla | Valor | Comportamiento |
|-------|-------|----------------|
| Duracion maxima | 8 horas | `SESSION_DURATION_SECONDS = 28800` |
| Inactividad | > 8h sin ping ni carga | Sesion destruida, motivo: `inactividad` |
| Medianoche | Cambio de dia (UTC-6) | Sesion destruida, motivo: `medianoche` |
| Heartbeat | Cada 5 min | Renueva `last_activity` y `last_activity_at` en BD |

---

## Tablas de BD (produccion verificada)

### `php_sessions`
```
session_id    VARCHAR(128)  PK
data          MEDIUMTEXT    -- $_SESSION serializado
last_activity INT UNSIGNED  INDEX
created_at    INT UNSIGNED
```

### `erp_sesiones_activas`
```
id               INT UNSIGNED  PK AUTO_INCREMENT
session_id       VARCHAR(128)  INDEX
cod_operario     INT           INDEX
usuario_nombre   VARCHAR(150)
cargo_cod        INT           NULLABLE
cargo_nombre     VARCHAR(150)  NULLABLE
ip               VARCHAR(45)
user_agent       VARCHAR(300)
login_at         DATETIME      INDEX  DEFAULT current_timestamp
last_activity_at DATETIME             DEFAULT current_timestamp
logout_at        DATETIME      INDEX  NULL = sesion activa
motivo_cierre    ENUM('logout_manual','inactividad','medianoche','forzado_sistema') NULLABLE
```

---

## Fallback automatico (Hostinger)

```
BD disponible + permisos OK -> usa php_sessions (MySQL)
BD disponible + sin permisos -> usa core/sessions/ (archivos)
Sin BD -> usa core/sessions/ (archivos)
session_start() lanza exception -> fuerza handler nativo + reintenta
```

> Ver incidente 30-Oct-2026 en docs/sesiones.md seccion 17.

---

## Queries rapidos

```sql
-- Sesiones activas ahora mismo (reales)
SELECT usuario_nombre, cargo_nombre, login_at, last_activity_at
FROM erp_sesiones_activas
WHERE logout_at IS NULL AND last_activity_at >= NOW() - INTERVAL 8 HOUR
ORDER BY last_activity_at DESC;

-- Limpiar sesiones huerfanas (logout_at no registrado)
UPDATE erp_sesiones_activas
SET logout_at = DATE_ADD(last_activity_at, INTERVAL 8 HOUR),
    motivo_cierre = 'forzado_sistema'
WHERE logout_at IS NULL AND last_activity_at < NOW() - INTERVAL 8 HOUR;
```
