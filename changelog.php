<?php
declare(strict_types=1);

// Lists events that exist in events.json but NOT in the shared spreadsheet.
// Useful for spotting events that were added/edited directly via dashboard
// claims and haven't been mirrored back to the canonical sheet.

$ROOT        = __DIR__;
$EVENTS_FILE = $ROOT . '/events.json';

$SHEET_ID  = '1o9Ue218Yx8mMa9OGyPfd66NoofYI3O1ewkN8NB-qnVc';
$SHEET_GID = '1785212198';
$SHEET_URL = "https://docs.google.com/spreadsheets/d/$SHEET_ID/export?format=csv&gid=$SHEET_GID";
$SHEET_VIEW_URL = "https://docs.google.com/spreadsheets/d/$SHEET_ID/edit?gid=$SHEET_GID#gid=$SHEET_GID";

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pretty_type(string $t): string {
    $map = [
        'camp'             => 'camp',
        'sound_stage'      => 'sound stage',
        'art_installation' => 'art installation',
        'mutant_vehicle'   => 'mutant vehicle',
    ];
    if ($t === '') return '';
    return $map[$t] ?? str_replace('_', ' ', $t);
}

/**
 * Mirror of admin/reconcile-sheet.php::canonical(). Kept in sync manually —
 * if reconcile changes its rules, update here too so the comparison stays
 * meaningful.
 */
function canonical(string $name): string {
    $s = trim($name);
    if ($s === '') return '';
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if (is_string($t)) $s = $t;
        else $s = strtolower($s);
    } else {
        $s = strtolower($s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
    }
    $s = preg_replace('/^the\s+/', '', $s);
    $s = preg_replace('/,?\s*the\s*$/', '', $s);
    $s = preg_replace('/\s+camp\s*$/', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string)$s;
}

/**
 * Loads camp-aliases.json and returns [canonical(typo) => canonical-name]
 * so misspelled names match the canonical version when comparing sides.
 */
function load_alias_map(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    $aliases = (is_array($data) && isset($data['aliases']) && is_array($data['aliases']))
        ? $data['aliases'] : [];
    $out = [];
    foreach ($aliases as $from => $to) {
        $k = canonical((string)$from);
        if ($k !== '' && is_string($to)) $out[$k] = (string)$to;
    }
    return $out;
}

function event_key(string $camp, string $title, string $day, array $aliasMap): string {
    $c = canonical($camp);
    if (isset($aliasMap[$c])) $c = canonical($aliasMap[$c]);
    return $c . '|' . strtolower(trim($title)) . '|' . strtolower(trim($day));
}

/**
 * Fetch the sheet and build a set of (camp,title,day) keys.
 * Returns [keys, error] — keys is empty if the fetch failed.
 */
function fetch_sheet_keys(string $url, array $aliasMap): array {
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 20,
            'follow_location' => 1,
            'ignore_errors'   => true,
            'header'          => "Accept: text/csv\r\n",
        ],
    ]);
    $csv = @file_get_contents($url, false, $ctx);
    if ($csv === false) {
        return [[], 'Could not reach the spreadsheet (network error).'];
    }
    $statusLine = $http_response_header[0] ?? '';
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) && (int)$m[1] >= 400) {
        return [[], 'Spreadsheet fetch returned HTTP ' . $m[1] . '.'];
    }
    $trimmed = ltrim($csv);
    if ($trimmed === '') {
        return [[], 'Spreadsheet response was empty.'];
    }
    if (stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0) {
        return [[], 'Spreadsheet returned HTML, not CSV (sheet likely not public).'];
    }

    $rows = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $rows[] = $r;
    }
    fclose($fh);

    // Find the header row (matches reconcile-sheet.php's heuristic).
    $headerRowIdx = null;
    foreach ($rows as $i => $r) {
        if (($r[2] ?? '') === 'Day' && ($r[3] ?? '') === 'Start Time') {
            $headerRowIdx = $i;
            break;
        }
    }
    if ($headerRowIdx === null) {
        return [[], 'Could not find a header row in the spreadsheet.'];
    }
    $headerIndex = [];
    foreach ($rows[$headerRowIdx] as $i => $hname) {
        $headerIndex[trim((string)$hname)] = $i;
    }
    foreach (['Day', 'Camp', 'Event Name'] as $required) {
        if (!isset($headerIndex[$required])) {
            return [[], "Spreadsheet is missing the '$required' column."];
        }
    }

    $keys = [];
    foreach (array_slice($rows, $headerRowIdx + 1) as $r) {
        $camp  = trim((string)($r[$headerIndex['Camp']] ?? ''));
        $title = trim((string)($r[$headerIndex['Event Name']] ?? ''));
        $day   = trim((string)($r[$headerIndex['Day']] ?? ''));
        if ($camp === '' || $title === '') continue;
        $keys[event_key($camp, $title, $day, $aliasMap)] = true;
    }
    return [$keys, null];
}

