<?php
/**
 * Fleetra — Administration / User account
 * ------------------------------------------------------------------
 * modules/users/view.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/_logic.php';

require_permission('users.manage');

$userId = get_int('id');

if ($userId <= 0) {
    abort_not_found('No account was specified.');
}

$account = db_one(
    'SELECT u.*,
            (SELECT COUNT(*) FROM tickets t JOIN bookings b ON b.id = t.booking_id WHERE b.user_id = u.id) AS ticket_count,
            (SELECT COUNT(*) FROM incidents i WHERE i.reported_by = u.id) AS incident_count
       FROM users u
      WHERE u.id = ? AND u.status <> "deleted"
      LIMIT 1',
    [$userId]
);

if ($account === null) {
    abort_not_found('That account does not exist.');
}

$role       = (string) $account['role'];
$isSelf     = $userId === user_id();
$isDriver   = $role === 'driver';
$references = user_reference_counts($userId);
$hasHistory = array_sum($references) > 0;

$driverRecord = $isDriver
    ? db_one(
        'SELECT d.id, d.employee_id, d.license_number, d.license_expiry, d.employment_status,
                d.experience_years, d.joining_date, d.assigned_bus_id,
                b.bus_number, b.registration_number
           FROM drivers d
           LEFT JOIN buses b ON b.id = d.assigned_bus_id
          WHERE d.user_id = ?
          LIMIT 1',
        [$userId]
    )
    : null;

$recentBookings = db_all(
    'SELECT b.id, b.booking_number, b.seat_number, b.fare, b.booking_status, b.payment_status,
            s.schedule_date, s.departure_time,
            r.route_code, r.source, r.destination
       FROM bookings b
       JOIN schedules s ON s.id = b.schedule_id
       JOIN routes r    ON r.id = s.route_id
      WHERE b.user_id = ?
      ORDER BY b.created_at DESC
      LIMIT 6',
    [$userId]
);

$activityHistory = db_all(
    'SELECT al.action, al.description, al.module, al.created_at, al.ip_address, u.name AS actor_name
       FROM activity_logs al
       LEFT JOIN users u ON u.id = al.user_id
      WHERE al.user_id = ? OR (al.module = "users" AND al.record_id = ?)
      ORDER BY al.created_at DESC
      LIMIT 12',
    [$userId, $userId]
);

$capabilities = user_access_summary($role);

$unread = unread_notification_count($userId);

/* ------------------------------------------------------------------
 | Danger zone rules
 ------------------------------------------------------------------ */

$lastAdmin    = $role === 'admin' && (string) $account['status'] === 'active' && active_admin_count() <= 1;
$canDelete    = !$isSelf && !$lastAdmin;
$deleteReason = '';

if ($isSelf) {
    $deleteReason = 'You cannot delete the account you are signed in with.';
} elseif ($lastAdmin) {
    $deleteReason = 'This is the only active administrator. Promote another account first.';
} elseif ($hasHistory) {
    $deleteReason = 'This account has activity on record, so deleting it archives the account and suspends sign-in '
        . 'instead of erasing the history.';
} else {
    $deleteReason = 'This account has no bookings, trips or audit history, so it can be removed completely.';
}

$deleteForm = $canDelete
    ? '<form method="post" action="' . e(url('modules/users/delete.php?id=' . $userId)) . '" class="d-inline"
              data-confirm="' . ($hasHistory
                    ? 'This account has activity on record. It will be archived and the sign-in disabled, and the email address will be released.'
                    : 'This account has no history and will be removed permanently.')
              . '"
              data-confirm-title="Delete ' . e((string) $account['name']) . '?"
              data-confirm-button="' . ($hasHistory ? 'Archive account' : 'Delete account') . '"
              data-confirm-variant="danger">
           ' . csrf_field() . '
           <button type="submit" class="btn btn-outline-danger">
               <i class="bi bi-trash" aria-hidden="true"></i> ' . ($hasHistory ? 'Archive account' : 'Delete account') . '
           </button>
       </form>'
    : '';

$actions = '<a class="btn btn-outline-secondary" href="' . e(url('modules/users/index.php')) . '">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Users
            </a>
            <a class="btn btn-primary" href="' . e(url('modules/users/edit.php?id=' . $userId)) . '">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit account
            </a>' . $deleteForm;

