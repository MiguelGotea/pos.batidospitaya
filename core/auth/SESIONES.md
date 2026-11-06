# Sistema de Sesiones y Autenticacion - ERP Batidos Pitaya

> **Ubicacion:** `erp.batidospitaya.com/core/auth/`
> **Ultima actualizacion:** Noviembre 2026
> **Ambiente:** Hostinger compartido - PHP 8.2 - MySQL InnoDB

---

## Tabla de Contenidos

1. [Vision general](#1-vision-general)
2. [Archivos del sistema](#2-archivos-del-sistema)
3. [Tablas de base de datos](#3-tablas-de-base-de-datos)
4. [Reglas de negocio de sesiones](#4-reglas-de-negocio-de-sesiones)
5. [Flujo de inicio de sesion (ERP)](#5-flujo-de-inicio-de-sesion-erp)
6. [Flujo de autenticacion POS](#6-flujo-de-autenticacion-pos)
7. [Variables de sesion](#7-variables-de-sesion)
8. [API publica del SessionManager](#8-api-publica-del-sessionmanager)
9. [Heartbeat y renovacion de actividad](#9-heartbeat-y-renovacion-de-actividad)
10. [Monitor de sesiones AJAX](#10-monitor-de-sesiones-ajax)
11. [Validacion de dispositivo Cargo 27](#11-validacion-de-dispositivo-cargo-27)
12. [Almacenamiento BD vs Archivos](#12-almacenamiento-bd-vs-archivos)
13. [Seguridad de cookies](#13-seguridad-de-cookies)
14. [Historico de incidentes](#14-historico-de-incidentes)
15. [Queries utiles para diagnostico](#15-queries-utiles-para-diagnostico)

---

## 1. Vision general

El ERP usa **sesiones PHP respaldadas en MySQL** (tabla `php_sessions`) en lugar de los archivos de sesion por defecto.
Esto evita que el Garbage Collector automatico de Hostinger destruya sesiones activas de forma prematura en entornos de hosting compartido.

Sobre la capa de sesion PHP existe una **logica de negocio adicional** que impone dos reglas de expiracion propias:

| Regla | Comportamiento |
|-------|---------------|
| **Inactividad** | La sesion expira si el usuario no hace ninguna accion en **8 horas** (ventana deslizante renovada cada 5 min por heartbeat) |
| **Corte a medianoche** | La sesion expira automaticamente al cambiar el dia calendario (zona horaria `America/Managua`, UTC-6) |

Todas las expiraciones y logouts quedan registradas en la tabla de auditoria `erp_sesiones_activas`.

---

## 2. Archivos del sistema

```
core/auth/
+-- session_manager.php      <- Nucleo del sistema de sesiones (API publica)
+-- DbSessionHandler.php     <- Handler MySQL para PHP SessionHandlerInterface
+-- auth.php                 <- Guard de autenticacion para paginas ERP
+-- auth_pos.php             <- Guard y logica de autenticacion para POS
+-- ping.php                 <- Endpoint heartbeat (cada 5 min desde el frontend)
+-- ajax/
|   +-- sesiones_ajax.php    <- API AJAX del monitor de sesiones activas
+-- SESIONES.md              <- Este archivo
```

**Archivos relacionados fuera de `auth/`:**

```
login.php                    <- Formulario de login ERP
logout.php                   <- Cierre de sesion (llama a cerrarSesion())
core/database/conexion.php   <- Conexion PDO global ($conn)
core/sessions/               <- Directorio fallback de sesiones en archivo
```

---

## 3. Tablas de base de datos

### `php_sessions` -- Almacen de sesiones PHP

Reemplaza los archivos de sesion en disco. PHP escribe/lee aqui en lugar de `/tmp`.

```sql
CREATE TABLE `php_sessions` (
  `session_id`    VARCHAR(128)  NOT NULL,
  `data`          MEDIUMTEXT    NOT NULL DEFAULT '',
  `last_activity` INT UNSIGNED  NOT NULL DEFAULT 0,  -- timestamp Unix
  `created_at`    INT UNSIGNED  NOT NULL DEFAULT 0,  -- timestamp Unix
  PRIMARY KEY (`session_id`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Notas importantes:**
- La columna `data` contiene la serializacion nativa de PHP de `$_SESSION`.
- El GC propio de `DbSessionHandler::close()` elimina filas expiradas con probabilidad ~1% por peticion.
- Si el usuario de BD no tiene permisos sobre esta tabla, el sistema cae automaticamente al **modo fallback en archivos** (ver seccion 12).

---

### `erp_sesiones_activas` -- Auditoria de accesos

Registra cada login, actividad y motivo de cierre. **No es funcional para las sesiones PHP**, es solo auditoria.

```sql
CREATE TABLE `erp_sesiones_activas` (
  `id`               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  `session_id`       VARCHAR(128)  NOT NULL,
  `cod_operario`     INT           NOT NULL,
  `usuario_nombre`   VARCHAR(150)  DEFAULT NULL,
  `cargo_cod`        INT           DEFAULT NULL,
  `cargo_nombre`     VARCHAR(100)  DEFAULT NULL,
  `ip`               VARCHAR(45)   DEFAULT NULL,
  `user_agent`       VARCHAR(300)  DEFAULT NULL,
  `login_at`         DATETIME      DEFAULT NULL,
  `last_activity_at` DATETIME      DEFAULT NULL,  -- actualizado por ping.php
  `logout_at`        DATETIME      DEFAULT NULL,  -- NULL = sesion aun activa
  `motivo_cierre`    VARCHAR(50)   DEFAULT NULL,
  UNIQUE KEY `uq_session_id` (`session_id`),
  KEY `idx_logout_at` (`logout_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Valores de `motivo_cierre`:**

| Valor | Cuando se registra |
|-------|--------------------|
| `logout_manual` | El usuario hizo clic en "Salir" |
| `inactividad` | Expiro la ventana de 8 h sin actividad |
| `medianoche` | La sesion cruzo el cambio de dia |
| `forzado_sistema` | Reservado para futuros cierres administrativos |

> **Importante:** Las IPs se detectan considerando proxies de Hostinger/Cloudflare.
> Se revisan en orden: `HTTP_CF_CONNECTING_IP` -> `HTTP_X_FORWARDED_FOR` -> `HTTP_X_REAL_IP` -> `REMOTE_ADDR`.

---

## 4. Reglas de negocio de sesiones

### 4.1 Duracion

```php
define('SESSION_DURATION_SECONDS', 28800); // 8 horas
```

La constante se define en `session_manager.php` y puede sobreescribirse antes de incluir ese archivo.

### 4.2 Regla de inactividad (ventana deslizante)

- Cada accion del usuario (carga de pagina) actualiza `$_SESSION['last_activity']`.
- El heartbeat de `ping.php` lo renueva cada 5 minutos aunque el usuario no navegue.
- Si `time() - last_activity > 28800` -> sesion expirada por inactividad.

```
Usuario activo -> last_activity se renueva -> contador reinicia
                 <-------------- 8 horas ------------->
Usuario inactivo 8h -> verificarExpiracionSesion() lo detecta -> logout
```

### 4.3 Regla de corte a medianoche

- Al hacer login se guarda `$_SESSION['login_date'] = date('Y-m-d')` (zona `America/Managua`).
- En cada peticion, `verificarExpiracionSesion()` compara `login_date` con la fecha actual.
- Si difieren -> sesion expirada por medianoche, independientemente de la inactividad.

**Proposito de negocio:** Garantizar que los registros de actividad en el ERP correspondan siempre
al dia laboral correcto. Un usuario no puede operar en "ayer" si ya es hoy.

### 4.4 Prioridad de las reglas

La regla de **medianoche se evalua primero**. Si se cumple, no se evalua la de inactividad.

---

## 5. Flujo de inicio de sesion (ERP)

### 5.1 Verificaciones en `login.php`

El login de operarios pasa por **4 verificaciones en orden**:

```
POST usuario + password
        |
        v
1. usuario === 'admin'?
   +-- Si  -> tabla `usuarios`, password_verify() -> login directo
   +-- No  -> continua

2. Buscar en tabla `Operarios`
   +-- No existe?       -> "Usuario no encontrado"
   +-- Operativo != 1?  -> "Cuenta desactivada"
   +-- Clave incorrecta -> "Credenciales invalidas"
   +-- Clave correcta   -> continua

3. Tiene contrato activo? (tabla `Contratos`)
   +-- fin_contrato < hoy -> "Contrato finalizado el [fecha]"
   +-- Sin contrato        -> continua a verificacion de cargos

4. Tiene cargo vigente? (tabla `AsignacionNivelesCargos`)
   +-- Sin cargos + sin contrato  -> "Sin contrato activo ni movimientos vigentes"
   +-- Con contrato pero sin cargo -> "Sin cargos asignados"
   +-- Tiene cargo                 -> continua

OK: registrarInicioSesion()
```

### 5.2 Prioridad de cargos en login

Cuando el operario tiene varios cargos activos, se selecciona el principal con esta logica:

```sql
ORDER BY
    CASE WHEN anc.CodNivelesCargos = 2 THEN 1 ELSE 0 END, -- Cargo 2 va al final
    anc.Fecha DESC
LIMIT 1
```

El cargo `2` (operario base) tiene menor prioridad que cargos especificos.

### 5.3 Campos de formulario con nombres aleatorios

Para bloquear el autocompletado de contrasenas de Chrome/Edge, los campos `usuario` y `password`
se generan con nombres aleatorios en cada carga GET:

```php
$_SESSION['_fn_u'] = 'f_' . bin2hex(random_bytes(4)); // ej: f_a3f1b2c4
$_SESSION['_fn_p'] = 'f_' . bin2hex(random_bytes(4));
```

En el POST se leen con `$_POST[$_SESSION['_fn_u']]`. Esto tambien previene el bug de
"usuario no encontrado en el primer intento" cuando la cookie de sesion vieja sobrevivia
entre el GET y el POST.

### 5.4 Redirect despues del login

Si el usuario llega al login desde una redireccion de sesion expirada, la URL original se pasa como
`?redirect=/modulo/herramienta`. Solo se permiten rutas internas (deben empezar con `/` y no contener `//`).

---

## 6. Flujo de autenticacion POS

El POS (`pos.batidospitaya.com`) tiene un sistema de autenticacion en **dos etapas**, implementado en `auth_pos.php`:

```
Dispositivo (cookie pos_device_token)
        |
        v
Etapa 1: Dispositivo autorizado?
   +-- No -> pantalla de error (no es un dispositivo configurado por TI)
   +-- Si -> auto-login de tienda (pos_store_sucursal, pos_store_id)
        |
        v
Etapa 2: Colaborador ingresa su clave (PIN)
   +-- Verifica clave contra todos los operarios activos (bcrypt, respaldo texto plano)
   +-- Verifica marcacion activa HOY en la sucursal del dispositivo
   +-- Cargos exentos de marcacion: 11 (Gerencia), 16 (TI/Sistemas), 49 (Desarrollador)
   +-- Colaborador validado -> pos_colaborador_id en sesion
```

### 6.1 Modo desarrollo (DEV)

`auth_pos.php` tiene un bypass de desarrollo controlado por constante:

```php
define('POS_DEV_MODE', true);           // ADVERTENCIA: CAMBIAR A false EN PRODUCCION
define('POS_DEV_SUCURSAL_COD', 5);
define('POS_DEV_SUCURSAL_NOMBRE', 'Sucursal Test (DEV)');
```

Con `POS_DEV_MODE = true`:
- Se omite la validacion de cookie de dispositivo
- Se omite la validacion de marcacion del colaborador
- Se asigna automaticamente la sucursal de prueba

> ADVERTENCIA: Verificar que `POS_DEV_MODE` este en `false` antes de cualquier deploy a produccion.

---

## 7. Variables de sesion

### Variables del ERP (`$_SESSION`)

| Clave | Tipo | Descripcion |
|-------|------|-------------|
| `usuario_id` | int | CodOperario del operario o id del admin |
| `usuario_nombre` | string | Nombre de usuario (login) |
| `login_time` | int | Timestamp Unix del momento de login |
| `login_date` | string | Fecha Y-m-d del login (para regla de medianoche) |
| `last_activity` | int | Timestamp Unix de la ultima actividad |
| `cargo_cod` | int | CodNivelesCargos del cargo activo |
| `cargo_nombre` | string | Nombre del cargo |
| `usuario_rol` | string | 'admin' para el admin, no presente para operarios |
| `sucursal_id` | int | Solo para el admin |
| `modulos_permitidos` | array | Cacheado tras primera carga. Rutas de modulos con acceso |
| `datos_usuario_actual` | array | Fila completa de Operarios + cargo. Cacheada |
| `_fn_u` | string | Nombre aleatorio del campo usuario en el formulario de login |
| `_fn_p` | string | Nombre aleatorio del campo contrasena en el formulario de login |

### Variables adicionales del POS

| Clave | Tipo | Descripcion |
|-------|------|-------------|
| `pos_device_sucursal` | int | Codigo de sucursal del dispositivo (desde cookie) |
| `pos_store_id` | int | CodOperario del encargado de sucursal (Etapa 1) |
| `pos_store_usuario` | string | Nombre de usuario del encargado |
| `pos_store_sucursal` | int | Codigo de sucursal activa |
| `pos_store_sucursal_nombre` | string | Nombre de la sucursal |
| `pos_colaborador_id` | int | CodOperario del colaborador operando (Etapa 2) |
| `pos_colaborador_nombre` | string | Nombre completo del colaborador activo |

---

## 8. API publica del SessionManager

Todas las funciones estan en `session_manager.php`. Se incluye con `require_once`.

### `iniciarSesionSegura(): void`

Debe llamarse **una sola vez, al inicio de cada pagina**, antes de cualquier output.

```php
require_once __DIR__ . '/core/auth/session_manager.php';
iniciarSesionSegura();
```

**Lo que hace internamente:**
1. Si ya hay sesion activa (`PHP_SESSION_ACTIVE`), retorna inmediatamente.
2. Establece zona horaria `America/Managua`.
3. Intenta conectar a BD via `$conn` global o cargando `conexion.php`.
4. Hace un probe silencioso (`SELECT 1 FROM php_sessions LIMIT 1`) para verificar permisos.
5. Si hay permisos: registra `DbSessionHandler` como handler de sesiones.
6. Si no hay permisos o BD no disponible: cae a sesiones en archivos (`core/sessions/`).
7. Configura `gc_maxlifetime`, `cookie_lifetime`, cookies seguras (HttpOnly, SameSite=Lax, Secure).
8. Llama a `session_start()`. Si falla, hace segundo intento con handler nativo de archivos.

---

### `verificarExpiracionSesion(): bool`

Evalua si la sesion actual sigue siendo valida segun las reglas de negocio.

```php
$esValida = verificarExpiracionSesion();
// true  = sesion valida (o sin usuario logueado)
// false = sesion expirada y destruida
```

Se llama desde:
- `auth.php` -> `verificarAutenticacion()` (cada carga de pagina ERP)
- `ping.php` -> heartbeat del frontend
- `sesiones_ajax.php` -> endpoints de monitor

---

### `registrarInicioSesion($usuarioId, $usuarioNombre, array $datosAdicionales): void`

Llamar **solo despues de verificar credenciales correctamente**. Nunca llamar sin autenticar antes.

```php
registrarInicioSesion($user['CodOperario'], $user['usuario'], [
    'cargo_cod'    => $cargo['CodNivelesCargos'],
    'cargo_nombre' => $cargo['cargo_nombre'],
]);
```

Escribe en `$_SESSION` y ademas inserta una fila en `erp_sesiones_activas`.

---

### `cerrarSesion(): void`

Usar desde `logout.php`. Registra el cierre en auditoria, limpia `$_SESSION`,
invalida la cookie y destruye la sesion.

```php
// En logout.php:
require_once 'core/auth/session_manager.php';
iniciarSesionSegura();
cerrarSesion();
header('Location: /login.php');
exit();
```

---

### `actualizarPingAuditoria(string $sessionId): void`

Solo la llama `ping.php`. Actualiza `last_activity_at` en `erp_sesiones_activas`
sin afectar la logica de expiracion de `$_SESSION`.

---

## 9. Heartbeat y renovacion de actividad

El frontend llama a `ping.php` **cada 5 minutos** desde todas las paginas del ERP
(implementado en `header_universal.php`).

### Respuestas de `ping.php`

| HTTP | JSON `status` | Significado |
|------|--------------|-------------|
| `200` | `active` | Sesion viva. Incluye `time_left` (segundos restantes) y `usuario` |
| `401` | `expired` | Sesion expirada o no autenticada. Frontend redirige a login |

### Flujo del heartbeat

```
Cada 5 min: fetch('/core/auth/ping.php')
    |
    v
ping.php llama verificarExpiracionSesion()
    +-- Expirada -> HTTP 401 -> JS redirige a /login.php
    +-- Valida   -> session_write_close() -> actualiza last_activity_at en BD -> HTTP 200
```

> `session_write_close()` se llama **antes** de `actualizarPingAuditoria()` para liberar el lock
> de sesion y no bloquear otras peticiones concurrentes de la misma pestana.

---

## 10. Monitor de sesiones AJAX

El modulo de administracion incluye una pantalla de **Monitor de Sesiones**
que consume `ajax/sesiones_ajax.php`.

### Endpoints disponibles

| `?accion=` | Descripcion |
|------------|-------------|
| `kpis` | Contadores: sesiones activas ahora, logins hoy, cierres por inactividad, logouts manuales |
| `activas` | Lista de sesiones con `logout_at IS NULL` ordenadas por `last_activity_at DESC` |
| `historial` | Historial paginado. Params: `fecha1`, `fecha2`, `motivo`, `pagina`, `por_pagina` (max 200) |

---

## 11. Validacion de dispositivo Cargo 27

Los usuarios con `cargo_cod = 27` (Encargado de Sucursal) tienen una **validacion extra automatica**
que se ejecuta en cada carga de pagina, implementada directamente en `auth.php`:

```
Usuario con cargo 27 carga cualquier pagina ERP
        |
        v
Tiene sucursal asignada en AsignacionNivelesCargos?
   +-- No -> logout + error "sin sucursal asignada"
   +-- Si -> continua
        |
        v
verificarDispositivoAutorizado(sucursal, 'acceso')
   +-- No autorizado -> logout completo (cookie + sesion) + redirect a login
   +-- Autorizado    -> carga normal de la pagina
```

> El logout en este caso **tambien invalida la cookie** del navegador.
> Esto es critico para evitar el bucle ERR_TOO_MANY_REDIRECTS que ocurre si la cookie
> persiste pero la sesion esta destruida.

---

## 12. Almacenamiento BD vs Archivos

El sistema tiene un mecanismo de **fallback automatico** con tres niveles:

```
Intento 1: DbSessionHandler (MySQL, tabla php_sessions)
   +-- Conexion OK + permisos OK     -> usa BD
   +-- Conexion OK + sin permisos    -> Fallback
   +-- Sin conexion                  -> Fallback

Intento 2: Sesiones en archivos en core/sessions/
   +-- Directorio existe y es writable -> usa archivos
   +-- Sin permisos                     -> PHP usa su directorio por defecto (/tmp)
```

### Por que se creo el DbSessionHandler

Hostinger (hosting compartido) ejecuta el GC de sesiones PHP de forma agresiva, eliminando
archivos de sesion inactivos antes de que expiren las 8 horas del negocio. Almacenar las
sesiones en MySQL garantiza que el ERP controla cuando expira cada sesion.

### Incidente 30-Oct-2026

Las tablas `php_sessions` y `erp_sesiones_activas` fueron creadas el 28-Oct-2026 con un usuario
de BD con mas privilegios que `u839374897_erp`. Al subir el codigo, el usuario de la app no tenia
permisos SELECT sobre `php_sessions`, causando `SQLSTATE[42000]: 1142` durante `session_start()` -> HTTP 500.

**Solucion aplicada:** Antes de registrar el handler se hace un probe silencioso:

```php
$conn->query('SELECT 1 FROM `php_sessions` LIMIT 1');
```

Si falla, se usa el fallback de archivos automaticamente. Adicionalmente, `session_start()`
esta envuelto en `try/catch` como segunda linea de defensa.

---

## 13. Seguridad de cookies

La cookie de sesion se configura con:

| Atributo | Valor |
|----------|-------|
| `lifetime` | SESSION_DURATION_SECONDS (8 horas) |
| `path` | `/` |
| `secure` | true si HTTPS (detectado via headers de Cloudflare/proxy) |
| `httponly` | true (inaccesible desde JavaScript) |
| `samesite` | Lax (protege contra CSRF basico) |

Deteccion de HTTPS (en orden de prioridad):
1. `$_SERVER['HTTPS']`
2. `$_SERVER['SERVER_PORT'] == 443`
3. `HTTP_X_FORWARDED_PROTO`
4. `HTTP_X_FORWARDED_SSL`

---

## 14. Historico de incidentes

| Fecha | Incidente | Causa | Solucion |
|-------|-----------|-------|----------|
| 2026-10-28 | Se implementa DbSessionHandler y tablas de sesiones | -- | Commit a2ccb9d1 |
| 2026-10-30 | ERP da HTTP 500 en login.php | Tablas creadas con usuario root; usuario de la app sin permisos SELECT | Probe silencioso + fallback a archivos (commit 78ceff18) |

---

## 15. Queries utiles para diagnostico

```sql
-- Sesiones activas ahora mismo
SELECT cod_operario, usuario_nombre, cargo_nombre, ip, login_at, last_activity_at
FROM erp_sesiones_activas
WHERE logout_at IS NULL
ORDER BY last_activity_at DESC;

-- Historial de hoy
SELECT usuario_nombre, login_at, logout_at, motivo_cierre
FROM erp_sesiones_activas
WHERE DATE(login_at) = CURDATE()
ORDER BY login_at DESC;

-- Sesiones activas con tiempo transcurrido desde ultimo ping
SELECT usuario_nombre, cargo_nombre, ip, login_at, last_activity_at,
       TIMESTAMPDIFF(MINUTE, last_activity_at, NOW()) as minutos_sin_actividad
FROM erp_sesiones_activas
WHERE logout_at IS NULL
ORDER BY minutos_sin_actividad DESC;

-- Sesiones con mas de 8h de inactividad que no cerraron correctamente
SELECT *
FROM erp_sesiones_activas
WHERE logout_at IS NULL
  AND TIMESTAMPDIFF(HOUR, last_activity_at, NOW()) > 8;

-- Limpiar sesiones PHP huerfanas (> 16h)
DELETE FROM php_sessions
WHERE last_activity < UNIX_TIMESTAMP() - 57600;

-- Resumen de motivos de cierre del mes actual
SELECT motivo_cierre, COUNT(*) as total
FROM erp_sesiones_activas
WHERE login_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
GROUP BY motivo_cierre;

-- Verificar permisos del usuario de la app sobre las tablas de sesion
SELECT TABLE_NAME, PRIVILEGE_TYPE
FROM information_schema.TABLE_PRIVILEGES
WHERE GRANTEE LIKE '%u839374897_erp%'
  AND TABLE_NAME IN ('php_sessions', 'erp_sesiones_activas');
```

---

*Documento generado a partir del codigo fuente de `session_manager.php`, `DbSessionHandler.php`,
`auth.php`, `auth_pos.php`, `ping.php` y `ajax/sesiones_ajax.php`.*
