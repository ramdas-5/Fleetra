<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/functions.php
 *
 * Reusable helpers shared by every module: output escaping, redirects,
 * flash messages, CSRF protection, activity logging, pagination,
 * formatting and status badges.
 *
 * Loading order: config.php -> database.php -> functions.php -> auth.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/* ------------------------------------------------------------------
 | URLs and redirects
 ------------------------------------------------------------------ */

/**
 * Stop with a friendly 404 when a requested record does not exist — used
 * by every module instead of rendering a broken detail page.
 */
function abort_not_found(string $message = 'The record you are looking for does not exist or has been removed.'): void
{
    fleetra_fatal($message, 404);
}

/**
 * Load a record by id or abort with a 404. Returns the row as an array.
 *
 * @param array<int, mixed> $params
 * @return array<string, mixed>
 */
function find_or_404(string $table, int $id, array $params = []): array
{
    $record = db_one(sprintf('SELECT * FROM `%s` WHERE id = ? LIMIT 1', $table), array_merge([$id], $params));

    if ($record === null) {
        abort_not_found();
    }

    return $record;
}

/**
 * True when at least one row matches the given WHERE clause.
 *
 * @param array<int, mixed> $params
 */
function db_exists(string $table, string $column, mixed $value, ?int $excludeId = null): bool
{
    if ($excludeId !== null) {
        return (int) db_value(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ? AND id <> ?', $table, $column),
            [$value, $excludeId],
            0
        ) > 0;
    }

    return (int) db_value(
        sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column),
        [$value],
        0
    ) > 0;
}

/** Build an absolute in-app URL, e.g. url('admin/dashboard.php'). */
function url(string $path = ''): string
{
    return BASE_URL . ltrim($path, '/');
}

/** Build an absolute asset URL, e.g. asset('css/style.css'). */
function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

/**
 * Redirect to an in-app path (or a full URL) and stop execution.
 */
function redirect(string $path): never
{
    $target = preg_match('#^https?://#i', $path) ? $path : url($path);

    if (!headers_sent()) {
        header('Location: ' . $target, true, 302);
    } else {
        echo '<script>window.location.href=' . json_encode($target) . ';</script>';
    }

    exit;
}

/**
 * Only allow internal redirect targets, so a crafted ?next= value can
 * never bounce a signed-in user to an external site.
 */
function safe_redirect_target(?string $target): ?string
{
    $target = trim((string) $target);

    if ($target === '') {
        return null;
    }

    // Reject protocol-relative and absolute external URLs.
    if (str_starts_with($target, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) {
        return null;
    }

    if (!str_starts_with($target, '/')) {
        return null;
    }

    return $target;
}

/** True when the request is a POST. */
function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** True when the request was made with XMLHttpRequest / fetch. */
function is_ajax(): bool
{
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return strtolower($requestedWith) === 'xmlhttprequest';
}

/** Send a JSON response and stop execution (used by api/ endpoints). */
function json_response(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ------------------------------------------------------------------
 | Output escaping
 ------------------------------------------------------------------ */

/** Escape a value for safe HTML output. ALWAYS use this for user data. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape a value for use inside a JavaScript context. */
function e_js(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null';
}

/* ------------------------------------------------------------------
 | Request input
 ------------------------------------------------------------------ */

/** Trimmed string from $_POST. */
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_scalar($value) ? trim((string) $value) : $default;
}

/** Trimmed string from $_GET. */
function get(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    return is_scalar($value) ? trim((string) $value) : $default;
}

/** Integer from $_POST, clamped to a minimum. */
function post_int(string $key, int $default = 0, int $min = 0): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);

    return $value === false ? $default : max($min, (int) $value);
}

/** Integer from $_GET, clamped to a minimum. */
function get_int(string $key, int $default = 0, int $min = 0): int
{
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT);

    return $value === false ? $default : max($min, (int) $value);
}

