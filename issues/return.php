<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/issues/index.php');
require_csrf();
$conn = db();
$id = filter_var($_POST['issue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    set_flash('error', 'Select a valid issue record.');
    redirect('/issues/index.php');
}
$id = (int) $id;

try {
    $conn->begin_transaction();
    $statement = $conn->prepare('SELECT issue_date, due_date, return_date FROM issue_records WHERE issue_id = ? FOR UPDATE');
    $statement->bind_param('i', $id);
    $statement->execute();
    $issue = $statement->get_result()->fetch_assoc();
    if (!$issue) {
        $conn->rollback();
        set_flash('error', 'That issue record could not be found.');
        redirect('/issues/index.php');
    }
    if ($issue['return_date'] !== null) {
        $conn->rollback();
        set_flash('error', 'This book has already been returned.');
        redirect('/issues/index.php');
    }
    $returnDate = date('Y-m-d');
    if ($returnDate < $issue['issue_date']) {
        $conn->rollback();
        set_flash('error', 'The return date cannot be earlier than the issue date.');
        redirect('/issues/index.php');
    }
    $update = $conn->prepare('UPDATE issue_records SET return_date = ? WHERE issue_id = ? AND return_date IS NULL');
    $update->bind_param('si', $returnDate, $id);
    $update->execute();
    if ($update->affected_rows !== 1) {
        $conn->rollback();
        set_flash('error', 'This issue has already been returned.');
        redirect('/issues/index.php');
    }
    $conn->commit();
    if ($returnDate > $issue['due_date']) {
        $holdEnds = (new DateTimeImmutable($returnDate))->modify('+10 days')->format('Y-m-d');
        set_flash('success', 'Late return recorded. This member cannot borrow another book until ' . display_date($holdEnds) . '.');
    } else {
        set_flash('success', 'Return recorded. The copy is available again.');
    }
} catch (mysqli_sql_exception $exception) {
    try { $conn->rollback(); } catch (Throwable $ignored) { }
    set_flash('error', 'The return could not be recorded. Refresh the issue register and try again.');
}
redirect('/issues/index.php');
