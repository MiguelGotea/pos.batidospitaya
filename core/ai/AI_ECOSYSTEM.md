# 🤖 Ecosistema de IA — Batidos Pitaya
> **Ubicación:** `core/ai/` · **Mantenido por:** Equipo de Desarrollo Pitaya  
> **Última actualización:** 2026-11-03

Este documento describe **todos los servicios y módulos que consumen Inteligencia Artificial** dentro del ecosistema Pitaya.  
Su objetivo es servir de referencia para nuevas implementaciones, integraciones y mantenimiento.

---

## 📐 Arquitectura General

```
ia_proveedores_api (MySQL)
        │
        ▼
  AIService.php ◄─── Clase centralizada (core/ai/AIService.php)
        │               Soporta: Groq, OpenAI, DeepSeek, Google,
        │               Cerebras, OpenRouter, HuggingFace, Mistral
        │
        ├── ERP PHP modules (via require_once AIService.php)
        │
        └── Proyectos Python VPS (llaman directo a Gemini API REST)
                ├── HikvisionAnalisisIA
                └── ResumenReunionesIA
```

### Principios de diseño
- **Un solo punto de configuración:** Las API Keys se gestionan en la tabla `ia_proveedores_api`. Ningún módulo tiene keys hardcodeadas.
- **Cascada de proveedores:** Cada módulo define un orden de proveedores a intentar. Si el primero falla (429/quota), rota automáticamente al siguiente.
- **Auto-reset diario:** `AIService` reinicia el flag `limite_alcanzado_hoy` cada nuevo día UTC.

---

## 🗄️ Tabla de Configuración de Keys

```sql
-- Tabla: ia_proveedores_api
-- Base de datos: Hostinger (erp.batidospitaya.com)

SELECT id, proveedor, activa, limite_alcanzado_hoy, ultimo_uso
FROM ia_proveedores_api
ORDER BY proveedor;
```

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT | PK |
| `proveedor` | VARCHAR | `'google'`, `'groq'`, `'openai'`, `'deepseek'`, `'mistral'`, `'cerebras'`, `'openrouter'`, `'huggingface'` |
| `api_key` | TEXT | Clave del proveedor |
| `activa` | TINYINT | `1` = en uso, `0` = deshabilitada |
| `limite_alcanzado_hoy` | TINYINT | `1` = cuota diaria agotada (se resetea al día siguiente) |
| `ultimo_uso` | DATETIME | Timestamp del último request exitoso |

> **Nota:** `HikvisionAnalisisIA` y `ResumenReunionesIA` obtienen su key de Google via el endpoint  
> `GET api.batidospitaya.com/api/resumen_reuniones_ia/obtener_key_gemini.php`  
> que también lee de esta misma tabla.

---

## 🏗️ Clase Central: `AIService.php`

**Ruta:** `core/ai/AIService.php`

### Proveedores soportados

| Proveedor | Endpoint | Modelo por defecto |
|-----------|----------|--------------------|
| `google` | `generativelanguage.googleapis.com/v1beta/models/` | `gemini-flash-latest` |
| `groq` | `api.groq.com/openai/v1/chat/completions` | `llama-3.3-70b-versatile` |
| `openai` | `api.openai.com/v1/chat/completions` | `gpt-4o-mini` |
| `deepseek` | `api.deepseek.com/chat/completions` | `deepseek-chat` |
| `mistral` | `api.mistral.ai/v1/chat/completions` | `mistral-medium-latest` |
| `cerebras` | `api.cerebras.ai/v1/chat/completions` | `llama3.1-70b` |
| `openrouter` | `openrouter.ai/api/v1/chat/completions` | `nvidia/nemotron-3-nano-30b-a3b:free` |
| `huggingface` | `router.huggingface.co/v1/chat/completions` | `meta-llama/Llama-3.2-3B-Instruct` |

### Métodos principales

```php
new AIService($conn, 'google')       // Instanciar con proveedor
$svc->procesarPrompt($sys, $user)    // Enviar prompt y obtener respuesta
$svc->extraerJSON($content)          // Parsear JSON de respuesta LLM
$svc->setModel('gemini-2.5-pro')     // Sobreescribir modelo
$svc->getProveedor()                 // Nombre del proveedor activo
$svc->hasAvailableKeys()             // Verificar si hay keys disponibles
```

---

## 📦 Módulos que usan IA

---

