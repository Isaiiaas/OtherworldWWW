<?php
declare(strict_types=1);

/**
 * Reconciles events.json against the spreadsheet.
 *
 * For unclaimed camps the sheet is the source of truth: each unclaimed camp's
 * events list gets replaced with whatever the sheet currently says.
 * Claimed camps are left untouched so their owners' dashboard edits survive.
 *
 * Camps that appear in the sheet but not in events.json are added as new
 * `type=camp` entries. Unclaimed camps that no longer appear in the sheet
 * have their events list cleared.
 *
 * CLI:   php admin/reconcile-sheet.php           # dry run (default)
 *        php admin/reconcile-sheet.php --apply   # write changes
 *
 * Web:   GET  /admin/reconcile-sheet.php         # dry-run JSON preview
 *        POST /admin/reconcile-sheet.php         # apply changes
 */

define('DASHBOARD', true); // satisfies claims.php's exit guard

$ROOT         = dirname(__DIR__);
$EVENTS_FILE  = $ROOT . '/events.json';
$CLAIMS_FILE  = $ROOT . '/claims.php';
$DATA_JS_FILE = $ROOT . '/data.js';
$LOG_DIR      = __DIR__ . '/logs/reconcile';

$SHEET_ID  = '1o9Ue218Yx8mMa9OGyPfd66NoofYI3O1ewkN8NB-qnVc';
$SHEET_GID = '1785212198';
$SHEET_URL = "https://docs.google.com/spreadsheets/d/$SHEET_ID/export?format=csv&gid=$SHEET_GID";

// Boolean tag columns to lift onto each event. Stored verbatim so the labels
// stay in sync with the spreadsheet without a mapping table.
$TAG_COLUMNS = [
    'Workshop / Class',
    'Interactive / Crafts',
    'Food / Drink',
    'Performance / Show',
    'Wellness / Yoga',
    'Refuge / Introspective',
    'Music / Dance',
    'Game / Activity',
    '19+',
    'Alcohol Involved',
];

$isCli = (PHP_SAPI === 'cli');
$apply = $isCli
    ? in_array('--apply', $argv ?? [], true)
    : (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');

// ── Fetch the sheet ───────────────────────────────────────────────────────
// Any failure here must hard-stop the script — silently proceeding with an
// empty or broken sheet would wipe every unclaimed camp's events.
$ctx = stream_context_create([
    'http' => [
        'timeout'        => 20,
        'follow_location'=> 1,
        'ignore_errors'  => true, // surface response headers even on 4xx/5xx
        'header'         => "Accept: text/csv\r\n",
    ],
]);
$csv = @file_get_contents($SHEET_URL, false, $ctx);
if ($csv === false) {
    fail($isCli, 'Could not fetch spreadsheet (network error).');
}

// $http_response_header is populated by the HTTP wrapper after file_get_contents.
$statusLine = $http_response_header[0] ?? '';
$statusCode = 0;
if (preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
    $statusCode = (int)$m[1];
}
if ($statusCode === 0 || $statusCode >= 400) {
    fail($isCli, 'Spreadsheet fetch returned HTTP ' . ($statusCode ?: '???') . ' — refusing to proceed.');
}

$trimmed = ltrim($csv);
if ($trimmed === '') {
    fail($isCli, 'Spreadsheet response was empty — refusing to proceed.');
}
// Google serves an HTML login/redirect page when the sheet isn't shared
// publicly. Detect that before we waste time parsing it as CSV.
if (stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0) {
    fail($isCli, 'Spreadsheet response looked like HTML, not CSV (sheet likely not public).');
}

$rows = [];
$fh = fopen('php://memory', 'r+');
fwrite($fh, $csv);
rewind($fh);
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    $rows[] = $r;
}
fclose($fh);

if (count($rows) < 5) {
    fail($isCli, 'Spreadsheet had only ' . count($rows) . ' rows — refusing to proceed.');
}

