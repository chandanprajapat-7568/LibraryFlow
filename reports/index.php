<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$query = query_string('q');
$query = limit_text($query, 100);
$status = query_string('status') ?: 'all';
if (!in_array($status, ['all', 'open', 'returned', 'overdue'], true)) $status = 'all';

$today = date('Y-m-d');
$summaryStatement = $conn->prepare(
    'SELECT
        (SELECT COUNT(*) FROM books) AS title_count,
        (SELECT COALESCE(SUM(total_copies), 0) FROM books) AS total_copies,
        (SELECT COUNT(*) FROM issue_records WHERE return_date IS NULL) AS issued_copies,
        (SELECT COUNT(*) FROM members) AS member_count,
        (SELECT COUNT(*) FROM issue_records WHERE return_date IS NULL AND due_date < ?) AS overdue_count'
);
$summaryStatement->bind_param('s', $today);
$summaryStatement->execute();
$summary = $summaryStatement->get_result()->fetch_assoc();
$summary['available_copies'] = (int) $summary['total_copies'] - (int) $summary['issued_copies'];

$inventorySql = 'SELECT b.book_id, b.title, b.author, b.category, b.total_copies,
                        (SELECT COUNT(*) FROM issue_records ir WHERE ir.book_id = b.book_id AND ir.return_date IS NULL) AS issued_copies
                 FROM books b';
$inventoryStatement = null;
if ($query !== '') {
    if (ctype_digit($query)) {
        $inventoryStatement = $conn->prepare($inventorySql . ' WHERE b.book_id = ? OR b.title LIKE ? OR b.author LIKE ? ORDER BY b.title');
        $id = (int) $query;
        $pattern = '%' . $query . '%';
        $inventoryStatement->bind_param('iss', $id, $pattern, $pattern);
    } else {
        $inventoryStatement = $conn->prepare($inventorySql . ' WHERE b.title LIKE ? OR b.author LIKE ? ORDER BY b.title');
        $pattern = '%' . $query . '%';
        $inventoryStatement->bind_param('ss', $pattern, $pattern);
    }
    $inventoryStatement->execute();
    $inventory = $inventoryStatement->get_result();
} else {
    $inventory = $conn->query($inventorySql . ' ORDER BY b.title');
}

$issueSql = 'SELECT ir.issue_id, ir.issue_date, ir.due_date, ir.return_date, b.title, m.member_code, m.full_name
             FROM issue_records ir
             INNER JOIN books b ON b.book_id = ir.book_id
             INNER JOIN members m ON m.member_id = ir.member_id
             WHERE 1 = 1';
switch ($status) {
    case 'open': $issueSql .= ' AND ir.return_date IS NULL'; break;
    case 'returned': $issueSql .= ' AND ir.return_date IS NOT NULL'; break;
    case 'overdue': $issueSql .= ' AND ir.return_date IS NULL AND ir.due_date < ?'; break;
}
$issueSql .= ' ORDER BY (ir.return_date IS NULL) DESC, ir.due_date ASC, ir.issue_id DESC';
if ($status === 'overdue') {
    $issueStatement = $conn->prepare($issueSql);
    $issueStatement->bind_param('s', $today);
    $issueStatement->execute();
    $issueRows = $issueStatement->get_result();
} else {
    $issueRows = $conn->query($issueSql);
}

render_header('Reports', 'reports');
?>
<div class="page-heading">
    <div><div class="eyebrow">Library reporting</div><h1>Reports and summary</h1><p class="page-intro">Inventory and lending figures are calculated from current database records.</p></div>
    <button class="button button-secondary print-button" type="button" data-print>Print report</button>
</div>
<section class="stat-grid report-stats" aria-label="Report totals">
    <article class="stat-card"><span class="stat-label">Book titles</span><strong><?= number_format((int) $summary['title_count']) ?></strong></article>
    <article class="stat-card"><span class="stat-label">Total copies</span><strong><?= number_format((int) $summary['total_copies']) ?></strong></article>
    <article class="stat-card"><span class="stat-label">Issued</span><strong><?= number_format((int) $summary['issued_copies']) ?></strong></article>
    <article class="stat-card"><span class="stat-label">Available</span><strong><?= number_format((int) $summary['available_copies']) ?></strong></article>
    <article class="stat-card"><span class="stat-label">Members</span><strong><?= number_format((int) $summary['member_count']) ?></strong></article>
    <article class="stat-card <?= (int) $summary['overdue_count'] > 0 ? 'stat-card-attention' : '' ?>"><span class="stat-label">Overdue loans</span><strong><?= number_format((int) $summary['overdue_count']) ?></strong></article>
