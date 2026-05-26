<?php
declare(strict_types=1);

// Gate so claims.php refuses to expose its array if hit any other way.
define('DASHBOARD', true);

session_start();

// ── Paths ─────────────────────────────────────────────────────────────────
$ROOT             = __DIR__;
$EVENTS_FILE      = $ROOT . '/events.json';
$CLAIMS_FILE      = $ROOT . '/claims.php';
$CLAIM_RATE_FILE  = $ROOT . '/claim_rate.php';
$DATA_JS_FILE     = $ROOT . '/data.js';

$DAYS = ['Thursday', 'Friday', 'Saturday', 'Sunday', 'Monday'];
$MIN_PASS_LEN          = 8;
$CLAIM_COOLDOWN_SECS   = 30 * 60;   // one successful claim per IP per 30 min

// ── Helpers ───────────────────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(400);
        exit('CSRF token mismatch. Please reload and try again.');
    }
}

function hash_algo() {
    // Argon2id is the safest modern PHP option. Fall back to default (bcrypt
    // on older builds) if it isn't compiled in.
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function read_flashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect_self(): void {
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $path);
    exit;
}

function load_claims(): array {
    global $CLAIMS_FILE;
    if (!file_exists($CLAIMS_FILE)) return [];
    $claims = include $CLAIMS_FILE;
    return is_array($claims) ? $claims : [];
}

function save_claims(array $claims): void {
    global $CLAIMS_FILE;
    $body = "<?php\n"
          . "// Auto-generated file. Do not edit by hand.\n"
          . "// Stores camp name => password_hash() output.\n"
          . "defined('DASHBOARD') or exit;\n"
          . "return " . var_export($claims, true) . ";\n";
    $tmp = $CLAIMS_FILE . '.tmp';
    file_put_contents($tmp, $body, LOCK_EX);
    rename($tmp, $CLAIMS_FILE);
    @chmod($CLAIMS_FILE, 0640);
}

function client_ip(): string {
    // REMOTE_ADDR is the only thing we can trust without a configured reverse
    // proxy. X-Forwarded-For would be spoofable by any client.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip !== '' ? $ip : 'unknown';
}

function load_claim_rate(): array {
    global $CLAIM_RATE_FILE;
    if (!file_exists($CLAIM_RATE_FILE)) return [];
    $data = include $CLAIM_RATE_FILE;
    return is_array($data) ? $data : [];
}

function save_claim_rate(array $data): void {
    global $CLAIM_RATE_FILE, $CLAIM_COOLDOWN_SECS;
    // Prune expired entries so the file doesn't grow forever.
    $cutoff = time() - $CLAIM_COOLDOWN_SECS;
    foreach ($data as $ip => $ts) {
        if ((int)$ts < $cutoff) unset($data[$ip]);
    }
    $body = "<?php\n"
          . "// Auto-generated. IP => unix-timestamp of last successful claim.\n"
          . "defined('DASHBOARD') or exit;\n"
          . "return " . var_export($data, true) . ";\n";
    $tmp = $CLAIM_RATE_FILE . '.tmp';
    file_put_contents($tmp, $body, LOCK_EX);
    rename($tmp, $CLAIM_RATE_FILE);
    @chmod($CLAIM_RATE_FILE, 0640);
}

function claim_cooldown_remaining(): int {
    global $CLAIM_COOLDOWN_SECS;
    $rates = load_claim_rate();
    $ip    = client_ip();
    if (!isset($rates[$ip])) return 0;
    $remaining = ((int)$rates[$ip] + $CLAIM_COOLDOWN_SECS) - time();
    return max(0, $remaining);
}

function record_claim_attempt(): void {
    $rates = load_claim_rate();
    $rates[client_ip()] = time();
    save_claim_rate($rates);
}

function format_remaining(int $seconds): string {
    if ($seconds <= 0)  return 'now';
    if ($seconds < 60)  return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    $mins = (int) ceil($seconds / 60);
    return $mins . ' minute' . ($mins === 1 ? '' : 's');
}

function load_events(): array {
    global $EVENTS_FILE;
    $raw = file_get_contents($EVENTS_FILE);
    if ($raw === false) {
        http_response_code(500);
        exit('Could not read events.json');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(500);
        exit('events.json is not valid JSON.');
    }
    return $data;
}