### 1. 🏪 Gestión de Anulaciones — Validación Manual
**Módulo:** `Sistemas`  
**Archivo:** `modulos/sistemas/ajax/anulaciones_ia_validar.php`

**¿Qué hace?**  
Cuando un operador abre una solicitud de anulación de pedido, puede pulsar "Consultar IA". El sistema envía el pedido a anular, el pedido de cambio (si existe) y el motivo declarado. La IA analiza coherencia y emite una recomendación.

**Cascada de proveedores:**
```
google → openai → deepseek → mistral → cerebras → groq
```

**Formato de respuesta esperado (JSON):**
```json
{
  "decision": "aprobar | rechazar | revisar",
  "confianza": "alta | media | baja",
  "comentario": "Explicación para el operador (máx 300 chars)",
  "puntos": ["Punto 1 del análisis", "Punto 2"]
}
```

**Comportamiento:**  
- Si IA recomienda `aprobar` → el modal auto-aprueba tras 1.8 s.  
- Si `rechazar` o `revisar` → muestra resultado, operador decide manualmente.  
- Guarda `ia_decision` + `ia_resultado` (JSON) en tabla `AnulacionPedidosHost`.

---

### 2. ⚙️ Gestión de Anulaciones — Auto-Batch
**Módulo:** `Sistemas`  
**Archivo:** `modulos/sistemas/ajax/anulaciones_ia_auto_batch.php`

**¿Qué hace?**  
Procesa en lote todas las solicitudes de anulación `Status=0` sin veredicto IA aún. Puede ser llamado por:
- El módulo `sync_anulacion_pedidos.php` (capa API) vía curl interno.
- El VBA de Access directamente vía proxy.
- Manualmente desde el ERP.

**Cascada de proveedores:**
```
google → openai → deepseek → mistral → cerebras → groq
```

**Autenticación:** Bearer token (`IA_BATCH_TOKEN`) o sesión PHP activa.

**Lógica de aprobación automática:**  
- `"aprobar"` → `Status=1`, `AprobadoPor='IA Automática'`, `FechaAprobacion=NOW()`  
- `"rechazar"` / `"revisar"` → queda `Status=0`, solo guarda veredicto para revisión humana.

---

### 3. 🧾 Reembolsos — OCR de Foto/PDF de Factura
**Módulo:** `Compras`  
**Archivo:** `modulos/compras/ajax/reembolsos_ia_procesar_foto.php`

**¿Qué hace?**  
El colaborador sube una foto o PDF de una factura/ticket. La IA extrae los datos (monto, fecha, proveedor, descripción) para pre-llenar el formulario de reembolso.

**Cascada de proveedores:**
```
PDF:    google  (único compatible con Files API)
Imagen: google → openai → groq
```

> ⚠️ **Advertencia:** Si el archivo es PDF y Google no está disponible, el proceso falla completamente. No hay fallback para PDFs en otros proveedores (Mistral soporta PDFs — candidato para agregar).

---

### 4. 🧾 Reembolsos — Procesamiento de Factura Existente
**Módulo:** `Compras`  
**Archivo:** `modulos/compras/ajax/reembolsos_ia_procesar_existente.php`

**¿Qué hace?**  
Similar al anterior, pero opera sobre facturas ya registradas en el sistema. La IA ayuda a estructurar y completar los datos del reembolso.

**Cascada de proveedores:**
```
google → openai → groq
```

**Modelos específicos por proveedor:**
- `openai` → `gpt-4o-mini`
- `groq` → `llama-3.2-90b-vision-preview`

---

### 5. 🏦 Contabilidad — Lectura de Vouchers de Depósito
**Módulo:** `Contabilidad`  
**Archivo:** `modulos/contabilidad/ajax/masivo_depositos_subir_voucher.php`

**¿Qué hace?**  
El contador sube imágenes de vouchers bancarios. La IA extrae número de depósito, banco, monto y fecha para conciliación contable masiva.

**Cascada de proveedores:**
```
google → openai → groq
```

---

### 6. 📊 Gerencia — Chat IA sobre Gráficos y KPIs
**Módulo:** `Gerencia`  
**Archivo:** `modulos/gerencia/ajax/ia_graficos_procesar_prompt.php`

**¿Qué hace?**  
Los gerentes pueden escribir un prompt en lenguaje natural sobre los dashboards activos (ventas, producción, etc.). La IA analiza los datos mostrados y genera interpretaciones y recomendaciones.