// ── Load events.json ──────────────────────────────────────────────────────
$entries = [];
$eventsError = null;
if (!is_file($EVENTS_FILE)) {
    $eventsError = 'events.json not found.';
} else {
    $raw = @file_get_contents($EVENTS_FILE);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        $eventsError = 'events.json is missing or malformed.';
    } else {
        $entries = $data['entries'];
    }
}

// ── Fetch sheet keys ──────────────────────────────────────────────────────
$aliasMap = load_alias_map($ROOT . '/camp-aliases.json');
[$sheetKeys, $sheetError] = fetch_sheet_keys($SHEET_URL, $aliasMap);

// ── Dedup entries in memory (canonical name + alias resolution) ───────────
// Mirrors scripts/dedupe-events.php so the list reflects the post-dedup view
// even before the script has been run on this events.json. Within a group:
//   - Primary = claimed wins; else lowest array index.
//   - Display name rewritten to the alias target if the primary is itself an
//     aliased / typo'd variant.
//   - Events merged with first-wins on (title, day, startTime).
//   - Claimed flag = OR of every entry in the group.
$groups = []; // canonicalKey => ['entry'=>array, 'index'=>int]
foreach ($entries as $i => $entry) {
    $rawCanon = canonical((string)($entry['name'] ?? ''));
    $key = isset($aliasMap[$rawCanon]) ? canonical($aliasMap[$rawCanon]) : $rawCanon;
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    $groups[$key][] = ['entry' => $entry, 'index' => $i];
}
$dedupedEntries = [];
foreach ($groups as $list) {
    usort($list, function ($a, $b) {
        $ac = !empty($a['entry']['claimed']) ? 0 : 1;
        $bc = !empty($b['entry']['claimed']) ? 0 : 1;
        if ($ac !== $bc) return $ac - $bc;
        return $a['index'] - $b['index'];
    });
    $primary = $list[0]['entry'];
    $primaryCanon = canonical((string)($primary['name'] ?? ''));
    if (isset($aliasMap[$primaryCanon])) {
        $primary['name'] = $aliasMap[$primaryCanon];
    }
    $evKey = static fn(array $e) => strtolower(trim((string)($e['title'] ?? '')))
                                  . '|' . trim((string)($e['day'] ?? ''))
                                  . '|' . trim((string)($e['startTime'] ?? ''));
    $events = is_array($primary['events'] ?? null) ? $primary['events'] : [];
    $seen = [];
    $kept = [];
    foreach ($events as $ev) {
        $k = $evKey($ev);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $kept[] = $ev;
    }
    $claimed = !empty($primary['claimed']);
    for ($i = 1, $n = count($list); $i < $n; $i++) {
        if (!empty($list[$i]['entry']['claimed'])) $claimed = true;
        foreach (($list[$i]['entry']['events'] ?? []) as $ev) {
            $k = $evKey($ev);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $kept[] = $ev;
        }
    }
    $primary['events']  = $kept;
    $primary['claimed'] = $claimed;
    $dedupedEntries[]   = $primary;
}
$entries = $dedupedEntries;

