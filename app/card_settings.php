<?php
declare(strict_types=1);

/** CyberPower RMCARD settings sent as ready-made shell commands, not a config restore. */

function ba_device_is_cyberpower(array $row): bool
{
    $kind = strtolower(trim((string)($row['kind'] ?? 'ups')));
    if ($kind === '') {
        $kind = 'ups';
    }
    if (in_array($kind, ['switch', 'patch_panel'], true)) {
        return false;
    }
    $mfr = strtolower(trim((string)($row['manufacturer'] ?? '') . ' ' . (string)($row['template_manufacturer'] ?? '')));
    if (str_contains($mfr, 'cyberpower') || str_contains($mfr, 'cyber power')) {
        return true;
    }
    $model = strtoupper(trim((string)($row['model'] ?? '')));
    if ($model === '') {
        return false;
    }
    if (str_contains($model, 'RMCARD')) {
        return true;
    }
    return (bool)preg_match('/^(PR|OL|OR)\d/', $model);
}

function ba_card_settings_available(PDO $db, array $user): bool
{
    foreach (ba_card_device_rows($db) as $row) {
        if (ba_card_row_ready($user, $row)) {
            return true;
        }
    }
    return false;
}

function ba_write_kind_label(string $kind): string
{
    if ($kind === 'card_settings') {
        return 'Card settings';
    }
    return $kind;
}

function ba_card_attach_template_mfr(PDO $db, array $row): array
{
    if (trim((string)($row['template_manufacturer'] ?? '')) !== '') {
        return $row;
    }
    $tid = (int)($row['template_id'] ?? 0);
    $row['template_manufacturer'] = '';
    if ($tid < 1) {
        return $row;
    }
    $st = $db->prepare('SELECT manufacturer FROM device_templates WHERE id=?');
    $st->execute([$tid]);
    $row['template_manufacturer'] = (string)($st->fetchColumn() ?: '');
    return $row;
}

/** A person who can edit every target may open a card-settings job without Fleet Writes. */
function ba_card_job_visible(PDO $db, array $user, array $job): bool
{
    if ((string)($job['kind'] ?? '') !== 'card_settings') {
        return false;
    }
    if (ba_can($user, 'edit_devices_all')) {
        return true;
    }
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId < 1) {
        return false;
    }
    $st = $db->prepare('SELECT d.department_id FROM write_job_targets t JOIN devices d ON d.id = t.device_id WHERE t.job_id = ?');
    $st->execute([$jobId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return false;
    }
    foreach ($rows as $row) {
        if (!ba_can_edit_device($user, $row)) {
            return false;
        }
    }
    return true;
}

