<?php
$page_title = 'Waste Inventory';
require_once 'header.php';

$db = get_db_connection();

// Handle add waste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_waste'])) {
    verify_request();

    $grade = $_POST['grade'] ?? '';
    $depth_cm = $_POST['depth_cm'] ?? null;
    $qty = intval($_POST['qty'] ?? 0);
    $location = $_POST['location'] ?? '';
    $notes = $_POST['notes'] ?? '';

    if ($grade && $qty > 0) {
        $stmt = $db->prepare("INSERT INTO foam_waste (grade, depth_cm, qty, location, notes, added_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$grade, $depth_cm, $qty, $location, $notes]);

        $_SESSION['success_message'] = 'Waste item added successfully!';
        redirect('waste.php');
    }
}

// Handle delete waste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_waste'])) {
    verify_request();

    $id = intval($_POST['waste_id'] ?? 0);
    if ($id) {
        $stmt = $db->prepare("DELETE FROM foam_waste WHERE id = ?");
        $stmt->execute([$id]);

        $_SESSION['success_message'] = 'Waste item deleted!';
        redirect('waste.php');
    }
}

// Get waste inventory
$waste_items = $db->query("SELECT * FROM foam_waste ORDER BY added_at DESC")->fetchAll();

// Get total by grade
$grade_totals = $db->query("SELECT grade, SUM(qty) as total FROM foam_waste GROUP BY grade ORDER BY total DESC")->fetchAll();

if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success">' . escape_html($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']);
}
?>

<div class="page-header">
    <h1>♻️ Waste Inventory</h1>
    <p>Manage foam waste and offcuts</p>
</div>

<!-- Add Waste Form -->
<div class="card">
    <div class="card-header">➕ Add Waste Item</div>

    <form method="POST">
        <?php echo csrf_field(); ?>

        <div class="grid grid-4">
            <div class="form-group">
                <label>Grade *</label>
                <input type="text" name="grade" required placeholder="e.g., CMHR 35/130">
            </div>

            <div class="form-group">
                <label>Depth (cm)</label>
                <input type="number" name="depth_cm" placeholder="Optional">
            </div>

            <div class="form-group">
                <label>Quantity *</label>
                <input type="number" name="qty" required min="1" placeholder="1">
            </div>

            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" placeholder="e.g., Shelf A">
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="2" placeholder="Optional notes about this waste item"></textarea>
        </div>

        <button type="submit" name="add_waste" class="btn btn-primary">➕ Add to Inventory</button>
    </form>
</div>

<!-- Summary by Grade -->
<?php if (!empty($grade_totals)): ?>
    <div class="card">
        <div class="card-header">📊 Summary by Grade</div>

        <div class="grid grid-4">
            <?php foreach ($grade_totals as $total): ?>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($total['total']); ?></div>
                    <div class="stat-label"><?php echo escape_html($total['grade']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Waste Inventory Table -->
<div class="card">
    <div class="card-header">Inventory (<?php echo count($waste_items); ?> items)</div>

    <?php if (empty($waste_items)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No waste items in inventory. Add items using the form above.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Grade</th>
                        <th>Depth (cm)</th>
                        <th>Quantity</th>
                        <th>Location</th>
                        <th>Notes</th>
                        <th>Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($waste_items as $item): ?>
                        <tr>
                            <td><strong><?php echo $item['id']; ?></strong></td>
                            <td><?php echo escape_html($item['grade']); ?></td>
                            <td style="text-align: center;"><?php echo $item['depth_cm'] ?? '—'; ?></td>
                            <td style="text-align: center;"><strong><?php echo $item['qty']; ?></strong></td>
                            <td><?php echo escape_html($item['location'] ?: '—'); ?></td>
                            <td><?php echo escape_html($item['notes'] ?: '—'); ?></td>
                            <td><?php echo date('M j, Y', strtotime($item['added_at'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="waste_id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" name="delete_waste" class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;" data-confirm="Delete this waste item?">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
