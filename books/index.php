<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$currentUser = current_user();
$query = query_string('q');
$query = limit_text($query, 100);

$sql = 'SELECT b.book_id, b.isbn, b.title, b.author, b.publisher, b.category,
               b.publication_year, b.total_copies,
               (SELECT COUNT(*) FROM issue_records ir WHERE ir.book_id = b.book_id AND ir.return_date IS NULL) AS issued_copies,
               (b.total_copies - (SELECT COUNT(*) FROM issue_records ir WHERE ir.book_id = b.book_id AND ir.return_date IS NULL)) AS available_copies
        FROM books b';
$statement = null;
if ($query !== '') {
    if (ctype_digit($query)) {
        $sql .= ' WHERE b.book_id = ? OR b.title LIKE ? OR b.author LIKE ?';
        $statement = $conn->prepare($sql . ' ORDER BY b.title, b.book_id');
        $id = (int) $query;
        $pattern = '%' . $query . '%';
        $statement->bind_param('iss', $id, $pattern, $pattern);
    } else {
        $sql .= ' WHERE b.title LIKE ? OR b.author LIKE ?';
        $statement = $conn->prepare($sql . ' ORDER BY b.title, b.book_id');
        $pattern = '%' . $query . '%';
        $statement->bind_param('ss', $pattern, $pattern);
    }
    $statement->execute();
    $books = $statement->get_result();
} else {
    $books = $conn->query($sql . ' ORDER BY b.title, b.book_id');
}

render_header('Books', 'books');
?>
<div class="page-heading">
    <div><div class="eyebrow">Catalogue</div><h1>Books</h1><p class="page-intro">Manage titles and track available copies.</p></div>
    <a class="button button-primary" href="<?= e(url('/books/form.php')) ?>">Add book</a>
</div>
<section class="panel">
    <form class="toolbar-search" method="get" action="<?= e(url('/books/index.php')) ?>">
        <label class="sr-only" for="book-search">Search by book ID, title or author</label>
        <input id="book-search" type="search" name="q" value="<?= e($query) ?>" placeholder="Search title, author or book ID">
        <button class="button button-secondary" type="submit">Search</button>
        <?php if ($query !== ''): ?><a class="text-link" href="<?= e(url('/books/index.php')) ?>">Clear search</a><?php endif; ?>
    </form>
    <div class="table-meta"><span><?= number_format($books->num_rows) ?> record<?= $books->num_rows === 1 ? '' : 's' ?></span><?php if ($query !== ''): ?><span>Matching “<?= e($query) ?>”</span><?php endif; ?></div>
    <?php if ($books->num_rows === 0): ?>
        <div class="empty-state"><h2><?= $query === '' ? 'No books in the catalogue' : 'No matching books' ?></h2><p><?= $query === '' ? 'Add the first title to begin tracking copies.' : 'Try another title, author or book ID.' ?></p><?php if ($query === ''): ?><a class="button button-primary" href="<?= e(url('/books/form.php')) ?>">Add a book</a><?php endif; ?></div>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Book</th><th>Author</th><th>ISBN</th><th>Copies</th><th>Issued</th><th>Available</th><th class="actions-heading">Actions</th></tr></thead>
            <tbody>
            <?php while ($book = $books->fetch_assoc()): ?>
                <tr>
                    <td><span class="cell-strong"><?= e($book['title']) ?></span><span class="cell-sub">ID <?= (int) $book['book_id'] ?><?= $book['category'] ? ' · ' . e($book['category']) : '' ?></span></td>
                    <td><?= e($book['author']) ?></td>
                    <td><?= e($book['isbn'] ?: 'Not supplied') ?></td>
                    <td><?= (int) $book['total_copies'] ?></td>
                    <td><?= (int) $book['issued_copies'] ?></td>
                    <td><span class="availability <?= (int) $book['available_copies'] === 0 ? 'availability-zero' : '' ?>"><?= (int) $book['available_copies'] ?></span></td>
                    <td class="row-actions"><a href="<?= e(url('/books/form.php?id=' . (int) $book['book_id'])) ?>">Edit</a><?php if (($currentUser['role'] ?? '') === 'admin'): ?><a class="danger-link" href="<?= e(url('/books/delete.php?id=' . (int) $book['book_id'])) ?>">Delete</a><?php endif; ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
