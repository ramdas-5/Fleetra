<?php $pageTitle=$pageTitle??'Dashboard'; $pageSubtitle=$pageSubtitle??''; ?>
<header class="topbar">
  <button class="icon-btn mobile-menu" id="menuBtn" aria-label="Menu">☰</button>
  <div class="title-wrap"><h1><?= htmlspecialchars($pageTitle) ?></h1><?php if($pageSubtitle): ?><p><?= htmlspecialchars($pageSubtitle) ?></p><?php endif; ?></div>
  <div class="top-actions">
    <span class="sync-pill"><i></i> All systems online</span>
    <button class="icon-btn" aria-label="Notifications"><?= icon('bell',18) ?><b class="notify-dot"></b></button>
    <div class="user-chip"><img src="assets/images/ananya.png" alt="Ananya"><div><strong>Ananya Das</strong><span>Administrator</span></div><span class="down">⌄</span></div>
  </div>
</header>