<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$allowedStatuses = ['all', 'open', 'returned', 'overdue'];
$status = query_string('status') ?: 'all';
if (!in_array($status, $allowedStatuses, true)) $status = 'all';
$query = query_string('q');
$query = limit_text($query, 100);
$sql = 'SELECT ir.issue_id, ir.issue_date, ir.due_date, ir.return_date,
               b.book_id, b.title, m.member_id, m.member_code, m.full_name
        FROM issue_records ir
        INNER JOIN books b ON b.book_id = ir.book_id
        INNER JOIN members m ON m.member_id = ir.member_id
        WHERE 1 = 1';
$types = '';
$params = [];
switch ($status) {
    case 'open': $sql .= ' AND ir.return_date IS NULL'; break;
    case 'returned': $sql .= ' AND ir.return_date IS NOT NULL'; break;
    case 'overdue':
        $sql .= ' AND ir.return_date IS NULL AND ir.due_date < ?';
        $types .= 's';
        $params[] = date('Y-m-d');
        break;
}
if ($query !== '') {
    $sql .= ' AND (ir.issue_id = ? OR b.title LIKE ? OR m.full_name LIKE ? OR m.member_code LIKE ?)';
    $pattern = '%' . $query . '%';
    $types .= 'isss';
    $params[] = ctype_digit($query) ? (int) $query : 0;
    $params[] = $pattern;
    $params[] = $pattern;
    $params[] = $pattern;
}
$sql .= ' ORDER BY (ir.return_date IS NULL) DESC, ir.due_date ASC, ir.issue_id DESC';
$statement = $conn->prepare($sql);
bind_values($statement, $types, $params);
$statement->execute();
$issues = $statement->get_result();

render_header('Issue and return', 'issues');
?>
<div class="page-heading">
    <div><div class="eyebrow">Lending register</div><h1>Issue / return</h1><p class="page-intro">Track active, returned and overdue loans. A copy becomes available when its return is recorded.</p></div>
    <a class="button button-primary" href="<?= e(url('/issues/create.php')) ?>">Issue a book</a>
</div>
<section class="panel">
    <form class="filter-toolbar" method="get" action="<?= e(url('/issues/index.php')) ?>">
        <div class="toolbar-search-field"><label class="sr-only" for="issue-search">Search the issue register</label><input id="issue-search" type="search" name="q" value="<?= e($query) ?>" placeholder="Search book, member or issue ID"></div>
        <div class="filter-group"><label for="status">Status</label><select id="status" name="status"><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All records</option><option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option><option value="returned" <?= $status === 'returned' ? 'selected' : '' ?>>Returned</option><option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Overdue</option></select></div>
        <button class="button button-secondary" type="submit">Apply</button>
        <?php if ($query !== '' || $status !== 'all'): ?><a class="text-link" href="<?= e(url('/issues/index.php')) ?>">Clear filters</a><?php endif; ?>
    </form>
    <div class="table-meta"><span><?= number_format($issues->num_rows) ?> record<?= $issues->num_rows === 1 ? '' : 's' ?></span></div>
    <?php if ($issues->num_rows === 0): ?>
        <div class="empty-state"><h2>No matching issue records</h2><p>Try another search or status filter, or create the first issue.</p><a class="button button-primary" href="<?= e(url('/issues/create.php')) ?>">Issue a book</a></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Issue</th><th>Book</th><th>Member</th><th>Issue date</th><th>Due date</th><th>Return date</th><th>Status</th><th class="actions-heading">Action</th></tr></thead>
            <tbody>
            <?php while ($issue = $issues->fetch_assoc()): ?>
                <?php $overdue = !$issue['return_date'] && $issue['due_date'] < date('Y-m-d'); $label = $issue['return_date'] ? 'Returned' : ($overdue ? 'Overdue' : 'Open'); $tone = $issue['return_date'] ? 'neutral' : ($overdue ? 'danger' : 'info'); ?>
                <tr>
                    <td class="cell-strong">#<?= (int) $issue['issue_id'] ?></td>
                    <td><span class="cell-strong"><?= e($issue['title']) ?></span><span class="cell-sub">Book ID <?= (int) $issue['book_id'] ?></span></td>
                    <td><span class="cell-strong"><?= e($issue['full_name']) ?></span><span class="cell-sub"><?= e($issue['member_code']) ?></span></td>
                    <td><?= e(display_date($issue['issue_date'])) ?></td>
                    <td><?= e(display_date($issue['due_date'])) ?></td>
                    <td><?= e(display_date($issue['return_date'])) ?></td>
                    <td><?= status_badge($label, $tone) ?></td>
                    <td><?php if (!$issue['return_date']): ?><form class="inline-form" method="post" action="<?= e(url('/issues/return.php')) ?>" data-confirm="Record this book as returned today?"><?= csrf_field() ?><input type="hidden" name="issue_id" value="<?= (int) $issue['issue_id'] ?>"><button class="button button-small button-secondary" type="submit">Record return</button></form><?php else: ?><span class="muted">Complete</span><?php endif; ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
