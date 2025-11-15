<?php
/**
 * Foam Orders System - REST API Endpoint
 * Receives order data from external systems
 */

require_once 'config.php';

// Set JSON response header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

// Get API secret from database or config
$db = get_db_connection();
$stmt = $db->query("SELECT setting_value FROM foam_settings WHERE setting_key = 'api_secret'");
$api_secret = $stmt->fetchColumn() ?: API_SECRET;

// Verify signature
$signature = $_SERVER['HTTP_X_FOD_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_FOD_TIMESTAMP'] ?? '';
$raw_body = file_get_contents('php://input');

if (!$signature || !$timestamp || abs(time() - intval($timestamp)) > 300) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Bad headers or expired timestamp']);
    exit;
}

$calculated_signature = hash_hmac('sha256', $timestamp . '.' . $raw_body, $api_secret);

if (!hash_equals($calculated_signature, $signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => 'Invalid signature']);
    exit;
}

// Parse payload
$payload = json_decode($raw_body, true);

if (!is_array($payload) || empty($payload['order_id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Invalid payload']);
    exit;
}

try {
    // Ensure schema is ready
    ensure_schema($db);

    // Prepare billing and shipping addresses
    $billing_address = null;
    $shipping_address = null;

    if (!empty($payload['billing_first_name']) || !empty($payload['billing_address_1'])) {
        $billing_address = json_encode([
            'first_name' => $payload['billing_first_name'] ?? '',
            'last_name' => $payload['billing_last_name'] ?? '',
            'company' => $payload['billing_company'] ?? '',
            'address_1' => $payload['billing_address_1'] ?? '',
            'address_2' => $payload['billing_address_2'] ?? '',
            'city' => $payload['billing_city'] ?? '',
            'postcode' => $payload['billing_postcode'] ?? '',
            'country' => $payload['billing_country'] ?? '',
            'state' => $payload['billing_state'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
    } elseif (!empty($payload['billing_address'])) {
        $billing_address = json_encode($payload['billing_address'], JSON_UNESCAPED_UNICODE);
    }

    if (!empty($payload['shipping_first_name']) || !empty($payload['shipping_address_1'])) {
        $shipping_address = json_encode([
            'first_name' => $payload['shipping_first_name'] ?? '',
            'last_name' => $payload['shipping_last_name'] ?? '',
            'company' => $payload['shipping_company'] ?? '',
            'address_1' => $payload['shipping_address_1'] ?? '',
            'address_2' => $payload['shipping_address_2'] ?? '',
            'city' => $payload['shipping_city'] ?? '',
            'postcode' => $payload['shipping_postcode'] ?? '',
            'country' => $payload['shipping_country'] ?? '',
            'state' => $payload['shipping_state'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
    } elseif (!empty($payload['shipping_address'])) {
        $shipping_address = json_encode($payload['shipping_address'], JSON_UNESCAPED_UNICODE);
    }

    // Insert or update order
    $stmt = $db->prepare("
        INSERT INTO foam_orders (
            order_id, order_number, status, customer_name, email, phone,
            total, currency, shipping_method, priority, date_created, date_modified,
            grades, depths, job_desc, source, billing_address, shipping_address, customer_notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            order_number = VALUES(order_number),
            status = VALUES(status),
            customer_name = VALUES(customer_name),
            email = VALUES(email),
            phone = VALUES(phone),
            total = VALUES(total),
            currency = VALUES(currency),
            shipping_method = VALUES(shipping_method),
            priority = VALUES(priority),
            date_modified = VALUES(date_modified),
            grades = VALUES(grades),
            depths = VALUES(depths),
            job_desc = VALUES(job_desc),
            source = VALUES(source),
            billing_address = VALUES(billing_address),
            shipping_address = VALUES(shipping_address),
            customer_notes = VALUES(customer_notes)
    ");

    $stmt->execute([
        intval($payload['order_id']),
        $payload['order_number'] ?? $payload['order_id'],
        $payload['status'] ?? 'processing',
        $payload['customer_name'] ?? '',
        $payload['email'] ?? '',
        $payload['phone'] ?? '',
        floatval($payload['total'] ?? 0),
        $payload['currency'] ?? 'GBP',
        $payload['shipping_method'] ?? '',
        $payload['priority'] ?? '',
        $payload['date_created'] ?? date('Y-m-d H:i:s'),
        $payload['date_modified'] ?? date('Y-m-d H:i:s'),
        $payload['grades'] ?? '',
        $payload['depths'] ?? '',
        $payload['job_desc'] ?? '',
        $payload['store'] ?? 'Unknown',
        $billing_address,
        $shipping_address,
        $payload['customer_notes'] ?? ''
    ]);

    // Get order row ID
    $order_row_id = $db->query("SELECT id FROM foam_orders WHERE order_id = " . intval($payload['order_id']))->fetchColumn();

    // Replace order items
    if (!empty($payload['items']) && is_array($payload['items'])) {
        $db->prepare("DELETE FROM foam_order_items WHERE order_id = ?")->execute([intval($payload['order_id'])]);

        foreach ($payload['items'] as $item) {
            $meta = isset($item['meta']) ? $item['meta'] : [];
            $stmt = $db->prepare("INSERT INTO foam_order_items (order_id, product_id, sku, name, qty, line_total, meta_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                intval($payload['order_id']),
                isset($item['product_id']) ? intval($item['product_id']) : null,
                $item['sku'] ?? null,
                $item['name'] ?? '',
                intval($item['qty'] ?? 0),
                floatval($item['line_total'] ?? 0),
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        }
    }

    // Build parts from items
    $db->prepare("DELETE FROM foam_order_parts WHERE order_id = ?")->execute([intval($payload['order_id'])]);

    $items_result = $db->prepare("SELECT id, qty, meta_json FROM foam_order_items WHERE order_id = ?");
    $items_result->execute([intval($payload['order_id'])]);
    $items = $items_result->fetchAll();

    $idx = 0;
    $inserted_parts = 0;

    foreach ($items as $item) {
        $idx++;
        $meta = json_decode($item['meta_json'], true) ?: [];

        $grade_raw = grade_from_meta($meta);
        if ($grade_raw === '') continue;

        $depth_cm = null;
        if (isset($meta['depth_cm'])) {
            $depth_cm = intval($meta['depth_cm']);
        } else {
            $depth_cm = detect_depth_from_meta_cm($meta);
        }

        $qty = intval($item['qty'] ?? 1);
        $parts_to_create = [];

        // Split grades
        if (stripos($grade_raw, 'Mixed With') !== false) {
            $grades = array_map('trim', explode('Mixed With', $grade_raw, 2));
            foreach ($grades as $g) {
                if ($g !== '') $parts_to_create[] = ['grade' => $g, 'depth_cm' => $depth_cm, 'qty' => $qty];
            }
        } elseif (strpos($grade_raw, ',') !== false) {
            $grades = array_values(array_filter(array_map('trim', explode(',', $grade_raw))));
            foreach ($grades as $g) {
                $parts_to_create[] = ['grade' => $g, 'depth_cm' => $depth_cm, 'qty' => $qty];
            }
        } else {
            $parts_to_create[] = ['grade' => $grade_raw, 'depth_cm' => $depth_cm, 'qty' => $qty];
        }

        foreach ($parts_to_create as $pt) {
            $stmt = $db->prepare("INSERT INTO foam_order_parts (order_row_id, order_id, item_index, grade, depth_cm, qty, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([
                $order_row_id,
                intval($payload['order_id']),
                $idx,
                $pt['grade'],
                $pt['depth_cm'],
                $pt['qty']
            ]);
            $inserted_parts++;
        }
    }

    // Recalculate progress
    if ($order_row_id) {
        $total_parts = $db->prepare("SELECT COUNT(*) FROM foam_order_parts WHERE order_row_id = ?");
        $total_parts->execute([$order_row_id]);
        $total = $total_parts->fetchColumn();

        $done_parts = $db->prepare("SELECT COUNT(*) FROM foam_order_parts WHERE order_row_id = ? AND status = 'done'");
        $done_parts->execute([$order_row_id]);
        $done = $done_parts->fetchColumn();

        $pct = $total ? floor(($done / $total) * 100) : 0;

        $db->prepare("UPDATE foam_orders SET parts_total = ?, parts_done = ?, progress_pct = ? WHERE id = ?")->execute([$total, $done, $pct, $order_row_id]);
    }

    http_response_code(200);
    echo json_encode(['ok' => true, 'parts_created' => $inserted_parts]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'Server error: ' . $e->getMessage()]);
}

// Helper functions for parsing
function strip_hint($txt) {
    $txt = strip_tags($txt);
    return preg_replace('/\s*\(\+[^)]*\)\s*$/u', '', $txt);
}

function to_num($v) {
    if (is_numeric($v)) return floatval($v);
    $v = preg_replace('/[^0-9.\-]+/', '', $v);
    return $v === '' ? 0.0 : floatval($v);
}

function cm_ceil($val, $unit) {
    $n = to_num($val);
    $u = strtoupper(trim($unit));
    if ($u === 'CM') return intval(ceil($n));
    if ($u === 'MM') return intval(ceil($n / 10.0));
    if ($u === 'IN' || $u === 'INCH' || $u === 'INCHES') return intval(ceil($n * 2.54));
    return intval(ceil($n));
}

function detect_unit_from_meta($meta) {
    if (!empty($meta['Measure your cushions'])) return $meta['Measure your cushions'];
    if (!empty($meta['_wapf_meta']['fields']['62ea52c0ab295']['value'])) {
        return $meta['_wapf_meta']['fields']['62ea52c0ab295']['value'];
    }
    return 'CM';
}

function detect_depth_from_meta_cm($meta) {
    $unit = detect_unit_from_meta($meta);
    $val = null;
    if (isset($meta['Side C (Depth)'])) {
        $val = $meta['Side C (Depth)'];
    } elseif (!empty($meta['_wapf_meta']['fields']['62ea52c0ab2a0']['value'])) {
        $val = $meta['_wapf_meta']['fields']['62ea52c0ab2a0']['value'];
    }
    return ($val !== null && $val !== '') ? cm_ceil($val, $unit) : null;
}

function grade_from_meta($meta) {
    $g = '';
    if (!empty($meta['Standard Foams'])) $g = $meta['Standard Foams'];
    if (!empty($meta['Bonded Foams'])) $g = $meta['Bonded Foams'];
    return strip_hint($g);
}

function ensure_schema($db) {
    // Add columns if they don't exist
    $columns = $db->query("SHOW COLUMNS FROM foam_orders")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('source', $columns)) {
        $db->exec("ALTER TABLE foam_orders ADD COLUMN source VARCHAR(100) NULL");
    }
    if (!in_array('billing_address', $columns)) {
        $db->exec("ALTER TABLE foam_orders ADD COLUMN billing_address TEXT NULL");
    }
    if (!in_array('shipping_address', $columns)) {
        $db->exec("ALTER TABLE foam_orders ADD COLUMN shipping_address TEXT NULL");
    }
    if (!in_array('customer_notes', $columns)) {
        $db->exec("ALTER TABLE foam_orders ADD COLUMN customer_notes TEXT NULL");
    }
}
