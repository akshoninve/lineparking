<?php
/**
 * admin/logout.php
 */
require_once __DIR__ . '/../includes/admin-auth.php';
adminLogout();
header('Location: login.php');
exit;
