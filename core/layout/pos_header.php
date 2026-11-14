<?php
/**
 * POS Header Component
 * Modular layout based on ERP design
 */

function renderPOSHeader($titulo = '') {
    $colaboradorNombre = $_SESSION['pos_colaborador_nombre'] ?? 'Operador';
    $sucursalNombre = $_SESSION['pos_store_name'] ?? 'Sucursal';
    $colaboradorId = $_SESSION['pos_colaborador_id'] ?? null;
    $cargoNombre = $_SESSION['pos_colaborador_cargo'] ?? 'Colaborador';
    
    // Intentar obtener datos extra si no están en sesión
    if ($colaboradorId && !isset($_SESSION['pos_usuario_completo'])) {
        $usuario = obtenerUsuarioActual();
        $_SESSION['pos_usuario_completo'] = $usuario;
    } else {
        $usuario = $_SESSION['pos_usuario_completo'] ?? null;
    }

    $fotoUrl = '';
    if ($usuario && !empty($usuario['foto_perfil'])) {
        // Las fotos están en el dominio del ERP.
        // Si el campo ya trae la ruta completa desde la raíz (ej: uploads/fotos_perfil/489/img.png)
        $rutaLimpia = ltrim($usuario['foto_perfil'], './');
        // Cache-buster por colaborador: el navegador cachea la foto por usuario
        // (mismo ID = usa caché), pero al cambiar de usuario la URL cambia
        // y descarga la foto correcta sin tener que borrar cookies.
        $fotoUrl = "https://erp.batidospitaya.com/" . $rutaLimpia . "?v=" . $colaboradorId;
    }

    ob_start();
    ?>
    <style>
        .pos-main-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 24px;
            background: #FFFFFF;
            border-bottom: 2px solid #E2E8F0;
            position: sticky;
            top: 0;
            z-index: 900;
            margin-bottom: 25px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .pos-header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .pos-header-title {
            color: #0E544C;
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0;
        }

        .pos-header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .pos-user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-right: 15px;
            border-right: 1px solid #E2E8F0;
        }

        .pos-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #51B8AC;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(81, 184, 172, 0.2);
        }

        .pos-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .pos-user-info {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .pos-user-name {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1E293B;
        }

        .pos-user-branch {
            font-size: 0.75rem;
            color: #64748B;
        }

        .pos-logout-btn {
            color: #64748B;
            font-size: 1.1rem;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 35px;
            height: 35px;
            border-radius: 8px;
        }

        .pos-logout-btn:hover {
            color: #E11D48;
            background: #FFF1F2;
        }
    </style>

    <header class="pos-main-header">
        <div class="pos-header-left">
            <h1 class="pos-header-title"><?= htmlspecialchars($titulo ?: 'Panel de Control') ?></h1>
        </div>
        
        <div class="pos-header-right">
            <div class="pos-user-profile">
                <div class="pos-user-info">
                    <span class="pos-user-name"><?= htmlspecialchars($colaboradorNombre) ?></span>
                    <span class="pos-user-branch"><?= htmlspecialchars($sucursalNombre) ?></span>
                </div>
                <div class="pos-avatar">
                    <?php if ($fotoUrl): ?>
                        <img src="<?= $fotoUrl ?>" alt="Avatar" onerror="this.parentElement.innerHTML='<?= strtoupper(substr($colaboradorNombre, 0, 1)) ?>'">
                    <?php else: ?>
                        <?= strtoupper(substr($colaboradorNombre, 0, 1)) ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <a href="/logout.php?type=colaborador" class="pos-logout-btn" title="Cerrar Sesión">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </header>

    <script>
    (function(w) {
        // Estandarización de formato de Fechas y Horas en todo el POS Batidos Pitaya
        // Formato de Fecha: 29-Ago-26
        // Formato de Hora:  06:23 pm
        const MESES_POS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        function parseFechaHoraLocal(str) {
            if (!str) return null;
            if (str instanceof Date) {
                return isNaN(str.getTime()) ? null : str;
            }
            str = String(str).trim();
            if (!str || str === '0000-00-00' || str === '0000-00-00 00:00:00') return null;

            // Formato "YYYY-MM-DD" o "YYYY-MM-DD HH:mm:ss"
            const m = str.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T\s](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?/);
            if (m) {
                const anio = parseInt(m[1], 10);
                const mes = parseInt(m[2], 10) - 1;
                const dia = parseInt(m[3], 10);
                const hora = m[4] !== undefined ? parseInt(m[4], 10) : 0;
                const min = m[5] !== undefined ? parseInt(m[5], 10) : 0;
                const seg = m[6] !== undefined ? parseInt(m[6], 10) : 0;
                return new Date(anio, mes, dia, hora, min, seg);
            }

            // Formato "HH:mm" o "HH:mm:ss"
            const mH = str.match(/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/);
            if (mH) {
                const d = new Date();
                d.setHours(parseInt(mH[1], 10), parseInt(mH[2], 10), mH[3] ? parseInt(mH[3], 10) : 0, 0);
                return d;
            }

            const d = new Date(str.replace(' ', 'T'));
            return isNaN(d.getTime()) ? null : d;
        }

        function formatoFechaPOS(str) {
            const d = parseFechaHoraLocal(str);
            if (!d) return str || '';
            const dia = String(d.getDate()).padStart(2, '0');
            const mes = MESES_POS[d.getMonth()] || '';
            const anio = String(d.getFullYear()).slice(-2);
            return `${dia}-${mes}-${anio}`;
        }

        function formatoHoraPOS(str) {
            const d = parseFechaHoraLocal(str);
            if (!d) return str || '';
            let horas = d.getHours();
            const min = String(d.getMinutes()).padStart(2, '0');
            const ampm = horas >= 12 ? 'pm' : 'am';
            horas = horas % 12 || 12;
            return String(horas).padStart(2, '0') + ':' + min + ' ' + ampm;
        }

        function formatoFechaHoraPOS(str) {
            if (!str) return '';
            const d = parseFechaHoraLocal(str);
            if (!d) return str;
            const f = formatoFechaPOS(d);
            const h = formatoHoraPOS(d);
            return `${f} ${h}`;
        }

        function separarFechaHoraPOS(str) {
            if (!str) return { fecha: '—', hora: '—' };
            const d = parseFechaHoraLocal(str);
            if (!d) return { fecha: '—', hora: '—' };
            return {
                fecha: formatoFechaPOS(d),
                hora: formatoHoraPOS(d)
            };
        }

        w.parseFechaHoraLocalPOS = parseFechaHoraLocal;
        w.formatoFechaPOS = formatoFechaPOS;
        w.formatoHoraPOS = formatoHoraPOS;
        w.formatoFechaHoraPOS = formatoFechaHoraPOS;
        w.separarFechaHoraPOS = separarFechaHoraPOS;
    })(window);

    (function() {
        // Heartbeat Keep-Alive del POS: mantiene la sesión del cajero activa en segundo plano
        const PING_INTERVAL_MS = 3 * 60 * 1000; // cada 3 minutos
        let lastPing = Date.now();

        function ejecutarPingPOS() {
            fetch('/core/auth/ping.php', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(res => {
                lastPing = Date.now();
            })
            .catch(err => {
                console.debug('[POS Heartbeat] Fallo temporal:', err);
            });
        }

        setInterval(ejecutarPingPOS, PING_INTERVAL_MS);

        // Si la pestaña vuelve a primer plano tras más de 60s, renovar de inmediato
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible' && (Date.now() - lastPing) > 60000) {
                ejecutarPingPOS();
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