$page_title       = (string) $account['name'];
$active_nav       = 'users';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Administration', 'url' => url('modules/users/index.php')],
    ['label' => 'Users', 'url' => url('modules/users/index.php')],
    ['label' => (string) $account['name']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    (string) $account['name'],
    role_label($role) . ' · ' . $account['email'] . ($isSelf ? ' · this is your account' : ''),
    $actions
) ?>

<?php if ($isSelf): ?>
    <div class="alert alert-info app-alert" role="alert">
        <i class="bi bi-info-circle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            You are viewing your own account. Your role and status are protected so you cannot lock yourself out.
        </span>
    </div>
<?php endif; ?>

<?php if ((string) $account['status'] !== 'active'): ?>
    <div class="alert alert-warning app-alert" role="alert">
        <i class="bi bi-exclamation-triangle app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text">
            This account is <strong><?= e(labelize((string) $account['status'])) ?></strong> and cannot sign in to Fleetra.
            Any pending password reset links have been cancelled.
        </span>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('Bookings', $references['bookings'], 'bi-ticket-perforated', $references['bookings'] > 0 ? 'primary' : 'muted', 'Seats reserved by this account') ?>
    <?= stat_card('Tickets issued', (int) $account['ticket_count'], 'bi-qr-code', (int) $account['ticket_count'] > 0 ? 'info' : 'muted', 'Printable travel tickets') ?>
    <?= stat_card('Audit entries', $references['logs'], 'bi-clock-history', $references['logs'] > 0 ? 'warning' : 'muted', 'Recorded actions') ?>
    <?= stat_card('Incidents reported', (int) $account['incident_count'], 'bi-exclamation-diamond', (int) $account['incident_count'] > 0 ? 'danger' : 'muted', 'Raised by this account') ?>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Account details</h2>
                    <p class="card-fl__subtitle">Identity and sign-in information</p>
                </div>
                <?= status_badge((string) $account['status']) ?>
            </div>

            <div class="card-fl__body">
                <div class="detail-grid">
                    <div class="detail-item">
                        <span class="detail-item__label">Full name</span>
                        <span class="detail-item__value"><?= e((string) $account['name']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Role</span>
                        <span class="detail-item__value"><?= role_badge($role) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Email address</span>
                        <span class="detail-item__value"><?= e((string) $account['email']) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Mobile number</span>
                        <span class="detail-item__value"><?= e($account['phone'] ?: '—') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Account created</span>
                        <span class="detail-item__value"><?= e(format_date((string) $account['created_at'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Last updated</span>
                        <span class="detail-item__value"><?= e(format_datetime((string) $account['updated_at'])) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Last sign in</span>
                        <span class="detail-item__value">
                            <?= $account['last_login'] !== null
                                ? e(format_datetime((string) $account['last_login'])) . ' · ' . e(time_ago((string) $account['last_login']))
                                : 'Never signed in' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-item__label">Unread notifications</span>
                        <span class="detail-item__value"><?= (int) $unread ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isDriver && $driverRecord !== null): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Driver record</h2>
                        <p class="card-fl__subtitle">Licence and employment details kept in the Drivers module</p>
                    </div>
                    <?= status_badge($driverRecord['employment_status']) ?>
                </div>

                <div class="card-fl__body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-item__label">Employee ID</span>
                            <span class="detail-item__value"><?= e($driverRecord['employee_id']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Licence number</span>
                            <span class="detail-item__value"><?= e($driverRecord['license_number']) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Licence expiry</span>
                            <span class="detail-item__value"><?= e(format_date((string) $driverRecord['license_expiry'])) ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Experience</span>
                            <span class="detail-item__value"><?= (int) $driverRecord['experience_years'] ?> years</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Assigned bus</span>
                            <span class="detail-item__value">
                                <?= $driverRecord['bus_number'] !== null ? e($driverRecord['bus_number']) : 'Not assigned' ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-item__label">Joining date</span>
                            <span class="detail-item__value"><?= e(format_date((string) $driverRecord['joining_date'])) ?></span>
                        </div>
                    </div>

                    <a class="btn btn-outline-secondary mt-3" href="<?= e(url('modules/drivers/view.php?id=' . (int) $driverRecord['id'])) ?>">
                        <i class="bi bi-person-badge" aria-hidden="true"></i> Open driver profile
                    </a>
                </div>
            </div>
        <?php elseif ($isDriver): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Driver record</h2>
                        <p class="card-fl__subtitle">Licence and employment details</p>
                    </div>
                </div>
                <?= empty_state(
                    'No driver profile linked',
                    'This account has the driver role but no driver record. Create the profile in the Drivers module so licence and vehicle data stay complete.',
                    'bi-person-badge'
                ) ?>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Recent bookings</h2>
                    <p class="card-fl__subtitle">The six most recent seats reserved by this account</p>
                </div>
            </div>

            <?php if ($recentBookings === []): ?>
                <?= empty_state('No bookings yet', 'Seats reserved by this account will be listed here.', 'bi-ticket-perforated') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Booking</th>
                                <th>Route</th>
                                <th>Departure</th>
                                <th>Seat</th>
                                <th>Fare</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentBookings as $booking): ?>
                                <tr>
                                    <td><span class="cell-strong"><?= e($booking['booking_number']) ?></span></td>
                                    <td>
                                        <span class="cell-strong"><?= e($booking['route_code']) ?></span><br>
                                        <span class="cell-muted"><?= e($booking['source']) ?> &rarr; <?= e($booking['destination']) ?></span>
                                    </td>
                                    <td>
                                        <span class="cell-strong"><?= e(format_date($booking['schedule_date'], 'd M Y')) ?></span><br>
                                        <span class="cell-muted"><?= e(format_time($booking['departure_time'])) ?></span>
                                    </td>
                                    <td class="cell-num"><?= e($booking['seat_number']) ?></td>
                                    <td class="cell-num"><?= e(money($booking['fare'])) ?></td>
                                    <td>
                                        <?= status_badge($booking['booking_status']) ?><br>
                                        <?= status_badge($booking['payment_status']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Activity &amp; audit trail</h2>
                    <p class="card-fl__subtitle">Actions taken by this account, and changes made to it</p>
                </div>
            </div>

            <?php if ($activityHistory === []): ?>
                <?= empty_state('Nothing recorded yet', 'This account has not performed or received any logged action.', 'bi-clock-history') ?>
            <?php else: ?>
                <div class="card-fl__body">
                    <ul class="timeline">
                        <?php foreach ($activityHistory as $entry): ?>
                            <li class="timeline__item timeline__item--done">
                                <p class="timeline__title"><?= e($entry['action']) ?></p>
                                <p class="timeline__meta">
                                    <?= e($entry['actor_name'] ?? 'System') ?>
                                    &middot; <?= e(labelize($entry['module'])) ?>
                                    &middot; <?= e(time_ago($entry['created_at'])) ?>
                                    <?php if (!empty($entry['description'])): ?>
                                        <br><?= e($entry['description']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['ip_address'])): ?>
                                        <br><span class="cell-muted">from <?= e($entry['ip_address']) ?></span>
                                    <?php endif; ?>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">What this account can do</h2>
                    <p class="card-fl__subtitle"><?= e(role_label($role)) ?> permissions</p>
                </div>
            </div>

            <div class="card-fl__body">
                <div class="cell-user">
                    <?= avatar_markup($account, 'lg') ?>
                    <span class="cell-user__text">
                        <span class="cell-user__name"><?= e((string) $account['name']) ?></span>
                        <span class="cell-user__meta"><?= e(role_label($role)) ?></span>
                    </span>
                </div>

                <ul class="fact-list mt-3">
                    <?php foreach ($capabilities as $capability): ?>
                        <li>
                            <span class="fact-list__label">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> <?= e($capability) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Records held</h2>
                    <p class="card-fl__subtitle">What deleting this account would affect</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Bookings</span>
                        <span class="fact-list__value"><?= $references['bookings'] ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Driver profile</span>
                        <span class="fact-list__value"><?= $references['driver_record'] > 0 ? 'Yes' : 'No' ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Notifications</span>
                        <span class="fact-list__value"><?= $references['notifications'] ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Audit entries</span>
                        <span class="fact-list__value"><?= $references['logs'] ?></span>
                    </li>
                </ul>

                <p class="form-text mt-3"><?= e($deleteReason) ?></p>

                <?php if ($canDelete): ?>
                    <div class="d-flex gap-2 mt-3">
                        <a class="btn btn-outline-secondary flex-grow-1" href="<?= e(url('modules/users/edit.php?id=' . $userId)) ?>">
                            <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                        </a>
                        <?= $deleteForm ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