function ba_queue_card_settings(PDO $db, array $user): array
{
    $recipe = (string)($_POST['recipe'] ?? '');
    if (!in_array($recipe, ['identity', 'utility', 'restore', 'sensor'], true)) {
        throw new RuntimeException('Choose a setting');
    }
    $fields = ba_card_fields($recipe);
    $simulate = isset($_POST['simulate']) && (string)$_POST['simulate'] === '1';
    if (!$simulate && trim((string)($_POST['confirm'] ?? '')) !== 'APPLY CARD') {
        throw new RuntimeException('Type APPLY CARD to change the cards');
    }
    $wanted = $_POST['device_ids'] ?? [];
    if (!is_array($wanted)) {
        $wanted = [];
    }
    $ids = [];
    foreach ($wanted as $raw) {
        $id = (int)$raw;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        throw new RuntimeException('Select at least one CyberPower card');
    }
    $byId = [];
    foreach (ba_card_device_rows($db) as $row) {
        $byId[(int)$row['id']] = $row;
    }
    $targets = [];
    $skipped = 0;
    foreach ($ids as $id) {
        $row = $byId[$id] ?? null;
        if ($row === null || !ba_card_row_ready($user, $row)) {
            $skipped++;
            continue;
        }
        $targets[] = $row;
    }
    if (!$targets) {
        throw new RuntimeException('None of the selected devices are CyberPower cards you can edit');
    }
    $db->prepare('INSERT INTO write_jobs (kind, status, simulate, stop_on_error, created_by, payload_json, template_id, firmware_id) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            'card_settings',
            'queued',
            $simulate ? 1 : 0,
            0,
            (string)($user['username'] ?? ''),
            json_encode(['recipe' => $recipe, 'fields' => $fields], JSON_UNESCAPED_SLASHES),
            null,
            null,
        ]);
    $jid = ba_last_id($db);
    if ($jid < 1) {
        throw new RuntimeException('Could not save the card settings job');
    }
    $ins = $db->prepare("INSERT INTO write_job_targets (job_id, device_id, ip, hostname, status) VALUES (?,?,?,?, 'queued')");
    $ips = [];
    foreach ($targets as $row) {
        $ins->execute([$jid, (int)$row['id'], (string)$row['ip'], (string)($row['hostname'] ?? '')]);
        $ips[] = (string)$row['ip'];
    }
    ba_audit($db, 'write_job', 'card_settings', (string)$jid, json_encode([
        'recipe' => $recipe,
        'targets' => $ips,
        'simulate' => $simulate,
        'skipped' => $skipped,
    ]));
    $n = count($targets);
    $message = 'Queued card settings for ' . $n . ($n === 1 ? ' card' : ' cards');
    if ($skipped > 0) {
        $message .= '. Skipped ' . $skipped . ' that are not CyberPower cards you can edit';
    }
    return ['job_id' => $jid, 'message' => $message];
}