// ── Compute the missing-from-sheet rows ───────────────────────────────────
$missing = [];
$totalEvents = 0;
if ($sheetError === null && $eventsError === null) {
    foreach ($entries as $entry) {
        $camp = (string)($entry['name'] ?? '');
        $type = (string)($entry['type'] ?? '');
        $claimed = !empty($entry['claimed']);
        foreach (($entry['events'] ?? []) as $e) {
            $totalEvents++;
            $title = (string)($e['title'] ?? '');
            $day   = (string)($e['day'] ?? '');
            $key = event_key($camp, $title, $day, $aliasMap);
            if (isset($sheetKeys[$key])) continue;
            $missing[] = [
                'camp'      => $camp,
                'type'      => $type,
                'claimed'   => $claimed,
                'title'     => $title,
                'day'       => $day,
                'startTime' => (string)($e['startTime'] ?? ''),
                'endTime'   => (string)($e['endTime'] ?? ''),
            ];
        }
    }
    // Sort: by camp (alpha), then by day (Thu→Mon), then by start time.
    $dayOrder = ['Thursday' => 0, 'Friday' => 1, 'Saturday' => 2, 'Sunday' => 3, 'Monday' => 4];
    usort($missing, function ($a, $b) use ($dayOrder) {
        $c = strcasecmp($a['camp'], $b['camp']);
        if ($c !== 0) return $c;
        $da = $dayOrder[$a['day']] ?? 99;
        $db = $dayOrder[$b['day']] ?? 99;
        if ($da !== $db) return $da - $db;
        return strcmp($a['startTime'], $b['startTime']);
    });
}

$sheetCount = count($sheetKeys);
$missingCount = count($missing);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <title>Otherworld 2026 — Events not in spreadsheet</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght,SOFT@9..144,400..700,0..100&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --night-1: #0a1620;
      --night-2: #122332;
      --night-3: #1a3045;
      --moss-1: rgba(120, 180, 200, 0.10);
      --moss-2: rgba(120, 180, 200, 0.22);
      --cream: #f2ead0;
      --cream-soft: #d4cea7;
      --cream-dim: #95a3a9;
      --lime: #cce84e;
      --pink: #ff86bd;
      --cyan: #74dce8;
      --amber: #ffae5a;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Inter", -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
      background:
        radial-gradient(ellipse 80% 50% at 12% -10%, rgba(204, 232, 78, 0.10), transparent 60%),
        radial-gradient(ellipse 70% 60% at 92% 10%, rgba(255, 134, 189, 0.08), transparent 60%),
        var(--night-1);
      color: var(--cream);
      line-height: 1.5;
      min-height: 100vh;
    }
    header {
      padding: 22px 32px 14px;
      border-bottom: 1px solid var(--moss-1);
      display: flex;
      align-items: baseline;
      gap: 20px;
      flex-wrap: wrap;
    }
    h1 {
      font-family: "Fraunces", Georgia, serif;
      font-weight: 700;
      font-size: 26px;
      letter-spacing: -0.02em;
      margin: 0;
      color: var(--cream);
    }
    h1 .accent { color: var(--lime); font-style: italic; }
    header a {
      color: var(--cream-dim);
      text-decoration: none;
      font-size: 13px;
      font-weight: 500;
      border-bottom: 1px solid transparent;
      transition: color .15s ease, border-color .15s ease;
    }
    header a:hover { color: var(--cream); border-color: var(--moss-2); }
    .count {
      color: var(--cream-dim);
      font-size: 12px;
      margin-left: auto;
    }
    .count strong { color: var(--cream); font-weight: 600; }
    main {
      padding: 24px 32px 64px;
      max-width: 1100px;
      margin: 0 auto;
    }
    .blurb {
      color: var(--cream-dim);
      font-size: 13px;
      margin: 0 0 18px;
      line-height: 1.55;
    }
    .blurb a { color: var(--lime); text-decoration: none; border-bottom: 1px dashed rgba(204,232,78,0.4); }
    .blurb a:hover { color: var(--lime); border-bottom-style: solid; }
    .error {
      padding: 14px 18px;
      background: rgba(255, 134, 189, 0.10);
      border: 1px solid var(--pink);
      color: var(--cream);
      border-radius: 10px;
      margin-bottom: 16px;
      font-size: 14px;
    }
    .empty {
      padding: 40px 24px;
      text-align: center;
      color: var(--cream-dim);
      background: var(--night-2);
      border: 1px solid var(--moss-1);
      border-radius: 10px;
    }
    .table-wrap {
      overflow-x: auto;
      background: var(--night-2);
      border: 1px solid var(--moss-1);
      border-radius: 10px;
    }
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 14px;
    }
    thead th {
      text-align: left;
      font-weight: 600;
      font-size: 11px;
      letter-spacing: 0.10em;
      text-transform: uppercase;
      color: var(--cream-dim);
      padding: 12px 16px;
      background: rgba(0, 0, 0, 0.18);
      border-bottom: 1px solid var(--moss-1);
      position: sticky;
      top: 0;
    }
    tbody td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--moss-1);
      vertical-align: top;
    }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: var(--night-3); }
    .col-camp   { color: var(--cream); font-weight: 500; }
    .col-type   { color: var(--cream-dim); font-size: 12px; text-transform: capitalize; white-space: nowrap; width: 1%; }
    .col-title  { color: var(--cream-soft); }
    .col-day    { color: var(--cream-soft); white-space: nowrap; width: 1%; }
    .col-time   { color: var(--cream-dim); font-variant-numeric: tabular-nums; white-space: nowrap; width: 1%; font-size: 13px; }
    .col-flag   { white-space: nowrap; width: 1%; }
    .pill {
      display: inline-block;
      padding: 2px 9px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      border: 1px solid currentColor;
      white-space: nowrap;
    }
    .pill.claimed   { color: var(--cyan); }
    .pill.unclaimed { color: var(--amber); }
    @media (max-width: 720px) {
      header { padding: 16px 16px 10px; }
      main   { padding: 16px; }
      h1     { font-size: 20px; }
      table  { font-size: 13px; }
      thead th, tbody td { padding: 10px 10px; }
    }
  </style>
