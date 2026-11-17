/* ============================================================
   NUEVA MEMBRESÍA CLUB PITAYA — Registro rápido desde POS
   Archivo: modulos/pos/js/membresia_club.js
   Cargado después de facturas_nueva.js
   ============================================================ */

const ID_PRESENTACION_MEMBRESIA = 126;
const ID_CATALOGO_MEMBRESIA     = 13;

let _nmMembresiaVerificada = false;

$(document).ready(function () {

    /* ── Botón abrir modal ── */
    $(document).on('click', '#btnNuevaMembresia', _abrirModalNuevaMembresia);

    /* ── Verificar disponibilidad ── */
    $('#btnVerificarMembresia').on('click', _verificarMembresia);
    $('#nmMembresia').on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); _verificarMembresia(); }
    });

    /* Reset feedback al cambiar el número */
    $('#nmMembresia').on('input', function () {
        const v = $(this).val().replace(/\D/g, '');
        $(this).val(v);
        _nmMembresiaVerificada = false;
        $('#nmMembresiaFeedback').text('').removeClass('text-success text-danger');
        $('#btnRegistrarMembresia').prop('disabled', true);
    });

    /* Celular: solo 8 dígitos */
    $('#nmCelular').on('input', function () {
        const v = $(this).val().replace(/\D/g, '').substring(0, 8);
        $(this).val(v);
    });

    /* ── Registrar ── */
    $('#btnRegistrarMembresia').on('click', _registrarNuevaMembresia);

    /* ── Reset modal al cerrar ── */
    $('#modalNuevaMembresia').on('hidden.bs.modal', function () {
        $('#nmMembresia').val('');
        $('#nmCelular').val('');
        $('#nmMembresiaFeedback').text('').removeClass('text-success text-danger');
        $('#btnRegistrarMembresia').prop('disabled', true);
        _nmMembresiaVerificada = false;
    });
});

/* ──────────────────────────────────────────────────────────
   _abrirModalNuevaMembresia
────────────────────────────────────────────────────────── */
function _abrirModalNuevaMembresia() {
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNuevaMembresia'));
    modal.show();
    setTimeout(() => $('#nmMembresia').focus(), 320);
}

/* ──────────────────────────────────────────────────────────
   _verificarMembresia  — accion=verificar
────────────────────────────────────────────────────────── */
function _verificarMembresia() {
    const membresia = $('#nmMembresia').val().trim();
    if (!membresia || isNaN(membresia) || parseInt(membresia) <= 0) {
        $('#nmMembresiaFeedback')
            .text('Ingrese un número de membresía válido.')
            .removeClass('text-success').addClass('text-danger');
        return;
    }

    const $btn = $('#btnVerificarMembresia');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    $.post('/modulos/pos/ajax/registrar_membresia_club.php',
        { accion: 'verificar', membresia },
        function (res) {
            $btn.prop('disabled', false).html('<i class="bi bi-search"></i>');
            if (res && res.disponible) {
                _nmMembresiaVerificada = true;
                $('#nmMembresiaFeedback')
                    .html('<i class="bi bi-check-circle-fill me-1"></i>Membresía disponible ✓')
                    .removeClass('text-danger').addClass('text-success');
                $('#btnRegistrarMembresia').prop('disabled', false);
                setTimeout(() => $('#nmCelular').focus(), 100);
            } else {
                _nmMembresiaVerificada = false;
                const msg = (res && res.mensaje) ? res.mensaje : 'Esta membresía ya está registrada.';
                $('#nmMembresiaFeedback')
                    .html('<i class="bi bi-x-circle-fill me-1"></i>' + escHtml(msg))
                    .removeClass('text-success').addClass('text-danger');
                $('#btnRegistrarMembresia').prop('disabled', true);
            }
        }, 'json'
    ).fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-search"></i>');
        $('#nmMembresiaFeedback').text('Error de conexión. Intente nuevamente.').addClass('text-danger');
    });
}

