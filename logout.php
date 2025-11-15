<?php
require_once 'config.php';

// Destroy session and redirect to login
session_destroy();
redirect('login.php');
