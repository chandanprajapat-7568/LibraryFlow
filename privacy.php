<?php
require __DIR__ . '/includes/bootstrap.php';
render_header('Privacy', '');
?>
<div class="page-heading"><div><div class="eyebrow">Information handling</div><h1>Privacy overview</h1><p class="page-intro">What this LibraryFlow application stores and what the operating institution must define before deployment.</p></div></div>
<article class="panel prose-panel">
    <h2>Information stored</h2>
    <p>The configured database can store book catalogue details, member codes and names, course and semester, optional phone and email, issue and return dates, and staff account details. Passwords are stored as password hashes. The application also stores a short-lived session cookie and hashed login-attempt keys.</p>
    <h2>Use and access</h2>
    <p>LibraryFlow has no analytics or advertising integration. Staff pages require an account; administrators can create, deactivate and assign staff roles. The institution operating this installation is responsible for deciding which data is necessary, who may access it, and how long records and backups are retained.</p>
    <h2>Deployment details to complete</h2>
    <p>Before public or institutional use, the operator must add the responsible institution name, hosting and database location, privacy contact, purposes, retention schedule, backup handling and a process for data requests. This overview is not a substitute for an institution-approved privacy notice.</p>
    <h2>Questions</h2>
    <p>For this deployment, contact the library or institution that operates the service. Add its verified contact details before making the site available to staff or students.</p>
</article>
<?php render_footer(); ?>
