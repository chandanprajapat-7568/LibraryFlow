<?php
require __DIR__ . '/includes/bootstrap.php';
$conn = db();

$today = date('Y-m-d');
$summaryStatement = $conn->prepare(
    'SELECT
        (SELECT COUNT(*) FROM books) AS title_count,
        (SELECT COALESCE(SUM(total_copies), 0) FROM books) AS copy_count,
        (SELECT COUNT(*) FROM issue_records WHERE return_date IS NULL) AS issued_count,
        (SELECT COUNT(*) FROM members) AS member_count,
        (SELECT COUNT(*) FROM issue_records WHERE return_date IS NULL AND due_date < ?) AS overdue_count'
);
$summaryStatement->bind_param('s', $today);
$summaryStatement->execute();
$summary = $summaryStatement->get_result()->fetch_assoc();
$summary['available_count'] = (int) $summary['copy_count'] - (int) $summary['issued_count'];

$recent = $conn->query(
    'SELECT ir.issue_id, ir.issue_date, ir.due_date, ir.return_date,
            b.title, m.full_name
     FROM issue_records ir
     INNER JOIN books b ON b.book_id = ir.book_id
     INNER JOIN members m ON m.member_id = ir.member_id
     ORDER BY ir.created_at DESC, ir.issue_id DESC
     LIMIT 5'
);

render_header('Dashboard', 'dashboard');
?>
<div class="page-heading">
    <div>
        <div class="eyebrow">Overview</div>
        <h1>Library overview</h1>
        <p class="page-intro">Current catalogue and lending activity from the library database.</p>
    </div>
    <div class="heading-actions">
        <a class="button button-secondary" href="<?= e(url('/books/index.php')) ?>">Browse catalogue</a>
        <a class="button button-primary" href="<?= e(url('/issues/create.php')) ?>">Issue a book</a>
    </div>
</div>

<section class="stat-grid" aria-label="Library counts">
    <article class="stat-card"><span class="stat-label">Book titles</span><strong><?= number_format((int) $summary['title_count']) ?></strong><span class="stat-detail">Catalogue records</span></article>
    <article class="stat-card"><span class="stat-label">Total copies</span><strong><?= number_format((int) $summary['copy_count']) ?></strong><span class="stat-detail">Physical copies recorded</span></article>
    <article class="stat-card"><span class="stat-label">Issued copies</span><strong><?= number_format((int) $summary['issued_count']) ?></strong><span class="stat-detail">Currently on loan</span></article>
    <article class="stat-card"><span class="stat-label">Available copies</span><strong><?= number_format((int) $summary['available_count']) ?></strong><span class="stat-detail">Ready to issue</span></article>
    <article class="stat-card"><span class="stat-label">Members</span><strong><?= number_format((int) $summary['member_count']) ?></strong><span class="stat-detail">Registered borrowers</span></article>
    <article class="stat-card <?= (int) $summary['overdue_count'] > 0 ? 'stat-card-attention' : '' ?>"><span class="stat-label">Overdue loans</span><strong><?= number_format((int) $summary['overdue_count']) ?></strong><span class="stat-detail">Open and past due date</span></article>
</section>

<section class="panel">
    <div class="panel-heading">
        <div><div class="eyebrow">Recent activity</div><h2>Latest loan records</h2></div>
        <a class="text-link" href="<?= e(url('/issues/index.php')) ?>">View issue register</a>
    </div>
    <?php if ($recent->num_rows === 0): ?>
        <div class="empty-state"><h3>No loan records yet</h3><p>Add a member and a book, then record the first issue.</p><a class="button button-primary" href="<?= e(url('/issues/create.php')) ?>">Create an issue</a></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Book</th><th>Member</th><th>Issue date</th><th>Due date</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php while ($row = $recent->fetch_assoc()): ?>
                <?php
                $isOverdue = !$row['return_date'] && $row['due_date'] < date('Y-m-d');
                $label = $row['return_date'] ? 'Returned' : ($isOverdue ? 'Overdue' : 'Open');
                $tone = $row['return_date'] ? 'neutral' : ($isOverdue ? 'danger' : 'info');
                ?>
                <tr>
                    <td class="cell-strong"><?= e($row['title']) ?></td>
                    <td><?= e($row['full_name']) ?></td>
                    <td><?= e(display_date($row['issue_date'])) ?></td>
                    <td><?= e(display_date($row['due_date'])) ?></td>
                    <td><?= status_badge($label, $tone) ?></td>
                    <td><a class="text-link" href="<?= e(url('/issues/index.php')) ?>">Open register</a></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