// The header row is the one whose 3rd column is "Day" and 4th is "Start Time".
// Earlier rows are banner / section titles we don't care about.
$headerRowIdx = null;
foreach ($rows as $i => $r) {
    if (($r[2] ?? '') === 'Day' && ($r[3] ?? '') === 'Start Time') {
        $headerRowIdx = $i;
        break;
    }
}
if ($headerRowIdx === null) {
    fail($isCli, 'Could not find header row in spreadsheet — refusing to proceed.');
}

$headerIndex = [];
foreach ($rows[$headerRowIdx] as $i => $h) {
    $headerIndex[trim((string)$h)] = $i;
}
foreach (['Day','Start Time','End Time','Camp','Neighbourhood','Event Name','Description of Event'] as $required) {
    if (!isset($headerIndex[$required])) {
        fail($isCli, "Missing expected column in sheet: $required");
    }
}

$dataRows = array_slice($rows, $headerRowIdx + 1);

// Group sheet rows by canonicalised camp name.
$bySheet = [];
$skipped = ['no_camp' => 0, 'no_title' => 0, 'section_header' => 0];

foreach ($dataRows as $r) {
    $camp  = trim((string)($r[$headerIndex['Camp']] ?? ''));
    $title = trim((string)($r[$headerIndex['Event Name']] ?? ''));
    $day   = trim((string)($r[$headerIndex['Day']] ?? ''));

    if ($camp === '' && $title === '') {
        $day !== '' ? $skipped['section_header']++ : $skipped['no_camp']++;
        continue;
    }
    if ($camp === '')  { $skipped['no_camp']++;  continue; }
    if ($title === '') { $skipped['no_title']++; continue; }

    $key = canonical($camp);
    if (!isset($bySheet[$key])) {
        $bySheet[$key] = [
            'displayName'   => $camp,
            'neighbourhood' => trim((string)($r[$headerIndex['Neighbourhood']] ?? '')),
            'rows'          => [],
        ];
    }
    $bySheet[$key]['rows'][] = $r;
}

// ── Load events.json + claims ─────────────────────────────────────────────
if (!is_file($EVENTS_FILE)) fail($isCli, 'events.json not found.');
$events = json_decode((string)file_get_contents($EVENTS_FILE), true);
if (!is_array($events) || !isset($events['entries']) || !is_array($events['entries'])) {
    fail($isCli, 'events.json is missing or malformed.');
}

// Deep-clone the original entries so we can diff before/after for the log.
$beforeEntries = json_decode((string)json_encode($events['entries']), true);

$claims = [];
if (is_file($CLAIMS_FILE)) {
    $claims = include $CLAIMS_FILE;
    if (!is_array($claims)) $claims = [];
}
// Map canonical claim key -> original claim name, so the script can be
// resilient to apostrophe / casing / typo drift between claims and entries.
$claimedCanonical = [];
foreach ($claims as $name => $_) {
    $claimedCanonical[canonical((string)$name)] = (string)$name;
}

// ── Reconcile ─────────────────────────────────────────────────────────────
$summary = [
    'sheet_rows_seen'   => count($dataRows),
    'skipped'           => $skipped,
    'unclaimed_updated' => [],
    'unclaimed_cleared' => [],
    'claimed_preserved' => [],
    'added'             => [],
    'fuzzy_matches'     => [], // surface these so a human can sanity-check merges
];

