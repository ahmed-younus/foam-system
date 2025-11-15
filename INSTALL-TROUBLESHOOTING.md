# 🚨 QUICK FIX for 403 Forbidden Error

If you're getting **403 Forbidden** error on `install-setup.php`:

## Solution:

The `.htaccess` file was protecting sensitive files. Follow these steps:

### Method 1: Rename .htaccess (Easiest)
1. In Hostinger File Manager, rename `.htaccess` to `.htaccess.backup`
2. Complete the installation at `yourdomain.com/install-setup.php`
3. After installation, rename it back to `.htaccess`

### Method 2: Use the Updated .htaccess
1. The `.htaccess` in the repository now has security commented out
2. After installation is complete:
   - Copy content from `.htaccess.secure`
   - Replace your `.htaccess` content
3. Delete `install-setup.php` from server

### Method 3: Temporarily Delete .htaccess
1. Delete `.htaccess` file temporarily
2. Complete installation
3. Upload `.htaccess.secure` and rename to `.htaccess`

## After Installation:
1. ✅ Delete or rename `install-setup.php`
2. ✅ Use `.htaccess.secure` content for production
3. ✅ Change `API_SECRET` in `config.php`

---

# Also Check These:

## File Permissions (in Hostinger):
- PHP files: **644**
- Directories: **755**
- config.php: **644**

## If Still Getting Error:
Check `config.php` database settings:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
```

## Common Issues:
- ❌ Wrong database credentials → Update config.php
- ❌ .htaccess blocking files → Rename temporarily
- ❌ Wrong file permissions → Set 644 for .php files
- ❌ PHP version < 7.4 → Check PHP version in Hostinger

---

Need help? Check main README.md for full documentation.