function ba_card_settings_modal(bool $bulk, int $deviceId = 0): void
{
    ba_users_modal_open('modal-card-settings', 'Card settings', false, '', false);
    echo '<form method="post" class="stack" id="card-settings-form"' . ($bulk ? ' data-bulk="1"' : '') . '>';
    echo '<input type="hidden" name="act" value="card_settings">';
    if (!$bulk && $deviceId > 0) {
        echo '<input type="hidden" name="device_ids[]" value="' . $deviceId . '">';
    }
    echo '<p class="muted">One setting at a time, sent over SSH. Empty boxes stay as they are on the card. The card stays up. Switches and other brands are skipped.</p>';
    echo '<p class="muted" id="card-settings-need" hidden>Select at least one CyberPower card in the list.</p>';
    echo '<div class="card-setting">';
    echo '<input type="radio" name="recipe" id="recipe-identity" value="identity" checked>';
    echo '<input type="radio" name="recipe" id="recipe-utility" value="utility">';
    echo '<input type="radio" name="recipe" id="recipe-restore" value="restore">';
    echo '<input type="radio" name="recipe" id="recipe-sensor" value="sensor">';
    echo '<div class="card-recipe-bar">';
    echo '<label for="recipe-identity">Identity</label>';
    echo '<label for="recipe-utility">Utility limits</label>';
    echo '<label for="recipe-restore">Restore timing</label>';
    echo '<label for="recipe-sensor">Closet sensor</label>';
    echo '</div>';
    echo '<div class="card-panes">';
    echo '<div class="card-pane pane-identity">';
    echo '<label for="id-name">Name</label><input id="id-name" name="id_name" maxlength="63" autocomplete="off">';
    echo '<label for="id-location">Location</label><input id="id-location" name="id_location" maxlength="127" autocomplete="off">';
    echo '<label for="id-contact">Contact</label><input id="id-contact" name="id_contact" maxlength="63" autocomplete="off">';
    echo '<p class="muted">Name and contact can be 63 characters. Location can be 127 on firmware 1.4.3 and newer.</p>';
    echo '</div>';
    echo '<div class="card-pane pane-utility">';
    echo '<label for="card-sens">Sensitivity</label><select id="card-sens" name="sensitivity">';
    echo '<option value="">Leave unchanged</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>';
    echo '</select>';
    echo '<div class="card-pair">';
    echo '<div><label for="card-lowv">Low voltage</label><input id="card-lowv" name="low_volts" inputmode="numeric" maxlength="3" autocomplete="off"></div>';
    echo '<div><label for="card-highv">High voltage</label><input id="card-highv" name="high_volts" inputmode="numeric" maxlength="3" autocomplete="off"></div>';
    echo '</div>';
    echo '<label for="card-outv">Output voltage</label><input id="card-outv" name="output_volts" inputmode="numeric" maxlength="3" autocomplete="off">';
    echo '<p class="muted">Volts AC. Leave a box blank to keep the card value. The card rejects a voltage that model cannot use.</p>';
    echo '</div>';
    echo '<div class="card-pane pane-restore">';
    echo '<label for="card-rdelay">Recharge delay</label><select id="card-rdelay" name="recharge_delay">';
    echo ba_card_delay_options();
    echo '</select>';
    echo '<label for="card-rcap">Recharge capacity</label><select id="card-rcap" name="recharge_capacity">';
    echo '<option value="">Leave unchanged</option>';
    foreach ([0, 15, 30, 45, 60, 75, 90] as $pct) {
        echo '<option value="' . $pct . '">' . $pct . '%</option>';
    }
    echo '</select>';
    echo '<label for="card-return">Return delay (seconds)</label><input id="card-return" name="return_delay" inputmode="numeric" maxlength="3" autocomplete="off">';
    echo '<p class="muted">Return delay is 0 to 600, in steps of 5. Blank keeps the current delay.</p>';
    echo '</div>';
    echo '<div class="card-pane pane-sensor">';
    echo '<label for="card-sindex">Sensor</label><select id="card-sindex" name="sensor_index">';
    for ($i = 1; $i <= 8; $i++) {
        echo '<option value="' . $i . '">' . $i . '</option>';
    }
    echo '</select>';
    echo '<label for="sensor-name">Sensor name</label><input id="sensor-name" name="sensor_name" maxlength="63" autocomplete="off">';
    echo '<label for="sensor-location">Sensor location</label><input id="sensor-location" name="sensor_location" maxlength="63" autocomplete="off">';
    echo '<div class="card-pair">';
    echo '<div><label for="card-tlo">Low temperature</label><input id="card-tlo" name="temp_low" inputmode="numeric" maxlength="4" autocomplete="off"></div>';
    echo '<div><label for="card-thi">High temperature</label><input id="card-thi" name="temp_high" inputmode="numeric" maxlength="4" autocomplete="off"></div>';
    echo '</div>';
    echo '<div class="card-pair">';
    echo '<div><label for="card-hlo">Low humidity %</label><input id="card-hlo" name="humid_low" inputmode="numeric" maxlength="3" autocomplete="off"></div>';
    echo '<div><label for="card-hhi">High humidity %</label><input id="card-hhi" name="humid_high" inputmode="numeric" maxlength="3" autocomplete="off"></div>';
    echo '</div>';
    echo '<p class="muted">Uses the temperature unit already set on the card. Firmware 1.4.1 or newer.</p>';
    echo '</div>';
    echo '</div></div>';
    echo '<input type="hidden" name="simulate" value="0">';
    echo '<label><input type="checkbox" name="simulate" value="1" checked> Simulate (show the commands, do not change the card)</label>';
    echo '<p class="muted">Leave Simulate checked for a dry run. Uncheck it and type APPLY CARD to change the cards.</p>';
    echo '<label for="card-confirm">Type APPLY CARD to change the cards</label>';
    echo '<input id="card-confirm" name="confirm" autocomplete="off">';
    echo '<button>Queue this setting</button>';
    echo '</form>';
    echo <<<'JS'
<script>
(function () {
  var form = document.getElementById("card-settings-form");
  if (!form) return;
  form.addEventListener("submit", function (ev) {
    if (form.getAttribute("data-bulk") === "1") {
      form.querySelectorAll('input[data-card-target="1"]').forEach(function (node) { node.remove(); });
      var count = 0;
      document.querySelectorAll('input[name="device_ids[]"]:checked').forEach(function (box) {
        var hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = "device_ids[]";
        hidden.value = box.value;
        hidden.setAttribute("data-card-target", "1");
        form.appendChild(hidden);
        count++;
      });
      var need = document.getElementById("card-settings-need");
      if (!count) {
        ev.preventDefault();
        if (need) need.hidden = false;
      } else if (need) {
        need.hidden = true;
      }
    }
  });
  document.addEventListener("click", function (ev) {
    var opener = ev.target.closest ? ev.target.closest('[data-open-modal="modal-card-settings"]') : null;
    if (!opener) return;
    setTimeout(function () {
      var name = document.getElementById("id-name");
      if (name) name.focus();
    }, 0);
  });
})();
</script>
JS;
    ba_users_modal_close();
}