**Cascada de proveedores (8 opciones — mayor resiliencia):**
```
google → openai → deepseek → mistral → cerebras → openrouter → huggingface → groq
```

**Restricción:** Prompts limitados a 500 caracteres.

---

### 7. 🔧 Mantenimiento — Asistente IA de Formularios
**Módulo:** `Mantenimiento`  
**Archivo:** `modulos/mantenimiento/ajax/formulario_consulta_ia.php`

**¿Qué hace?**  
Asiste al técnico al llenar formularios de mantenimiento de equipos e infraestructura. Sugiere causas, soluciones y pasos basándose en el historial descrito.

**Cascada de proveedores:**
```
google → openai → deepseek → mistral → cerebras → openrouter → huggingface → groq
```

---

### 8. 🔧 Mantenimiento — Consulta IA de Historial
**Módulo:** `Mantenimiento`  
**Archivo:** `modulos/mantenimiento/ajax/historial_consulta_ia.php`

**¿Qué hace?**  
Permite consultas en lenguaje natural sobre el historial de mantenimiento de equipos específicos. La IA resume patrones, fallas recurrentes y tendencias.

**Cascada de proveedores:**
```
google → openai → deepseek → mistral → cerebras → openrouter → huggingface → groq
```

---

### 9. 👤 Reclutamiento — Validación Automática de CVs
**Módulo:** `Reclutamiento`  
**Archivo:** `modulos/reclutamiento/ajax/postulacion_cv_ia_validar.php`

**¿Qué hace?**  
Evalúa y puntúa CVs de postulantes automáticamente. Verifica compatibilidad con el perfil solicitado, extrae habilidades clave y genera un resumen del candidato.

**Cascada de proveedores:**
```
google → groq → deepseek
```

---

### 10. 👤 Reclutamiento — Procesamiento de CV/PDF desde Panel
**Módulo:** `Reclutamiento`  
**Archivo:** `modulos/reclutamiento/ajax/postulacion_panel_control_pdf_accion.php`

**¿Qué hace?**  
El reclutador sube manualmente un CV en PDF, Word o imagen desde el panel de control. La IA extrae y estructura los datos del candidato.

**Proveedor:**
```
google  (único — sin cascada)
```

> ⚠️ **Advertencia:** `'google'` hardcodeado. Si Google no está disponible, falla completamente. Pendiente agregar cascada.

---

## 🌐 Proyectos VPS Independientes (Python)

Estos proyectos corren en un VPS aparte y **no usan `AIService.php`**. Llaman directamente a la API REST de Google Gemini.

---

### 11. 📹 HikvisionAnalisisIA — Análisis de Video de Atención
**Proyecto:** `HikvisionAnalisisIA/` (Python — VPS `198.211.97.243`)  
**Archivo clave:** `src/analyzer.py`  
**Modelo:** `gemini-2.5-flash`

**¿Qué hace?**  
Descarga clips de video de las cámaras Hikvision de cada sucursal via túneles SSH. Sube el video a **Gemini Files API** (upload resumable) y analiza el cumplimiento del **Protocolo Oficial de Atención Pitaya** (10 pasos en 5 grupos):

| Grupo | Pasos | Descripción |
|-------|-------|-------------|
| 1 | Paso 1 | Bienvenida |
| 2 | Pasos 2-4 | Asesoría al cliente (\* opcionales en fila larga) |
| 3 | Paso 5 | Oferta de Membresía |
| 4 | Pasos 6-8 | Proceso de Cobro |
| 5 | Pasos 9-10 | Entrega del producto |

**Flujo:**
```
Cola de jobs (DB) → Descarga video (ffmpeg) → Upload Gemini Files API
→ Polling ACTIVE → generateContent → Resultado JSON → DB → Notificación
```

**Dónde se obtiene la key:**
```
api.batidospitaya.com/api/hikvision → DB ia_proveedores_api (proveedor='google')
```

> ⚠️ **Sin fallback.** Solo Gemini puede procesar video nativo. Si Google no está disponible, los análisis se acumulan en cola.

---

### 12. 🎙️ ResumenReunionesIA — Transcripción y Resumen de Reuniones
**Proyecto:** `ResumenReunionesIA/` (Python — VPS)  
**Archivo clave:** `app/gemini_client.py`  
**Modelo:** `gemini-2.5-pro`

