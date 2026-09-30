<?php
/**
 * Fleetra — Administration / Add user
 * ------------------------------------------------------------------
 * modules/users/create.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('users.manage');

$errors = [];
$values = user_form_values();

if (is_post()) {
    require_csrf();

    $result = validate_user_request($_POST, false);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($errors === []) {
        $userId = db_insert('users', array_merge(user_db_payload($values), [
            'password' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
        ]));

        log_activity(
            'Created user account for ' . $values['name'],
            'users',
            $userId,
            role_label($values['role']) . ' account created'
        );
        fleetra_log('User #' . $userId . ' created by admin #' . user_id(), 'INFO');

        flash('success', 'The account for ' . $values['name'] . ' was created.');
        redirect('modules/users/view.php?id=' . $userId);
    }
}

$formAction = url('modules/users/create.php');
$isEdit     = false;
$userId     = null;

$page_title       = 'Add user';
$active_nav       = 'users';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Administration', 'url' => url('modules/users/index.php')],
    ['label' => 'Users', 'url' => url('modules/users/index.php')],
    ['label' => 'Add user'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Add a user',
    'Create an account and choose what it can access',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/users/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to users
     </a>'
) ?>

<div class="form-card">
    <?php require __DIR__ . '/_form.php'; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
