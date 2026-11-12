<?php
require __DIR__ . '/_inc.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $_SESSION = [];
    session_destroy();
}
redirect(url('admin/login.php'));
