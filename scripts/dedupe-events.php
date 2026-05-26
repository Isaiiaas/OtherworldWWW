<?php
declare(strict_types=1);

/**
 * Collapse same-named duplicate entries in events.json that the reconcile
 * script accumulated. Safe to re-run.
 *
 * Usage:
 *   php scripts/dedupe-events.php              # dry run — prints what would change
 *   php scripts/dedupe-events.php --apply      # write the changes
 *
 * Rules:
 *   - Entries are grouped by exact `.name`.
 *   - Primary entry = the FIRST occurrence by array index (the original, since
 *     reconcile appends new dupes at the end). Its fields (type, claimed,
 *     neighbourhood, etc.) win.
 *   - Events are merged: primary's events come first; events from later dupes
 *     are appended only if their (lowercased title, day, startTime) key isn't
 *     already present. Preserves owner edits while pulling in any net-new
 *     events the dupes carried.
 *   - Metadata counts (entryCount, eventCount, countsByType) are recomputed.
 *
 * Atomic via tmp+rename. Snapshots via /usr/local/bin/otherworld-snapshot if
 * available (matching dashboard.php / reconcile-sheet.php).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the CLI.\n");
}

$ROOT         = dirname(__DIR__);
$EVENTS_FILE  = $ROOT . '/events.json';
$SNAPSHOT_BIN = '/usr/local/bin/otherworld-snapshot';

$apply = in_array('--apply', $argv ?? [], true);

function event_key(array $e): string {
    return strtolower(trim((string)($e['title'] ?? ''))) . '|'
         . trim((string)($e['day'] ?? '')) . '|'
         . trim((string)($e['startTime'] ?? ''));
}

function recompute_metadata(array $data): array {
    $eventCount = 0;
    $counts = [];
    foreach ($data['entries'] as $entry) {
        $t = (string)($entry['type'] ?? '');
        $counts[$t] = ($counts[$t] ?? 0) + 1;
        $evs = is_array($entry['events'] ?? null) ? $entry['events'] : [];
        $counts[$t . '_events'] = ($counts[$t . '_events'] ?? 0) + count($evs);
        $eventCount += count($evs);
    }
    $data['metadata'] = $data['metadata'] ?? [];
    $data['metadata']['entryCount']   = count($data['entries']);
    $data['metadata']['eventCount']   = $eventCount;
    $data['metadata']['countsByType'] = $counts;
    return $data;
}

// ── Load ──────────────────────────────────────────────────────────────────
if (!is_file($EVENTS_FILE)) {
    fwrite(STDERR, "events.json not found at $EVENTS_FILE\n");
    exit(1);
}
$raw = (string)file_get_contents($EVENTS_FILE);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
    fwrite(STDERR, "events.json missing or malformed.\n");
    exit(1);
}

// ── Group + dedupe ────────────────────────────────────────────────────────
$groups = []; // name => [['entry'=>..., 'index'=>i], ...]
foreach ($data['entries'] as $i => $entry) {
    $name = (string)($entry['name'] ?? '');
    $groups[$name][] = ['entry' => $entry, 'index' => $i];
}

$merged = [];
$report = [];
$removedEntries = 0;
$mergedInEvents = 0;
$droppedDupes   = 0;

foreach ($groups as $name => $list) {
    if (count($list) === 1) {
        // Singleton — still scrub self-duplicate events within the entry.
        $entry = $list[0]['entry'];
        $before = is_array($entry['events'] ?? null) ? count($entry['events']) : 0;
        $seen = [];
        $kept = [];
        foreach (($entry['events'] ?? []) as $ev) {
            $k = event_key($ev);
            if (isset($seen[$k])) { $droppedDupes++; continue; }
            $seen[$k] = true;
            $kept[]  = $ev;
        }
        $entry['events'] = $kept;
        if (count($kept) !== $before) {
            $report[] = sprintf("  · %s — %d self-duplicate event(s) dropped", $name, $before - count($kept));
        }
        $merged[] = $entry;
        continue;
    }

    // Multi-entry group: merge events into primary (lowest index).
    usort($list, fn($a, $b) => $a['index'] - $b['index']);
    $primary = $list[0]['entry'];
    $events  = is_array($primary['events'] ?? null) ? $primary['events'] : [];
    $seen    = [];
    foreach ($events as $ev) $seen[event_key($ev)] = true;

    $added   = 0;
    $skipped = 0;
    for ($i = 1, $n = count($list); $i < $n; $i++) {
        foreach (($list[$i]['entry']['events'] ?? []) as $ev) {
            $k = event_key($ev);
            if (isset($seen[$k])) { $skipped++; continue; }
            $seen[$k] = true;
            $events[] = $ev;
            $added++;
        }
    }

    $primary['events'] = $events;
    $merged[]          = $primary;
    $removedEntries   += count($list) - 1;
    $mergedInEvents   += $added;
    $droppedDupes     += $skipped;
    $report[] = sprintf(
        "  · %s — %d copies → 1 (kept primary at index %d, +%d net-new events, dropped %d duplicate events)",
        $name, count($list), $list[0]['index'], $added, $skipped
    );
}

$data['entries'] = $merged;
$data = recompute_metadata($data);

// ── Report ────────────────────────────────────────────────────────────────
echo ($apply ? "Applying:" : "Dry run — nothing written:") . "\n";
printf("  Entries:  %d  (removed %d)\n", count($data['entries']), $removedEntries);
printf(
    "  Events:   %d  (merged %d net-new from dupes, dropped %d duplicates)\n",
    $data['metadata']['eventCount'], $mergedInEvents, $droppedDupes
);
if ($report) {
    echo "\nChanges:\n";
    foreach ($report as $line) echo $line . "\n";
}

if (!$apply) {
    echo "\nRe-run with --apply to write.\n";
    exit(0);
}

if ($removedEntries === 0 && $droppedDupes === 0) {
    echo "\nNo changes needed.\n";
    exit(0);
}

// ── Snapshot (best-effort) ────────────────────────────────────────────────
if (is_file($SNAPSHOT_BIN)) {
    $out = []; $rc = 0;
    $cmd = escapeshellcmd($SNAPSHOT_BIN) . ' events.json 2>&1';
    $cwd = getcwd();
    chdir($ROOT);
    @exec($cmd, $out, $rc);
    if ($cwd !== false) chdir($cwd);
    if ($rc !== 0) {
        fwrite(STDERR, "WARNING: otherworld-snapshot exited rc=$rc: " . implode(' | ', $out) . "\n");
    }
} else {
    $stamp = gmdate('Y-m-d\TH-i-s\Z');
    $bak = $EVENTS_FILE . '.bak.' . $stamp;
    copy($EVENTS_FILE, $bak);
    echo "(no otherworld-snapshot here — wrote local backup " . basename($bak) . ")\n";
}

// ── Atomic write ──────────────────────────────────────────────────────────
$json = json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$tmp = $EVENTS_FILE . '.tmp';
file_put_contents($tmp, $json . "\n", LOCK_EX);
rename($tmp, $EVENTS_FILE);

echo "\nWrote events.json.\n";
