<?php
declare(strict_types=1);

function ba_nav_on(string $nav, array $keys): string {
    return in_array($nav, $keys, true) ? 'on' : '';
}

function ba_layout_start(string $title, string $nav = 'fleet'): void {
    $u = ba_user() ?? [];
    $role = $u['role'] ?? '';
    $roleLabel = (string)($u['role_name'] ?? $role);
    $canOrg = function_exists('ba_editor') && ba_editor($u, 'edit_org');
    $canSettings = function_exists('ba_can') && ba_can($u, 'manage_settings');
    $canUsers = function_exists('ba_can') && ba_can($u, 'manage_users');
    $canWrites = function_exists('ba_editor') && ba_editor($u, 'edit_writes');
    $powerOn = ba_nav_on($nav, ['fleet', 'batteries', 'battery', 'alerts', 'events', 'writes', 'snmp']);
    $envOn = ba_nav_on($nav, ['climate']);
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · BackAisle</title>
  <link rel="stylesheet" href="/assets/app.css?v=layout3">
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
      <?php if ($canWrites): ?>
        <a class="<?= $nav==='writes'?'on':'' ?>" href="/writes.php">Writes</a>
      <?php endif; ?>
    </span>
    <span class="nav-cat <?= $envOn ?>">
      <span class="nav-cat-label">Environment</span>
      <a class="<?= $nav==='climate'?'on':'' ?>" href="/climate.php">Climate</a>
    </span>
    <?php if (($canOrg || $canSettings || $canUsers) && !ba_tech_mode()): ?>
      <span class="nav-cat <?= ba_nav_on($nav, ['org','admin','users']) ?>">
        <span class="nav-cat-label">Admin</span>
        <?php if ($canOrg): ?><a class="<?= $nav==='org'?'on':'' ?>" href="/org.php">Org</a><?php endif; ?>
        <?php if ($canSettings): ?><a class="<?= $nav==='admin'?'on':'' ?>" href="/admin.php">Admin</a><?php endif; ?>
        <?php if ($canUsers): ?><a class="<?= $nav==='users'?'on':'' ?>" href="/users.php">Users</a><?php endif; ?>
      </span>
    <?php endif; ?>
  </nav>
  <div class="who"><?php if (ba_tech_mode() && $nav !== 'dash') { ba_tech_toggle(true); } ?><?= h($u['username'] ?? '') ?> · <?= h($roleLabel) ?> · <a href="/logout.php">out</a></div>
</header>
<main>
<?php
}

function ba_card_open(string $title, string $actionsHtml = '', string $id = '', string $extraClass = ''): void
{
    $cls = 'ucard' . ($extraClass !== '' ? ' ' . $extraClass : '');
    echo '<section class="' . $cls . '"' . ($id !== '' ? ' id="' . h($id) . '"' : '') . '>';
    echo '<div class="ucard-head"><h3>' . h($title) . '</h3>';
    if ($actionsHtml !== '') {
        echo '<div class="head-actions">' . $actionsHtml . '</div>';
    }
    echo '</div><div class="ucard-body">';
}

function ba_card_close(): void
{
    echo '</div></section>';
}

function ba_users_modal_open(string $id, string $title, bool $open, string $closeHref = '', bool $wide = false): void
{
    $titleId = $id . '-title';
    echo '<div id="' . h($id) . '" class="ldaps-modal"' . ($open ? '' : ' hidden') . ' aria-hidden="' . ($open ? 'false' : 'true') . '"';
    if ($closeHref !== '') {
        echo ' data-close-href="' . h($closeHref) . '"';
    }
    echo '>';
    echo '<div class="ldaps-modal-backdrop" data-close-modal></div>';
    echo '<div class="ldaps-modal-panel' . ($wide ? ' wide' : '') . '" role="dialog" aria-modal="true" aria-labelledby="' . h($titleId) . '">';
    echo '<div class="ldaps-modal-head"><h3 id="' . h($titleId) . '">' . h($title) . '</h3>';
    echo '<button type="button" class="btn" data-close-modal>Close</button></div>';
}

function ba_users_modal_close(): void
{
    echo '</div></div>';
}

function ba_users_modal_script(): void
{
    echo <<<'JS'
<script>
(function () {
  function hide(m) {
    var href = m.getAttribute('data-close-href') || '';
    if (href) {
      window.location.href = href;
      return;
    }
    m.hidden = true;
    m.setAttribute('aria-hidden', 'true');
  }
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-open-modal]');
    if (opener) {
      var id = opener.getAttribute('data-open-modal');
      var modal = id ? document.getElementById(id) : null;
      if (!modal) return;
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      var field = modal.querySelector('input:not([type=hidden]), select, textarea');
      if (field) field.focus();
      return;
    }
    if (e.target.closest('[data-close-modal]')) {
      var modal = e.target.closest('.ldaps-modal');
      if (modal) hide(modal);
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.ldaps-modal:not([hidden])').forEach(hide);
  });
})();
</script>
JS;
}

function ba_layout_end(): void {
    echo '</main><footer class="site-foot">BackAisle v'.h(ba_version()).' · <a href="https://github.com/sabap/BackAisle" target="_blank" rel="noopener">GitHub</a></footer>';
    echo '<script src="/assets/app.js?v=layout1"></script>';
    if (function_exists('ba_users_modal_script')) {
        ba_users_modal_script();
    }
    echo '</body></html>';
}
