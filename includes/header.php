<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f5f6">
    <title><?= e($pageTitle) ?> | LibraryFlow</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(url('/assets/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" aria-label="Main navigation">
        <a class="brand" href="<?= e(url('/index.php')) ?>">
            <span class="brand-mark" aria-hidden="true">L</span>
            <span><strong>LibraryFlow</strong><small>Library operations</small></span>
        </a>
        <div class="nav-caption">Workspace</div>
        <nav class="primary-nav">
            <a class="nav-link <?= $activePage === 'search' ? 'is-active' : '' ?>" href="<?= e(url('/search/index.php')) ?>"><span class="nav-icon" aria-hidden="true">01</span>Search</a>
            <a class="nav-link <?= $activePage === 'dashboard' ? 'is-active' : '' ?>" href="<?= e(url('/index.php')) ?>"><span class="nav-icon" aria-hidden="true">02</span>Dashboard</a>
            <a class="nav-link <?= $activePage === 'books' ? 'is-active' : '' ?>" href="<?= e(url('/books/index.php')) ?>"><span class="nav-icon" aria-hidden="true">03</span>Books</a>
            <a class="nav-link <?= $activePage === 'members' ? 'is-active' : '' ?>" href="<?= e(url('/members/index.php')) ?>"><span class="nav-icon" aria-hidden="true">04</span>Members</a>
            <a class="nav-link <?= $activePage === 'issues' ? 'is-active' : '' ?>" href="<?= e(url('/issues/index.php')) ?>"><span class="nav-icon" aria-hidden="true">05</span>Issue / return</a>
            <a class="nav-link <?= $activePage === 'reports' ? 'is-active' : '' ?>" href="<?= e(url('/reports/index.php')) ?>"><span class="nav-icon" aria-hidden="true">06</span>Reports</a>
            <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                <a class="nav-link <?= $activePage === 'users' ? 'is-active' : '' ?>" href="<?= e(url('/admin/users.php')) ?>"><span class="nav-icon" aria-hidden="true">07</span>Staff access</a>
            <?php endif; ?>
            <div class="nav-divider" aria-hidden="true"></div>
            <a class="nav-link <?= $activePage === 'password' ? 'is-active' : '' ?>" href="<?= e(url('/account/password.php')) ?>"><span class="nav-icon" aria-hidden="true">↗</span>Change password</a>
        </nav>
        <div class="sidebar-bottom">
            <?php if ($currentUser): ?>
                <span class="local-indicator"><span></span>Signed in: <?= e($currentUser['display_name']) ?></span>
                <a href="<?= e(url('/privacy.php')) ?>">Privacy</a>
                <a href="<?= e(url('/terms.php')) ?>">Terms</a>
            <?php else: ?>
                <span>Library staff access</span>
                <a href="<?= e(url('/login.php')) ?>">Sign in</a>
                <a href="<?= e(url('/privacy.php')) ?>">Privacy</a>
                <a href="<?= e(url('/terms.php')) ?>">Terms</a>
            <?php endif; ?>
        </div>
    </aside>
    <div class="main-column">
        <header class="topbar">
            <span>Books, members and lending</span>
            <?php if ($currentUser): ?>
                <div class="topbar-account">
                    <span class="topbar-note">Library staff workspace</span>
                    <form method="post" action="<?= e(url('/logout.php')) ?>">
                        <?= csrf_field() ?>
                        <button class="sign-out-button" type="submit">Sign out</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="topbar-account"><a class="topbar-note" href="<?= e(url('/login.php')) ?>">Staff sign in</a><a class="topbar-note" href="<?= e(url('/admin-login.php')) ?>">Administrator sign in</a></div>
            <?php endif; ?>
        </header>
        <main class="content">
            <?php $flashMessage = take_flash(); ?>
            <?php if ($flashMessage): ?>
                <div class="notice notice-<?= e($flashMessage['type'] ?? 'info') ?>" role="status"><?= e($flashMessage['message'] ?? '') ?></div>
            <?php endif; ?>
