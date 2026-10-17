<?php
require __DIR__ . '/includes/bootstrap.php';
$loginRole = 'admin';
$loginRoute = '/admin-login.php';
$loginLabel = 'Administrator access';
$loginDescription = 'Use your administrator account to manage the library and staff access.';
$alternateLoginLabel = 'Staff sign in';
$alternateLoginRoute = '/login.php';
require __DIR__ . '/includes/login_page.php';
