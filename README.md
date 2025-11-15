# 🔷 Foam Orders Management System

Modern standalone PHP application for managing foam orders, production batches, waste inventory, and dispatch operations.

**Version:** 2.0.0
**Converted from:** WordPress Plugin
**Tech Stack:** PHP 7.4+, MySQL, PDO, Session-based Authentication

---

## 📋 Features

- ✅ **Order Management** - Complete order tracking with customer details, items, and parts
- ✅ **REST API** - Secure API endpoint for receiving orders from external systems
- ✅ **Batch Management** - Create and manage production batches
- ✅ **Waste Inventory** - Track foam waste and offcuts
- ✅ **Auto Planner** - Plan production and match waste to orders
- ✅ **Dispatch System** - Track orders through dispatch and delivery
- ✅ **Modern UI** - Clean, responsive interface with gradient design
- ✅ **Backup/Restore** - Full database backup and restore functionality
- ✅ **Authentication** - Secure session-based login system
- ✅ **Multi-source Tracking** - Track orders from different sources/stores

---

## 🚀 Installation on Hostinger

### Prerequisites

- PHP 7.4 or higher
- MySQL database
- Web hosting with PHP support (Hostinger recommended)

### Step-by-Step Installation

1. **Upload Files**
   ```bash
   # Upload all files to your Hostinger public_html directory
   # You can use File Manager or FTP
   ```

2. **Create Database**
   - Log into Hostinger control panel
   - Go to MySQL Databases
   - Create a new database (e.g., `foam_orders`)
   - Note down: database name, username, password

3. **Configure Database Connection**
   - Edit `config.php`
   - Update these lines:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_NAME', 'your_database_name');
     define('DB_USER', 'your_database_user');
     define('DB_PASS', 'your_database_password');
     ```

4. **Run Setup Script**
   - Navigate to: `https://yourdomain.com/install-setup.php`
   - Follow the on-screen instructions
   - The setup will create all necessary database tables

5. **Create Admin Account**
   - After setup, go to: `https://yourdomain.com/login.php`
   - Create your first admin account

6. **Configure API Secret**
   - Login and go to Settings
   - Update the API secret key (used for external API calls)

7. **Security (Important!)**
   - Delete or rename `install-setup.php` after installation
   - Update `API_SECRET` in `config.php` to a strong random string
   - Ensure `config.php` is not publicly accessible

---

## 🔐 Security Configuration

### Generate Strong API Secret

```bash
# Generate a random API secret
openssl rand -hex 32
```

Update in `config.php`:
```php
define('API_SECRET', 'your-generated-secret-here');
```

### .htaccess Protection (Recommended)

Create `.htaccess` in your root directory:

```apache
# Protect sensitive files
<FilesMatch "(config\.php|install\.sql|install-setup\.php)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Enable clean URLs (optional)
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
```

---

## 📡 API Usage

### Endpoint

```
POST https://yourdomain.com/api.php
```

### Authentication

The API uses HMAC-SHA256 signature authentication:

**Headers Required:**
- `X-FOD-Signature`: HMAC signature
- `X-FOD-Timestamp`: Current Unix timestamp

**Signature Calculation:**
```php
$timestamp = time();
$payload = json_encode($order_data);
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $api_secret);
```

### Example Request (PHP)

```php
<?php
$api_url = 'https://yourdomain.com/api.php';
$api_secret = 'your-api-secret';

$order_data = [
    'order_id' => 12345,
    'order_number' => 'ORD-12345',
    'status' => 'processing',
    'customer_name' => 'John Doe',
    'email' => 'john@example.com',
    'phone' => '+44 123 456 7890',
    'total' => 299.99,
    'currency' => 'GBP',
    'store' => 'Main Store',
    'items' => [
        [
            'name' => 'Foam Cushion',
            'qty' => 2,
            'sku' => 'FOAM-001',
            'line_total' => 149.99,
            'meta' => [
                'Standard Foams' => 'CMHR 35/130',
                'Side C (Depth)' => '10',
                'Measure your cushions' => 'CM'
            ]
        ]
    ]
];

$timestamp = time();
$payload = json_encode($order_data);
$signature = hash_hmac('sha256', $timestamp . '.' . $payload, $api_secret);

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-FOD-Signature: ' . $signature,
    'X-FOD-Timestamp: ' . $timestamp
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
```

### Response Format

**Success:**
```json
{
    "ok": true,
    "parts_created": 2
}
```

**Error:**
```json
{
    "ok": false,
    "msg": "Invalid signature"
}
```

---

## 📂 File Structure

```
foam-system/
├── config.php              # Database & app configuration
├── login.php               # Login page
├── logout.php              # Logout handler
├── index.php               # Entry point (redirects to login/dashboard)
├── header.php              # Common header template
├── footer.php              # Common footer template
├── dashboard.php           # Main dashboard
├── orders.php              # Orders listing
├── order-detail.php        # Single order view
├── batches.php             # Batches management
├── batch-detail.php        # Batch details
├── waste.php               # Waste inventory
├── planner.php             # Auto planner
├── dispatch.php            # Dispatch management
├── settings.php            # System settings
├── api.php                 # REST API endpoint
├── install-setup.php       # Installation script
├── install.sql             # Database schema
└── README.md               # This file
```

---

## 🎨 Customization

### Change Color Scheme

Edit gradient colors in `header.php`:

```css
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
```

### Add Custom Fields

1. Add columns to database in `install.sql`
2. Update API parsing in `api.php`
3. Display in order detail in `order-detail.php`

---

## 🔧 Maintenance

### Database Backup

1. Login to the application
2. Go to Settings
3. Click "Download Backup"
4. Save the JSON file securely

### Database Restore

1. Go to Settings
2. Upload your backup JSON file
3. Confirm restoration

### Clear All Data

⚠️ **Danger Zone** - Only if you need to start fresh:

1. Go to Settings
2. Scroll to "Danger Zone"
3. Follow the confirmation steps

---

## 🐛 Troubleshooting

### Can't Login / No Users

1. Go to `https://yourdomain.com/login.php`
2. The system will detect no users and show setup form
3. Create your admin account

### Database Connection Error

- Check `config.php` has correct credentials
- Verify database exists in Hostinger panel
- Check MySQL service is running

### API Returns 401 Unauthorized

- Verify API secret matches between sender and receiver
- Check timestamp is within 5 minutes
- Ensure signature is calculated correctly

### Orders Not Showing

- Check API is sending data correctly
- Verify database tables exist (`foam_orders`, `foam_order_items`, etc.)
- Check PHP error logs

---

## 📊 Database Tables

- `foam_users` - User accounts
- `foam_orders` - Main orders
- `foam_order_items` - Order line items
- `foam_order_parts` - Parts for batching
- `foam_batches` - Production batches
- `foam_waste` - Waste inventory
- `foam_allocations` - Waste to order allocations
- `foam_settings` - System settings

---

## 🤝 Support

For issues or questions:
1. Check this README
2. Review error logs in Hostinger control panel
3. Check database connection and structure

---

## 📝 License

Proprietary - Foam Superstore

---

## ✨ Version History

**v2.0.0** - Standalone PHP Application
- Converted from WordPress plugin
- Modern responsive UI
- Enhanced security
- Full API support
- Backup/restore functionality

**v1.0.7** - WordPress Plugin (Original)
- WordPress plugin version

---

**Made with ❤️ for Foam Superstore**
