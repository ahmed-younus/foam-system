<?php
$page_title = 'Auto Planner';
require_once 'header.php';

$db = get_db_connection();

// Get pending parts grouped by grade and depth
$pending_parts = $db->query("
    SELECT grade, depth_cm, COUNT(*) as count, SUM(qty) as total_qty
    FROM foam_order_parts
    WHERE status = 'pending'
    GROUP BY grade, depth_cm
    ORDER BY count DESC
")->fetchAll();

// Get available waste inventory
$waste_inventory = $db->query("
    SELECT grade, depth_cm, SUM(qty) as available
    FROM foam_waste
    GROUP BY grade, depth_cm
    ORDER BY available DESC
")->fetchAll();
?>

<div class="page-header">
    <h1>🎯 Auto Planner</h1>
    <p>Plan production and match waste to orders</p>
</div>

<!-- Pending Parts -->
<div class="card">
    <div class="card-header">📦 Pending Parts by Grade & Depth</div>

    <?php if (empty($pending_parts)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No pending parts to plan.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Grade</th>
                        <th>Depth (cm)</th>
                        <th>Parts Count</th>
                        <th>Total Quantity</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_parts as $part): ?>
                        <tr>
                            <td><strong><?php echo escape_html($part['grade']); ?></strong></td>
                            <td style="text-align: center;"><?php echo $part['depth_cm'] ?? '—'; ?></td>
                            <td style="text-align: center;"><?php echo $part['count']; ?></td>
                            <td style="text-align: center;"><strong><?php echo $part['total_qty']; ?></strong></td>
                            <td>
                                <a href="batches.php" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                    Create Batch
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Waste Inventory -->
<div class="card">
    <div class="card-header">♻️ Available Waste Inventory</div>

    <?php if (empty($waste_inventory)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No waste inventory available.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Grade</th>
                        <th>Depth (cm)</th>
                        <th>Available Quantity</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($waste_inventory as $waste): ?>
                        <tr>
                            <td><strong><?php echo escape_html($waste['grade']); ?></strong></td>
                            <td style="text-align: center;"><?php echo $waste['depth_cm'] ?? '—'; ?></td>
                            <td style="text-align: center;"><strong><?php echo $waste['available']; ?></strong></td>
                            <td>
                                <span class="badge badge-completed">Available</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Planning Info -->
<div class="card">
    <div class="card-header">💡 Planning Tips</div>

    <div style="line-height: 1.8;">
        <p><strong>How to use the Auto Planner:</strong></p>
        <ul style="margin-left: 20px;">
            <li>Review pending parts grouped by grade and depth</li>
            <li>Check available waste inventory that can be used</li>
            <li>Create batches for efficient production planning</li>
            <li>Match waste items to pending orders to reduce material costs</li>
        </ul>
    </div>
</div>

<?php require_once 'footer.php'; ?>
