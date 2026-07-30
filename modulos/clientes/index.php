<?php
// index.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';
posRequiereColaborador();
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_sidebar.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/layout/pos_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/core/permissions/permissions.php';

$usuario = obtenerUsuarioActual();
$cargoOperario = $usuario['CodNivelesCargos'];

// Verificar acceso
if (!tienePermiso('clientes_club_pos', 'vista', $cargoOperario)) {
    header('Location: /login.php');
    exit();
}

$puedeEditar = tienePermiso('clientes_club_pos', 'edicion', $cargoOperario);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Clientes — POS</title>
    <link rel="icon" href="/assets/img/icon12.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/core/assets/css/global_tools.css?v=<?php echo time(); ?>">
    <style>
        .clientes-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid #E2E8F0;
            padding: 20px;
            margin-bottom: 20px;
        }

        .buscador-clientes {
            position: relative;
            max-width: 450px;
            margin-bottom: 20px;
        }

        .buscador-clientes input {
            width: 100%;
            padding: 10px 15px 10px 40px;
            border-radius: 8px;
            border: 1.5px solid #CBD5E1;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }

        .buscador-clientes input:focus {
            border-color: #51B8AC;
            box-shadow: 0 0 0 3px rgba(81, 184, 172, 0.15);
        }

        .buscador-clientes .icon-search {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 1.1rem;
        }

        .tabla-clientes {
            font-size: 0.9rem;
        }

        .tabla-clientes th {
            background-color: #0E544C !important;
            color: white !important;
            font-weight: 600;
            padding: 12px 16px;
            border: none;
        }

        .tabla-clientes td {
            padding: 12px 16px;
            vertical-align: middle;
            color: #334155;
            border-bottom: 1px solid #F1F5F9;
        }

        .tabla-clientes tr:hover td {
            background-color: #F8FAFC;
        }

        .btn-ver-perfil {
            background-color: #E2E8F0;
            color: #334155;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-ver-perfil:hover {
            background-color: #51B8AC;
            color: white;
        }

        .paginacion-container {
            display: flex;
            gap: 5px;
        }

        .paginacion-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #E2E8F0;
            background: white;
            color: #334155;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .paginacion-btn:hover:not(:disabled) {
            border-color: #51B8AC;
            color: #51B8AC;
        }

        .paginacion-btn.active {
            background-color: #51B8AC;
            border-color: #51B8AC;
            color: white;
        }

        .paginacion-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>