/** Re-display a previously submitted value (used to refill forms). */
function old(string $key, string $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

/* ------------------------------------------------------------------
 | Flash messages
 ------------------------------------------------------------------ */

/**
 * Queue a flash message that survives one redirect.
 * Types: success | danger | warning | info
 */
function flash(string $type, string $message): void
{
    fleetra_session_start();

    $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message];
}

/** Pull every queued flash message and clear the queue. */
function take_flashes(): array
{
    fleetra_session_start();

    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);

    return $messages;
}

/* ------------------------------------------------------------------
 | CSRF protection
 ------------------------------------------------------------------ */

/** Return the current session CSRF token, creating it when missing. */
function csrf_token(): string
{
    fleetra_session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/** Hidden input field to drop inside every state-changing form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Validate a submitted token using a timing-safe comparison. */
function csrf_verify(?string $token): bool
{
    fleetra_session_start();

    $sessionToken = $_SESSION['csrf_token'] ?? '';

    return $sessionToken !== '' && is_string($token) && hash_equals($sessionToken, $token);
}

/**
 * Abort the request when a POST arrives without a valid CSRF token.
 * Call this at the top of every state-changing handler.
 */
function require_csrf(): void
{
    if (!is_post()) {
        return;
    }

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        fleetra_log('CSRF validation failed for ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'), 'WARNING');
        fleetra_fatal('Your session expired or the form was tampered with. Please reload the page and try again.', 419);
    }
}

/* ------------------------------------------------------------------
 | Activity log (audit trail)
 ------------------------------------------------------------------ */

/**
 * Record an important action in activity_logs.
 *
 * @param string $action      Short verb phrase, e.g. "Created bus FLT-102".
 * @param string $module      Module key, e.g. "buses".
 * @param int|null $recordId  Affected record id, when applicable.
 */
