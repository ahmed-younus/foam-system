<?php
// This file is included from orders.php when viewing a single order
$order_id = intval($_GET['order_id'] ?? 0);

if (!$order_id) {
    echo '<div class="alert alert-error">Invalid order ID</div>';
    require_once 'footer.php';
    exit;
}

// Get order details
$stmt = $db->prepare("SELECT * FROM foam_orders WHERE order_id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    echo '<div class="alert alert-error">Order not found</div>';
    require_once 'footer.php';
    exit;
}

// Get order items
$items_stmt = $db->prepare("SELECT * FROM foam_order_items WHERE order_id = ?");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

// Get order parts
$parts_stmt = $db->prepare("SELECT * FROM foam_order_parts WHERE order_id = ? ORDER BY created_at ASC");
$parts_stmt->execute([$order_id]);
$parts = $parts_stmt->fetchAll();

// Parse addresses
$billing_address = $order['billing_address'] ? json_decode($order['billing_address'], true) : null;
$shipping_address = $order['shipping_address'] ? json_decode($order['shipping_address'], true) : null;

// Helper functions
function get_status_color($status) {
    $status = strtolower($status);
    $colors = [
        'completed' => '#27ae60',
        'done' => '#27ae60',
        'processing' => '#f39c12',
        'in_progress' => '#f39c12',
        'pending' => '#e67e22',
        'cancelled' => '#e74c3c',
        'failed' => '#e74c3c',
        'out-for-delivery' => '#9b59b6'
    ];
    return $colors[$status] ?? '#95a5a6';
}

function get_status_bg($status) {
    $status = strtolower($status);
    $colors = [
        'completed' => '#d4edda',
        'done' => '#d4edda',
        'processing' => '#fff3cd',
        'in_progress' => '#fff3cd',
        'pending' => '#ffe5cc',
        'cancelled' => '#f8d7da',
        'failed' => '#f8d7da',
        'out-for-delivery' => '#f3e5f5'
    ];
    return $colors[$status] ?? '#f8f9fa';
}

function format_meta_key($key) {
    $key = str_replace(['_', '-'], ' ', $key);
    return ucwords($key);
}
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1>📦 Order #<?php echo escape_html($order['order_number']); ?></h1>
        <p>Detailed order information</p>
    </div>
    <a href="orders.php" class="btn btn-secondary">← Back to Orders</a>
</div>

<!-- Order Summary Cards -->
<div class="grid grid-4">
    <div class="card">
        <h3 style="margin: 0 0 12px; font-size: 14px; color: #7f8c8d; text-transform: uppercase;">Customer</h3>
        <div style="font-size: 18px; font-weight: 700; margin-bottom: 8px;">
            <?php echo escape_html($order['customer_name'] ?: 'N/A'); ?>
        </div>
        <div style="font-size: 14px; color: #666; line-height: 1.6;">
            <?php if ($order['email']): ?>
                📧 <a href="mailto:<?php echo escape_html($order['email']); ?>"><?php echo escape_html($order['email']); ?></a><br>
            <?php endif; ?>
            <?php if ($order['phone']): ?>
                📞 <?php echo escape_html($order['phone']); ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h3 style="margin: 0 0 12px; font-size: 14px; color: #7f8c8d; text-transform: uppercase;">Source</h3>
        <div style="font-size: 18px; font-weight: 700; margin-bottom: 8px; color: #667eea;">
            <?php echo escape_html($order['source'] ?? 'Unknown'); ?>
        </div>
        <div style="font-size: 14px; color: #666;">
            Order received from<br>external system
        </div>
    </div>

    <div class="card">
        <h3 style="margin: 0 0 12px; font-size: 14px; color: #7f8c8d; text-transform: uppercase;">Order Info</h3>
        <div style="font-size: 20px; font-weight: 700; margin-bottom: 8px; color: #27ae60;">
            <?php echo escape_html($order['currency']); ?> <?php echo number_format($order['total'], 2); ?>
        </div>
        <div style="font-size: 14px; color: #666; line-height: 1.6;">
            <span style="color: <?php echo get_status_color($order['status']); ?>; font-weight: 600;">
                <?php echo escape_html(ucfirst(str_replace('-', ' ', $order['status']))); ?>
            </span><br>
            <?php echo escape_html($order['shipping_method'] ?: 'Standard Shipping'); ?>
        </div>
    </div>

    <div class="card">
        <h3 style="margin: 0 0 12px; font-size: 14px; color: #7f8c8d; text-transform: uppercase;">Progress</h3>
        <div style="font-size: 18px; font-weight: 700; margin-bottom: 8px;">
            <?php
            if ($order['parts_total'] > 0) {
                echo $order['parts_done'] . '/' . $order['parts_total'] . ' (' . $order['progress_pct'] . '%)';
            } else {
                echo '0/0 (0%)';
            }
            ?>
        </div>
        <div style="font-size: 13px; color: #666; line-height: 1.6;">
            Created: <?php echo date('M j, Y', strtotime($order['date_created'])); ?><br>
            Modified: <?php echo date('M j, Y', strtotime($order['date_modified'])); ?>
        </div>
    </div>