/* ──────────────────────────────────────────────────────────
   _registrarNuevaMembresia  — accion=registrar
────────────────────────────────────────────────────────── */
function _registrarNuevaMembresia() {
    if (!_nmMembresiaVerificada) {
        Swal.fire({
            title: 'Verificación pendiente',
            text: 'Primero verifica que la membresía esté disponible.',
            icon: 'warning',
            confirmButtonColor: '#0E544C'
        });
        return;
    }

    const membresia = $('#nmMembresia').val().trim();
    const celular   = $('#nmCelular').val().replace(/\D/g, '').substring(0, 8);

    if (celular.length < 8) {
        Swal.fire({
            title: 'Celular requerido',
            text: 'Ingrese un número de celular de 8 dígitos.',
            icon: 'warning',
            confirmButtonColor: '#0E544C'
        });
        $('#nmCelular').focus();
        return;
    }

    const $btn = $('#btnRegistrarMembresia');
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Registrando...');

    $.post('/modulos/pos/ajax/registrar_membresia_club.php',
        { accion: 'registrar', membresia, celular },
        function (res) {
            if (res && res.success) {
                /* Cerrar modal */
                bootstrap.Modal.getInstance(document.getElementById('modalNuevaMembresia')).hide();

                /* Cambiar tipo de cliente a Club Pitaya */
                $('#tipoClienteClub').prop('checked', true);
                cambiarTipoCliente('club');

                /* Poner membresía en el campo y buscar el socio */
                $('#membresiaCliente').val(membresia);
                buscarClienteClub(false);

                /* Agregar línea membresía al carrito */
                _agregarItemMembresia(membresia, res.precio_membresia || 99);

                /* Notificar WSP (fire & forget) */
                $.post('/modulos/pos/ajax/registrar_membresia_club.php',
                    { accion: 'notificar_wsp', membresia, celular });

                Swal.fire({
                    title: '¡Membresía registrada!',
                    text: `Membresía #${membresia} creada y agregada a la factura.`,
                    icon: 'success',
                    confirmButtonColor: '#0E544C',
                    timer: 2500,
                    showConfirmButton: false
                });
            } else {
                const msg = (res && res.mensaje) ? res.mensaje : 'Error al registrar.';
                $btn.prop('disabled', false)
                    .html('<i class="bi bi-check2-circle me-1"></i> Registrar y Agregar');
                Swal.fire({ title: 'Error', text: msg, icon: 'error', confirmButtonColor: '#0E544C' });
            }
        }, 'json'
    ).fail(function () {
        $btn.prop('disabled', false)
            .html('<i class="bi bi-check2-circle me-1"></i> Registrar y Agregar');
        Swal.fire({ title: 'Error de conexión', icon: 'error', confirmButtonColor: '#0E544C' });
    });
}

/* ──────────────────────────────────────────────────────────
   _agregarItemMembresia  — agrega la línea al carrito
────────────────────────────────────────────────────────── */
function _agregarItemMembresia(membresia, precio) {
    /* Eliminar si ya había una membresía previa en carrito */
    detalleFactura = detalleFactura.filter(function (d) { return !d.es_membresia; });

    const rowKey = ++_rowKeyCounter;
    detalleFactura.unshift({
        _rowKey:              rowKey,
        id_presentacion:      ID_PRESENTACION_MEMBRESIA,
        id_catalogo_menu_pos: ID_CATALOGO_MEMBRESIA,
        nombre:               'Membresía Club Pitaya',
        precio_unitario_base: precio,
        precio_unitario:      precio,
        cantidad:             1,
        costo_total_iva:      precio,
        notas:                String(membresia),
        membresia_venta:      parseInt(membresia),
        es_membresia:         true,
        bloquea_cantidad:     true,
        es_canjeado:          false,
        es_canje_puntos:      0,
        bloquea_puntos:       1,
        puntos_acumulacion:   0,
        puntos_canje:         null,
        es_personalizable:    false,
        descuento_promo:      0,
        promos_en_item:       [],
        es_condicion_promo:   false,
        qty_condicion:        0,
        personalizacion: {
            ingredientes_quitados:    [],
            ingredientes_sustituidos: [],
            endulzante_id:            null,
            endulzante_nombre:        '',
            extras_agregados:         [],
            monto_extras:             0,
            notas:                    ''
        }
    });

    actualizarTablaDetalle();
    actualizarTotal(true);
}

/* ──────────────────────────────────────────────────────────
   _eliminarItemMembresia  — confirmación + rollback BD
────────────────────────────────────────────────────────── */
function _eliminarItemMembresia(idx) {
    const item = detalleFactura[idx];
    if (!item || !item.es_membresia) return;

    const membresia = item.membresia_venta || item.notas;

    Swal.fire({
        title: '¿Eliminar membresía?',
        html: `Se eliminará la membresía <strong>#${membresia}</strong> de la factura<br>
               y se <strong>cancelará el registro</strong> del socio en el sistema.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor:  '#6c757d',
        confirmButtonText:  '<i class="bi bi-trash3 me-1"></i>Sí, eliminar',
        cancelButtonText:   'No, conservar'
    }).then(function (result) {
        if (!result.isConfirmed) return;

        $.post('/modulos/pos/ajax/registrar_membresia_club.php',
            { accion: 'eliminar', membresia },
            function () {
                /* Quitar del carrito */
                detalleFactura.splice(idx, 1);
                actualizarTablaDetalle();
                actualizarTotal(true);

                /* Si el campo de membresía coincide, resetear tipo de cliente */
                const membresiaActual = $('#membresiaCliente').val().trim();
                if (String(membresia) === membresiaActual) {
                    clienteActual = null;
                    $('#tipoClienteGeneral').prop('checked', true);
                    cambiarTipoCliente('general');
                }
            }, 'json'
        ).fail(function () {
            /* Aunque falle el backend, eliminamos del carrito igualmente */
            detalleFactura.splice(idx, 1);
            actualizarTablaDetalle();
            actualizarTotal(true);
        });
    });
}
