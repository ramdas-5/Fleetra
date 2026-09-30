<?php
/**
 * Fleetra — System settings
 * ------------------------------------------------------------------
 * modules/settings/index.php
 *
 * Editable system configuration for administrators. Values are read from a
 * key/value store, validated on the server and written back one key at a
 * time, so a partial save can never corrupt the configuration.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('settings.manage');

$schema = settings_schema();
$errors = [];
$values = settings_values();

if (is_post()) {
    require_csrf();

    $result = validate_settings($_POST);
    $errors = $result['errors'];

    // Re-display what was typed when something was rejected.
    $values = array_merge($values, $result['values']);

    if ($errors === []) {
        $written = save_settings($result['values']);

        log_activity(
            'Updated system settings',
            'settings',
            null,
            $written . ' setting(s) saved'
        );

        fleetra_log('Settings updated by user #' . user_id(), 'INFO');

        flash('success', $written . ' setting' . ($written === 1 ? '' : 's') . ' saved.');

        // Re-read so the screen shows exactly what is now stored.
        redirect('modules/settings/index.php');
    }
}

/* Read-only environment information. */
$environmentRows = [
    ['Application', FLEETRA_NAME . ' v' . FLEETRA_VERSION],
    ['Environment', APP_ENV === 'local' ? 'Local development (XAMPP)' : 'Production'],
    ['PHP version', PHP_VERSION],
    ['Database', DB_NAME . ' @ ' . DB_HOST],
    ['Active timezone', date_default_timezone_get()],
    ['Currency', APP_CURRENCY],
    ['Session idle timeout', round(SESSION_IDLE_TIMEOUT / 60) . ' minutes'],
    ['Email delivery', settings_mailer_available() ? 'Available' : 'Not configured (local install)'],
];

$page_title       = 'Settings';
$active_nav       = 'settings';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Administration'],
    ['label' => 'Settings'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'System settings',
    'Configure how Fleetra behaves across booking, maintenance, tracking and notifications'
) ?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger app-alert" role="alert">
        <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            Nothing was saved. Please review the highlighted fields and submit again.
        </span>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('modules/settings/index.php')) ?>" novalidate>
    <?= csrf_field() ?>

    <div class="grid-2">
        <?php foreach ($schema as $groupKey => $group): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">
                            <i class="bi <?= e($group['icon']) ?>" aria-hidden="true"></i>
                            <?= e($group['label']) ?>
                        </h2>
                        <p class="card-fl__subtitle"><?= e($group['description']) ?></p>
                    </div>
                </div>

                <div class="card-fl__body">
                    <?php foreach ($group['fields'] as $key => $field): ?>
                        <?php
                        $fieldId   = 'setting_' . $key;
                        $value     = (string) ($values[$key] ?? '');
                        $hasError  = isset($errors[$key]);
                        $type      = (string) $field['type'];
                        ?>
                        <div class="setting-row">
                            <?php if ($type === 'toggle'): ?>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="1"
                                           <?= $value === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="<?= e($fieldId) ?>">
                                        <?= e($field['label']) ?>
                                    </label>
                                </div>
                            <?php else: ?>
                                <label class="form-label" for="<?= e($fieldId) ?>">
                                    <?= e($field['label']) ?>
                                    <?php if (in_array('required', $field['rules'] ?? [], true)): ?>
                                        <span class="req">*</span>
                                    <?php endif; ?>
                                </label>

                                <?php if ($type === 'select'): ?>
                                    <select class="form-select<?= $hasError ? ' is-invalid' : '' ?>"
                                            id="<?= e($fieldId) ?>" name="<?= e($key) ?>">
                                        <?= option_tags($field['options'] ?? [], $value) ?>
                                    </select>
                                <?php elseif ($type === 'number'): ?>
                                    <input type="number" class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                                           id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>"
                                           min="<?= e((string) ($field['min'] ?? 0)) ?>"
                                           max="<?= e((string) ($field['max'] ?? 999999)) ?>"
                                           step="1">
                                <?php else: ?>
                                    <input type="<?= e($type === 'tel' ? 'tel' : ($type === 'email' ? 'email' : 'text')) ?>"
                                           class="form-control<?= $hasError ? ' is-invalid' : '' ?>"
                                           id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>">
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($hasError): ?>
                                <p class="form-error"><?= e($errors[$key]) ?></p>
                            <?php elseif (!empty($field['help'])): ?>
                                <p class="form-text"><?= e((string) $field['help']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card-fl">
        <div class="card-fl__footer">
            <a class="btn btn-outline-secondary" href="<?= e(url('modules/settings/index.php')) ?>">Discard changes</a>
            <button type="submit" class="btn btn-primary" data-loading-text="Saving…">
                <i class="bi bi-check2" aria-hidden="true"></i> Save settings
            </button>
        </div>
    </div>
</form>

<div class="card-fl">
    <div class="card-fl__header">
        <div>
            <h2 class="card-fl__title">Environment</h2>
            <p class="card-fl__subtitle">Read-only configuration reported by the application itself</p>
        </div>
    </div>

    <div class="card-fl__body">
        <ul class="fact-list">
            <?php foreach ($environmentRows as $row): ?>
                <li>
                    <span class="fact-list__label"><?= e($row[0]) ?></span>
                    <span class="fact-list__value"><?= e($row[1]) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="form-text mt-3 mb-0">
            Database credentials and application constants live in
            <span class="cell-mono">config/</span> and are intentionally not editable from the browser.
        </p>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
