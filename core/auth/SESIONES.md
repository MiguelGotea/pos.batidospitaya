# Sistema de Sesiones y Autenticacion - ERP Batidos Pitaya

> **Ultima actualizacion:** Noviembre 2026
> **Aplica a:** `erp.batidospitaya.com`
> **Referencia rapida en codigo:** `core/auth/SESIONES.md`

---

## Indice

1. [Contexto y problemas resueltos](#1-contexto-y-problemas-resueltos)
2. [Arquitectura general](#2-arquitectura-general)
3. [Archivos del sistema](#3-archivos-del-sistema)
4. [Tablas en base de datos](#4-tablas-en-base-de-datos)
5. [Reglas de expiracion de sesion](#5-reglas-de-expiracion-de-sesion)
6. [Flujo completo de una sesion](#6-flujo-completo-de-una-sesion)
7. [Flujo de inicio de sesion (ERP login.php)](#7-flujo-de-inicio-de-sesion-erp-loginphp)
8. [Flujo de autenticacion POS (auth_pos.php)](#8-flujo-de-autenticacion-pos-auth_posphp)
9. [Heartbeat y coordinacion entre pestanas](#9-heartbeat-y-coordinacion-entre-pestanas)
10. [Variables de sesion](#10-variables-de-sesion)
11. [API publica del SessionManager](#11-api-publica-del-sessionmanager)
12. [Monitor de sesiones (AJAX)](#12-monitor-de-sesiones-ajax)
13. [Validacion de dispositivo (Cargo 27)](#13-validacion-de-dispositivo-cargo-27)
14. [Almacenamiento: BD vs Archivos (fallback)](#14-almacenamiento-bd-vs-archivos-fallback)
15. [Seguridad de cookies](#15-seguridad-de-cookies)
16. [Bugs corregidos](#16-bugs-corregidos)
17. [Historico de incidentes](#17-historico-de-incidentes)
18. [Queries utiles para diagnostico](#18-queries-utiles-para-diagnostico)

---

## 1. Contexto y problemas resueltos

Antes de la refactorizacion de Octubre 2026 el sistema de sesiones tenia tres fallos criticos:

### Bug A - Sesiones destruidas por GC de Hostinger
Hostinger ejecuta su propio Garbage Collector ignorando el `session.gc_maxlifetime` configurado en PHP.
Destruia sesiones activas prematuramente mientras el usuario trabajaba.
**Solucion:** almacenar las sesiones en MySQL (`php_sessions`) usando `DbSessionHandler`,
que es inmune al GC del hosting.

### Bug B - Otras pestanas redirigian a `index` tras re-login
Cuando una pestana detectaba el 401 del heartbeat y mandaba al login con `?redirect=/modulos/xxx`,
si otra pestana ya habia renovado la sesion, `login.php` veia la sesion activa e ignoraba el redirect,
enviando siempre al hub de cargo.
**Solucion:** en `login.php`, si hay sesion activa y existe un `$redirectUrl` valido, honrarlo tambien en el GET.

### Bug C - "Usuario no encontrado" en el primer intento de login
`login.php` genera nombres de campo aleatorios (`_fn_u`, `_fn_p`) en cada carga GET para bloquear
el autocompletado del navegador. Si la sesion anterior habia expirado pero la cookie del navegador
seguia apuntando al session ID antiguo, el POST llegaba con un session ID distinto al del GET.
`$_SESSION['_fn_u']` no existia -> PHP buscaba el campo literal `usuario` -> "Usuario no encontrado".
**Solucion:** `session_regenerate_id(true)` en el GET de `login.php` para emitir cookie limpia.

### Bug D - `time_left` en ping.php calculado con `login_time` (fijo) en lugar de `last_activity`
ping.php calculaba `SESSION_DURATION_SECONDS - (now - login_time)`, que caia a cero a las 8h desde
el login aunque el usuario estuviera activo.
**Solucion:** usar `last_activity` para el calculo del tiempo restante.

---

## 2. Arquitectura general

```
Navegador
  |
  +-- Cookie PHPSESSID ------------------------------------------------+
  |                                                                     |
  +-- fetch /core/auth/ping.php (cada 5 min, via header_universal.php) |
  |         |                                                           v
  |         v                                                        MySQL
  |    session_manager.php                                     php_sessions
  |    +-- iniciarSesionSegura()       --------+
  |    +-- verificarExpiracionSesion() -----+  |          erp_sesiones_activas
  |    +-- registrarInicioSesion()     --+  |  |          +-- INSERT (login)
  |    +-- actualizarPingAuditoria()   --+--+--+-------> +-- UPDATE last_activity_at (ping)
  |    +-- cerrarSesion()              --+              +-- UPDATE logout_at (cierre)
  |
  +-- BroadcastChannel('erp_session_hb')
       Si una pestana detecta doble 401 -> notifica a TODAS las demas
       Cada pestana redirige a login.php?redirect=<su_url_actual>
```

---

## 3. Archivos del sistema

| Archivo | Rol |
|---------|-----|
| `core/auth/session_manager.php` | Nucleo. Todas las funciones publicas y privadas del sistema |
| `core/auth/DbSessionHandler.php` | Handler MySQL para `php_sessions`. Implementa `SessionHandlerInterface` |
| `core/auth/ping.php` | Endpoint heartbeat. El frontend hace fetch aqui cada 5 min |
| `core/auth/auth.php` | Guard ERP: incluye `session_manager.php` + `verificarAutenticacion()` + validacion cargo 27 |
| `core/auth/auth_pos.php` | Guard y logica de autenticacion de dos etapas para POS |
| `core/auth/ajax/sesiones_ajax.php` | Endpoint AJAX del monitor de sesiones (KPIs, activas, historial) |
| `core/layout/header_universal.php` | Contiene el JS del heartbeat (IIFE + setInterval + BroadcastChannel) |
| `login.php` | Login ERP con nombres de campo aleatorios y session_regenerate_id |
| `logout.php` | Llama a `cerrarSesion()` que registra el motivo antes de destruir |
| `modulos/sistemas/sesiones_activas.php` | Pantalla del monitor de sesiones (solo Sistemas/Admin) |
| `core/auth/sql/crear_tablas_sesiones.sql` | Script SQL para crear `php_sessions` y `erp_sesiones_activas` |
| `core/auth/sql/registrar_tool_sesiones_activas.sql` | INSERT en `tools_erp` para registrar la herramienta |
| `core/sessions/` | Directorio de fallback cuando MySQL no esta disponible |

---

## 4. Tablas en base de datos

> Las tablas deben crearse ejecutando: `core/auth/sql/crear_tablas_sesiones.sql`

### `php_sessions` -- Almacen tecnico de sesiones PHP

Reemplaza los archivos de sesion en disco. PHP escribe/lee aqui en lugar de `/tmp`.
No se consulta manualmente -- es de uso exclusivo de `DbSessionHandler`.

| Columna | Tipo | Nulo | Descripcion |
|---------|------|------|-------------|
| `session_id` | varchar(128) | NO | ID de sesion. PRIMARY KEY |
| `data` | mediumtext | NO | Contenido de `$_SESSION` serializado por PHP |
| `last_activity` | int(10) unsigned | NO | Unix timestamp de la ultima escritura. INDEX |
| `created_at` | int(10) unsigned | NO | Unix timestamp de creacion |

**GC propio:** `DbSessionHandler::close()` borra filas expiradas con ~1% de probabilidad por peticion.

### `erp_sesiones_activas` -- Auditoria de negocio

Registra cada login, actividad y motivo de cierre. No afecta el funcionamiento de sesiones PHP.

| Columna | Tipo | Nulo | Default | Descripcion |
|---------|------|------|---------|-------------|
| `id` | int(10) unsigned | NO | auto_increment | PRIMARY KEY |
| `session_id` | varchar(128) | NO | -- | Vincula con `php_sessions.session_id`. INDEX |
| `cod_operario` | int(11) | NO | -- | CodOperario o id del admin. INDEX |
| `usuario_nombre` | varchar(150) | NO | -- | Nombre del usuario al momento del login |
| `cargo_cod` | int(11) | SI | NULL | Codigo del cargo activo |
| `cargo_nombre` | varchar(150) | SI | NULL | Nombre del cargo activo |
| `ip` | varchar(45) | NO | '' | IP real del cliente (considera Cloudflare/proxies) |
| `user_agent` | varchar(300) | NO | '' | User-Agent del navegador (max 300 chars) |
| `login_at` | datetime | NO | current_timestamp | Fecha y hora del login. INDEX |
| `last_activity_at` | datetime | NO | current_timestamp | Ultimo ping exitoso. Actualizado por ping.php |
| `logout_at` | datetime | SI | NULL | Fecha de cierre. NULL = sesion todavia activa. INDEX |
| `motivo_cierre` | enum | SI | NULL | logout_manual / inactividad / medianoche / forzado_sistema |

> Las IPs se detectan en orden: `HTTP_CF_CONNECTING_IP` -> `HTTP_X_FORWARDED_FOR` -> `HTTP_X_REAL_IP` -> `REMOTE_ADDR`

**Indices reales en produccion:**
- PRIMARY KEY (`id`)
- `idx_session_id` (`session_id`)
- `idx_cod_operario` (`cod_operario`)
- `idx_login_at` (`login_at`)
- `idx_activas` (`logout_at`)

**Estadisticas de produccion (Noviembre 2026):**
- Total sesiones registradas: 1,119
- Sesiones realmente activas: ~28
- Cierres por logout manual: 482
- Cierres por medianoche: 12
- Sesiones huerfanas (sin logout_at por fallback a archivos 29-Oct a 5-Nov): 597

---

## 5. Reglas de expiracion de sesion

Definidas en `session_manager.php::verificarExpiracionSesion()`. Se evaluan en cada peticion.
La constante `SESSION_DURATION_SECONDS = 28800` (8 horas).

| Regla | Condicion | Motivo registrado | Prioridad |
|-------|-----------|-------------------|-----------|
| **Medianoche** | `login_date !== date('Y-m-d')` en hora Nicaragua (UTC-6) | `medianoche` | 1 (se evalua primero) |
| **Inactividad** | `(time() - last_activity) > 28800` | `inactividad` | 2 |

`last_activity` se renueva en cada peticion valida y en cada ping exitoso -> **ventana deslizante**.
La sesion solo expira si el usuario lleva 8 horas sin hacer absolutamente nada.

**Proposito del corte a medianoche:** garantizar que los registros de actividad en el ERP correspondan
siempre al dia laboral correcto. Un usuario no puede operar en "ayer" si ya es hoy.

---

## 6. Flujo completo de una sesion

### 6.1 Login

```
GET /login.php
  -> iniciarSesionSegura()          # abre sesion con DbSessionHandler
  -> session_regenerate_id(true)    # cookie limpia (fix Bug C)
  -> genera _fn_u y _fn_p aleatorios y los guarda en $_SESSION

POST /login.php
  -> lee campos con los nombres _fn_u/_fn_p de $_SESSION
  -> valida credenciales contra BD (ver seccion 7)
  -> registrarInicioSesion($id, $nombre, ['cargo_cod'=>..., 'cargo_nombre'=>...])
      -> guarda en $_SESSION: usuario_id, usuario_nombre, login_time, login_date, last_activity, cargo_*
      -> INSERT en erp_sesiones_activas
  -> header('Location: <redirectUrl o hub de cargo>')
```

### 6.2 Navegacion normal (cada carga de pagina)

```
Cualquier pagina del ERP
  -> require_once auth.php
  -> iniciarSesionSegura()          # reanuda sesion desde php_sessions
  -> verificarAutenticacion()
      -> verificarExpiracionSesion()
          -> comprueba login_date (medianoche) y last_activity (8 h)
          -> si valida: $_SESSION['last_activity'] = time()
          -> si expirada: UPDATE erp_sesiones_activas SET logout_at, motivo_cierre
                         session_destroy() -> redirige a login.php?redirect=<url>
```

### 6.3 Heartbeat (cada 5 minutos)

```
setInterval -> fetch GET /core/auth/ping.php
  -> iniciarSesionSegura()
  -> verificarExpiracionSesion()     # renueva last_activity en $_SESSION
  -> si 200: session_write_close() -> actualizarPingAuditoria(session_id())
                -> UPDATE erp_sesiones_activas SET last_activity_at = NOW()
  -> si 401 (primer intento): espera 3 s y reintenta
  -> si 401 (segundo intento): BroadcastChannel.postMessage({type:'SESSION_EXPIRED'})
               -> todas las pestanas -> window.location = /login.php?redirect=<su_url>
```

### 6.4 Logout manual

```
GET /logout.php
  -> iniciarSesionSegura()
  -> cerrarSesion()
      -> _sm_registrarCierreSesion(session_id(), 'logout_manual')
          -> UPDATE erp_sesiones_activas SET logout_at=NOW(), motivo_cierre='logout_manual'
      -> $_SESSION = []
      -> elimina cookie PHPSESSID (setcookie con tiempo negativo)
      -> session_destroy()
  -> header('Location: login.php')
```

---

## 7. Flujo de inicio de sesion (ERP login.php)

El login de operarios pasa por 4 verificaciones en orden:

```
POST usuario + password
        |
        v
1. usuario === 'admin'?
   +-- Si  -> tabla `usuarios`, password_verify() -> login directo
   +-- No  -> continua

2. Buscar en tabla `Operarios`
   +-- No existe?       -> "Cuenta no encontrada"
   +-- Operativo != 1?  -> "Cuenta desactivada"
   +-- Clave incorrecta -> "Credenciales invalidas"
   +-- Clave correcta   -> continua

3. Tiene contrato activo? (tabla `Contratos`)
   +-- fin_contrato < hoy -> "Contrato finalizado el [fecha]"
   +-- Sin contrato        -> continua a verificacion de cargos

4. Tiene cargo vigente? (tabla `AsignacionNivelesCargos`)
   +-- Sin cargos + sin contrato  -> "Sin contrato activo ni movimientos vigentes"
   +-- Con contrato pero sin cargo -> "Sin cargos asignados"
   +-- Tiene cargo                 -> login exitoso

OK -> registrarInicioSesion() -> redireccion
```

**Prioridad de cargo cuando hay varios activos:**
El cargo `2` (operario base) tiene menor prioridad; se selecciona el cargo especifico mas reciente.

**Redirect seguro despues del login:**
Solo se honran rutas internas (deben empezar con `/` y no contener `//`).

---

## 8. Flujo de autenticacion POS (auth_pos.php)

El POS tiene autenticacion en dos etapas:

```
Dispositivo (cookie pos_device_token)
        |
        v
Etapa 1: Dispositivo autorizado? (tabla sucursales.pos_cookie_token)
   +-- No -> pantalla de error (dispositivo no configurado por TI)
   +-- Si -> auto-login de tienda (pos_store_sucursal, pos_store_id en sesion)
        |
        v
Etapa 2: Colaborador ingresa su PIN
   +-- Verifica clave contra todos los operarios activos (bcrypt, respaldo texto plano)
   +-- Verifica marcacion activa HOY en la sucursal del dispositivo
   +-- Cargos exentos de marcacion: 11 (Gerencia), 16 (TI/Sistemas), 49 (Desarrollador)
   +-- Validado -> pos_colaborador_id en sesion
```

**Modo desarrollo (DEV):**
```php
define('POS_DEV_MODE', true);           // ADVERTENCIA: false en produccion
define('POS_DEV_SUCURSAL_COD', 5);
```
Con DEV=true: omite validacion de cookie y marcacion, asigna sucursal de prueba automaticamente.

---

## 9. Heartbeat y coordinacion entre pestanas

Implementado como IIFE en `core/layout/header_universal.php`, presente en todas las paginas.

```
PING_INTERVAL_MS = 5 min  -> cada pestana hace su propio ping cada 5 min
MIN_PING_GAP_MS  = 1 min  -> minimo entre pings por visibilitychange
```

### BroadcastChannel 'erp_session_hb'

Cuando una pestana confirma doble 401 (dos intentos con 3 s de diferencia):
1. Publica `{ type: 'SESSION_EXPIRED' }` en el canal
2. Todas las pestanas ejecutan `manejarSesionExpirada()`
3. Cada pestana redirige a `/login.php?redirect=<su_propia_url_actual>`

Esto garantiza que al re-loguearse, cada pestana tiene su propio `?redirect=` correcto.

### visibilitychange

Cuando el usuario vuelve a poner en foco una pestana que estuvo en background, se dispara
un ping inmediato si han pasado mas de 1 minuto desde el ultimo. Detecta expiracion antes
de que el usuario intente hacer algo.

---

## 10. Variables de sesion

### Variables del ERP

| Clave | Tipo | Descripcion |
|-------|------|-------------|
| `usuario_id` | int | CodOperario del operario o id del admin |
| `usuario_nombre` | string | Nombre de usuario (login) |
| `login_time` | int | Timestamp Unix del momento de login |
| `login_date` | string | Fecha Y-m-d del login (para regla de medianoche) |
| `last_activity` | int | Timestamp Unix de la ultima actividad |
| `cargo_cod` | int | CodNivelesCargos del cargo activo |
| `cargo_nombre` | string | Nombre del cargo |
| `usuario_rol` | string | 'admin' para el admin; ausente para operarios |
| `sucursal_id` | int | Solo para el admin |
| `modulos_permitidos` | array | Cacheado. Rutas de modulos con acceso |
| `datos_usuario_actual` | array | Fila completa Operarios + cargo. Cacheada |
| `_fn_u` | string | Nombre aleatorio del campo usuario en el formulario de login |
| `_fn_p` | string | Nombre aleatorio del campo contrasena en el formulario de login |

### Variables adicionales del POS

| Clave | Tipo | Descripcion |
|-------|------|-------------|
| `pos_device_sucursal` | int | Codigo de sucursal del dispositivo |
| `pos_store_id` | int | CodOperario del encargado de sucursal (Etapa 1) |
| `pos_store_usuario` | string | Usuario del encargado |
| `pos_store_sucursal` | int | Codigo de sucursal activa |
| `pos_store_sucursal_nombre` | string | Nombre de la sucursal |
| `pos_colaborador_id` | int | CodOperario del colaborador operando (Etapa 2) |
| `pos_colaborador_nombre` | string | Nombre completo del colaborador activo |

---

## 11. API publica del SessionManager

Todas las funciones estan en `core/auth/session_manager.php`.

### `iniciarSesionSegura(): void`
Llamar una sola vez al inicio de cada pagina, antes de cualquier output.
Internamente: detecta si BD tiene permisos (probe silencioso), registra handler o cae a archivos,
configura cookies seguras, llama `session_start()` con doble fallback.

### `verificarExpiracionSesion(): bool`
Evalua reglas de negocio. Retorna `true` si valida o sin usuario; `false` si expiro y se destruyo.

### `registrarInicioSesion($usuarioId, $usuarioNombre, array $datosAdicionales): void`
Llamar solo despues de verificar credenciales. Escribe en `$_SESSION` e inserta en `erp_sesiones_activas`.

### `cerrarSesion(): void`
Usar desde `logout.php`. Registra en auditoria, limpia sesion, invalida cookie, destruye sesion.

### `actualizarPingAuditoria(string $sessionId): void`
Solo la llama `ping.php`. Actualiza `last_activity_at` en auditoria sin afectar logica de expiracion.

---

## 12. Monitor de sesiones (AJAX)

**Ruta pantalla:** `/modulos/sistemas/sesiones_activas.php`
**Permiso requerido:** `sesiones_activas` -> accion `vista`
**Tool registrada en:** `tools_erp` (grupo: Sistemas)

### Endpoints `core/auth/ajax/sesiones_ajax.php`

| `?accion=` | Descripcion |
|------------|-------------|
| `kpis` | Contadores: activas ahora, logins hoy, cierres por inactividad, logouts manuales |
| `activas` | Sesiones con `logout_at IS NULL`, ordenadas por `last_activity_at DESC` |
| `historial` | Paginado. Params: `fecha1`, `fecha2`, `motivo`, `pagina`, `por_pagina` (max 200) |

---

## 13. Validacion de dispositivo (Cargo 27)

Usuarios con `cargo_cod = 27` (Encargado de Sucursal) tienen validacion extra automatica
en cada carga de pagina, implementada en `auth.php`:

```
Cargo 27 carga cualquier pagina ERP
        |
        v
Tiene sucursal asignada en AsignacionNivelesCargos?
   +-- No -> logout completo + cookie borrada + error "sin sucursal asignada"
   +-- Si -> continua
        |
        v
verificarDispositivoAutorizado(sucursal, 'acceso')
   +-- No autorizado -> logout completo + cookie borrada + redirect a login
   +-- Autorizado    -> carga normal
```

> CRITICO: se borra tambien la cookie del navegador al hacer logout aqui.
> Sin esto se produce ERR_TOO_MANY_REDIRECTS porque la cookie persiste con la sesion destruida.

---

## 14. Almacenamiento: BD vs Archivos (fallback)

```
Intento 1: DbSessionHandler (MySQL, tabla php_sessions)
   +-- Conexion OK + probe SELECT 1 FROM php_sessions OK -> usa BD
   +-- Probe falla (sin permisos)                        -> Fallback
   +-- Sin conexion a BD                                 -> Fallback

Intento 2: Sesiones en archivos en core/sessions/
   +-- Directorio writable -> usa archivos propios
   +-- Sin permisos        -> PHP usa /tmp (default del sistema)

Segundo fallback en session_start():
   Si session_start() lanza Throwable con handler de BD:
   -> session_set_save_handler(new SessionHandler(), true)
   -> _sm_usarSesionesPorArchivo()
   -> reintenta session_start()
```

**Por que se creo el DbSessionHandler:**
Hostinger ejecuta el GC de sesiones PHP de forma agresiva, eliminando archivos antes de las 8h.
MySQL garantiza que el ERP controla cuando expira cada sesion.

**Nota sobre sesiones huerfanas:**
Si el sistema cayo en fallback a archivos y los usuarios cerraron el navegador sin logout,
`erp_sesiones_activas` puede quedar con filas `logout_at = NULL` que son sesiones ya terminadas.
Limpiar periodicamente con el SQL de la seccion 18.

---

## 15. Seguridad de cookies

| Atributo | Valor |
|----------|-------|
| `lifetime` | SESSION_DURATION_SECONDS (8 horas) |
| `path` | `/` |
| `secure` | true si HTTPS (detectado via 4 headers de proxy/Cloudflare) |
| `httponly` | true (inaccesible desde JavaScript) |
| `samesite` | Lax (proteccion basica contra CSRF) |

Deteccion de HTTPS en orden: `$_SERVER['HTTPS']` -> `SERVER_PORT == 443` -> `HTTP_X_FORWARDED_PROTO` -> `HTTP_X_FORWARDED_SSL`

---

## 16. Bugs corregidos

### Bug A - Sesiones destruidas por GC de Hostinger
- **Causa:** archivos de sesion borrados por GC de hosting
- **Fix:** DbSessionHandler redirige operaciones a MySQL
- **Archivos:** `DbSessionHandler.php`, `session_manager.php`

### Bug B - Otras pestanas redirigen a `index` tras re-login
- **Causa:** `login.php` ignoraba `?redirect=` si habia sesion activa
- **Fix:** honrar el redirect en GET cuando hay sesion activa
- **Archivo:** `login.php` linea ~25-31

### Bug C - "Usuario no encontrado" en el primer intento de login
- **Causa:** cookie de sesion antigua -> POST con session ID distinto al GET -> `_fn_u`/`_fn_p` no existian
- **Fix:** `session_regenerate_id(true)` en el GET de `login.php`
- **Archivo:** `login.php`, bloque `if (REQUEST_METHOD !== 'POST')`

### Bug D - `time_left` en ping.php calculado con `login_time` en lugar de `last_activity`
- **Causa:** caia a cero a las 8h desde el login aunque el usuario estuviera activo
- **Fix:** usar `last_activity` para el calculo
- **Archivo:** `core/auth/ping.php`

---

## 17. Historico de incidentes

| Fecha | Incidente | Causa raiz | Solucion | Commit |
|-------|-----------|------------|----------|--------|
| 2026-10-28 | Implementacion de DbSessionHandler y tablas de sesiones | -- | -- | a2ccb9d1 |
| 2026-10-30 | ERP da HTTP 500 en login.php | Tablas creadas con usuario root; usuario de la app sin permisos SELECT en `php_sessions` -> SQLSTATE 1142 | Probe silencioso antes de registrar handler + fallback automatico a archivos | 78ceff18 |
| 2026-10-30 al 2026-11-05 | Sesiones en archivos (fallback activo) | Falta de permisos MySQL hasta resolver incidente | Probe silencioso + fallback | -- |
| 2026-11-06 | 597 sesiones huerfanas en erp_sesiones_activas | Durante periodo de fallback, los logouts no actualizaban la auditoria en BD | Limpiar con SQL de la seccion 18 | -- |

---

## 18. Queries utiles para diagnostico

```sql
-- Sesiones realmente activas ahora (menos de 8h de inactividad)
SELECT usuario_nombre, cargo_nombre, ip, login_at, last_activity_at,
       TIMESTAMPDIFF(MINUTE, last_activity_at, NOW()) AS min_inactivo
FROM erp_sesiones_activas
WHERE logout_at IS NULL
  AND last_activity_at >= NOW() - INTERVAL 8 HOUR
ORDER BY last_activity_at DESC;

-- Conteo de sesiones activas reales vs huerfanas
SELECT
  SUM(CASE WHEN logout_at IS NULL AND last_activity_at >= NOW() - INTERVAL 8 HOUR THEN 1 ELSE 0 END) AS activas_reales,
  SUM(CASE WHEN logout_at IS NULL AND last_activity_at < NOW() - INTERVAL 8 HOUR THEN 1 ELSE 0 END)  AS huerfanas,
  SUM(CASE WHEN logout_at IS NOT NULL THEN 1 ELSE 0 END) AS cerradas
FROM erp_sesiones_activas;

-- Limpiar sesiones huerfanas (cerrarlas retroactivamente)
UPDATE erp_sesiones_activas
SET logout_at     = DATE_ADD(last_activity_at, INTERVAL 8 HOUR),
    motivo_cierre = 'forzado_sistema'
WHERE logout_at IS NULL
  AND last_activity_at < NOW() - INTERVAL 8 HOUR;

-- Historial del dia de hoy
SELECT usuario_nombre, cargo_nombre, login_at, logout_at, motivo_cierre
FROM erp_sesiones_activas
WHERE DATE(login_at) = CURDATE()
ORDER BY login_at DESC;

-- Resumen de motivos de cierre del mes actual
SELECT motivo_cierre, COUNT(*) AS total
FROM erp_sesiones_activas
WHERE login_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
GROUP BY motivo_cierre;

-- Limpiar sesiones PHP huerfanas en php_sessions (> 16h sin actividad)
DELETE FROM php_sessions
WHERE last_activity < UNIX_TIMESTAMP() - 57600;

-- Verificar permisos del usuario de la app sobre las tablas de sesion
SELECT TABLE_NAME, PRIVILEGE_TYPE
FROM information_schema.TABLE_PRIVILEGES
WHERE GRANTEE LIKE '%u839374897_erp%'
  AND TABLE_NAME IN ('php_sessions', 'erp_sesiones_activas');
```

---

## Pendientes de configuracion

- [x] Tablas `php_sessions` y `erp_sesiones_activas` creadas en produccion
- [x] Herramienta `sesiones_activas` registrada en `tools_erp`
- [ ] Limpiar 597 sesiones huerfanas (ver SQL de seccion 18)
- [ ] Ejecutar `registrar_tool_sesiones_activas.sql` si la herramienta no aparece en el menu Sistemas

---

*Documento maestro. Referencia rapida de codigo en `core/auth/SESIONES.md`.*
*Esquemas de tablas verificados contra BD de produccion via MCP (Noviembre 2026).*