function ba_card_device_rows(PDO $db): array
{
    $sql = 'SELECT d.id, d.ip, d.hostname, d.kind, d.manufacturer, d.model, d.enabled, d.is_simulated, d.department_id, d.decommissioned_at, t.manufacturer AS template_manufacturer
            FROM devices d
            LEFT JOIN device_templates t ON t.id = d.template_id';
    return $db->query($sql)->fetchAll();
}

function ba_card_row_ready(array $user, array $row): bool
{
    if (!ba_can_edit_device($user, $row) || !ba_device_is_cyberpower($row)) {
        return false;
    }
    if (trim((string)($row['decommissioned_at'] ?? '')) !== '') {
        return false;
    }
    $enabled = $row['enabled'] ?? 1;
    if ($enabled === 0 || $enabled === '0' || $enabled === false) {
        return false;
    }
    $sim = $row['is_simulated'] ?? 0;
    if ($sim === 1 || $sim === '1' || $sim === true) {
        return false;
    }
    return filter_var((string)($row['ip'] ?? ''), FILTER_VALIDATE_IP) !== false;
}

function ba_card_fields(string $recipe): array
{
    $out = [];
    if ($recipe === 'identity') {
        foreach (['id_name' => ['name', 63, 'Name'], 'id_location' => ['location', 127, 'Location'], 'id_contact' => ['contact', 63, 'Contact']] as $post => $spec) {
            $raw = trim((string)($_POST[$post] ?? ''));
            if ($raw !== '') {
                $out[$spec[0]] = ba_card_text($raw, $spec[1], $spec[2]);
            }
        }
    } elseif ($recipe === 'utility') {
        $sens = strtolower(trim((string)($_POST['sensitivity'] ?? '')));
        if ($sens !== '') {
            if (!in_array($sens, ['low', 'medium', 'high'], true)) {
                throw new RuntimeException('Sensitivity must be low, medium, or high');
            }
            $out['sensitivity'] = $sens;
        }
        foreach (['low_volts' => 'Low voltage', 'high_volts' => 'High voltage', 'output_volts' => 'Output voltage'] as $key => $label) {
            $raw = trim((string)($_POST[$key] ?? ''));
            if ($raw !== '') {
                $out[$key] = ba_card_int($raw, 50, 300, $label);
            }
        }
        if (isset($out['high_volts'], $out['low_volts']) && $out['high_volts'] <= $out['low_volts']) {
            throw new RuntimeException('High voltage has to be above the low voltage');
        }
    } elseif ($recipe === 'restore') {
        $delay = trim((string)($_POST['recharge_delay'] ?? ''));
        if ($delay !== '') {
            $out['recharge_delay'] = ba_card_choice($delay, [0, 60, 120, 180, 300, 600, 1200, 1800, 3600], 'Recharge delay');
        }
        $cap = trim((string)($_POST['recharge_capacity'] ?? ''));
        if ($cap !== '') {
            $out['recharge_capacity'] = ba_card_choice($cap, [0, 15, 30, 45, 60, 75, 90], 'Recharge capacity');
        }
        $back = trim((string)($_POST['return_delay'] ?? ''));
        if ($back !== '') {
            $seconds = ba_card_int($back, 0, 600, 'Return delay');
            if ($seconds % 5 !== 0) {
                throw new RuntimeException('Return delay must be a multiple of 5 seconds');
            }
            $out['return_delay'] = $seconds;
        }
    } else {
        $index = ba_card_int(trim((string)($_POST['sensor_index'] ?? '1')), 1, 8, 'Sensor');
        $named = false;
        foreach (['sensor_name' => ['name', 63, 'Sensor name'], 'sensor_location' => ['location', 63, 'Sensor location']] as $post => $spec) {
            $raw = trim((string)($_POST[$post] ?? ''));
            if ($raw !== '') {
                $out[$spec[0]] = ba_card_text($raw, $spec[1], $spec[2]);
                $named = true;
            }
        }
        foreach (['temp_low' => 'Low temperature', 'temp_high' => 'High temperature'] as $key => $label) {
            $raw = trim((string)($_POST[$key] ?? ''));
            if ($raw !== '') {
                $out[$key] = ba_card_int($raw, -40, 250, $label);
                $named = true;
            }
        }
        foreach (['humid_low' => 'Low humidity', 'humid_high' => 'High humidity'] as $key => $label) {
            $raw = trim((string)($_POST[$key] ?? ''));
            if ($raw !== '') {
                $out[$key] = ba_card_int($raw, 0, 100, $label);
                $named = true;
            }
        }
        if (isset($out['temp_high'], $out['temp_low']) && $out['temp_high'] <= $out['temp_low']) {
            throw new RuntimeException('High temperature has to be above the low temperature');
        }
        if (isset($out['humid_high'], $out['humid_low']) && $out['humid_high'] <= $out['humid_low']) {
            throw new RuntimeException('High humidity has to be above the low humidity');
        }
        if (!$named) {
            throw new RuntimeException('Enter at least one sensor value');
        }
        $out = ['index' => $index] + $out;
    }
    if (!$out) {
        throw new RuntimeException('Enter at least one value. Blank fields stay unchanged');
    }
    return $out;
}