function log_activity(string $action, string $module, ?int $recordId = null, string $description = ''): void
{
    try {
        db_insert('activity_logs', [
            'user_id'     => $_SESSION['user_id'] ?? null,
            'action'      => $action,
            'module'      => $module,
            'record_id'   => $recordId,
            'description' => $description !== '' ? $description : null,
            'ip_address'  => client_ip(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $exception) {
        // An audit failure must never break the user's action.
        fleetra_log('Activity log failed: ' . $exception->getMessage(), 'WARNING');
    }
}

/** Best-effort client IP address. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/* ------------------------------------------------------------------
 | Pagination
 ------------------------------------------------------------------ */

/**
 * Build pagination metadata for a list page.
 *
 * @return array{page:int, per_page:int, total:int, pages:int, offset:int, from:int, to:int}
 */
function paginate(int $total, int $perPage = 15, ?int $currentPage = null): array
{
    $perPage     = max(1, $perPage);
    $pages       = max(1, (int) ceil($total / $perPage));
    $currentPage = $currentPage ?? get_int('page', 1, 1);
    $currentPage = min(max(1, $currentPage), $pages);
    $offset      = ($currentPage - 1) * $perPage;

    return [
        'page'     => $currentPage,
        'per_page' => $perPage,
        'total'    => $total,
        'pages'    => $pages,
        'offset'   => $offset,
        'from'     => $total === 0 ? 0 : $offset + 1,
        'to'       => min($offset + $perPage, $total),
    ];
}

/**
 * Render Bootstrap pagination markup, preserving current query filters.
 *
 * @param array{page:int, per_page:int, total:int, pages:int, from:int, to:int} $meta
 */
function render_pagination(array $meta, int $window = 2): string
{
    $html = '<div class="table-foot">';
    $html .= '<span class="table-foot__info">Showing <strong>' . $meta['from'] . '</strong>–<strong>'
        . $meta['to'] . '</strong> of <strong>' . $meta['total'] . '</strong></span>';

    if ($meta['pages'] > 1) {
        $query = $_GET;
        unset($query['page']);

        $linkFor = static function (int $page) use ($query): string {
            $query['page'] = $page;

            return '?' . http_build_query($query);
        };

        $html .= '<nav><ul class="pagination pagination-sm mb-0">';

        $prevDisabled = $meta['page'] <= 1;
        $html .= '<li class="page-item' . ($prevDisabled ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . ($prevDisabled ? '#' : e($linkFor($meta['page'] - 1))) . '" aria-label="Previous">&laquo;</a></li>';

        $start = max(1, $meta['page'] - $window);
        $end   = min($meta['pages'], $meta['page'] + $window);

        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . e($linkFor(1)) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
        }

        for ($page = $start; $page <= $end; $page++) {
            $active = $page === $meta['page'] ? ' active' : '';
            $html  .= '<li class="page-item' . $active . '"><a class="page-link" href="'
                . e($linkFor($page)) . '">' . $page . '</a></li>';
        }

        if ($end < $meta['pages']) {
            if ($end < $meta['pages'] - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . e($linkFor($meta['pages'])) . '">'
                . $meta['pages'] . '</a></li>';
        }

        $nextDisabled = $meta['page'] >= $meta['pages'];
        $html .= '<li class="page-item' . ($nextDisabled ? ' disabled' : '') . '">'
            . '<a class="page-link" href="' . ($nextDisabled ? '#' : e($linkFor($meta['page'] + 1))) . '" aria-label="Next">&raquo;</a></li>';

        $html .= '</ul></nav>';
    }

    $html .= '</div>';

    return $html;
}

/* ------------------------------------------------------------------
 | Role routing
 ------------------------------------------------------------------ */

/**
 * Dashboard path for a role key. Unknown roles fall back to the login page
 * so a future role can never land on a broken URL.
 */
function dashboard_path(string $role): string
{
    $map = [
        'admin'      => 'admin/dashboard.php',
        'manager'    => 'manager/dashboard.php',
        'dispatcher' => 'dispatcher/dashboard.php',
        'driver'     => 'driver/dashboard.php',
        'passenger'  => 'passenger/dashboard.php',
    ];

    return $map[$role] ?? 'login.php';
}

/* ------------------------------------------------------------------
 | Domain option lists — one source for forms, filters and labels
 ------------------------------------------------------------------ */

/** @return array<string, string> */
function bus_type_options(): array
{
    return [
        'seater'       => 'Seater',
        'semi_sleeper' => 'Semi-sleeper',
        'sleeper'      => 'Sleeper',
        'ac_seater'    => 'AC seater',
        'ac_sleeper'   => 'AC sleeper',
        'mini'         => 'Mini bus',
    ];
}

/** @return array<string, string> */
function fuel_type_options(): array
{
    return [
        'diesel'   => 'Diesel',
        'petrol'   => 'Petrol',
        'cng'      => 'CNG',
        'electric' => 'Electric',
        'hybrid'   => 'Hybrid',
    ];
}

/** @return array<string, string> */
function bus_status_options(): array
{
    return [
        'active'      => 'Active',
        'inactive'    => 'Inactive',
        'maintenance' => 'In maintenance',
    ];
}

/** @return array<string, string> */
function employment_status_options(): array
{
    return [
        'active'     => 'Active',
        'on_leave'   => 'On leave',
        'suspended'  => 'Suspended',
        'terminated' => 'Terminated',
        'resigned'   => 'Resigned',
    ];
}

/** @return array<string, string> */
function route_status_options(): array
{
    return [
        'active'   => 'Active',
        'inactive' => 'Inactive',
    ];
}

/** @return array<string, string> */
function trip_status_options(): array
{
    return [
        'scheduled' => 'Scheduled',
        'boarding'  => 'Boarding',
        'running'   => 'Running',
        'delayed'   => 'Delayed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}

/** @return array<string, string> */
function maintenance_type_options(): array
{
    return [
        'routine'     => 'Routine service',
        'repair'      => 'Repair',
        'inspection'  => 'Inspection',
        'tyre'        => 'Tyres',
        'oil_change'  => 'Oil change',
        'brake'       => 'Brakes',
        'electrical'  => 'Electrical',
        'bodywork'    => 'Bodywork',
        'other'       => 'Other',
    ];
}

/** @return array<string, string> */
function maintenance_status_options(): array
{
    return [
        'scheduled'   => 'Scheduled',
        'in_progress' => 'In progress',
        'completed'   => 'Completed',
        'overdue'     => 'Overdue',
        'cancelled'   => 'Cancelled',
    ];
}

/** @return array<string, string> */
function incident_type_options(): array
{
    return [
        'breakdown' => 'Breakdown',
        'accident'  => 'Accident',
        'traffic'   => 'Traffic delay',
        'medical'   => 'Medical',
        'security'  => 'Security',
        'weather'   => 'Weather',
        'other'     => 'Other',
    ];
}

/** @return array<string, string> */
function incident_severity_options(): array
{
    return [
        'low'      => 'Low',
        'medium'   => 'Medium',
        'high'     => 'High',
        'critical' => 'Critical',
    ];
}

/** @return array<string, string> */
function incident_status_options(): array
{
    return [
        'open'          => 'Open',
        'investigating' => 'Investigating',
        'resolved'      => 'Resolved',
        'closed'        => 'Closed',
    ];
}

/** @return array<string, string> */
function notification_type_options(): array
{
    return [
        'system'      => 'System',
        'trip'        => 'Trip update',
        'delay'       => 'Delay',
        'booking'     => 'Booking',
        'maintenance' => 'Maintenance',
        'emergency'   => 'Emergency',
    ];
}

/** @return array<string, string> */
function ticket_status_options(): array
{
    return [
        'valid'     => 'Valid',
        'used'      => 'Used',
        'cancelled' => 'Cancelled',
        'expired'   => 'Expired',
    ];
}

/** @return array<string, string> */
function payment_method_options(): array
{
    return [
        'simulated'   => 'Online (simulated)',
        'cash'        => 'Cash',
        'card'        => 'Card',
        'upi'         => 'UPI',
        'net_banking' => 'Net banking',
        'wallet'      => 'Wallet',
    ];
}

/** @return array<string, string> */
function booking_status_options(): array
{
    return [
        'pending'   => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show'   => 'No show',
    ];
}

/** True when a value is a valid key in one of the option lists above. */
function is_valid_option(array $options, ?string $value): bool
{
    return $value !== null && array_key_exists($value, $options);
}

/**
 * Render <option> tags for a select, marking the current value selected.
 */
function option_tags(array $options, mixed $selected = null, string $placeholder = ''): string
{
    $html       = '';
    $selected   = (string) ($selected ?? '');

    if ($placeholder !== '') {
        $html .= '<option value="">' . e($placeholder) . '</option>';
    }

    foreach ($options as $value => $label) {
        $isSelected = ((string) $value === $selected) ? ' selected' : '';
        $html      .= '<option value="' . e((string) $value) . '"' . $isSelected . '>' . e($label) . '</option>';
    }

    return $html;
}

/* ------------------------------------------------------------------
 | Formatting helpers
 ------------------------------------------------------------------ */

/** Format a date (returns an em dash when empty). */
function format_date(?string $date, string $format = 'd M Y'): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '—';
    }

    $timestamp = strtotime($date);

    return $timestamp ? date($format, $timestamp) : '—';
}

/** Format a time value such as 08:30:00 as 08:30 AM. */
function format_time(?string $time, string $format = 'h:i A'): string
{
    if (empty($time)) {
        return '—';
    }

    $timestamp = strtotime($time);

    return $timestamp ? date($format, $timestamp) : '—';
}

/** Format a full date + time value. */
function format_datetime(?string $datetime, string $format = 'd M Y, h:i A'): string
{
    return format_date($datetime, $format);
}

/** Format a monetary amount with the configured currency symbol. */
function money(mixed $amount, bool $withSymbol = true): string
{
    $formatted = number_format((float) $amount, 2);

    return $withSymbol ? APP_CURRENCY . $formatted : $formatted;
}

/** Convert minutes into a readable duration, e.g. "4h 15m". */
function format_duration(int|float|null $minutes): string
{
    $minutes = (int) round((float) $minutes);

    if ($minutes <= 0) {
        return '—';
    }

    $hours = intdiv($minutes, 60);
    $mins  = $minutes % 60;

    return trim(($hours > 0 ? $hours . 'h ' : '') . ($mins > 0 ? $mins . 'm' : '')) ?: '0m';
}

/** Human friendly "3 hours ago" style timestamp. */
function time_ago(?string $datetime): string
{
    if (empty($datetime)) {
        return '—';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '—';
    }

    $seconds = time() - $timestamp;
    if ($seconds < 0) {
        return 'just now';
    }

    if ($seconds < 60) {
        return 'just now';
    }

    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
    ];

    foreach ($units as $unitSeconds => $label) {
        if ($seconds >= $unitSeconds) {
            $value = (int) floor($seconds / $unitSeconds);

            return $value . ' ' . $label . ($value > 1 ? 's' : '') . ' ago';
        }
    }

    return 'just now';
}

/** Two-letter initials used by avatar placeholders. */
function initials(?string $name): string
{
    $name  = trim((string) $name);
    if ($name === '') {
        return 'FL';
    }

    $parts    = preg_split('/\s+/', $name) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) === 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'FL';
}

/*
 * Live-position freshness.
 *
 * A GPS fix is only "live" for a short while. These thresholds decide how
 * a position is labelled so a stale reading is never shown as if it were
 * happening right now. Override the settings below to tune the windows.
 */
const TRACKING_LIVE_SECONDS   = 120;   // <= 2 min  → Live
const TRACKING_RECENT_SECONDS = 600;   // <= 10 min → Recently updated
const TRACKING_STALE_SECONDS  = 1800;  // <= 30 min → Stale

/**
 * Classify a position timestamp into a freshness status.
 *
 * @return array{status:string, label:string, variant:string, seconds:?int, recorded_at:?string}
 *         status: live | recent | stale | offline | no_location
 */
function tracking_freshness(?string $recordedAt): array
{
    if ($recordedAt === null || trim($recordedAt) === '') {
        return [
            'status'      => 'no_location',
            'label'       => 'No recent location',
            'variant'     => 'muted',
            'seconds'     => null,
            'recorded_at' => null,
        ];
    }

    $timestamp = strtotime($recordedAt);

    if ($timestamp === false) {
        return [
            'status'      => 'no_location',
            'label'       => 'No recent location',
            'variant'     => 'muted',
            'seconds'     => null,
            'recorded_at' => null,
        ];
    }

    $seconds = max(0, time() - $timestamp);

    if ($seconds <= TRACKING_LIVE_SECONDS) {
        return ['status' => 'live',     'label' => 'Live',                'variant' => 'success', 'seconds' => $seconds, 'recorded_at' => $recordedAt];
    }

    if ($seconds <= TRACKING_RECENT_SECONDS) {
        return ['status' => 'recent',   'label' => 'Recently updated',    'variant' => 'info',    'seconds' => $seconds, 'recorded_at' => $recordedAt];
    }

    if ($seconds <= TRACKING_STALE_SECONDS) {
        return ['status' => 'stale',    'label' => 'Stale location',      'variant' => 'warning', 'seconds' => $seconds, 'recorded_at' => $recordedAt];
    }

    return ['status' => 'offline', 'label' => 'Offline — no recent location', 'variant' => 'muted', 'seconds' => $seconds, 'recorded_at' => $recordedAt];
}

/** Render a small pill for a live-position freshness status. */
function live_status_badge(?string $recordedAt): string
{
    $freshness = tracking_freshness($recordedAt);

    return '<span class="badge-status badge-' . e($freshness['variant']) . ' status-dot status-dot--' . e($freshness['status']) . '">'
        . e($freshness['label']) . '</span>';
}

/** Shorten long text for tables and cards. */
function truncate(?string $text, int $length = 60): string
{
    $text = trim((string) $text);

    return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
}

/** Readable label for a snake_case / kebab-case value. */
function labelize(?string $value): string
{
    return ucwords(str_replace(['_', '-'], ' ', (string) $value));
}

/* ------------------------------------------------------------------
 | Status badges — one colour map for the entire application so the
 | same status never appears in two different colours.
 ------------------------------------------------------------------ */

/** Map any status/severity value to a semantic badge variant. */
function status_variant(?string $status): string
{
    $key = strtolower(str_replace([' ', '-'], '_', (string) $status));

    $map = [
        // Generic availability
        'active'        => 'success',
        'available'     => 'success',
        'enabled'       => 'success',
        'inactive'      => 'muted',
        'disabled'      => 'muted',
        'retired'       => 'muted',
        // Fleet / maintenance
        'maintenance'   => 'warning',
        'in_service'    => 'warning',
        'scheduled'     => 'info',
        'upcoming'      => 'info',
        'due'           => 'warning',
        'overdue'       => 'danger',
        'in_progress'   => 'info',
        'routine'       => 'muted',
        'repair'        => 'warning',
        // Live position freshness
        'live'          => 'success',
        'recent'        => 'info',
        'stale'         => 'warning',
        'offline'       => 'muted',
        'no_location'   => 'muted',
        // Trips
        'boarding'      => 'info',
        'running'       => 'primary',
        'on_time'       => 'success',
        'delayed'       => 'warning',
        'completed'     => 'success',
        'cancelled'     => 'danger',
        'canceled'      => 'danger',
        'missed'        => 'danger',
        'diverted'      => 'warning',
        // Tickets
        'valid'         => 'success',
        // Notification types (mirrors the notifications.notification_type enum)
        'trip'          => 'info',
        'delay'         => 'warning',
        'booking'       => 'primary',
        'emergency'     => 'danger',
        'system'        => 'muted',
        // Bookings & payments
        'confirmed'     => 'success',
        'pending'       => 'warning',
        'paid'          => 'success',
        'unpaid'        => 'warning',
        'partial'       => 'warning',
        'failed'        => 'danger',
        'refunded'      => 'muted',
        'expired'       => 'danger',
        'used'          => 'muted',
        'checked_in'    => 'info',
        'no_show'       => 'muted',
        // Employment
        'on_leave'      => 'warning',
        'suspended'     => 'danger',
        'terminated'    => 'danger',
        'resigned'      => 'muted',
        // Incidents & severity
        'low'           => 'info',
        'medium'        => 'warning',
        'high'          => 'danger',
        'critical'      => 'danger',
        'open'          => 'warning',
        'investigating' => 'warning',
        'resolved'      => 'success',
        'closed'        => 'muted',
    ];

    return $map[$key] ?? 'muted';
}

/** Render a consistent status badge. */
function status_badge(?string $status, ?string $label = null): string
{
    $status = (string) $status;

    if ($status === '') {
        return '<span class="badge-status badge-muted">Unknown</span>';
    }

    return '<span class="badge-status badge-' . e(status_variant($status)) . '">'
        . e($label ?? labelize($status)) . '</span>';
}

/** Small neutral chip used for counts and metadata. */
function chip(string $label, ?string $value = null): string
{
    $html = '<span class="chip">' . e($label);
    if ($value !== null) {
        $html .= ' <strong>' . e($value) . '</strong>';
    }

    return $html . '</span>';
}

/* ------------------------------------------------------------------
 | Validation
 ------------------------------------------------------------------ */

/** Basic email syntax check. */
function is_valid_email(?string $email): bool
{
    return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
}

/** True when a value is a real calendar date in Y-m-d form. */
function is_valid_date(?string $value): bool
{
    $value = (string) $value;

    if ($value === '') {
        return false;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

/** Check a phone number: 10–15 digits, optional +, spaces and dashes. */
function is_valid_phone(?string $phone): bool
{
    $digits = preg_replace('/\D+/', '', (string) $phone);

    return is_string($digits) && strlen($digits) >= 10 && strlen($digits) <= 15;
}

/**
 * Validate password strength.
 * Requires 8+ characters with at least one letter and one number.
 */
function password_issues(?string $password): ?string
{
    $password = (string) $password;

    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        return 'Password must contain at least one letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must contain at least one number.';
    }

    return null;
}

/* ------------------------------------------------------------------
 | Secure file uploads
 ------------------------------------------------------------------ */

/**
 * Validate and store an uploaded image inside uploads/<folder>.
 *
 * @param array<string, mixed> $file  One entry from $_FILES.
 * @param string $folder              Sub-folder name, e.g. "buses".
 * @param string $prefix              Filename prefix, e.g. "bus".
 * @return array{ok:bool, filename?:string, error?:string}
 */
function upload_image(array $file, string $folder, string $prefix = 'img', int $maxBytes = 2097152): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'The uploaded file is not valid.'];
    }

    switch ($file['error']) {
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'error' => 'no_file'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'error' => 'The image is larger than the allowed size.'];
        case UPLOAD_ERR_OK:
            break;
        default:
            return ['ok' => false, 'error' => 'The image could not be uploaded. Please try again.'];
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'The image is larger than ' . round($maxBytes / 1048576, 1) . ' MB.'];
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = (string) finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }

    if ($mime === '' || !isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Only JPG, PNG or WEBP images are allowed.'];
    }

    $destination = UPLOAD_PATH . '/' . trim($folder, '/');
    if (!is_dir($destination) && !@mkdir($destination, 0775, true)) {
        return ['ok' => false, 'error' => 'The upload folder is not writable.'];
    }

    $filename = sprintf(
        '%s_%s_%s.%s',
        preg_replace('/[^a-z0-9]+/i', '-', $prefix) ?: 'file',
        date('YmdHis'),
        bin2hex(random_bytes(4)),
        $allowed[$mime]
    );

    if (!move_uploaded_file($file['tmp_name'], $destination . '/' . $filename)) {
        return ['ok' => false, 'error' => 'The image could not be saved. Please try again.'];
    }

    return ['ok' => true, 'filename' => $filename];
}

