<?php
/**
 * Fleetra — Live tracking
 * ------------------------------------------------------------------
 * modules/tracking/index.php
 *
 * Leaflet + OpenStreetMap view of where every bus is right now. Positions
 * come from bus_locations and carry their source, so a simulated demo
 * position is never presented as a real GPS reading.
 *
 * Staff can advance the built-in simulation; a driver can push their own
 * position from this page (browser geolocation or manual entry), which is
 * stored with source = 'device'.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../trips/_logic.php';
require_once __DIR__ . '/_logic.php';

require_permission('tracking.view');

$canSimulate = has_role('admin', 'manager', 'dispatcher');
$canUpdate   = can('tracking.update');
$driverId    = $canUpdate ? current_driver_id() : 0;
$myTrip      = $driverId > 0 ? driver_current_trip($driverId) : null;

$buses    = tracking_buses();
$refresh  = tracking_refresh_seconds();
$simulated = (int) db_value("SELECT COUNT(*) FROM bus_locations WHERE source = 'simulated'", [], 0);
$device    = (int) db_value("SELECT COUNT(*) FROM bus_locations WHERE source = 'device'", [], 0);

/* Markers handed to the JavaScript in a shape the map can use directly.
   The same helper feeds the polling endpoint, so the two never diverge. */
$markers = [];

foreach ($buses as $bus) {
    $marker = tracking_marker_payload($bus);

    if ($marker !== null) {
        $markers[] = $marker;
    }
}

$onRoad    = count($markers);
$tracked   = count(array_filter($buses, static fn (array $bus): bool => (bool) $bus['has_fix']));
$liveNow   = count(array_filter($buses, static fn (array $bus): bool => $bus['live_status'] === 'live'));
$staleNow  = count(array_filter($buses, static fn (array $bus): bool => in_array($bus['live_status'], ['stale', 'offline'], true)));

$page_title       = 'Live tracking';
$active_nav       = 'tracking';
$extra_css        = [asset('vendor/leaflet/leaflet.css')];
$extra_js         = [asset('vendor/leaflet/leaflet.js')];
$page_breadcrumbs = [
    ['label' => 'Dashboard', 'url' => url(dashboard_path(current_role()))],
    ['label' => 'Operations'],
    ['label' => 'Live tracking'],
];

require __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Live tracking',
    $liveNow . ' live now · ' . $staleNow . ' stale or offline · ' . $tracked . ' of ' . count($buses)
        . ' bus' . (count($buses) === 1 ? '' : 'es') . ' reporting · map refreshes every ' . $refresh . ' seconds',
    $canSimulate
        ? '<form method="post" action="' . e(url('modules/tracking/simulate.php')) . '" class="d-inline">
               ' . csrf_field() . '
               <button type="submit" class="btn btn-primary">
                   <i class="bi bi-play-circle" aria-hidden="true"></i> Advance simulation
               </button>
           </form>'
        : ''
) ?>

<div class="alert alert-info app-alert" role="alert">
    <i class="bi bi-broadcast-pin app-alert__icon" aria-hidden="true"></i>
    <span class="app-alert__text">
        <strong>How freshness works.</strong> A position is <strong>Live</strong> for the first 2 minutes, becomes
        <strong>Recently updated</strong> up to 10 minutes, then <strong>Stale</strong> up to 30 minutes, and is shown as
        <strong>Offline</strong> beyond that — a stale or offline marker is never presented as a real-time position.
        On this local install positions come from Fleetra's own demo simulation (<em>source = simulated</em>); a real
        GPS unit or a driver sharing their position writes the same rows with <em>source = device</em>. Map tiles come
        from OpenStreetMap and need an internet connection; the markers still work offline.
    </span>
</div>