</head>
<body>
  <header>
    <h1>Events <span class="accent">not in the spreadsheet</span></h1>
    <a href="./">← Back to schedule</a>
    <span class="count">
      <?php if ($sheetError === null && $eventsError === null): ?>
        <strong><?= $missingCount ?></strong> missing · <?= $totalEvents ?> total in events.json · <?= $sheetCount ?> in sheet
      <?php endif; ?>
    </span>
  </header>
  <main>
    <p class="blurb">
      Each row is an event that lives in <code>events.json</code> but has no matching <em>(camp, title, day)</em> row in
      the <a href="<?= h($SHEET_VIEW_URL) ?>" target="_blank" rel="noopener">shared spreadsheet</a>.
      Most of these will be from claimed camps whose owners edit on the site instead of the sheet.
    </p>

    <?php if ($eventsError !== null): ?>
      <div class="error"><strong>events.json:</strong> <?= h($eventsError) ?></div>
    <?php endif; ?>
    <?php if ($sheetError !== null): ?>
      <div class="error"><strong>Spreadsheet:</strong> <?= h($sheetError) ?></div>
    <?php endif; ?>

    <?php if ($sheetError === null && $eventsError === null): ?>
      <?php if ($missingCount === 0): ?>
        <div class="empty">Every event in events.json has a match in the spreadsheet. Nothing to mirror.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-camp">Camp</th>
                <th class="col-type">Type</th>
                <th class="col-title">Event</th>
                <th class="col-day">Day</th>
                <th class="col-time">Time</th>
                <th class="col-flag">Claim</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($missing as $r): ?>
                <tr>
                  <td class="col-camp"><?= h($r['camp']) ?></td>
                  <td class="col-type"><?= h(pretty_type($r['type'])) ?></td>
                  <td class="col-title"><?= h($r['title']) ?></td>
                  <td class="col-day"><?= h($r['day']) ?></td>
                  <td class="col-time">
                    <?= h($r['startTime']) ?><?= $r['endTime'] !== '' ? ' – ' . h($r['endTime']) : '' ?>
                  </td>
                  <td class="col-flag">
                    <?php if ($r['claimed']): ?>
                      <span class="pill claimed">✓ Claimed</span>
                    <?php else: ?>
                      <span class="pill unclaimed">unclaimed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
