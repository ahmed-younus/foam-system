<?php
$page_title = 'Batch Details';
require_once 'header.php';

$db = get_db_connection();
$batch_id = $_GET['batch_id'] ?? '';

if (!$batch_id) {
    echo '<div class="alert alert-error">Invalid batch ID</div>';
    require_once 'footer.php';
    exit;
}

// Get batch details
$stmt = $db->prepare("SELECT * FROM foam_batches WHERE batch_id = ?");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch();

if (!$batch) {
    echo '<div class="alert alert-error">Batch not found</div>';
    require_once 'footer.php';
    exit;
}

// Get parts in this batch
$parts_stmt = $db->prepare("
    SELECT p.*, o.order_number, o.customer_name
    FROM foam_order_parts p
    LEFT JOIN foam_orders o ON p.order_id = o.order_id
    WHERE p.batch_id = ?
    ORDER BY p.created_at ASC
");
$parts_stmt->execute([$batch_id]);
$parts = $parts_stmt->fetchAll();
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h1>📋 <?php echo escape_html($batch_id); ?></h1>
        <p>Batch details and parts</p>
    </div>
    <a href="batches.php" class="btn btn-secondary">← Back to Batches</a>
</div>

<!-- Batch Info -->
<div class="grid grid-4">
    <div class="stat-card">
        <div class="stat-icon">🏷️</div>
        <div class="stat-value" style="font-size: 16px;"><?php echo escape_html($batch['grade']); ?></div>
        <div class="stat-label">Grade</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">📏</div>
        <div class="stat-value"><?php echo $batch['depth_cm'] ?? '—'; ?> cm</div>
        <div class="stat-label">Depth</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">📦</div>
        <div class="stat-value"><?php echo count($parts); ?></div>
        <div class="stat-label">Parts Count</div>
    </div>

    <div class="stat-card">
        <div class="stat-icon">📊</div>
        <div class="stat-value" style="font-size: 16px;"><?php echo escape_html(ucfirst($batch['status'])); ?></div>
        <div class="stat-label">Status</div>
    </div>
</div>

<!-- Parts Table -->
<div class="card">
    <div class="card-header">Parts in Batch (<?php echo count($parts); ?>)</div>

    <?php if (empty($parts)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No parts assigned to this batch yet.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Part ID</th>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Grade</th>
                        <th>Depth</th>
                        <th>Qty</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parts as $part): ?>
                        <tr>
                            <td><strong><?php echo $part['id']; ?></strong></td>
                            <td>
                                <a href="orders.php?view=order&order_id=<?php echo $part['order_id']; ?>">
                                    #<?php echo escape_html($part['order_number']); ?>
                                </a>
                            </td>
                            <td><?php echo escape_html($part['customer_name']); ?></td>
                            <td><?php echo escape_html($part['grade']); ?></td>
                            <td style="text-align: center;"><?php echo $part['depth_cm'] ?? '—'; ?></td>
                            <td style="text-align: center;"><?php echo $part['qty']; ?></td>
                            <td>
                                <span class="badge badge-<?php echo $part['status']; ?>">
                                    <?php echo escape_html(ucfirst($part['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($part['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
