<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/alerts.php
 *
 * Renders queued flash messages as dismissible Bootstrap alerts.
 * Types: success | danger | warning | info
 */

declare(strict_types=1);

$fleetraFlashes = take_flashes();

$fleetraAlertIcons = [
    'success' => 'bi-check-circle',
    'danger'  => 'bi-exclamation-octagon',
    'warning' => 'bi-exclamation-triangle',
    'info'    => 'bi-info-circle',
];

foreach ($fleetraFlashes as $fleetraFlash):
    $fleetraType = (string) ($fleetraFlash['type'] ?? 'info');
    $fleetraType = isset($fleetraAlertIcons[$fleetraType]) ? $fleetraType : 'info';
    ?>
    <div class="alert alert-<?= e($fleetraType) ?> alert-dismissible fade show app-alert" role="alert">
        <i class="bi <?= e($fleetraAlertIcons[$fleetraType]) ?> app-alert__icon" aria-hidden="true"></i>
        <span class="app-alert__text"><?= e($fleetraFlash['message'] ?? '') ?></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
    </div>
<?php endforeach; ?>

<?php unset($fleetraFlashes, $fleetraAlertIcons, $fleetraFlash, $fleetraType); ?>
