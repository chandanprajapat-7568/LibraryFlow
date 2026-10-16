<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$member = ['member_id' => 0, 'member_code' => '', 'full_name' => '', 'course' => '', 'semester' => '', 'phone' => '', 'email' => ''];
if ($id > 0) {
    $statement = $conn->prepare('SELECT member_id, member_code, full_name, course, semester, phone, email FROM members WHERE member_id = ?');
    $statement->bind_param('i', $id);
    $statement->execute();
    $savedMember = $statement->get_result()->fetch_assoc();
    if (!$savedMember) {
        set_flash('error', 'That member record could not be found.');
        redirect('/members/index.php');
    }
    $member = $savedMember;
}
$state = consume_form_state('members');
$member = array_merge($member, $state['values'] ?? []);
$errors = $state['errors'] ?? [];
$editing = (int) $member['member_id'] > 0;
render_header($editing ? 'Edit member' : 'Add member', 'members');
?>
<div class="page-heading">
    <div><div class="eyebrow"><a class="breadcrumb-link" href="<?= e(url('/members/index.php')) ?>">Members</a> / <?= $editing ? 'Edit record' : 'New record' ?></div><h1><?= $editing ? 'Edit member' : 'Add a member' ?></h1><p class="page-intro">Member code and name are required. Contact details are optional.</p></div>
</div>
<section class="panel form-panel">
    <form method="post" action="<?= e(url('/members/save.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="member_id" value="<?= (int) $member['member_id'] ?>">
        <div class="form-grid">
            <div class="form-field <?= isset($errors['member_code']) ? 'has-error' : '' ?>"><label for="member_code">Member code <span class="required-mark">Required</span></label><input id="member_code" name="member_code" maxlength="30" required value="<?= e($member['member_code']) ?>" aria-describedby="member_code-error"><small>Unique roll number or library code.</small><?= field_error($errors, 'member_code') ?></div>
            <div class="form-field <?= isset($errors['full_name']) ? 'has-error' : '' ?>"><label for="full_name">Full name <span class="required-mark">Required</span></label><input id="full_name" name="full_name" maxlength="150" required value="<?= e($member['full_name']) ?>" aria-describedby="full_name-error"><?= field_error($errors, 'full_name') ?></div>
            <div class="form-field <?= isset($errors['course']) ? 'has-error' : '' ?>"><label for="course">Course</label><input id="course" name="course" maxlength="100" value="<?= e($member['course']) ?>" aria-describedby="course-error"><?= field_error($errors, 'course') ?></div>
            <div class="form-field <?= isset($errors['semester']) ? 'has-error' : '' ?>"><label for="semester">Semester</label><input id="semester" name="semester" type="number" min="1" max="255" step="1" value="<?= e($member['semester']) ?>" aria-describedby="semester-error"><small>Optional positive number.</small><?= field_error($errors, 'semester') ?></div>
            <div class="form-field <?= isset($errors['phone']) ? 'has-error' : '' ?>"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="20" value="<?= e($member['phone']) ?>" aria-describedby="phone-error"><?= field_error($errors, 'phone') ?></div>
            <div class="form-field <?= isset($errors['email']) ? 'has-error' : '' ?>"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" value="<?= e($member['email']) ?>" aria-describedby="email-error"><?= field_error($errors, 'email') ?></div>
        </div>
        <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/members/index.php')) ?>">Cancel</a><button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Save member' ?></button></div>
    </form>
</section>
<?php render_footer(); ?>
