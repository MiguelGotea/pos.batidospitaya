<?php
/**
 * facturas_get_imprimibles.php
 * Endpoint AJAX para obtener datos estructurados de impresión por ID de factura.
 * Devuelve JSON con: factura, líneas con personalizaciones, comandas por estación.
 */
require_once '../../../core/auth/auth_pos.php';
posRequiereColaboradorAjax();
require_once '../../../core/database/conexion.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $idFactura = isset($_GET['id_factura']) ? (int)$_GET['id_factura'] : 0;
    if ($idFactura <= 0) {
        throw new Exception('ID de factura inválido.');
    }

    // ── 1. Cabecera de factura ──────────────────────────────────────────────────
    $stmtFac = $conn->prepare("
        SELECT
            f.id,
            f.tipo_cliente,
            f.id_cliente_club,
            f.nombre_cliente_general,
            f.subtotal,
            f.descuento_total,
            f.propina,
            f.porcentaje_propina,
            f.con_propina,
            f.total,
            f.fecha_hora,
            f.estado,
            COALESCE(c.nombre_completo, f.nombre_cliente_general) AS nombre_cliente,
            s.nombre AS nombre_sucursal
        FROM pos_ventas_facturas f
        LEFT JOIN clientesclub c ON c.id_clienteclub = f.id_cliente_club
        LEFT JOIN sucursales   s ON s.codigo = f.cod_sucursal
        WHERE f.id = :id
        LIMIT 1
    ");
    $stmtFac->execute([':id' => $idFactura]);
    $factura = $stmtFac->fetch(PDO::FETCH_ASSOC);

    if (!$factura) {
        throw new Exception("Factura #{$idFactura} no encontrada.");
    }

    // ── 2. Líneas de la factura con datos del grupo ─────────────────────────────
    $stmtLineas = $conn->prepare("
        SELECT
            fl.id                          AS id_linea,
            fl.id_producto_presentacion,
            fl.nombre_producto_snapshot    AS nombre_producto,
            fl.cantidad,
            fl.precio_unitario_final,
            fl.precio_linea_total,
            fl.notas,
            fl.es_canje_puntos,
            fl.orden_preparacion,
            pp.Id_receta_producto           AS id_receta,
            g.id                           AS id_grupo,
            g.nombre                       AS nombre_grupo,
            g.estacion_trabajo,
            g.lleva_endulzante,
            /* Endulzante elegido: id_endulzante_producto FK -> producto_presentacion (grupo Endulzantes)
               Pero en este POS, el endulzante se guarda en notas como texto resumen.
               Para la comanda necesitamos datos estructurados: los tomamos del campo notas si no
               hay tabla dedicada. El endpoint acepta también datos del carrito original via POST. */
            fl.id_endulzante_producto,
            fl.nivel_endulzante
        FROM pos_ventas_factura_lineas fl
        JOIN producto_presentacion pp ON pp.id = fl.id_producto_presentacion
        LEFT JOIN catalogo_menu_pos cmp ON cmp.id = pp.id_catalogo_menu_pos
        LEFT JOIN subgrupo_presentacion_producto sg ON sg.id = cmp.id_subgrupo
        LEFT JOIN grupo_presentacion_producto g ON g.id = sg.id_grupo_presentacion_producto
        WHERE fl.id_factura = :id
        ORDER BY fl.orden_preparacion ASC, fl.id ASC
    ");
    $stmtLineas->execute([':id' => $idFactura]);
    $lineas = $stmtLineas->fetchAll(PDO::FETCH_ASSOC);

    // ── 3. Para cada línea: obtener ingredientes de su receta ───────────────────
    $stmtIngredientes = $conn->prepare("
        SELECT
            crp.id              AS id_componente,
            crp.nombre          AS nombre_componente,
            crp.cantidad,
            crp.tipo_comanda,
            crp.orden_comanda,
            crp.notas           AS nota_ingrediente,
            pp.Nombre           AS nombre_ingrediente,
            COALESCE(up.nombre, 'u') AS unidad
        FROM componentes_receta_producto crp
        JOIN producto_presentacion pp ON pp.id = crp.id_presentacion_producto
        LEFT JOIN unidad_producto up  ON up.id = pp.id_unidad_producto
        WHERE crp.id_receta_producto_global = :id_receta
          AND crp.orden_comanda IS NOT NULL
        ORDER BY crp.orden_comanda ASC, crp.id ASC
    ");

    // ── 4. Endulzante por línea (desde tabla endulzantes, via id_endulzante_producto) ──
    // El id_endulzante_producto en pos_ventas_factura_lineas apunta a producto_presentacion (grupo Endulzantes)
    // pero los datos de factor/unidad_medida están en la tabla endulzantes.
    // Relación: producto_presentacion.Nombre contiene el nombre, pero factor está en endulzantes.
    // Estrategia: leer el nombre del endulzante desde notas (texto resumen) ya grabado.
    // Para la comanda, parseamos el campo notas o aceptamos datos del carrito via POST.

    $lineasConIngredientes = [];
    foreach ($lineas as $linea) {
        $idReceta = $linea['id_receta'];
        $ingredientesLinea = [];
        if ($idReceta) {
            $stmtIngredientes->execute([':id_receta' => $idReceta]);
            $ingredientesLinea = $stmtIngredientes->fetchAll(PDO::FETCH_ASSOC);
        }

        // Parsear notas para extraer personalizaciones
        $personalizacion = parsearNotasPersonalizacion($linea['notas'] ?? '');

        $lineasConIngredientes[] = array_merge($linea, [
            'ingredientes_receta' => $ingredientesLinea,
            'personalizacion_parseada' => $personalizacion,
        ]);
    }

    // ── 5. Cargar endulzante completo si tiene id_endulzante_producto ───────────
    // El campo id_endulzante_producto en factura_lineas es FK a producto_presentacion (grupo Endulzantes)
    // Necesitamos mapear a la tabla endulzantes para obtener factor y unidad_medida
    // Enfoque: buscar por nombre del producto_presentacion en la tabla endulzantes
    $mapEndulzantes = [];
    $stmtEndAll = $conn->prepare("SELECT id, nombre, unidad_medida, factor, nombre_opcion FROM endulzantes WHERE activo = 1");
    $stmtEndAll->execute();
    foreach ($stmtEndAll->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $mapEndulzantes[$e['id']] = $e;
    }

    // Si tenemos id_endulzante_producto, buscar en producto_presentacion y luego mapear
    // Alternativamente, aceptar datos adicionales via POST (carrito original)
    $cartData = [];
    if (!empty($_GET['cart_data'])) {
        $cartData = json_decode($_GET['cart_data'], true) ?: [];
    }
    // Indexar cartData por id_presentacion para lookup rápido
    $cartIndex = [];
    foreach ($cartData as $ci) {
        $cartIndex[(int)$ci['id_presentacion']] = $ci;
    }

    // ── 6. Enriquecer líneas con endulzante estructurado ───────────────────────
    foreach ($lineasConIngredientes as &$linea) {
        $endulzanteData = null;

        // Opción A: desde cartData (datos del carrito JS si se pasan)
        $idPres = (int)$linea['id_producto_presentacion'];
        if (isset($cartIndex[$idPres])) {
            $ci = $cartIndex[$idPres];
            $pers = $ci['personalizacion'] ?? [];
            if (!empty($pers['endulzante_id'])) {
                $eid = (int)$pers['endulzante_id'];
                if (isset($mapEndulzantes[$eid])) {
                    $endulzanteData = $mapEndulzantes[$eid];
                }
            }
        }

        // Opción B: desde nivel_endulzante + id_endulzante_producto en la línea
        // (guardado en BD cuando la factura se guarda — por ahora no está implementado completamente)
        // Dejamos como fallback el texto parseado de notas

        $linea['endulzante_comanda'] = $endulzanteData; // puede ser null
    }
    unset($linea);

    // ── 7. Estructurar comandas por estación de trabajo ─────────────────────────
    $comandas = [];
    $lineasConEstacion = [];
    $lineasSinEstacion = [];

    foreach ($lineasConIngredientes as $linea) {
        if ($linea['estacion_trabajo'] == 1) {
            $gid = (int)$linea['id_grupo'];
            if (!isset($comandas[$gid])) {
                $comandas[$gid] = [
                    'id_grupo'        => $gid,
                    'nombre_grupo'    => $linea['nombre_grupo'],
                    'lleva_endulzante'=> (int)$linea['lleva_endulzante'],
                    'productos'       => [],
                ];
            }
            $comandas[$gid]['productos'][] = $linea;
            $lineasConEstacion[] = $linea;
        } else {
            $lineasSinEstacion[] = $linea;
        }
    }

    // Para cada comanda, calcular "completar_con" (productos de otras estaciones + sin estación)
    foreach ($comandas as $gid => &$comanda) {
        $completarCon = [];
        foreach ($lineasConIngredientes as $linea) {
            if ((int)$linea['id_grupo'] !== $gid) {
                $completarCon[] = [
                    'nombre_producto' => $linea['nombre_producto'],
                    'cantidad'        => $linea['cantidad'],
                ];
            }
        }
        $comanda['completar_con'] = $completarCon;
    }
    unset($comanda);

    echo json_encode([
        'success'  => true,
        'factura'  => [
            'id'              => (int)$factura['id'],
            'tipo_cliente'    => $factura['tipo_cliente'],
            'nombre_cliente'  => $factura['nombre_cliente'],
            'fecha_hora'      => $factura['fecha_hora'],
            'nombre_sucursal' => $factura['nombre_sucursal'],
            'subtotal'        => (float)$factura['subtotal'],
            'descuento_total' => (float)$factura['descuento_total'],
            'propina'         => (float)$factura['propina'],
            'con_propina'     => (int)$factura['con_propina'],
            'porcentaje_propina' => (float)$factura['porcentaje_propina'],
            'total'           => (float)$factura['total'],
            'estado'          => $factura['estado'],
            'lineas'          => $lineasConIngredientes,
        ],
        'comandas' => array_values($comandas),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ── Helper: parsear notas para extraer resumen de personalización ──────────────
function parsearNotasPersonalizacion(string $notas): array {
    $resultado = [
        'notas_libres'            => '',
        'endulzante_texto'        => '',
        'ingredientes_quitados'   => [],
        'ingredientes_sustituidos'=> [],
        'extras'                  => [],
    ];

    // El campo notas tiene formato: "texto libre [Endulzante: X | Sin: Y | Cambio: A→B | Extra: Z]"
    // Separar texto libre del bloque de personalización
    if (preg_match('/^(.*?)\s*\[(.+)\]\s*$/s', trim($notas), $m)) {
        $resultado['notas_libres'] = trim($m[1]);
        $bloques = explode('|', $m[2]);
        foreach ($bloques as $bloque) {
            $bloque = trim($bloque);
            if (str_starts_with($bloque, 'Endulzante:')) {
                $resultado['endulzante_texto'] = trim(substr($bloque, 11));
            } elseif (str_starts_with($bloque, 'Sin:')) {
                $items = explode(',', trim(substr($bloque, 4)));
                $resultado['ingredientes_quitados'] = array_map('trim', $items);
            } elseif (str_starts_with($bloque, 'Cambio:')) {
                $items = explode(',', trim(substr($bloque, 7)));
                foreach ($items as $item) {
                    if (str_contains($item, '→')) {
                        [$orig, $nuevo] = explode('→', $item, 2);
                        $resultado['ingredientes_sustituidos'][] = [
                            'nombre_original' => trim($orig),
                            'nombre_nuevo'    => trim($nuevo),
                        ];
                    }
                }
            } elseif (str_starts_with($bloque, 'Extra:')) {
                $items = explode(',', trim(substr($bloque, 6)));
                $resultado['extras'] = array_map('trim', $items);
            } elseif (str_starts_with($bloque, 'PRODUCTO CANJEADO')) {
                // ignorar
            }
        }
    } else {
        $resultado['notas_libres'] = trim($notas);
    }

    return $resultado;
}
?>

