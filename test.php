<?php
/**
 * Simple Diagnostic Test File
 * Upload this to check PHP and server status
 */

echo "<h1>System Diagnostics</h1>";
echo "<style>body{font-family:Arial;padding:20px;} .ok{color:green;} .error{color:red;} .info{color:blue;}</style>";

// 1. PHP Version
echo "<h2>1. PHP Version</h2>";
echo "<p class='ok'>✅ PHP Version: " . PHP_VERSION . "</p>";
if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    echo "<p class='ok'>✅ Version OK (Required: 7.4+)</p>";
} else {
    echo "<p class='error'>❌ PHP version too old! Need 7.4 or higher</p>";
}

// 2. Required Extensions
echo "<h2>2. Required PHP Extensions</h2>";
$extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'session'];
foreach ($extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<p class='ok'>✅ $ext - Loaded</p>";
    } else {
        echo "<p class='error'>❌ $ext - NOT LOADED!</p>";
    }
}

// 3. Database Connection Test
echo "<h2>3. Database Connection Test</h2>";
echo "<p class='info'>Checking if we can connect to database...</p>";

// Get database credentials from user
$db_host = 'localhost';
$db_name = 'YOUR_DB_NAME'; // CHANGE THIS
$db_user = 'YOUR_DB_USER'; // CHANGE THIS
$db_pass = 'YOUR_DB_PASS'; // CHANGE THIS

echo "<p class='info'>Host: $db_host<br>Database: $db_name<br>User: $db_user</p>";

try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass);
    echo "<p class='ok'>✅ Database connection successful!</p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p class='error'>Please check your database credentials in config.php</p>";
}

// 4. File Paths
echo "<h2>4. File Paths</h2>";
echo "<p class='info'>Current Directory: " . __DIR__ . "</p>";
echo "<p class='info'>Script: " . __FILE__ . "</p>";

// 5. Check if config.php exists
echo "<h2>5. Config File Check</h2>";
if (file_exists(__DIR__ . '/config.php')) {
    echo "<p class='ok'>✅ config.php exists</p>";
} else {
    echo "<p class='error'>❌ config.php NOT FOUND!</p>";
}

// 6. Session Test
echo "<h2>6. Session Test</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<p class='ok'>✅ Sessions working</p>";
} else {
    echo "<p class='error'>❌ Sessions NOT working</p>";
}

// 7. Error Reporting
echo "<h2>7. PHP Error Reporting</h2>";
echo "<p class='info'>Display Errors: " . (ini_get('display_errors') ? 'ON' : 'OFF') . "</p>";
echo "<p class='info'>Error Reporting Level: " . error_reporting() . "</p>";

echo "<hr><h2>✅ Diagnostic Complete</h2>";
echo "<p>If everything above shows ✅, your server is ready.</p>";
echo "<p>If you see ❌ errors, fix those issues first.</p>";
?>
