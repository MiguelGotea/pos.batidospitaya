# 🚀 POS Batidos Pitaya

Repositorio del sistema de **Punto de Venta (POS)** para Batidos Pitaya.

## 📦 Estructura del Proyecto

- `modulos/`: Contiene los módulos del sistema POS.
- `.github/workflows/`: Workflows de GitHub Actions para deploy automático.
- `.scripts/`: Scripts auxiliares de PowerShell.
- `docs/`: Documentación técnica y de infraestructura (sincronizada desde PitayaCore).
- `core/`: Lógica global compartida (sincronizada desde PitayaCore).

## 🔄 Sincronización con PitayaCore

Este repositorio forma parte del ecosistema **Mano de Hierro (Iron Sync v13.1)**.  
Las carpetas `core/`, `docs/` y `.agent/` son gestionadas centralmente por `PitayaCore`  
y se inyectan automáticamente en este repositorio en cada push al maestro.

## 🚀 Deploy Automático

### Gestión de Archivos (Estandarización)

| Carpeta/Archivo | Subir a GitHub | Subir al Host |
| :--- | :---: | :---: |
| `.scripts/` | ✅ Sí | ❌ No |
| `.github/`, `.gitignore` | ✅ Sí | ❌ No |
| `modulos/` (lógica) | ✅ Sí | ✅ Sí |
| `core/`, `docs/` | ✅ Sí | ✅ Sí |
| `modulos/*/uploads/` | ❌ No | ❌ No |
| `.agent/` | ✅ Sí | ❌ No |

- 🔧 Permisos automáticos aplicados en cada deploy: 755 para carpetas y 644 para archivos.
- 📁 Las carpetas `uploads` dentro de cada módulo se crean automáticamente si no existen.

### Documentación de Deploy

Toda la información sobre el sistema de deploy se encuentra en la carpeta `docs/`:

1. [**Guía de Configuración General**](docs/DEPLOY_SETUP.md)
2. [**Implementar Nuevo Dominio**](docs/DEPLOY_NEW_DOMAIN.md)
3. [**Solución de Problemas (Troubleshooting)**](docs/TROUBLESHOOTING.md)

---

## 🛠️ Desarrollo Local

Para trabajar en este proyecto localmente, asegúrate de tener configurado tu entorno de PHP y Visual Studio Code.

### Scripts de Ayuda

Usa los scripts en `.scripts/` para agilizar tus commits y pushes:
- `.\\.scripts\\gitpush.ps1`: Sube todos los cambios y activa el deploy.

---

## 🔑 Secrets Requeridos en GitHub

| Secret | Valor |
|--------|-------|
| `HOSTINGER_SSH_KEY` | Clave privada compartida (`batidospitaya-deploy`) |
| `HOSTINGER_USER` | `u839374897` |
| `HOSTINGER_HOST` | `145.223.105.42` |
| `HOSTINGER_PATH_POS` | `/home/u839374897/domains/pos.batidospitaya.com/public_html` |
| `SYNC_TOKEN` | Token de acceso a GitHub (compartido con los otros repos) |

---

**Última actualización:** 2026-03-31
