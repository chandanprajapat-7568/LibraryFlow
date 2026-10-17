<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$conn = db();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$member = ['member_id' => 0, 'member_code' => '', 'full_name' => '', 'course' => '', 'section_group' => '', 'semester' => '', 'phone' => '', 'email' => ''];
if ($id > 0) {
    $statement = $conn->prepare('SELECT member_id, member_code, full_name, course, section_group, semester, phone, email FROM members WHERE member_id = ?');
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
$catalog = course_catalog();
$selectedCourse = (string) ($member['course'] ?? '');
$maximumSemester = course_semester_limit($selectedCourse);
$selectedSemester = (int) ($member['semester'] ?? 0);
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
            <div class="form-field <?= isset($errors['course']) ? 'has-error' : '' ?>"><label for="course">Course / program <span class="required-mark">Required</span></label><select id="course" name="course" required data-course-select aria-describedby="course-error"><option value="">Select a college program</option><?php foreach ($catalog as $group => $programs): ?><optgroup label="<?= e($group) ?>"><?php foreach ($programs as $program => $details): ?><option value="<?= e($program) ?>" data-max-semesters="<?= (int) $details['semesters'] ?>" <?= $selectedCourse === $program ? 'selected' : '' ?>><?= e($details['label']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select><small>Choose a program offered by Lachoo Memorial College.</small><?= field_error($errors, 'course') ?></div>
            <div class="form-field <?= isset($errors['semester']) ? 'has-error' : '' ?>"><label for="semester">Semester</label><select id="semester" name="semester" data-semester-select data-current-value="<?= $selectedSemester > 0 ? $selectedSemester : '' ?>" <?= $maximumSemester > 0 ? '' : 'disabled' ?> aria-describedby="semester-error"><option value="">Not specified</option><?php for ($number = 1; $number <= $maximumSemester; $number++): ?><option value="<?= $number ?>" <?= $selectedSemester === $number ? 'selected' : '' ?>>Semester <?= $number ?></option><?php endfor; ?></select><small class="semester-hint"><?= $maximumSemester > 0 ? 'This program has ' . $maximumSemester . ' semesters.' : 'Select a course first; the semester limit will match its duration.' ?></small><?= field_error($errors, 'semester') ?></div>
            <div class="form-field <?= isset($errors['section_group']) ? 'has-error' : '' ?>"><label for="section_group">Section / group</label><input id="section_group" name="section_group" maxlength="80" value="<?= e($member['section_group']) ?>" placeholder="For example, Section A, Group A1" aria-describedby="section_group-error"><small>Optional class section or group, if applicable.</small><?= field_error($errors, 'section_group') ?></div>
            <div class="form-field <?= isset($errors['phone']) ? 'has-error' : '' ?>"><label for="phone">Phone</label><input id="phone" name="phone" type="tel" maxlength="20" value="<?= e($member['phone']) ?>" aria-describedby="phone-error"><?= field_error($errors, 'phone') ?></div>
            <div class="form-field <?= isset($errors['email']) ? 'has-error' : '' ?>"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" value="<?= e($member['email']) ?>" aria-describedby="email-error"><?= field_error($errors, 'email') ?></div>
        </div>
        <div class="form-actions"><a class="button button-secondary" href="<?= e(url('/members/index.php')) ?>">Cancel</a><button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Save member' ?></button></div>
    </form>
</section>
<?php render_footer(); ?>
