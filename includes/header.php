<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/header.php
 *
 * Opens the shared application shell (sidebar + topbar + content area).
 * Pages set a few variables before including this file:
 *
 *   $page_title       string  Document title and topbar label.
 *   $page_breadcrumbs array   [['label' => 'Dashboard', 'url' => '...'], ...]
 *   $active_nav       string  Navigation key to highlight in the sidebar.
 *   $extra_css        array   Extra stylesheet URLs.
 *   $extra_js         array   Extra script URLs (loaded in the footer).
 *   $body_class       string  Extra body classes.
 *
 * Every page ends by including includes/footer.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/nav.php';

// Pages that render the shell always require an authenticated session.
require_login();

$page_title       = $page_title       ?? 'Dashboard';
$page_breadcrumbs = $page_breadcrumbs ?? [['label' => $page_title]];
$active_nav       = $active_nav       ?? '';
$extra_css        = $extra_css        ?? [];
$extra_js         = $extra_js         ?? [];
$body_class       = $body_class       ?? '';

$documentTitle = $page_title . ' · ' . FLEETRA_NAME;
$user          = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e(FLEETRA_NAME . ' — ' . FLEETRA_TAGLINE) ?>">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($documentTitle) ?></title>

    <?php /* All vendor assets are served locally from assets/vendor so the
             application works with no internet connection. */ ?>
    <link href="<?= e(asset('vendor/fonts/inter.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">

    <link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset('css/responsive.css')) ?>" rel="stylesheet">

    <?php foreach ($extra_css as $stylesheet): ?>
        <link href="<?= e($stylesheet) ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>
<body class="<?= e($body_class) ?>">
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>

    <div class="app-main">
        <?php require __DIR__ . '/topbar.php'; ?>

        <main class="page-content">
            <div class="content-inner">
                <?php require __DIR__ . '/alerts.php'; ?>
