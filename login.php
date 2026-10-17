<?php
require __DIR__ . '/includes/bootstrap.php';
$loginRole = 'librarian';
$loginRoute = '/login.php';
$loginLabel = 'Staff access';
$loginDescription = 'Use your authorized librarian account to continue.';
$alternateLoginLabel = 'Administrator sign in';
$alternateLoginRoute = '/admin-login.php';
require __DIR__ . '/includes/login_page.php';