function recompute_metadata(array $data): array {
    $counts = [
        'camp' => 0, 'camp_events' => 0,
        'sound_stage' => 0, 'sound_stage_events' => 0,
        'mutant_vehicle' => 0, 'mutant_vehicle_events' => 0,
        'art_installation' => 0, 'art_installation_events' => 0,
    ];
    $entryCount = 0; $eventCount = 0;
    foreach ($data['entries'] ?? [] as $entry) {
        $entryCount++;
        $t = $entry['type'] ?? '';
        if (isset($counts[$t])) $counts[$t]++;
        $n = count($entry['events'] ?? []);
        $eventCount += $n;
        if (isset($counts[$t . '_events'])) $counts[$t . '_events'] += $n;
    }
    $data['metadata']['entryCount'] = $entryCount;
    $data['metadata']['eventCount'] = $eventCount;
    $data['metadata']['countsByType'] = $counts;
    return $data;
}

function save_events(array $data): void {
    global $EVENTS_FILE, $DATA_JS_FILE;
    $data = recompute_metadata($data);

    // Bake the claim flag into events.json so the client (which reads events.json
    // directly) can render the Verified pill without depending on data.js.
    $claims = load_claims();
    foreach ($data['entries'] as &$entry) {
        $entry['claimed'] = isset($claims[$entry['name']]);
    }
    unset($entry);

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $tmp = $EVENTS_FILE . '.tmp';

    // Snapshot the pre-edit events.json into /var/www/otherworld-versions/events/
    // before overwriting. Dedup-on-identical happens inside the helper.
    if (is_file('/usr/local/bin/otherworld-snapshot') && is_file($EVENTS_FILE)) {
        $snapOut = [];
        $snapRc  = 0;
        @exec('/usr/local/bin/otherworld-snapshot events.json 2>&1', $snapOut, $snapRc);
        if ($snapRc !== 0) {
            error_log('otherworld-snapshot failed (rc=' . $snapRc . '): ' . implode(' | ', $snapOut));
        }
    }

    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $EVENTS_FILE);

    regenerate_data_js($data);
}

function regenerate_data_js(?array $data = null): void {
    global $DATA_JS_FILE;
    if ($data === null) $data = load_events();
    $claims = load_claims();
    foreach ($data['entries'] as &$entry) {
        $entry['claimed'] = isset($claims[$entry['name']]);
    }
    unset($entry);
    $body = "window.OTHERWORLD_DATA = "
          . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
          . ";\n";
    $tmp = $DATA_JS_FILE . '.tmp';
    file_put_contents($tmp, $body, LOCK_EX);
    rename($tmp, $DATA_JS_FILE);
}

function get_entry_index(array $data, string $campName): ?int {
    foreach ($data['entries'] as $i => $entry) {
        if (($entry['name'] ?? '') === $campName) return $i;
    }
    return null;
}

function valid_time(string $t): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function time_options(): array {
    // 30-minute increments covering the full 24h day. Grouped by period so the
    // dropdown is easy to scan (festival hours wrap past midnight).
    $groups = [
        'Late night (00–05)' => [],
        'Morning (06–11)'    => [],
        'Afternoon (12–16)'  => [],
        'Evening (17–20)'    => [],
        'Night (21–23)'      => [],
    ];
    for ($h = 0; $h < 24; $h++) {
        foreach ([0, 30] as $m) {
            $t = sprintf('%02d:%02d', $h, $m);
            if      ($h < 6)  $groups['Late night (00–05)'][] = $t;
            elseif  ($h < 12) $groups['Morning (06–11)'][]    = $t;
            elseif  ($h < 17) $groups['Afternoon (12–16)'][]  = $t;
            elseif  ($h < 21) $groups['Evening (17–20)'][]    = $t;
            else              $groups['Night (21–23)'][]      = $t;
        }
    }
    return $groups;
}