// First pass: exact-canonical matches between entries and sheet groups. We do
// this before fuzzy matching so identical names always win, and a sheet group
// can never be fuzz-bound to more than one entry.
$entriesNeedingFuzzy = []; // entry index => canonical key
foreach ($events['entries'] as $i => $entry) {
    $entryKey = canonical((string)($entry['name'] ?? ''));

    // Claim check: exact first, then fuzzy.
    $claimKey = isset($claimedCanonical[$entryKey]) ? $entryKey : null;
    if ($claimKey === null) {
        $match = fuzzy_find($entryKey, $claimedCanonical);
        if ($match !== null) {
            $claimKey = $match;
            $summary['fuzzy_matches'][] = [
                'kind'         => 'claim',
                'entry_name'   => $entry['name'],
                'matched_claim'=> $claimedCanonical[$match],
            ];
        }
    }
    if ($claimKey !== null) {
        $summary['claimed_preserved'][] = $entry['name'];
        // Consume the sheet group for this claimed camp so the leftover-sheet
        // loop at the bottom doesn't re-append it as a "new" entry every
        // reconcile run — that was creating one fresh duplicate per hour.
        if (isset($bySheet[$entryKey])) {
            unset($bySheet[$entryKey]);
        } else {
            $sheetMatch = fuzzy_find($entryKey, $bySheet);
            if ($sheetMatch !== null) unset($bySheet[$sheetMatch]);
        }
        continue;
    }

    if (isset($bySheet[$entryKey])) {
        apply_sheet_group($events['entries'][$i], $bySheet[$entryKey], $headerIndex, $TAG_COLUMNS, $summary);
        unset($bySheet[$entryKey]);
    } else {
        $entriesNeedingFuzzy[$i] = $entryKey;
    }
}

// Second pass: fuzzy-match the leftovers against any sheet groups still unclaimed.
foreach ($entriesNeedingFuzzy as $i => $entryKey) {
    $match = fuzzy_find($entryKey, $bySheet);
    if ($match !== null) {
        $summary['fuzzy_matches'][] = [
            'kind'        => 'sheet',
            'entry_name'  => $events['entries'][$i]['name'],
            'matched_sheet_name' => $bySheet[$match]['displayName'],
        ];
        apply_sheet_group($events['entries'][$i], $bySheet[$match], $headerIndex, $TAG_COLUMNS, $summary);
        unset($bySheet[$match]);
        continue;
    }
    $oldCount = count($events['entries'][$i]['events'] ?? []);
    if ($oldCount > 0) {
        $events['entries'][$i]['events'] = [];
        $summary['unclaimed_cleared'][] = ['name' => $events['entries'][$i]['name'], 'removed' => $oldCount];
    }
}

// Any sheet camps still in $bySheet are new — add them.
foreach ($bySheet as $key => $group) {
    $newEntry = [
        'name'   => $group['displayName'],
        'type'   => 'camp',
        'events' => [],
    ];
    if ($group['neighbourhood'] !== '') {
        $newEntry['neighbourhood'] = $group['neighbourhood'];
    }
    foreach ($group['rows'] as $r) {
        $newEntry['events'][] = build_event($r, $headerIndex, $TAG_COLUMNS, $group['displayName'], 'camp');
    }
    $events['entries'][] = $newEntry;
    $summary['added'][] = ['name' => $group['displayName'], 'event_count' => count($newEntry['events'])];
}

$events = recompute_metadata($events);
$events['metadata']['lastReconciledAt']    = gmdate('c');
$events['metadata']['reconciledFromSheet'] = $SHEET_ID;

// Build the change log: per-camp before/after with a computed event diff so
// it's easy to render in a viewer without re-running this script.
$changes = build_changes_log($beforeEntries, $events['entries'], $claimedCanonical);

if (!$apply) {
    output($isCli, [
        'ok'       => true,
        'dry_run'  => true,
        'summary'  => $summary,
        'changes'  => $changes,
    ]);
    exit;
}

// Persist the change log alongside this run. The dry-run path skips this so a
// preview doesn't litter the log dir.
$logPath = write_change_log($LOG_DIR, $SHEET_ID, $summary, $changes);

// ── Write (with snapshot) ─────────────────────────────────────────────────
if (is_file('/usr/local/bin/otherworld-snapshot') && is_file($EVENTS_FILE)) {
    $snapOut = []; $snapRc = 0;
    @exec('/usr/local/bin/otherworld-snapshot events.json 2>&1', $snapOut, $snapRc);
    if ($snapRc !== 0) {
        error_log('otherworld-snapshot failed (rc=' . $snapRc . '): ' . implode(' | ', $snapOut));
    }
}

