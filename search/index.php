<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$query = query_string('q');
$query = limit_text($query, 100);
$kind = query_string('type') ?: 'all';
if (!in_array($kind, ['all', 'books', 'members'], true)) $kind = 'all';
$books = null;
$members = null;

if ($query !== '' && in_array($kind, ['all', 'books'], true)) {
    $bookSql = 'SELECT b.book_id, b.title, b.author, b.isbn, b.total_copies,
                       (SELECT COUNT(*) FROM issue_records ir WHERE ir.book_id = b.book_id AND ir.return_date IS NULL) AS issued_copies
                FROM books b';
    if (ctype_digit($query)) {
        $bookStatement = $conn->prepare($bookSql . ' WHERE b.book_id = ? OR b.title LIKE ? OR b.author LIKE ? ORDER BY b.title LIMIT 50');
        $id = (int) $query;
        $pattern = '%' . $query . '%';
        $bookStatement->bind_param('iss', $id, $pattern, $pattern);
    } else {
        $bookStatement = $conn->prepare($bookSql . ' WHERE b.title LIKE ? OR b.author LIKE ? ORDER BY b.title LIMIT 50');
        $pattern = '%' . $query . '%';
        $bookStatement->bind_param('ss', $pattern, $pattern);
    }
    $bookStatement->execute();
    $books = $bookStatement->get_result();
}
if ($query !== '' && in_array($kind, ['all', 'members'], true)) {
    $memberSql = 'SELECT member_id, member_code, full_name, course, email FROM members';
    if (ctype_digit($query)) {
        $memberStatement = $conn->prepare($memberSql . ' WHERE member_id = ? OR member_code LIKE ? OR full_name LIKE ? ORDER BY full_name LIMIT 50');
        $id = (int) $query;
        $pattern = '%' . $query . '%';
        $memberStatement->bind_param('iss', $id, $pattern, $pattern);
    } else {
        $memberStatement = $conn->prepare($memberSql . ' WHERE member_code LIKE ? OR full_name LIKE ? ORDER BY full_name LIMIT 50');
        $pattern = '%' . $query . '%';
        $memberStatement->bind_param('ss', $pattern, $pattern);
    }
    $memberStatement->execute();
    $members = $memberStatement->get_result();
}
$resultCount = ($books ? $books->num_rows : 0) + ($members ? $members->num_rows : 0);
render_header('Search', 'search');
?>
<div class="page-heading"><div><div class="eyebrow">Find a record</div><h1>Search</h1><p class="page-intro">Search the catalogue by title, author or book ID, and the member register by name or code.</p></div></div>
<section class="panel search-panel">
    <form class="search-page-form" method="get" action="<?= e(url('/search/index.php')) ?>">
        <div class="form-field"><label for="q">Search term</label><input id="q" name="q" type="search" value="<?= e($query) ?>" placeholder="Enter a title, author, member or ID" autofocus></div>
        <div class="form-field"><label for="type">Search in</label><select id="type" name="type"><option value="all" <?= $kind === 'all' ? 'selected' : '' ?>>Books and members</option><option value="books" <?= $kind === 'books' ? 'selected' : '' ?>>Books</option><option value="members" <?= $kind === 'members' ? 'selected' : '' ?>>Members</option></select></div>
        <button class="button button-primary" type="submit">Search records</button>
        <?php if ($query !== ''): ?><a class="text-link" href="<?= e(url('/search/index.php')) ?>">Clear</a><?php endif; ?>
    </form>
</section>
<?php if ($query === ''): ?>
    <section class="panel guidance-panel"><h2>Search tips</h2><ul><li>Book title and author searches match partial text.</li><li>A numeric value also checks the exact book or member ID.</li><li>Use the Issue / return page to filter loans by open, returned or overdue status.</li></ul></section>
<?php else: ?>
    <div class="section-heading"><h2>Results</h2><span><?= number_format($resultCount) ?> match<?= $resultCount === 1 ? '' : 'es' ?> for “<?= e($query) ?>”</span></div>
    <?php if ($books && $books->num_rows > 0): ?>
        <section class="panel result-panel"><div class="panel-heading"><div><div class="eyebrow">Catalogue</div><h2>Books</h2></div><a class="text-link" href="<?= e(url('/books/index.php?q=' . rawurlencode($query))) ?>">Open book list</a></div>
            <div class="table-wrap"><table><thead><tr><th>Title</th><th>Author</th><th>ISBN</th><th>Copies</th><th>Available</th></tr></thead><tbody>
            <?php while ($book = $books->fetch_assoc()): $available = (int) $book['total_copies'] - (int) $book['issued_copies']; ?>
                <tr><td><span class="cell-strong"><?= e($book['title']) ?></span><span class="cell-sub">ID <?= (int) $book['book_id'] ?></span></td><td><?= e($book['author']) ?></td><td><?= e($book['isbn'] ?: 'Not supplied') ?></td><td><?= (int) $book['total_copies'] ?></td><td><?= $available ?></td></tr>
            <?php endwhile; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
    <?php if ($members && $members->num_rows > 0): ?>
        <section class="panel result-panel"><div class="panel-heading"><div><div class="eyebrow">Borrower register</div><h2>Members</h2></div><a class="text-link" href="<?= e(url('/members/index.php?q=' . rawurlencode($query))) ?>">Open member list</a></div>
            <div class="table-wrap"><table><thead><tr><th>Member</th><th>Course</th><th>Email</th></tr></thead><tbody>
            <?php while ($member = $members->fetch_assoc()): ?>
                <tr><td><span class="cell-strong"><?= e($member['full_name']) ?></span><span class="cell-sub"><?= e($member['member_code']) ?> · ID <?= (int) $member['member_id'] ?></span></td><td><?= e($member['course'] ?: 'Not supplied') ?></td><td><?= e($member['email'] ?: 'Not supplied') ?></td></tr>
            <?php endwhile; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
    <?php if ($resultCount === 0): ?><section class="panel"><div class="empty-state"><h2>No matching records</h2><p>Try a shorter search term or choose a different record type.</p></div></section><?php endif; ?>
<?php endif; ?>
<?php render_footer(); ?>
