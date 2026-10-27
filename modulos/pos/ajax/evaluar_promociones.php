<?php
/**
 * ajax/evaluar_promociones.php
 * Motor de evaluación de promociones para el POS de ventas.
 *
 * Recibe (JSON body):
 *   context  { dia, hora, sucursal, canal, tipo_cliente }
 *   cart     [ { id, id_producto_maestro, id_grupo, id_subgrupo, precio, cantidad } … ]
 *   approved_ids  [ int ]   — IDs aprobados/aplicados por el cajero
 *
 * Devuelve:
 *   success          bool
 *   items            array  — carrito con descuento_total y promos[] por ítem
 *   total_original   float
 *   total_descuento  float
 *   total_final      float
 *   promos_aplican   array  — califican completamente (listas para botón APLICAR)
 *   promos_potenciales array — cumplen algunas condiciones (falta algo)
 *   promos_aplicadas  array — ya se aplicó (approved_ids)
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/core/auth/auth_pos.php';

    if (!isset($conn)) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/core/database/conexion.php';
    }

    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['context']) || !isset($data['cart'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Datos de entrada incompletos']);
        exit;
    }

    $context      = $data['context'];
    $cart         = $data['cart'];
    $approved_ids = isset($data['approved_ids']) ? (array)$data['approved_ids'] : [];

    // Si el carrito está vacío, devolver respuesta vacía
    if (empty($cart)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'           => true,
            'items'             => [],
            'total_original'    => 0,
            'total_descuento'   => 0,
            'total_final'       => 0,
            'promos_aplican'    => [],
            'promos_potenciales'=> [],
            'promos_aplicadas'  => [],
        ]);
        exit;
    }

    // Obtener todas las promociones activas y vigentes
    $sql = "SELECT p.*,
            (SELECT COUNT(*) FROM promo_condiciones WHERE promo_id = p.id) AS num_condiciones
            FROM promo_promociones p
            WHERE p.estado = 'activa'
              AND (p.fecha_inicio IS NULL OR p.fecha_inicio <= CURDATE())
              AND (p.fecha_fin   IS NULL OR p.fecha_fin   >= CURDATE())
            ORDER BY p.prioridad ASC, p.id DESC";
    $stmt = $conn->query($sql);
    $promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Preparar carrito con campos de seguimiento
    foreach ($cart as &$item) {
        $item['descuento_total']  = 0;
        $item['promos']           = [];
        $item['precio_original']  = (float)($item['precio'] ?? 0);
        $item['cantidad_libre']   = (int)($item['cantidad'] ?? 0);
    }
    unset($item);

    $promos_aplicadas   = [];
    $promos_aplican     = [];   // califican totalmente pero no están en approved_ids
    $promos_potenciales = [];
    $promos_descartadas = [];
    $total_descuento_global_extra = 0;

    // Evaluar cada promoción
    foreach ($promociones as $p) {
        $promo_id = $p['id'];

        $stmt_c = $conn->prepare("SELECT * FROM promo_condiciones WHERE promo_id = :pid ORDER BY orden");
        $stmt_c->execute([':pid' => $promo_id]);
        $condiciones = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

        $total_conds        = count($condiciones);
        $conds_met          = 0;
        $pasa_contexto      = true;
        $qualifying_indices = array_keys($cart);
        $has_product_cond   = false;
        $motivo_descarte    = '';

        // Evaluar condiciones
        foreach ($condiciones as $c) {
            $valor  = json_decode($c['valor_json'], true);
            $nombre = $c['nombre_cond'];
            $met    = true;

            if ($c['tipo_cond'] === 'A') {
                // TIPO A: Contexto
                switch ($nombre) {
                    case 'dia_semana':
                        if (!isset($valor['dias']) || !in_array($context['dia'] ?? '', $valor['dias'])) {
                            $met = false; $pasa_contexto = false;
                            $motivo_descarte = 'Día no permitido: ' . ($context['dia'] ?? '?');
                        }
                        break;
                    case 'sucursal':
                        if (!isset($valor['ids']) || !in_array((string)($context['sucursal'] ?? ''), array_map('strval', $valor['ids']))) {
                            $met = false; $pasa_contexto = false;
                            $motivo_descarte = 'Sucursal no permitida.';
                        }
                        break;
                    case 'horario':
                        $hora_actual = $context['hora'] ?? '00:00';
                        if ($hora_actual < ($valor['desde'] ?? '00:00') || $hora_actual > ($valor['hasta'] ?? '23:59')) {
                            $met = false; $pasa_contexto = false;
                            $motivo_descarte = 'Fuera de horario (' . ($valor['desde'] ?? '') . '–' . ($valor['hasta'] ?? '') . ').';
                        }
                        break;
                    case 'canal_venta':
                        if (!isset($valor['canales']) || !in_array($context['canal'] ?? '', $valor['canales'])) {
                            $met = false; $pasa_contexto = false;
                            $motivo_descarte = 'Canal no válido.';
                        }
                        break;
                    case 'tipo_cliente':
                        if (!isset($valor['tipos']) || !in_array($context['tipo_cliente'] ?? '', $valor['tipos'])) {
                            $met = false; $pasa_contexto = false;
                            $motivo_descarte = 'Tipo de cliente no aplica.';
                        }
                        break;
                }
            } else {
                // TIPO B: Carrito
                switch ($nombre) {
                    case 'producto':
                        $has_product_cond = true;
                        $match_found = false;
                        $new_qualifying = [];
                        foreach ($qualifying_indices as $idx) {
                            $it = $cart[$idx];
                            if ($it['id'] == $valor['id_producto'] || ($it['id_producto_maestro'] ?? 0) == $valor['id_producto']) {
                                if ($it['cantidad_libre'] >= ($valor['cantidad_min'] ?? 1)) {
                                    $new_qualifying[] = $idx;
                                    $match_found = true;
                                }
                            }
                        }
                        if (!$match_found) {
                            $met = false;
                            $qualifying_indices = [];
                            $motivo_descarte = 'Falta producto: ' . ($valor['nombre_producto'] ?? 'ID ' . $valor['id_producto']);
                        } else {
                            $qualifying_indices = $new_qualifying;
                        }
                        break;

                    case 'grupo_producto':
                        $has_product_cond = true;
                        $match_found = false;
                        $new_qualifying = [];
                        foreach ($qualifying_indices as $idx) {
                            $it = $cart[$idx];
                            if (($it['id_grupo'] ?? 0) == $valor['id_grupo'] && $it['cantidad_libre'] > 0) {
                                $new_qualifying[] = $idx;
                                $match_found = true;
                            }
                        }
                        if (!$match_found) {
                            $met = false;
                            $qualifying_indices = [];
                            $motivo_descarte = 'No hay productos del grupo: ' . ($valor['nombre_grupo'] ?? 'ID ' . $valor['id_grupo']);
                        } else {
                            $qualifying_indices = $new_qualifying;
                        }
                        break;

                    case 'monto_min':
                        $total_monto = 0;
                        foreach ($cart as $it) $total_monto += ($it['precio_original'] * $it['cantidad']);
                        if ($total_monto < ($valor['monto'] ?? 0)) {
                            $met = false;
                            $qualifying_indices = [];
                            $motivo_descarte = 'Monto mínimo insuficiente (se requieren C$ ' . number_format($valor['monto'], 2) . ').';
                        }
                        break;

                    case 'cantidad_min':
                        $total_items = 0;
                        foreach ($cart as $it) $total_items += $it['cantidad'];
                        if ($total_items < ($valor['cantidad'] ?? 1)) {
                            $met = false;
                            $qualifying_indices = [];
                            $motivo_descarte = 'Cantidad mínima no alcanzada (se requieren ' . ($valor['cantidad'] ?? 1) . ' unidades).';
                        }
                        break;
                }
            }

            if ($met) $conds_met++;
        }

        // ¿Califica completamente?
        if ($conds_met === $total_conds && !empty($qualifying_indices)) {
            $aplicado = false;
            $monto_descuento_promo = 0;

            // Determinar ítems a descontar
            $candidatos = [];
            foreach ($qualifying_indices as $idx) {
                for ($i = 0; $i < $cart[$idx]['cantidad_libre']; $i++) {
                    $candidatos[] = $idx;
                }
            }

            $items_finales = [];
            switch ($p['objetivo_descuento']) {
                case 'todos':
                    foreach ($qualifying_indices as $idx) $items_finales[$idx] = $cart[$idx]['cantidad'];
                    break;
                case 'mas_barato':
                    $min_idx = -1; $min_precio = PHP_FLOAT_MAX;
                    foreach ($qualifying_indices as $idx) {
                        if ($cart[$idx]['precio_original'] < $min_precio) {
                            $min_precio = $cart[$idx]['precio_original'];
                            $min_idx = $idx;
                        }
                    }
                    if ($min_idx !== -1) $items_finales[$min_idx] = 1;
                    break;
                case 'nth_item':
                    $n = (int)($p['objetivo_nth_numero'] ?: 2);
                    if (count($candidatos) >= $n) {
                        $idx = $candidatos[$n - 1];
                        $items_finales[$idx] = ($items_finales[$idx] ?? 0) + 1;
                    }
                    break;
                case 'get_y':
                    $y_prod_id = $p['objetivo_get_y_prod'];
                    $y_cant    = (int)($p['objetivo_get_y_cant'] ?: 1);
                    foreach ($cart as $idx => $it) {
                        if ($it['id'] == $y_prod_id || ($it['id_producto_maestro'] ?? 0) == $y_prod_id) {
                            $items_finales[$idx] = min($it['cantidad'], $y_cant);
                            break;
                        }
                    }
                    break;
                case 'factura':
                    break;
            }

            // Ejecutar descuento
            if ($p['objetivo_descuento'] === 'factura') {
                if ($p['resultado_tipo'] === 'pct_factura') {
                    $subtotal = 0;
                    foreach ($cart as $it) $subtotal += ($it['precio_original'] * $it['cantidad']);
                    $monto_descuento_promo = $subtotal * ($p['resultado_valor'] / 100);
                } elseif ($p['resultado_tipo'] === 'monto_factura') {
                    $monto_descuento_promo = (float)$p['resultado_valor'];
                }
                $aplicado = ($monto_descuento_promo > 0);
            } else {
                foreach ($items_finales as $idx => $cant_desc) {
                    $item_ref = &$cart[$idx];
                    $desc_unitario = ($p['resultado_tipo'] === 'pct_producto')
                        ? ($item_ref['precio_original'] * ($p['resultado_valor'] / 100))
                        : (float)$p['resultado_valor'];

                    $total_desc_item = $desc_unitario * $cant_desc;
                    if ($total_desc_item > 0) {
                        if (in_array($promo_id, $approved_ids)) {
                            $item_ref['descuento_total'] += $total_desc_item;
                            $item_ref['promos'][] = ['id' => $p['id'], 'nombre' => $p['nombre']];
                            if ($p['combinable'] == 0) $item_ref['cantidad_libre'] -= $cant_desc;
                        }
                        $monto_descuento_promo += $total_desc_item;
                        $aplicado = true;
                    }
                }
                unset($item_ref);
            }

            // Aplicar tope máximo si existe
            if ($p['descuento_maximo_cs'] > 0 && $monto_descuento_promo > $p['descuento_maximo_cs']) {
                $monto_descuento_promo = (float)$p['descuento_maximo_cs'];
            }

            if ($aplicado) {
                $resumen_promo = 'Ahorro: C$' . number_format($monto_descuento_promo, 2);
                if (in_array($promo_id, $approved_ids)) {
                    // Ya aprobada → registrar como aplicada
                    $promos_aplicadas[] = [
                        'id'      => $p['id'],
                        'nombre'  => $p['nombre'],
                        'resumen' => $resumen_promo,
                        'resultado_tipo'  => $p['resultado_tipo'],
                        'resultado_valor' => $p['resultado_valor'],
                    ];
                    if ($p['objetivo_descuento'] === 'factura') {
                        $total_descuento_global_extra += $monto_descuento_promo;
                    }
                } else {
                    // Califica pero aún no aprobada
                    $promos_aplican[] = [
                        'id'                   => $p['id'],
                        'nombre'               => $p['nombre'],
                        'resumen'              => $resumen_promo,
                        'resultado_tipo'       => $p['resultado_tipo'],
                        'resultado_valor'      => $p['resultado_valor'],
                        'objetivo_descuento'   => $p['objetivo_descuento'],
                        'requiere_autorizacion'=> (bool)$p['requiere_autorizacion'],
                        'ejecucion_automatica' => (bool)$p['ejecucion_automatica'],
                        'monto_descuento'      => round($monto_descuento_promo, 2),
                    ];
                }
            } else {
                $promos_descartadas[] = ['id' => $p['id'], 'nombre' => $p['nombre'], 'motivo' => 'No se encontraron ítems válidos.'];
            }
        } elseif ($conds_met > 0) {
            // Potencial: cumple algunas condiciones
            $promos_potenciales[] = [
                'id'               => $p['id'],
                'nombre'           => $p['nombre'],
                'condiciones_met'  => $conds_met,
                'total_condiciones'=> $total_conds,
                'faltante'         => $motivo_descarte ?: 'Faltan condiciones por cumplir.',
            ];
        } else {
            $promos_descartadas[] = [
                'id'     => $p['id'],
                'nombre' => $p['nombre'],
                'motivo' => $motivo_descarte ?: 'No cumple las condiciones requeridas.',
            ];
        }
    }

    // Totales finales
    $total_original  = 0;
    $total_descuento = 0;
    foreach ($cart as &$item) {
        $total_original  += ($item['precio_original'] * $item['cantidad']);
        $total_descuento += $item['descuento_total'];
        $item['subtotal_final'] = ($item['precio_original'] * $item['cantidad']) - $item['descuento_total'];
    }
    unset($item);
    $total_descuento += $total_descuento_global_extra;

    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success'           => true,
        'items'             => $cart,
        'total_original'    => round($total_original, 2),
        'total_descuento'   => round($total_descuento, 2),
        'total_final'       => round($total_original - $total_descuento, 2),
        'promos_aplican'    => $promos_aplican,
        'promos_potenciales'=> $promos_potenciales,
        'promos_aplicadas'  => $promos_aplicadas,
    ]);

} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

