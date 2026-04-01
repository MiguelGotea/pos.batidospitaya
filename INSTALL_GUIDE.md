# 🚀 Guía de Instalación: pos.batidospitaya en Nueva Máquina

Esta guía detalla los pasos para configurar el nuevo repositorio y el ecosistema completo en una computadora nueva con una cuenta de GitHub distinta.

---

## 0. Instalación de Git (Windows)

Si la computadora es nueva, primero debes instalar Git:

1. **Descargar**: Ve a [git-scm.com](https://git-scm.com/download/win) y descarga el instalador para Windows de 64 bits.
2. **Instalación**: Ejecuta el instalador. Puedes dejar la mayoría de las opciones por defecto, pero asegúrate de seleccionar:
   - **Editor**: Selecciona "Visual Studio Code as Git's default editor" si planeas usarlo.
   - **Branch Name**: Deja "Let Git decide" (usará `master`) o cámbialo a `main` si prefieres el estándar moderno.
   - **PATH**: Selecciona "Git from the command line and also from 3rd-party software" (Recomendado).
   - **SSH**: Usa "OpenSSH".
3. **Verificación**: Abre una terminal (PowerShell o CMD) y escribe:
   ```powershell
   git --version
   ```
   Debería mostrar algo como `git version 2.x.x.windows.x`.

## 1. Configuración de Identidad (Nueva Máquina)

En la terminal de la nueva computadora, configura tu identidad de Git:

```powershell
git config --global user.name "Tu Nombre"
git config --global user.email "tu-nuevo-email@ejemplo.com"
```

## 2. Acceso y Permisos (GitHub)

Para que la nueva cuenta pueda hacer `push`, el dueño del repositorio original (`MiguelGotea`) debe invitarte como colaborador:

1. El dueño debe ir a: `https://github.com/MiguelGotea/pos.batidospitaya/settings/access`
2. Hacer clic en **"Add people"** e invitar a tu nueva cuenta.
3. **Repetir este paso** para el repositorio `PitayaCore` (y cualquier otro subdominio que necesites).
4. **IMPORTANTE**: Revisa tu correo o notificaciones de GitHub para **Aceptar la invitación**.

## 3. Autenticación SSH (Recomendado)

Genera una nueva llave SSH para tu máquina:

```powershell
ssh-keygen -t ed25519 -C "tu-nuevo-email@ejemplo.com"
# Presiona Enter a todo
cat ~/.ssh/id_ed25519.pub
```
Copia el contenido y agrégalo a tu perfil de GitHub: `Settings > SSH and GPG keys > New SSH key`.

## 4. Instalación del Repositorio Local

Crea la carpeta de tu proyecto y clona **únicamente** el repositorio de POS:

1. Crea una carpeta raíz, por ejemplo: `C:\Proyectos\VisualCode\`
2. Clona el repositorio de POS:
   ```powershell
   cd C:\Proyectos\VisualCode\
   git clone git@github.com:MiguelGotea/pos.batidospitaya.git
   ```

> [!TIP]
> **¿Por qué no necesito PitayaCore?**
> A diferencia de otras configuraciones, en esta PC no necesitas clonar el repositorio maestro. Gracias al sistema **Mano de Hierro**, cuando subas cambios en la carpeta `core/` desde tu repositorio de POS, GitHub se encargará automáticamente de actualizar el Maestro y repartir esos cambios a todos los demás subdominios.

## 5. Configuración de Secrets en GitHub (Crítico)

Para que el deploy automático funcione, el repositorio debe tener configurados los **Secrets** en `Settings > Secrets and variables > Actions`.

> [!NOTE]
> **¿Ya ves los secrets en la lista?**
> Si al entrar con tu nueva cuenta ya ves los nombres de los secrets (como `HOSTINGER_SSH_KEY`), significa que ya han sido configurados por el administrador. **¡Esto es perfecto!** No necesitas hacer nada más.
> 
> - **¿Son públicos?** No. Aunque veas el nombre, GitHub cifra los valores y nadie puede verlos (ni siquiera tú ni el dueño). Solo los servidores de GitHub los usan durante el deploy.
> - **¿Tengo que cambiarlos?** No. Deja los que están, ya que son los autorizados para conectar con Hostinger.

Si hace falta alguno o deseas crearlos de cero, estos son los requeridos:

| Nombre de la Variable | Valor |
| :--- | :--- |
| `HOSTINGER_SSH_KEY` | La clave privada compartida (obtener de `cat ~/.ssh/batidospitaya-deploy` en el host) |
| `HOSTINGER_USER` | `u839374897` |
| `HOSTINGER_HOST` | `145.223.105.42` |
| `HOSTINGER_PATH_POS` | `/home/u839374897/domains/pos.batidospitaya.com/public_html` |
| `SYNC_TOKEN` | Un Personal Access Token (PAT) con permisos de `repo` |

## 6. Flujo de Trabajo y Sincronización Automática

Una vez configurado, el sistema funcionará de la siguiente manera:

1. **Desarrollo**: Trabajas normalmente en tu repositorio `pos.batidospitaya`.
2. **Push de Cambios**: Ejecutas `.\.scripts\gitpush.ps1`.
3. **Actualización del Core (Automática)**: 
   - Si GitHub detecta cambios en las carpetas `core/`, `docs/` o `.agent/`, activará el workflow **"Propose Core Update"**.
   - Este proceso clonará internamente `PitayaCore` en los servidores de GitHub, le inyectará tus cambios y hará un push al Maestro.
4. **Propagación Global (Automática)**: `PitayaCore` detectará el nuevo cambio y lo enviará automáticamente a los repositorios de ERP, API y Talento.
5. **Recibir Cambios**: Para bajar actualizaciones que otros hayan hecho al core, simplemente usa un `git pull` o ejecuta tu script de push, el cual siempre intenta sincronizarse antes de subir.

## 🛡️ Sistema "Anti-Choque" de Sincronización

¿Qué pasa si cambias algo en la PC principal y al mismo tiempo en la nueva cuenta? **Nada malo.**

El script `gitpush.ps1` (Tanque v7) está diseñado para evitar sobreescrituras:
- **`git pull --rebase`**: Antes de subir, el script descarga e integra los cambios del servidor.
- **Auto-Stack**: Tus cambios locales se "apilan" ordenadamente después de los cambios descargados.
- **Cero Pérdida**: Nunca se borrará el trabajo de una cuenta por la acción de la otra; el sistema fusiona ambos proyectos de forma inteligente.

> [!IMPORTANT]
> Para que este flujo funcione, el **SYNC_TOKEN** que configures en los Secrets debe tener permisos de escritura en los repositorios `pos.batidospitaya` y `PitayaCore`.