function render_time_select(string $name, string $current): string {
    $groups   = time_options();
    $allFlat  = array_merge(...array_values($groups));
    $out      = '<select name="' . h($name) . '" required>';
    $out     .= '<option value="">— pick a time —</option>';

    // Preserve unusual existing values (e.g. 18:15) that don't fall on our
    // 30-minute grid so editing doesn't silently change them.
    if ($current !== '' && !in_array($current, $allFlat, true)) {
        $out .= '<option value="' . h($current) . '" selected>' . h($current) . ' (custom)</option>';
    }
    foreach ($groups as $label => $times) {
        $out .= '<optgroup label="' . h($label) . '">';
        foreach ($times as $t) {
            $sel = ($t === $current) ? ' selected' : '';
            $out .= '<option value="' . h($t) . '"' . $sel . '>' . h($t) . '</option>';
        }
        $out .= '</optgroup>';
    }
    $out .= '</select>';
    return $out;
}

function normalize_event(array $in, string $campType): array {
    $title       = trim((string)($in['title'] ?? ''));
    $description = trim((string)($in['description'] ?? ''));
    $day         = trim((string)($in['day'] ?? ''));
    $startTime   = trim((string)($in['startTime'] ?? ''));
    $endTime     = trim((string)($in['endTime'] ?? ''));

    $duration = null;
    $crosses  = false;
    if (valid_time($startTime) && valid_time($endTime)) {
        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));
        $startMin = $sh * 60 + $sm;
        $endMin   = $eh * 60 + $em;
        if ($endMin <= $startMin) { $endMin += 24 * 60; $crosses = true; }
        $duration = round(($endMin - $startMin) / 60, 2);
    }
    return [
        'owner'              => '',           // filled in by caller
        'ownerType'          => $campType,
        'title'              => $title,
        'description'        => $description,
        'day'                => $day,
        'startTime'          => $startTime,
        'endTime'            => $endTime,
        'durationHours'      => $duration,
        'crossesMidnight'    => $crosses,
        'normalizationFlags' => [],
        'rawTimeText'        => trim($day . ' ' . $startTime . ' - ' . $endTime),
    ];
}

function validate_event_input(array $in, array $days): ?string {
    $title     = trim((string)($in['title'] ?? ''));
    $day       = trim((string)($in['day'] ?? ''));
    $startTime = trim((string)($in['startTime'] ?? ''));
    $endTime   = trim((string)($in['endTime'] ?? ''));
    if ($title === '')             return 'Title is required.';
    if (!in_array($day, $days, true)) return 'Pick a valid day.';
    if (!valid_time($startTime))   return 'Start time must be HH:MM (24h).';
    if (!valid_time($endTime))     return 'End time must be HH:MM (24h).';
    return null;
}

function require_camp_auth(string $camp): void {
    if (($_SESSION['camp'] ?? null) !== $camp || $camp === '') {
        http_response_code(403);
        exit('Not authenticated for this camp.');
    }
}