</div>

<!-- Customer Notes -->
<?php if (!empty($order['customer_notes'])): ?>
    <div class="alert alert-warning">
        <strong>📝 Customer Notes:</strong><br>
        <?php echo nl2br(escape_html($order['customer_notes'])); ?>
    </div>
<?php endif; ?>

<!-- Addresses -->
<?php if ($billing_address || $shipping_address): ?>
    <div class="grid grid-2">
        <?php if ($billing_address): ?>
            <div class="card">
                <div class="card-header">📮 Billing Address</div>
                <div style="line-height: 1.8; font-size: 14px;">
                    <?php if (!empty($billing_address['first_name']) || !empty($billing_address['last_name'])): ?>
                        <strong><?php echo escape_html(trim(($billing_address['first_name'] ?? '') . ' ' . ($billing_address['last_name'] ?? ''))); ?></strong><br>
                    <?php endif; ?>
                    <?php if (!empty($billing_address['company'])): ?>
                        <?php echo escape_html($billing_address['company']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($billing_address['address_1'])): ?>
                        <?php echo escape_html($billing_address['address_1']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($billing_address['address_2'])): ?>
                        <?php echo escape_html($billing_address['address_2']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($billing_address['city']) || !empty($billing_address['postcode'])): ?>
                        <?php echo escape_html(trim(($billing_address['city'] ?? '') . ' ' . ($billing_address['postcode'] ?? ''))); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($billing_address['country'])): ?>
                        <?php echo escape_html($billing_address['country']); ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($shipping_address): ?>
            <div class="card">
                <div class="card-header">🚚 Shipping Address</div>
                <div style="line-height: 1.8; font-size: 14px;">
                    <?php if (!empty($shipping_address['first_name']) || !empty($shipping_address['last_name'])): ?>
                        <strong><?php echo escape_html(trim(($shipping_address['first_name'] ?? '') . ' ' . ($shipping_address['last_name'] ?? ''))); ?></strong><br>
                    <?php endif; ?>
                    <?php if (!empty($shipping_address['company'])): ?>
                        <?php echo escape_html($shipping_address['company']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($shipping_address['address_1'])): ?>
                        <?php echo escape_html($shipping_address['address_1']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($shipping_address['address_2'])): ?>
                        <?php echo escape_html($shipping_address['address_2']); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($shipping_address['city']) || !empty($shipping_address['postcode'])): ?>
                        <?php echo escape_html(trim(($shipping_address['city'] ?? '') . ' ' . ($shipping_address['postcode'] ?? ''))); ?><br>
                    <?php endif; ?>
                    <?php if (!empty($shipping_address['country'])): ?>
                        <?php echo escape_html($shipping_address['country']); ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Specifications -->
