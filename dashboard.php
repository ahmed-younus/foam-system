<?php
$page_title = 'Dashboard';
require_once 'header.php';

$db = get_db_connection();

// Get statistics
$total_orders = $db->query("SELECT COUNT(*) FROM foam_orders")->fetchColumn();
$today_orders = $db->query("SELECT COUNT(*) FROM foam_orders WHERE DATE(date_created) = CURDATE()")->fetchColumn();
$open_orders = $db->query("SELECT COUNT(*) FROM foam_orders WHERE status IN ('pending','processing','on-hold')")->fetchColumn();
$completed_orders = $db->query("SELECT COUNT(*) FROM foam_orders WHERE status='completed'")->fetchColumn();

// Recent orders
$recent_orders = $db->query("SELECT * FROM foam_orders ORDER BY date_created DESC LIMIT 10")->fetchAll();

// Orders by status
$status_counts = $db->query("
    SELECT status, COUNT(*) as count
    FROM foam_orders
    GROUP BY status
    ORDER BY count DESC
")->fetchAll();

// Orders by source
$source_counts = $db->query("
    SELECT source, COUNT(*) as count
    FROM foam_orders
    WHERE source IS NOT NULL AND source != ''
    GROUP BY source
    ORDER BY count DESC
    LIMIT 5
")->fetchAll();

// Batch statistics
$total_batches = $db->query("SELECT COUNT(*) FROM foam_batches")->fetchColumn();
$open_batches = $db->query("SELECT COUNT(*) FROM foam_batches WHERE status='open'")->fetchColumn();
$total_parts = $db->query("SELECT COUNT(*) FROM foam_order_parts")->fetchColumn();
$pending_parts = $db->query("SELECT COUNT(*) FROM foam_order_parts WHERE status='pending'")->fetchColumn();

// Waste inventory count
$waste_items = $db->query("SELECT COUNT(*) FROM foam_waste")->fetchColumn();

?>

<div class="page-header">
    <h1>📊 Dashboard</h1>
    <p>Overview of your foam orders management system</p>
</div>

<!-- Main Statistics -->
<div class="grid grid-4">
    <div class="stat-card">
        <div class="stat-icon">📦</div>
        <div class="stat-value"><?php echo number_format($total_orders); ?></div>
        <div class="stat-label">Total Orders</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">🆕</div>
        <div class="stat-value"><?php echo number_format($today_orders); ?></div>
        <div class="stat-label">Today's Orders</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">⏳</div>
        <div class="stat-value"><?php echo number_format($open_orders); ?></div>
        <div class="stat-label">Open Orders</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?php echo number_format($completed_orders); ?></div>
        <div class="stat-label">Completed</div>
    </div>
</div>

<!-- Production Statistics -->
<div class="grid grid-4" style="margin-top: 24px;">
    <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
        <div class="stat-icon" style="opacity: 0.5;">📋</div>
        <div class="stat-value" style="color: white;"><?php echo number_format($total_batches); ?></div>
        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Total Batches</div>
    </div>

    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
        <div class="stat-icon" style="opacity: 0.5;">🔓</div>
        <div class="stat-value" style="color: white;"><?php echo number_format($open_batches); ?></div>
        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Open Batches</div>
    </div>

    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
        <div class="stat-icon" style="opacity: 0.5;">🧩</div>
        <div class="stat-value" style="color: white;"><?php echo number_format($total_parts); ?></div>
        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Total Parts</div>
    </div>

    <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white;">
        <div class="stat-icon" style="opacity: 0.5;">♻️</div>
        <div class="stat-value" style="color: white;"><?php echo number_format($waste_items); ?></div>
        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Waste Items</div>
    </div>
</div>

<div class="grid grid-2" style="margin-top: 30px;">
    <!-- Recent Orders -->
    <div class="card">
        <div class="card-header">
            <span>📦 Recent Orders</span>
            <a href="orders.php" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">View All</a>
        </div>

        <?php if (empty($recent_orders)): ?>
            <p style="text-align: center; color: #7f8c8d; padding: 40px 0;">
                No orders yet. Orders will appear here once they are received.
            </p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_orders as $order): ?>
                        <tr>
                            <td><strong>#<?php echo escape_html($order['order_number']); ?></strong></td>
                            <td><?php echo escape_html($order['customer_name'] ?: 'N/A'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $order['status']; ?>">
                                    <?php echo escape_html($order['status']); ?>
                                </span>
                            </td>
                            <td><?php echo escape_html($order['currency']); ?> <?php echo number_format($order['total'], 2); ?></td>
                            <td><?php echo date('M d, Y', strtotime($order['date_created'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Orders by Status -->
    <div class="card">
        <div class="card-header">📈 Orders by Status</div>

        <?php if (empty($status_counts)): ?>
            <p style="text-align: center; color: #7f8c8d; padding: 40px 0;">No data available</p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php
                $max_count = max(array_column($status_counts, 'count'));
                foreach ($status_counts as $stat):
                    $percentage = $max_count > 0 ? ($stat['count'] / $max_count) * 100 : 0;
                ?>
                    <div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                            <span style="font-weight: 600; text-transform: capitalize;">
                                <?php echo escape_html($stat['status']); ?>
                            </span>
                            <span style="color: #667eea; font-weight: 700;">
                                <?php echo number_format($stat['count']); ?>
                            </span>
                        </div>
                        <div style="background: #ecf0f1; height: 8px; border-radius: 4px; overflow: hidden;">
                            <div style="background: linear-gradient(90deg, #667eea, #764ba2); height: 100%; width: <?php echo $percentage; ?>%; transition: width 0.3s;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Orders by Source -->
<?php if (!empty($source_counts)): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header">🌐 Orders by Source</div>

    <div class="grid grid-5">
        <?php foreach ($source_counts as $source): ?>
            <div style="background: #f8f9fa; padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 24px; font-weight: 700; color: #667eea;">
                    <?php echo number_format($source['count']); ?>
                </div>
                <div style="font-size: 13px; color: #666; margin-top: 4px;">
                    <?php echo escape_html($source['source'] ?: 'Unknown'); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">⚡ Quick Actions</div>

    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="orders.php" class="btn btn-primary">
            📦 View All Orders
        </a>
        <a href="batches.php" class="btn btn-success">
            📋 Manage Batches
        </a>
        <a href="planner.php" class="btn btn-warning">
            🎯 Auto Planner
        </a>
        <a href="waste.php" class="btn btn-secondary">
            ♻️ Waste Inventory
        </a>
        <a href="settings.php" class="btn btn-secondary">
            ⚙️ Settings
        </a>
    </div>
</div>

<?php require_once 'footer.php'; ?>
