<?php
require_once 'config.php';

// Redirect to dashboard if logged in, otherwise to login
if (is_logged_in()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
