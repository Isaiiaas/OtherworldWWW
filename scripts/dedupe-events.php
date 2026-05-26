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
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run from the CLI.\n");
}

$ROOT         = dirname(__DIR__);
$EVENTS_FILE  = $ROOT . '/events.json';
$ALIAS_FILE   = $ROOT . '/camp-aliases.json';
$SNAPSHOT_BIN = '/usr/local/bin/otherworld-snapshot';

$apply = in_array('--apply', $argv ?? [], true);

/**
 * Returns an array of [canonical(typo-name) => canonical-display-name].
 * Empty if the file is missing/malformed.
 */
function load_alias_map(string $path): array {
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    $aliases = (is_array($data) && isset($data['aliases']) && is_array($data['aliases']))
        ? $data['aliases']
        : [];
    $out = [];
    foreach ($aliases as $from => $to) {
        $k = canonical((string)$from);
        if ($k === '' || !is_string($to)) continue;
        $out[$k] = (string)$to;
    }
    return $out;
}

function event_key(array $e): string {
    return strtolower(trim((string)($e['title'] ?? ''))) . '|'
         . trim((string)($e['day'] ?? '')) . '|'
         . trim((string)($e['startTime'] ?? ''));
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

// ── Group by canonical name + dedupe ──────────────────────────────────────
// Apply the alias map first so misspelled camp names ("The Saloon Saloon")
// hash to the same canonical key as their canonical version ("Salon Saloon").
$aliasMap = load_alias_map($ALIAS_FILE); // canonical(typo) => canonical-name
$groups = []; // canonicalKey => [['entry'=>..., 'index'=>i], ...]
foreach ($data['entries'] as $i => $entry) {
    $rawCanon = canonical((string)($entry['name'] ?? ''));
    $key = isset($aliasMap[$rawCanon]) ? canonical($aliasMap[$rawCanon]) : $rawCanon;
    $groups[$key][] = ['entry' => $entry, 'index' => $i];
}

$merged = [];
$report = [];
$removedEntries = 0;
$mergedInEvents = 0;
$droppedDupes   = 0;

foreach ($groups as $canon => $list) {
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
            $report[] = sprintf(
                "  · %s — %d self-duplicate event(s) dropped",
                $entry['name'] ?? '(unnamed)', $before - count($kept)
            );
        }
        $merged[] = $entry;
        continue;
    }

    // Multi-entry group. Pick primary: a claimed entry wins; else the first
    // occurrence by array index. Among multiple claimed (shouldn't happen
    // post-fix, but does in current data), fall back to lowest index.
    usort($list, function ($a, $b) {
        $ac = !empty($a['entry']['claimed']) ? 0 : 1;
        $bc = !empty($b['entry']['claimed']) ? 0 : 1;
        if ($ac !== $bc) return $ac - $bc;
        return $a['index'] - $b['index'];
    });

    $primary = $list[0]['entry'];

    // If the primary's name is itself an aliased (typo'd) variant, rewrite
    // it to the canonical name so the merged entry doesn't carry the typo.
    $primaryCanon = canonical((string)($primary['name'] ?? ''));
    if (isset($aliasMap[$primaryCanon])) {
        $primary['name'] = $aliasMap[$primaryCanon];
    }

    $events  = is_array($primary['events'] ?? null) ? $primary['events'] : [];
    $seen    = [];
    foreach ($events as $ev) $seen[event_key($ev)] = true;

    $added   = 0;
    $skipped = 0;
    $dupNames = [];
    for ($i = 1, $n = count($list); $i < $n; $i++) {
        $dupNames[] = $list[$i]['entry']['name'] ?? '(unnamed)';
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
    $primaryTag = !empty($primary['claimed']) ? ' ✓ claimed' : '';
    $report[] = sprintf(
        "  · %s%s — %d copies → 1 (merged %s; +%d net-new events, dropped %d duplicate events)",
        $primary['name'] ?? '(unnamed)', $primaryTag,
        count($list),
        implode(', ', $dupNames),
        $added, $skipped
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

// ── Fuzzy near-match report (not auto-merged) ─────────────────────────────
// Surface typo-suspects so a human can decide whether to fold them in.
$canonByEntry = [];
foreach ($data['entries'] as $e) {
    $c = canonical((string)($e['name'] ?? ''));
    if ($c === '' || strlen($c) < 6) continue;
    $canonByEntry[$c] = $e['name'] ?? '';
}
$canons = array_keys($canonByEntry);
sort($canons);
$fuzzy = [];
for ($i = 0, $n = count($canons); $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        if (abs(strlen($canons[$i]) - strlen($canons[$j])) > 2) continue;
        $d = levenshtein($canons[$i], $canons[$j]);
        if ($d > 0 && $d <= 2) {
            $fuzzy[] = sprintf(
                "  · d=%d : %s  ↔  %s",
                $d, $canonByEntry[$canons[$i]], $canonByEntry[$canons[$j]]
            );
        }
    }
}
if ($fuzzy) {
    echo "\nFuzzy near-matches (likely typos — not auto-merged, review by hand):\n";
    foreach ($fuzzy as $line) echo $line . "\n";
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