function ba_card_text(string $raw, int $max, string $label): string
{
    if (strlen($raw) > $max || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 .,_\/#()+\-]*$/', $raw)) {
        throw new RuntimeException($label . ' can use letters, numbers, spaces, and . , _ - / # ( ) +');
    }
    return $raw;
}

function ba_card_int(string $raw, int $min, int $max, string $label): int
{
    if (!preg_match('/^-?\d+$/', $raw)) {
        throw new RuntimeException($label . ' must be a whole number');
    }
    $number = (int)$raw;
    if ((string)$number !== $raw) {
        throw new RuntimeException($label . ' must be a whole number');
    }
    if ($number < $min || $number > $max) {
        throw new RuntimeException($label . ' must be between ' . $min . ' and ' . $max);
    }
    return $number;
}

function ba_card_choice(string $raw, array $allowed, string $label): int
{
    $number = ba_card_int($raw, min($allowed), max($allowed), $label);
    if (!in_array($number, $allowed, true)) {
        throw new RuntimeException($label . ' is not one of the card choices');
    }
    return $number;
}

function ba_card_delay_options(): string
{
    $html = '<option value="">Leave unchanged</option>';
    $labels = [
        0 => '0 seconds',
        60 => '1 minute',
        120 => '2 minutes',
        180 => '3 minutes',
        300 => '5 minutes',
        600 => '10 minutes',
        1200 => '20 minutes',
        1800 => '30 minutes',
        3600 => '60 minutes',
    ];
    foreach ($labels as $value => $label) {
        $html .= '<option value="' . $value . '">' . $label . '</option>';
    }
    return $html;
}
