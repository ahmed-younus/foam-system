<?php
$page_title = 'Orders Management';
require_once 'header.php';

$db = get_db_connection();

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    verify_request();

    $order_ids = $_POST['order_ids'] ?? [];
    if (!empty($order_ids)) {
        $placeholders = str_repeat('?,', count($order_ids) - 1) . '?';

        // Get affected batch IDs
        $stmt = $db->prepare("SELECT DISTINCT batch_id FROM foam_order_parts WHERE order_id IN ($placeholders) AND batch_id IS NOT NULL AND batch_id != ''");
        $stmt->execute($order_ids);
        $affected_batches = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete parts, items, and orders
        $db->prepare("DELETE FROM foam_order_parts WHERE order_id IN ($placeholders)")->execute($order_ids);
        $db->prepare("DELETE FROM foam_order_items WHERE order_id IN ($placeholders)")->execute($order_ids);
        $db->prepare("DELETE FROM foam_orders WHERE order_id IN ($placeholders)")->execute($order_ids);

        // Clean up empty batches
        foreach ($affected_batches as $batch_id) {
            $count = $db->prepare("SELECT COUNT(*) FROM foam_order_parts WHERE batch_id = ?");
            $count->execute([$batch_id]);
            if ($count->fetchColumn() == 0) {
                $db->prepare("DELETE FROM foam_batches WHERE batch_id = ?")->execute([$batch_id]);
            }
        }

        $_SESSION['success_message'] = count($order_ids) . ' order(s) deleted successfully!';
        redirect('orders.php');
    }
}

// Check if viewing single order
if (isset($_GET['view']) && $_GET['view'] === 'order' && isset($_GET['order_id'])) {
    require_once 'order-detail.php';
    exit;
}

// Filters
$status = $_GET['status'] ?? '';
$search = $_GET['s'] ?? '';
$grade = $_GET['grade'] ?? '';
$source = $_GET['source'] ?? '';

// Build WHERE clause
$where_conditions = ['1=1'];
$params = [];

if ($status) {
    $where_conditions[] = 'status = ?';
    $params[] = $status;
}

if ($search) {
    $where_conditions[] = '(order_number LIKE ? OR customer_name LIKE ? OR email LIKE ?)';
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($grade) {
    $where_conditions[] = 'grades LIKE ?';
    $params[] = "%$grade%";
}

if ($source) {
    $where_conditions[] = 'source = ?';
    $params[] = $source;
}

$where_clause = implode(' AND ', $where_conditions);

// Pagination
$page = max(1, intval($_GET['paged'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_stmt = $db->prepare("SELECT COUNT(*) FROM foam_orders WHERE $where_clause");
$count_stmt->execute($params);
$total = $count_stmt->fetchColumn();

// Get orders
$stmt = $db->prepare("SELECT * FROM foam_orders WHERE $where_clause ORDER BY date_created DESC LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get unique sources for filter
$sources = $db->query("SELECT DISTINCT source FROM foam_orders WHERE source IS NOT NULL AND source != '' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

$total_pages = max(1, ceil($total / $per_page));

// Success message
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success">' . escape_html($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']);
}
?>

<div class="page-header">
    <h1>📦 Orders Management</h1>
    <p>View and manage all foam orders</p>
</div>

<!-- Filters -->
<div class="card">
    <form method="GET" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: end;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 200px;">
            <label>Search</label>
            <input type="search" name="s" value="<?php echo escape_html($search); ?>" placeholder="Order #, Customer, Email...">
        </div>

        <div class="form-group" style="margin: 0; min-width: 150px;">
            <label>Status</label>
            <select name="status">
                <option value="">All Statuses</option>
                <?php
                $statuses = ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'out-for-delivery'];
                foreach ($statuses as $st) {
                    $selected = ($status === $st) ? 'selected' : '';
                    echo '<option value="' . $st . '" ' . $selected . '>' . ucfirst(str_replace('-', ' ', $st)) . '</option>';
                }
                ?>
            </select>
        </div>

        <div class="form-group" style="margin: 0; min-width: 150px;">
            <label>Source</label>
            <select name="source">
                <option value="">All Sources</option>
                <?php foreach ($sources as $src): ?>
                    <option value="<?php echo escape_html($src); ?>" <?php echo ($source === $src) ? 'selected' : ''; ?>>
                        <?php echo escape_html($src); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin: 0; min-width: 150px;">
            <label>Grade</label>
            <input type="text" name="grade" value="<?php echo escape_html($grade); ?>" placeholder="Filter by grade">
        </div>

        <button type="submit" class="btn btn-primary" style="margin: 0;">🔍 Filter</button>

        <?php if ($status || $search || $grade || $source): ?>
            <a href="orders.php" class="btn btn-secondary" style="margin: 0;">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Orders Table -->
<div class="card">
    <div class="card-header">
        <span>Total: <?php echo number_format($total); ?> order(s)</span>
    </div>

    <form method="POST">
        <?php echo csrf_field(); ?>

        <?php if (!empty($orders)): ?>
            <div style="margin-bottom: 12px;">
                <button type="submit" name="bulk_delete" class="btn btn-danger" data-confirm="Delete selected orders and their items/parts?">
                    🗑️ Delete Selected
                </button>
            </div>
        <?php endif; ?>

        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="select-all">
                        </th>
                        <th>Task</th>
                        <th>Source</th>
                        <th>Grades</th>
                        <th>Depths</th>
                        <th>Job Desc</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Value</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px; color: #7f8c8d;">
                                No orders found. Orders will appear here once received via API.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="order_ids[]" value="<?php echo $order['order_id']; ?>" class="order-checkbox">
                                </td>
                                <td>
                                    <strong><?php echo escape_html($order['order_id'] . ' - ' . $order['customer_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge" style="background: #e3f2fd; color: #1976d2;">
                                        <?php echo escape_html($order['source'] ?? 'Unknown'); ?>
                                    </span>
                                </td>
                                <td><?php echo escape_html($order['grades'] ?? ''); ?></td>
                                <td><?php echo escape_html($order['depths'] ?? ''); ?></td>
                                <td><?php echo escape_html(substr($order['job_desc'] ?? '', 0, 50)); ?></td>
                                <td>
                                    <?php
                                    if (isset($order['parts_total']) && $order['parts_total'] > 0) {
                                        $progress = $order['parts_done'] . '/' . $order['parts_total'] . ' (' . $order['progress_pct'] . '%)';
                                        echo escape_html($progress);
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $order['status']; ?>">
                                        <?php echo escape_html(ucfirst(str_replace('-', ' ', $order['status']))); ?>
                                    </span>
                                </td>
                                <td><?php echo escape_html($order['priority'] ?? ''); ?></td>
                                <td><?php echo escape_html($order['currency']); ?> <?php echo number_format($order['total'], 2); ?></td>
                                <td><?php echo date('M j, Y', strtotime($order['date_created'])); ?></td>
                                <td>
                                    <a href="?view=order&order_id=<?php echo $order['order_id']; ?>" class="btn btn-primary" style="padding: 6px 12px; font-size: 13px;">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
    <div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px;">
        <?php if ($page > 1): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['paged' => $page - 1])); ?>" class="btn btn-secondary">« Previous</a>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <?php if ($i == $page): ?>
                <span class="btn btn-primary"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['paged' => $i])); ?>" class="btn btn-secondary"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?<?php echo http_build_query(array_merge($_GET, ['paged' => $page + 1])); ?>" class="btn btn-secondary">Next »</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.order-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});
</script>

<?php require_once 'footer.php'; ?>
