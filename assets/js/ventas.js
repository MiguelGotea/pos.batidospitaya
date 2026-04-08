// Función para inicializar todos los eventos
function inicializarEventos() {
    // Evento para cambiar visibilidad de campos según tipo de servicio
    $('#tipo_servicio').change(function() {
        const tipo = $(this).val();
        $('#delivery-fields, #retiro-fields').addClass('hidden');
        
        if (tipo === 'delivery') {
            $('#delivery-fields').removeClass('hidden');
        } else if (tipo === 'retiro_local') {
            $('#retiro-fields').removeClass('hidden');
        }
        
        calcularTotales();
    });
    
    // Evento para cambiar visibilidad de campos según tipo de pago
    $('#tipo_pago').change(function() {
        const tipo = $(this).val();
        $('#efectivo-fields').addClass('hidden');
        
        if (tipo === 'efectivo' || tipo === 'mixto') {
            $('#efectivo-fields').removeClass('hidden');
        }
    });
    
    // Evento para autocompletar cliente por teléfono
    $('#telefono_cliente').on('input', function() {
        const telefono = $(this).val().trim();
        
        if (telefono.length >= 3) {
            buscarClientes(telefono);
        } else {
            $('#sugerencias-clientes').empty();
        }
    });
    
    // Evento para agregar producto desde los botones
    $('.producto-btn').click(function() {
        agregarProducto(
            $(this).data('producto-id'),
            $(this).data('producto-nombre'),
            $(this).data('precio-16oz'),
            $(this).data('precio-20oz'),
            $(this).data('precio-fijo'),
            $(this).data('tiene-tamanos')
        );
    });
    
    // Evento delegado para botones de cantidad
    $('#tabla-productos').on('click', '.btn-cantidad-sumar', function() {
        const input = $(this).siblings('.cantidad-input');
        input.val(parseInt(input.val()) + 1);
        actualizarSubtotal($(this).closest('tr'));
        calcularTotales();
    });
    
    $('#tabla-productos').on('click', '.btn-cantidad-restar', function() {
        const input = $(this).siblings('.cantidad-input');
        const nuevaCantidad = parseInt(input.val()) - 1;
        if (nuevaCantidad >= 1) {
            input.val(nuevaCantidad);
            actualizarSubtotal($(this).closest('tr'));
            calcularTotales();
        }
    });
    
    // Evento delegado para cambio de tamaño
    $('#tabla-productos').on('change', '.tamano-select', function() {
        const fila = $(this).closest('tr');
        const productoId = fila.data('producto-id');
        const tamano = $(this).val();
        
        // Buscar el producto para obtener precios
        const producto = productos.find(p => p.id == productoId);
        
        if (producto) {
            let precio = 0;
            
            if (producto.tiene_tamanos) {
                precio = tamano === '16oz' ? producto.precio_16oz : producto.precio_20oz;
            } else {
                precio = producto.precio_fijo;
            }
            
            fila.find('.precio-input').val(precio.toFixed(2));
            actualizarSubtotal(fila);
            calcularTotales();
        }
    });
    
    // Evento delegado para eliminar producto
    $('#tabla-productos').on('click', '.btn-eliminar-producto', function() {
        $(this).closest('tr').remove();
        calcularTotales();
    });
    
    // Evento delegado para agregar extras
    $('#tabla-productos').on('click', '.btn-agregar-extra', function() {
        const filaProducto = $(this).closest('tr');
        const productoId = filaProducto.data('producto-id');
        
        // Crear fila de extra
        const filaExtra = $(`
            <tr class="endulzante-extra" data-producto-id="${productoId}">
                <td colspan="2">
                    <select name="extra_id[]" class="extra-select">
                        <option value="">Seleccione extra</option>
                        ${generarOpcionesExtras()}
                    </select>
                </td>
                <td>
                    <input type="number" name="extra_cantidad[]" value="1" min="1" style="width: 40px;">
                </td>
                <td>
                    <input type="number" name="extra_precio[]" class="extra-precio" value="0.00" step="0.01" readonly>
                </td>
                <td colspan="3">
                    <input type="text" name="extra_notas[]" placeholder="Notas para el extra">
                </td>
                <td class="acciones-td">
                    <button type="button" class="btn-icon btn-eliminar-extra">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `);
        
        // Insertar después de la fila del producto
        filaExtra.insertAfter(filaProducto);
        
        // Evento para cambio de extra
        filaExtra.find('.extra-select').change(function() {
            const extraId = $(this).val();
            const extra = extras.find(e => e.id == extraId);
            
            if (extra) {
                $(this).closest('tr').find('.extra-precio').val(extra.precio.toFixed(2));
            } else {
                $(this).closest('tr').find('.extra-precio').val('0.00');
            }
            
            calcularTotales();
        });
    });
    
    // Evento delegado para eliminar extra
    $('#tabla-productos').on('click', '.btn-eliminar-extra', function() {
        $(this).closest('tr').remove();
        calcularTotales();
    });
    
    // Eventos para cálculo de pago
    $('#pago_recibido_cordobas, #pago_recibido_dolares').on('input', function() {
        calcularCambio();
    });
    
    // Evento para guardar pedido
    $('#form-pedido').submit(function(e) {
        e.preventDefault();
        
        // Validar que haya al menos un producto
        if ($('#tabla-productos tbody tr[data-producto-id]').length === 0) {
            alert('Debe agregar al menos un producto al pedido');
            return;
        }
        
        // Enviar formulario por AJAX
        $.ajax({
            url: $(this).attr('action'),
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('Pedido guardado correctamente');
                    window.location.href = 'index.php';
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function() {
                alert('Error al procesar el pedido');
            }
        });
    });
    
    // Evento para botón de imprimir
    $('#btn-imprimir').click(function() {
        if ($('#tabla-productos tbody tr[data-producto-id]').length === 0) {
            alert('Debe agregar al menos un producto al pedido');
            return;
        }
        
        // Mostrar opciones de impresión
        const opcion = prompt('Seleccione tipo de impresión:\n1. Cliente\n2. Sucursal\n3. Motorizado');
        
        if (opcion) {
            // Guardar primero el pedido si es nuevo
            if (!$('input[name="pedido_id"]').val()) {
                $('#form-pedido').off('submit').submit();
            } else {
                // Redirigir a imprimir
                window.open(`imprimir_pedido.php?id=${$('input[name="pedido_id"]').val()}&tipo=${opcion}`, '_blank');
            }
        }
    });
    
    // Evento para botón de cancelar
    $('#btn-cancelar').click(function() {
        if (confirm('¿Está seguro de cancelar este pedido? Los cambios no se guardarán.')) {
            window.location.href = 'index.php';
        }
    });
}