$json = json_encode(
    $events,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$tmp = $EVENTS_FILE . '.tmp';
file_put_contents($tmp, $json, LOCK_EX);
rename($tmp, $EVENTS_FILE);

// Regenerate data.js, mirroring dashboard.php so the claim flag stays accurate.
foreach ($events['entries'] as &$entry) {
    $entry['claimed'] = isset($claims[$entry['name']]);
}
unset($entry);
$body = 'window.OTHERWORLD_DATA = '
      . json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
      . ";\n";
$tmp = $DATA_JS_FILE . '.tmp';
file_put_contents($tmp, $body, LOCK_EX);
rename($tmp, $DATA_JS_FILE);

output($isCli, [
    'ok'       => true,
    'applied'  => true,
    'summary'  => $summary,
    'log_file' => $logPath,
]);

// ── Helpers ───────────────────────────────────────────────────────────────
/**
 * Aggressive normalisation for matching camp names across the sheet, events.json,
 * and claims.php. Folds diacritics, lowercases, and strips everything but a-z0-9
 * so "Garden of Eatin'" and "Garden of Eatin" collide, "Café Olé" and "cafe ole"
 * collide, etc. Use {@see fuzzy_find()} for near-misses one or two typos apart.
 */
function canonical(string $name): string {
    $s = trim($name);
    if ($s === '') return '';
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if (is_string($t)) $s = $t;
        else $s = strtolower($s);
    } else {
        // Best-effort fallback if intl isn't available.
        $s = strtolower($s);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        }
    }
    // Word-order variants and "Camp" suffix: "Living Room, The" / "The Living
    // Room" / "Living Room Camp" should all collide. Conservative on purpose —
    // we don't strip parentheticals or extra descriptors since those can
    // distinguish genuinely different camps.
    $s = preg_replace('/^the\s+/', '', $s);
    $s = preg_replace('/,?\s*the\s*$/', '', $s);
    $s = preg_replace('/\s+camp\s*$/', '', $s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string)$s;
}

/**
 * Returns the closest key in $haystack to $needle within an edit-distance
 * threshold scaled by length, or null if no candidate is close enough. Both
 * sides should already be canonicalised by {@see canonical()}.
 */
function fuzzy_find(string $needle, array $haystack): ?string {
    if ($needle === '' || empty($haystack)) return null;
    $len = strlen($needle);
    // Too short to fuzz safely — a 1-char edit can change the meaning entirely.
    if ($len < 6) return null;

    // Allow ~10% of length, capped at 3, so single-letter typos in long names
    // match without lumping unrelated short names together.
    $threshold = min(3, max(1, (int)floor($len * 0.1)));

    $best = null;
    $bestDist = PHP_INT_MAX;
    foreach ($haystack as $candidate => $_) {
        $clen = strlen((string)$candidate);
        if ($clen < 6) continue;
        if (abs($clen - $len) > $threshold) continue; // can't be within threshold
        // levenshtein() has a 255-byte limit; canonical strings are short ASCII so this is fine.
        $d = levenshtein($needle, (string)$candidate);
        if ($d <= $threshold && $d < $bestDist) {
            $best = (string)$candidate;
            $bestDist = $d;
            if ($d === 0) break;
        }
    }
    return $best;
}

function valid_time(string $t): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function normalize_time(string $t): string {
    $t = trim($t);
    if ($t === '') return '';
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
        $h = (int)$m[1]; $mn = (int)$m[2];
        if ($h <= 23 && $mn <= 59) return sprintf('%02d:%02d', $h, $mn);
    }
    return $t;
}

function truthy($v): bool {
    return is_string($v) && strtoupper(trim($v)) === 'TRUE';
}

/**
 * Replace an entry's events list with the rows from a sheet group, and copy
 * over the neighbourhood. Records the change in $summary.
 */
