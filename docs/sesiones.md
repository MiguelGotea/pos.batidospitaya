# Sistema de Gestión de Sesiones — ERP Pitaya

> **Última actualización:** Octubre 2026  
> **Aplica a:** `erp.batidospitaya.com`

---

## Índice

1. [Contexto y problemas que se resolvieron](#1-contexto-y-problemas-que-se-resolvieron)
2. [Arquitectura general](#2-arquitectura-general)
3. [Archivos del sistema](#3-archivos-del-sistema)
4. [Flujo completo de una sesión](#4-flujo-completo-de-una-sesión)
5. [Tablas en base de datos](#5-tablas-en-base-de-datos)
6. [Reglas de expiración](#6-reglas-de-expiración)
7. [Heartbeat y coordinación entre pestañas](#7-heartbeat-y-coordinación-entre-pestañas)
8. [Auditoría: Monitor de Sesiones](#8-auditoría-monitor-de-sesiones)
9. [Bugs corregidos](#9-bugs-corregidos)
10. [Pendientes de configuración](#10-pendientes-de-configuración)

---

## 1. Contexto y problemas que se resolvieron

Antes de esta refactorización el sistema de sesiones presentaba tres fallos críticos:

### Bug A — Cierre de sesión inesperado mientras el usuario trabajaba
Hostinger ejecuta su propio GC (Garbage Collector) de archivos de sesión ignorando el `session.gc_maxlifetime` configurado en PHP. Esto destruía sesiones activas prematuramente.  
**Solución:** almacenar las sesiones en MySQL (`php_sessions`) usando `DbSessionHandler`, que es inmune al GC del hosting.

### Bug B — Al re-loguearse, otras pestañas redirigían a `index` en lugar de la herramienta correcta
Cuando una pestaña detectaba el 401 del heartbeat y enviaba al login con `?redirect=/modulos/xxx`, si otra pestaña ya había renovado la sesión, `login.php` veía la sesión activa e ignoraba el parámetro `redirect`, enviando siempre al hub de cargo.  
**Solución:** en `login.php`, si hay sesión activa y existe un `$redirectUrl` válido, honrarlo en el GET también.

### Bug C — "Usuario no encontrado" en el primer intento de login (funciona en el segundo)
`login.php` genera **nombres de campo aleatorios** (`_fn_u`, `_fn_p`) en cada carga GET para bloquear el autocompletado del navegador. Si la sesión anterior había expirado pero la cookie del navegador seguía apuntando al session ID antiguo, el POST llegaba con un session ID distinto al del GET → `$_SESSION['_fn_u']` no existía → PHP buscaba el campo literal `usuario` → no lo encontraba → "Usuario no encontrado".  
**Solución:** llamar a `session_regenerate_id(true)` en el GET de `login.php` para emitir una cookie limpia y garantizar que GET y POST compartan el mismo session ID.

---

## 2. Arquitectura general

```
Navegador
  │
  ├─ Cookie PHPSESSID ──────────────────────────────────────────────────┐
  │                                                                      │
  ├─ fetch /core/auth/ping.php (cada 5 min, por header_universal.php)   │
  │         │                                                            │
  │         ▼                                                            ▼
  │    session_manager.php                                         MySQL
  │    ├── iniciarSesionSegura()          ──────────► php_sessions
  │    ├── verificarExpiracionSesion()    ──────────► erp_sesiones_activas (UPDATE logout_at)
  │    ├── registrarInicioSesion()        ──────────► erp_sesiones_activas (INSERT)
  │    ├── actualizarPingAuditoria()      ──────────► erp_sesiones_activas (UPDATE last_activity_at)
  │    ├── cerrarSesion()                 ──────────► erp_sesiones_activas (UPDATE logout_at)
  │    └── _sm_registrarCierreSesion()   ──────────► erp_sesiones_activas (interno)
  │
  └─ BroadcastChannel('erp_session_hb')
       Si una pestaña detecta 401 → notifica a TODAS las demás pestañas
       Cada pestaña redirige a login.php?redirect=<su_url_actual>
```

---

## 3. Archivos del sistema

| Archivo | Rol |
|---|---|
| `core/auth/session_manager.php` | Núcleo. Todas las funciones públicas y privadas del sistema de sesiones |
| `core/auth/DbSessionHandler.php` | Handler MySQL para `php_sessions`. Implementa `SessionHandlerInterface` |
| `core/auth/ping.php` | Endpoint heartbeat. El frontend hace GET aquí cada 5 min |
| `core/auth/auth.php` | Wrapper: incluye `session_manager.php` y expone `verificarAutenticacion()` |
| `core/auth/ajax/sesiones_ajax.php` | Endpoint AJAX del monitor de sesiones (KPIs, activas, historial) |
| `core/layout/header_universal.php` | Contiene el JS del heartbeat (IIFE con `setInterval` + `BroadcastChannel`) |
| `login.php` | Login con nombres de campo aleatorios y `session_regenerate_id` |
| `logout.php` | Llama a `cerrarSesion()` que registra el motivo antes de destruir |
| `modulos/sistemas/sesiones_activas.php` | Pantalla del monitor de sesiones (solo Sistemas/Admin) |
| `core/auth/sql/crear_tablas_sesiones.sql` | Script SQL para crear `php_sessions` y `erp_sesiones_activas` |
| `core/auth/sql/registrar_tool_sesiones_activas.sql` | INSERT en `tools_erp` para registrar la herramienta |

---

## 4. Flujo completo de una sesión

### 4.1 Login

```
GET /login.php
  → iniciarSesionSegura()          # abre sesión con DbSessionHandler
  → session_regenerate_id(true)    # cookie limpia (fix Bug C)
  → genera _fn_u y _fn_p aleatorios y los guarda en $_SESSION

POST /login.php
  → lee campos con los nombres _fn_u/_fn_p de $_SESSION
  → valida credenciales contra BD
  → registrarInicioSesion($id, $nombre, ['cargo_cod'=>..., 'cargo_nombre'=>...])
      → guarda en $_SESSION: usuario_id, usuario_nombre, login_time, login_date, last_activity, cargo_*
      → INSERT en erp_sesiones_activas
  → header('Location: <redirectUrl o hub de cargo>')
```

### 4.2 Navegación normal (cada carga de página)

```
Cualquier página del ERP
  → require_once auth.php
  → iniciarSesionSegura()          # reanuda sesión desde php_sessions
  → verificarAutenticacion()
      → verificarExpiracionSesion()
          → comprueba login_date (medianoche) y last_activity (8 h)
          → si válida: $_SESSION['last_activity'] = time()
          → si expirada: UPDATE erp_sesiones_activas SET logout_at, motivo_cierre
                         session_destroy() → redirige a login.php?redirect=<url>
```

### 4.3 Heartbeat (cada 5 minutos, por header_universal.php)

```
setInterval → fetch GET /core/auth/ping.php
  → iniciarSesionSegura()
  → verificarExpiracionSesion()     # renueva last_activity en $_SESSION
  → si 200: actualizarPingAuditoria(session_id())
               → UPDATE erp_sesiones_activas SET last_activity_at = NOW()
  → si 401 (primer intento): espera 3 s y reintenta
  → si 401 (segundo intento): BroadcastChannel.postMessage({type:'SESSION_EXPIRED'})
               → todas las pestañas → window.location = /login.php?redirect=<su_url>
```

### 4.4 Logout manual

```
GET /logout.php
  → iniciarSesionSegura()
  → cerrarSesion()
      → _sm_registrarCierreSesion(session_id(), 'logout_manual')
          → UPDATE erp_sesiones_activas SET logout_at=NOW(), motivo_cierre='logout_manual'
      → $_SESSION = []
      → elimina cookie PHPSESSID
      → session_destroy()
  → header('Location: login.php')
```

---

## 5. Tablas en base de datos

> **Las tablas deben crearse manualmente** ejecutando:  
> `core/auth/sql/crear_tablas_sesiones.sql`  
> No hay ningún `CREATE TABLE` en el código PHP.

### `php_sessions` — Almacén técnico de sesiones PHP

| Columna | Tipo | Descripción |
|---|---|---|
| `session_id` | VARCHAR(128) | ID de la cookie del navegador. PK |
| `data` | MEDIUMTEXT | Contenido de `$_SESSION` serializado por PHP |
| `last_activity` | INT UNSIGNED | Unix timestamp de la última escritura |
| `created_at` | INT UNSIGNED | Unix timestamp de creación |

**Quién la usa:** PHP internamente a través de `DbSessionHandler`. No se consulta manualmente.  
**GC:** el handler tiene su propio GC que borra filas con `last_activity` antiguo (~1% de las peticiones).

### `erp_sesiones_activas` — Auditoría de negocio

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED | PK autoincremental |
| `session_id` | VARCHAR(128) | Vincula con `php_sessions.session_id` |
| `cod_operario` | INT | `CodOperario` o `id` de la tabla `usuarios` (admin) |
| `usuario_nombre` | VARCHAR(150) | Nombre del usuario al momento del login |
| `cargo_cod` | INT | Código del cargo activo |
| `cargo_nombre` | VARCHAR(150) | Nombre del cargo activo |
| `ip` | VARCHAR(45) | IP real del cliente (considera Cloudflare/proxies) |
| `user_agent` | VARCHAR(300) | User-Agent del navegador |
| `login_at` | DATETIME | Fecha y hora del login (hora Nicaragua) |
| `last_activity_at` | DATETIME | Última vez que ping.php actualizó (cada 5 min) |
| `logout_at` | DATETIME | Fecha de cierre. `NULL` = sesión todavía activa |
| `motivo_cierre` | ENUM | `logout_manual` / `inactividad` / `medianoche` / `forzado_sistema` |

**Quién la usa:** `session_manager.php` (escritura) y `sesiones_ajax.php` (lectura para el monitor).

---

## 6. Reglas de expiración

Definidas en `session_manager.php::verificarExpiracionSesion()`. Se evalúan en **cada petición** (carga de página o ping).

| Regla | Condición | Motivo registrado |
|---|---|---|
| **Inactividad** | `(time() - last_activity) > 28800` (8 horas) | `inactividad` |
| **Medianoche** | `login_date !== date('Y-m-d')` en hora Nicaragua | `medianoche` |

La constante `SESSION_DURATION_SECONDS` = `28800` (8 h) se define una sola vez al incluir `session_manager.php`.

`last_activity` se renueva en **cada petición válida** (carga de página o ping exitoso), por lo que es una **ventana deslizante**: la sesión solo expira si el usuario lleva 8 horas sin hacer absolutamente nada.

---

## 7. Heartbeat y coordinación entre pestañas

Implementado como IIFE en `core/layout/header_universal.php`, presente en **todas las páginas** del ERP.

```
PING_INTERVAL_MS = 5 min   → cada pestaña hace su propio ping cada 5 min
MIN_PING_GAP_MS  = 1 min   → mínimo entre pings por visibilitychange
```

### BroadcastChannel `erp_session_hb`

Cuando una pestaña confirma el **doble 401** (dos intentos con 3 s de diferencia):

1. Publica `{ type: 'SESSION_EXPIRED' }` en el canal
2. Todas las pestañas que escuchan el canal ejecutan `manejarSesionExpirada()`
3. Cada pestaña redirige a `/login.php?redirect=<su_propia_url_actual>`

Esto garantiza que al re-loguearse, **cada pestaña tiene su propio `?redirect=`** correcto y el usuario regresa exactamente a donde estaba en cada una.

### visibilitychange

Cuando el usuario vuelve a poner en foco una pestaña que estuvo en background (donde el browser puede throttlear los `setInterval`), se dispara un ping inmediato si han pasado más de 1 minuto desde el último. Esto detecta expiración antes de que el usuario intente hacer algo.

---

## 8. Auditoría: Monitor de Sesiones

**Ruta:** `/modulos/sistemas/sesiones_activas.php`  
**Permiso requerido:** `sesiones_activas` → acción `vista`  
**Tool registrada en:** `tools_erp` (nombre: `sesiones_activas`, grupo: `Sistemas`)

### Funcionalidades

- **KPIs en tiempo real:** sesiones activas ahora, logins del día, cierres por inactividad, logouts manuales
- **Tab "Activas ahora":** tabla con todos los usuarios conectados en este momento — nombre, cargo, IP, hora de login, última actividad, tiempo activo. Auto-refresca cada 60 s
- **Tab "Historial":** filtrable por rango de fechas y motivo de cierre, paginado a 50 registros por página

### Endpoint AJAX

`core/auth/ajax/sesiones_ajax.php` acepta `?accion=`:

| Acción | Descripción |
|---|---|
| `kpis` | Retorna los 4 contadores de KPIs |
| `activas` | Retorna todas las filas de `erp_sesiones_activas` con `logout_at IS NULL` |
| `historial` | Retorna filas filtradas por `fecha1`, `fecha2`, `motivo`, paginadas |

---

## 9. Bugs corregidos

### Bug A — Sesiones destruidas por GC de Hostinger
- **Causa:** PHP guardaba sesiones como archivos; el GC de Hostinger los borraba
- **Fix:** `DbSessionHandler` redirige todas las operaciones de sesión a MySQL (`php_sessions`)
- **Archivos:** `DbSessionHandler.php`, `session_manager.php::iniciarSesionSegura()`

### Bug B — Otras pestañas redirigen a `index` tras re-login
- **Causa:** `login.php` siempre llamaba `redirigirSegunCargo()` si había sesión activa, ignorando `?redirect=`
- **Fix:** si existe `$redirectUrl` válido y hay sesión activa en GET, hacer la redirección a esa URL
- **Archivo:** `login.php` línea ~25-31

### Bug C — "Usuario no encontrado" en el primer intento de login
- **Causa:** cookie de sesión antigua en el navegador → POST con session ID distinto al GET → `_fn_u`/`_fn_p` no existían en esa sesión → fallback a campo `usuario` literal → no encontrado
- **Fix:** `session_regenerate_id(true)` en el GET de `login.php` fuerza cookie limpia
- **Archivo:** `login.php`, bloque `if (REQUEST_METHOD !== 'POST')`

### Bug D — `time_left` en ping.php calculado con `login_time` (fijo) en lugar de `last_activity` (deslizante)
- **Causa:** `ping.php` calculaba segundos restantes como `SESSION_DURATION_SECONDS - (now - login_time)`, que caía a cero a las 8 h desde el login aunque el usuario estuviera activo
- **Fix:** usar `last_activity` para el cálculo
- **Archivo:** `core/auth/ping.php`

---

## 10. Pendientes de configuración

Tras desplegar los archivos y ejecutar los SQL, quedan estos pasos manuales:

- [ ] Ejecutar `core/auth/sql/crear_tablas_sesiones.sql` en la BD de producción
- [ ] Ejecutar `core/auth/sql/registrar_tool_sesiones_activas.sql` para registrar la herramienta
- [ ] Asignar permiso `vista` en `permisos_tools_erp` para `sesiones_activas` a los cargos de Sistemas/Admin
- [ ] Agregar la herramienta al menú lateral del módulo Sistemas (si aplica)