**¿Qué hace?**  
Recibe grabaciones de audio de reuniones corporativas (webm/mp3/mp4/wav), las sube a **Gemini Files API** y genera:
1. **Resumen ejecutivo** estructurado en Markdown (Decisiones Tomadas, Tareas Asignadas, Acuerdos, Puntos de Seguimiento, Temas sin Resolución)
2. **Transcripción completa** de la reunión

**Tipos de reunión soportados:**

| Tipo | Prompt especializado |
|------|---------------------|
| `general` | Resumen estándar |
| `iterativa` | Narra evolución de temas (de X a Y) |
| `informativa` | Distingue decisiones previas vs. nuevas |
| `decision` | Identifica opciones evaluadas y argumento ganador |
| `brainstorming` | Agrupa ideas por tracción, sin forzar cierre |
| `nivel10` | Estructura EOS: Marcador, Titulares, Rocas, IDS, Pendientes |

**Flujo:**
```
Grabación audio (web) → Upload Gemini Files API → Polling ACTIVE
→ generateContent (2.5-pro, 16000 tokens) → Extracción XML tags
→ Resultado: {resultado_final, resumen, transcripcion} → DB ERP
```

**Dónde se obtiene la key:**
```
api.batidospitaya.com/api/resumen_reuniones_ia/obtener_key_gemini.php → DB ia_proveedores_api
```

> ⚠️ **Sin fallback.** El upload de audio nativo requiere Gemini Files API. No compatible con otros proveedores en su estado actual.

---

## ⚠️ Vulnerabilidades Conocidas y Pendientes

| # | Problema | Archivos afectados | Prioridad |
|---|----------|--------------------|-----------|
| 1 | Error HTTP 403 de Google no rota — lanza excepción fatal | `core/ai/AIService.php` L.336-337 | 🔴 Alta |
| 2 | PDFs solo procesables con Google (sin fallback) | `reembolsos_ia_procesar_foto.php`, `postulacion_panel_control_pdf_accion.php` | 🟡 Media |
| 3 | `postulacion_panel_control_pdf_accion.php` usa `'google'` hardcodeado sin cascada | `postulacion_panel_control_pdf_accion.php` | 🟡 Media |
| 4 | HikvisionAnalisisIA y ResumenReunionesIA sin proveedor alternativo para audio/video | `analyzer.py`, `gemini_client.py` | 🟡 Media |

### Fix recomendado para vulnerabilidad #1 (`AIService.php`)
```php
// Agregar ANTES del throw final en el bloque de manejo de errores (línea ~335):
if ($httpCode === 403) {
    $ultimoError = new Exception("Error 403 en {$this->proveedor} (billing/cuota agotada): {$errorMsg}");
    $this->marcarLlaveAgotada($idLlave);
    continue; // Rotar al siguiente proveedor en lugar de lanzar excepción
}
```

---

## 📌 Guía para Nuevas Implementaciones

### Cómo agregar IA a un nuevo módulo PHP

```php
<?php
require_once '../../../core/ai/AIService.php';

// Definir cascada de proveedores según necesidad
$proveedores = ['google', 'openai', 'deepseek', 'groq'];
$respuesta   = null;
$aiService   = null;

foreach ($proveedores as $prov) {
    try {
        $svc = new AIService($conn, $prov);
        $respuesta = $svc->procesarPrompt($systemPrompt, $userPrompt, 0.2);
        if ($respuesta) { $aiService = $svc; break; }
    } catch (Exception $e) {
        // Continuar al siguiente proveedor
        continue;
    }
}

if (!$respuesta) {
    throw new Exception('Ningún proveedor de IA disponible.');
}

// Si esperas JSON estructurado:
$datos = $aiService->extraerJSON($respuesta);
```

### Registrar una nueva API Key
```sql
INSERT INTO ia_proveedores_api (proveedor, api_key, activa, limite_alcanzado_hoy)
VALUES ('groq', 'gsk_TU_KEY_AQUI', 1, 0);
```

### Proveedores gratuitos recomendados para fallback
| Proveedor | Límite gratuito | Mejor para |
|-----------|-----------------|------------|
| **Groq** | ~14,400 req/día | Texto rápido, JSON estructurado |
| **OpenRouter** (free tier) | Varía por modelo | Texto general |
| **HuggingFace** | Limitado | Texto simple |
| **Cerebras** | Cuota generosa | Texto, respuestas rápidas |

---

*Documento generado automáticamente a partir del análisis del ecosistema — 2026-11-03*  
*Para cambios, editar este archivo y hacer commit al repositorio.*