function apply_sheet_group(array &$entry, array $group, array $headerIndex, array $tagColumns, array &$summary): void {
    $type = $entry['type'] ?? 'camp';
    $newEvents = [];
    foreach ($group['rows'] as $r) {
        $newEvents[] = build_event($r, $headerIndex, $tagColumns, (string)$entry['name'], (string)$type);
    }
    $oldCount = count($entry['events'] ?? []);
    $entry['events'] = $newEvents;
    if ($group['neighbourhood'] !== '') {
        $entry['neighbourhood'] = $group['neighbourhood'];
    }
    $summary['unclaimed_updated'][] = [
        'name'            => $entry['name'],
        'old_event_count' => $oldCount,
        'new_event_count' => count($newEvents),
    ];
}

function build_event(array $row, array $headerIndex, array $tagColumns, string $owner, string $ownerType): array {
    $get = static function (string $key) use ($row, $headerIndex): string {
        $i = $headerIndex[$key] ?? null;
        return $i !== null ? (string)($row[$i] ?? '') : '';
    };

    $title       = trim($get('Event Name'));
    $description = trim($get('Description of Event'));
    $day         = trim($get('Day'));
    $startTime   = normalize_time($get('Start Time'));
    $endTime     = normalize_time($get('End Time'));

    $duration = null; $crosses = false;
    if (valid_time($startTime) && valid_time($endTime)) {
        [$sh, $sm] = array_map('intval', explode(':', $startTime));
        [$eh, $em] = array_map('intval', explode(':', $endTime));
        $startMin = $sh * 60 + $sm;
        $endMin   = $eh * 60 + $em;
        if ($endMin <= $startMin) { $endMin += 24 * 60; $crosses = true; }
        $duration = round(($endMin - $startMin) / 60, 2);
    }

    $tags = [];
    foreach ($tagColumns as $col) {
        if (truthy($get($col))) $tags[] = $col;
    }

    return [
        'owner'              => $owner,
        'ownerType'          => $ownerType,
        'title'              => $title,
        'description'        => $description,
        'day'                => $day,
        'startTime'          => $startTime,
        'endTime'            => $endTime,
        'durationHours'      => $duration,
        'crossesMidnight'    => $crosses,
        'normalizationFlags' => [],
        'rawTimeText'        => trim($day . ' ' . $startTime . ' - ' . $endTime),
        'tags'               => $tags,
    ];
}

/**
 * Build a per-camp before/after change log with a computed event diff.
 * Camps with no meaningful change (claimed-and-untouched, or unclaimed-and-
 * identical-events) are omitted to keep the log focused.
 */
function build_changes_log(array $beforeEntries, array $afterEntries, array $claimedCanonical): array {
    $beforeByCanon = [];
    foreach ($beforeEntries as $e) {
        $beforeByCanon[canonical((string)($e['name'] ?? ''))] = $e;
    }

    $changes = [];
    $seenBeforeKeys = [];

    foreach ($afterEntries as $after) {
        $key = canonical((string)($after['name'] ?? ''));
        $before = $beforeByCanon[$key] ?? null;
        if ($before !== null) $seenBeforeKeys[$key] = true;

        $beforeEvents = $before['events'] ?? [];
        $afterEvents  = $after['events']  ?? [];

        $isClaimed = isset($claimedCanonical[$key]);
        if ($isClaimed) continue; // nothing the script touched

        $action = $before === null ? 'added'
                : (empty($afterEvents) && !empty($beforeEvents) ? 'cleared' : 'updated');

        $diff = diff_events($beforeEvents, $afterEvents);
        $hasChange = !empty($diff['added']) || !empty($diff['removed']) || !empty($diff['modified']);
        if ($action === 'updated' && !$hasChange) continue;
        $diff['totalBefore'] = count($beforeEvents);
        $diff['totalAfter']  = count($afterEvents);

        $entry = [
            'name'   => $after['name'] ?? '',
            'action' => $action,
            'diff'   => $diff,
        ];
        if (!empty($before['neighbourhood']) || !empty($after['neighbourhood'])) {
            $entry['neighbourhood'] = [
                'before' => $before['neighbourhood'] ?? null,
                'after'  => $after['neighbourhood']  ?? null,
            ];
        }
        $changes[] = $entry;
    }

    // Entries that existed before but disappeared from $afterEntries — shouldn't
    // happen under current logic, but record them defensively so a future code
    // change that removes entries doesn't lose its diff trail.
    foreach ($beforeEntries as $before) {
        $key = canonical((string)($before['name'] ?? ''));
        if (isset($seenBeforeKeys[$key])) continue;
        if (isset($claimedCanonical[$key])) continue;
        $changes[] = [
            'name'   => $before['name'] ?? '',
            'action' => 'deleted',
            'diff'   => diff_events($before['events'] ?? [], []),
        ];
    }

    return $changes;
}