// ── Actions (POST) ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    verify_csrf();

    if ($action === 'logout') {
        unset($_SESSION['camp']);
        flash('ok', 'Logged out.');
        redirect_self();
    }

    if ($action === 'claim') {
        $remaining = claim_cooldown_remaining();
        if ($remaining > 0) {
            flash('err', 'You\'ve claimed a camp from this network recently. Please wait '
                . format_remaining($remaining) . ' before claiming another.');
            redirect_self();
        }

        $camp    = trim((string)($_POST['camp'] ?? ''));
        $pass    = (string)($_POST['passphrase'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        $claims  = load_claims();
        $data    = load_events();

        if (get_entry_index($data, $camp) === null) {
            flash('err', 'Camp not found.');
        } elseif (isset($claims[$camp])) {
            flash('err', 'This camp is already claimed. Use the unlock form instead.');
        } elseif (strlen($pass) < $MIN_PASS_LEN) {
            flash('err', "Passphrase must be at least {$MIN_PASS_LEN} characters.");
        } elseif ($pass !== $confirm) {
            flash('err', 'Passphrases do not match.');
        } else {
            $claims[$camp] = password_hash($pass, hash_algo());
            save_claims($claims);
            save_events(load_events());
            record_claim_attempt();
            $_SESSION['camp'] = $camp;
            flash('ok', 'Claim successful. You can now edit events for "' . $camp . '".');
        }
        redirect_self();
    }

    if ($action === 'unlock') {
        $camp   = trim((string)($_POST['camp'] ?? ''));
        $pass   = (string)($_POST['passphrase'] ?? '');
        $claims = load_claims();

        if (!isset($claims[$camp])) {
            flash('err', 'That camp has not been claimed yet. Set a passphrase to claim it.');
        } elseif (!password_verify($pass, $claims[$camp])) {
            flash('err', 'Wrong passphrase.');
        } else {
            $_SESSION['camp'] = $camp;
            flash('ok', 'Unlocked "' . $camp . '".');
        }
        redirect_self();
    }

    if ($action === 'add_event') {
        $camp = $_SESSION['camp'] ?? '';
        require_camp_auth($camp);
        $data = load_events();
        $idx  = get_entry_index($data, $camp);
        if ($idx === null) {
            flash('err', 'Camp not found.');
        } elseif ($msg = validate_event_input($_POST, $DAYS)) {
            flash('err', $msg);
        } else {
            $type = $data['entries'][$idx]['type'] ?? 'camp';
            $ev   = normalize_event($_POST, $type);
            $ev['owner'] = $camp;
            $data['entries'][$idx]['events'][] = $ev;
            save_events($data);
            flash('ok', 'Event added.');
        }
        redirect_self();
    }

    if ($action === 'edit_event') {
        $camp = $_SESSION['camp'] ?? '';
        require_camp_auth($camp);
        $data       = load_events();
        $idx        = get_entry_index($data, $camp);
        $eventIdx   = filter_var($_POST['event_index'] ?? '', FILTER_VALIDATE_INT);
        if ($idx === null || $eventIdx === false || !isset($data['entries'][$idx]['events'][$eventIdx])) {
            flash('err', 'Event not found.');
        } elseif ($msg = validate_event_input($_POST, $DAYS)) {
            flash('err', $msg);
        } else {
            $type = $data['entries'][$idx]['type'] ?? 'camp';
            $ev   = normalize_event($_POST, $type);
            $ev['owner'] = $camp;
            $data['entries'][$idx]['events'][$eventIdx] = $ev;
            save_events($data);
            flash('ok', 'Event updated.');
        }
        redirect_self();
    }

    if ($action === 'delete_event') {
        $camp = $_SESSION['camp'] ?? '';
        require_camp_auth($camp);
        $data     = load_events();
        $idx      = get_entry_index($data, $camp);
        $eventIdx = filter_var($_POST['event_index'] ?? '', FILTER_VALIDATE_INT);
        if ($idx === null || $eventIdx === false || !isset($data['entries'][$idx]['events'][$eventIdx])) {
            flash('err', 'Event not found.');
        } else {
            array_splice($data['entries'][$idx]['events'], $eventIdx, 1);
            save_events($data);
            flash('ok', 'Event deleted.');
        }
        redirect_self();
    }

    // Unknown action — just bounce back.
    redirect_self();
}

// ── View state ────────────────────────────────────────────────────────────
$claims     = load_claims();
$data       = load_events();
$authedCamp = $_SESSION['camp'] ?? null;
$flashes    = read_flashes();

// Sort camp names case-insensitively.
$entries = $data['entries'];
usort($entries, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$campEntry  = null;
$campEvents = [];
if ($authedCamp) {
    $idx = get_entry_index($data, $authedCamp);
    if ($idx !== null) {
        $campEntry  = $data['entries'][$idx];
        $campEvents = $campEntry['events'];
    } else {
        unset($_SESSION['camp']);
        $authedCamp = null;
    }
}

$claimedNames     = array_keys($claims);
$claimCooldown    = claim_cooldown_remaining();
$claimCooldownTxt = $claimCooldown > 0 ? format_remaining($claimCooldown) : '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Otherworld 2026 — Camp Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT@9..144,400..700,0..100&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --night-0: #04090c;
      --night-1: #0a1620;
      --night-2: #122332;
      --night-3: #1a3045;
      --night-4: #244257;
      --moss-1: rgba(120, 180, 200, 0.10);
      --moss-2: rgba(120, 180, 200, 0.22);
      --moss-3: #6e8a9c;
      --cream: #f2ead0;
      --cream-soft: #d4cea7;
      --cream-dim: #95a3a9;
      --lime: #cce84e;
      --lime-soft: #e6f099;
      --lime-deep: #7ea529;
      --pink: #ff86bd;
      --cyan: #74dce8;
      --amber: #ffae5a;
      --danger: #ff6e6e;
      --font-display: "Fraunces", Georgia, serif;
      --font-sans: "Inter", -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
      --font-mono: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace;
      --radius: 10px;
      --radius-lg: 14px;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: var(--font-sans);
      background:
        radial-gradient(ellipse 80% 50% at 12% -10%, rgba(204, 232, 78, 0.10), transparent 60%),
        radial-gradient(ellipse 70% 60% at 92% 10%, rgba(255, 134, 189, 0.08), transparent 60%),
        radial-gradient(ellipse 80% 60% at 50% 110%, rgba(116, 220, 232, 0.07), transparent 60%),
        var(--night-1);
      color: var(--cream);
      line-height: 1.5;
      font-feature-settings: "ss01", "cv11";
    }
    ::selection { background: var(--lime); color: var(--night-0); }
    a { color: var(--lime-soft); }

    header {
      padding: 32px 28px 18px;
      border-bottom: 1px solid var(--moss-2);
      background: linear-gradient(180deg, rgba(4,9,12,0.5), transparent);
    }
    .header-row {
      display: flex; align-items: baseline; gap: 18px; flex-wrap: wrap;
      max-width: 1100px; margin: 0 auto;
    }
    h1 {
      font-family: var(--font-display);
      font-weight: 500;
      font-size: 30px;
      letter-spacing: -0.01em;
      margin: 0;
      color: var(--cream);
    }
    h1 .accent { color: var(--lime); }

    h1.logo-h1 {
      font-size: 0;
      line-height: 1;
      margin: 0;
    }
    h1.logo-h1 a {
      display: inline-flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
      text-decoration: none;
      color: inherit;
    }
    .logo {
      height: 56px;
      width: auto;
      display: block;
      filter: drop-shadow(0 4px 14px rgba(0, 0, 0, 0.45));
    }
    .logo-text {
      font-family: var(--font-sans);
      font-size: 12px;
      font-weight: 600;
      letter-spacing: 0.22em;
      text-transform: uppercase;
      color: var(--cream-soft);
    }
    .logo-text .accent {
      color: var(--lime);
      margin-left: 2px;
    }
    @media (max-width: 640px) {
      .logo { height: 44px; }
      .logo-text { font-size: 11px; letter-spacing: 0.18em; }
    }
    .tag-line {
      font-family: var(--font-mono);
      color: var(--moss-3);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.18em;
    }
    .crumbs { color: var(--cream-dim); font-size: 13px; }
    .crumbs a { text-decoration: none; }
    .crumbs a:hover { text-decoration: underline; }
    .ml-auto { margin-left: auto; }

    main {
      max-width: 1100px;
      margin: 0 auto;
      padding: 28px;
    }

    .panel {
      background: var(--night-2);
      border: 1px solid var(--moss-2);
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: 0 18px 50px rgba(0,0,0,0.45);
    }
    .panel + .panel { margin-top: 22px; }
    .panel h2 {
      font-family: var(--font-display);
      font-weight: 500;
      font-size: 22px;
      margin: 0 0 6px;
      letter-spacing: -0.01em;
    }
    .panel .panel-sub {
      color: var(--cream-dim);
      font-size: 13.5px;
      margin: 0 0 18px;
    }

    label.field {
      display: block;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.16em;
      color: var(--moss-3);
      margin-bottom: 6px;
    }
    input[type=text],
    input[type=password],
    input[type=time],
    select,
    textarea {
      width: 100%;
      font: inherit;
      color: var(--cream);
      background: var(--night-1);
      border: 1px solid var(--moss-2);
      border-radius: var(--radius);
      padding: 10px 12px;
      outline: none;
    }
    textarea { resize: vertical; min-height: 80px; }
    input:focus, select:focus, textarea:focus {
      border-color: var(--lime);
      box-shadow: 0 0 0 3px rgba(204,232,78,0.18);
    }

    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
    @media (max-width: 640px) {
      .row, .row-3 { grid-template-columns: 1fr; }
    }

    button, .btn {
      font: inherit;
      font-weight: 600;
      cursor: pointer;
      border-radius: var(--radius);
      border: 1px solid var(--moss-2);
      background: var(--night-3);
      color: var(--cream);
      padding: 9px 16px;
      transition: background .12s ease, border-color .12s ease, transform .04s;
    }
    button:hover, .btn:hover { background: var(--night-4); border-color: var(--moss-3); }
    button:active { transform: translateY(1px); }
    button.primary {
      background: var(--lime);
      color: var(--night-0);
      border-color: var(--lime);
    }
    button.primary:hover { background: var(--lime-soft); border-color: var(--lime-soft); }
    button.danger {
      background: transparent;
      color: var(--danger);
      border-color: rgba(255,110,110,0.4);
    }
    button.danger:hover { background: rgba(255,110,110,0.1); border-color: var(--danger); }
    button.ghost { background: transparent; }

    .actions {
      display: flex; gap: 10px; flex-wrap: wrap;
      margin-top: 18px;
    }

    .flash {
      padding: 12px 14px;
      border-radius: var(--radius);
      margin-bottom: 18px;
      font-size: 14px;
      border: 1px solid var(--moss-2);
    }
    .flash.ok {
      background: rgba(204,232,78,0.10);
      border-color: rgba(204,232,78,0.4);
      color: var(--lime-soft);
    }
    .flash.err {
      background: rgba(255,110,110,0.10);
      border-color: rgba(255,110,110,0.4);
      color: var(--danger);
    }

    .verified-pill {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(204,232,78,0.16);
      color: var(--lime);
      font-size: 11px;
      font-weight: 600;
      padding: 3px 9px;
      border-radius: 999px;
      border: 1px solid rgba(204,232,78,0.4);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .editor-meta {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      margin-bottom: 22px;
    }
    .editor-meta .name {
      font-family: var(--font-display);
      font-size: 24px;
      letter-spacing: -0.01em;
    }

    .events-list { display: flex; flex-direction: column; gap: 10px; }
    .event-row {
      background: var(--night-1);
      border: 1px solid var(--moss-2);
      border-radius: var(--radius);
      overflow: hidden;
    }
    .event-summary {
      display: grid;
      grid-template-columns: 110px 92px 1fr auto;
      align-items: center;
      gap: 14px;
      padding: 12px 14px;
    }
    .event-summary .day {
      font-family: var(--font-mono);
      font-size: 12px;
      color: var(--cyan);
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }
    .event-summary .time {
      font-family: var(--font-mono);
      font-size: 13px;
      color: var(--lime);
    }
    .event-summary .title {
      color: var(--cream);
      font-weight: 600;
      font-size: 15px;
      overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .event-row-actions { display: flex; gap: 6px; }
    .event-row-actions button { padding: 6px 12px; font-size: 13px; }

    .event-edit-form {
      display: none;
      padding: 16px;
      border-top: 1px solid var(--moss-2);
      background: rgba(0,0,0,0.18);
    }
    .event-row.open .event-edit-form { display: block; }
    .event-row.open .event-summary { background: rgba(204,232,78,0.04); }

    .empty {
      padding: 24px;
      text-align: center;
      color: var(--moss-3);
      font-style: italic;
      border: 1px dashed var(--moss-2);
      border-radius: var(--radius);
    }

    @media (max-width: 640px) {
      header { padding: 22px 16px 14px; }
      main { padding: 18px; }
      .panel { padding: 18px; }
      .event-summary {
        grid-template-columns: 1fr;
        gap: 4px;
      }
      .event-row-actions { justify-content: flex-end; }
    }
  </style>
</head>
<body>

<header>
  <div class="header-row">
    <h1 class="logo-h1">
      <a href="index.html">
        <img src="logo.png" alt="Otherworld 2026" class="logo">
        <span class="logo-text">Otherworld <span class="accent">· 2026</span></span>
      </a>
    </h1>
    <span class="tag-line">Camp Dashboard</span>
    <span class="crumbs ml-auto"><a href="index.html">← Back to schedule</a></span>
  </div>
</header>

<main>

  <?php foreach ($flashes as $f): ?>
    <div class="flash <?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
  <?php endforeach; ?>

  <?php if (!$authedCamp): ?>

    <div class="panel">
      <h2>Pick your camp</h2>
      <p class="panel-sub">Select your camp below. If nobody has claimed it yet, you'll set the passphrase that locks it. Otherwise, enter the passphrase to unlock and edit your events.</p>

      <?php if ($claimCooldown > 0): ?>
        <div class="flash err" style="margin-top:14px;">
          A camp was claimed from your network recently. New claims are limited to one per 30 minutes per network — try again in <?= h($claimCooldownTxt) ?>. (You can still <em>unlock</em> camps you've already claimed.)
        </div>
      <?php endif; ?>

      <label class="field" for="camp-picker">Camp</label>
      <select id="camp-picker" autofocus>
        <option value="">— Select a camp —</option>
        <?php foreach ($entries as $entry): ?>
          <option value="<?= h($entry['name']) ?>"><?= h($entry['name']) ?><?= isset($claims[$entry['name']]) ? '  ✓ claimed' : '' ?></option>
        <?php endforeach; ?>
      </select>

      <!-- CLAIM form (camp not yet claimed) -->
      <form id="claim-form" method="post" style="display:none; margin-top: 22px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="claim">
        <input type="hidden" name="camp" id="claim-camp" value="">
        <h2 style="margin-bottom:4px;">Claim this camp</h2>
        <p class="panel-sub">Once set, the passphrase can't be changed without the original passphrase, and nobody else can claim this camp. Pick something you'll remember — minimum <?= (int)$MIN_PASS_LEN ?> characters.</p>
        <div class="row">
          <div>
            <label class="field" for="claim-pass">Passphrase</label>
            <input type="password" id="claim-pass" name="passphrase" minlength="<?= (int)$MIN_PASS_LEN ?>" required autocomplete="new-password">
          </div>
          <div>
            <label class="field" for="claim-confirm">Confirm passphrase</label>
            <input type="password" id="claim-confirm" name="confirm" minlength="<?= (int)$MIN_PASS_LEN ?>" required autocomplete="new-password">
          </div>
        </div>
        <div class="actions">
          <button type="submit" class="primary" id="claim-submit"<?= $claimCooldown > 0 ? ' disabled title="Network rate-limited"' : '' ?>>
            <?php if ($claimCooldown > 0): ?>
              Locked — try again in <?= h($claimCooldownTxt) ?>
            <?php else: ?>
              Claim <span id="claim-camp-label" style="font-style:italic;"></span>
            <?php endif; ?>
          </button>
        </div>
      </form>

      <!-- UNLOCK form (camp already claimed) -->
      <form id="unlock-form" method="post" style="display:none; margin-top: 22px;">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="unlock">
        <input type="hidden" name="camp" id="unlock-camp" value="">
        <h2 style="margin-bottom:4px;">Unlock <span id="unlock-camp-label" style="font-style:italic;"></span></h2>
        <p class="panel-sub">This camp has already been claimed. Enter the passphrase to edit its events.</p>
        <label class="field" for="unlock-pass">Passphrase</label>
        <input type="password" id="unlock-pass" name="passphrase" required autocomplete="current-password">
        <div class="actions">
          <button type="submit" class="primary">Unlock</button>
        </div>
      </form>
    </div>

    <script>
      const CLAIMED = <?= json_encode($claimedNames, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const picker      = document.getElementById('camp-picker');
      const claimForm   = document.getElementById('claim-form');
      const unlockForm  = document.getElementById('unlock-form');
      const claimInput  = document.getElementById('claim-camp');
      const unlockInput = document.getElementById('unlock-camp');
      const claimLabel  = document.getElementById('claim-camp-label');
      const unlockLabel = document.getElementById('unlock-camp-label');

      picker.addEventListener('change', () => {
        const camp = picker.value;
        if (!camp) {
          claimForm.style.display = 'none';
          unlockForm.style.display = 'none';
          return;
        }
        if (CLAIMED.includes(camp)) {
          claimForm.style.display = 'none';
          unlockForm.style.display = '';
          unlockInput.value = camp;
          unlockLabel.textContent = '"' + camp + '"';
          document.getElementById('unlock-pass').focus();
        } else {
          unlockForm.style.display = 'none';
          claimForm.style.display = '';
          claimInput.value = camp;
          if (claimLabel) claimLabel.textContent = '"' + camp + '"';
          document.getElementById('claim-pass').focus();
        }
      });
    </script>

  <?php else: ?>

    <div class="panel">
      <div class="editor-meta">
        <span class="name"><?= h($authedCamp) ?></span>
        <span class="verified-pill">✓ Verified</span>
        <form method="post" class="ml-auto" style="margin:0;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="ghost">Log out</button>
        </form>
      </div>

      <h2>Events</h2>
      <p class="panel-sub">Click <strong>Edit</strong> on any row to change time, day, title, or description. Click <strong>Delete</strong> to remove it (no undo). Changes are saved to the schedule immediately.</p>

      <?php if (empty($campEvents)): ?>
        <div class="empty">No events yet — add your first one below.</div>
      <?php else: ?>
        <div class="events-list">
          <?php foreach ($campEvents as $i => $ev): ?>
            <?php
              $day   = (string)($ev['day'] ?? '');
              $title = (string)($ev['title'] ?? '');
              $st    = (string)($ev['startTime'] ?? '');
              $et    = (string)($ev['endTime'] ?? '');
              $desc  = (string)($ev['description'] ?? '');
            ?>
            <div class="event-row" id="row-<?= (int)$i ?>">
              <div class="event-summary">
                <span class="day"><?= h($day) ?></span>
                <span class="time"><?= h($st) ?>–<?= h($et) ?></span>
                <span class="title"><?= h($title !== '' ? $title : '(untitled)') ?></span>
                <span class="event-row-actions">
                  <button type="button" onclick="toggleRow(<?= (int)$i ?>)">Edit</button>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Delete &quot;<?= h(addslashes($title)) ?>&quot;? This cannot be undone.');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_index" value="<?= (int)$i ?>">
                    <button type="submit" class="danger">Delete</button>
                  </form>
                </span>
              </div>
              <div class="event-edit-form">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="edit_event">
                  <input type="hidden" name="event_index" value="<?= (int)$i ?>">
                  <div class="row-3">
                    <div>
                      <label class="field">Day</label>
                      <select name="day" required>
                        <?php foreach ($DAYS as $d): ?>
                          <option value="<?= h($d) ?>" <?= $d === $day ? 'selected' : '' ?>><?= h($d) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div>
                      <label class="field">Start</label>
                      <?= render_time_select('startTime', $st) ?>
                    </div>
                    <div>
                      <label class="field">End</label>
                      <?= render_time_select('endTime', $et) ?>
                    </div>
                  </div>
                  <div style="margin-top:14px;">
                    <label class="field">Title</label>
                    <input type="text" name="title" value="<?= h($title) ?>" required maxlength="200">
                  </div>
                  <div style="margin-top:14px;">
                    <label class="field">Description</label>
                    <textarea name="description" maxlength="2000"><?= h($desc) ?></textarea>
                  </div>
                  <div class="actions">
                    <button type="submit" class="primary">Save changes</button>
                    <button type="button" onclick="toggleRow(<?= (int)$i ?>)">Cancel</button>
                  </div>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Add a new event</h2>
      <p class="panel-sub">All events you add will appear under <em><?= h($authedCamp) ?></em> on the public schedule.</p>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="add_event">
        <div class="row-3">
          <div>
            <label class="field">Day</label>
            <select name="day" required>
              <option value="">— pick a day —</option>
              <?php foreach ($DAYS as $d): ?>
                <option value="<?= h($d) ?>"><?= h($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="field">Start time</label>
            <?= render_time_select('startTime', '') ?>
          </div>
          <div>
            <label class="field">End time</label>
            <?= render_time_select('endTime', '') ?>
          </div>
        </div>
        <div style="margin-top:14px;">
          <label class="field">Title</label>
          <input type="text" name="title" placeholder="e.g. Sunset Cacao Ceremony" required maxlength="200">
        </div>
        <div style="margin-top:14px;">
          <label class="field">Description</label>
          <textarea name="description" placeholder="Tell people what to expect, bring, or know." maxlength="2000"></textarea>
        </div>
        <div class="actions">
          <button type="submit" class="primary">Add event</button>
        </div>
      </form>
    </div>

    <script>
      function toggleRow(i) {
        document.getElementById('row-' + i).classList.toggle('open');
      }
    </script>

  <?php endif; ?>

</main>
</body>
</html>
