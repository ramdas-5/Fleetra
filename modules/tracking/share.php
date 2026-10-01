<?php
/**
 * Fleetra — Share my location (driver)
 * ------------------------------------------------------------------
 * modules/tracking/share.php
 *
 * A focused page for a driver on duty: report a real device position for
 * the bus they are actually working. Drivers do NOT get the fleet map or
 * the whole-fleet table — those belong to staff and passengers holding
 * tracking.view. Access is enforced here with tracking.update.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../trips/_logic.php';
require_once __DIR__ . '/_logic.php';

require_permission('tracking.update');

$driverId = current_driver_id();
$myTrip   = $driverId > 0 ? driver_current_trip($driverId) : null;

$recent = [];

if ($myTrip !== null) {
    $recent = db_all(
        'SELECT latitude, longitude, speed, heading, source, recorded_at
           FROM bus_locations
          WHERE bus_id = ?
          ORDER BY recorded_at DESC, id DESC
          LIMIT 6',
        [(int) $myTrip['bus_id']]
    );
}

$page_title       = 'Share my location';
$active_nav       = 'share-location';
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Operations'],
    ['label' => 'Share my location'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Share my location',
    'Send your current position so dispatch can see exactly where you are. This is optional — nothing is shared until you press send.'
) ?>

<?php if ($driverId <= 0): ?>
    <div class="card-fl">
        <?= empty_state(
            'No driver profile',
            'This account is not linked to a driver profile, so a position cannot be reported.',
            'bi-person-badge'
        ) ?>
    </div>
<?php elseif ($myTrip === null): ?>
    <div class="card-fl">
        <?= empty_state(
            'No trip in progress',
            'You can share your position while you have a service in progress. Your next departure will appear here.',
            'bi-geo-alt'
        ) ?>
    </div>
<?php else: ?>
    <div class="grid-main-side">
        <div>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Report a position</h2>
                        <p class="card-fl__subtitle"><?= e($myTrip['route_code']) ?> · bus <?= e($myTrip['bus_number']) ?></p>
                    </div>
                    <?= status_badge($myTrip['trip_status']) ?>
                </div>

                <div class="card-fl__body">
                    <p class="form-text mb-3">
                        Your duty today is <?= e($myTrip['route_code']) ?> (<?= e($myTrip['route_name']) ?>)
                        on bus <?= e($myTrip['bus_number']) ?>. Sending your position stores a real device fix
                        labelled <strong>Live device</strong> on the operations map.
                    </p>

                    <button type="button" class="btn btn-primary w-100" id="useGeolocation"
                            data-api="<?= e(url('api/tracking.php')) ?>"
                            data-bus="<?= (int) $myTrip['bus_id'] ?>"
                            data-trip="<?= (int) $myTrip['trip_id'] ?>">
                        <i class="bi bi-crosshair" aria-hidden="true"></i> Use my current position
                    </button>

                    <div class="divider"></div>

                    <form id="manualLocation" data-api="<?= e(url('api/tracking.php')) ?>">
                        <div class="form-row">
                            <div>
                                <label class="form-label" for="share_lat">Latitude</label>
                                <input type="number" class="form-control" id="share_lat" name="latitude"
                                       step="0.0000001" min="-90" max="90" required>
                            </div>
                            <div>
                                <label class="form-label" for="share_lng">Longitude</label>
                                <input type="number" class="form-control" id="share_lng" name="longitude"
                                       step="0.0000001" min="-180" max="180" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-outline-secondary w-100 mt-3" data-loading-text="Sending…">
                            <i class="bi bi-send" aria-hidden="true"></i> Send manual position
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Recent positions</h2>
                        <p class="card-fl__subtitle">Latest fixes recorded for this bus</p>
                    </div>
                </div>

                <?php if ($recent === []): ?>
                    <?= empty_state('No positions yet', 'Your shared positions will be listed here.', 'bi-geo') ?>
                <?php else: ?>
                    <div class="card-fl__body stack-sm">
                        <ul class="fact-list">
                            <?php foreach ($recent as $fix): ?>
                                <li>
                                    <span class="fact-list__label">
                                        <?= e(number_format((float) $fix['latitude'], 5)) ?>,
                                        <?= e(number_format((float) $fix['longitude'], 5)) ?>
                                    </span>
                                    <span class="fact-list__value">
                                        <?= e(labelize((string) $fix['source'])) ?> · <?= e(time_ago((string) $fix['recorded_at'])) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var geoButton = document.getElementById('useGeolocation');
    var manual = document.getElementById('manualLocation');

    function send(api, payload) {
        window.Fleetra.post(api, payload).then(function (response) {
            window.Fleetra.toast(response.message || 'Position recorded.', response.success ? 'success' : 'danger');
            if (response.success) {
                window.setTimeout(function () { window.location.reload(); }, 900);
            }
        });
    }

    if (geoButton) {
        geoButton.addEventListener('click', function () {
            if (!navigator.geolocation) {
                window.Fleetra.toast('This browser cannot share a location.', 'warning');
                return;
            }

            geoButton.disabled = true;

            navigator.geolocation.getCurrentPosition(function (position) {
                send(geoButton.getAttribute('data-api'), {
                    action: 'update',
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    speed: position.coords.speed ? position.coords.speed * 3.6 : 0,
                    heading: position.coords.heading || 0
                });
                geoButton.disabled = false;
            }, function () {
                window.Fleetra.toast('Could not read your location. Enter it manually below.', 'warning');
                geoButton.disabled = false;
            }, { timeout: 8000, maximumAge: 30000 });
        });
    }

    if (manual) {
        manual.addEventListener('submit', function (event) {
            event.preventDefault();

            var data = new FormData(manual);
            var payload = { action: 'update' };

            data.forEach(function (value, key) { payload[key] = value; });

            send(manual.getAttribute('data-api'), payload);
        });
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