</section>

<section class="panel report-panel">
    <div class="panel-heading"><div><div class="eyebrow">Inventory</div><h2>Copy summary by title</h2></div><form class="compact-search" method="get" action="<?= e(url('/reports/index.php')) ?>"><label class="sr-only" for="inventory-search">Search inventory report</label><input id="inventory-search" type="search" name="q" value="<?= e($query) ?>" placeholder="Title, author or ID"><input type="hidden" name="status" value="<?= e($status) ?>"><button class="button button-small button-secondary" type="submit">Search</button></form></div>
    <div class="table-meta"><span><?= number_format($inventory->num_rows) ?> title<?= $inventory->num_rows === 1 ? '' : 's' ?></span><?php if ($query !== ''): ?><span>Matching “<?= e($query) ?>”</span><a class="text-link" href="<?= e(url('/reports/index.php?status=' . rawurlencode($status))) ?>">Clear search</a><?php endif; ?></div>
    <?php if ($inventory->num_rows === 0): ?><div class="empty-state"><h3>No inventory rows match</h3><p>Clear the search or add a book to the catalogue.</p></div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Book</th><th>Author</th><th>Category</th><th>Total</th><th>Issued</th><th>Available</th></tr></thead><tbody>
        <?php $inventoryTotal = 0; $inventoryIssued = 0; while ($book = $inventory->fetch_assoc()): $available = (int) $book['total_copies'] - (int) $book['issued_copies']; $inventoryTotal += (int) $book['total_copies']; $inventoryIssued += (int) $book['issued_copies']; ?>
            <tr><td><span class="cell-strong"><?= e($book['title']) ?></span><span class="cell-sub">Book ID <?= (int) $book['book_id'] ?></span></td><td><?= e($book['author']) ?></td><td><?= e($book['category'] ?: 'Not supplied') ?></td><td><?= (int) $book['total_copies'] ?></td><td><?= (int) $book['issued_copies'] ?></td><td><?= $available ?></td></tr>
        <?php endwhile; ?>
        </tbody><tfoot><tr><th colspan="3">Filtered total</th><th><?= $inventoryTotal ?></th><th><?= $inventoryIssued ?></th><th><?= $inventoryTotal - $inventoryIssued ?></th></tr></tfoot></table></div>
    <?php endif; ?>
</section>

<section class="panel report-panel">
    <div class="panel-heading"><div><div class="eyebrow">Lending history</div><h2>Issue register</h2></div>
        <form class="compact-search" method="get" action="<?= e(url('/reports/index.php')) ?>"><label class="sr-only" for="report-status">Filter issue records</label><select id="report-status" name="status"><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option><option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option><option value="returned" <?= $status === 'returned' ? 'selected' : '' ?>>Returned</option><option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option></select><input type="hidden" name="q" value="<?= e($query) ?>"><button class="button button-small button-secondary" type="submit">Apply</button></form>
    </div>
    <div class="table-meta"><span><?= number_format($issueRows->num_rows) ?> issue record<?= $issueRows->num_rows === 1 ? '' : 's' ?></span></div>
    <?php if ($issueRows->num_rows === 0): ?><div class="empty-state"><h3>No issue records</h3><p>There are no loans for the selected status.</p></div><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Issue</th><th>Book</th><th>Member</th><th>Issue date</th><th>Due date</th><th>Return date</th><th>Status</th></tr></thead><tbody>
        <?php while ($issue = $issueRows->fetch_assoc()): $overdue = !$issue['return_date'] && $issue['due_date'] < date('Y-m-d'); $label = $issue['return_date'] ? 'Returned' : ($overdue ? 'Overdue' : 'Open'); $tone = $issue['return_date'] ? 'neutral' : ($overdue ? 'danger' : 'info'); ?>
            <tr><td>#<?= (int) $issue['issue_id'] ?></td><td><?= e($issue['title']) ?></td><td><span class="cell-strong"><?= e($issue['full_name']) ?></span><span class="cell-sub"><?= e($issue['member_code']) ?></span></td><td><?= e(display_date($issue['issue_date'])) ?></td><td><?= e(display_date($issue['due_date'])) ?></td><td><?= e(display_date($issue['return_date'])) ?></td><td><?= status_badge($label, $tone) ?></td></tr>
        <?php endwhile; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