/**
 * Pair events between $before and $after by (title, day) and classify each
 * pair. Multiple events with the same key get matched sequentially in array
 * order so adding a new occurrence reads as 'added', not as 'modified'.
 */
function diff_events(array $before, array $after): array {
    $key = static fn(array $e) => strtolower(trim((string)($e['title'] ?? ''))) . '|' . strtolower(trim((string)($e['day'] ?? '')));

    $beforeQ = []; $afterQ = [];
    foreach ($before as $e) $beforeQ[$key($e)][] = $e;
    foreach ($after  as $e) $afterQ[$key($e)][]  = $e;

    $unchanged = []; $added = []; $removed = []; $modified = [];
    $keys = array_unique(array_merge(array_keys($beforeQ), array_keys($afterQ)));
    foreach ($keys as $k) {
        $bq = $beforeQ[$k] ?? [];
        $aq = $afterQ[$k]  ?? [];
        while (!empty($bq) || !empty($aq)) {
            $b = array_shift($bq);
            $a = array_shift($aq);
            if ($b !== null && $a !== null) {
                if (event_equal($b, $a)) {
                    $unchanged[] = $a;
                } else {
                    $modified[] = ['before' => $b, 'after' => $a];
                }
            } elseif ($b !== null) {
                $removed[] = $b;
            } else {
                $added[] = $a;
            }
        }
    }
    // Return only the things that actually changed plus a count of carryovers,
    // so the log stays small enough to render quickly.
    return [
        'unchangedCount' => count($unchanged),
        'added'          => $added,
        'removed'        => $removed,
        'modified'       => $modified,
    ];
}

function event_equal(array $a, array $b): bool {
    // Compare the user-meaningful fields. Drop derived ones (durationHours,
    // crossesMidnight, rawTimeText) so a recomputation doesn't show as a
    // "change" when nothing the user cares about moved.
    $fields = ['title','description','day','startTime','endTime','tags'];
    foreach ($fields as $f) {
        $av = $a[$f] ?? null;
        $bv = $b[$f] ?? null;
        if (is_array($av) || is_array($bv)) {
            $av = is_array($av) ? $av : [];
            $bv = is_array($bv) ? $bv : [];
            sort($av); sort($bv);
            if ($av !== $bv) return false;
        } else {
            if ((string)$av !== (string)$bv) return false;
        }
    }
    return true;
}

function write_change_log(string $logDir, string $sheetId, array $summary, array $changes): string {
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $ts = gmdate('Y-m-d\THis\Z');
    $path = $logDir . '/' . $ts . '.json';
    $payload = [
        'ranAt'   => gmdate('c'),
        'sheetId' => $sheetId,
        'summary' => $summary,
        'changes' => $changes,
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($path, $json, LOCK_EX);
    return $path;
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
    $data['metadata']['entryCount']   = $entryCount;
    $data['metadata']['eventCount']   = $eventCount;
    $data['metadata']['countsByType'] = $counts;
    return $data;
}

function output(bool $isCli, array $payload): void {
    if ($isCli) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function fail(bool $isCli, string $msg): void {
    if ($isCli) {
        fwrite(STDERR, "ERROR: $msg\n");
        exit(1);
    }
    http_response_code(500);
    output($isCli, ['ok' => false, 'error' => $msg]);
    exit;
}
