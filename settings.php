<?php
$page_title = 'Settings';
require_once 'header.php';

$db = get_db_connection();

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    verify_request();

    $api_secret = $_POST['api_secret'] ?? '';
    if ($api_secret) {
        $stmt = $db->prepare("INSERT INTO foam_settings (setting_key, setting_value) VALUES ('api_secret', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$api_secret, $api_secret]);

        $_SESSION['success_message'] = 'Settings saved successfully!';
        redirect('settings.php');
    }
}

// Handle backup creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    verify_request();

    $tables = ['foam_orders', 'foam_order_items', 'foam_order_parts', 'foam_batches', 'foam_waste', 'foam_allocations', 'foam_settings'];

    $backup = [
        'version' => APP_VERSION,
        'created_at' => date('Y-m-d H:i:s'),
        'tables' => []
    ];

    foreach ($tables as $table) {
        $data = $db->query("SELECT * FROM $table")->fetchAll();
        $backup['tables'][$table] = $data;
    }

    $filename = 'foam-backup-' . date('Y-m-d-H-i-s') . '.json';
    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

// Get current settings
$stmt = $db->query("SELECT * FROM foam_settings WHERE setting_key = 'api_secret'");
$api_secret = $stmt->fetchColumn(1) ?: API_SECRET;

// Get database stats
$stats = [];
$tables = ['foam_orders', 'foam_order_items', 'foam_order_parts', 'foam_batches', 'foam_waste', 'foam_allocations'];
foreach ($tables as $table) {
    $count = $db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    $stats[$table] = $count;
}

if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success">' . escape_html($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']);
}
?>

<div class="page-header">
    <h1>⚙️ Settings</h1>
    <p>Configure system settings and manage data</p>
</div>

<!-- API Configuration -->
<div class="card">
    <div class="card-header">📡 API Configuration</div>

    <form method="POST">
        <?php echo csrf_field(); ?>

        <div class="alert alert-info">
            <strong>API Endpoint:</strong><br>
            <code style="background: #f5f7fa; padding: 8px; border-radius: 4px; display: inline-block; margin-top: 8px;">
                <?php echo BASE_URL; ?>/api.php
            </code>
        </div>

        <div class="form-group">
            <label>Shared API Secret</label>
            <input type="text" name="api_secret" value="<?php echo escape_html($api_secret); ?>" required>
            <p style="font-size: 13px; color: #7f8c8d; margin-top: 6px;">
                This secret key is used to authenticate incoming API requests. Keep it secure!
            </p>
        </div>

        <button type="submit" name="save_settings" class="btn btn-primary">💾 Save Settings</button>
    </form>
</div>

<!-- Database Statistics -->
<div class="card">
    <div class="card-header">📊 Database Statistics</div>

    <div class="grid grid-3">
        <?php foreach ($stats as $table => $count): ?>
            <div style="background: #f8f9fa; padding: 16px; border-radius: 8px; text-align: center;">
                <div style="font-size: 24px; font-weight: 700; color: #667eea;">
                    <?php echo number_format($count); ?>
                </div>
                <div style="font-size: 13px; color: #666; margin-top: 4px;">
                    <?php echo ucwords(str_replace('_', ' ', str_replace('foam_', '', $table))); ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Backup & Restore -->
<div class="grid grid-2">
    <div class="card" style="background: #e8f5e9;">
        <div class="card-header" style="color: #2e7d32;">💾 Create Backup</div>

        <p style="margin-bottom: 16px; line-height: 1.6;">
            Create a complete backup of all system data including orders, batches, waste inventory, and settings.
        </p>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <button type="submit" name="create_backup" class="btn btn-success" data-confirm="Create backup of all data?">
                📦 Download Backup
            </button>
        </form>

        <div style="margin-top: 16px; padding: 12px; background: #fff3e0; border-radius: 6px; font-size: 13px;">
            <strong>💡 Tip:</strong> Regular backups are recommended before major operations!
        </div>
    </div>

    <div class="card" style="background: #fff3e0;">
        <div class="card-header" style="color: #f57f17;">📥 System Information</div>

        <div style="line-height: 2;">
            <strong>Application Version:</strong> <?php echo APP_VERSION; ?><br>
            <strong>PHP Version:</strong> <?php echo phpversion(); ?><br>
            <strong>Database:</strong> <?php echo DB_NAME; ?><br>
            <strong>Total Records:</strong> <?php echo number_format(array_sum($stats)); ?><br>
        </div>

        <div style="margin-top: 16px;">
            <a href="install-setup.php" class="btn btn-warning">
                🔧 Re-run Setup
            </a>
        </div>
    </div>
</div>

<!-- User Management -->
<div class="card">
    <div class="card-header">👥 User Management</div>

    <div style="line-height: 1.8;">
        <p><strong>Current User:</strong> <?php echo escape_html(get_current_user()['username']); ?></p>
        <p style="color: #7f8c8d; font-size: 14px;">
            To add more users, modify the foam_users table in the database or use the setup page.
        </p>
    </div>
</div>

<!-- Danger Zone -->
<div class="card" style="background: #ffebee; border: 2px solid #e74c3c;">
    <div class="card-header" style="color: #c0392b;">⚠️ Danger Zone</div>

    <div style="padding: 16px; background: #fff; border-radius: 8px; margin-top: 12px;">
        <h4 style="margin: 0 0 8px; color: #e74c3c;">Clear All Data</h4>
        <p style="margin: 0 0 16px; color: #7f8c8d; font-size: 14px;">
            This action will permanently delete all orders, batches, waste data, and allocations. This cannot be undone!
        </p>

        <form method="POST" action="clear-data.php" style="display: inline;">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-danger" data-confirm="Are you ABSOLUTELY sure? This will delete ALL data permanently!">
                💀 Clear All Data
            </button>
        </form>
    </div>
</div>

<?php require_once 'footer.php'; ?>
