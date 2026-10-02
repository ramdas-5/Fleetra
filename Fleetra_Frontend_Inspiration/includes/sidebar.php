<?php
$nav = [
 ['dashboard.php','home','Dashboard'],['live-operations.php','map','Live Operations'],['fleet.php','bus','Fleet'],['routes-network.php','route','Routes'],['schedules.php','calendar','Schedules'],['bookings.php','ticket','Bookings'],['passengers.php','users','Passengers'],['drivers.php','user','Drivers'],['maintenance.php','wrench','Maintenance'],['incident-center.php','alert','Incidents'],['analytics.php','chart','Analytics'],['settings.php','settings','Settings']
];
$current=basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="sidebar">
  <a class="brand" href="dashboard.php"><img src="assets/images/logo.png" alt="Fleetra"></a>
  <nav class="main-nav">
    <?php foreach($nav as $n): ?><a class="nav-item <?= $current===$n[0]?'active':'' ?>" href="<?= $n[0] ?>"><?= icon($n[1],18) ?><span><?= $n[2] ?></span></a><?php endforeach; ?>
  </nav>
  <div class="sidebar-visual"><img src="assets/images/skyline.png" alt=""><p>Greener cities.<br><b>Smarter journeys.</b></p></div>
  <a class="nav-item signout" href="login.php"><?= icon('logout',18) ?><span>Sign out</span></a>
</aside>