<body>
    <?= renderPOSSidebar('clientes') ?>

    <div class="pos-main-container">
        <?= renderPOSHeader('Buscador de Clientes') ?>

        <div class="pos-content">
            <div class="container-fluid p-3">
                <div class="clientes-card">
                    <!-- Buscador -->
                    <div class="buscador-clientes">
                        <i class="bi bi-search icon-search"></i>
                        <input type="text" id="buscarCliente" placeholder="Buscar por membresía, nombre, celular o cédula..." autocomplete="off">
                    </div>

                    <!-- Tabla -->
                    <div class="table-responsive">
                        <table class="table tabla-clientes" id="tablaClientes">
                            <thead>
                                <tr>
                                    <th>Membresía</th>
                                    <th>Nombre Completo</th>
                                    <th>Celular</th>
                                    <th>Cédula</th>
                                    <th>Sucursal Registro</th>
                                    <th>Fecha Registro</th>
                                    <th style="width: 120px;" class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaClientesBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <div class="spinner-border spinner-border-sm me-2"></div>
                                        Cargando clientes...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Paginación -->
                    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <label class="mb-0" style="font-size:.85rem; color: #64748B;">Mostrar:</label>
                            <select class="form-select form-select-sm" id="registrosPorPagina" style="width:auto;" onchange="cambiarRegistrosPorPagina()">
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            <span style="font-size:.85rem; color: #64748B;">registros</span>
                        </div>
                        <div id="paginacion" class="paginacion-container"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let paginaActual = 1;
        let registrosPorPagina = 25;
        let busqueda = '';
        let timerBusqueda = null;

        $(document).ready(function() {
            cargarClientes();

            $('#buscarCliente').on('input', function() {
                clearTimeout(timerBusqueda);
                busqueda = $(this).val();
                timerBusqueda = setTimeout(function() {
                    paginaActual = 1;
                    cargarClientes();
                }, 300);
            });
        });

        function cargarClientes() {
            const tbody = $('#tablaClientesBody');
            tbody.html(`
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <div class="spinner-border spinner-border-sm me-2" style="color: #51B8AC;"></div>
                        Buscando clientes...
                    </td>
                </tr>
            `);

            $.ajax({
                url: 'ajax/clientes_get_datos.php',
                method: 'POST',
                data: {
                    pagina: paginaActual,
                    registros_por_pagina: registrosPorPagina,
                    search: busqueda
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        renderTabla(response.datos);
                        renderPaginacion(response.total_registros);
                    } else {
                        tbody.html(`<tr><td colspan="7" class="text-center text-danger py-4">${response.message}</td></tr>`);
                    }
                },
                error: function() {
                    tbody.html('<tr><td colspan="7" class="text-center text-danger py-4">Error de conexión al cargar clientes.</td></tr>');
                }
            });
        }

        function renderTabla(datos) {
            const tbody = $('#tablaClientesBody');
            tbody.empty();

            if (datos.length === 0) {
                tbody.append('<tr><td colspan="7" class="text-center text-muted py-4">No se encontraron clientes.</td></tr>');
                return;
            }

            datos.forEach(row => {
                const nombreCompleto = (row.nombre || '') + ' ' + (row.apellido || '');
                const membresia = row.membresia || '-';
                const celular = row.celular || '-';
                const cedula = row.cedula || '-';
                const sucursal = row.nombre_sucursal || '-';
                const fecha = row.fecha_registro ? row.fecha_registro.substring(0, 10) : '-';

                tbody.append(`
                    <tr>
                        <td><strong>${membresia}</strong></td>
                        <td>${nombreCompleto}</td>
                        <td>${celular}</td>
                        <td>${cedula}</td>
                        <td>${sucursal}</td>
                        <td>${fecha}</td>
                        <td class="text-center">
                            <a href="cliente_gestion.php?membresia=${encodeURIComponent(membresia)}" class="btn-ver-perfil">
                                <i class="bi bi-person-gear"></i> Perfil
                            </a>
                        </td>
                    </tr>
                `);
            });
        }

        function renderPaginacion(totalRegistros) {
            const container = $('#paginacion');
            container.empty();

            const totalPaginas = Math.ceil(totalRegistros / registrosPorPagina);
            if (totalPaginas <= 1) return;

            // Botón Anterior
            const btnPrev = $('<button class="paginacion-btn">Anterior</button>');
            if (paginaActual === 1) btnPrev.prop('disabled', true);
            btnPrev.on('click', () => {
                paginaActual--;
                cargarClientes();
            });
            container.append(btnPrev);

            // Páginas numéricas
            let inicio = Math.max(1, paginaActual - 2);
            let fin = Math.min(totalPaginas, paginaActual + 2);

            for (let i = inicio; i <= fin; i++) {
                const btnPage = $(`<button class="paginacion-btn ${i === paginaActual ? 'active' : ''}">${i}</button>`);
                btnPage.on('click', () => {
                    paginaActual = i;
                    cargarClientes();
                });
                container.append(btnPage);
            }

            // Botón Siguiente
            const btnNext = $('<button class="paginacion-btn">Siguiente</button>');
            if (paginaActual === totalPaginas) btnNext.prop('disabled', true);
            btnNext.on('click', () => {
                paginaActual++;
                cargarClientes();
            });
            container.append(btnNext);
        }

        function cambiarRegistrosPorPagina() {
            registrosPorPagina = parseInt($('#registrosPorPagina').val());
            paginaActual = 1;
            cargarClientes();
        }
    </script>
</body>

</html>