<?php
/**
 * Fleetra — Notifications / Send an announcement
 * ------------------------------------------------------------------
 * modules/notifications/send.php
 *
 * Administrators, managers and dispatchers can broadcast a message to a
 * whole audience. Each recipient gets their own notification row, so read
 * state stays per person.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('notifications.send');

$errors = [];
$values = [
    'audience' => 'staff',
    'type'     => 'system',
    'title'    => '',
    'message'  => '',
];

if (is_post()) {
    require_csrf();

    $values = [
        'audience' => post('audience', 'staff'),
        'type'     => post('type', 'system'),
        'title'    => post('title'),
        'message'  => post('message'),
    ];

    if (!is_valid_option(announcement_audience_options(), $values['audience'])) {
        $errors['audience'] = 'Choose who should receive this announcement.';
    }

    if (!is_valid_option(notification_type_options(), $values['type'])) {
        $errors['type'] = 'Choose a valid notification type.';
    }

    if (mb_strlen($values['title']) < 3 || mb_strlen($values['title']) > 160) {
        $errors['title'] = 'Give the announcement a title of 3 to 160 characters.';
    }

    if (mb_strlen($values['message']) < 5) {
        $errors['message'] = 'Write a message of at least 5 characters.';
    } elseif (mb_strlen($values['message']) > 2000) {
        $errors['message'] = 'Keep the message under 2000 characters.';
    }

    if ($errors === []) {
        $result = send_announcement($values['audience'], $values['title'], $values['message'], $values['type']);

        log_activity(
            'Sent announcement "' . $values['title'] . '"',
            'notifications',
            null,
            'Delivered to ' . $result['sent'] . ' recipient(s): ' . $values['audience']
        );

        fleetra_log('Announcement sent to ' . $result['sent'] . ' users by #' . user_id(), 'INFO');

        $noun = $result['sent'] === 1 ? 'person' : 'people';

        if ($result['sent'] === 0) {
            flash('warning', 'No active user matches that audience, so nothing was sent.');
        } else {
            flash('success', 'Announcement delivered to ' . $result['sent'] . ' ' . $noun . '.');
        }

        redirect('modules/notifications/index.php');
    }
}

/** Live recipient count shown next to the audience picker. */
$audienceCounts = [];
foreach (array_keys(announcement_audience_options()) as $audience) {
    $audienceCounts[$audience] = count(announcement_recipients($audience));
}

$page_title       = 'Send announcement';
$active_nav       = 'notifications';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Notifications', 'url' => url('modules/notifications/index.php')],
    ['label' => 'Send announcement'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Send an announcement',
    'Broadcast an operational message to Fleetra users',
    '<a class="btn btn-outline-secondary" href="' . e(url('modules/notifications/index.php')) . '">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Notifications
     </a>'
) ?>

<div class="form-card">
    <form method="post" action="<?= e(url('modules/notifications/send.php')) ?>" novalidate>
        <?= csrf_field() ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger app-alert" role="alert">
                <i class="bi bi-exclamation-octagon app-alert__icon" aria-hidden="true"></i>
                <span class="app-alert__text">
                    The announcement was not sent. Please review the highlighted fields.
                </span>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Audience</h2>
                    <p class="card-fl__subtitle">Who receives this message</p>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="form-row">
                    <div>
                        <label class="form-label" for="audience">Send to <span class="req">*</span></label>
                        <select class="form-select<?= isset($errors['audience']) ? ' is-invalid' : '' ?>"
                                id="audience" name="audience" required>
                            <?php foreach (announcement_audience_options() as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $values['audience'] === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?> (<?= (int) ($audienceCounts[$key] ?? 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['audience'])): ?>
                            <p class="form-error"><?= e($errors['audience']) ?></p>
                        <?php else: ?>
                            <p class="form-text">Only active accounts receive announcements.</p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="form-label" for="type">Message type <span class="req">*</span></label>
                        <select class="form-select<?= isset($errors['type']) ? ' is-invalid' : '' ?>"
                                id="type" name="type" required>
                            <?= option_tags(notification_type_options(), $values['type']) ?>
                        </select>
                        <?php if (isset($errors['type'])): ?>
                            <p class="form-error"><?= e($errors['type']) ?></p>
                        <?php else: ?>
                            <p class="form-text">Emergency messages are highlighted across the app.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Message</h2>
                    <p class="card-fl__subtitle">Keep it short and factual</p>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="form-row">
                    <div class="span-2">
                        <label class="form-label" for="title">Title <span class="req">*</span></label>
                        <input type="text" class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>"
                               id="title" name="title" value="<?= e($values['title']) ?>"
                               maxlength="160" required placeholder="e.g. Coastal Link services diverted today">
                        <?php if (isset($errors['title'])): ?>
                            <p class="form-error"><?= e($errors['title']) ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="span-2">
                        <label class="form-label" for="message">Message <span class="req">*</span></label>
                        <textarea class="form-control<?= isset($errors['message']) ? ' is-invalid' : '' ?>"
                                  id="message" name="message" rows="5" required maxlength="2000"
                                  placeholder="Explain what is happening and what recipients should do."><?= e($values['message']) ?></textarea>
                        <?php if (isset($errors['message'])): ?>
                            <p class="form-error"><?= e($errors['message']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card-fl__footer">
                <a class="btn btn-outline-secondary" href="<?= e(url('modules/notifications/index.php')) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary" data-loading-text="Sending…">
                    <i class="bi bi-megaphone" aria-hidden="true"></i> Send announcement
                </button>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
