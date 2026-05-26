<?php
declare(strict_types=1);

// Public changelog page.
//
// Reads pre-edit snapshots from /var/www/otherworld-versions/events/
// (written by /usr/local/bin/otherworld-snapshot via dashboard.php), pairs
// each snapshot with the next one to derive what changed, and renders a flat
// table sorted newest-first.

$ROOT          = __DIR__;
$EVENTS_FILE   = $ROOT . '/events.json';
$VERSIONS_DIR  = '/var/www/otherworld-versions/events';

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
    if ($t === '') return 'entry';
    return $map[$t] ?? str_replace('_', ' ', $t);
}

function load_entries_by_name(string $path): array {
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) return [];
    $byName = [];
    foreach ($data['entries'] as $entry) {
        if (!is_array($entry) || !isset($entry['name'])) continue;
        $byName[(string)$entry['name']] = $entry;
    }
    return $byName;
}

function event_key(array $e): string {
    return ($e['title'] ?? '') . '|' . ($e['day'] ?? '') . '|' . ($e['startTime'] ?? '');
}

function event_signature(array $e): string {
    $fields = ['title', 'description', 'day', 'startTime', 'endTime', 'rawTimeText'];
    $parts  = [];
    foreach ($fields as $f) $parts[] = (string)($e[$f] ?? '');
    return implode("\x1f", $parts);
}

function format_date(?string $iso): string {
    if ($iso === null || $iso === '') return '—';
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone('America/Vancouver'));
        return $dt->format('M j, Y g:i a');
    } catch (Throwable $e) {
        return $iso;
    }
}

