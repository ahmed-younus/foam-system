<?php
$page_title = 'Dispatch Management';
require_once 'header.php';

$db = get_db_connection();

// Get orders by dispatch status
$ready_dispatch = $db->query("
    SELECT * FROM foam_orders
    WHERE status IN ('processing', 'completed')
    AND progress_pct >= 100
    ORDER BY date_created ASC
")->fetchAll();

$out_for_delivery = $db->query("
    SELECT * FROM foam_orders
    WHERE status = 'out-for-delivery'
    ORDER BY date_modified DESC
")->fetchAll();

$completed_orders = $db->query("
    SELECT * FROM foam_orders
    WHERE status = 'completed'
    ORDER BY date_modified DESC
    LIMIT 20
")->fetchAll();
?>

<div class="page-header">
    <h1>🚚 Dispatch Management</h1>
    <p>Manage order dispatch and delivery</p>
</div>

<!-- Stats -->
<div class="grid grid-3">
    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
        <div class="stat-icon" style="opacity: 0.5;">📦</div>
        <div class="stat-value" style="color: white;"><?php echo count($ready_dispatch); ?></div>
        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Ready for Dispatch</div>
    </div>

    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
        <div class="stat-icon" style="opacity: 0.5;">🚚</div>
        <div class="stat-value" style="color: white;"><?php echo count($out_for_delivery); ?></div>
        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Out for Delivery</div>
    </div>

    <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
        <div class="stat-icon" style="opacity: 0.5;">✅</div>
        <div class="stat-value" style="color: white;"><?php echo count($completed_orders); ?></div>
        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Completed Today</div>
    </div>
</div>

<!-- Ready for Dispatch -->
<div class="card">
    <div class="card-header">📦 Ready for Dispatch (<?php echo count($ready_dispatch); ?>)</div>

    <?php if (empty($ready_dispatch)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No orders ready for dispatch.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Progress</th>
                        <th>Total</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ready_dispatch as $order): ?>
                        <tr>
                            <td><strong>#<?php echo escape_html($order['order_number']); ?></strong></td>
                            <td><?php echo escape_html($order['customer_name']); ?></td>
                            <td><?php echo $order['progress_pct']; ?>%</td>
                            <td><?php echo escape_html($order['currency']); ?> <?php echo number_format($order['total'], 2); ?></td>
                            <td><?php echo date('M j, Y', strtotime($order['date_created'])); ?></td>
                            <td>
                                <a href="orders.php?view=order&order_id=<?php echo $order['order_id']; ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                    View Order
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Out for Delivery -->
<div class="card">
    <div class="card-header">🚚 Out for Delivery (<?php echo count($out_for_delivery); ?>)</div>

    <?php if (empty($out_for_delivery)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No orders out for delivery.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Phone</th>
                        <th>Total</th>
                        <th>Dispatched</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($out_for_delivery as $order): ?>
                        <tr>
                            <td><strong>#<?php echo escape_html($order['order_number']); ?></strong></td>
                            <td><?php echo escape_html($order['customer_name']); ?></td>
                            <td><?php echo escape_html($order['phone']); ?></td>
                            <td><?php echo escape_html($order['currency']); ?> <?php echo number_format($order['total'], 2); ?></td>
                            <td><?php echo date('M j, Y', strtotime($order['date_modified'])); ?></td>
                            <td>
                                <a href="orders.php?view=order&order_id=<?php echo $order['order_id']; ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                    View Order
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Completed Orders -->
<div class="card">
    <div class="card-header">✅ Recently Completed (<?php echo count($completed_orders); ?>)</div>

    <?php if (empty($completed_orders)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No completed orders yet.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Completed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed_orders as $order): ?>
                        <tr>
                            <td><strong>#<?php echo escape_html($order['order_number']); ?></strong></td>
                            <td><?php echo escape_html($order['customer_name']); ?></td>
                            <td><?php echo escape_html($order['currency']); ?> <?php echo number_format($order['total'], 2); ?></td>
                            <td><?php echo date('M j, Y', strtotime($order['date_modified'])); ?></td>
                            <td>
                                <a href="orders.php?view=order&order_id=<?php echo $order['order_id']; ?>" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