// Función para buscar clientes por teléfono
function buscarClientes(telefono) {
    $.get('obtener_clientes.php', { telefono: telefono }, function(data) {
        $('#sugerencias-clientes').empty();
        
        if (data.length > 0) {
            data.forEach(cliente => {
                $('#sugerencias-clientes').append(`
                    <div class="sugerencia" 
                         data-cliente-id="${cliente.id}"
                         data-nombre="${cliente.nombre}"
                         data-direccion="${cliente.direccion}">
                        ${cliente.nombre} - ${cliente.telefono}
                    </div>
                `);
            });
            
            // Evento para seleccionar cliente
            $('.sugerencia').click(function() {
                $('#telefono_cliente').val($(this).text().split(' - ')[1]);
                $('#nombre_cliente').val($(this).data('nombre'));
                $('#direccion_cliente').val($(this).data('direccion'));
                $('#miembro_club').prop('checked', true);
                $('#sugerencias-clientes').empty();
            });
        }
    }, 'json');
}

// Función para agregar producto a la tabla
function agregarProducto(id, nombre, precio16oz, precio20oz, precioFijo, tieneTamanos) {
    // Verificar si el producto ya está en la tabla
    const existe = $(`#tabla-productos tr[data-producto-id="${id}"]`).length > 0;
    
    if (existe) {
        // Si ya existe, aumentar la cantidad
        const fila = $(`#tabla-productos tr[data-producto-id="${id}"]`).first();
        const inputCantidad = fila.find('.cantidad-input');
        inputCantidad.val(parseInt(inputCantidad.val()) + 1);
        actualizarSubtotal(fila);
    } else {
        // Si no existe, agregar nueva fila
        let selectTamano = '';
        let precio = 0;
        
        if (tieneTamanos) {
            selectTamano = `
                <select name="tamano[]" class="tamano-select">
                    <option value="16oz">16oz</option>
                    <option value="20oz" selected>20oz</option>
                </select>
            `;
            precio = precio20oz;
        } else {
            selectTamano = '<input type="hidden" name="tamano[]" value="unico">Único';
            precio = precioFijo;
        }
        
        const fila = $(`
            <tr data-producto-id="${id}">
                <td>
                    <input type="text" class="producto-nombre" value="${nombre}" readonly>
                    <input type="hidden" name="producto_id[]" value="${id}">
                </td>
                <td>${selectTamano}</td>
                <td>
                    <button type="button" class="btn-icon btn-cantidad-restar">-</button>
                    <input type="number" name="cantidad[]" class="cantidad-input" value="1" min="1" style="width: 40px;">
                    <button type="button" class="btn-icon btn-cantidad-sumar">+</button>
                </td>
                <td>
                    <input type="number" name="precio_unitario[]" class="precio-input" 
                           value="${precio.toFixed(2)}" step="0.01" min="0" readonly>
                </td>
                <td>
                    <select name="endulzante_tipo[]" class="endulzante-select">
                        <option value="">Seleccione</option>
                        ${generarOpcionesEndulzantes()}
                    </select>
                </td>
                <td>
                    <input type="text" name="promocion[]" class="promocion-input">
                </td>
                <td>
                    <button type="button" class="btn-icon btn-agregar-extra">
                        <i class="fas fa-plus"></i>
                    </button>
                </td>
                <td>
                    <input type="text" name="notas[]" class="notas-input">
                </td>
                <td class="acciones-td">
                    <button type="button" class="btn-icon btn-eliminar-producto">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `);
        
        $('#tabla-productos tbody').append(fila);
    }
    
    calcularTotales();
}