// ── Collect snapshots, oldest → newest ────────────────────────────────────
$snapshots = [];
if (is_dir($VERSIONS_DIR)) {
    foreach (glob($VERSIONS_DIR . '/events-*.json') ?: [] as $path) {
        if (!preg_match('/events-(\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}Z)\.json$/', basename($path), $m)) continue;
        $iso = preg_replace('/^(\d{4}-\d{2}-\d{2}T\d{2})-(\d{2})-(\d{2})Z$/', '$1:$2:$3Z', $m[1]);
        $snapshots[] = ['path' => $path, 'timestamp' => $iso];
    }
    usort($snapshots, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
}

// Chain: every snapshot in order, then current events.json as the final state.
$chain = $snapshots;
if (is_file($EVENTS_FILE)) {
    $chain[] = ['path' => $EVENTS_FILE, 'timestamp' => null];
}

// ── Diff each adjacent pair into change rows ──────────────────────────────
// snapshot_k holds the pre-edit content for the edit at time T_k;
// snapshot_{k+1} (or current events.json if k is last) holds the post-edit
// content. So the edit timestamp displayed is taken from the LEFT side.
$rows = [];
for ($i = 0, $n = count($chain) - 1; $i < $n; $i++) {
    $before  = load_entries_by_name($chain[$i]['path']);
    $after   = load_entries_by_name($chain[$i + 1]['path']);
    $editTs  = $chain[$i]['timestamp'];

    $allNames = array_unique(array_merge(array_keys($before), array_keys($after)));
    foreach ($allNames as $name) {
        $b = $before[$name] ?? null;
        $a = $after[$name]  ?? null;
        $type = (string)($a['type'] ?? $b['type'] ?? '');

        if ($b === null && $a !== null) {
            $rows[] = ['ts' => $editTs, 'owner' => $name, 'event' => '', 'change' => 'New ' . pretty_type($type)];
            foreach (($a['events'] ?? []) as $e) {
                $rows[] = ['ts' => $editTs, 'owner' => $name, 'event' => (string)($e['title'] ?? ''), 'change' => 'Added'];
            }
            continue;
        }
        if ($a === null && $b !== null) {
            $rows[] = ['ts' => $editTs, 'owner' => $name, 'event' => '', 'change' => 'Deleted ' . pretty_type($type)];
            continue;
        }

        $bEvents = [];
        foreach (($b['events'] ?? []) as $e) $bEvents[event_key($e)] = $e;
        $aEvents = [];
        foreach (($a['events'] ?? []) as $e) $aEvents[event_key($e)] = $e;

        $allKeys = array_unique(array_merge(array_keys($bEvents), array_keys($aEvents)));
        foreach ($allKeys as $k) {
            $be = $bEvents[$k] ?? null;
            $ae = $aEvents[$k] ?? null;
            if ($be === null && $ae !== null) {
                $rows[] = ['ts' => $editTs, 'owner' => $name, 'event' => (string)($ae['title'] ?? ''), 'change' => 'Added'];
            } elseif ($be !== null && $ae === null) {
                $rows[] = ['ts' => $editTs, 'owner' => $name, 'event' => (string)($be['title'] ?? ''), 'change' => 'Removed'];
            } elseif ($be !== null && $ae !== null && event_signature($be) !== event_signature($ae)) {
                $rows[] = ['ts' => $editTs, 'owner' => $name, 'event' => (string)($ae['title'] ?? ''), 'change' => 'Edited'];
            }
        }
    }
}

// Newest first; within one edit, entry-level rows before event-level rows.
usort($rows, function ($a, $b) {
    $cmp = strcmp((string)($b['ts'] ?? ''), (string)($a['ts'] ?? ''));
    if ($cmp !== 0) return $cmp;
    $aEntry = ($a['event'] === '') ? 0 : 1;
    $bEntry = ($b['event'] === '') ? 0 : 1;
    if ($aEntry !== $bEntry) return $aEntry - $bEntry;
    return strcmp($a['owner'], $b['owner']);
});

function badge_class(string $change): string {
    $c = strtolower($change);
    if (str_starts_with($c, 'new '))     return 'new';
    if (str_starts_with($c, 'deleted ')) return 'deleted';
    if ($c === 'added')                  return 'added';
    if ($c === 'removed')                return 'removed';
    if ($c === 'edited')                 return 'edited';
    return 'muted';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <title>Otherworld 2026 — Changelog</title>
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
      font-size: 28px;
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
    main {
      padding: 24px 32px 64px;
      max-width: 1100px;
      margin: 0 auto;
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
    .col-date   { white-space: nowrap; color: var(--cream-soft); width: 1%; }
    .col-owner  { color: var(--cream); }
    .col-event  { color: var(--cream-soft); }
    .col-change { white-space: nowrap; width: 1%; }
    .muted { color: var(--cream-dim); }
    .badge {
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
    .badge.added   { color: var(--lime); }
    .badge.edited  { color: var(--cyan); }
    .badge.removed { color: var(--pink); }
    .badge.new     { color: var(--amber); }
    .badge.deleted { color: var(--pink); }
    .badge.muted   { color: var(--cream-dim); }
    .count {
      color: var(--cream-dim);
      font-size: 12px;
      margin-left: auto;
    }
    @media (max-width: 720px) {
      header { padding: 16px 16px 10px; }
      main   { padding: 16px; }
      h1     { font-size: 22px; }
      table  { font-size: 13px; }
      thead th, tbody td { padding: 10px 10px; }
    }
  </style>
</head>
<body>
  <header>
    <h1>Otherworld <span class="accent">changelog</span></h1>
    <a href="./">← Back to schedule</a>
    <span class="count"><?= count($rows) ?> change<?= count($rows) === 1 ? '' : 's' ?></span>
  </header>
  <main>
    <?php if (empty($rows)): ?>
      <div class="empty">No changes recorded yet.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="col-date">Date</th>
              <th class="col-owner">Camp</th>
              <th class="col-event">Event</th>
              <th class="col-change">Change</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="col-date"><?= h(format_date($r['ts'])) ?></td>
                <td class="col-owner"><?= h($r['owner']) ?></td>
                <td class="col-event"><?= $r['event'] !== '' ? h($r['event']) : '<span class="muted">—</span>' ?></td>
                <td class="col-change"><span class="badge <?= h(badge_class($r['change'])) ?>"><?= h($r['change']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
