<?php
$page_title = 'Batches Management';
require_once 'header.php';

$db = get_db_connection();

// Handle batch creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_batch'])) {
    verify_request();

    $grade = $_POST['grade'] ?? '';
    $depth_cm = $_POST['depth_cm'] ?? null;

    if ($grade) {
        $batch_id = 'BATCH-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        $stmt = $db->prepare("INSERT INTO foam_batches (batch_id, grade, depth_cm, status, created_at) VALUES (?, ?, ?, 'open', NOW())");
        $stmt->execute([$batch_id, $grade, $depth_cm]);

        $_SESSION['success_message'] = "Batch $batch_id created successfully!";
        redirect('batches.php');
    }
}

// Handle batch closure
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_batch'])) {
    verify_request();

    $batch_id = $_POST['batch_id'] ?? '';
    if ($batch_id) {
        $stmt = $db->prepare("UPDATE foam_batches SET status = 'closed', closed_at = NOW() WHERE batch_id = ?");
        $stmt->execute([$batch_id]);

        $_SESSION['success_message'] = "Batch closed successfully!";
        redirect('batches.php');
    }
}

// Get batches
$status_filter = $_GET['status'] ?? '';
$where = '1=1';
$params = [];

if ($status_filter) {
    $where .= ' AND status = ?';
    $params[] = $status_filter;
}

$stmt = $db->prepare("SELECT b.*, COUNT(p.id) as parts_count FROM foam_batches b
                      LEFT JOIN foam_order_parts p ON b.batch_id = p.batch_id
                      WHERE $where
                      GROUP BY b.id
                      ORDER BY b.created_at DESC");
$stmt->execute($params);
$batches = $stmt->fetchAll();

// Get distinct grades from parts for quick batch creation
$grades = $db->query("SELECT DISTINCT grade FROM foam_order_parts WHERE status = 'pending' ORDER BY grade")->fetchAll(PDO::FETCH_COLUMN);

if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success">' . escape_html($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']);
}
?>

<div class="page-header">
    <h1>📋 Batches Management</h1>
    <p>Create and manage production batches</p>
</div>

<!-- Create New Batch -->
<div class="card">
    <div class="card-header">➕ Create New Batch</div>

    <form method="POST" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end;">
        <?php echo csrf_field(); ?>

        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
            <label>Grade</label>
            <select name="grade" required>
                <option value="">Select Grade</option>
                <?php foreach ($grades as $grade): ?>
                    <option value="<?php echo escape_html($grade); ?>"><?php echo escape_html($grade); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin: 0; min-width: 150px;">
            <label>Depth (cm)</label>
            <input type="number" name="depth_cm" placeholder="Optional">
        </div>

        <button type="submit" name="create_batch" class="btn btn-primary" style="margin: 0;">
            ➕ Create Batch
        </button>
    </form>
</div>

<!-- Filter -->
<div class="card">
    <form method="GET" style="display: flex; gap: 12px; align-items: end;">
        <div class="form-group" style="margin: 0;">
            <label>Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="open" <?php echo $status_filter === 'open' ? 'selected' : ''; ?>>Open</option>
                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secondary" style="margin: 0;">Filter</button>
        <?php if ($status_filter): ?>
            <a href="batches.php" class="btn btn-secondary" style="margin: 0;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Batches List -->
<div class="card">
    <div class="card-header">All Batches (<?php echo count($batches); ?>)</div>

    <?php if (empty($batches)): ?>
        <p style="text-align: center; padding: 40px; color: #7f8c8d;">
            No batches created yet. Create your first batch above.
        </p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Batch ID</th>
                        <th>Grade</th>
                        <th>Depth</th>
                        <th>Parts Count</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Closed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><strong><?php echo escape_html($batch['batch_id']); ?></strong></td>
                            <td><?php echo escape_html($batch['grade']); ?></td>
                            <td style="text-align: center;"><?php echo $batch['depth_cm'] ?? '—'; ?> cm</td>
                            <td style="text-align: center;"><strong><?php echo $batch['parts_count']; ?></strong></td>
                            <td>
                                <span class="badge badge-<?php echo $batch['status']; ?>">
                                    <?php echo escape_html(ucfirst($batch['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y H:i', strtotime($batch['created_at'])); ?></td>
                            <td><?php echo $batch['closed_at'] ? date('M j, Y H:i', strtotime($batch['closed_at'])) : '—'; ?></td>
                            <td>
                                <a href="batch-detail.php?batch_id=<?php echo urlencode($batch['batch_id']); ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                    View
                                </a>
                                <?php if ($batch['status'] === 'open'): ?>
                                    <form method="POST" style="display: inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="batch_id" value="<?php echo escape_html($batch['batch_id']); ?>">
                                        <button type="submit" name="close_batch" class="btn btn-warning" style="padding: 6px 12px; font-size: 13px;" data-confirm="Close this batch?">
                                            Close
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
