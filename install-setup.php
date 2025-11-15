<?php
/**
 * Foam Orders System - Installation & Setup Script
 * Run this once to setup the database and create admin account
 */

// Include config
require_once 'config.php';

$step = $_GET['step'] ?? 'check';
$errors = [];
$success = [];

// Step 1: Check requirements
if ($step === 'check') {
    $php_version_ok = version_compare(PHP_VERSION, '7.4.0', '>=');
    $pdo_available = extension_loaded('pdo') && extension_loaded('pdo_mysql');
    $json_available = function_exists('json_encode');

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Setup - <?php echo APP_NAME; ?></title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 600px;
                width: 100%;
                padding: 40px;
            }
            h1 { color: #2c3e50; margin-bottom: 10px; }
            .check { padding: 12px; margin: 8px 0; border-radius: 8px; }
            .check.ok { background: #d4edda; color: #155724; }
            .check.error { background: #f8d7da; color: #721c24; }
            .btn {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 14px 28px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                margin-top: 20px;
            }
            .btn:hover { opacity: 0.9; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🚀 System Setup</h1>
            <p style="color: #7f8c8d; margin-bottom: 30px;">Checking system requirements...</p>

            <div class="check <?php echo $php_version_ok ? 'ok' : 'error'; ?>">
                <?php echo $php_version_ok ? '✅' : '❌'; ?> PHP Version: <?php echo PHP_VERSION; ?>
                (Required: 7.4 or higher)
            </div>

            <div class="check <?php echo $pdo_available ? 'ok' : 'error'; ?>">
                <?php echo $pdo_available ? '✅' : '❌'; ?> PDO MySQL Extension
            </div>

            <div class="check <?php echo $json_available ? 'ok' : 'error'; ?>">
                <?php echo $json_available ? '✅' : '❌'; ?> JSON Extension
            </div>

            <?php if ($php_version_ok && $pdo_available && $json_available): ?>
                <p style="margin-top: 20px; padding: 16px; background: #d1ecf1; border-radius: 8px; color: #0c5460;">
                    ✅ All requirements met! Ready to proceed with database setup.
                </p>
                <a href="?step=database" class="btn">Continue to Database Setup →</a>
            <?php else: ?>
                <p style="margin-top: 20px; padding: 16px; background: #f8d7da; border-radius: 8px; color: #721c24;">
                    ❌ Please fix the errors above before continuing.
                </p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Step 2: Database setup
if ($step === 'database') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            // Read and execute SQL file
            $sql = file_get_contents(__DIR__ . '/install.sql');

            // Remove CREATE DATABASE and USE statements (we already have connection)
            $sql = preg_replace('/CREATE DATABASE.*?;/i', '', $sql);
            $sql = preg_replace('/USE .*?;/i', '', $sql);

            // Split into individual statements
            $statements = array_filter(array_map('trim', explode(';', $sql)));

            $db = get_db_connection();
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $db->exec($statement);
                }
            }

            $success[] = 'Database tables created successfully!';
            header('Location: ?step=complete');
            exit;

        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Database Setup - <?php echo APP_NAME; ?></title>
        <link rel="stylesheet" href="setup-style.css">
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 600px;
                width: 100%;
                padding: 40px;
            }
            h1 { color: #2c3e50; margin-bottom: 10px; }
            .btn {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 14px 28px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                margin-top: 20px;
                width: 100%;
            }
            .error {
                background: #f8d7da;
                color: #721c24;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 16px;
            }
            .info {
                background: #fff3cd;
                color: #856404;
                padding: 16px;
                border-radius: 8px;
                margin: 16px 0;
                line-height: 1.6;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>💾 Database Setup</h1>
            <p style="color: #7f8c8d; margin-bottom: 20px;">Install database tables</p>

            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="error"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="info">
                <strong>📋 What will be created:</strong><br>
                • User authentication table<br>
                • Orders and order items tables<br>
                • Order parts for batching<br>
                • Batches management table<br>
                • Waste inventory table<br>
                • Allocations table<br>
                • Settings table
            </div>

            <p><strong>Database:</strong> <?php echo DB_NAME; ?> on <?php echo DB_HOST; ?></p>

            <form method="POST">
                <button type="submit" class="btn">🚀 Install Database Tables</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Step 3: Complete
if ($step === 'complete') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Setup Complete - <?php echo APP_NAME; ?></title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .container {
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                max-width: 600px;
                width: 100%;
                padding: 40px;
                text-align: center;
            }
            h1 { color: #27ae60; margin-bottom: 10px; font-size: 36px; }
            .btn {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 14px 28px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                margin-top: 20px;
            }
            .info {
                background: #d4edda;
                color: #155724;
                padding: 20px;
                border-radius: 8px;
                margin: 24px 0;
                text-align: left;
                line-height: 1.8;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>✅ Setup Complete!</h1>
            <p style="color: #7f8c8d; margin-bottom: 20px; font-size: 18px;">
                Your system is ready to use!
            </p>

            <div class="info">
                <strong style="font-size: 18px;">📝 Next Steps:</strong><br><br>
                1. <strong>Create Admin Account:</strong> Go to the login page to create your first admin user<br>
                2. <strong>Configure API:</strong> Set your API secret in Settings<br>
                3. <strong>Test API:</strong> Send a test order from your external system<br>
                4. <strong>Security:</strong> Change the API_SECRET constant in config.php
            </div>

            <div style="background: #fff3cd; color: #856404; padding: 16px; border-radius: 8px; margin: 16px 0;">
                <strong>⚠️ Security Notice:</strong><br>
                Remember to update the database credentials and API secret in <code>config.php</code>!
            </div>

            <a href="login.php" class="btn">🚀 Go to Login Page</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
