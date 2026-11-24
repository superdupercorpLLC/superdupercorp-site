<?php
require __DIR__ . '/_inc.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!empty($_SESSION['sdc_admin'])) security_log('logout');
    $_SESSION = [];
    session_destroy();
}
redirect(url('admin/login.php'));