/** Public URL for an uploaded file, with a graceful fallback. */
function upload_url(?string $filename, string $folder): ?string
{
    if (empty($filename)) {
        return null;
    }

    return url('uploads/' . trim($folder, '/') . '/' . $filename);
}

/**
 * Delete an uploaded file, but only inside the Fleetra uploads directory.
 * Used when a user replaces or removes their profile photo.
 */
function delete_upload(?string $filename, string $folder): void
{
    if (empty($filename)) {
        return;
    }

    // Guard against path traversal in a stored filename.
    $safeName = basename($filename);
    $path     = UPLOAD_PATH . '/' . trim($folder, '/') . '/' . $safeName;

    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Render a user avatar: the uploaded photo when one exists, otherwise the
 * person's initials. Sizes map to the .avatar--* CSS classes.
 *
 * @param array<string, mixed>|null $user
 */
function avatar_markup(?array $user, string $size = 'sm'): string
{
    $name = (string) ($user['name'] ?? '');
    $url  = upload_url($user['profile_image'] ?? null, 'profiles');

    if ($url !== null) {
        return '<img class="avatar avatar--' . e($size) . '" src="' . e($url)
            . '" alt="' . e($name) . '" loading="lazy">';
    }

    return '<span class="avatar avatar--' . e($size) . '" aria-hidden="true">'
        . e(initials($name)) . '</span>';
}

/** Join non-empty string parts with a separator. */
function join_parts(array $parts, string $separator = ' · '): string
{
    $parts = array_filter(array_map(static fn ($p) => trim((string) $p), $parts), static fn ($p) => $p !== '');

    return implode($separator, $parts);
}

/* ------------------------------------------------------------------
 | Notifications helper
 ------------------------------------------------------------------ */

/**
 * Unread notification count for a user (0 when signed out).
 */
function unread_notification_count(?int $userId = null): int
{
    $userId ??= (int) ($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return 0;
    }

    try {
        return (int) db_value(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId],
            0
        );
    } catch (Throwable $exception) {
        fleetra_log('Notification count failed: ' . $exception->getMessage(), 'WARNING');

        return 0;
    }
}