<div class="grid grid-2">
    <div class="card">
        <div class="card-header">🔧 Foam Specifications</div>
        <div style="margin-bottom: 12px;">
            <strong style="color: #667eea;">Grades:</strong><br>
            <span style="font-family: monospace; font-size: 14px;"><?php echo escape_html($order['grades'] ?: 'Not specified'); ?></span>
        </div>
        <div>
            <strong style="color: #667eea;">Depths:</strong><br>
            <span style="font-family: monospace; font-size: 14px;"><?php echo escape_html($order['depths'] ?: 'Not specified'); ?></span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">📄 Job Description</div>
        <div style="font-size: 14px; line-height: 1.6;">
            <?php echo nl2br(escape_html($order['job_desc'] ?: 'No description provided')); ?>
        </div>
    </div>
</div>

<!-- Order Items -->
<?php if (!empty($items)): ?>
    <div class="card">
        <div class="card-header">📦 Order Items (<?php echo count($items); ?>)</div>

        <?php foreach ($items as $index => $item): ?>
            <div style="border: 1px solid #ecf0f1; border-radius: 8px; margin-bottom: 16px; overflow: hidden;">
                <div style="background: #f8f9fa; padding: 14px; border-bottom: 1px solid #ecf0f1; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 16px;"><?php echo escape_html($item['name'] ?: 'Unnamed Product'); ?></strong>
                        <?php if ($item['sku']): ?>
                            <span style="font-size: 13px; color: #7f8c8d; margin-left: 12px;">SKU: <?php echo escape_html($item['sku']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: right;">
                        <strong>Qty: <?php echo escape_html($item['qty']); ?></strong><br>
                        <strong style="color: #27ae60; font-size: 16px;"><?php echo escape_html($order['currency']); ?> <?php echo number_format($item['line_total'], 2); ?></strong>
                    </div>
                </div>

                <?php
                $meta = json_decode($item['meta_json'], true);
                if ($meta && is_array($meta)):
                    $all_fields = [];

                    // Direct fields
                    foreach ($meta as $key => $value) {
                        if (empty($value) || strpos($key, '_') === 0) continue;
                        if (!is_array($value)) {
                            $all_fields[format_meta_key($key)] = $value;
                        }
                    }

                    // WAPF fields
                    if (isset($meta['_wapf_meta']['fields']) && is_array($meta['_wapf_meta']['fields'])) {
                        foreach ($meta['_wapf_meta']['fields'] as $field_id => $field_data) {
                            if (isset($field_data['value']) && !empty($field_data['value'])) {
                                $field_name = $field_data['label'] ?? format_meta_key($field_data['name'] ?? 'Custom Field');
                                $all_fields[$field_name] = $field_data['value'];
                            }
                        }
                    }

                    if (!empty($all_fields)):
                ?>
                    <div style="padding: 14px;">
                        <div class="grid grid-4">
                            <?php foreach ($all_fields as $key => $value): ?>
                                <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; border-left: 3px solid #667eea;">
                                    <div style="font-size: 11px; font-weight: 700; color: #667eea; text-transform: uppercase; margin-bottom: 4px;">
                                        <?php echo escape_html($key); ?>
                                    </div>
                                    <div style="font-size: 13px; font-weight: 600; color: #2c3e50;">
                                        <?php echo escape_html($value); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php
                    endif;
                endif;
                ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Order Parts -->
<?php if (!empty($parts)): ?>
    <div class="card">
        <div class="card-header">🧩 Order Parts (<?php echo count($parts); ?>)</div>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Part ID</th>
                        <th>Item #</th>
                        <th>Grade</th>
                        <th>Depth (cm)</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Batch ID</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parts as $part): ?>
                        <tr>
                            <td><strong><?php echo $part['id']; ?></strong></td>
                            <td><?php echo escape_html($part['item_index']); ?></td>
                            <td><?php echo escape_html($part['grade']); ?></td>
                            <td style="text-align: center;"><strong><?php echo $part['depth_cm'] ?? '—'; ?></strong></td>
                            <td style="text-align: center;"><?php echo $part['qty']; ?></td>
                            <td>
                                <span class="badge" style="background: <?php echo get_status_bg($part['status']); ?>; color: <?php echo get_status_color($part['status']); ?>;">
                                    <?php echo escape_html(ucfirst($part['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo escape_html($part['batch_id'] ?: '—'); ?></td>
                            <td><?php echo date('M j, Y', strtotime($part['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
