<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/auth-header.php
 *
 * Public auth shell (login, register, forgot / reset password).
 * Two-column on desktop: brand panel + form card. Single column on
 * mobile. Pages set:
 *
 *   $auth_heading        Card title.
 *   $auth_subtitle       Card subtitle.
 *   $auth_aside_title    Brand panel headline (optional override).
 *   $auth_aside_text     Brand panel paragraph (optional override).
 *   $auth_page_title     <title> value (defaults to $auth_heading).
 *
 * Pages end by including includes/auth-footer.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$auth_heading     = $auth_heading     ?? 'Sign in';
$auth_subtitle    = $auth_subtitle    ?? '';
$auth_aside_title = $auth_aside_title ?? 'Transport operations, run from one console.';
$auth_aside_text  = $auth_aside_text  ?? 'Fleetra brings buses, drivers, schedules, bookings, maintenance and reporting together so your team works from a single source of truth.';
$auth_page_title  = ($auth_page_title ?? $auth_heading) . ' · ' . FLEETRA_NAME;

$authFeatures = [
    ['bi-ticket-perforated', 'Passenger booking',          'Search services, reserve seats and issue printable e-tickets.'],
    ['bi-calendar-check', 'Conflict-free scheduling',   'Buses and drivers are validated so overlapping assignments cannot happen.'],
    ['bi-bar-chart-line', 'Operations reporting',       'Utilisation, revenue, delays and maintenance cost in one dashboard.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($auth_page_title) ?></title>

    <?php /* Vendor assets are served locally so the sign-in pages work offline. */ ?>
    <link href="<?= e(asset('vendor/fonts/inter.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">

    <link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/responsive.css')) ?>" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <aside class="auth-aside">
        <div class="auth-aside__inner">
            <a class="auth-aside__brand" href="<?= e(url('index.php')) ?>">
                <span class="brand__mark" aria-hidden="true">FL</span>
                <span>
                    <span class="auth-aside__brand-name d-block"><?= e(FLEETRA_NAME) ?></span>
                    <span class="auth-aside__brand-tag"><?= e(FLEETRA_TAGLINE) ?></span>
                </span>
            </a>

            <div class="auth-aside__body">
                <h2 class="auth-aside__title"><?= e($auth_aside_title) ?></h2>
                <p class="auth-aside__text"><?= e($auth_aside_text) ?></p>

                <ul class="auth-features">
                    <?php foreach ($authFeatures as [$icon, $title, $text]): ?>
                        <li>
                            <i class="bi <?= e($icon) ?>" aria-hidden="true"></i>
                            <span><strong class="text-white d-block"><?= e($title) ?></strong><?= e($text) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <p class="auth-aside__foot">
            &copy; <?= date('Y') ?> <?= e(FLEETRA_NAME) ?> &middot; Local demonstration build v<?= e(FLEETRA_VERSION) ?>
        </p>
    </aside>

    <main class="auth-main">
        <div class="auth-card">
            <?php require __DIR__ . '/alerts.php'; ?>