/* ------------------------------------------------------------------
 | Shared UI partials
 ------------------------------------------------------------------ */

/**
 * Standard page header used at the top of the content area.
 * Keeps title / subtitle / action alignment identical on every page.
 */
function render_page_header(string $title, string $subtitle = '', string $actionsHtml = ''): string
{
    $html = '<div class="page-head"><div class="page-head__text">';
    $html .= '<h1 class="page-title">' . e($title) . '</h1>';

    if ($subtitle !== '') {
        $html .= '<p class="page-subtitle">' . e($subtitle) . '</p>';
    }

    $html .= '</div>';

    if ($actionsHtml !== '') {
        $html .= '<div class="page-head__actions">' . $actionsHtml . '</div>';
    }

    return $html . '</div>';
}

/**
 * Metric card used by every dashboard.
 *
 * @param string $variant primary|success|warning|danger|info|muted
 */
function stat_card(
    string $label,
    mixed $value,
    string $icon = 'bi-graph-up',
    string $variant = 'primary',
    string $meta = '',
    ?string $href = null
): string {
    $tag    = $href !== null ? 'a' : 'div';
    $attrs  = $href !== null ? ' href="' . e($href) . '" class="stat-card stat-card--link"' : ' class="stat-card"';
    $value  = is_numeric($value) ? number_format((float) $value) : (string) $value;

    $html  = '<' . $tag . $attrs . '>';
    $html .= '<div class="stat-card__top">';
    $html .= '<span class="stat-card__label">' . e($label) . '</span>';
    $html .= '<span class="stat-icon stat-icon--' . e($variant) . '"><i class="bi ' . e($icon) . '"></i></span>';
    $html .= '</div>';
    $html .= '<div class="stat-card__value">' . e($value) . '</div>';

    if ($meta !== '') {
        $html .= '<div class="stat-card__meta">' . $meta . '</div>';
    }

    return $html . '</' . $tag . '>';
}

