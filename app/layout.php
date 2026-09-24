<?php
declare(strict_types=1);

function ba_nav_on(string $nav, array $keys): string {
    return in_array($nav, $keys, true) ? 'on' : '';
}

function ba_layout_start(string $title, string $nav = 'fleet'): void {
    $u = ba_user();
    $role = $u['role'] ?? '';
    $powerOn = ba_nav_on($nav, ['fleet', 'batteries', 'battery', 'alerts', 'events', 'writes', 'snmp']);
    $envOn = ba_nav_on($nav, ['climate']);
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · BackAisle</title>
  <link rel="stylesheet" href="/assets/app.css?v=tech1">
</head>
<body<?= ba_tech_mode() ? ' class="tech"' : '' ?>>
<header class="top">
  <div class="brand"><span class="mark">BA</span> BackAisle <small>IDF infrastructure</small></div>
  <nav>
    <a class="<?= $nav==='dash'?'on':'' ?>" href="/index.php">Dashboard</a>
    <a class="<?= $nav==='idfs'?'on':'' ?>" href="/idfs.php">IDFs</a>
    <a class="<?= $nav==='devices'?'on':'' ?>" href="/devices.php">Inventory</a>
    <?php if (!ba_tech_mode()): ?>
    <a class="<?= $nav==='templates'?'on':'' ?>" href="/templates.php">Templates</a>
    <?php endif; ?>
    <span class="nav-cat <?= $powerOn ?>">
      <span class="nav-cat-label">Power</span>
      <a class="<?= $nav==='snmp'?'on':'' ?>" href="/snmp.php">SNMP</a>
      <a class="<?= $nav==='fleet'?'on':'' ?>" href="/fleet.php">UPS fleet</a>
      <a class="<?= $nav==='batteries'?'on':'' ?>" href="/batteries.php">Batteries</a>
      <a class="<?= $nav==='battery'?'on':'' ?>" href="/battery.php">Due</a>
      <a class="<?= $nav==='alerts'?'on':'' ?>" href="/alerts.php">Alerts</a>
      <a class="<?= $nav==='events'?'on':'' ?>" href="/events.php">Events</a>
      <?php if ($role === 'admin'): ?>
        <a class="<?= $nav==='writes'?'on':'' ?>" href="/writes.php">Writes</a>
      <?php endif; ?>
    </span>
    <span class="nav-cat <?= $envOn ?>">
      <span class="nav-cat-label">Environment</span>
      <a class="<?= $nav==='climate'?'on':'' ?>" href="/climate.php">Climate</a>
    </span>
    <?php if ($role === 'admin' && !ba_tech_mode()): ?>
      <span class="nav-cat <?= ba_nav_on($nav, ['org','admin']) ?>">
        <span class="nav-cat-label">Admin</span>
        <a class="<?= $nav==='org'?'on':'' ?>" href="/org.php">Org</a>
        <a class="<?= $nav==='admin'?'on':'' ?>" href="/admin.php">Admin</a>
      </span>
    <?php endif; ?>
  </nav>
  <div class="who"><?php if (ba_tech_mode() && $nav !== 'dash') { ba_tech_toggle(true); } ?><?= h($u['username'] ?? '') ?> · <?= h($role) ?> · <a href="/logout.php">out</a></div>
</header>
<main>
<?php
}

function ba_layout_end(): void {
    echo '</main><footer class="site-foot">BackAisle v'.h(ba_version()).' · <a href="https://github.com/sabap/BackAisle" target="_blank" rel="noopener">GitHub</a></footer>';
    echo '<script src="/assets/app.js?v=rank3"></script></body></html>';
}
