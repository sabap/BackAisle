<?php
declare(strict_types=1);

function ba_nav_on(string $nav, array $keys): string {
    return in_array($nav, $keys, true) ? 'on' : '';
}

function ba_layout_start(string $title, string $nav = 'fleet'): void {
    $u = ba_user();
    $role = $u['role'] ?? '';
    $powerOn = ba_nav_on($nav, ['fleet', 'batteries', 'battery', 'alerts', 'events', 'writes']);
    $envOn = ba_nav_on($nav, ['climate']);
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · BackAisle</title>
  <link rel="stylesheet" href="/assets/app.css?v=loctree1">
</head>
<body>
<header class="top">
  <div class="brand"><span class="mark">BA</span> BackAisle <small>IDF infrastructure</small></div>
  <nav>
    <a class="<?= $nav==='dash'?'on':'' ?>" href="/">Dashboard</a>
    <a class="<?= $nav==='idfs'?'on':'' ?>" href="/idfs">IDFs</a>
    <a class="<?= $nav==='devices'?'on':'' ?>" href="/devices">Inventory</a>
    <a class="<?= $nav==='templates'?'on':'' ?>" href="/templates">Templates</a>
    <span class="nav-cat <?= $powerOn ?>">
      <span class="nav-cat-label">Power</span>
      <a class="<?= $nav==='fleet'?'on':'' ?>" href="/fleet">UPS fleet</a>
      <a class="<?= $nav==='batteries'?'on':'' ?>" href="/batteries">Batteries</a>
      <a class="<?= $nav==='battery'?'on':'' ?>" href="/battery">Due</a>
      <a class="<?= $nav==='alerts'?'on':'' ?>" href="/alerts">Alerts</a>
      <a class="<?= $nav==='events'?'on':'' ?>" href="/events">Events</a>
      <?php if ($role === 'admin'): ?>
        <a class="<?= $nav==='writes'?'on':'' ?>" href="/writes">Writes</a>
      <?php endif; ?>
    </span>
    <span class="nav-cat <?= $envOn ?>">
      <span class="nav-cat-label">Environment</span>
      <a class="<?= $nav==='climate'?'on':'' ?>" href="/climate">Climate</a>
    </span>
    <?php if ($role === 'admin'): ?>
      <span class="nav-cat <?= ba_nav_on($nav, ['org','admin']) ?>">
        <span class="nav-cat-label">Admin</span>
        <a class="<?= $nav==='org'?'on':'' ?>" href="/org">Org</a>
        <a class="<?= $nav==='admin'?'on':'' ?>" href="/admin">Admin</a>
      </span>
    <?php endif; ?>
  </nav>
  <div class="who"><?= h($u['username'] ?? '') ?> · <?= h($role) ?> · <a href="/logout">out</a></div>
</header>
<main>
<?php
}

function ba_layout_end(): void {
    echo '</main><footer class="site-foot">BackAisle v'.h(ba_version()).' · <a href="https://github.com/sabap/BackAisle" target="_blank" rel="noopener">GitHub</a></footer>';
    echo '<script src="/assets/app.js?v=loctree1"></script></body></html>';
}