/**
 * Professional empty state — never leave a list or table blank.
 */
function empty_state(
    string $title,
    string $message = '',
    string $icon = 'bi-inbox',
    string $actionHtml = ''
): string {
    $html  = '<div class="empty-state">';
    $html .= '<span class="empty-state__icon"><i class="bi ' . e($icon) . '"></i></span>';
    $html .= '<h3 class="empty-state__title">' . e($title) . '</h3>';

    if ($message !== '') {
        $html .= '<p class="empty-state__text">' . e($message) . '</p>';
    }

    if ($actionHtml !== '') {
        $html .= '<div class="empty-state__action">' . $actionHtml . '</div>';
    }

    return $html . '</div>';
}

/**
 * Render subtle breadcrumbs for the topbar.
 *
 * @param array<int, array{label:string, url?:string}> $crumbs
 */
function render_breadcrumbs(array $crumbs): string
{
    if ($crumbs === []) {
        return '';
    }

    $parts   = [];
    $lastKey = array_key_last($crumbs);

    foreach ($crumbs as $index => $crumb) {
        $label = e($crumb['label'] ?? '');

        if ($index === $lastKey || empty($crumb['url'])) {
            $parts[] = '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
        } else {
            $parts[] = '<li class="breadcrumb-item"><a href="' . e($crumb['url']) . '">' . $label . '</a></li>';
        }
    }

    return '<nav aria-label="breadcrumb"><ol class="breadcrumb">' . implode('', $parts) . '</ol></nav>';
}