// Función para generar opciones de endulzantes
function generarOpcionesEndulzantes() {
    let options = '';
    endulzantes.forEach(endulzante => {
        options += `<option value="${endulzante.id}">${endulzante.nombre}</option>`;
    });
    return options;
}

// Función para generar opciones de extras
function generarOpcionesExtras() {
    let options = '';
    extras.forEach(extra => {
        options += `<option value="${extra.id}" data-precio="${extra.precio}">${extra.nombre} (C$${extra.precio})</option>`;
    });
    return options;
}

// Función para actualizar subtotal de una fila
function actualizarSubtotal(fila) {
    const cantidad = parseInt(fila.find('.cantidad-input').val());
    const precio = parseFloat(fila.find('.precio-input').val());
    const subtotal = cantidad * precio;
    
    // Actualizar subtotal en alguna parte si es necesario
}

// Función para calcular totales del pedido
function calcularTotales() {
    let subtotal = 0;
    
    // Sumar productos
    $('#tabla-productos tbody tr[data-producto-id]').each(function() {
        const cantidad = parseInt($(this).find('.cantidad-input').val());
        const precio = parseFloat($(this).find('.precio-input').val());
        subtotal += cantidad * precio;
    });
    
    // Sumar extras
    $('#tabla-productos tbody tr.endulzante-extra').each(function() {
        const precio = parseFloat($(this).find('.extra-precio').val()) || 0;
        subtotal += precio;
    });
    
    // Calcular cargo delivery
    const cargoDelivery = parseFloat($('#cargo_delivery').val()) || 0;
    const total = subtotal + cargoDelivery;
    
    // Actualizar en pantalla
    $('#subtotal').text(`C$ ${subtotal.toFixed(2)}`);
    $('#cargo-delivery-total').text(`C$ ${cargoDelivery.toFixed(2)}`);
    $('#total').text(`C$ ${total.toFixed(2)}`);
    $('#total-dolares').text(`$ ${(total / tipoCambio).toFixed(2)}`);
    
    // Calcular cambio si es pago en efectivo
    if ($('#tipo_pago').val() === 'efectivo' || $('#tipo_pago').val() === 'mixto') {
        calcularCambio();
    }
}

// Función para calcular el cambio
function calcularCambio() {
    const total = parseFloat($('#total').text().replace('C$ ', '')) || 0;
    const pagoCordobas = parseFloat($('#pago_recibido_cordobas').val()) || 0;
    const pagoDolares = parseFloat($('#pago_recibido_dolares').val()) || 0;
    const pagoTotal = pagoCordobas + (pagoDolares * tipoCambio);
    const cambio = pagoTotal - total;
    
    $('#cambio_cordobas').val(cambio > 0 ? cambio.toFixed(2) : '0.00');
}