<div class="grid-main-side">
    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Fleet map</h2>
                    <p class="card-fl__subtitle">
                        <span class="live-dot" aria-hidden="true"></span>
                        <span id="mapUpdated">Waiting for the first fix…</span>
                    </p>
                </div>
                <span class="chip">Tracked <strong id="mapCount"><?= $tracked ?></strong> / <?= $onRoad ?></span>
            </div>

            <div class="card-fl__body card-fl__body--flush">
                <div class="map-shell" id="fleetMap"
                     data-markers="<?= e_js($markers) ?>"
                     data-refresh="<?= (int) $refresh ?>"
                     data-endpoint="<?= e(url('api/tracking.php')) ?>"></div>
            </div>
        </div>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Vehicles on the road</h2>
                    <p class="card-fl__subtitle">Latest reported position for each active service</p>
                </div>
                <span class="chip">Simulated <strong><?= $simulated ?></strong> · device <strong><?= $device ?></strong></span>
            </div>

            <?php if ($buses === []): ?>
                <?= empty_state('No buses to track', 'Add buses to the fleet to see them on the live map.', 'bi-bus-front') ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table-fl">
                        <thead>
                            <tr>
                                <th>Bus</th>
                                <th>Service</th>
                                <th>Driver</th>
                                <th>Speed</th>
                                <th>Service</th>
                                <th>Live status</th>
                                <th>Source</th>
                                <th>Last fix</th>
                                <th class="cell-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($buses as $bus): ?>
                                <?php $trip = $bus['trip']; ?>
                                <tr>
                                    <td>
                                        <span class="cell-strong"><?= e($bus['bus_number']) ?></span><br>
                                        <span class="cell-muted"><?= e($bus['registration_number']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($trip !== null): ?>
                                            <span class="cell-strong"><?= e($trip['route_code']) ?> · <?= e($trip['route_name']) ?></span><br>
                                            <span class="cell-muted">
                                                <?= e($trip['source']) ?> &rarr; <?= e($trip['destination']) ?><br>
                                                dep. <?= e(format_time((string) $trip['departure_time'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="cell-muted">Not on a service</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $trip !== null ? e($trip['driver_name']) : '<span class="cell-muted">—</span>' ?>
                                    </td>
                                    <td class="cell-num">
                                        <?= $bus['speed'] !== null ? (int) round((float) $bus['speed']) . ' km/h' : '—' ?>
                                    </td>
                                    <td>
                                        <?= $trip !== null
                                            ? status_badge($trip['trip_status'])
                                            : status_badge($bus['bus_status']) ?>
                                    </td>
                                    <td>
                                        <?= live_status_badge($bus['recorded_at'] ?? null) ?>
                                    </td>
                                    <td>
                                        <?= status_badge($bus['source'] ?? 'none', tracking_source_label($bus['source'])) ?>
                                    </td>
                                    <td>
                                        <?= $bus['recorded_at'] !== null
                                            ? e(time_ago((string) $bus['recorded_at']))
                                            : '<span class="cell-muted">Never</span>' ?>
                                    </td>
                                    <td class="cell-actions">
                                        <a class="row-action" href="<?= e(url('modules/buses/view.php?id=' . (int) $bus['bus_id'])) ?>"
                                           title="Open bus" aria-label="Open bus <?= e($bus['bus_number']) ?>">
                                            <i class="bi bi-bus-front" aria-hidden="true"></i>
                                        </a>
                                        <?php if ($trip !== null): ?>
                                            <a class="row-action" href="<?= e(url('modules/trips/view.php?id=' . (int) $trip['trip_id'])) ?>"
                                               title="Open trip" aria-label="Open trip">
                                                <i class="bi bi-signpost-split" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Map legend</h2>
                    <p class="card-fl__subtitle">What each marker means</p>
                </div>
            </div>

            <div class="card-fl__body">                            <div class="map-legend">
                    <div class="map-legend__item">
                        <span class="map-legend__dot map-legend__dot--live"></span> Live — updated in the last 2 minutes
                    </div>
                    <div class="map-legend__item">
                        <span class="map-legend__dot map-legend__dot--recent"></span> Recently updated — up to 10 minutes
                    </div>
                    <div class="map-legend__item">
                        <span class="map-legend__dot map-legend__dot--stale"></span> Stale location — up to 30 minutes
                    </div>
                    <div class="map-legend__item">
                        <span class="map-legend__dot map-legend__dot--delayed"></span> Running late (live)
                    </div>
                    <div class="map-legend__item">
                        <span class="map-legend__dot map-legend__dot--offline"></span> Offline / last known position
                    </div>
                </div>
            </div>
        </div>

        <?php if ($canUpdate && $driverId > 0 && $myTrip !== null): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Share my position</h2>
                        <p class="card-fl__subtitle">Reported as a real device fix</p>
                    </div>
                    <span class="chip"><?= e($myTrip['bus_number']) ?></span>
                </div>

                <div class="card-fl__body">
                    <p class="form-text mb-3">
                        Your duty today is <?= e($myTrip['route_code']) ?> on bus <?= e($myTrip['bus_number']) ?>.
                        Send your current coordinates so dispatch can see exactly where you are.
                    </p>

                    <button type="button" class="btn btn-outline-secondary w-100" id="useGeolocation"
                            data-api="<?= e(url('api/tracking.php')) ?>"
                            data-bus="<?= (int) $myTrip['bus_id'] ?>"
                            data-trip="<?= (int) $myTrip['trip_id'] ?>">
                        <i class="bi bi-crosshair" aria-hidden="true"></i> Use my current position
                    </button>

                    <div class="divider"></div>

                    <form id="manualLocation" data-api="<?= e(url('api/tracking.php')) ?>">
                        <input type="hidden" name="bus_id" value="<?= (int) $myTrip['bus_id'] ?>">
                        <input type="hidden" name="trip_id" value="<?= (int) $myTrip['trip_id'] ?>">

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

                        <button type="submit" class="btn btn-primary w-100 mt-3" data-loading-text="Sending…">
                            <i class="bi bi-send" aria-hidden="true"></i> Send position
                        </button>

                        <p class="form-text mt-2 mb-0">
                            A device fix is clearly labelled on the map as <strong>Live device</strong>.
                        </p>
                    </form>
                </div>
            </div>
        <?php elseif ($canUpdate && $driverId > 0): ?>
            <div class="card-fl">
                <div class="card-fl__header">
                    <div>
                        <h2 class="card-fl__title">Share my position</h2>
                        <p class="card-fl__subtitle">No active duty right now</p>
                    </div>
                </div>
                <?= empty_state(
                    'No trip in progress',
                    'You can share your position while you have a service in progress. Your next departure will appear here.',
                    'bi-geo-alt'
                ) ?>
            </div>
        <?php endif; ?>

        <div class="card-fl">
            <div class="card-fl__header">
                <div>
                    <h2 class="card-fl__title">Tracking source</h2>
                    <p class="card-fl__subtitle">Configured in system settings</p>
                </div>
            </div>

            <div class="card-fl__body">
                <ul class="fact-list">
                    <li>
                        <span class="fact-list__label">Mode</span>
                        <span class="fact-list__value"><?= e(labelize(tracking_setting('tracking_source', 'simulated'))) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Refresh</span>
                        <span class="fact-list__value"><?= $refresh ?> seconds</span>
                    </li>
                    <li>
                        <span class="fact-list__label">Simulated fixes</span>
                        <span class="fact-list__value"><?= number_format($simulated) ?></span>
                    </li>
                    <li>
                        <span class="fact-list__label">Device fixes</span>
                        <span class="fact-list__value"><?= number_format($device) ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
/* --------------------------------------------------------------
   Live map. Leaflet is served locally; map tiles come from
   OpenStreetMap and simply do not paint when offline.
   -------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function () {
    var shell = document.getElementById('fleetMap');

    if (!shell || typeof L === 'undefined') {
        return;
    }

    var endpoint = shell.getAttribute('data-endpoint');
    var refresh  = (parseInt(shell.getAttribute('data-refresh'), 10) || 15) * 1000;
    var markers  = JSON.parse(shell.getAttribute('data-markers') || '[]');
    var byBus    = {};
    var map;

    function variantClass(variant) {
        return variant ? ' bus-marker--' + variant : '';
    }

    function iconFor(variant) {
        return L.divIcon({
            className: '',
            html: '<span class="bus-marker' + variantClass(variant) + '"><i class="bi bi-bus-front"></i></span>',
            iconSize: [30, 30],
            iconAnchor: [15, 15],
            popupAnchor: [0, -14]
        });
    }

    function ageLabel(seconds) {
        if (seconds === null || typeof seconds === 'undefined') {
            return 'Never';
        }
        if (seconds < 60) {
            return seconds + 's ago';
        }
        if (seconds < 3600) {
            return Math.round(seconds / 60) + ' min ago';
        }
        return Math.round(seconds / 3600) + ' h ago';
    }

    function popupHtml(bus) {
        var rows = [
            ['Live status', bus.live_label || 'No recent location'],
            ['Route', bus.route_code ? bus.route_code + ' · ' + (bus.route_name || '') : 'Not on a service'],
            ['Journey', bus.route_from ? bus.route_from + ' → ' + bus.route_to : '—'],
            ['Driver', bus.driver || 'Unassigned'],
            ['Speed', bus.speed !== null ? Math.round(bus.speed) + ' km/h' : 'Not moving'],
            ['Trip status', bus.trip_status ? bus.trip_status.replace(/_/g, ' ') : '—'],
            ['Passengers', bus.passengers !== null ? String(bus.passengers) : '—'],
            ['Source', bus.source === 'device' ? 'Live device' : (bus.source === 'simulated' ? 'Simulated demo' : 'No position yet')],
            ['Last updated', ageLabel(bus.age_seconds)]
        ];

        var html = '<div class="map-popup"><strong>' + bus.bus_number + '</strong>';
        html += '<span class="cell-muted"> · ' + bus.registration + '</span><dl>';

        rows.forEach(function (row) {
            html += '<dt>' + row[0] + '</dt><dd>' + row[1] + '</dd>';
        });

        html += '</dl></div>';

        return html;
    }

    function ensureMarker(bus) {
        var existing = byBus[bus.bus_id];

        if (existing) {
            existing.setLatLng([bus.lat, bus.lng]);
            existing.setIcon(iconFor(bus.variant));
            existing.setPopupContent(popupHtml(bus));
            return existing;
        }

        var marker = L.marker([bus.lat, bus.lng], { icon: iconFor(bus.variant) })
            .addTo(map)
            .bindPopup(popupHtml(bus));

        byBus[bus.bus_id] = marker;

        return marker;
    }

    map = L.map(shell, { zoomControl: true }).setView([12.97, 77.59], 7);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    var bounds = [];
    markers.forEach(function (bus) {
        ensureMarker(bus);
        bounds.push([bus.lat, bus.lng]);
    });

    if (bounds.length === 1) {
        map.setView(bounds[0], 12);
    } else if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [40, 40] });
    }

    var updatedEl = document.getElementById('mapUpdated');
    var countEl = document.getElementById('mapCount');
    var lastRefresh = new Date();

    function stampUpdated(count) {
        lastRefresh = new Date();
        if (countEl) {
            countEl.textContent = count;
        }
    }

    function tick() {
        window.Fleetra.get(endpoint + '?action=positions').then(function (response) {
            if (!response.success || !response.buses) {
                return;
            }

            var seen = [];

            response.buses.forEach(function (bus) {
                seen.push(bus.bus_id);
                ensureMarker(bus);
            });

            // Remove markers for buses that finished their service.
            Object.keys(byBus).forEach(function (id) {
                if (seen.indexOf(parseInt(id, 10)) === -1) {
                    map.removeLayer(byBus[id]);
                    delete byBus[id];
                }
            });

            stampUpdated(response.buses.length);
        }).catch(function () {
            /* Offline or a transient error — keep the last known positions. */
        });
    }

    // Update the "last refreshed" caption on a short timer of its own.
    window.setInterval(function () {
        if (!updatedEl) {
            return;
        }

        var seconds = Math.round((Date.now() - lastRefresh.getTime()) / 1000);
        updatedEl.textContent = 'Updated ' + (seconds < 2 ? 'just now' : seconds + ' seconds ago');
    }, 2000);

    if (updatedEl) {
        updatedEl.textContent = 'Updated just now';
    }

    var timer = window.setInterval(tick, refresh);

    // Do not poll while the tab is in the background.
    document.addEventListener('visibilitychange', function () {
        window.clearInterval(timer);

        if (!document.hidden) {
            tick();
            timer = window.setInterval(tick, refresh);
        }
    });
});
</script>

<script>
/* Driver position sharing (only rendered for driver accounts). */
document.addEventListener('DOMContentLoaded', function () {
    var geoButton = document.getElementById('useGeolocation');
    var manual = document.getElementById('manualLocation');

    function send(api, payload, done) {
        window.Fleetra.post(api, payload).then(function (response) {
            window.Fleetra.toast(response.message || 'Position recorded.', response.success ? 'success' : 'danger');
            if (response.success && typeof done === 'function') {
                done();
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
                    bus_id: geoButton.getAttribute('data-bus'),
                    trip_id: geoButton.getAttribute('data-trip'),
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    speed: position.coords.speed ? position.coords.speed * 3.6 : 0,
                    heading: position.coords.heading || 0
                }, function () {
                    geoButton.disabled = false;
                });
            }, function () {
                window.Fleetra.toast('Could not read your location. Enter it manually below.', 'warning');
                geoButton.disabled = false;
            });
        });
    }

    if (manual) {
        manual.addEventListener('submit', function (event) {
            event.preventDefault();

            var data = new FormData(manual);
            var payload = { action: 'update' };

            data.forEach(function (value, key) {
                payload[key] = value;
            });

            send(manual.getAttribute('data-api'), payload);
        });
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
